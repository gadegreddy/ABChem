<?php
/**
 * CAS Verifier — three-stage badge against PubChem synonyms.
 *
 * Separate from pubchem_fetch.php so the enrichment script stays focused
 * on properties/images and this stays focused on CAS verification.
 *
 * Verification source: the `synonyms` column already populated by
 * pubchem_fetch.php — no extra PubChem API calls. Run pubchem_fetch FIRST
 * for any compound missing synonyms; then run this to set cas_verified.
 *
 * States set on compounds.cas_verified:
 *   verified   — stored CAS is the ONLY CAS in PubChem synonyms
 *   multi      — stored CAS matches, PubChem also lists others (saved to cas_other)
 *   unverified — stored CAS is NOT present in PubChem synonyms (alternates in cas_other)
 *   unchecked  — compound has no synonyms yet (run pubchem_fetch first)
 */
require_once __DIR__ . '/../private/functions.php';

// Web-only gates
if (php_sapi_name() !== 'cli') {
    enforceSessionTimeout(900);
    if (!isset($_SESSION['role']) || !checkRole('Admin')) {
        header('Location: /signin');
        exit;
    }
}

error_reporting(E_ALL);
ini_set('display_errors', php_sapi_name() === 'cli' ? '1' : '0');
ini_set('log_errors', '1');

class CasVerifier {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Extract CAS-shaped strings from a pipe-separated synonyms field,
     * validated against the official CAS check-digit algorithm.
     */
    public static function extractCasNumbers(string $synonymsField): array {
        if ($synonymsField === '' || $synonymsField === 'NA') return [];
        $synonyms = array_map('trim', explode('|', $synonymsField));

        $found = [];
        foreach ($synonyms as $syn) {
            if (!preg_match_all('/\b(\d{2,7})-(\d{2})-(\d)\b/', $syn, $matches, PREG_SET_ORDER)) continue;
            foreach ($matches as $m) {
                $cas = $m[0];
                if (self::isValidCas($cas) && !in_array($cas, $found, true)) {
                    $found[] = $cas;
                }
            }
        }
        return $found;
    }

    /**
     * CAS check-digit validation. Last digit = sum(d_i * i) mod 10,
     * where i = position from the right (excluding the check digit itself).
     */
    public static function isValidCas(string $cas): bool {
        $digits = preg_replace('/[^0-9]/', '', $cas);
        $len = strlen($digits);
        if ($len < 5 || $len > 10) return false;
        $check = (int)$digits[$len - 1];
        $sum = 0;
        for ($i = 0; $i < $len - 1; $i++) {
            $sum += (int)$digits[$len - 2 - $i] * ($i + 1);
        }
        return ($sum % 10) === $check;
    }

    /**
     * Verify a single compound. Returns the new state + update payload.
     */
    public function verifyOne(array $product): array {
        $synonyms      = $product['synonyms'] ?? '';
        $storedCas     = trim((string)($product['cas_number'] ?? ''));
        $storedCasNorm = ($storedCas !== '' && $storedCas !== 'NA') ? $storedCas : '';

        // No synonyms → can't verify
        if ($synonyms === '' || $synonyms === 'NA') {
            return [
                'state'   => 'unchecked',
                'reason'  => 'No synonyms — run PubChem fetch first',
                'update'  => ['cas_verified' => 'unchecked'],
                'others'  => [],
            ];
        }

        $pubchemCasList = self::extractCasNumbers($synonyms);

        if (empty($pubchemCasList)) {
            // Synonyms exist but no CAS strings found
            $state = $storedCasNorm !== '' ? 'unverified' : 'unchecked';
            return [
                'state'  => $state,
                'reason' => 'No CAS numbers in PubChem synonyms',
                'update' => ['cas_verified' => $state, 'cas_other' => null],
                'others' => [],
            ];
        }

        // No stored CAS — adopt PubChem's first as primary
        if ($storedCasNorm === '') {
            $state    = count($pubchemCasList) > 1 ? 'multi' : 'verified';
            $casOther = count($pubchemCasList) > 1
                ? implode('|', array_slice($pubchemCasList, 1, 10))
                : null;
            return [
                'state'  => $state,
                'reason' => 'CAS adopted from PubChem',
                'update' => [
                    'cas_number'   => $pubchemCasList[0],
                    'cas_verified' => $state,
                    'cas_other'    => $casOther,
                ],
                'others' => array_slice($pubchemCasList, 1),
            ];
        }

        // Compare stored vs PubChem list
        $matched      = in_array($storedCasNorm, $pubchemCasList, true);
        $otherCasArr  = array_values(array_filter($pubchemCasList, fn($c) => $c !== $storedCasNorm));

        if ($matched) {
            $state    = empty($otherCasArr) ? 'verified' : 'multi';
            $casOther = empty($otherCasArr) ? null : implode('|', array_slice($otherCasArr, 0, 10));
            return [
                'state'  => $state,
                'reason' => $state === 'verified' ? 'Single CAS — exact match' : 'CAS matches; alternates stored',
                'update' => ['cas_verified' => $state, 'cas_other' => $casOther],
                'others' => $otherCasArr,
            ];
        }

        // Stored CAS does not appear in PubChem synonyms
        return [
            'state'  => 'unverified',
            'reason' => 'Stored CAS not in PubChem synonyms — review',
            'update' => ['cas_verified' => 'unverified', 'cas_other' => implode('|', array_slice($pubchemCasList, 0, 10))],
            'others' => $pubchemCasList,
        ];
    }

