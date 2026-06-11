<?php
/**
 * Compound Deduplication / Merge Tool — Admin
 *
 * Detects duplicate compound rows by admin-selected criteria
 * (compound_name, cas_number, inchi_key, or any combination), shows them
 * as groups, lets the admin pick a "keeper" per group, and bulk-merges
 * selected groups in single transactions per merge.
 *
 * Safety features:
 *   • Hard-deletes losers but writes a full JSON snapshot to compound_archive
 *     (migration 011) so any merge can be inspected after the fact.
 *   • Writes compound_redirects entries (migration 011) so product.php can
 *     301 inbound traffic from old slugs / catalog tokens to the keeper.
 *   • All FK references in supplier_listings, order_items, quote_items,
 *     saved_products, recently_viewed and pharmacopeia_impurities are
 *     re-pointed at the keeper BEFORE the loser row is deleted.
 *   • Per-group transaction: a failure on one group rolls back that group
 *     only; other groups in the same bulk submit still complete.
 */

require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/csrf.php';
require_once __DIR__ . '/../private/dedup.php';  // dedup_completeness_score() + dedup_merge()

enforceSessionTimeout(900);
if (!isset($_SESSION['role']) || !checkRole('Admin')) {
    header('Location: /signin.php');
    exit;
}

$db      = Database::getInstance();
$message = '';
$error   = '';
$results = ['ok' => 0, 'fail' => 0, 'errors' => []];

// dedup_completeness_score() moved to private/dedup.php (shared with pubchem_fetch.php)

/**
 * Build the SQL grouping key from selected criteria. Returns the SQL
 * expression to GROUP BY. Empty result = no criteria selected.
 */
function dedup_group_key(array $criteria): string {
    $parts = [];
    if (in_array('name', $criteria, true)) {
        // Case-insensitive + whitespace-normalized
        $parts[] = "LOWER(TRIM(REGEXP_REPLACE(compound_name, '\\\\s+', ' ')))";
    }
    if (in_array('cas', $criteria, true)) {
        $parts[] = "COALESCE(NULLIF(TRIM(cas_number), ''), '')";
    }
    if (in_array('inchi_key', $criteria, true)) {
        $parts[] = "COALESCE(NULLIF(TRIM(inchi_key), ''), '')";
    }
    return $parts ? "CONCAT_WS('|', " . implode(', ', $parts) . ")" : '';
}

/**
 * Find duplicate groups by selected criteria. Each group is N >= 2 compounds
 * sharing the same key. Returns:
 *   [ ['key' => '...', 'compounds' => [row, row, ...]], ... ]
 */
