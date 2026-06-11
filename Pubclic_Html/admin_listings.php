<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/u670463068/domains/abchem.co.in/error_log');
error_reporting(E_ALL);

require_once __DIR__ . '/../private/functions.php';

// Auth
enforceSessionTimeout(900);
if (!isset($_SESSION['role']) || !checkRole('Admin')) {
    header('Location: /signin');
    exit;
}

$db      = Database::getInstance();
$message = '';
$error   = '';

if (!empty($_SESSION['flash_message'])) { $message = $_SESSION['flash_message']; unset($_SESSION['flash_message']); }
if (!empty($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

// ── Filters ────────────────────────────────────────────────────────────────
$filterSupplier = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0;
$filterStatus   = $_GET['status']   ?? '';
$filterAvail    = $_GET['avail']    ?? '';
$search         = trim($_GET['search'] ?? '');
$currentPage    = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 50;
$offset         = ($currentPage - 1) * $perPage;

// =============================================
// AJAX ENDPOINTS
// =============================================

// ── Quick status toggle ────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'toggle_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $lid    = intval($_POST['listing_id'] ?? 0);
    $newSt  = $_POST['status'] ?? 'Active';
    if (!in_array($newSt, ['Active', 'Inactive'])) { echo json_encode(['error' => 'Invalid status']); exit; }
    $db->update('supplier_listings', ['status' => $newSt], 'id = :id', ['id' => $lid]);
    logAudit('listing_status_changed', "Listing #$lid set to $newSt");
    echo json_encode(['success' => true, 'status' => $newSt]);
    exit;
}

// ── Bulk status update ─────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'bulk_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $ids   = json_decode($_POST['ids'] ?? '[]', true);
    $newSt = $_POST['status'] ?? 'Active';
    if (!is_array($ids) || empty($ids)) { echo json_encode(['error' => 'No IDs supplied']); exit; }
    if (!in_array($newSt, ['Active', 'Inactive'])) { echo json_encode(['error' => 'Invalid status']); exit; }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $db->query(
        "UPDATE supplier_listings SET status = ? WHERE id IN ($placeholders)",
        array_merge([$newSt], array_map('intval', $ids))
    );
    logAudit('listing_bulk_status', count($ids) . " listings set to $newSt");
    echo json_encode(['success' => true, 'count' => count($ids)]);
    exit;
}

// ── Delete a listing ───────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'listing_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $lid = intval($_POST['listing_id'] ?? 0);
    $cid = (int)$db->fetchValue("SELECT compound_id FROM supplier_listings WHERE id = :id", ['id' => $lid]);
    $cnt = (int)$db->fetchValue(
        "SELECT COUNT(*) FROM supplier_listings WHERE compound_id = :cid AND status = 'Active'", ['cid' => $cid]
    );
    if ($cnt <= 1) {
        $db->update('supplier_listings', ['status' => 'Inactive'], 'id = :id', ['id' => $lid]);
        logAudit('listing_deactivated', "Listing #$lid deactivated (last active)");
        echo json_encode(['success' => true, 'soft_delete' => true]);
    } else {
        $db->delete('supplier_listings', 'id = :id', ['id' => $lid]);
        logAudit('listing_deleted', "Listing #$lid deleted");
        echo json_encode(['success' => true]);
    }
    exit;
}

// =============================================
// BUILD QUERY
// =============================================
$where  = ['1=1'];
$params = [];

if ($filterSupplier) {
    $where[]  = 'sl.supplier_id = :sid';
    $params['sid'] = $filterSupplier;
}
if ($filterStatus !== '') {
    $where[]  = 'sl.status = :st';
    $params['st'] = $filterStatus;
}
if ($filterAvail !== '') {
    $where[]  = 'sl.availability = :av';
    $params['av'] = $filterAvail;
}
if ($search !== '') {
    $where[]  = '(c.compound_name LIKE :s OR c.cas_number LIKE :s OR sl.catalog_number LIKE :s OR sl.lot_number LIKE :s)';
    $params['s'] = "%$search%";
}

