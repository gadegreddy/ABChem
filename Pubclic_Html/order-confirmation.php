<?php
/**
 * order-confirmation.php - Order Success Page
 */
require_once __DIR__ . '/../private/functions.php';

enforceSessionTimeout(900);
if (!isset($_SESSION['user'])) {
    header('Location: /signin');
    exit;
}

$orderNumber = $_GET['order'] ?? '';
$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Fetch order details
$order = null;
if (!empty($orderNumber)) {
    $order = $db->fetchOne(
        "SELECT * FROM orders WHERE order_number = :num AND user_id = :uid ORDER BY created_at DESC LIMIT 1",
        ['num' => $orderNumber, 'uid' => $userId]
    );
}

// Get recent order if no specific order number
if (!$order) {
    $order = $db->fetchOne(
        "SELECT * FROM orders WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1",
        ['uid' => $userId]
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed | AB Chem India</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
    <style>
        .confirmation-container {
            max-width: 700px;
            margin: 60px auto;
            padding: 0 20px;
        }
        .confirmation-card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            text-align: center;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        .success-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }
        .order-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
            background: #f8fafc;
            padding: 12px 24px;
            border-radius: 8px;
            display: inline-block;
            margin: 16px 0;
            border: 2px dashed #e2e8f0;
        }
        .next-steps {
            background: #f0fdf4;
            padding: 20px;
            border-radius: 8px;
            margin: 24px 0;
            text-align: left;
        }
        .next-steps h3 { color: #166534; margin-bottom: 12px; }
        .next-steps ol { padding-left: 20px; color: #166534; }
        .next-steps ol li { margin-bottom: 8px; }
        .action-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 24px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="confirmation-container">
    <div class="confirmation-card">
        <div class="success-icon">🎉</div>
        <h1 style="color: #0f172a; margin-bottom: 8px;">Order Placed Successfully!</h1>
        <p style="color: #64748b; margin-bottom: 16px;">
            Thank you for your order. Our team will review and process it shortly.
        </p>
        
        <?php if ($order): ?>
        <div class="order-number">
            Order #<?= e($order['order_number']) ?>
        </div>
        <p style="color: #64748b; margin-top: 12px; font-size: 0.9rem;">
            Placed on: <?= date('d M Y, h:i A', strtotime($order['created_at'] ?? 'now')) ?>
        </p>
        <?php endif; ?>
        
        <div class="next-steps">
            <h3>📋 What happens next?</h3>
            <ol>
                <li>Our team will review your order within 24 hours</li>
                <li>You'll receive a confirmation email with pricing details</li>
                <li>Once confirmed, we'll process and ship your order</li>
                <li>You can track your order status from your dashboard</li>
            </ol>
        </div>
        
        <div class="action-buttons">
            <a href="/dashboard?tab=orders" class="btn btn-primary">
                📦 View My Orders
            </a>
            <a href="/catalog" class="btn btn-outline">
                🔍 Continue Shopping
            </a>
            <a href="/contact" class="btn btn-outline">
                📧 Contact Support
            </a>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>