<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/u670463068/domains/abchem.co.in/error_log');
error_reporting(E_ALL);
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/seller_settings.php';

// Enforce 15-minute session timeout
enforceSessionTimeout(900);

// Gate: Admins only
if (!isset($_SESSION['role']) || !checkRole('Admin')) {
    header('Location: /signin'); 
    exit;
}

$db = Database::getInstance();
$message = '';
$error = '';

// Download actions (must run before any HTML output)
if (isset($_GET['download'])) {
    if ($_GET['download'] === 'audit') downloadAuditCSV();
    if ($_GET['download'] === 'report') downloadMonthlyReportCSV();
}

$tab = $_GET['tab'] ?? 'dashboard';

// =============================================
// POST HANDLERS
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Update quote status
    if (isset($_POST['update_quote_status'])) {
        $quoteId = intval($_POST['quote_id']);
        $newStatus = $_POST['status'];
        $note = $_POST['admin_note'] ?? '';
        $quotedAmount = floatval($_POST['quoted_amount'] ?? 0);
        $validUntil = $_POST['quote_valid_until'] ?? null;
        
        $updateData = [
            'status' => $newStatus, 
            'admin_notes' => $note, 
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        if ($quotedAmount > 0) {
            $updateData['quoted_amount'] = $quotedAmount;
        }
        if ($validUntil) {
            $updateData['quote_valid_until'] = $validUntil;
        }
        
        $db->update('quote_requests', $updateData, 'id = :id', ['id' => $quoteId]);
        
        // Send email notification
        sendQuoteStatusEmail($quoteId, $newStatus, $note);
        
        // Create in-app notification
        $quote = $db->fetchOne("SELECT user_id, quote_number FROM quote_requests WHERE id = :id", ['id' => $quoteId]);
        if ($quote) {
            createNotification($quote['user_id'], 'quote', 
                "Quote #{$quote['quote_number']} Updated", 
                "Status changed to: {$newStatus}" . ($note ? " — {$note}" : ""),
                "/dashboard?tab=quotes"
            );
        }
        
        logAudit('quote_updated', "Admin updated quote #{$quoteId} to {$newStatus}");
        $message = "Quote updated and customer notified.";
    }
    
    // Delete quote
    if (isset($_POST['delete_quote'])) {
        $quoteId = intval($_POST['quote_id']);
        $db->beginTransaction();
        try {
            $db->delete('quote_items', 'quote_id = :qid', ['qid' => $quoteId]);
            $db->delete('quote_requests', 'id = :id', ['id' => $quoteId]);
            $db->commit();
            logAudit('quote_deleted', "Deleted quote #{$quoteId}");
            $message = 'Quote deleted successfully.';
        } catch (Exception $e) {
            $db->rollback();
            $error = 'Error deleting quote: ' . $e->getMessage();
        }
    }
    
    // ── Save Seller / Company Settings ──────────────────────────────────────
    if (isset($_POST['save_seller_settings'])) {
        $allowedKeys = [
            'seller_name','seller_address1','seller_address2','seller_city',
            'seller_state','seller_pin','seller_country','seller_phone',
            'seller_email','seller_website','seller_gstin','seller_pan','seller_cin',
            'tax_cgst_pct','tax_sgst_pct','tax_igst_pct',
            'bank_name','bank_account','bank_ifsc','bank_branch','bank_upi',
            'invoice_prefix','invoice_footer','invoice_terms',
        ];
        $toSave = [];
        foreach ($allowedKeys as $k) {
            if (isset($_POST[$k])) $toSave[$k] = $_POST[$k];
        }
        if (saveSellerSettings($toSave)) {
            logAudit('seller_settings_updated', 'Admin updated company/seller settings');
            $message = '✅ Seller settings saved successfully.';
        } else {
            $error = '❌ Failed to save settings — check the DB migration has been run.';
        }
    }

    // Update order status
    if (isset($_POST['update_order_status'])) {
        $orderId = intval($_POST['order_id']);
        $newStatus = $_POST['status'];
        $tracking = $_POST['tracking'] ?? null;
        
        $updateData = [
            'status' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        if ($tracking) {
            $updateData['tracking_number'] = $tracking;
        }
        
        $db->update('orders', $updateData, 'id = :id', ['id' => $orderId]);
        
        // Notify customer
        $order = $db->fetchOne("SELECT user_id, order_number FROM orders WHERE id = :id", ['id' => $orderId]);
        if ($order) {
            createNotification($order['user_id'], 'order', 
                "Order #{$order['order_number']} Updated", 
                "Status changed to: {$newStatus}",
                "/dashboard?tab=orders"
            );
        }
        
        logAudit('order_updated', "Admin updated order #{$orderId} to {$newStatus}");
        $message = "Order status updated.";
    }
    
    // Update inquiry status
    if (isset($_POST['update_inquiry_status'])) {
        $inquiryId = intval($_POST['inquiry_id']);
        $status = $_POST['inquiry_status'];
        $note = $_POST['admin_note'] ?? '';
        
        $db->update('inquiries', [
            'status' => $status, 
            'admin_note' => $note, 
            'updated_at' => date('Y-m-d H:i:s'), 
            'updated_by' => $_SESSION['user']
        ], 'id = :id', ['id' => $inquiryId]);
        
        logAudit('inquiry_updated', "Admin updated inquiry #{$inquiryId} to {$status}");
        $message = 'Inquiry updated successfully.';
    }
    
    // Delete inquiry
    if (isset($_POST['delete_inquiry'])) {
        $db->delete('inquiries', 'id = :id', ['id' => intval($_POST['inquiry_id'])]);
        logAudit('inquiry_deleted', "Deleted inquiry #{$_POST['inquiry_id']}");
        $message = 'Inquiry deleted successfully.';
    }
    
    // User management
    if (isset($_POST['update_user'])) {
        $userId = intval($_POST['user_id']);
        $role = $_POST['role'];
        $status = $_POST['status'];
        $pwd = $_POST['password'] ?? '';
        
        $updateData = ['role' => $role, 'status' => $status];
        if (!empty($pwd)) {
            $updateData['password_hash'] = password_hash($pwd, PASSWORD_DEFAULT);
        }
        
        $db->update('users', $updateData, 'id = :id', ['id' => $userId]);
        logAudit('user_updated', "Admin updated user ID: {$userId}");
        $message = 'User updated successfully.';
    }
    
    if (isset($_POST['delete_user'])) {
        $userId = intval($_POST['user_id']);
        if ($userId != $_SESSION['user_id']) {
            $db->delete('users', 'id = :id', ['id' => $userId]);
            logAudit('user_deleted', "Deleted user ID: {$userId}");
            $message = 'User deleted successfully.';
        } else {
            $error = 'You cannot delete your own account.';
        }
    }
    
    // PubChem fetch
    if (isset($_POST['pubchem_fetch']) && !empty($_POST['fetch_slug'])) {
        require_once 'pubchem_fetch.php';
        $fetcher = new PubChemFetcher();
        $result = $fetcher->lazyFetchProduct(preg_replace('/[^\w\-]/', '', $_POST['fetch_slug']));
        clearProductCache();
        logAudit('pubchem_fetch', "Admin fetched slug: {$_POST['fetch_slug']}");
        $message = isset($result['error']) ? '❌ ' . $result['error'] : '✅ PubChem data saved.';
    }
}

// =============================================
// FETCH DATA FOR DISPLAY
// =============================================

// Stats for dashboard
$stats = [
    'products' => $db->fetchValue("SELECT COUNT(*) FROM compounds WHERE status = 'Active'"),
    'users' => $db->fetchValue("SELECT COUNT(*) FROM users"),
    'pending_quotes' => $db->fetchValue("SELECT COUNT(*) FROM quote_requests WHERE status = 'new'"),
    'pending_orders' => $db->fetchValue("SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'processing')"),
    'new_inquiries' => $db->fetchValue("SELECT COUNT(*) FROM inquiries WHERE status = 'New'"),
];

// Fetch data based on tab
$quotes = $orders = $inquiries = $users = [];
$auditLogs = [];

switch ($tab) {
    case 'quotes':
        $quotes = $db->fetchAll("
            SELECT q.*, u.email as user_email, u.company_name,
                   (SELECT COUNT(*) FROM quote_items WHERE quote_id = q.id) as item_count
            FROM quote_requests q
            LEFT JOIN users u ON q.user_id = u.id
            ORDER BY q.created_at DESC
        ");
        break;
        
    case 'orders':
        $orders = $db->fetchAll("
            SELECT o.*, u.email as user_email, u.company_name,
                   (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            ORDER BY o.created_at DESC
        ");
        break;
        
    case 'inquiries':
        $inquiries = $db->fetchAll("
            SELECT * FROM inquiries ORDER BY created_at DESC
        ");
        break;
        
    case 'users':
        $users = $db->fetchAll("
            SELECT * FROM users ORDER BY created_at DESC
        ");
        break;
        
    case 'audit':
        $auditLogs = getAuditLog(500);
        break;
}

// Helper function for status badges
function statusBadge($s) {
    $map = [
        'New' => '#3b82f6', 'Replied' => '#f59e0b', 'Closed' => '#22c55e',
        'Active' => '#22c55e', 'Inactive' => '#ef4444', 'Pending' => '#f59e0b',
        'new' => '#3b82f6', 'quoted' => '#8b5cf6', 'accepted' => '#22c55e', 'rejected' => '#ef4444',
        'pending' => '#f59e0b', 'processing' => '#3b82f6', 'shipped' => '#06b6d4', 'delivered' => '#22c55e',
    ];
    $c = $map[$s] ?? '#64748b';
    return "<span style='background:{$c}22;color:{$c};padding:2px 9px;border-radius:12px;font-size:0.78rem;font-weight:500;'>$s</span>";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Admin Dashboard | AB Chem India</title>
<link rel="stylesheet" href="styles.css">

</head>
<body>
<?php include 'header.php'; ?>

<div class="admin-container">
    <div class="admin-header">
        <h1 style="color: var(--primary);">🔧 Admin Dashboard</h1>
        <div>
            <a href="admin_products.php" class="btn btn-primary">📦 Manage Products</a>
            <a href="/admin_suppliers.php" class="btn btn-primary">🏭 Supplier Mgmt</a>
            <a href="admin_listings.php" class="btn btn-primary">📋 All Supplier Listings</a>
			<a href="logout" class="btn btn-outline">Logout</a>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="message message-success">✅ <?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="message message-error">❌ <?= e($error) ?></div>
    <?php endif; ?>
    
    <!-- Tabs -->
    <div class="admin-tabs">
        <a href="?tab=dashboard" class="<?= $tab === 'dashboard' ? 'active' : '' ?>">📊 Dashboard</a>
        <a href="?tab=quotes" class="<?= $tab === 'quotes' ? 'active' : '' ?>">📝 Quotes</a>
        <a href="?tab=orders" class="<?= $tab === 'orders' ? 'active' : '' ?>">📦 Orders</a>
        <a href="?tab=inquiries" class="<?= $tab === 'inquiries' ? 'active' : '' ?>">💬 Inquiries</a>
        <a href="?tab=users" class="<?= $tab === 'users' ? 'active' : '' ?>">👥 Users</a>
        <a href="?tab=audit" class="<?= $tab === 'audit' ? 'active' : '' ?>">📋 Audit Trail</a>
        <a href="?tab=pubchem" class="<?= $tab === 'pubchem' ? 'active' : '' ?>">⚗️ PubChem</a>
		<a href="/chemspider_fetch" class="">⚗️ Chem Spider</a>
		<a href="/cas_verify.php" class="">🔍 CAS Verify</a>
        <a href="?tab=data-audit" class="<?= $tab === 'data-audit' ? 'active' : '' ?>">📊 Products Info</a>
        </div>
    
    <!-- ============================================= -->
    <!-- DASHBOARD TAB -->
    <!-- ============================================= -->
    <?php if ($tab === 'dashboard'): ?>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">📦</div>
            <div class="stat-value"><?= $stats['products'] ?></div>
            <div class="stat-label">Active Products</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['users'] ?></div>
            <div class="stat-label">Registered Users</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['pending_quotes'] ?></div>
            <div class="stat-label">Pending Quotes</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['pending_orders'] ?></div>
            <div class="stat-label">Active Orders</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['new_inquiries'] ?></div>
            <div class="stat-label">New Inquiries</div>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
        <!-- Recent Quotes -->
        <div class="order-card">
            <h3 style="margin-top: 0;">📝 Recent Quote Requests</h3>
            <?php 
            $recentQuotes = $db->fetchAll("SELECT * FROM quote_requests ORDER BY created_at DESC LIMIT 5");
            if ($recentQuotes): ?>
            <table style="width:100%;">
                <?php foreach ($recentQuotes as $q): ?>
                <tr>
                    <td><strong><?= e($q['quote_number']) ?></strong></td>
                    <td><?= date('d M', strtotime($q['created_at'])) ?></td>
                    <td><?= statusBadge($q['status']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php else: ?>
            <p style="color: var(--muted);">No quotes yet.</p>
            <?php endif; ?>
        </div>
        
        <!-- Recent Inquiries -->
        <div class="order-card">
            <h3 style="margin-top: 0;">💬 Recent Inquiries</h3>
            <?php 
            $recentInquiries = $db->fetchAll("SELECT * FROM inquiries ORDER BY created_at DESC LIMIT 5");
            if ($recentInquiries): ?>
            <table style="width:100%;">
                <?php foreach ($recentInquiries as $i): ?>
                <tr>
                    <td><strong><?= e($i['name']) ?></strong></td>
                    <td><?= e(substr($i['subject'] ?? $i['message'] ?? '', 0, 30)) ?>...</td>
                    <td><?= statusBadge($i['status']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php else: ?>
            <p style="color: var(--muted);">No inquiries yet.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- ============================================= -->
    <!-- QUOTES TAB - UNIFIED VIEW OF ALL QUOTES -->
    <!-- ============================================= -->
    <?php if ($tab === 'quotes'): ?>
    <h2 style="margin-bottom: 20px;">📝 All Quote Requests</h2>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Quote #</th>
                    <th>Customer</th>
                    <th>Subject</th>
                    <th>Items</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quotes as $q): ?>
                <tr>
                    <td><strong><?= e($q['quote_number']) ?></strong></td>
                    <td><?= e($q['user_email'] ?? $q['company_name'] ?? '—') ?></td>
                    <td><?= e(substr($q['subject'] ?? '—', 0, 40)) ?></td>
                    <td><?= $q['item_count'] ?? 0 ?></td>
                    <td><?= $q['quoted_amount'] ? '₹' . number_format($q['quoted_amount'], 2) : '—' ?></td>
                    <td><?= statusBadge($q['status']) ?></td>
                    <td><?= date('d M Y', strtotime($q['created_at'])) ?></td>
                    <td>
                        <div class="quote-actions">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="quote_id" value="<?= $q['id'] ?>">
                                <select name="status" onchange="this.form.submit()" style="padding:4px; font-size:12px;">
                                    <option value="new" <?= $q['status'] === 'new' ? 'selected' : '' ?>>New</option>
                                    <option value="quoted" <?= $q['status'] === 'quoted' ? 'selected' : '' ?>>Quoted</option>
                                    <option value="accepted" <?= $q['status'] === 'accepted' ? 'selected' : '' ?>>Accepted</option>
                                    <option value="rejected" <?= $q['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                </select>
                                <input type="hidden" name="update_quote_status" value="1">
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this quote?');">
                                <input type="hidden" name="delete_quote" value="1">
                                <input type="hidden" name="quote_id" value="<?= $q['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="color:#dc2626;">🗑️</button>
                            </form>
                            <a href="quote-detail?id=<?= $q['id'] ?>" class="btn btn-outline btn-sm">View</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- ============================================= -->
    <!-- ORDERS TAB -->
    <!-- ============================================= -->
    <?php if ($tab === 'orders'):
    $ss = getSellerSettings();
    ?>

    <!-- ══════════════════════════════════════════════════════════════════
         SELLER / COMPANY SETTINGS PANEL
         ══════════════════════════════════════════════════════════════════ -->
    <details class="seller-settings-panel" <?= !empty($error) && isset($_POST['save_seller_settings']) ? 'open' : '' ?>>
        <summary class="seller-settings-summary">
            🏢 Seller / Company Settings
            <span class="seller-settings-hint">Used on all invoices — click to expand</span>
        </summary>

        <form method="post" action="?tab=orders" class="seller-form">
            <input type="hidden" name="save_seller_settings" value="1">

            <div class="seller-form-grid">

                <!-- ── Company Info ── -->
                <div class="seller-section">
                    <h4 class="seller-section-title">🏭 Company Details</h4>
                    <div class="seller-row">
                        <label>Company Name *</label>
                        <input type="text" name="seller_name" value="<?= e($ss['seller_name']) ?>"
                               class="filter-input" required>
                    </div>
                    <div class="seller-row">
                        <label>Address Line 1</label>
                        <input type="text" name="seller_address1" value="<?= e($ss['seller_address1']) ?>"
                               class="filter-input" placeholder="Street / Plot No.">
                    </div>
                    <div class="seller-row">
                        <label>Address Line 2</label>
                        <input type="text" name="seller_address2" value="<?= e($ss['seller_address2']) ?>"
                               class="filter-input" placeholder="Area / Locality">
                    </div>
                    <div class="seller-2col">
                        <div class="seller-row">
                            <label>City</label>
                            <input type="text" name="seller_city" value="<?= e($ss['seller_city']) ?>" class="filter-input">
                        </div>
                        <div class="seller-row">
                            <label>State</label>
                            <input type="text" name="seller_state" value="<?= e($ss['seller_state']) ?>" class="filter-input">
                        </div>
                    </div>
                    <div class="seller-2col">
                        <div class="seller-row">
                            <label>PIN Code</label>
                            <input type="text" name="seller_pin" value="<?= e($ss['seller_pin']) ?>" class="filter-input" maxlength="10">
                        </div>
                        <div class="seller-row">
                            <label>Country</label>
                            <input type="text" name="seller_country" value="<?= e($ss['seller_country']) ?>" class="filter-input">
                        </div>
                    </div>
                    <div class="seller-2col">
                        <div class="seller-row">
                            <label>Phone</label>
                            <input type="text" name="seller_phone" value="<?= e($ss['seller_phone']) ?>" class="filter-input">
                        </div>
                        <div class="seller-row">
                            <label>Email</label>
                            <input type="email" name="seller_email" value="<?= e($ss['seller_email']) ?>" class="filter-input">
                        </div>
                    </div>
                    <div class="seller-row">
                        <label>Website</label>
                        <input type="text" name="seller_website" value="<?= e($ss['seller_website']) ?>" class="filter-input" placeholder="www.example.com">
                    </div>
                </div>

                <!-- ── Tax / Legal ── -->
                <div class="seller-section">
                    <h4 class="seller-section-title">📋 Tax &amp; Legal</h4>
                    <div class="seller-row">
                        <label>GSTIN</label>
                        <input type="text" name="seller_gstin" value="<?= e($ss['seller_gstin']) ?>"
                               class="filter-input" maxlength="15" placeholder="22AAAAA0000A1Z5"
                               style="font-family: monospace; letter-spacing: 1px;">
                    </div>
                    <div class="seller-row">
                        <label>PAN</label>
                        <input type="text" name="seller_pan" value="<?= e($ss['seller_pan']) ?>"
                               class="filter-input" maxlength="10" placeholder="AAAAA0000A"
                               style="font-family: monospace; letter-spacing: 1px;">
                    </div>
                    <div class="seller-row">
                        <label>CIN (optional)</label>
                        <input type="text" name="seller_cin" value="<?= e($ss['seller_cin']) ?>"
                               class="filter-input" placeholder="U12345TN2020PTC000000">
                    </div>
                    <h4 class="seller-section-title" style="margin-top:16px;">🧾 Tax Rates (%)</h4>
                    <div class="seller-3col">
                        <div class="seller-row">
                            <label>CGST %</label>
                            <input type="number" name="tax_cgst_pct" value="<?= e($ss['tax_cgst_pct']) ?>"
                                   class="filter-input" min="0" max="50" step="0.5">
                        </div>
                        <div class="seller-row">
                            <label>SGST %</label>
                            <input type="number" name="tax_sgst_pct" value="<?= e($ss['tax_sgst_pct']) ?>"
                                   class="filter-input" min="0" max="50" step="0.5">
                        </div>
                        <div class="seller-row">
                            <label>IGST %</label>
                            <input type="number" name="tax_igst_pct" value="<?= e($ss['tax_igst_pct']) ?>"
                                   class="filter-input" min="0" max="50" step="0.5">
                        </div>
                    </div>
                    <p class="seller-hint-text">CGST+SGST for intra-state · IGST for inter-state orders</p>

                    <!-- ── Bank Details ── -->
                    <h4 class="seller-section-title" style="margin-top:16px;">🏦 Bank Details</h4>
                    <div class="seller-row">
                        <label>Bank Name</label>
                        <input type="text" name="bank_name" value="<?= e($ss['bank_name']) ?>" class="filter-input" placeholder="HDFC Bank / SBI / etc.">
                    </div>
                    <div class="seller-2col">
                        <div class="seller-row">
                            <label>Account Number</label>
                            <input type="text" name="bank_account" value="<?= e($ss['bank_account']) ?>"
                                   class="filter-input" style="font-family:monospace;">
                        </div>
                        <div class="seller-row">
                            <label>IFSC Code</label>
                            <input type="text" name="bank_ifsc" value="<?= e($ss['bank_ifsc']) ?>"
                                   class="filter-input" maxlength="11" style="font-family:monospace; letter-spacing:1px;">
                        </div>
                    </div>
                    <div class="seller-2col">
                        <div class="seller-row">
                            <label>Branch / Address</label>
                            <input type="text" name="bank_branch" value="<?= e($ss['bank_branch']) ?>" class="filter-input">
                        </div>
                        <div class="seller-row">
                            <label>UPI ID</label>
                            <input type="text" name="bank_upi" value="<?= e($ss['bank_upi']) ?>"
                                   class="filter-input" placeholder="name@bank">
                        </div>
                    </div>

                    <!-- ── Invoice Preferences ── -->
                    <h4 class="seller-section-title" style="margin-top:16px;">📄 Invoice Preferences</h4>
                    <div class="seller-row">
                        <label>Invoice Number Prefix</label>
                        <input type="text" name="invoice_prefix" value="<?= e($ss['invoice_prefix']) ?>"
                               class="filter-input" maxlength="10" placeholder="INV">
                    </div>
                    <div class="seller-row">
                        <label>Payment Terms</label>
                        <input type="text" name="invoice_terms" value="<?= e($ss['invoice_terms']) ?>"
                               class="filter-input" placeholder="Net 30 days">
                    </div>
                    <div class="seller-row">
                        <label>Invoice Footer Note</label>
                        <textarea name="invoice_footer" class="filter-input" rows="2"
                                  placeholder="Computer-generated invoice..."><?= e($ss['invoice_footer']) ?></textarea>
                    </div>
                </div>
            </div><!-- /seller-form-grid -->

            <div class="seller-form-footer">
                <button type="submit" class="btn btn-primary">💾 Save Seller Settings</button>
                <span class="seller-hint-text">Changes apply to all new invoices immediately.</span>
            </div>
        </form>
    </details>

    <h2 style="margin: 24px 0 16px;">📦 All Orders</h2>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Tracking</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td><strong><?= e($o['order_number']) ?></strong></td>
                    <td><?= e($o['user_email'] ?? $o['company_name'] ?? '—') ?></td>
                    <td><?= $o['item_count'] ?? 0 ?> item(s)</td>
                    <td>₹ <?= number_format($o['total_amount'], 2) ?></td>
                    <td><?= statusBadge($o['status']) ?></td>
                    <td><?= e($o['tracking_number'] ?? '—') ?></td>
                    <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                    <td>
                    <div style="display: flex; gap: 6px; align-items: center;">
                        <a href="/order-detail?id=<?= $o['id'] ?>" class="btn btn-outline btn-sm">View</a>
                        <a href="/invoice_pdf_no_composer?id=<?= $o['id'] ?>" class="btn btn-outline btn-sm" target="_blank">📄 Invoice</a>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                            <input type="hidden" name="update_order_status" value="1">
                            <select name="status" onchange="this.form.submit()" style="padding:4px; font-size:12px;">
                                <option value="pending" <?= $o['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="processing" <?= $o['status'] === 'processing' ? 'selected' : '' ?>>Processing</option>
                                <option value="shipped" <?= $o['status'] === 'shipped' ? 'selected' : '' ?>>Shipped</option>
                                <option value="delivered" <?= $o['status'] === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                                <option value="cancelled" <?= $o['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- ============================================= -->
    <!-- INQUIRIES TAB -->
    <!-- ============================================= -->
    <?php if ($tab === 'inquiries'): ?>
    <h2 style="margin-bottom: 20px;">💬 All Inquiries</h2>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inquiries as $i): ?>
                <tr>
                    <td>#<?= $i['id'] ?></td>
                    <td><?= e($i['name']) ?></td>
                    <td><?= e($i['email']) ?></td>
                    <td><?= e(substr($i['subject'] ?? $i['message'] ?? '', 0, 50)) ?></td>
                    <td><?= statusBadge($i['status']) ?></td>
                    <td><?= date('d M Y', strtotime($i['created_at'])) ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="inquiry_id" value="<?= $i['id'] ?>">
                            <select name="inquiry_status" onchange="this.form.submit()" style="padding:4px; font-size:12px;">
                                <option value="New" <?= $i['status'] === 'New' ? 'selected' : '' ?>>New</option>
                                <option value="Replied" <?= $i['status'] === 'Replied' ? 'selected' : '' ?>>Replied</option>
                                <option value="Closed" <?= $i['status'] === 'Closed' ? 'selected' : '' ?>>Closed</option>
                            </select>
                            <input type="hidden" name="update_inquiry_status" value="1">
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this inquiry?');">
                            <input type="hidden" name="delete_inquiry" value="1">
                            <input type="hidden" name="inquiry_id" value="<?= $i['id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm" style="color:#dc2626;">🗑️</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- ============================================= -->
    <!-- USERS TAB -->
    <!-- ============================================= -->
    <?php if ($tab === 'users'): ?>
    <h2 style="margin-bottom: 20px;">👥 User Management</h2>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Email</th>
                    <th>Company</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= e($u['company_name'] ?? '—') ?></td>
                    <td><?= e($u['role']) ?></td>
                    <td><?= statusBadge($u['status']) ?></td>
                    <td><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <select name="role" onchange="this.form.submit()" style="padding:4px; font-size:12px;">
                                <option value="User" <?= $u['role'] === 'User' ? 'selected' : '' ?>>User</option>
                                <option value="Admin" <?= $u['role'] === 'Admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                            <select name="status" onchange="this.form.submit()" style="padding:4px; font-size:12px;">
                                <option value="Active" <?= $u['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                                <option value="Inactive" <?= $u['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                <option value="Pending" <?= $u['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            </select>
                            <input type="hidden" name="update_user" value="1">
                        </form>
                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this user?');">
                            <input type="hidden" name="delete_user" value="1">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm" style="color:#dc2626;">🗑️</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- ============================================= -->
    <!-- AUDIT TAB -->
    <!-- ============================================= -->
    <?php if ($tab === 'audit'): ?>
    <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
        <h2>📋 Audit Trail</h2>
        <a href="?download=audit" class="btn btn-outline">⬇ Download CSV</a>
    </div>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Detail</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($auditLogs as $l): ?>
                <tr>
                    <td><?= e($l['created_at']) ?></td>
                    <td><?= e($l['user_email'] ?? 'system') ?></td>
                    <td><?= e($l['action']) ?></td>
                    <td><?= e($l['detail']) ?></td>
                    <td><?= e($l['ip_address']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- ============================================= -->
    <!-- PUBCHEM TAB -->
    <!-- ============================================= -->
    <?php if ($tab === 'pubchem'): ?>
    <h2 style="margin-bottom: 20px;">⚗️ PubChem Data Fetcher</h2>
    <div style="background: white; padding: 24px; border-radius: 8px; border: 1px solid var(--border);">
        <form method="post" style="display: flex; gap: 10px;">
            <input type="text" name="fetch_slug" placeholder="Product slug" required class="filter-input" style="flex:1;">
            <button type="submit" name="pubchem_fetch" value="1" class="btn btn-primary">Fetch from PubChem</button>
        </form>
        <p style="margin-top: 16px; color: var(--muted);">
            <a href="pubchem_fetch.php" target="_blank">Open full PubChem Fetcher →</a>
        </p>
    </div>
    <?php endif; ?>
    
</div>
<!-- ============================================= -->
<!-- DATA AUDIT TAB - Missing Product Fields -->
<!-- ============================================= -->
<?php if ($tab === 'data-audit'): 
    $products = $db->fetchAll("SELECT *, compound_name AS product_name FROM compounds WHERE status = 'Active' ORDER BY compound_name");
    $criticalFields = ['cas_number', 'molecular_formula', 'molecular_weight', 'smiles', 'inchi_key'];
    $recommendedFields = ['image_url', 'synonyms', 'iupac_name', 'pubchem_cid'];
    
    $issues = [];
    foreach ($products as $p) {
        $missing = [];
        $warnings = [];

        foreach ($criticalFields as $field) {
            if (empty($p[$field]) || $p[$field] === 'NA') {
                $missing[] = $field;
            }
        }
        foreach ($recommendedFields as $field) {
            if (empty($p[$field]) || $p[$field] === 'NA') {
                $warnings[] = $field;
            }
        }

        if (!empty($missing) || !empty($warnings)) {
            $issues[] = [
                'id'           => $p['id'],
                'name'         => $p['product_name'],
                'slug'         => $p['slug'],
                'cas'          => $p['cas_number'],
                'cas_verified' => $p['cas_verified'] ?? 'unchecked',
                'cas_other'    => $p['cas_other'] ?? '',
                'missing'      => $missing,
                'warnings'     => $warnings,
                'score'        => round((1 - (count($missing) / count($criticalFields))) * 100)
            ];
        }
    }

    // CAS verification badge colours — shared with /cas_verify.php
    $casBadgeMap = [
        'verified'   => ['label' => '✓ Verified',   'bg' => '#dcfce7', 'fg' => '#166534'],
        'multi'      => ['label' => '✓ Multi',      'bg' => '#dbeafe', 'fg' => '#1e40af'],
        'unverified' => ['label' => '⚠ Unverified', 'bg' => '#fef3c7', 'fg' => '#92400e'],
        'unchecked'  => ['label' => '… Unchecked',  'bg' => '#f1f5f9', 'fg' => '#475569'],
    ];
    
    // ── Sort handling ────────────────────────────────────────────────
    // 'score' is computed in PHP (not in SQL), so sorting is also in PHP via usort.
    // Allowlist: column key → callable that returns the comparison value for a row.
    $sortMap = [
        'id'        => fn($r) => (int)$r['id'],
        'name'      => fn($r) => strtolower($r['name'] ?? ''),
        'cas'       => fn($r) => strtolower((string)($r['cas'] ?? '')),
        'verified'  => fn($r) => $r['cas_verified'] ?? 'unchecked',
        'missing'   => fn($r) => count($r['missing']),
        'warnings'  => fn($r) => count($r['warnings']),
        'score'     => fn($r) => $r['score'],
    ];
    $sortKey = $_GET['sort'] ?? 'score';
    if (!isset($sortMap[$sortKey])) $sortKey = 'score';
    $sortDir = strtolower($_GET['dir'] ?? '') === 'desc' ? 'desc' : 'asc';
    // Default 'score' to ascending = worst-first (matches the previous behavior);
    // other columns default to ascending too.

    $cmp = $sortMap[$sortKey];
    usort($issues, function($a, $b) use ($cmp, $sortDir) {
        $av = $cmp($a); $bv = $cmp($b);
        if ($av == $bv) return $a['id'] - $b['id']; // stable tiebreak by id
        $diff = is_numeric($av) && is_numeric($bv)
            ? ($av <=> $bv)
            : strcmp((string)$av, (string)$bv);
        return $sortDir === 'desc' ? -$diff : $diff;
    });

    $filterMissing = $_GET['filter'] ?? 'all';
    if ($filterMissing === 'critical') {
        $issues = array_filter($issues, fn($i) => !empty($i['missing']));
    } elseif ($filterMissing === 'image') {
        $issues = array_filter($issues, fn($i) => in_array('image_url', $i['warnings']));
    }

    // Build a sortable column header that preserves the current filter
    $sortHeader = function(string $label, string $key) use ($sortKey, $sortDir, $filterMissing): string {
        $nextDir = ($key === $sortKey && $sortDir === 'asc') ? 'desc' : 'asc';
        $arrow   = $key === $sortKey ? ($sortDir === 'asc' ? ' ▲' : ' ▼') : '';
        $params  = ['tab' => 'data-audit', 'filter' => $filterMissing, 'sort' => $key, 'dir' => $nextDir];
        $href    = '?' . http_build_query($params);
        $style   = $key === $sortKey ? 'color:#0e7abf;font-weight:700;' : '';
        return '<a href="' . htmlspecialchars($href) . '" style="text-decoration:none;color:inherit;' . $style . '">' . htmlspecialchars($label) . $arrow . '</a>';
    };
?>
<h2 style="margin-bottom: 20px;">📊 Products - Missing Fields</h2>

<div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
    <a href="?tab=data-audit&filter=all" class="btn <?= $filterMissing === 'all' ? 'btn-primary' : 'btn-outline' ?>">All Issues</a>
    <a href="?tab=data-audit&filter=critical" class="btn <?= $filterMissing === 'critical' ? 'btn-primary' : 'btn-outline' ?>">Missing Critical Fields</a>
    <a href="?tab=data-audit&filter=image" class="btn <?= $filterMissing === 'image' ? 'btn-primary' : 'btn-outline' ?>">Missing Images</a>
    <a href="/cas_verify.php" class="btn btn-outline">🔍 CAS Verify Tab →</a>
</div>

<div style="overflow-x: auto;">
    <table class="admin-table">
        <thead>
            <tr>
                <th><?= $sortHeader('ID', 'id') ?></th>
                <th><?= $sortHeader('Product', 'name') ?></th>
                <th><?= $sortHeader('CAS', 'cas') ?></th>
                <th><?= $sortHeader('CAS Verified', 'verified') ?></th>
                <th><?= $sortHeader('Missing (Critical)', 'missing') ?></th>
                <th><?= $sortHeader('Missing (Recommended)', 'warnings') ?></th>
                <th><?= $sortHeader('Score', 'score') ?></th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($issues as $i):
                $cb = $casBadgeMap[$i['cas_verified']] ?? $casBadgeMap['unchecked'];
                $otherCount = $i['cas_other'] ? count(explode('|', $i['cas_other'])) : 0;
            ?>
            <tr>
                <td style="color:#64748b;font-size:0.85rem;"><?= $i['id'] ?></td>
                <td><strong><?= e(substr($i['name'], 0, 60)) ?></strong></td>
                <td style="font-family:'JetBrains Mono',monospace;font-size:.78rem;"><?= e($i['cas'] ?? '—') ?></td>
                <td>
                    <span style="background:<?= $cb['bg'] ?>;color:<?= $cb['fg'] ?>;padding:2px 8px;border-radius:4px;font-size:.7rem;font-weight:700;white-space:nowrap;" title="<?= e($i['cas_verified']) ?><?= $otherCount > 0 ? ' — ' . $otherCount . ' alt CAS' : '' ?>">
                        <?= $cb['label'] ?>
                    </span>
                    <?php if ($otherCount > 0): ?>
                        <span style="font-size:.68rem;color:#92400e;margin-left:4px;">+<?= $otherCount ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php foreach ($i['missing'] as $m): ?>
                    <span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:12px; font-size:11px; margin:2px; display:inline-block;">
                        <?= str_replace('_', ' ', $m) ?>
                    </span>
                    <?php endforeach; ?>
                </td>
                <td>
                    <?php foreach ($i['warnings'] as $w): ?>
                    <span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:12px; font-size:11px; margin:2px; display:inline-block;">
                        <?= str_replace('_', ' ', $w) ?>
                    </span>
                    <?php endforeach; ?>
                </td>
                <td>
                    <div style="width:60px; height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden;">
                        <div style="width:<?= $i['score'] ?>%; height:100%; background:<?= $i['score'] > 70 ? '#22c55e' : ($i['score'] > 40 ? '#f59e0b' : '#ef4444') ?>;"></div>
                    </div>
                    <?= $i['score'] ?>%
                </td>
                <td>
                    <a href="admin_products.php?action=edit&id=<?= $i['id'] ?>" class="btn btn-outline btn-sm">✏️ Edit</a>
                    <a href="pubchem_fetch.php?fetch_id=<?= $i['id'] ?>" class="btn btn-outline btn-sm">⚗️ Fetch</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>
</body>
</html>