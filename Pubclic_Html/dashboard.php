<?php
/**
 * dashboard.php - Unified Customer Dashboard
 * Role-based dashboard for Customers, Buyers, and Vendors
 */
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/mail_config.php';

// Enforce login
enforceSessionTimeout(900);
if (!isset($_SESSION['user'])) {
    header('Location: /signin');
    exit;
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'User';
$userType = $_SESSION['user_type'] ?? 'Customer';

// Get user details
$user = $db->fetchOne(
    "SELECT * FROM users WHERE id = :id",
    ['id' => $userId]
);

// Get active tab
$tab = $_GET['tab'] ?? 'overview';

// Get statistics based on user type
$stats = getUserDashboardStats($userId, $userType);

/**
 * Get dashboard statistics
 */
function getUserDashboardStats($userId, $userType) {
    $db = Database::getInstance();
    
    return [
        'quotes' => [
            'total' => $db->fetchValue("SELECT COUNT(*) FROM quote_requests WHERE user_id = :uid", ['uid' => $userId]),
            'pending' => $db->fetchValue("SELECT COUNT(*) FROM quote_requests WHERE user_id = :uid AND status IN ('new', 'reviewed')", ['uid' => $userId]),
            'approved' => $db->fetchValue("SELECT COUNT(*) FROM quote_requests WHERE user_id = :uid AND status IN ('quoted', 'accepted')", ['uid' => $userId])
        ],
        'orders' => [
            'total' => $db->fetchValue("SELECT COUNT(*) FROM orders WHERE user_id = :uid", ['uid' => $userId]),
            'processing' => $db->fetchValue("SELECT COUNT(*) FROM orders WHERE user_id = :uid AND status IN ('pending', 'processing')", ['uid' => $userId]),
            'shipped' => $db->fetchValue("SELECT COUNT(*) FROM orders WHERE user_id = :uid AND status = 'shipped'", ['uid' => $userId]),
            'delivered' => $db->fetchValue("SELECT COUNT(*) FROM orders WHERE user_id = :uid AND status = 'delivered'", ['uid' => $userId])
        ],
        'saved' => $db->fetchValue("SELECT COUNT(*) FROM saved_products WHERE user_id = :uid", ['uid' => $userId]),
        'notifications' => $db->fetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0", ['uid' => $userId])
    ];
}

// Mark notification as read if requested
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $db->update('notifications', 
        ['is_read' => 1],
        'id = :id AND user_id = :uid',
        ['id' => $_GET['mark_read'], 'uid' => $userId]
    );
    header('Location: dashboard?tab=notifications');
    exit;
}

// Handle quick actions
$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if ($action === 'save_product' && isset($_POST['product_id'])) {
        try {
            $db->insert('saved_products', [
                'user_id' => $userId,
                'product_id' => (int)$_POST['product_id']
            ]);
            $message = 'Product saved to your list!';
        } catch (Exception $e) {
            // Already saved - ignore
        }
    }
    
    if ($action === 'remove_saved' && isset($_POST['product_id'])) {
        $db->delete('saved_products', 
            'user_id = :uid AND product_id = :pid',
            ['uid' => $userId, 'pid' => (int)$_POST['product_id']]
        );
        $message = 'Product removed from saved list.';
    }
    
    if ($action === 'add_address') {
        // If setting as default, unset other defaults
        if (isset($_POST['is_default']) && $_POST['is_default']) {
            $db->update('user_addresses', 
                ['is_default' => 0],
                'user_id = :uid AND address_type = :type',
                ['uid' => $userId, 'type' => $_POST['address_type'] ?? 'both']
            );
        }
        
        $db->insert('user_addresses', [
            'user_id' => $userId,
            'address_type' => $_POST['address_type'] ?? 'both',
            'is_default' => isset($_POST['is_default']) ? 1 : 0,
            'address_line1' => $_POST['address_line1'],
            'address_line2' => $_POST['address_line2'] ?? null,
            'city' => $_POST['city'],
            'state' => $_POST['state'],
            'postal_code' => $_POST['postal_code'],
            'country' => $_POST['country'] ?? 'India',
            'phone' => $_POST['phone'] ?? null,
            'gstin' => $_POST['gstin'] ?? null
        ]);
        $message = 'Address added successfully!';
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'delete_quote' && isset($_POST['quote_id'])) {
    $action = $_POST['action'];
    $quoteId = intval($_POST['quote_id']);
    // Verify ownership
    $quote = $db->fetchOne("SELECT id FROM quote_requests WHERE id = :id AND user_id = :uid", 
        ['id' => $quoteId, 'uid' => $userId]);
    
    if ($quote && ($quote['status'] ?? 'new') === 'new') {
        $db->beginTransaction();
        try {
            $db->delete('quote_items', 'quote_id = :qid', ['qid' => $quoteId]);
            $db->delete('quote_requests', 'id = :id', ['id' => $quoteId]);
            $db->commit();
            $message = 'Quote deleted successfully.';
        } catch (Exception $e) {
            $db->rollback();
            $error = 'Could not delete quote.';
        }
    } else {
        $error = 'Only pending quotes can be deleted.';
    }
}