$whereClause = implode(' AND ', $where);

$totalRows = (int)$db->fetchValue(
    "SELECT COUNT(*)
     FROM supplier_listings sl
     JOIN compounds c ON c.id = sl.compound_id
     JOIN suppliers s ON s.id = sl.supplier_id
     WHERE $whereClause",
    $params
);

$listings = $db->fetchAll(
    "SELECT sl.*, c.compound_name, c.cas_number, c.slug AS compound_slug, c.product_type,
            s.supplier_name AS company_make, s.supplier_code, s.catalog_prefix
     FROM supplier_listings sl
     JOIN compounds c ON c.id = sl.compound_id
     JOIN suppliers s ON s.id = sl.supplier_id
     WHERE $whereClause
     ORDER BY sl.updated_at DESC, sl.id DESC
     LIMIT :lim OFFSET :off",
    array_merge($params, ['lim' => $perPage, 'off' => $offset])
);

$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Filters data
$allSuppliers = $db->fetchAll("SELECT id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name");

// Stats
$statsActive  = (int)$db->fetchValue("SELECT COUNT(*) FROM supplier_listings WHERE status = 'Active'");
$statsInStock = (int)$db->fetchValue("SELECT COUNT(*) FROM supplier_listings WHERE status = 'Active' AND availability = 'In Stock'");
$statsOnReq   = (int)$db->fetchValue("SELECT COUNT(*) FROM supplier_listings WHERE status = 'Active' AND availability = 'On Request'");

$pageTitle = $filterSupplier
    ? 'Listings — ' . ($db->fetchValue("SELECT supplier_name FROM suppliers WHERE id = :id", ['id' => $filterSupplier]) ?: 'Supplier')
    : 'All Supplier Listings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | AB Chem Admin</title>
