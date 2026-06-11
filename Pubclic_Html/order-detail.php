<?php
/**
 * order-detail.php — View order, admin can edit seller details inline.
 * Seller details saved to company_settings, instantly reflected on invoice.
 */
require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/seller_settings.php';
require_once __DIR__ . '/../private/csrf.php';
enforceSessionTimeout(900);

if (!isset($_SESSION['user'])) {
    header('Location: /signin?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$db      = Database::getInstance();
$orderId = intval($_GET['id'] ?? 0);
$userId  = $_SESSION['user_id'];
$isAdmin = ($_SESSION['role'] ?? '') === 'Admin';

// ── Fetch order ──────────────────────────────────────────────────────────────
if ($isAdmin) {
    $order = $db->fetchOne(
        "SELECT o.*, u.email as user_email, u.company_name, u.contact_name,
                u.phone, u.user_type
         FROM orders o LEFT JOIN users u ON o.user_id = u.id
         WHERE o.id = :id",
        ['id' => $orderId]
    );
} else {
    $order = $db->fetchOne(
        "SELECT o.*, u.email as user_email, u.company_name, u.contact_name,
                u.phone, u.user_type
         FROM orders o LEFT JOIN users u ON o.user_id = u.id
         WHERE o.id = :id AND o.user_id = :uid",
        ['id' => $orderId, 'uid' => $userId]
    );
}

if (!$order) { http_response_code(404); die("Order not found or access denied."); }

$items = $db->fetchAll("SELECT * FROM order_items WHERE order_id = :oid", ['oid' => $orderId]);

// ── Buyer billing address ────────────────────────────────────────────────────
$buyerAddress = $db->fetchOne(
    "SELECT * FROM user_addresses
     WHERE user_id = :uid AND address_type IN ('billing','both') AND is_default = 1
     LIMIT 1",
    ['uid' => $order['user_id']]
);
if (!$buyerAddress) {
    $buyerAddress = $db->fetchOne(
        "SELECT * FROM user_addresses WHERE user_id = :uid LIMIT 1",
        ['uid' => $order['user_id']]
    );
}

// ── Handle Admin: save seller settings ──────────────────────────────────────
$settingsSaved = false;
$settingsError = '';
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_seller_settings'])) {
    if (!CSRF::verify($_POST['csrf_token'] ?? '')) {
        $settingsError = 'Invalid form token. Please refresh.';
    } else {
        $allowed = [
            'seller_name','seller_address1','seller_address2','seller_city',
            'seller_state','seller_pin','seller_country','seller_phone',
            'seller_email','seller_website','seller_gstin','seller_pan','seller_cin',
            'tax_cgst_pct','tax_sgst_pct','tax_igst_pct',
            'bank_name','bank_account','bank_ifsc','bank_branch','bank_upi',
            'invoice_prefix','invoice_footer','invoice_terms',
        ];
        $data = [];
        foreach ($allowed as $k) { if (isset($_POST[$k])) $data[$k] = $_POST[$k]; }
        if (saveSellerSettings($data)) {
            logAudit('seller_settings_updated', "Admin updated seller settings from order #{$orderId}");
            $settingsSaved = true;
        } else {
            $settingsError = 'Could not save — run the DB migration first.';
        }
    }
}

// ── Handle Admin: update order status / admin notes ──────────────────────────
$orderUpdated = false;
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order'])) {
    if (CSRF::verify($_POST['csrf_token'] ?? '')) {
        $upd = [
            'status'            => $_POST['status']         ?? $order['status'],
            'payment_status'    => $_POST['payment_status'] ?? $order['payment_status'],
            'tracking_number'   => trim($_POST['tracking_number'] ?? ''),
            'shipping_carrier'  => trim($_POST['shipping_carrier'] ?? ''),
            'admin_notes'       => trim($_POST['admin_notes'] ?? ''),
            'updated_at'        => date('Y-m-d H:i:s'),
        ];
        if ($_POST['total_amount'] !== '') {
            $upd['total_amount'] = (float)$_POST['total_amount'];
        }
        $db->update('orders', $upd, 'id = :id', ['id' => $orderId]);
        logAudit('order_updated', "Admin updated order #{$order['order_number']}");
        $order   = array_merge($order, $upd);
        $orderUpdated = true;
    }
}

