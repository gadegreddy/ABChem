<?php
/**
 * quote-detail.php - View quote details (allows both customer and admin)
 */
require_once __DIR__ . '/../private/functions.php';
enforceSessionTimeout(900);

if (!isset($_SESSION['user'])) {
    header('Location: /signin?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$db = Database::getInstance();
$quoteId = intval($_GET['id'] ?? 0);
$userId = $_SESSION['user_id'];
$isAdmin = ($_SESSION['role'] ?? '') === 'Admin';

// Fetch quote - allow admin to view any quote
if ($isAdmin) {
    $quote = $db->fetchOne(
        "SELECT q.*, u.email as user_email, u.company_name 
         FROM quote_requests q
         LEFT JOIN users u ON q.user_id = u.id
         WHERE q.id = :id",
        ['id' => $quoteId]
    );
} else {
    $quote = $db->fetchOne(
        "SELECT * FROM quote_requests 
         WHERE id = :id AND user_id = :uid",
        ['id' => $quoteId, 'uid' => $userId]
    );
}

if (!$quote) {
    http_response_code(404);
    die("Quote not found or access denied.");
}

// Fetch quote items
$items = $db->fetchAll(
    "SELECT * FROM quote_items WHERE quote_id = :qid",
    ['qid' => $quoteId]
);

$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Admin: Update quote status
    if ($isAdmin && isset($_POST['update_status'])) {
        $newStatus = $_POST['status'];
        $adminNote = $_POST['admin_note'] ?? '';
        $quotedAmount = floatval($_POST['quoted_amount'] ?? 0);
        $validUntil = $_POST['quote_valid_until'] ?? null;
        
        $updateData = [
            'status' => $newStatus,
            'admin_notes' => $adminNote,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        if ($quotedAmount > 0) {
            $updateData['quoted_amount'] = $quotedAmount;
        }
        if ($validUntil) {
            $updateData['quote_valid_until'] = $validUntil;
        }
        
        $db->update('quote_requests', $updateData, 'id = :id', ['id' => $quoteId]);
        
        // Send email to customer
        sendQuoteStatusEmail($quoteId, $newStatus, $adminNote);
        
        // Create notification for customer
        createNotification($quote['user_id'], 'quote', 
            "Quote #{$quote['quote_number']} Updated", 
            "Status changed to: {$newStatus}",
            "/quote-detail?id={$quoteId}"
        );
        
        logAudit('quote_updated', "Admin updated quote #{$quoteId} to {$newStatus}");
        $message = "Quote updated and customer notified.";
        
        // Refresh quote data
        $quote = $db->fetchOne("SELECT * FROM quote_requests WHERE id = :id", ['id' => $quoteId]);
    }
    
    // Customer: Accept quote (only if status = 'quoted')
    if (!$isAdmin && isset($_POST['accept_quote']) && $quote['status'] === 'quoted') {
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
            foreach ($items as $item) {
                $db->insert('order_items', [
                    'order_id' => $orderId,
                    'product_id' => $item['product_id'] ?? null,
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'] ?? 0,
                    'total_price' => ($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0)
                ]);
            }
            
            // Update quote status with order ID
            $db->update('quote_requests', 
                ['status' => 'accepted', 'converted_order_id' => $orderId, 'updated_at' => date('Y-m-d H:i:s')],
                'id = :id', 
                ['id' => $quoteId]
            );
            
            $db->commit();
            
            // Notify admin
            createNotification(1, 'order', 'Quote Accepted', 
                "Customer {$quote['user_email']} accepted quote #{$quote['quote_number']} → Order #{$orderNumber}",
                "/admin?tab=orders"
            );
            
            logAudit('quote_accepted', "Customer accepted quote #{$quote['quote_number']}, created order #{$orderNumber}");
            
            // Redirect to order confirmation
            header('Location: /dashboard?tab=orders&message=' . urlencode("Order #{$orderNumber} created successfully!"));
            exit;
            
        } catch (Exception $e) {
            $db->rollback();
            $error = "Failed to create order: " . $e->getMessage();
        }
    }
    
    // Customer: Delete quote (only if status = 'new')
    if (!$isAdmin && isset($_POST['delete_quote']) && $quote['status'] === 'new') {
        $db->beginTransaction();
        try {
            $db->delete('quote_items', 'quote_id = :qid', ['qid' => $quoteId]);
            $db->delete('quote_requests', 'id = :id', ['id' => $quoteId]);
            $db->commit();
            logAudit('quote_deleted', "Customer deleted quote #{$quoteId}");
            header('Location: /dashboard?tab=quotes&message=' . urlencode('Quote deleted successfully'));
            exit;
        } catch (Exception $e) {
            $db->rollback();
            $error = 'Could not delete quote.';
        }
    }
}

// Get order info if converted
$orderInfo = null;
if (!empty($quote['converted_order_id'])) {
    $orderInfo = $db->fetchOne(
        "SELECT order_number, status FROM orders WHERE id = :id",
        ['id' => $quote['converted_order_id']]
    );
}

function statusBadge($s) {
    $map = [
        'new' => '#3b82f6', 'quoted' => '#8b5cf6', 'accepted' => '#22c55e', 'rejected' => '#ef4444',
        'pending' => '#f59e0b', 'processing' => '#3b82f6', 'shipped' => '#06b6d4', 'delivered' => '#22c55e',
    ];
    $c = $map[$s] ?? '#64748b';
    return "<span style='background:{$c}22;color:{$c};padding:4px 12px;border-radius:20px;font-size:0.8rem;font-weight:600;'>" . ucfirst($s) . "</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quote #<?= e($quote['quote_number']) ?> | AB Chem</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
</head>
<body>
<?php include 'header.php'; ?>

<div class="quote-container">
    
    <div class="quote-card">
        <div class="quote-header">
            <h1 style="margin:0; color:#0f172a;">Quote #<?= e($quote['quote_number']) ?></h1>
            <?= statusBadge($quote['status']) ?>
        </div>
        
        <!-- Customer Info (visible to admin) -->
        <?php if ($isAdmin && isset($quote['user_email'])): ?>
        <div style="background: #e0f2fe; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
            <strong>Customer:</strong> <?= e($quote['company_name'] ?? $quote['user_email']) ?> (<?= e($quote['user_email']) ?>)
        </div>
        <?php endif; ?>
        
        <div class="quote-meta">
            <div><strong>Created:</strong> <?= date('d M Y, h:i A', strtotime($quote['created_at'])) ?></div>
            <?php if ($quote['subject']): ?>
            <div><strong>Subject:</strong> <?= e($quote['subject']) ?></div>
            <?php endif; ?>
            <?php if ($quote['quoted_amount'] > 0): ?>
            <div><strong>Quoted Amount:</strong> ₹<?= number_format($quote['quoted_amount'], 2) ?></div>
            <?php endif; ?>
            <?php if ($quote['quote_valid_until']): ?>
            <div><strong>Valid Until:</strong> <?= date('d M Y', strtotime($quote['quote_valid_until'])) ?></div>
            <?php endif; ?>
        </div>
        
        <?php if ($quote['notes']): ?>
        <div style="margin-bottom: 20px; padding: 12px; background: #fef3c7; border-radius: 8px;">
            <strong>Customer Notes:</strong><br>
            <?= nl2br(e($quote['notes'])) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($quote['admin_notes']): ?>
        <div style="margin-bottom: 20px; padding: 12px; background: #e0f2fe; border-radius: 8px;">
            <strong>Admin Notes:</strong><br>
            <?= nl2br(e($quote['admin_notes'])) ?>
        </div>
        <?php endif; ?>
        
        <h3 style="margin-bottom: 16px;">Items</h3>
        <table class="items-table">
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
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><strong><?= e($item['product_name']) ?></strong></td>
                    <td><?= e($item['cas_number'] ?? '—') ?></td>
                    <td><?= number_format($item['quantity'], 3) ?> <?= e($item['unit'] ?? 'mg') ?></td>
                    <td>₹<?= number_format($item['unit_price'] ?? 0, 2) ?></td>
                    <td><strong>₹<?= number_format(($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0), 2) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Customer Actions -->
        <?php if (!$isAdmin): ?>
            <?php if ($quote['status'] === 'quoted'): ?>
            <div style="margin-top: 30px; text-align: center;">
                <form method="post" id="acceptForm" onsubmit="return confirmAccept()">
                    <button type="submit" name="accept_quote" class="btn-success">
                        ✅ Accept Quote & Create Order
                    </button>
                </form>
                <p style="color:#64748b; font-size:14px; margin-top:12px;">
                    By accepting, you agree to the quoted pricing and terms. No payment required at this stage.
                </p>
            </div>
            <?php elseif ($quote['status'] === 'accepted' && $orderInfo): ?>
            <div style="margin-top: 30px; background: #dcfce7; padding: 20px; border-radius: 8px; text-align: center;">
                <strong style="font-size:18px;">✅ Quote Accepted</strong><br>
                Order #<?= e($orderInfo['order_number']) ?> has been created.<br>
                <a href="/dashboard?tab=orders" class="btn btn-primary" style="margin-top: 16px;">View Order</a>
            </div>
            <?php elseif ($quote['status'] === 'accepted'): ?>
            <div style="margin-top: 30px; background: #dcfce7; padding: 20px; border-radius: 8px; text-align: center;">
                <strong>✅ Quote Accepted</strong><br>
                <a href="/dashboard?tab=orders" class="btn btn-primary" style="margin-top: 16px;">View Orders</a>
            </div>
            <?php endif; ?>
            
            <?php if ($quote['status'] === 'new'): ?>
            <div style="margin-top: 30px; display: flex; justify-content: flex-end;">
                <form method="post" id="deleteForm" onsubmit="return confirmDelete()">
                    <input type="hidden" name="delete_quote" value="1">
                    <button type="submit" class="btn-danger">🗑️ Delete Quote</button>
                </form>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <!-- Admin Actions -->
        <?php if ($isAdmin): ?>
        <div class="admin-section">
            <h3 style="margin-bottom: 16px;">🔧 Admin Actions</h3>
            <form method="post">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px;">Status</label>
                        <select name="status" style="width:100%; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                            <option value="new" <?= $quote['status'] === 'new' ? 'selected' : '' ?>>New</option>
                            <option value="quoted" <?= $quote['status'] === 'quoted' ? 'selected' : '' ?>>Quoted</option>
                            <option value="accepted" <?= $quote['status'] === 'accepted' ? 'selected' : '' ?>>Accepted</option>
                            <option value="rejected" <?= $quote['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px;">Quoted Amount (₹)</label>
                        <input type="number" name="quoted_amount" step="0.01" value="<?= $quote['quoted_amount'] ?? '' ?>" style="width:100%; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    </div>
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px;">Valid Until</label>
                        <input type="date" name="quote_valid_until" value="<?= $quote['quote_valid_until'] ?? '' ?>" style="width:100%; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    </div>
                </div>
                <div style="margin-top: 16px;">
                    <label style="display:block; font-weight:600; margin-bottom:4px;">Admin Note</label>
                    <textarea name="admin_note" rows="3" style="width:100%; padding:10px; border-radius:6px; border:1px solid #e2e8f0;"><?= e($quote['admin_notes'] ?? '') ?></textarea>
                </div>
                <div style="margin-top: 16px;">
                    <button type="submit" name="update_status" class="btn btn-primary">Update Quote</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
        
        <div style="margin-top: 30px;">
            <?php if ($isAdmin): ?>
            <a href="/admin?tab=quotes" class="btn btn-outline">← Back to Admin</a>
            <?php else: ?>
            <a href="/dashboard?tab=quotes" class="btn btn-outline">← Back to My Quotes</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Toast Notification System -->
<div id="toastContainer" style="position:fixed; top:20px; right:20px; z-index:9999;"></div>

<script>
// Modern toast notifications (replaces alert/confirm)
function showToast(message, type = 'info', duration = 5000) {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast-message toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        padding: 16px 24px;
        border-radius: 8px;
        color: white;
        font-weight: 500;
        margin-bottom: 10px;
        animation: slideIn 0.3s ease;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        background: ${type === 'success' ? '#22c55e' : type === 'error' ? '#ef4444' : '#3b82f6'};
    `;
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

function confirmAccept() {
    return confirm('Accept this quote and create an order? You can review the order before any payment is required.');
}

function confirmDelete() {
    return confirm('Are you sure you want to delete this quote? This cannot be undone.');
}

// Show messages if any
<?php if ($message): ?>
showToast('<?= addslashes($message) ?>', 'success');
<?php endif; ?>
<?php if ($error): ?>
showToast('<?= addslashes($error) ?>', 'error');
<?php endif; ?>
</script>

<?php include 'footer.php'; ?>
</body>
</html>