// Get data for current tab
$quotes = [];
$orders = [];
$savedProducts = [];
$recentProducts = [];
$notifications = [];
$addresses = [];
$documents = [];

switch ($tab) {
    case 'quotes':
        $quotes = $db->fetchAll(
            "SELECT * FROM quote_requests WHERE user_id = :uid ORDER BY created_at DESC LIMIT 50",
            ['uid' => $userId]
        );
        break;
        
    case 'orders':
        $orders = $db->fetchAll(
            "SELECT o.*, 
                    (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
             FROM orders o 
             WHERE o.user_id = :uid 
             ORDER BY o.created_at DESC LIMIT 50",
            ['uid' => $userId]
        );
        break;
        
    case 'saved':
        $savedProducts = $db->fetchAll(
            "SELECT c.*, c.compound_name AS product_name, sp.created_at as saved_at
             FROM saved_products sp
             JOIN compounds c ON sp.product_id = c.id
             WHERE sp.user_id = :uid
             ORDER BY sp.created_at DESC",
            ['uid' => $userId]
        );
        break;
        
    case 'notifications':
        $notifications = $db->fetchAll(
            "SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 100",
            ['uid' => $userId]
        );
        break;
        
    case 'addresses':
        $addresses = $db->fetchAll(
            "SELECT * FROM user_addresses WHERE user_id = :uid ORDER BY is_default DESC, created_at DESC",
            ['uid' => $userId]
        );
        break;
        
    case 'documents':
        $documents = $db->fetchAll(
            "SELECT d.*, o.order_number, q.quote_number
             FROM documents d
             LEFT JOIN orders o ON d.order_id = o.id
             LEFT JOIN quote_requests q ON d.quote_id = q.id
             WHERE d.user_id = :uid
             ORDER BY d.created_at DESC LIMIT 50",
            ['uid' => $userId]
        );
        break;
        
    case 'overview':
    default:
        $recentQuotes = $db->fetchAll(
            "SELECT * FROM quote_requests WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5",
            ['uid' => $userId]
        );
        $recentOrders = $db->fetchAll(
            "SELECT * FROM orders WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5",
            ['uid' => $userId]
        );
        $recentProducts = $db->fetchAll(
            "SELECT c.*, c.compound_name AS product_name, rv.viewed_at
             FROM recently_viewed rv
             JOIN compounds c ON rv.product_id = c.id
             WHERE rv.user_id = :uid
             ORDER BY rv.viewed_at DESC LIMIT 8",
            ['uid' => $userId]
        );
        $savedProducts = $db->fetchAll(
            "SELECT c.*, c.compound_name AS product_name FROM saved_products sp
             JOIN compounds c ON sp.product_id = c.id
             WHERE sp.user_id = :uid
             ORDER BY sp.created_at DESC LIMIT 4",
            ['uid' => $userId]
        );
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | AB Chem India</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">

</head>
<body>
<?php include 'header.php'; ?>

<div class="dashboard-container">
    
    <!-- Header -->
    <div class="dashboard-header">
        <div>
            <h1>
                Welcome back, <?= e($user['contact_name'] ?? $user['company_name'] ?? 'User') ?>!
                <span class="user-badge"><?= e($userType) ?></span>
            </h1>
            <p style="color: var(--muted); margin: 4px 0 0;">
                <?= e($user['company_name']) ?> • <?= e($user['email']) ?>
            </p>
        </div>
        <div class="quick-actions">
            <a href="/catalog" class="btn btn-primary">🔍 Browse Catalog</a>
            <a href="/contact" class="btn btn-outline">📋 Request Quote</a>
        </div>
    </div>
    
    <!-- Navigation Tabs -->
    <div class="dashboard-nav">
        <a href="?tab=overview" class="<?= $tab === 'overview' ? 'active' : '' ?>">
            📊 Overview
        </a>
        <a href="?tab=quotes" class="<?= $tab === 'quotes' ? 'active' : '' ?>">
            📝 Quotes
            <?php if ($stats['quotes']['pending'] > 0): ?>
                <span class="badge"><?= $stats['quotes']['pending'] ?></span>
            <?php endif; ?>
        </a>
        <a href="?tab=orders" class="<?= $tab === 'orders' ? 'active' : '' ?>">
            📦 Orders
            <?php if ($stats['orders']['processing'] > 0): ?>
                <span class="badge"><?= $stats['orders']['processing'] ?></span>
            <?php endif; ?>
        </a>
        
        <a href="?tab=saved" class="<?= $tab === 'saved' ? 'active' : '' ?>">
            ⭐ Saved Products
        </a>
        <a href="?tab=addresses" class="<?= $tab === 'addresses' ? 'active' : '' ?>">
            📍 Addresses
        </a>
        <a href="?tab=documents" class="<?= $tab === 'documents' ? 'active' : '' ?>">
            📄 Documents
        </a>
        <a href="?tab=notifications" class="<?= $tab === 'notifications' ? 'active' : '' ?>">
            🔔 Notifications
            <?php if ($stats['notifications'] > 0): ?>
                <span class="badge"><?= $stats['notifications'] ?></span>
            <?php endif; ?>
        </a>
        <a href="/profile" style="margin-left: auto;">⚙️ Settings</a>
    </div>
    
    <?php if (isset($message)): ?>
        <div style="background: #dcfce7; color: #166534; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
            <?= e($message) ?>
        </div>
    <?php endif; ?>
    
    <!-- OVERVIEW TAB -->
    <?php if ($tab === 'overview'): ?>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">📝</div>
            <div class="stat-value"><?= $stats['quotes']['total'] ?></div>
            <div class="stat-label">Total Quotes</div>
            <div class="stat-detail">
                <span>🆕 <?= $stats['quotes']['pending'] ?> pending</span>
                <span>✅ <?= $stats['quotes']['approved'] ?> approved</span>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">📦</div>
            <div class="stat-value"><?= $stats['orders']['total'] ?></div>
            <div class="stat-label">Total Orders</div>
            <div class="stat-detail">
                <span>⚙️ <?= $stats['orders']['processing'] ?> processing</span>
                <span>🚚 <?= $stats['orders']['shipped'] ?> shipped</span>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">✅</div>
            <div class="stat-value"><?= $stats['orders']['delivered'] ?></div>
            <div class="stat-label">Delivered</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">⭐</div>
            <div class="stat-value"><?= $stats['saved'] ?></div>
            <div class="stat-label">Saved Products</div>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
        <!-- Recent Quotes -->
        <div class="content-card">
            <div class="section-title">
                <h2>📝 Recent Quote Requests</h2>
                <a href="?tab=quotes" class="btn btn-outline btn-sm">View All →</a>
            </div>
            
            <?php if (empty($recentQuotes)): ?>
                <p style="color: var(--muted); text-align: center; padding: 30px;">
                    No quote requests yet.<br>
                    <a href="/contact" class="btn btn-primary" style="margin-top: 16px;">Request a Quote</a>
                </p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Quote #</th>
                                <th>Date</th>
                                <th>Subject</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentQuotes as $q): ?>
                            <tr>
                                <td><strong><?= e($q['quote_number']) ?></strong></td>
                                <td><?= date('d M Y', strtotime($q['created_at'])) ?></td>
                                <td><?= e($q['subject'] ?? 'General Inquiry') ?></td>
                                <td>
                                    <span class="status-badge status-<?= strtolower($q['status']) ?>">
                                        <?= e($q['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Recent Orders -->
        <div class="content-card">
            <div class="section-title">
                <h2>📦 Recent Orders</h2>
                <a href="?tab=orders" class="btn btn-outline btn-sm">View All →</a>
            </div>
            
            <?php if (empty($recentOrders)): ?>
                <p style="color: var(--muted); text-align: center; padding: 30px;">
                    No orders yet.
                </p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Date</th>
                                <th>Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $o): ?>
                            <tr>
                                <td><strong><?= e($o['order_number']) ?></strong></td>
                                <td><?= date('d M Y', strtotime($o['order_date'])) ?></td>
                                <td>₹ <?= number_format($o['total_amount'], 2) ?></td>
                                <td>
                                    <span class="status-badge status-<?= strtolower($o['status']) ?>">
                                        <?= e($o['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Recently Viewed Products -->
    <?php if (!empty($recentProducts)): ?>
    <div class="content-card" style="margin-top: 24px;">
        <div class="section-title">
            <h2>👁️ Recently Viewed</h2>
            <a href="/catalog" class="btn btn-outline btn-sm">Browse Catalog →</a>
        </div>
        <div class="product-grid">
            <?php foreach ($recentProducts as $p): ?>
            <div class="mini-product-card">
                <h4><a href="/product/<?= e($p['slug']) ?>"><?= e($p['product_name']) ?></a></h4>
                <div style="font-size: 0.8rem; color: var(--muted);">
                    CAS: <?= e($p['cas_number'] ?? 'N/A') ?><br>
                    Purity: <?= e($p['purity'] ?? 'N/A') ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Saved Products Preview -->
    <?php if (!empty($savedProducts)): ?>
    <div class="content-card" style="margin-top: 24px;">
        <div class="section-title">
            <h2>⭐ Saved Products</h2>
            <a href="?tab=saved" class="btn btn-outline btn-sm">View All →</a>
        </div>
        <div class="product-grid">
            <?php foreach ($savedProducts as $p): ?>
            <div class="mini-product-card">
                <h4><a href="/product/<?= e($p['slug']) ?>"><?= e($p['product_name']) ?></a></h4>
                <div style="font-size: 0.8rem; color: var(--muted);">
                    CAS: <?= e($p['cas_number'] ?? 'N/A') ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php endif; ?>
    
    <!-- QUOTES TAB -->
    <?php if ($tab === 'quotes'): ?>
    <div class="content-card">
        <div class="section-title">
            <h2>📝 Quote Requests</h2>
            <a href="/contact" class="btn btn-primary">➕ New Quote Request</a>
        </div>
        
        <?php if (empty($quotes)): ?>
            <p style="color: var(--muted); text-align: center; padding: 40px;">
                No quote requests yet.<br>
                <a href="/contact" class="btn btn-primary" style="margin-top: 16px;">Request Your First Quote</a>
            </p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Quote #</th>
                            <th>Date</th>
                            <th>Subject</th>
                            <th>Items</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quotes as $q): 
                            $itemCount = $db->fetchValue("SELECT COUNT(*) FROM quote_items WHERE quote_id = :qid", ['qid' => $q['id']]);
                        ?>
                        <tr>
                            <td><strong><?= e($q['quote_number']) ?></strong></td>
                            <td><?= date('d M Y', strtotime($q['created_at'])) ?></td>
                            <td><?= e($q['subject'] ?? '—') ?></td>
                            <td><?= $itemCount ?> item(s)</td>
                            <td>
                                <?= $q['quoted_amount'] ? '₹ ' . number_format($q['quoted_amount'], 2) : 'Pending' ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?= strtolower($q['status']) ?>">
                                    <?= e($q['status']) ?>
                                </span>
                            </td>
                            <td>
                                <a href="quote-detail?id=<?= $q['id'] ?>" class="btn btn-outline btn-sm">View</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- ORDERS TAB -->
    <?php if ($tab === 'orders'): ?>
    <div class="content-card">
        <div class="section-title">
            <h2>📦 My Orders</h2>
        </div>
        
        <?php if (empty($orders)): ?>
            <p style="color: var(--muted); text-align: center; padding: 40px;">
                No orders yet.
            </p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th>Tracking</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o): ?>
                        <tr>
                            <td><strong><?= e($o['order_number']) ?></strong></td>
                            <td><?= date('d M Y', strtotime($o['order_date'])) ?></td>
                            <td><?= $o['item_count'] ?> item(s)</td>
                            <td><strong>₹ <?= number_format($o['total_amount'], 2) ?></strong></td>
                            <td>
                                <span class="status-badge status-<?= strtolower($o['payment_status']) ?>">
                                    <?= e($o['payment_status']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-<?= strtolower($o['status']) ?>">
                                    <?= e($o['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($o['tracking_number']): ?>
                                    <?= e($o['tracking_number']) ?><br>
                                    <small><?= e($o['shipping_carrier']) ?></small>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            
                            <td>
                        <a href="/order-detail?id=<?= $o['id'] ?>" class="btn btn-outline btn-sm">View</a>
                        <a href="/invoice_pdf_no_composer?id=<?= $o['id'] ?>" class="btn btn-outline btn-sm" target="_blank">📄 Invoice</a>
                    </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    
    <!-- SAVED PRODUCTS TAB -->
    <?php if ($tab === 'saved'): ?>
    <div class="content-card">
        <div class="section-title">
            <h2>⭐ Saved Products</h2>
            <span><?= count($savedProducts) ?> products</span>
        </div>
        
        <?php if (empty($savedProducts)): ?>
            <p style="color: var(--muted); text-align: center; padding: 40px;">
                No saved products yet.<br>
                <a href="/catalog" class="btn btn-primary" style="margin-top: 16px;">Browse Catalog</a>
            </p>
        <?php else: ?>
            <div class="product-grid" style="grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));">
                <?php foreach ($savedProducts as $p): ?>
                <div class="mini-product-card" style="position: relative;">
                    <form method="post" style="position: absolute; top: 8px; right: 8px;">
                        <input type="hidden" name="action" value="remove_saved">
                        <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                        <button type="submit" style="background: none; border: none; cursor: pointer; font-size: 18px;" 
                                onclick="return confirm('Remove from saved?')" title="Remove">🗑️</button>
                    </form>
                    <h4><a href="/product/<?= e($p['slug']) ?>"><?= e($p['product_name']) ?></a></h4>
                    <div style="font-size: 0.8rem; color: var(--muted); margin: 8px 0;">
                        <strong>CAS:</strong> <?= e($p['cas_number'] ?? 'N/A') ?><br>
                        <strong>Purity:</strong> <?= e($p['purity'] ?? 'N/A') ?><br>
                        <strong>Type:</strong> <?= e($p['product_type'] ?? 'N/A') ?>
                    </div>
                    <div style="display: flex; gap: 8px; margin-top: 12px;">
                        <a href="/product/<?= e($p['slug']) ?>" class="btn btn-outline btn-sm">View Details</a>
                        <a href="/contact?subject=<?= urlencode($p['product_name']) ?>" class="btn btn-primary btn-sm">Request Quote</a>
                    </div>
                    <div style="font-size: 0.7rem; color: var(--muted); margin-top: 8px;">
                        Saved on <?= date('d M Y', strtotime($p['saved_at'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- ADDRESSES TAB -->
    <?php if ($tab === 'addresses'): ?>
    <div class="content-card">
        <div class="section-title">
            <h2>📍 My Addresses</h2>
            <button onclick="toggleAddAddressForm()" class="btn btn-primary">➕ Add New Address</button>
        </div>
        
        <!-- Add Address Form -->
        <div id="addAddressForm" style="display: none; background: var(--bg); padding: 20px; border-radius: 8px; margin-bottom: 24px;">
            <h3 style="margin-top: 0;">Add New Address</h3>
            <form method="post">
                <input type="hidden" name="action" value="add_address">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <label class="filter-label">Address Type</label>
                        <select name="address_type" class="filter-input">
                            <option value="both">Shipping & Billing</option>
                            <option value="shipping">Shipping Only</option>
                            <option value="billing">Billing Only</option>
                        </select>
                    </div>
                    <div>
                        <label class="filter-label">Phone</label>
                        <input type="tel" name="phone" class="filter-input" placeholder="+91 XXXXX XXXXX">
                    </div>
                    <div style="grid-column: span 2;">
                        <label class="filter-label">Address Line 1 *</label>
                        <input type="text" name="address_line1" required class="filter-input" placeholder="Street, Building">
                    </div>
                    <div style="grid-column: span 2;">
                        <label class="filter-label">Address Line 2</label>
                        <input type="text" name="address_line2" class="filter-input" placeholder="Landmark, Area">
                    </div>
                    <div>
                        <label class="filter-label">City *</label>
                        <input type="text" name="city" required class="filter-input">
                    </div>
                    <div>
                        <label class="filter-label">State *</label>
                        <input type="text" name="state" required class="filter-input">
                    </div>
                    <div>
                        <label class="filter-label">Postal Code *</label>
                        <input type="text" name="postal_code" required class="filter-input">
                    </div>
                    <div>
                        <label class="filter-label">Country</label>
                        <input type="text" name="country" value="India" class="filter-input">
                    </div>
                    <div>
                        <label class="filter-label">GSTIN (Optional)</label>
                        <input type="text" name="gstin" class="filter-input" placeholder="GST Number">
                    </div>
                </div>
                <div style="margin-top: 16px;">
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="is_default" value="1">
                        <span>Set as default address</span>
                    </label>
                </div>
                <div style="margin-top: 16px; display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">Save Address</button>
                    <button type="button" onclick="toggleAddAddressForm()" class="btn btn-outline">Cancel</button>
                </div>
            </form>
        </div>
        
        <?php if (empty($addresses)): ?>
            <p style="color: var(--muted); text-align: center; padding: 40px;">
                No addresses saved yet.<br>
                <button onclick="toggleAddAddressForm()" class="btn btn-primary" style="margin-top: 16px;">Add Your First Address</button>
            </p>
        <?php else: ?>
            <?php foreach ($addresses as $addr): ?>
            <div class="address-card <?= $addr['is_default'] ? 'default' : '' ?>">
                <?php if ($addr['is_default']): ?>
                    <span class="address-badge">✓ Default</span>
                <?php endif; ?>
                <div style="margin-bottom: 8px;">
                    <strong><?= e($addr['address_type']) ?></strong>
                </div>
                <div>
                    <?= e($addr['address_line1']) ?><br>
                    <?php if ($addr['address_line2']): ?><?= e($addr['address_line2']) ?><br><?php endif; ?>
                    <?= e($addr['city']) ?>, <?= e($addr['state']) ?> <?= e($addr['postal_code']) ?><br>
                    <?= e($addr['country']) ?>
                </div>
                <?php if ($addr['phone']): ?>
                    <div style="margin-top: 8px;">📞 <?= e($addr['phone']) ?></div>
                <?php endif; ?>
                <?php if ($addr['gstin']): ?>
                    <div style="margin-top: 4px;">GST: <?= e($addr['gstin']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- DOCUMENTS TAB -->
    <?php if ($tab === 'documents'): ?>
    <div class="content-card">
        <div class="section-title">
            <h2>📄 Documents & Vouchers</h2>
        </div>
        
        <?php if (empty($documents)): ?>
            <p style="color: var(--muted); text-align: center; padding: 40px;">
                No documents available yet.<br>
                Documents will appear here when orders are processed.
            </p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Type</th>
                            <th>Reference</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $doc): ?>
                        <tr>
                            <td><?= e($doc['file_name']) ?></td>
                            <td>
                                <span class="status-badge" style="background: #e0e7ff; color: #3730a3;">
                                    <?= e($doc['document_type']) ?>
                                </span>
                            </td>
                            <td>
                                <?= $doc['order_number'] ? 'Order: ' . e($doc['order_number']) : '' ?>
                                <?= $doc['quote_number'] ? 'Quote: ' . e($doc['quote_number']) : '' ?>
                            </td>
                            <td><?= date('d M Y', strtotime($doc['created_at'])) ?></td>
                            <td>
                                <a href="<?= e($doc['file_path']) ?>" target="_blank" class="btn btn-outline btn-sm">Download</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- NOTIFICATIONS TAB -->
    <?php if ($tab === 'notifications'): ?>
    <div class="content-card">
        <div class="section-title">
            <h2>🔔 Notifications</h2>
        </div>
        
        <?php if (empty($notifications)): ?>
            <p style="color: var(--muted); text-align: center; padding: 40px;">
                No notifications yet.
            </p>
        <?php else: ?>
            <?php foreach ($notifications as $n): ?>
            <div class="notification-item <?= $n['is_read'] ? '' : 'unread' ?>">
                <div class="notification-icon">
                    <?php
                    $icons = [
                        'order' => '📦',
                        'quote' => '📝',
                        'invoice' => '🧾',
                        'system' => 'ℹ️'
                    ];
                    echo $icons[$n['type']] ?? '🔔';
                    ?>
                </div>
                <div class="notification-content">
                    <div class="notification-title">
                        <?= e($n['title']) ?>
                        <?php if (!$n['is_read']): ?>
                            <span style="background: var(--accent); color: white; padding: 2px 6px; border-radius: 10px; font-size: 0.6rem; margin-left: 8px;">NEW</span>
                        <?php endif; ?>
                    </div>
                    <div><?= e($n['message']) ?></div>
                    <div class="notification-time"><?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></div>
                    <?php if ($n['link']): ?>
                        <a href="<?= e($n['link']) ?>" style="font-size: 0.85rem; margin-top: 8px; display: inline-block;">View Details →</a>
                    <?php endif; ?>
                </div>
                <?php if (!$n['is_read']): ?>
                    <a href="?tab=notifications&mark_read=<?= $n['id'] ?>" style="color: var(--accent); font-size: 0.8rem;">Mark read</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
</div>

<script>
function toggleAddAddressForm() {
    const form = document.getElementById('addAddressForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php include 'footer.php'; ?>
</body>
</html>