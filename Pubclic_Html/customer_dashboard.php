<?php
/**
 * customer_dashboard.php - Customer Quote & Order Management
 * For Customers, Buyers, and Vendors (NOT Admins)
 */
require_once __DIR__ . '/../private/functions.php';

// Enforce 15-minute session timeout
enforceSessionTimeout(900);

// Gate: Must be logged in (any role)
if (!isset($_SESSION['user'])) {
    header('Location: /signin.php?redirect=/customer-dashboard');
    exit;
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'User';
$message = '';
$error = '';

// Get active tab
$tab = $_GET['tab'] ?? 'quotes'; // Default to quotes for customer workflow

// =============================================
// POST HANDLERS - Customer Actions
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Customer accepts a quote
    if (isset($_POST['accept_quote'])) {
        $quoteId = intval($_POST['quote_id']);
        
        // Verify quote belongs to this user and is in 'quoted' status
        $quote = $db->fetchOne(
            "SELECT * FROM quote_requests WHERE id = :id AND user_id = :uid AND status = 'quoted'",
            ['id' => $quoteId, 'uid' => $userId]
        );
        
        if ($quote) {
            $orderNumber = generateOrderNumber('ORD');
            
            $db->beginTransaction();
            try {
                // Create order
                $orderId = $db->insert('orders', [
                    'order_number' => $orderNumber,
                    'user_id' => $userId,
                    'status' => 'pending',
                    'payment_status' => 'pending',
                    'total_amount' => $quote['quoted_amount'] ?? 0,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                
                // Copy quote items to order_items
                $quoteItems = $db->fetchAll(
                    "SELECT * FROM quote_items WHERE quote_id = :qid",
                    ['qid' => $quoteId]
                );
                
                foreach ($quoteItems as $item) {
                    $db->insert('order_items', [
                        'order_id' => $orderId,
                        'product_id' => $item['product_id'] ?? null,
                        'product_name' => $item['product_name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'] ?? 0,
                        'total_price' => ($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0)
                    ]);
                }
                
                // Update quote status
                $db->update('quote_requests', 
                    ['status' => 'accepted', 'converted_order_id' => $orderId],
                    'id = :id', 
                    ['id' => $quoteId]
                );
                
                $db->commit();
                
                // Notify admin
                createNotification(1, 'order', 'Quote Accepted', 
                    "Customer {$_SESSION['user']} accepted quote #{$quote['quote_number']} → Order #{$orderNumber}",
                    "/admin?tab=orders"
                );
                
                logAudit('quote_accepted', "Customer accepted quote #{$quote['quote_number']}, created order #{$orderNumber}");
                
                $message = "✅ Quote accepted! Order #{$orderNumber} has been created.";
                header('Location: /customer-dashboard?tab=orders&message=' . urlencode($message));
                exit;
                
            } catch (Exception $e) {
                $db->rollback();
                $error = "Failed to create order: " . $e->getMessage();
            }
        } else {
            $error = "Quote not found or cannot be accepted.";
        }
    }
    
    // Customer deletes their own quote (only if status = 'new')
    if (isset($_POST['delete_quote'])) {
        $quoteId = intval($_POST['quote_id']);
        
        // Verify ownership and status
        $quote = $db->fetchOne(
            "SELECT id, status FROM quote_requests WHERE id = :id AND user_id = :uid",
            ['id' => $quoteId, 'uid' => $userId]
        );
        
        if ($quote && $quote['status'] === 'new') {
            $db->beginTransaction();
            try {
                $db->delete('quote_items', 'quote_id = :qid', ['qid' => $quoteId]);
                $db->delete('quote_requests', 'id = :id', ['id' => $quoteId]);
                $db->commit();
                logAudit('quote_deleted', "Customer deleted quote #{$quoteId}");
                $message = 'Quote deleted successfully.';
            } catch (Exception $e) {
                $db->rollback();
                $error = 'Could not delete quote.';
            }
        } else {
            $error = 'Only pending quotes can be deleted.';
        }
    }
}

// =============================================
// FETCH DATA FOR DISPLAY
// =============================================
$quotes = $orders = $savedProducts = [];
$quoteItems = [];

switch ($tab) {
    case 'quotes':
        $quotes = $db->fetchAll("
            SELECT q.*, 
                   (SELECT COUNT(*) FROM quote_items WHERE quote_id = q.id) as item_count
            FROM quote_requests q
            WHERE q.user_id = :uid
            ORDER BY q.created_at DESC
        ", ['uid' => $userId]);
        break;
        
    case 'orders':
        $orders = $db->fetchAll("
            SELECT o.*, 
                   (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
            FROM orders o
            WHERE o.user_id = :uid
            ORDER BY o.created_at DESC
        ", ['uid' => $userId]);
        break;
        
    case 'saved':
        $savedProducts = $db->fetchAll("
            SELECT c.*, c.compound_name AS product_name, sp.created_at as saved_at
            FROM saved_products sp
            JOIN compounds c ON sp.product_id = c.id
            WHERE sp.user_id = :uid
            ORDER BY sp.created_at DESC
        ", ['uid' => $userId]);
        break;
}

// Get quote items for detail view
$quoteDetail = null;
if (isset($_GET['quote_id']) && is_numeric($_GET['quote_id'])) {
    $quoteId = intval($_GET['quote_id']);
    $quoteDetail = $db->fetchOne(
        "SELECT * FROM quote_requests WHERE id = :id AND user_id = :uid",
        ['id' => $quoteId, 'uid' => $userId]
    );
    if ($quoteDetail) {
        $quoteItems = $db->fetchAll(
            "SELECT * FROM quote_items WHERE quote_id = :qid",
            ['qid' => $quoteId]
        );
    }
}

// Helper function for status badges
function statusBadge($s) {
    $map = [
        'new' => '#3b82f6',
        'quoted' => '#8b5cf6', 
        'accepted' => '#22c55e', 
        'rejected' => '#ef4444',
        'pending' => '#f59e0b',
        'processing' => '#3b82f6',
        'shipped' => '#06b6d4',
        'delivered' => '#22c55e',
        'cancelled' => '#ef4444',
    ];
    $c = $map[$s] ?? '#64748b';
    return "<span style='background:{$c}22;color:{$c};padding:2px 10px;border-radius:12px;font-size:0.75rem;font-weight:600;'>" . ucfirst($s) . "</span>";
}

// Get URL message
if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Quotes & Orders | AB Chem India</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
    <style>
        .customer-container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .page-header h1 {
            color: var(--primary);
            margin: 0;
        }
        
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 8px;
        }
        
        .tabs a {
            padding: 12px 24px;
            text-decoration: none;
            font-weight: 500;
            border-radius: 8px 8px 0 0;
            transition: all 0.2s;
        }
        
        .tabs a.active {
            background: var(--accent);
            color: white;
        }
        
        .tabs a:not(.active) {
            color: var(--muted);
        }
        
        .tabs a:not(.active):hover {
            background: var(--bg);
        }
        
        .message {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .message-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .message-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .data-table {
            width: 100%;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border);
        }
        
        .data-table th {
            background: #0f172a;
            color: white;
            padding: 14px 16px;
            text-align: left;
            font-weight: 500;
        }
        
        .data-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        .data-table tr:hover td {
            background: var(--bg);
        }
        
        .btn-accept {
            background: #22c55e;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
        }
        
        .btn-accept:hover {
            background: #16a34a;
        }
        
        .btn-delete {
            color: #dc2626;
            border-color: #dc2626;
        }
        
        .btn-delete:hover {
            background: #dc2626;
            color: white;
        }
        
        .quote-detail-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid var(--border);
            margin-bottom: 24px;
        }
        
        .quote-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .saved-product-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            transition: all 0.2s;
        }
        
        .saved-product-card:hover {
            border-color: var(--accent);
            box-shadow: var(--shadow);
        }
        
        .saved-product-card h4 {
            margin: 0 0 12px 0;
        }
        
        .saved-product-card h4 a {
            color: var(--text);
            text-decoration: none;
        }
        
        .saved-product-card h4 a:hover {
            color: var(--accent);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        
        .empty-state p {
            color: var(--muted);
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="customer-container">
    
    <!-- Header -->
    <div class="page-header">
        <h1>📋 My Quotes & Orders</h1>
        <div>
            <a href="/catalog" class="btn btn-primary">🔍 Browse Catalog</a>
            <a href="/dashboard" class="btn btn-outline">📊 Dashboard</a>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="message message-success">✅ <?= e($message) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="message message-error">❌ <?= e($error) ?></div>
    <?php endif; ?>
    
    <!-- Tabs -->
    <div class="tabs">
        <a href="?tab=quotes" class="<?= $tab === 'quotes' ? 'active' : '' ?>">
            📝 My Quotes
        </a>
        <a href="?tab=orders" class="<?= $tab === 'orders' ? 'active' : '' ?>">
            📦 My Orders
        </a>
        <a href="?tab=saved" class="<?= $tab === 'saved' ? 'active' : '' ?>">
            ⭐ Saved Products
        </a>
    </div>
    
    <!-- ============================================= -->
    <!-- QUOTE DETAIL VIEW (when quote_id is provided) -->
    <!-- ============================================= -->
    <?php if ($quoteDetail): ?>
    <div class="quote-detail-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0;">Quote #<?= e($quoteDetail['quote_number']) ?></h2>
            <?= statusBadge($quoteDetail['status']) ?>
        </div>
        
        <div class="quote-meta">
            <div><strong>Created:</strong> <?= date('d M Y, h:i A', strtotime($quoteDetail['created_at'])) ?></div>
            <?php if ($quoteDetail['subject']): ?>
            <div><strong>Subject:</strong> <?= e($quoteDetail['subject']) ?></div>
            <?php endif; ?>
            <?php if ($quoteDetail['quoted_amount']): ?>
            <div><strong>Quoted Amount:</strong> ₹<?= number_format($quoteDetail['quoted_amount'], 2) ?></div>
            <?php endif; ?>
            <?php if ($quoteDetail['quote_valid_until']): ?>
            <div><strong>Valid Until:</strong> <?= date('d M Y', strtotime($quoteDetail['quote_valid_until'])) ?></div>
            <?php endif; ?>
        </div>
        
        <?php if ($quoteDetail['admin_notes']): ?>
        <div style="background: #f8fafc; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
            <strong>Admin Notes:</strong><br>
            <?= nl2br(e($quoteDetail['admin_notes'])) ?>
        </div>
        <?php endif; ?>
        
        <h3>Items</h3>
        <table class="data-table" style="margin-bottom: 20px;">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>CAS</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quoteItems as $item): ?>
                <tr>
                    <td><strong><?= e($item['product_name']) ?></strong></td>
                    <td><?= e($item['cas_number'] ?? '—') ?></td>
                    <td><?= number_format($item['quantity'], 3) ?> <?= e($item['unit'] ?? 'mg') ?></td>
                    <td>₹<?= number_format($item['unit_price'] ?? 0, 2) ?></td>
                    <td>₹<?= number_format(($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0), 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($quoteDetail['status'] === 'quoted'): ?>
        <div style="text-align: center; margin-top: 24px;">
            <form method="post" onsubmit="return confirm('Accept this quote and create an order?');">
                <input type="hidden" name="quote_id" value="<?= $quoteDetail['id'] ?>">
                <button type="submit" name="accept_quote" class="btn-accept">
                    ✅ Accept Quote & Create Order
                </button>
            </form>
            <p style="color: var(--muted); font-size: 14px; margin-top: 12px;">
                By accepting, you agree to the quoted pricing and terms.
            </p>
        </div>
        <?php endif; ?>
        
        <div style="margin-top: 24px;">
            <a href="?tab=quotes" class="btn btn-outline">← Back to Quotes</a>
        </div>
    </div>
    
    <!-- ============================================= -->
    <!-- QUOTES LIST -->
    <!-- ============================================= -->
    <?php elseif ($tab === 'quotes'): ?>
    <h2 style="margin-bottom: 20px;">My Quote Requests</h2>
    
    <?php if (empty($quotes)): ?>
    <div class="empty-state">
        <p>You haven't submitted any quote requests yet.</p>
        <a href="/catalog" class="btn btn-primary">Browse Products</a>
        <a href="/contact" class="btn btn-outline">Request a Quote</a>
    </div>
    <?php else: ?>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Quote #</th>
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
                    <td><?= e(substr($q['subject'] ?? '—', 0, 40)) ?></td>
                    <td><?= $q['item_count'] ?? 0 ?> item(s)</td>
                    <td><?= $q['quoted_amount'] ? '₹' . number_format($q['quoted_amount'], 2) : 'Pending' ?></td>
                    <td><?= statusBadge($q['status']) ?></td>
                    <td><?= date('d M Y', strtotime($q['created_at'])) ?></td>
                    <td>
                        <div style="display: flex; gap: 8px;">
                            <a href="?tab=quotes&quote_id=<?= $q['id'] ?>" class="btn btn-outline btn-sm">View</a>
                            <?php if ($q['status'] === 'new'): ?>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this quote?');">
                                <input type="hidden" name="delete_quote" value="1">
                                <input type="hidden" name="quote_id" value="<?= $q['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm btn-delete">🗑️</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- ============================================= -->
    <!-- ORDERS LIST -->
    <!-- ============================================= -->
    <?php elseif ($tab === 'orders'): ?>
    <h2 style="margin-bottom: 20px;">My Orders</h2>
    
    <?php if (empty($orders)): ?>
    <div class="empty-state">
        <p>You don't have any orders yet.</p>
        <a href="/catalog" class="btn btn-primary">Browse Products</a>
    </div>
    <?php else: ?>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Items</th>
                    <th>Total</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Tracking</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td><strong><?= e($o['order_number']) ?></strong></td>
                    <td><?= $o['item_count'] ?? 0 ?> item(s)</td>
                    <td>₹<?= number_format($o['total_amount'], 2) ?></td>
                    <td><?= statusBadge($o['payment_status']) ?></td>
                    <td><?= statusBadge($o['status']) ?></td>
                    <td><?= e($o['tracking_number'] ?? '—') ?></td>
                    <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- ============================================= -->
    <!-- SAVED PRODUCTS -->
    <!-- ============================================= -->
    <?php elseif ($tab === 'saved'): ?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>⭐ Saved Products</h2>
        <span><?= count($savedProducts) ?> products</span>
    </div>
    
    <?php if (empty($savedProducts)): ?>
    <div class="empty-state">
        <p>You haven't saved any products yet.</p>
        <a href="/catalog" class="btn btn-primary">Browse Catalog</a>
    </div>
    <?php else: ?>
    <div class="product-grid">
        <?php foreach ($savedProducts as $p): ?>
        <div class="saved-product-card">
            <form method="post" action="/dashboard" style="float: right;">
                <input type="hidden" name="action" value="remove_saved">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                <button type="submit" style="background: none; border: none; cursor: pointer; font-size: 16px;" 
                        onclick="return confirm('Remove from saved?')" title="Remove">🗑️</button>
            </form>
            <h4><a href="/product/<?= e($p['slug']) ?>"><?= e($p['product_name']) ?></a></h4>
            <div style="font-size: 13px; color: var(--muted); margin: 12px 0;">
                <strong>CAS:</strong> <?= e($p['cas_number'] ?? 'N/A') ?><br>
                <strong>Purity:</strong> <?= e($p['purity'] ?? 'N/A') ?><br>
                <strong>Type:</strong> <?= e($p['product_type'] ?? 'N/A') ?>
            </div>
            <div style="display: flex; gap: 8px;">
                <a href="/product/<?= e($p['slug']) ?>" class="btn btn-outline btn-sm">View</a>
                <a href="/contact?subject=<?= urlencode($p['product_name']) ?>" class="btn btn-primary btn-sm">Quote</a>
            </div>
            <div style="font-size: 12px; color: var(--muted); margin-top: 12px;">
                Saved on <?= date('d M Y', strtotime($p['saved_at'])) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <?php endif; ?>
    
</div>

<?php include 'footer.php'; ?>
</body>
</html>