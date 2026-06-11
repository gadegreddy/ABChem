<?php
/**
  */
require_once __DIR__ . '/../private/functions.php';
initCart();

// Enforce login
enforceSessionTimeout(900);
if (!isset($_SESSION['user'])) {
    header('Location: /signin?redirect=/cart');
    exit;
}

$db = Database::getInstance();
$message = '';
$error = '';

// Handle cart actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update') {
        $key = $_POST['key'] ?? '';
        $quantity = max(0.001, floatval($_POST['quantity'] ?? 1));
        
        if (isset($_SESSION['cart'][$key])) {
            if ($quantity > 0) {
                $_SESSION['cart'][$key]['quantity'] = $quantity;
                $message = 'Cart updated.';
            } else {
                unset($_SESSION['cart'][$key]);
                $message = 'Item removed from cart.';
            }
        }
    }
    
    if ($action === 'remove') {
        $key = $_POST['key'] ?? '';
        if (isset($_SESSION['cart'][$key])) {
            unset($_SESSION['cart'][$key]);
            $message = 'Item removed from cart.';
        }
    }
    
    if ($action === 'clear') {
        $_SESSION['cart'] = [];
        $message = 'Cart cleared.';
    }
    
    if ($action === 'place_order') {
        // ✅ DIRECT ORDER instead of quote request
        if (!empty($_SESSION['cart'])) {
            $userId = $_SESSION['user_id'];
            $notes = trim($_POST['order_notes'] ?? '');
            $purchaseOrder = trim($_POST['purchase_order'] ?? '');
            
            // Generate order number
            $orderNumber = generateOrderNumber('ORD');
            
            $db->beginTransaction();
            try {
                // Calculate total from cart items (if prices exist)
                $totalAmount = 0;
                foreach ($_SESSION['cart'] as $item) {
                    $totalAmount += ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0);
                }
                
                // Create the order
                $orderId = $db->insert('orders', [
                    'order_number' => $orderNumber,
                    'user_id' => $userId,
                    'status' => 'pending',
                    'payment_status' => 'pending',
                    'total_amount' => $totalAmount,
                    'notes' => $notes,
                    'purchase_order' => $purchaseOrder,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                
                // Add order items from cart
                foreach ($_SESSION['cart'] as $item) {
                    $itemTotal = ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0);
                    $db->insert('order_items', [
                        'order_id' => $orderId,
                        'product_id' => $item['product_id'],
                        'product_name' => $item['product_name'],
                        'cas_number' => $item['cas_number'] ?? null,
                        'quantity' => floatval($item['quantity']),
                        'unit' => $item['unit'] ?? 'mg',
                        'unit_price' => $item['unit_price'] ?? 0,
                        'total_price' => $itemTotal,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }
                
                $db->commit();
                
                // Create notification for admin
                try {
                    // Notify all admins
                    $admins = $db->fetchAll("SELECT id FROM users WHERE role = 'Admin' AND status = 'Active'");
                    foreach ($admins as $admin) {
                        createNotification($admin['id'], 'order', 'New Order Received',
                            "Order #{$orderNumber} with " . count($_SESSION['cart']) . " items has been placed.",
                            "/admin?tab=orders");
                    }
                } catch (Exception $e) {
                    // Notifications table might not exist - ignore
                }
                
                // Create notification for customer
                try {
                    createNotification($userId, 'order', 'Order Placed Successfully',
                        "Your order #{$orderNumber} has been placed. We will process it shortly.",
                        "/dashboard?tab=orders");
                } catch (Exception $e) {
                    // Ignore
                }
                
                // Log audit
                logAudit('order_placed', "User placed order #{$orderNumber} directly with " . count($_SESSION['cart']) . " items");
                
                // Clear cart
                $_SESSION['cart'] = [];
                
                // Redirect to order confirmation page
                header('Location: /order-confirmation?order=' . urlencode($orderNumber));
                exit;
                
            } catch (Exception $e) {
                $db->rollback();
                $error = 'Error placing order: ' . $e->getMessage();
                error_log('Order placement error: ' . $e->getMessage());
            }
        }
    }
}

$cartItems = $_SESSION['cart'] ?? [];
$cartCount = count($cartItems);

// Calculate estimated total
$estimatedTotal = 0;
foreach ($cartItems as $item) {
    $estimatedTotal += ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Place Order | AB Chem India</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
</head>
<body>
<?php include 'header.php'; ?>

<div class="cart-container">
    <h1 style="color: #0f172a; margin-bottom: 24px;">
        🛒 Place Order (<?= $cartCount ?> item<?= $cartCount !== 1 ? 's' : '' ?>)
    </h1>
    
    <?php if ($message): ?>
        <div class="message-success">✅ <?= e($message) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="message-error">❌ <?= e($error) ?></div>
    <?php endif; ?>
    
    <?php if (empty($cartItems)): ?>
        <div style="background: white; border-radius: 12px; padding: 60px; text-align: center; border: 1px solid #e2e8f0;">
            <p style="color: #64748b; font-size: 18px;">Your cart is empty.</p>
            <a href="/catalog" class="btn btn-primary" style="margin-top: 20px;">Browse Catalog</a>
        </div>
    <?php else: ?>
        <table class="cart-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>CAS</th>
                    <th>Purity</th>
                    <th>Quantity</th>
                    <th>Unit</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cartItems as $key => $item): ?>
                <tr>
                    <td>
                        <strong>
                            <a href="/product/<?= e($item['slug'] ?? '#') ?>"><?= e($item['product_name']) ?></a>
                        </strong>
                    </td>
                    <td><?= e($item['cas_number'] ?? 'N/A') ?></td>
                    <td><?= e($item['purity'] ?? 'N/A') ?></td>
                    <td>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="key" value="<?= e($key) ?>">
                            <input type="number" name="quantity" value="<?= e($item['quantity']) ?>" 
                                   min="0.001" step="0.001" class="quantity-input" onchange="this.form.submit()">
                        </form>
                    </td>
                    <td><?= e($item['unit'] ?? 'mg') ?></td>
                    <td>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="key" value="<?= e($key) ?>">
                            <button type="submit" style="background: none; border: none; color: #ef4444; cursor: pointer;">
                                🗑️ Remove
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Order Form -->
        <div class="checkout-section">
            <h3 style="margin: 0 0 16px; color: #0f172a;">📋 Order Details</h3>
            <form method="post">
                <input type="hidden" name="action" value="place_order">
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #1e293b;">
                        Purchase Order / Reference Number (Optional)
                    </label>
                    <input type="text" name="purchase_order" 
                           style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px;"
                           placeholder="Your PO number for reference">
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #1e293b;">
                        Special Instructions / Notes (Optional)
                    </label>
                    <textarea name="order_notes" rows="3" 
                              style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px;" 
                              placeholder="Shipping instructions, purity requirements, etc."></textarea>
                </div>
                
                <!-- Order Summary -->
                <div style="background: #f8fafc; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 12px; color: #0f172a;">Order Summary</h4>
                    <table style="width: 100%; font-size: 0.9rem;">
                        <tr>
                            <td style="padding: 4px 0; color: #64748b;">Total Items:</td>
                            <td style="text-align: right; font-weight: 600;"><?= $cartCount ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 4px 0; color: #64748b;">Order Type:</td>
                            <td style="text-align: right; font-weight: 600;">Direct Purchase Order</td>
                        </tr>
                        <?php if ($estimatedTotal > 0): ?>
                        <tr style="border-top: 1px solid #e2e8f0;">
                            <td style="padding: 8px 0; font-weight: 700; color: #0f172a;">Estimated Total:</td>
                            <td style="text-align: right; font-weight: 700; color: #0f172a; font-size: 1.1rem;">
                                ₹<?= number_format($estimatedTotal, 2) ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    <?php if ($estimatedTotal == 0): ?>
                    <p style="color: #f59e0b; font-size: 0.85rem; margin-top: 8px;">
                        ⚠️ Prices not available. Our team will send you a quotation.
                    </p>
                    <?php endif; ?>
                </div>
                
                <button type="submit" class="checkout-btn" onclick="return confirm('Confirm your order? Our team will review and process it.');">
                    ✅ Place Order
                </button>
                <p style="text-align: center; color: #64748b; font-size: 0.85rem; margin-top: 8px;">
                    By placing this order, you agree to our terms. Our team will review and confirm your order.
                </p>
            </form>
            
            <form method="post" style="margin-top: 16px; text-align: right;">
                <input type="hidden" name="action" value="clear">
                <button type="submit" style="background: none; border: none; color: #64748b; cursor: pointer; font-size: 0.85rem;">
                    Clear Cart
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
</body>
</html>