    /**
     * Sync alternate CAS numbers for one compound to the junction table.
     * Replaces ONLY rows from the given source so supplier-sourced aliases
     * (added during import on CAS conflicts) survive PubChem re-verifies.
     *
     * @param int      $compoundId
     * @param string[] $aliases    Alternate CAS numbers (excluding primary)
     * @param string   $source     'pubchem' | 'supplier' | 'manual' | 'crossref'
     */
    public function syncAliases(int $compoundId, array $aliases, string $source = 'pubchem'): void {
        // Scoped wipe — only this source's rows are replaced
        $this->db->delete(
            'compound_cas_aliases',
            'compound_id = :cid AND source = :src',
            ['cid' => $compoundId, 'src' => $source]
        );

        // Insert fresh ones, preserving the source's order via `position`
        $position = 0;
        foreach ($aliases as $cas) {
            $cas = trim((string)$cas);
            if ($cas === '' || !self::isValidCas($cas)) continue;
            try {
                $this->db->insert('compound_cas_aliases', [
                    'compound_id' => $compoundId,
                    'cas_number'  => $cas,
                    'source'      => $source,
                    'position'    => $position++,
                ]);
            } catch (Exception $e) {
                // UNIQUE KEY collision = same alias inserted twice; ignore
                error_log("syncAliases: skipped duplicate $cas for compound $compoundId");
            }
        }

        // Refresh cas_other cache column = pipe-join of ALL aliases (any source)
        // so the product page and search reflect supplier conflicts immediately,
        // not just after the next PubChem re-verify.
        $all = $this->db->fetchAll(
            "SELECT cas_number FROM compound_cas_aliases WHERE compound_id = :cid ORDER BY source = 'pubchem' DESC, position",
            ['cid' => $compoundId]
        );
        $casOther = empty($all) ? null : implode('|', array_slice(array_column($all, 'cas_number'), 0, 10));
        $this->db->update('compounds', ['cas_other' => $casOther], 'id = :id', ['id' => $compoundId]);
    }