function dedup_scan(Database $db, array $criteria): array {
    $groupKey = dedup_group_key($criteria);
    if ($groupKey === '') return [];

    // Find duplicate keys first (cheap aggregate)
    $sql = "SELECT $groupKey AS gk, COUNT(*) AS cnt
            FROM compounds
            WHERE status IN ('Active','Draft')
            GROUP BY gk
            HAVING cnt > 1 AND gk != '' AND gk NOT LIKE '|%' AND gk NOT LIKE '%|'
            ORDER BY cnt DESC, gk
            LIMIT 200";
    try {
        $dupKeys = $db->fetchAll($sql);
    } catch (Exception $e) {
        // REGEXP_REPLACE missing on older MariaDB — fall back to simpler key
        error_log('[dedup] REGEXP_REPLACE failed, using simple LOWER(TRIM): ' . $e->getMessage());
        $parts = [];
        if (in_array('name', $criteria, true))      $parts[] = "LOWER(TRIM(compound_name))";
        if (in_array('cas', $criteria, true))       $parts[] = "COALESCE(NULLIF(TRIM(cas_number), ''), '')";
        if (in_array('inchi_key', $criteria, true)) $parts[] = "COALESCE(NULLIF(TRIM(inchi_key), ''), '')";
        $groupKey = "CONCAT_WS('|', " . implode(', ', $parts) . ")";
        $sql = str_replace('LOWER(TRIM(REGEXP_REPLACE', '', $sql);
        $dupKeys = $db->fetchAll(
            "SELECT $groupKey AS gk, COUNT(*) AS cnt
             FROM compounds
             WHERE status IN ('Active','Draft')
             GROUP BY gk
             HAVING cnt > 1 AND gk != ''
             ORDER BY cnt DESC, gk
             LIMIT 200"
        );
    }

    // Fetch members of each group + listing/order counts
    $groups = [];
    foreach ($dupKeys as $dk) {
        $rows = $db->fetchAll(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM supplier_listings WHERE compound_id = c.id) AS listings_count,
                    (SELECT COUNT(*) FROM order_items       WHERE compound_id = c.id) AS orders_count
             FROM compounds c
             WHERE $groupKey = :key AND c.status IN ('Active','Draft')
             ORDER BY c.id",
            ['key' => $dk['gk']]
        );
        if (count($rows) < 2) continue; // race-safety: skip if no longer a dup

        // Score + suggest keeper (highest score wins; tiebreak: lowest id)
        foreach ($rows as &$r) $r['_score'] = dedup_completeness_score($r);
        unset($r);
        usort($rows, function($a, $b) {
            if ($a['_score'] !== $b['_score']) return $b['_score'] - $a['_score'];
            return $a['id'] - $b['id'];
        });
        $rows[0]['_is_suggested_keeper'] = true;

        $groups[] = [
            'key'       => $dk['gk'],
            'count'     => (int)$dk['cnt'],
            'compounds' => $rows,
        ];
    }
    return $groups;
}

// ── POST handler — execute the bulk merge ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'merge') {
    if (!isset($_POST['csrf_token']) || !CSRF::verify($_POST['csrf_token'])) {
        $error = 'CSRF validation failed — refresh the page and try again.';
    } else {
        $groups = $_POST['groups'] ?? [];
        $reason = $_POST['reason'] ?? 'manual';
        foreach ($groups as $idx => $groupData) {
            $keeperId  = (int)($groupData['keeper'] ?? 0);
            $allIds    = array_map('intval', $groupData['all_ids'] ?? []);
            $loserIds  = array_filter($allIds, fn($id) => $id > 0 && $id !== $keeperId);
            if ($keeperId <= 0 || empty($loserIds)) continue;
            try {
                dedup_merge($db, $keeperId, $loserIds, $reason);
                $results['ok']++;
            } catch (Exception $e) {
                $results['fail']++;
                $results['errors'][] = "Group $idx (keeper #$keeperId): " . $e->getMessage();
                error_log("[dedup] merge failed for group $idx: " . $e->getMessage());
            }
        }
        if ($results['ok'] > 0)   $message = "✅ Merged {$results['ok']} group" . ($results['ok'] !== 1 ? 's' : '') . " successfully.";
        if ($results['fail'] > 0) $error   = "❌ {$results['fail']} merge" . ($results['fail'] !== 1 ? 's' : '') . " failed — see details below.";
    }
}

