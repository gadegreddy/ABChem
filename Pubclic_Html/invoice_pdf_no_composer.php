<?php
/**
 * invoice_pdf_no_composer.php - Generate printable invoice (No library needed)
 * Uses browser's "Save as PDF" feature
 */
require_once __DIR__ . '/../private/functions.php';

enforceSessionTimeout(900);
if (!isset($_SESSION['user'])) {
    header('Location: /signin');
    exit;
}

$db = Database::getInstance();
$orderId = intval($_GET['id'] ?? 0);
$userId = $_SESSION['user_id'];
$isAdmin = ($_SESSION['role'] ?? '') === 'Admin';

// Fetch order
if ($isAdmin) {
    $order = $db->fetchOne(
        "SELECT o.*, u.company_name, u.contact_name, u.email, u.phone
         FROM orders o
         LEFT JOIN users u ON o.user_id = u.id
         WHERE o.id = :id",
        ['id' => $orderId]
    );
} else {
    $order = $db->fetchOne(
        "SELECT o.*, u.company_name, u.contact_name, u.email, u.phone
         FROM orders o
         LEFT JOIN users u ON o.user_id = u.id
         WHERE o.id = :id AND o.user_id = :uid",
        ['id' => $orderId, 'uid' => $userId]
    );
}

if (!$order) {
    die("Order not found or access denied.");
}

$items = $db->fetchAll("SELECT * FROM order_items WHERE order_id = :oid", ['oid' => $orderId]);
$subtotal = $order['total_amount'] ?? 0;
$cgst = $subtotal * 0.09;
$sgst = $subtotal * 0.09;
$grandTotal = $subtotal + $cgst + $sgst;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?= e($order['order_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 12px; color: #1e293b; padding: 20px; }
        .header { text-align: center; border-bottom: 3px solid #0f172a; padding-bottom: 15px; margin-bottom: 20px; }
        .header h1 { color: #0f172a; margin: 0; font-size: 24px; }
        .header h1 span { color: #0284c7; }
        .header p { margin: 3px 0; color: #64748b; font-size: 11px; }
        .invoice-title { text-align: right; font-size: 18px; font-weight: bold; color: #0f172a; margin-bottom: 20px; }
        .info-grid { display: flex; gap: 20px; margin-bottom: 20px; }
        .info-box { flex: 1; padding: 12px; border: 1px solid #e2e8f0; border-radius: 6px; }
        .info-box h3 { margin: 0 0 8px 0; font-size: 13px; color: #0f172a; background: #f8fafc; padding: 6px 10px; border-radius: 4px; }
        .info-box p { margin: 3px 0; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { background: #0f172a; color: white; padding: 10px; text-align: left; font-size: 11px; }
        td { padding: 10px; border-bottom: 1px solid #e2e8f0; font-size: 11px; }
        .totals { float: right; width: 300px; margin-top: 10px; }
        .totals table { width: 100%; }
        .totals td { padding: 6px 10px; font-size: 14px; border: none; }
        .totals .grand-total { font-size: 16px; font-weight: bold; border-top: 2px solid #0f172a; }
        .footer { text-align: center; margin-top: 40px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 10px; color: #64748b; }
        .bank-details { margin-top: 20px; padding: 12px; background: #f8fafc; border-radius: 6px; font-size: 11px; }
        .print-btn { text-align: center; margin: 20px 0; }
        @media print {
            .print-btn { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="print-btn">
        <button onclick="window.print()" style="padding:12px 32px; background:#0284c7; color:white; border:none; border-radius:8px; cursor:pointer; font-size:16px; font-weight:600;">
            🖨️ Print / Save as PDF
        </button>
        <p style="color:#64748b; margin-top:8px;">Use Ctrl+P or Cmd+P and select "Save as PDF"</p>
    </div>

    <div class="header">
        <h1>AB<span>Chem</span> India</h1>
        <p>Balanagar, Hyderabad, India | +91-97 05 09 2020</p>
        <p>connect@abchem.co.in | www.abchem.co.in</p>
        <p>GSTIN: 36ACDFA7838D1ZG</p>
    </div>

    <div class="invoice-title">TAX INVOICE</div>

    <div class="info-grid">
        <div class="info-box">
            <h3>Bill To:</h3>
            <p><strong><?= e($order['company_name'] ?? $order['contact_name'] ?? 'Customer') ?></strong></p>
            <p>Email: <?= e($order['email'] ?? 'N/A') ?></p>
            <p>Phone: <?= e($order['phone'] ?? 'N/A') ?></p>
        </div>
        <div class="info-box" style="text-align: right;">
            <h3>Invoice Details:</h3>
            <p><strong>Invoice #:</strong> <?= e($order['order_number']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($order['created_at'] ?? 'now')) ?></p>
            <p><strong>Status:</strong> <?= ucfirst($order['status'] ?? 'Pending') ?></p>
            <p><strong>Payment:</strong> <?= ucfirst($order['payment_status'] ?? 'Pending') ?></p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Product / Service</th>
                <th>CAS Number</th>
                <th>Qty</th>
                <th>Unit</th>
                <th>Rate (₹)</th>
                <th>Amount (₹)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $item): 
                $lineTotal = ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0);
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= e($item['product_name'] ?? 'N/A') ?></strong></td>
                <td><?= e($item['cas_number'] ?? 'N/A') ?></td>
                <td><?= number_format($item['quantity'] ?? 0, 3) ?></td>
                <td><?= e($item['unit'] ?? 'mg') ?></td>
                <td>₹<?= number_format($item['unit_price'] ?? 0, 2) ?></td>
                <td><strong>₹<?= number_format($lineTotal, 2) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <table>
            <tr><td>Subtotal:</td><td style="text-align:right;">₹<?= number_format($subtotal, 2) ?></td></tr>
            <tr><td>CGST (9%):</td><td style="text-align:right;">₹<?= number_format($cgst, 2) ?></td></tr>
            <tr><td>SGST (9%):</td><td style="text-align:right;">₹<?= number_format($sgst, 2) ?></td></tr>
            <tr class="grand-total"><td>Grand Total:</td><td style="text-align:right;">₹<?= number_format($grandTotal, 2) ?></td></tr>
        </table>
    </div>

    <div style="clear: both;"></div>

    <div class="bank-details">
        <h3>Bank Details for Payment:</h3>
        <p><strong>Bank:</strong> [Your Bank Name] | <strong>A/C:</strong> [Account Number] | <strong>IFSC:</strong> [IFSC Code]</p>
        <p><strong>UPI:</strong> [Your UPI ID]</p>
    </div>

    <div class="footer">
        <p>This is a computer-generated invoice. No signature required.</p>
        <p>© <?= date('Y') ?> AB Chem India. All rights reserved.</p>
    </div>
</body>
</html>