    /**
     * Run verification across many compounds. $ids = null → all active.
     * Returns counts + a per-state breakdown.
     */
    public function verifyBatch(?array $ids = null, int $limit = 0): array {
        $where  = "status = 'Active'";
        $params = [];
        if ($ids !== null && !empty($ids)) {
            $placeholders = [];
            foreach ($ids as $i => $id) {
                $key = "id_$i";
                $placeholders[] = ":$key";
                $params[$key]   = (int)$id;
            }
            $where = "id IN (" . implode(',', $placeholders) . ")";
        }
        $sql = "SELECT id, cas_number, cas_verified, cas_other, synonyms FROM compounds WHERE $where ORDER BY id";
        if ($limit > 0) $sql .= " LIMIT " . (int)$limit;

        $rows = $this->db->fetchAll($sql, $params);

        $counts = ['verified' => 0, 'multi' => 0, 'unverified' => 0, 'unchecked' => 0, 'changed' => 0, 'total' => 0];
        $samples = ['verified' => [], 'multi' => [], 'unverified' => [], 'unchecked' => []];

        foreach ($rows as $p) {
            $result = $this->verifyOne($p);
            $state  = $result['state'];
            $counts[$state]++;
            $counts['total']++;

            $changed = ($p['cas_verified'] !== $state)
                    || (($p['cas_other'] ?? null) !== ($result['update']['cas_other'] ?? null));
            if ($changed) {
                $this->db->update('compounds', $result['update'], 'id = :id', ['id' => $p['id']]);
                $counts['changed']++;
            }

            // Always sync the junction table so search stays consistent with
            // the latest verification result — even when the cache string is
            // unchanged the junction rows might need a refresh (e.g. when
            // we migrate sources or rerun against richer synonyms).
            $this->syncAliases((int)$p['id'], $result['others'] ?? []);

            if (count($samples[$state]) < 5) {
                $samples[$state][] = [
                    'id'      => $p['id'],
                    'cas'     => $p['cas_number'],
                    'reason'  => $result['reason'],
                    'others'  => $result['others'],
                ];
            }
        }

        logAudit('cas_verify_batch', "Verified $counts[total] compounds; $counts[changed] state changes");
        return ['counts' => $counts, 'samples' => $samples];
    }
}

// ── Handle POST actions ──────────────────────────────────────────────────────
$verifier   = new CasVerifier();
$batchStats = null;
$singleRes  = null;
$flash      = '';
$error      = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'verify_all') {
        $batchStats = $verifier->verifyBatch();
        $flash = "✅ Verified {$batchStats['counts']['total']} compounds. {$batchStats['counts']['changed']} states updated.";
    } elseif ($action === 'verify_unchecked') {
        $db = Database::getInstance();
        $ids = array_column(
            $db->fetchAll("SELECT id FROM compounds WHERE status='Active' AND (cas_verified IS NULL OR cas_verified='unchecked')"),
            'id'
        );
        $batchStats = $verifier->verifyBatch($ids);
        $flash = "✅ Verified " . count($ids) . " unchecked compounds.";
    } elseif ($action === 'verify_one') {
        $id = intval($_POST['compound_id'] ?? 0);
        if ($id > 0) {
            $db = Database::getInstance();
            $p = $db->fetchOne("SELECT id, cas_number, cas_verified, cas_other, synonyms, compound_name FROM compounds WHERE id = :id", ['id' => $id]);
            if ($p) {
                $singleRes = $verifier->verifyOne($p);
                $singleRes['product'] = $p;
                $changed = ($p['cas_verified'] !== $singleRes['state'])
                        || (($p['cas_other'] ?? null) !== ($singleRes['update']['cas_other'] ?? null));
                if ($changed) {
                    $db->update('compounds', $singleRes['update'], 'id = :id', ['id' => $id]);
                    $flash = "✅ #{$id} → <strong>{$singleRes['state']}</strong>";
                } else {
                    $flash = "ℹ️ #{$id} unchanged (already {$singleRes['state']}).";
                }
                // Always sync the junction table so search-side data stays
                // consistent with the verifier's decision, even on no-op.
                $verifier->syncAliases($id, $singleRes['others'] ?? []);
            } else {
                $error = "Compound #$id not found.";
            }
        }
    } elseif ($action === 'adopt_ai_cas') {
        $id = intval($_POST['compound_id'] ?? 0);
        $ai_cas = $_POST['ai_cas'] ?? '';
        if ($id > 0 && $ai_cas) {
            $db = Database::getInstance();
            $db->update('compounds', ['cas_number' => $ai_cas], 'id = :id', ['id' => $id]);
            
            // Re-verify immediately
            $p = $db->fetchOne("SELECT id, cas_number, cas_verified, cas_other, synonyms, compound_name FROM compounds WHERE id = :id", ['id' => $id]);
            if ($p) {
                $singleRes = $verifier->verifyOne($p);
                $singleRes['product'] = $p;
                $db->update('compounds', $singleRes['update'], 'id = :id', ['id' => $id]);
                $verifier->syncAliases($id, $singleRes['others'] ?? []);
                $flash = "🤖 Adopted AI CAS {$ai_cas} for #{$id}. New state: <strong>{$singleRes['state']}</strong>";
            }
        }
    }
}