// ── Scan duplicates (GET or after merge) ──────────────────────────────
$criteria        = $_REQUEST['criteria'] ?? [];
$duplicateGroups = !empty($criteria) ? dedup_scan($db, $criteria) : [];
$csrfToken       = CSRF::generate();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Compound Deduplication | AB Chem</title>
<style>
* { box-sizing: border-box; }
body { font-family: system-ui, -apple-system, sans-serif; max-width: 1400px; margin: 30px auto; padding: 20px; background: #f8fafc; }
h1 { color: #7c3aed; margin: 0 0 8px 0; }
h2 { color: #1e293b; font-size: 1.2rem; margin: 0 0 14px 0; }
.card { background: white; border-radius: 12px; padding: 22px; margin-bottom: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); border: 1px solid #e2e8f0; }
.muted { color: #64748b; font-size: 0.9rem; }
.btn { display: inline-block; background: #7c3aed; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; font-size: 14px; font-weight: 500; }
.btn:hover { background: #6d28d9; }
.btn-outline { background: white; color: #7c3aed; border: 2px solid #7c3aed; padding: 8px 16px; }
.btn-danger { background: #dc2626; }
.btn-danger:hover { background: #b91c1c; }
.alert-success { background: #dcfce7; color: #166534; padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; border-left: 4px solid #16a34a; }
.alert-error   { background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; border-left: 4px solid #dc2626; }
.alert-info    { background: #ede9fe; color: #5b21b6; padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; border-left: 4px solid #7c3aed; }
.checkbox-row { display: flex; gap: 18px; flex-wrap: wrap; margin: 10px 0; }
.checkbox-row label { display: flex; align-items: center; gap: 7px; padding: 8px 14px; border: 2px solid #e2e8f0; border-radius: 8px; cursor: pointer; font-size: 14px; }
.checkbox-row label:has(input:checked) { border-color: #7c3aed; background: #ede9fe; color: #5b21b6; font-weight: 500; }
.group-card { border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 14px; overflow: hidden; }
.group-header { background: #f1f5f9; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; }
.group-header label { display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer; }
.group-key { font-family: 'JetBrains Mono', monospace; font-size: 0.85rem; color: #475569; word-break: break-all; }
.group-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.group-table th { background: #f8fafc; color: #475569; padding: 8px 10px; text-align: left; font-weight: 600; border-bottom: 1px solid #e2e8f0; }
.group-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.group-table tr.keeper-row { background: #f0fdf4; }
.group-table tr.keeper-row td:first-child { border-left: 4px solid #16a34a; }
.suggested-badge { display: inline-block; background: #16a34a; color: white; font-size: 10px; padding: 2px 8px; border-radius: 12px; font-weight: 600; margin-left: 6px; }
.score-pill { background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.field-cell { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.field-cell code { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: #334155; }
.empty-cell { color: #cbd5e1; font-style: italic; }
.warning-pill { background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.sticky-actions { position: sticky; bottom: 0; background: white; padding: 16px; border-top: 2px solid #7c3aed; box-shadow: 0 -4px 12px rgba(0,0,0,0.08); margin-top: 20px; border-radius: 0 0 12px 12px; }
.sticky-actions .count-display { font-weight: 600; color: #5b21b6; margin-right: 16px; }
</style>
</head>
<body>

<div class="card">
    <h1>🧬 Compound Deduplication</h1>
    <p class="muted">
        Scan the catalog for duplicate compound rows by name, CAS number, and/or InChIKey.
        Pick a keeper per group, merge in bulk. All merges are logged + archived;
        old URLs get 301-redirected to the keeper.
    </p>
    <div class="alert-info">
        <strong>⚠️ Before you merge:</strong> migrations 011 (compound_redirects + compound_archive)
        must be applied. Each merge is irreversible from the UI, but every merged row's
        full snapshot is preserved in <code>compound_archive</code> for manual restoration if needed.
    </div>

    <?php if ($message): ?>
        <div class="alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php if (!empty($results['errors'])): ?>
            <ul style="margin: 6px 0 0 20px; font-size: 13px; color: #991b1b;">
                <?php foreach ($results['errors'] as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ── Scan form ────────────────────────────────────────────────────── -->
<div class="card">
    <h2>1️⃣ Select scan criteria</h2>
    <form method="get">
        <div class="checkbox-row">
            <label><input type="checkbox" name="criteria[]" value="name"
                <?= in_array('name', (array)$criteria, true) ? 'checked' : '' ?>>
                Compound name <span class="muted">(case-insensitive, whitespace-normalized)</span></label>
            <label><input type="checkbox" name="criteria[]" value="cas"
                <?= in_array('cas', (array)$criteria, true) ? 'checked' : '' ?>>
                CAS number <span class="muted">(exact)</span></label>
            <label><input type="checkbox" name="criteria[]" value="inchi_key"
                <?= in_array('inchi_key', (array)$criteria, true) ? 'checked' : '' ?>>
                InChIKey <span class="muted">(exact)</span></label>
        </div>
        <p class="muted" style="margin: 4px 0 12px 0;">
            Tick multiple criteria for tighter matches (e.g. name + InChIKey = compounds with the same name AND same structure).
            Empty fields are excluded — a compound with no InChIKey will not group with another by InChIKey alone.
        </p>
        <button type="submit" class="btn">🔍 Scan for duplicates</button>
    </form>
</div>

<?php if (!empty($criteria) && empty($duplicateGroups)): ?>
    <div class="card">
        <h2>✨ No duplicates found</h2>
        <p class="muted">No compound groups match the selected criteria. Try a different combination.</p>
    </div>
<?php endif; ?>

<?php if (!empty($duplicateGroups)): ?>
<!-- ── Bulk merge form ──────────────────────────────────────────────── -->
<form method="post" id="merge-form" onsubmit="return confirmMerge();">
    <input type="hidden" name="action" value="merge">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="reason" value="<?= htmlspecialchars(implode(',', (array)$criteria)) ?>">

    <div class="card">
        <h2>2️⃣ Review duplicate groups
            <span style="font-weight:400; color:#64748b; font-size:0.85rem;">
                — <?= count($duplicateGroups) ?> group<?= count($duplicateGroups) !== 1 ? 's' : '' ?> found
            </span>
        </h2>
        <p class="muted">
            Tick groups to merge. The <span class="suggested-badge">SUGGESTED</span> keeper has the most-complete data;
            click any other radio to override. Field merge rules: keeper's empty fields are filled from losers,
            synonyms are unioned, all supplier listings transfer to keeper (no listings are deleted).
        </p>

        <div style="margin: 10px 0;">
            <label style="cursor:pointer;">
                <input type="checkbox" id="select-all" onchange="toggleAll(this)"> Select all groups
            </label>
        </div>

        <?php foreach ($duplicateGroups as $gi => $group): ?>
        <div class="group-card" data-group-idx="<?= $gi ?>">
            <div class="group-header">
                <label>
                    <input type="checkbox" name="groups[<?= $gi ?>][selected]" value="1" class="group-tick" onchange="updateMergeCount()">
                    Group #<?= $gi + 1 ?> — <?= (int)$group['count'] ?> compounds
                    <span class="group-key">[ <?= htmlspecialchars($group['key']) ?> ]</span>
                </label>
                <span class="muted">Pick keeper →</span>
            </div>
            <table class="group-table">
                <thead>
                    <tr>
                        <th style="width:40px;">Keep?</th>
                        <th style="width:55px;">ID</th>
                        <th style="width:80px;">AB Catalog</th>
                        <th>Compound Name</th>
                        <th>CAS</th>
                        <th>InChIKey</th>
                        <th>MF</th>
                        <th style="width:60px;">Listings</th>
                        <th style="width:55px;">Orders</th>
                        <th style="width:55px;">Score</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($group['compounds'] as $ci => $c):
                    $isSuggested = !empty($c['_is_suggested_keeper']);
                ?>
                    <tr class="<?= $isSuggested ? 'keeper-row' : '' ?>" id="row-<?= $gi ?>-<?= $c['id'] ?>">
                        <td>
                            <input type="radio" name="groups[<?= $gi ?>][keeper]" value="<?= (int)$c['id'] ?>"
                                <?= $isSuggested ? 'checked' : '' ?>
                                onchange="highlightKeeper(<?= $gi ?>, <?= (int)$c['id'] ?>)">
                            <input type="hidden" name="groups[<?= $gi ?>][all_ids][]" value="<?= (int)$c['id'] ?>">
                        </td>
                        <td><strong>#<?= (int)$c['id'] ?></strong></td>
                        <td><code><?= htmlspecialchars($c['ab_catalog_number'] ?? '—') ?></code></td>
                        <td>
                            <?= htmlspecialchars($c['compound_name'] ?? '') ?>
                            <?php if ($isSuggested): ?><span class="suggested-badge">SUGGESTED</span><?php endif; ?>
                        </td>
                        <td><code><?= htmlspecialchars($c['cas_number'] ?? '') ?: '<span class="empty-cell">—</span>' ?></code></td>
                        <td class="field-cell" title="<?= htmlspecialchars($c['inchi_key'] ?? '') ?>"><code><?= htmlspecialchars(substr($c['inchi_key'] ?? '', 0, 24)) ?: '<span class="empty-cell">—</span>' ?></code></td>
                        <td><code><?= htmlspecialchars($c['molecular_formula'] ?? '') ?: '<span class="empty-cell">—</span>' ?></code></td>
                        <td><?= (int)($c['listings_count'] ?? 0) ?></td>
                        <td><?= (int)($c['orders_count'] ?? 0) ?></td>
                        <td><span class="score-pill"><?= (int)($c['_score'] ?? 0) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            // Inline warnings for risky merges
            $warnings = [];
            $hasOrders = false; $hasListings = false;
            foreach ($group['compounds'] as $c) {
                if (($c['orders_count']   ?? 0) > 0) $hasOrders = true;
                if (($c['listings_count'] ?? 0) > 0) $hasListings = true;
            }
            if ($hasOrders) $warnings[] = '⚠️ Some compounds have order history — those orders will be reassigned to the keeper.';
            if ($hasListings) $warnings[] = 'ℹ️ Supplier listings will transfer to the keeper; duplicates are NOT auto-removed.';
            ?>
            <?php if (!empty($warnings)): ?>
                <div style="padding: 10px 16px; background: #fffbeb; font-size: 12px; color: #92400e; border-top: 1px solid #fef3c7;">
                    <?= implode('<br>', $warnings) ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="sticky-actions card" style="margin-bottom: 0;">
        <span class="count-display">
            <span id="merge-count">0</span> group(s) selected for merge
        </span>
        <button type="submit" class="btn btn-danger" id="merge-btn" disabled>
            🧬 Merge selected groups
        </button>
        <a href="?" class="btn btn-outline">Cancel</a>
    </div>
</form>
<?php endif; ?>

<div class="card">
    <p>
        <a href="/admin" class="btn-outline btn">← Back to Admin</a>
        <a href="/admin_products.php" class="btn-outline btn">📦 Manage Products</a>
        <a href="/pubchem_fetch.php" class="btn-outline btn">🧪 PubChem Fetcher</a>
    </p>
</div>

<script>
function highlightKeeper(groupIdx, keeperId) {
    document.querySelectorAll('[id^="row-' + groupIdx + '-"]').forEach(tr => tr.classList.remove('keeper-row'));
    const row = document.getElementById('row-' + groupIdx + '-' + keeperId);
    if (row) row.classList.add('keeper-row');
}

function toggleAll(box) {
    document.querySelectorAll('.group-tick').forEach(cb => { cb.checked = box.checked; });
    updateMergeCount();
}

function updateMergeCount() {
    const n = document.querySelectorAll('.group-tick:checked').length;
    document.getElementById('merge-count').textContent = n;
    document.getElementById('merge-btn').disabled = n === 0;
}

function confirmMerge() {
    const n = document.querySelectorAll('.group-tick:checked').length;
    if (n === 0) {
        alert('No groups selected.');
        return false;
    }
    return confirm(
        '⚠️ Merge ' + n + ' duplicate group(s)?\n\n' +
        'For each group, the "Keep" compound absorbs all others:\n' +
        '  • Loser rows are archived and DELETED from compounds\n' +
        '  • Supplier listings transfer to the keeper\n' +
        '  • Order history, saved products, pharmacopeia refs are reassigned\n' +
        '  • 301 redirects are created for old URLs\n\n' +
        'This is irreversible from the UI. Continue?'
    );
}
</script>

</body>
</html>
