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

// ── Restore flash ──
if (!empty($_SESSION['flash_message'])) { $message = $_SESSION['flash_message']; unset($_SESSION['flash_message']); }
if (!empty($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

$action = $_GET['action'] ?? 'list';
$editId = isset($_GET['id']) ? intval($_GET['id']) : 0;

// =============================================
// POST HANDLERS
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // ── ADD or UPDATE supplier ─────────────────────────────────────────
    if ($postAction === 'add' || $postAction === 'update') {
        $data = [
            'supplier_code'    => strtoupper(trim($_POST['supplier_code'] ?? '')),
            'supplier_name'    => trim($_POST['supplier_name'] ?? ''),
            'contact_person'   => trim($_POST['contact_person'] ?? '') ?: null,
            'contact_email'    => filter_var(trim($_POST['contact_email'] ?? ''), FILTER_SANITIZE_EMAIL) ?: null,
            'contact_phone'    => trim($_POST['contact_phone'] ?? '') ?: null,
            'address'          => trim($_POST['address'] ?? '') ?: null,
            'city'             => trim($_POST['city'] ?? '') ?: null,
            'country'          => trim($_POST['country'] ?? '') ?: null,
            'website'          => filter_var(trim($_POST['website'] ?? ''), FILTER_SANITIZE_URL) ?: null,
            'catalog_prefix'   => trim($_POST['catalog_prefix'] ?? '') ?: null,
            'default_lead_time'=> trim($_POST['default_lead_time'] ?? '') ?: null,
            'default_currency' => trim($_POST['default_currency'] ?? 'INR'),
            'import_format'    => trim($_POST['import_format'] ?? '') ?: null,
            'notes'            => trim($_POST['notes'] ?? '') ?: null,
            'is_active'        => isset($_POST['is_active']) ? 1 : 0,
        ];

        if (empty($data['supplier_name'])) {
            $error = "Supplier name is required.";
        } else {
            try {
                if ($postAction === 'add') {
                    $newId = $db->insert('suppliers', $data);
                    logAudit('supplier_added', "Added supplier: {$data['supplier_name']}");
                    $_SESSION['flash_message'] = "Supplier \"{$data['supplier_name']}\" added successfully.";
                    header("Location: ?action=edit&id=$newId");
                } else {
                    $supId = intval($_POST['id']);
                    $db->update('suppliers', $data, 'id = :id', ['id' => $supId]);
                    logAudit('supplier_updated', "Updated supplier ID: $supId");
                    $_SESSION['flash_message'] = "Supplier updated.";
                    header("Location: ?action=edit&id=$supId");
                }
                exit;
            } catch (Exception $e) {
                $error = "Save failed: " . $e->getMessage();
            }
        }
    }

    // ── TOGGLE active status ───────────────────────────────────────────
    if ($postAction === 'toggle_active') {
        $supId   = intval($_POST['id']);
        $current = (int)$db->fetchValue("SELECT is_active FROM suppliers WHERE id = :id", ['id' => $supId]);
        $newVal  = $current ? 0 : 1;
        $db->update('suppliers', ['is_active' => $newVal], 'id = :id', ['id' => $supId]);
        logAudit('supplier_toggled', "Supplier #$supId toggled is_active to $newVal");
        $_SESSION['flash_message'] = "Supplier status updated.";
        header('Location: ?action=list');
        exit;
    }
}

// ── DELETE (GET) ───────────────────────────────────────────────────────────
if ($action === 'delete' && $editId) {
    // Check if supplier has listings before deleting
    $listingCount = (int)$db->fetchValue(
        "SELECT COUNT(*) FROM supplier_listings WHERE supplier_id = :id", ['id' => $editId]
    );
    if ($listingCount > 0) {
        $_SESSION['flash_error'] = "Cannot delete — this supplier has $listingCount listing(s). Deactivate it instead.";
    } else {
        $db->delete('suppliers', 'id = :id', ['id' => $editId]);
        logAudit('supplier_deleted', "Deleted supplier ID: $editId");
        $_SESSION['flash_message'] = "Supplier deleted.";
    }
    header('Location: ?action=list');
    exit;
}

// =============================================
// FETCH DATA
// =============================================
$suppliers   = $db->fetchAll(
    "SELECT s.*,
            COUNT(sl.id) AS listing_count,
            COUNT(DISTINCT sl.compound_id) AS compound_count
     FROM suppliers s
     LEFT JOIN supplier_listings sl ON sl.supplier_id = s.id AND sl.status = 'Active'
     GROUP BY s.id
     ORDER BY s.supplier_name"
);

$editSupplier = null;
if (in_array($action, ['edit', 'add'])) {
    if ($action === 'edit' && $editId) {
        $editSupplier = $db->fetchOne("SELECT * FROM suppliers WHERE id = :id", ['id' => $editId]);
        if (!$editSupplier) { $error = "Supplier not found."; $action = 'list'; }
    }
}

// Stats
$totalSuppliers  = count($suppliers);
$activeSuppliers = count(array_filter($suppliers, fn($s) => $s['is_active']));
$totalListings   = (int)$db->fetchValue("SELECT COUNT(*) FROM supplier_listings WHERE status = 'Active'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Supplier Management | AB Chem Admin</title>
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
    .stats-row  { grid-template-columns: repeat(3, 1fr); gap: 8px; }
    .stat-box   { padding: 10px 8px; }
    .stat-number { font-size: 1.25rem; }
    .stat-desc  { font-size: .7rem; }
}
.supplier-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
    margin-top: 4px;
}
.supplier-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px 22px;
    transition: box-shadow .2s, border-color .2s;
    position: relative;
}
.supplier-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.08); border-color: var(--accent); }
.supplier-card.inactive { opacity: .65; }
.supplier-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}
.supplier-name { font-size: 1.05rem; font-weight: 700; color: var(--primary); margin: 0 0 2px 0; }
.supplier-code { font-size: .78rem; color: var(--muted); font-family: monospace; }
.supplier-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px; font-size: .75rem; font-weight: 600;
}
.badge-active   { background: #dcfce7; color: #166534; }
.badge-inactive { background: #f1f5f9; color: #64748b; }
.supplier-meta { font-size: .83rem; color: var(--muted); margin-bottom: 14px; }
.supplier-meta span { display: block; margin-bottom: 3px; }
.supplier-stats {
    display: flex; gap: 16px;
    padding: 10px 12px;
    background: #f8fafc;
    border-radius: 8px;
    margin-bottom: 14px;
}
.supplier-stat { text-align: center; flex: 1; }
.supplier-stat strong { display: block; font-size: 1.2rem; color: var(--primary); }
.supplier-stat small { color: var(--muted); font-size: .73rem; }
.supplier-card-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.prefix-tag {
    display: inline-block;
    background: #eff6ff; color: #1d4ed8;
    padding: 2px 10px; border-radius: 6px;
    font-family: monospace; font-size: .82rem;
}
</style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="admin-products-container">

    <div class="page-header">
        <h1>🏭 Supplier Management</h1>
        <a href="admin_products.php" class="btn btn-outline" style="text-decoration:none;">← Back to Products</a>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success">✅ <?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error">❌ <?= e($error) ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-box"><div class="stat-number"><?= $totalSuppliers ?></div><div class="stat-desc">Total Suppliers</div></div>
        <div class="stat-box"><div class="stat-number"><?= $activeSuppliers ?></div><div class="stat-desc">Active Suppliers</div></div>
        <div class="stat-box"><div class="stat-number"><?= $totalListings ?></div><div class="stat-desc">Active Listings</div></div>
    </div>

    <!-- Tabs -->
    <div class="nav-tabs">
        <a href="?action=list" class="tab-link <?= $action === 'list' ? 'active' : '' ?>">📋 All Suppliers</a>
        <a href="?action=add"  class="tab-link <?= $action === 'add'  ? 'active' : '' ?>">➕ Add Supplier</a>
        <a href="admin_products.php" class="tab-link">📦 Products</a>
    </div>

    <!-- ==================== SUPPLIER LIST ==================== -->
    <?php if ($action === 'list'): ?>

    <?php if (empty($suppliers)): ?>
    <div class="form-card" style="text-align:center;padding:48px;">
        <p style="font-size:1.3rem;margin-bottom:8px;">🏭 No suppliers yet</p>
        <a href="?action=add" class="btn btn-primary" style="text-decoration:none;">➕ Add First Supplier</a>
    </div>
    <?php else: ?>
    <div class="supplier-grid">
        <?php foreach ($suppliers as $sup): ?>
        <div class="supplier-card <?= $sup['is_active'] ? '' : 'inactive' ?>">
            <div class="supplier-card-header">
                <div>
                    <p class="supplier-name"><?= e($sup['supplier_name']) ?></p>
                    <span class="supplier-code"><?= e($sup['supplier_code']) ?></span>
                </div>
                <span class="supplier-badge <?= $sup['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                    <?= $sup['is_active'] ? '● Active' : '○ Inactive' ?>
                </span>
            </div>

            <div class="supplier-stats">
                <div class="supplier-stat">
                    <strong><?= $sup['listing_count'] ?></strong>
                    <small>Listings</small>
                </div>
                <div class="supplier-stat">
                    <strong><?= $sup['compound_count'] ?></strong>
                    <small>Compounds</small>
                </div>
                <?php if ($sup['catalog_prefix']): ?>
                <div class="supplier-stat" style="flex:2;text-align:left;">
                    <small style="display:block;margin-bottom:3px;">Catalog prefix</small>
                    <span class="prefix-tag"><?= e($sup['catalog_prefix']) ?>####</span>
                </div>
                <?php endif; ?>
            </div>

            <div class="supplier-meta">
                <?php if ($sup['contact_person']): ?>
                    <span>👤 <?= e($sup['contact_person']) ?></span>
                <?php endif; ?>
                <?php if ($sup['contact_email']): ?>
                    <span>✉️ <?= e($sup['contact_email']) ?></span>
                <?php endif; ?>
                <?php if ($sup['city'] || $sup['country']): ?>
                    <span>📍 <?= e(implode(', ', array_filter([$sup['city'], $sup['country']]))) ?></span>
                <?php endif; ?>
                <?php if ($sup['default_lead_time']): ?>
                    <span>⏱ Default lead time: <?= e($sup['default_lead_time']) ?></span>
                <?php endif; ?>
            </div>

            <div class="supplier-card-actions">
                <a href="?action=edit&id=<?= $sup['id'] ?>" class="action-btn">✏️ Edit</a>
                <a href="admin_listings.php?supplier_id=<?= $sup['id'] ?>" class="action-btn">📋 Listings</a>
                <form method="post" style="display:inline;" onsubmit="return confirm('Toggle active status for this supplier?')">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id"     value="<?= $sup['id'] ?>">
                    <button type="submit" class="action-btn">
                        <?= $sup['is_active'] ? '🚫 Deactivate' : '✅ Activate' ?>
                    </button>
                </form>
                <?php if ($sup['listing_count'] == 0): ?>
                <a href="?action=delete&id=<?= $sup['id'] ?>" class="action-btn delete-btn"
                   onclick="return confirm('Permanently delete this supplier?')">🗑️ Delete</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ==================== ADD / EDIT SUPPLIER ==================== -->
    <?php elseif ($action === 'add' || ($action === 'edit' && $editSupplier)): ?>
    <?php
    $isEdit = ($action === 'edit');
    $s      = $isEdit ? $editSupplier : [];
    ?>
    <div class="form-card">
        <h2><?= $isEdit ? 'Edit: ' . e($s['supplier_name'] ?? '') : 'Add New Supplier' ?></h2>
        <p class="form-subtitle">Fields marked <span style="color:#ef4444">*</span> are required.</p>

        <form method="post" id="supplier-form">
            <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'add' ?>">
            <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <?php endif; ?>

            <div class="form-grid">
                <!-- Supplier Name -->
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Supplier Name <span style="color:#ef4444">*</span></label>
                    <input type="text" name="supplier_name" value="<?= e($s['supplier_name'] ?? '') ?>"
                           required class="form-input" placeholder="e.g. Sunveda Healthcare">
                </div>
                <!-- Supplier Code -->
                <div class="form-group">
                    <label class="form-label">Supplier Code</label>
                    <input type="text" name="supplier_code" value="<?= e($s['supplier_code'] ?? '') ?>"
                           class="form-input" placeholder="e.g. SUNVEDA" style="text-transform:uppercase">
                    <small style="color:var(--muted)">Short identifier used internally.</small>
                </div>
                <!-- Catalog Prefix -->
                <div class="form-group">
                    <label class="form-label">Catalog Number Prefix</label>
                    <input type="text" name="catalog_prefix" value="<?= e($s['catalog_prefix'] ?? '') ?>"
                           class="form-input" placeholder="e.g. SUN-IMP-">
                    <small style="color:var(--muted)">Auto-appended when generating catalog numbers.</small>
                </div>
                <!-- Contact Person -->
                <div class="form-group">
                    <label class="form-label">Contact Person</label>
                    <input type="text" name="contact_person" value="<?= e($s['contact_person'] ?? '') ?>"
                           class="form-input">
                </div>
                <!-- Contact Email -->
                <div class="form-group">
                    <label class="form-label">Contact Email</label>
                    <input type="email" name="contact_email" value="<?= e($s['contact_email'] ?? '') ?>"
                           class="form-input">
                </div>
                <!-- Contact Phone -->
                <div class="form-group">
                    <label class="form-label">Contact Phone</label>
                    <input type="text" name="contact_phone" value="<?= e($s['contact_phone'] ?? '') ?>"
                           class="form-input">
                </div>
                <!-- Website -->
                <div class="form-group">
                    <label class="form-label">Website</label>
                    <input type="url" name="website" value="<?= e($s['website'] ?? '') ?>"
                           class="form-input" placeholder="https://">
                </div>
                <!-- Address -->
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" value="<?= e($s['address'] ?? '') ?>"
                           class="form-input">
                </div>
                <!-- City -->
                <div class="form-group">
                    <label class="form-label">City</label>
                    <input type="text" name="city" value="<?= e($s['city'] ?? '') ?>"
                           class="form-input">
                </div>
                <!-- Country -->
                <div class="form-group">
                    <label class="form-label">Country</label>
                    <input type="text" name="country" value="<?= e($s['country'] ?? '') ?>"
                           class="form-input">
                </div>
                <!-- Default Lead Time -->
                <div class="form-group">
                    <label class="form-label">Default Lead Time</label>
                    <input type="text" name="default_lead_time" value="<?= e($s['default_lead_time'] ?? '') ?>"
                           class="form-input" placeholder="e.g. 7-14 days">
                </div>
                <!-- Default Currency -->
                <div class="form-group">
                    <label class="form-label">Default Currency</label>
                    <select name="default_currency" class="form-select">
                        <?php foreach (['INR','USD','EUR','GBP','JPY','CNY'] as $cur): ?>
                        <option value="<?= $cur ?>" <?= ($s['default_currency'] ?? 'INR') === $cur ? 'selected' : '' ?>>
                            <?= $cur ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Import Format -->
                <div class="form-group">
                    <label class="form-label">Import Format</label>
                    <input type="text" name="import_format" value="<?= e($s['import_format'] ?? '') ?>"
                           class="form-input" placeholder="e.g. CSV/Excel column mapping notes">
                </div>
            </div>

            <!-- Notes (full width) -->
            <div class="form-group" style="margin-top:8px;">
                <label class="form-label">Internal Notes</label>
                <textarea name="notes" rows="3" class="form-textarea"
                          placeholder="Payment terms, SLA, import compliance notes…"><?= e($s['notes'] ?? '') ?></textarea>
            </div>

            <!-- Active toggle -->
            <div class="form-group" style="margin-top:8px;">
                <label class="checkbox-label" style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           <?= ($s['is_active'] ?? 1) ? 'checked' : '' ?>
                           style="width:18px;height:18px;accent-color:var(--accent)">
                    <span>Supplier is <strong>Active</strong></span>
                </label>
            </div>

            <div class="form-actions" style="margin-top:24px;">
                <button type="submit" class="btn btn-primary">💾 Save Supplier</button>
                <a href="?action=list" class="btn btn-outline" style="text-decoration:none;">✕ Cancel</a>
                <?php if ($isEdit): ?>
                <a href="admin_listings.php?supplier_id=<?= $s['id'] ?>" class="btn btn-outline" style="text-decoration:none;">
                    📋 View Listings
                </a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($isEdit): ?>
        <!-- Listing count summary -->
        <?php
        $supListings = $db->fetchAll(
            "SELECT sl.*, c.compound_name, c.cas_number
             FROM supplier_listings sl
             JOIN compounds c ON c.id = sl.compound_id
             WHERE sl.supplier_id = :sid
             ORDER BY sl.status DESC, c.compound_name
             LIMIT 20",
            ['sid' => $s['id']]
        );
        $supListingTotal = (int)$db->fetchValue(
            "SELECT COUNT(*) FROM supplier_listings WHERE supplier_id = :sid", ['sid' => $s['id']]
        );
        ?>
        <?php if ($supListingTotal > 0): ?>
        <div style="margin-top:32px;border-top:2px solid var(--border);padding-top:24px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h3 style="margin:0;">📋 Listings from this Supplier
                    <span style="background:#0369a1;color:white;font-size:.75rem;padding:2px 10px;border-radius:12px;margin-left:8px;"><?= $supListingTotal ?></span>
                </h3>
                <a href="admin_listings.php?supplier_id=<?= $s['id'] ?>" class="btn btn-outline" style="text-decoration:none;font-size:.85rem;">
                    View All →
                </a>
            </div>
            <div style="overflow-x:auto;">
            <table class="product-table">
                <thead><tr><th>Compound</th><th>CAS</th><th>Catalog #</th><th>Purity</th><th>Availability</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($supListings as $sl): ?>
                <tr>
                    <td>
                        <a href="admin_products.php?action=edit&id=<?= $sl['compound_id'] ?>" style="font-weight:600;text-decoration:none;color:var(--accent);">
                            <?= e(mb_substr($sl['compound_name'], 0, 50)) ?>
                        </a>
                    </td>
                    <td><code style="font-size:.8rem"><?= e($sl['cas_number'] ?? '—') ?></code></td>
                    <td><code style="font-size:.8rem"><?= e($sl['catalog_number'] ?? '—') ?></code></td>
                    <td><?= e($sl['purity'] ?? '—') ?></td>
                    <td><?= e($sl['availability'] ?? '—') ?></td>
                    <td><span class="status-badge status-<?= strtolower($sl['status'] ?? 'active') ?>"><?= e($sl['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php if ($supListingTotal > 20): ?>
            <p style="color:var(--muted);font-size:.85rem;margin-top:10px;">
                Showing 20 of <?= $supListingTotal ?> listings.
                <a href="admin_listings.php?supplier_id=<?= $s['id'] ?>">View all →</a>
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

    </div>
    <?php endif; ?>

</div>

<?php include 'footer.php'; ?>
<script src="/js/utils.js" defer></script>
</body>
</html>