// ── Current stats for the page ───────────────────────────────────────────────
$db    = Database::getInstance();
$stats = $db->fetchAll(
    "SELECT COALESCE(cas_verified,'unchecked') AS state, COUNT(*) AS n
     FROM compounds WHERE status='Active' GROUP BY state"
);
$stateCounts = ['verified' => 0, 'multi' => 0, 'unverified' => 0, 'unchecked' => 0];
foreach ($stats as $r) $stateCounts[$r['state']] = (int)$r['n'];
$totalActive = array_sum($stateCounts);

// Filter for the listing table. Allowlist the values — they go into the SQL
// via a placeholder, but a fast allowlist also defends against typos in the
// URL silently returning the unfiltered set.
$validFilters = ['all', 'verified', 'multi', 'unverified', 'unchecked'];
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, $validFilters, true)) $filter = 'all';

if ($filter === 'all') {
    $rows = $db->fetchAll(
        "SELECT id, compound_name, cas_number, cas_verified, cas_other, synonyms, ai_predicted_cas
         FROM compounds WHERE status = 'Active'
         ORDER BY FIELD(COALESCE(cas_verified,'unchecked'),'unverified','multi','unchecked','verified'), id
         LIMIT 500"
    );
} else {
    $rows = $db->fetchAll(
        "SELECT id, compound_name, cas_number, cas_verified, cas_other, synonyms, ai_predicted_cas
         FROM compounds
         WHERE status = 'Active' AND COALESCE(cas_verified,'unchecked') = :state
         ORDER BY FIELD(COALESCE(cas_verified,'unchecked'),'unverified','multi','unchecked','verified'), id
         LIMIT 500",
        ['state' => $filter]
    );
}