$ss = getSellerSettings();

// Totals calculation using seller tax rates
$subtotal  = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items));
$displayTotal = $order['total_amount'] > 0 ? (float)$order['total_amount'] : $subtotal;
$cgstPct   = (float)($ss['tax_cgst_pct'] ?? 9);
$sgstPct   = (float)($ss['tax_sgst_pct'] ?? 9);
$cgst      = $displayTotal * ($cgstPct / 100);
$sgst      = $displayTotal * ($sgstPct / 100);
$grandTotal = $displayTotal + $cgst + $sgst;

function statusBadge(string $s): string {
    $map = ['pending'=>'#f59e0b','processing'=>'#3b82f6','shipped'=>'#06b6d4',
            'delivered'=>'#22c55e','cancelled'=>'#ef4444','completed'=>'#22c55e',
            'paid'=>'#22c55e','partial'=>'#f59e0b','refunded'=>'#8b5cf6'];
    $c = $map[$s] ?? '#64748b';
    return "<span class='status-pill' style='background:{$c}22;color:{$c};'>" . ucfirst($s) . "</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #<?= e($order['order_number']) ?> | AB Chem</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
</head>
<body>
<?php include 'header.php'; ?>

<div class="order-page">

    <!-- ══ Page Title ══════════════════════════════════════════════════════ -->
    <div class="order-page-header">
        <div>
            <h1 class="order-title">Order #<?= e($order['order_number']) ?></h1>
            <div class="order-badges">
                <?= statusBadge($order['status']) ?>
                <?= statusBadge($order['payment_status']) ?>
            </div>
        </div>
        <div class="order-actions-top">
            <a href="/invoice-print?id=<?= $order['id'] ?>" class="btn btn-primary" target="_blank">
                📄 Download Invoice PDF
            </a>
            <?php if ($isAdmin): ?>
            <a href="/admin?tab=orders" class="btn btn-outline">← Orders List</a>
            <?php else: ?>
            <a href="/dashboard?tab=orders" class="btn btn-outline">← My Orders</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($settingsSaved): ?>
    <div class="alert-success mb-2">✅ Seller settings saved — invoices will use the updated details.</div>
    <?php endif; ?>
    <?php if ($settingsError): ?>
    <div class="alert-error mb-2"><?= e($settingsError) ?></div>
    <?php endif; ?>
    <?php if ($orderUpdated): ?>
    <div class="alert-success mb-2">✅ Order updated successfully.</div>
    <?php endif; ?>

    <!-- ══ Two-column layout ═══════════════════════════════════════════════ -->
    <div class="order-cols">

        <!-- ── LEFT: Order main content ─────────────────────────────────── -->
        <div class="order-col-main">

            <!-- Order Meta -->
            <div class="order-card">
                <div class="order-meta-grid">
                    <div class="order-meta-item">
                        <span class="meta-label">Order Date</span>
                        <span class="meta-value"><?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></span>
                    </div>
                    <div class="order-meta-item">
                        <span class="meta-label">Order #</span>
                        <span class="meta-value fw-600"><?= e($order['order_number']) ?></span>
                    </div>
                    <?php if ($order['purchase_order']): ?>
                    <div class="order-meta-item">
                        <span class="meta-label">PO Number</span>
                        <span class="meta-value"><?= e($order['purchase_order']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($order['tracking_number']): ?>
                    <div class="order-meta-item">
                        <span class="meta-label">Tracking</span>
                        <span class="meta-value"><?= e($order['tracking_number']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($order['shipping_carrier']): ?>
                    <div class="order-meta-item">
                        <span class="meta-label">Carrier</span>
                        <span class="meta-value"><?= e($order['shipping_carrier']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($order['estimated_delivery']): ?>
                    <div class="order-meta-item">
                        <span class="meta-label">Est. Delivery</span>
                        <span class="meta-value"><?= date('d M Y', strtotime($order['estimated_delivery'])) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($order['notes']): ?>
                <div class="order-notes-box order-notes-customer">
                    <strong>Customer Notes:</strong><br><?= nl2br(e($order['notes'])) ?>
                </div>
                <?php endif; ?>

                <?php if ($order['admin_notes']): ?>
                <div class="order-notes-box order-notes-admin">
                    <strong>Admin Notes:</strong><br><?= nl2br(e($order['admin_notes'])) ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Bill-to Info -->
            <div class="order-card order-parties-grid">
                <div>
                    <h3 class="card-section-title">🏢 Bill To (Buyer)</h3>
                    <p class="fw-600"><?= e($order['company_name'] ?? $order['contact_name'] ?? 'Customer') ?></p>
                    <?php if ($order['contact_name']): ?><p><?= e($order['contact_name']) ?></p><?php endif; ?>
                    <p><?= e($order['user_email'] ?? '—') ?></p>
                    <?php if ($order['phone']): ?><p><?= e($order['phone']) ?></p><?php endif; ?>
                    <?php if ($buyerAddress): ?>
                    <p class="text-muted-sm mt-1">
                        <?= e($buyerAddress['address_line1']) ?><?= $buyerAddress['address_line2'] ? ', ' . e($buyerAddress['address_line2']) : '' ?><br>
                        <?= e($buyerAddress['city']) ?>, <?= e($buyerAddress['state']) ?> – <?= e($buyerAddress['postal_code']) ?><br>
                        <?= e($buyerAddress['country']) ?>
                        <?php if ($buyerAddress['gstin']): ?>
                        <br><span class="text-xs">GSTIN: <?= e($buyerAddress['gstin']) ?></span>
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>
                </div>
                <div>
                    <h3 class="card-section-title">🏭 Seller (Your Company)</h3>
                    <p class="fw-600"><?= e($ss['seller_name']) ?></p>
                    <p class="text-muted-sm"><?= e($ss['seller_address1']) ?><?= $ss['seller_address2'] ? ', ' . e($ss['seller_address2']) : '' ?></p>
                    <p class="text-muted-sm"><?= e($ss['seller_city']) ?>, <?= e($ss['seller_state']) ?> – <?= e($ss['seller_pin']) ?></p>
                    <p class="text-muted-sm"><?= e($ss['seller_phone']) ?></p>
                    <?php if ($ss['seller_gstin']): ?>
                    <p class="text-xs">GSTIN: <strong><?= e($ss['seller_gstin']) ?></strong></p>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                    <a href="#seller-edit-panel" class="link-accent text-xs mt-1 d-inline">✏️ Edit seller details ↓</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Order Items -->
            <div class="order-card">
                <h3 class="card-section-title">📦 Order Items</h3>
                <div class="table-scroll">
                    <table class="order-items-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>CAS</th>
                                <th>Qty</th>
                                <th>Unit</th>
                                <th>Rate (₹)</th>
                                <th>Amount (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $i => $item):
                                $lineTotal = (float)$item['quantity'] * (float)$item['unit_price'];
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= e($item['product_name']) ?></strong></td>
                                <td class="text-muted-sm"><?= e($item['cas_number'] ?? '—') ?></td>
                                <td><?= number_format((float)$item['quantity'], 3) ?></td>
                                <td><?= e($item['unit'] ?? 'mg') ?></td>
                                <td>₹<?= number_format((float)$item['unit_price'], 2) ?></td>
                                <td><strong>₹<?= number_format($lineTotal, 2) ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Totals -->
                <div class="order-totals-wrap">
                    <table class="order-totals-table">
                        <tr>
                            <td>Subtotal:</td>
                            <td>₹<?= number_format($displayTotal, 2) ?></td>
                        </tr>
                        <tr>
                            <td>CGST (<?= $cgstPct ?>%):</td>
                            <td>₹<?= number_format($cgst, 2) ?></td>
                        </tr>
                        <tr>
                            <td>SGST (<?= $sgstPct ?>%):</td>
                            <td>₹<?= number_format($sgst, 2) ?></td>
                        </tr>
                        <tr class="totals-grand">
                            <td><strong>Grand Total:</strong></td>
                            <td><strong>₹<?= number_format($grandTotal, 2) ?></strong></td>
                        </tr>
                    </table>
                </div>
            </div><!-- /items card -->

        </div><!-- /col-main -->

        <!-- ── RIGHT: Admin panels ───────────────────────────────────────── -->
        <?php if ($isAdmin): ?>
        <div class="order-col-side">

            <!-- Update Order -->
            <div class="order-card">
                <h3 class="card-section-title">⚙️ Update Order</h3>
                <form method="post" action="/order-detail?id=<?= $orderId ?>">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="update_order" value="1">
                    <div class="form-group">
                        <label class="filter-label">Order Status</label>
                        <select name="status" class="filter-input w-full">
                            <?php foreach (['pending','processing','shipped','delivered','completed','cancelled'] as $s): ?>
                            <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="filter-label">Payment Status</label>
                        <select name="payment_status" class="filter-input w-full">
                            <?php foreach (['pending','paid','partial','refunded'] as $s): ?>
                            <option value="<?= $s ?>" <?= $order['payment_status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="filter-label">Total Amount (₹)</label>
                        <input type="number" name="total_amount" step="0.01" min="0"
                               value="<?= e($order['total_amount']) ?>"
                               class="filter-input w-full" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="filter-label">Tracking Number</label>
                        <input type="text" name="tracking_number"
                               value="<?= e($order['tracking_number'] ?? '') ?>"
                               class="filter-input w-full" placeholder="Tracking / AWB">
                    </div>
                    <div class="form-group">
                        <label class="filter-label">Carrier</label>
                        <input type="text" name="shipping_carrier"
                               value="<?= e($order['shipping_carrier'] ?? 'India Post') ?>"
                               class="filter-input w-full" placeholder="India Post / DHL...">
                    </div>
                    <div class="form-group">
                        <label class="filter-label">Admin Notes</label>
                        <textarea name="admin_notes" rows="3" class="filter-input w-full"
                                  placeholder="Internal notes (not shown on invoice)"><?= e($order['admin_notes'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-full">Save Order Changes</button>
                </form>
            </div>

            <!-- ── Seller / Invoice Settings ─────────────────────────────── -->
            <div class="order-card" id="seller-edit-panel">
                <h3 class="card-section-title">🏭 Seller Details <span class="text-xs text-muted fw-400">(used on all invoices)</span></h3>

                <form method="post" action="/order-detail?id=<?= $orderId ?>">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="save_seller_settings" value="1">

                    <div class="form-group">
                        <label class="filter-label">Company Name *</label>
                        <input type="text" name="seller_name" value="<?= e($ss['seller_name']) ?>"
                               class="filter-input w-full" required>
                    </div>
                    <div class="form-group">
                        <label class="filter-label">Address Line 1</label>
                        <input type="text" name="seller_address1" value="<?= e($ss['seller_address1']) ?>"
                               class="filter-input w-full" placeholder="Street / Plot No.">
                    </div>
                    <div class="form-group">
                        <label class="filter-label">Address Line 2</label>
                        <input type="text" name="seller_address2" value="<?= e($ss['seller_address2']) ?>"
                               class="filter-input w-full" placeholder="Area / Locality">
                    </div>
                    <div class="seller-2col">
                        <div class="form-group">
                            <label class="filter-label">City</label>
                            <input type="text" name="seller_city" value="<?= e($ss['seller_city']) ?>"
                                   class="filter-input w-full">
                        </div>
                        <div class="form-group">
                            <label class="filter-label">State</label>
                            <input type="text" name="seller_state" value="<?= e($ss['seller_state']) ?>"
                                   class="filter-input w-full">
                        </div>
                    </div>
                    <div class="seller-2col">
                        <div class="form-group">
                            <label class="filter-label">PIN</label>
                            <input type="text" name="seller_pin" value="<?= e($ss['seller_pin']) ?>"
                                   class="filter-input w-full" maxlength="10">
                        </div>
                        <div class="form-group">
                            <label class="filter-label">Country</label>
                            <input type="text" name="seller_country" value="<?= e($ss['seller_country']) ?>"
                                   class="filter-input w-full">
                        </div>
                    </div>
                    <div class="seller-2col">
                        <div class="form-group">
                            <label class="filter-label">Phone</label>
                            <input type="text" name="seller_phone" value="<?= e($ss['seller_phone']) ?>"
                                   class="filter-input w-full">
                        </div>
                        <div class="form-group">
                            <label class="filter-label">Email</label>
                            <input type="email" name="seller_email" value="<?= e($ss['seller_email']) ?>"
                                   class="filter-input w-full">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="filter-label">GSTIN</label>
                        <input type="text" name="seller_gstin" value="<?= e($ss['seller_gstin']) ?>"
                               class="filter-input w-full font-mono" maxlength="15">
                    </div>
                    <div class="seller-2col">
                        <div class="form-group">
                            <label class="filter-label">PAN</label>
                            <input type="text" name="seller_pan" value="<?= e($ss['seller_pan']) ?>"
                                   class="filter-input w-full font-mono" maxlength="10">
                        </div>
                        <div class="form-group">
                            <label class="filter-label">Website</label>
                            <input type="text" name="seller_website" value="<?= e($ss['seller_website']) ?>"
                                   class="filter-input w-full">
                        </div>
                    </div>

                    <details class="bank-collapsible">
                        <summary class="bank-collapsible-title">🏦 Bank Details (for invoice)</summary>
                        <div class="form-group mt-2">
                            <label class="filter-label">Bank Name</label>
                            <input type="text" name="bank_name" value="<?= e($ss['bank_name']) ?>"
                                   class="filter-input w-full">
                        </div>
                        <div class="seller-2col">
                            <div class="form-group">
                                <label class="filter-label">A/C Number</label>
                                <input type="text" name="bank_account" value="<?= e($ss['bank_account']) ?>"
                                       class="filter-input w-full font-mono">
                            </div>
                            <div class="form-group">
                                <label class="filter-label">IFSC</label>
                                <input type="text" name="bank_ifsc" value="<?= e($ss['bank_ifsc']) ?>"
                                       class="filter-input w-full font-mono" maxlength="11">
                            </div>
                        </div>
                        <div class="seller-2col">
                            <div class="form-group">
                                <label class="filter-label">Branch</label>
                                <input type="text" name="bank_branch" value="<?= e($ss['bank_branch']) ?>"
                                       class="filter-input w-full">
                            </div>
                            <div class="form-group">
                                <label class="filter-label">UPI ID</label>
                                <input type="text" name="bank_upi" value="<?= e($ss['bank_upi']) ?>"
                                       class="filter-input w-full">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="filter-label">Payment Terms</label>
                            <input type="text" name="invoice_terms" value="<?= e($ss['invoice_terms']) ?>"
                                   class="filter-input w-full">
                        </div>
                        <div class="form-group">
                            <label class="filter-label">Invoice Footer Note</label>
                            <textarea name="invoice_footer" rows="2" class="filter-input w-full"><?= e($ss['invoice_footer']) ?></textarea>
                        </div>

                        <div class="seller-3col mt-1">
                            <div class="form-group">
                                <label class="filter-label">CGST %</label>
                                <input type="number" name="tax_cgst_pct" value="<?= e($ss['tax_cgst_pct']) ?>"
                                       class="filter-input w-full" min="0" max="50" step="0.5">
                            </div>
                            <div class="form-group">
                                <label class="filter-label">SGST %</label>
                                <input type="number" name="tax_sgst_pct" value="<?= e($ss['tax_sgst_pct']) ?>"
                                       class="filter-input w-full" min="0" max="50" step="0.5">
                            </div>
                            <div class="form-group">
                                <label class="filter-label">IGST %</label>
                                <input type="number" name="tax_igst_pct" value="<?= e($ss['tax_igst_pct']) ?>"
                                       class="filter-input w-full" min="0" max="50" step="0.5">
                            </div>
                        </div>
                    </details>

                    <button type="submit" class="btn btn-primary w-full mt-2">
                        💾 Save Seller Details
                    </button>
                    <p class="text-xs text-muted mt-1 text-center">Updates all future invoices globally</p>
                </form>
            </div>

        </div><!-- /col-side -->
        <?php endif; ?>

    </div><!-- /order-cols -->
</div><!-- /order-page -->

<?php include 'footer.php'; ?>
</body>
</html>