<link rel="stylesheet" href="/styles.css">
<style>
/* Responsive stats */
.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 12px;
    margin-bottom: 24px;
}
.stat-box { padding: 14px 12px; min-width: 0; }
.stat-number { font-size: 1.55rem; }
@media (max-width: 640px) {
    .stats-row  { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .stat-box   { padding: 10px 8px; }
    .stat-number { font-size: 1.25rem; }
    .stat-desc  { font-size: .7rem; }
}
.filter-bar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: flex-end;
    margin-bottom: 20px;
}
.filter-bar select,
.filter-bar input[type="text"] {
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: .875rem;
    background: white;
    min-width: 140px;
}
.filter-bar input[type="text"] { min-width: 220px; }
.bulk-bar {
    display: flex;
    gap: 10px;
    align-items: center;
    padding: 10px 14px;
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 8px;
    margin-bottom: 14px;
    flex-wrap: wrap;
}
.bulk-bar.hidden { display: none; }
#select-all-cb { width: 16px; height: 16px; accent-color: var(--accent); cursor: pointer; }
.row-cb { width: 14px; height: 14px; accent-color: var(--accent); cursor: pointer; }
.avail-badge {
    display: inline-block;
    padding: 2px 8px; border-radius: 10px;
    font-size: .72rem; font-weight: 600;
}
.avail-in-stock    { background: #dcfce7; color: #166534; }
.avail-backorder   { background: #fef3c7; color: #92400e; }
.avail-on-request  { background: #e0f2fe; color: #0369a1; }
.avail-discontinued{ background: #f1f5f9; color: #64748b; }
</style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="admin-products-container">

    <div class="page-header">
        <h1>📋 <?= e($pageTitle) ?></h1>
        <div style="display:flex;gap:10px;">
            <a href="admin_suppliers.php" class="btn btn-outline" style="text-decoration:none;">🏭 Suppliers</a>
            <a href="admin_products.php"  class="btn btn-outline" style="text-decoration:none;">📦 Products</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success">✅ <?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error">❌ <?= e($error) ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-box"><div class="stat-number"><?= $statsActive ?></div><div class="stat-desc">Active Listings</div></div>
        <div class="stat-box"><div class="stat-number"><?= $statsInStock ?></div><div class="stat-desc">In Stock</div></div>
        <div class="stat-box"><div class="stat-number"><?= $statsOnReq ?></div><div class="stat-desc">On Request</div></div>
        <div class="stat-box"><div class="stat-number"><?= $totalRows ?></div><div class="stat-desc">Matching Filter</div></div>
    </div>

    <!-- Filters -->
    <form method="get" class="filter-bar">
        <input type="text" name="search" placeholder="Search compound, CAS, lot, catalog…"
               value="<?= e($search) ?>">

        <select name="supplier_id">
            <option value="">All Suppliers</option>
            <?php foreach ($allSuppliers as $sup): ?>
            <option value="<?= $sup['id'] ?>" <?= $filterSupplier === $sup['id'] ? 'selected' : '' ?>>
                <?= e($sup['supplier_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <select name="avail">
            <option value="">All Availability</option>
            <?php foreach (['In Stock','Backorder','On Request','Discontinued'] as $av): ?>
            <option value="<?= $av ?>" <?= $filterAvail === $av ? 'selected' : '' ?>><?= $av ?></option>
            <?php endforeach; ?>
        </select>

        <select name="status">
            <option value="">All Statuses</option>
            <option value="Active"   <?= $filterStatus === 'Active'   ? 'selected' : '' ?>>Active</option>
            <option value="Inactive" <?= $filterStatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>

        <button type="submit" class="btn btn-primary">🔍 Filter</button>
        <a href="admin_listings.php" class="btn btn-outline" style="text-decoration:none;">✕ Clear</a>
		<a href="?action=add"       class="tab-link <?= $action === 'add'       ? 'active' : '' ?>">➕ Add New</a>
    </form>

    <!-- Bulk action bar (shown when rows are checked) -->
    <div class="bulk-bar hidden" id="bulk-bar">
        <span id="bulk-count" style="font-weight:600;color:#0369a1;">0 selected</span>
        <button type="button" class="btn btn-primary" style="padding:6px 14px;font-size:.85rem;"
                onclick="bulkSetStatus('Active')">✅ Set Active</button>
        <button type="button" class="btn btn-outline" style="padding:6px 14px;font-size:.85rem;"
                onclick="bulkSetStatus('Inactive')">🚫 Set Inactive</button>
        <button type="button" class="btn btn-outline" style="padding:6px 14px;font-size:.85rem;color:var(--muted);"
                onclick="clearSelection()">✕ Clear</button>
    </div>

    <!-- Listings Table -->
    <div class="product-table-wrapper">
        <div style="overflow-x:auto;">
        <table class="product-table" id="listings-table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="select-all-cb" title="Select all"></th>
                    <th>Compound</th>
                    <th>Supplier</th>
                    <th>Catalog #</th>
                    <th>Purity</th>
                    <th>Availability</th>
                    <th>Stock Status</th>
                    <th>Lead Time</th>
                    <th>Lot #</th>
                    <th>Exp</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($listings)): ?>
                <tr>
                    <td colspan="12" class="no-results">
                        <p>No listings match the current filter.</p>
                    </td>
                </tr>
            <?php else: foreach ($listings as $sl): ?>
                <?php
                $availClass = match($sl['availability'] ?? '') {
                    'In Stock'     => 'avail-in-stock',
                    'Backorder'    => 'avail-backorder',
                    'On Request'   => 'avail-on-request',
                    'Discontinued' => 'avail-discontinued',
                    default        => 'avail-on-request',
                };
                ?>
                <tr id="listing-row-<?= $sl['id'] ?>"
                    style="<?= $sl['status'] === 'Inactive' ? 'opacity:.6;' : '' ?>">
                    <td>
                        <input type="checkbox" class="row-cb" value="<?= $sl['id'] ?>"
                               onchange="onRowCheck()">
                    </td>
                    <td>
                        <a href="admin_products.php?action=edit&id=<?= $sl['compound_id'] ?>"
                           style="font-weight:600;text-decoration:none;color:var(--accent);"
                           title="<?= e($sl['compound_name']) ?>">
                            <?= e(mb_substr($sl['compound_name'], 0, 42)) ?><?= mb_strlen($sl['compound_name']) > 42 ? '…' : '' ?>
                        </a>
                        <?php if ($sl['cas_number']): ?>
                        <div style="font-size:.76rem;color:var(--muted)"><?= e($sl['cas_number']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= e($sl['company_make']) ?></strong>
                        <div style="font-size:.76rem;color:var(--muted)"><?= e($sl['supplier_code']) ?></div>
                    </td>
                    <td><code style="font-size:.77rem"><?= e($sl['catalog_number'] ?? '—') ?></code></td>
                    <td><?= e($sl['purity'] ?? '—') ?></td>
                    <td>
                        <span class="avail-badge <?= $availClass ?>">
                            <?= e($sl['availability'] ?? '—') ?>
                        </span>
                    </td>
                    <td style="font-size:.8rem"><?= e(str_replace('_', ' ', ucfirst($sl['stock_status'] ?? '—'))) ?></td>
                    <td style="font-size:.8rem"><?= e($sl['lead_time'] ?? '—') ?></td>
                    <td style="font-size:.8rem"><?= e($sl['lot_number'] ?? '—') ?></td>
                    <td style="font-size:.8rem">
                        <?php if ($sl['expiry_date']): ?>
                            <?php $exp = strtotime($sl['expiry_date']); ?>
                            <span style="color:<?= $exp < time() ? '#ef4444' : ($exp < strtotime('+3 months') ? '#d97706' : '#166534') ?>">
                                <?= date('Y-m-d', $exp) ?>
                            </span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge status-<?= strtolower($sl['status'] ?? 'active') ?>"
                              id="status-badge-<?= $sl['id'] ?>">
                            <?= e($sl['status']) ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-btns">
                            <a href="admin_products.php?action=edit&id=<?= $sl['compound_id'] ?>#listings-panel"
                               class="action-btn" title="Edit in compound page">✏️</a>
                            <button type="button" class="action-btn"
                                    onclick="toggleListingStatus(<?= $sl['id'] ?>, '<?= $sl['status'] === 'Active' ? 'Inactive' : 'Active' ?>')"
                                    title="Toggle status">
                                <?= $sl['status'] === 'Active' ? '🚫' : '✅' ?>
                            </button>
                            <button type="button" class="action-btn delete-btn"
                                    onclick="deleteListing(<?= $sl['id'] ?>, <?= $sl['compound_id'] ?>)">🗑️</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav class="admin-pagination" aria-label="Listing pages">
            <?php
            $qs   = http_build_query(array_filter(['supplier_id' => $filterSupplier, 'status' => $filterStatus,
                                                    'avail' => $filterAvail, 'search' => $search]));
            $base = 'admin_listings.php?' . ($qs ? $qs . '&' : '');
            $prev = $currentPage > 1 ? $currentPage - 1 : null;
            $next = $currentPage < $totalPages ? $currentPage + 1 : null;
            ?>
            <a href="<?= $prev ? $base . 'page=' . $prev : '#' ?>"
               class="<?= $prev ? '' : 'page-disabled' ?>">&laquo; Prev</a>
            <?php
            $start = max(1, $currentPage - 3);
            $end   = min($totalPages, $currentPage + 3);
            if ($start > 1) echo '<span>…</span>';
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="<?= $base . 'page=' . $i ?>"
                   class="<?= $i === $currentPage ? 'page-active' : '' ?>"><?= $i ?></a>
            <?php endfor;
            if ($end < $totalPages) echo '<span>…</span>';
            ?>
            <a href="<?= $next ? $base . 'page=' . $next : '#' ?>"
               class="<?= $next ? '' : 'page-disabled' ?>">Next &raquo;</a>
            <span style="margin-left:8px;font-size:.82rem;color:var(--muted);">
                Page <?= $currentPage ?> of <?= $totalPages ?>
                (<?= number_format($totalRows) ?> listings)
            </span>
        </nav>
        <?php endif; ?>
    </div>

</div>

<?php include 'footer.php'; ?>
<script src="/js/utils.js" defer></script>
<script>
/* ── Checkbox selection ───────────────────────────────────────────── */
document.getElementById('select-all-cb').addEventListener('change', function () {
    document.querySelectorAll('.row-cb').forEach(function (cb) { cb.checked = this.checked; }, this);
    updateBulkBar();
});

function onRowCheck() { updateBulkBar(); }

function updateBulkBar() {
    const checked = Array.from(document.querySelectorAll('.row-cb:checked'));
    const bar     = document.getElementById('bulk-bar');
    const cnt     = document.getElementById('bulk-count');
    bar.classList.toggle('hidden', checked.length === 0);
    cnt.textContent = checked.length + ' selected';
}

function clearSelection() {
    document.querySelectorAll('.row-cb, #select-all-cb').forEach(function (cb) { cb.checked = false; });
    updateBulkBar();
}

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.row-cb:checked')).map(function (cb) { return cb.value; });
}

/* ── Single listing status toggle ────────────────────────────────── */
async function toggleListingStatus(listingId, newStatus) {
    const fd = new FormData();
    fd.append('listing_id', listingId);
    fd.append('status', newStatus);
    const res  = await fetch('?ajax=toggle_status', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.error) { alert('❌ ' + data.error); return; }
    const badge = document.getElementById('status-badge-' + listingId);
    if (badge) {
        badge.className   = 'status-badge status-' + newStatus.toLowerCase();
        badge.textContent = newStatus;
    }
    const row = document.getElementById('listing-row-' + listingId);
    if (row) {
        row.style.opacity = newStatus === 'Inactive' ? '0.6' : '1';
        // Flip the toggle button label
        const btn = row.querySelector('button[onclick*="toggleListingStatus"]');
        if (btn) {
            const nextStatus = newStatus === 'Active' ? 'Inactive' : 'Active';
            btn.setAttribute('onclick', `toggleListingStatus(${listingId}, '${nextStatus}')`);
            btn.textContent  = newStatus === 'Active' ? '🚫' : '✅';
        }
    }
}

/* ── Bulk status update ───────────────────────────────────────────── */
async function bulkSetStatus(newStatus) {
    const ids = getSelectedIds();
    if (!ids.length) return;
    const fd = new FormData();
    fd.append('ids',    JSON.stringify(ids));
    fd.append('status', newStatus);
    const res  = await fetch('?ajax=bulk_status', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.error) { alert('❌ ' + data.error); return; }
    ids.forEach(function (id) {
        const badge = document.getElementById('status-badge-' + id);
        if (badge) {
            badge.className   = 'status-badge status-' + newStatus.toLowerCase();
            badge.textContent = newStatus;
        }
        const row = document.getElementById('listing-row-' + id);
        if (row) row.style.opacity = newStatus === 'Inactive' ? '0.6' : '1';
    });
    clearSelection();
    alert('✅ ' + data.count + ' listing(s) set to ' + newStatus + '.');
}

/* ── Delete listing ──────────────────────────────────────────────── */
async function deleteListing(listingId, compoundId) {
    if (!confirm('Delete this listing? If it is the only active one it will be deactivated instead.')) return;
    const fd = new FormData();
    fd.append('listing_id',  listingId);
    fd.append('compound_id', compoundId);
    const res  = await fetch('?ajax=listing_delete', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.error) { alert('❌ ' + data.error); return; }
    if (data.soft_delete) {
        toggleListingStatus(listingId, 'Inactive');
        alert('ℹ️ Last active listing — marked Inactive instead of deleted.');
    } else {
        const row = document.getElementById('listing-row-' + listingId);
        if (row) row.remove();
    }
}
</script>
</body>
</html>