$badge = [
    'verified'   => ['label' => '✓ Verified',   'bg' => '#dcfce7', 'fg' => '#166534'],
    'multi'      => ['label' => '✓ Multi',      'bg' => '#dbeafe', 'fg' => '#1e40af'],
    'unverified' => ['label' => '⚠ Unverified', 'bg' => '#fef3c7', 'fg' => '#92400e'],
    'unchecked'  => ['label' => '… Unchecked',  'bg' => '#f1f5f9', 'fg' => '#475569'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CAS Verify — AB Chem Admin</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
</head>
<body>
<?php include 'header.php'; ?>

<div class="admin-container" style="max-width:1280px;margin:0 auto;padding:28px 24px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
        <h1 style="margin:0;font-size:1.5rem;color:var(--primary);">🔍 CAS Verification</h1>
        <a href="/admin?tab=data-audit" style="font-size:.85rem;color:var(--accent);text-decoration:none;">← Back to admin</a>
    </div>

    <p style="margin:0 0 18px;color:var(--muted);font-size:.88rem;max-width:780px;line-height:1.5;">
        Reads each compound's PubChem-fetched <code>synonyms</code> column, extracts CAS-shaped strings,
        validates the check digit, and compares against the stored <code>cas_number</code>.
        No PubChem API calls — run <a href="/pubchem_fetch.php" style="color:var(--accent);">PubChem Fetcher</a> first to populate synonyms.
    </p>

    <?php if ($flash): ?><div class="message message-success" style="margin-bottom:14px;"><?= $flash ?></div><?php endif; ?>
    <?php if ($error): ?><div class="message message-error" style="margin-bottom:14px;"><?= e($error) ?></div><?php endif; ?>

    <!-- Stats strip -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:18px;">
        <?php foreach (['verified','multi','unverified','unchecked'] as $s):
            $b   = $badge[$s];
            $cnt = $stateCounts[$s];
            $pct = $totalActive > 0 ? round($cnt / $totalActive * 100, 1) : 0;
        ?>
        <a href="?filter=<?= $s ?>" style="text-decoration:none;display:block;padding:14px 16px;background:<?= $b['bg'] ?>;color:<?= $b['fg'] ?>;border-radius:10px;border:1px solid <?= $b['fg'] ?>22;transition:transform .15s;">
            <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:.85;"><?= $b['label'] ?></div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:1.6rem;font-weight:700;margin-top:2px;line-height:1;"><?= number_format($cnt) ?></div>
            <div style="font-size:.72rem;opacity:.7;margin-top:3px;"><?= $pct ?>% of catalog</div>
        </a>
        <?php endforeach; ?>
        <div style="padding:14px 16px;background:var(--surface-2);color:var(--text);border-radius:10px;border:1px solid var(--border);">
            <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:.7;">Total Active</div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:1.6rem;font-weight:700;margin-top:2px;line-height:1;"><?= number_format($totalActive) ?></div>
            <div style="font-size:.72rem;opacity:.7;margin-top:3px;">compounds</div>
        </div>
    </div>

    <!-- Action panel -->
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px 18px;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="verify_unchecked">
            <button type="submit" class="btn btn-primary" style="font-size:.85rem;">
                🚀 Verify Unchecked (<?= number_format($stateCounts['unchecked']) ?>)
            </button>
        </form>
        <form method="post" style="display:inline;" onsubmit="return confirm('Re-verify ALL <?= number_format($totalActive) ?> compounds? This re-reads synonyms and updates states.');">
            <input type="hidden" name="action" value="verify_all">
            <button type="submit" class="btn btn-outline" style="font-size:.85rem;">🔁 Re-verify All</button>
        </form>
        <form method="post" style="display:inline-flex;gap:6px;align-items:center;margin-left:auto;">
            <input type="hidden" name="action" value="verify_one">
            <input type="number" name="compound_id" placeholder="Compound ID" required min="1"
                   style="padding:7px 10px;border:1px solid var(--border);border-radius:6px;width:140px;font-size:.85rem;">
            <button type="submit" class="btn btn-outline" style="font-size:.85rem;">Verify One</button>
        </form>
    </div>

    <?php if ($batchStats): ?>
    <div style="background:#f0f9ff;border-left:4px solid var(--accent);border-radius:0 8px 8px 0;padding:12px 16px;margin-bottom:18px;font-size:.85rem;">
        <strong>Batch result:</strong>
        Verified <?= $batchStats['counts']['verified'] ?> ·
        Multi <?= $batchStats['counts']['multi'] ?> ·
        Unverified <?= $batchStats['counts']['unverified'] ?> ·
        Unchecked <?= $batchStats['counts']['unchecked'] ?> ·
        <strong>State changes: <?= $batchStats['counts']['changed'] ?></strong>
    </div>
    <?php endif; ?>

    <?php if ($singleRes): ?>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-bottom:18px;font-size:.88rem;">
        <strong>#<?= e($singleRes['product']['id']) ?> · <?= e($singleRes['product']['compound_name']) ?></strong><br>
        <span style="background:<?= $badge[$singleRes['state']]['bg'] ?>;color:<?= $badge[$singleRes['state']]['fg'] ?>;padding:2px 8px;border-radius:4px;font-size:.72rem;font-weight:700;"><?= $badge[$singleRes['state']]['label'] ?></span>
        <span style="color:var(--muted);font-size:.82rem;margin-left:8px;"><?= e($singleRes['reason']) ?></span>
        <?php if (!empty($singleRes['others'])): ?>
        <div style="margin-top:6px;"><span style="color:var(--muted);font-size:.78rem;">Other CAS:</span>
            <?php foreach ($singleRes['others'] as $oc): ?>
                <code style="background:#fef3c7;color:#78350f;padding:1px 6px;border-radius:3px;font-size:.74rem;margin-right:4px;"><?= e($oc) ?></code>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Filter pills -->
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
        <?php foreach (['all'=>'All','verified'=>'Verified','multi'=>'Multi','unverified'=>'Unverified','unchecked'=>'Unchecked'] as $f => $label):
            $active = $filter === $f;
        ?>
        <a href="?filter=<?= $f ?>" style="padding:5px 12px;border-radius:20px;font-size:.78rem;font-weight:600;text-decoration:none;border:1px solid <?= $active ? 'var(--accent)' : 'var(--border)' ?>;background:<?= $active ? 'var(--accent)' : 'var(--surface)' ?>;color:<?= $active ? '#fff' : 'var(--text)' ?>;">
            <?= e($label) ?>
        </a>
        <?php endforeach; ?>
        <span style="margin-left:auto;font-size:.78rem;color:var(--muted);align-self:center;">
            Showing <?= count($rows) ?> of <?= $filter === 'all' ? number_format($totalActive) : number_format($stateCounts[$filter] ?? 0) ?>
            <?php if (count($rows) >= 500): ?><strong>(capped at 500)</strong><?php endif; ?>
        </span>
    </div>

    <!-- Listing -->
    <div style="overflow-x:auto;background:var(--surface);border:1px solid var(--border);border-radius:12px;">
        <table class="admin-table" style="width:100%;border-collapse:collapse;font-size:.84rem;">
            <thead>
                <tr style="background:var(--surface-2);">
                    <th style="padding:9px 12px;text-align:left;font-size:.74rem;">ID</th>
                    <th style="padding:9px 12px;text-align:left;font-size:.74rem;">Compound</th>
                    <th style="padding:9px 12px;text-align:left;font-size:.74rem;">CAS</th>
                    <th style="padding:9px 12px;text-align:left;font-size:.74rem;">AI CAS</th>
                    <th style="padding:9px 12px;text-align:left;font-size:.74rem;">Status</th>
                    <th style="padding:9px 12px;text-align:left;font-size:.74rem;">Other CAS</th>
                    <th style="padding:9px 12px;text-align:left;font-size:.74rem;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    $state = $r['cas_verified'] ?: 'unchecked';
                    $b     = $badge[$state];
                    $others = !empty($r['cas_other']) ? explode('|', $r['cas_other']) : [];
                ?>
                <tr style="border-top:1px solid var(--border);">
                    <td style="padding:8px 12px;color:var(--muted);font-family:'JetBrains Mono',monospace;font-size:.78rem;"><?= $r['id'] ?></td>
                    <td style="padding:8px 12px;"><strong><?= e(mb_substr($r['compound_name'], 0, 65)) ?></strong></td>
                    <td style="padding:8px 12px;font-family:'JetBrains Mono',monospace;font-size:.78rem;"><?= e($r['cas_number'] ?: '—') ?></td>
                    <td style="padding:8px 12px;font-family:'JetBrains Mono',monospace;font-size:.78rem;color:#4338ca;font-weight:600;">
                        <?= $r['ai_predicted_cas'] ? '🤖 ' . e($r['ai_predicted_cas']) : '—' ?>
                    </td>
                    <td style="padding:8px 12px;">
                        <span style="background:<?= $b['bg'] ?>;color:<?= $b['fg'] ?>;padding:2px 8px;border-radius:4px;font-size:.7rem;font-weight:700;"><?= $b['label'] ?></span>
                    </td>
                    <td style="padding:8px 12px;font-family:'JetBrains Mono',monospace;font-size:.74rem;color:var(--muted);">
                        <?php if (!empty($others)): ?>
                            <?= e(implode(', ', array_slice($others, 0, 3))) ?>
                            <?php if (count($others) > 3): ?> <span style="opacity:.6;">+<?= count($others) - 3 ?></span><?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="padding:8px 12px;display:flex;gap:4px;">
                        <?php if (!empty($r['ai_predicted_cas']) && empty($r['cas_number'])): ?>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Adopt AI predicted CAS <?= e($r['ai_predicted_cas']) ?> as official?');">
                                <input type="hidden" name="action" value="adopt_ai_cas">
                                <input type="hidden" name="compound_id" value="<?= $r['id'] ?>">
                                <input type="hidden" name="ai_cas" value="<?= e($r['ai_predicted_cas']) ?>">
                                <button type="submit" style="font-size:.72rem;padding:3px 9px;background:#eef2ff;border:1px solid #c7d2fe;color:#4338ca;border-radius:5px;cursor:pointer;font-weight:600;" title="Adopt AI CAS">🤖 Adopt</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="verify_one">
                            <input type="hidden" name="compound_id" value="<?= $r['id'] ?>">
                            <button type="submit" style="font-size:.72rem;padding:3px 9px;background:var(--surface);border:1px solid var(--border);color:var(--text);border-radius:5px;cursor:pointer;font-weight:600;" title="Re-verify against PubChem">🔁 Verify</button>
                        </form>
                        <a href="/admin_products.php?action=edit&id=<?= $r['id'] ?>" style="font-size:.72rem;padding:3px 9px;background:var(--surface);border:1px solid var(--border);color:var(--text);border-radius:5px;text-decoration:none;font-weight:600;">✏️ Edit</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                <tr><td colspan="6" style="padding:30px;text-align:center;color:var(--muted);">No compounds match this filter.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
