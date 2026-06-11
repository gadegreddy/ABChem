<?php
// invoice_pdf.php - Generate PDF Invoice
require_once __DIR__ . '/../private/functions.php';
require_once 'vendor/autoload.php'; // Autoload Dompdf

use Dompdf\Dompdf;
use Dompdf\Options;

// Enforce login
enforceSessionTimeout(900);
if (!isset($_SESSION['user'])) {
    header('Location: /signin');
    exit;
}

$db = Database::getInstance();
$orderId = intval($_GET['id'] ?? 0);
$userId = $_SESSION['user_id'];

// Get order details
$order = $db->fetchOne(
    "SELECT o.*, u.company_name, u.contact_name, u.email, u.phone
     FROM orders o
     LEFT JOIN users u ON o.user_id = u.id
     WHERE o.id = :id AND o.user_id = :uid",
    ['id' => $orderId, 'uid' => $userId]
);

if (!$order) {
    die("Order not found or access denied.");
}

// Get order items
$items = $db->fetchAll(
    "SELECT * FROM order_items WHERE order_id = :oid",
    ['oid' => $orderId]
);

// Get company address (from settings or hardcoded)
$companyInfo = [
    'name' => 'AB Chem India',
    'address' => 'Balanagar, Hyderabad, India',
    'phone' => '+91-97 05 09 2020',
    'email' => 'connect@abchem.co.in',
    'gstin' => '36ACDFA7838D1ZG',
    'website' => 'www.abchem.co.in'
];

// Build HTML for PDF
$html = '
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; }
        .header { text-align: center; border-bottom: 3px solid #0f172a; padding-bottom: 15px; margin-bottom: 20px; }
        .header h1 { color: #0f172a; margin: 0; font-size: 24px; }
        .header h1 span { color: #0284c7; }
        .header p { margin: 5px 0; color: #64748b; font-size: 11px; }
        .invoice-title { text-align: right; font-size: 18px; font-weight: bold; color: #0f172a; margin-bottom: 20px; }
        .info-grid { display: table; width: 100%; margin-bottom: 20px; }
        .info-box { display: table-cell; width: 48%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 6px; vertical-align: top; }
        .info-box h3 { margin: 0 0 8px 0; font-size: 13px; color: #0f172a; background: #f8fafc; padding: 6px 10px; border-radius: 4px; }
        .info-box p { margin: 3px 0; font-size: 11px; }
        .items-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .items-table th { background: #0f172a; color: white; padding: 10px; text-align: left; font-size: 11px; }
        .items-table td { padding: 10px; border-bottom: 1px solid #e2e8f0; font-size: 11px; }
        .items-table tr:last-child td { border-bottom: 2px solid #0f172a; }
        .totals { float: right; width: 300px; margin-top: 10px; }
        .totals table { width: 100%; }
        .totals td { padding: 6px 10px; font-size: 11px; }
        .totals .grand-total { font-size: 14px; font-weight: bold; border-top: 2px solid #0f172a; }
        .footer { text-align: center; margin-top: 40px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 10px; color: #64748b; }
        .bank-details { margin-top: 20px; padding: 12px; background: #f8fafc; border-radius: 6px; font-size: 11px; }
        .bank-details h3 { margin: 0 0 8px 0; font-size: 12px; }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>AB<span>Chem</span> India</h1>
        <p>' . htmlspecialchars($companyInfo['address']) . ' | ' . htmlspecialchars($companyInfo['phone']) . '</p>
        <p>' . htmlspecialchars($companyInfo['email']) . ' | ' . htmlspecialchars($companyInfo['website']) . '</p>
        <p>GSTIN: ' . htmlspecialchars($companyInfo['gstin']) . '</p>
    </div>

    <!-- Invoice Title -->
    <div class="invoice-title">TAX INVOICE</div>

    <!-- Company & Customer Info -->
    <div class="info-grid">
        <div class="info-box">
            <h3>Bill To:</h3>
            <p><strong>' . htmlspecialchars($order['company_name'] ?? $order['contact_name'] ?? 'Customer') . '</strong></p>
            <p>Email: ' . htmlspecialchars($order['email'] ?? 'N/A') . '</p>
            <p>Phone: ' . htmlspecialchars($order['phone'] ?? 'N/A') . '</p>
        </div>
        <div class="info-box" style="text-align: right;">
            <h3>Invoice Details:</h3>
            <p><strong>Invoice #:</strong> ' . htmlspecialchars($order['order_number']) . '</p>
            <p><strong>Date:</strong> ' . date('d M Y', strtotime($order['created_at'] ?? 'now')) . '</p>
            <p><strong>Status:</strong> ' . ucfirst($order['status'] ?? 'Pending') . '</p>
            <p><strong>Payment:</strong> ' . ucfirst($order['payment_status'] ?? 'Pending') . '</p>
        </div>
    </div>

    <!-- Items Table -->
    <table class="items-table">
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
        <tbody>';

$subtotal = 0;
foreach ($items as $i => $item) {
    $lineTotal = ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0);
    $subtotal += $lineTotal;
    $html .= '
            <tr>
                <td>' . ($i + 1) . '</td>
                <td><strong>' . htmlspecialchars($item['product_name'] ?? 'N/A') . '</strong></td>
                <td>' . htmlspecialchars($item['cas_number'] ?? 'N/A') . '</td>
                <td>' . number_format($item['quantity'] ?? 0, 3) . '</td>
                <td>mg</td>
                <td>₹' . number_format($item['unit_price'] ?? 0, 2) . '</td>
                <td><strong>₹' . number_format($lineTotal, 2) . '</strong></td>
            </tr>';
}

// Calculate tax (18% GST)
$subtotal = $order['total_amount'] ?? $subtotal;
$taxRate = 0.18;
$cgst = $subtotal * ($taxRate / 2);
$sgst = $subtotal * ($taxRate / 2);
$grandTotal = $subtotal + $cgst + $sgst;

$html .= '
        </tbody>
    </table>

    <!-- Totals -->
    <div class="totals">
        <table>
            <tr><td><strong>Subtotal:</strong></td><td style="text-align: right;">₹' . number_format($subtotal, 2) . '</td></tr>
            <tr><td>CGST (9%):</td><td style="text-align: right;">₹' . number_format($cgst, 2) . '</td></tr>
            <tr><td>SGST (9%):</td><td style="text-align: right;">₹' . number_format($sgst, 2) . '</td></tr>
            <tr class="grand-total"><td><strong>Grand Total:</strong></td><td style="text-align: right;"><strong>₹' . number_format($grandTotal, 2) . '</strong></td></tr>
        </table>
    </div>

    <!-- Bank Details -->
    <div class="bank-details">
        <h3>Bank Details for Payment:</h3>
        <p><strong>Bank:</strong> [Your Bank Name] | <strong>A/C:</strong> [Account Number] | <strong>IFSC:</strong> [IFSC Code]</p>
        <p><strong>Branch:</strong> [Branch Address] | <strong>UPI:</strong> [Your UPI ID]</p>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>This is a computer-generated invoice. No signature required.</p>
        <p>© ' . date('Y') . ' AB Chem India. All rights reserved.</p>
    </div>
</body>
</html>';

// Generate PDF
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Output PDF
$filename = 'Invoice_' . ($order['order_number'] ?? 'ORD') . '_' . date('Ymd') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
?>