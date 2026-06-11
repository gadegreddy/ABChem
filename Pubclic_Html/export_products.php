<?php
/**
 * export_products.php — Export compounds + supplier listings to CSV.
 *
 * Query parameters:
 *   ?supplier_id=N   — export only listings from one supplier (0 = all)
 *   ?status=active   — export only Active listings (default)
 *                      pass ?status=all for every row regardless of status
 *
 * One CSV row per supplier_listing, so a compound with 3 suppliers produces
 * 3 rows.  Compound-level columns come first, then listing-level columns.
 */
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/u670463068/domains/abchem.co.in/error_log');
error_reporting(E_ALL);

require_once __DIR__ . '/../private/functions.php';

// ── Auth ──────────────────────────────────────────────────────────────────
enforceSessionTimeout(900);
if (!isset($_SESSION['role']) || !checkRole('Admin')) {
    header('Location: /signin');
    exit;
}

// Disable error output AFTER auth (prevents corrupting CSV stream)
error_reporting(0);
ob_start();   // buffer so headers can always be sent

$db = Database::getInstance();

// ── Params ────────────────────────────────────────────────────────────────
$supplierFilter = intval($_GET['supplier_id'] ?? 0);
$statusFilter   = strtolower(trim($_GET['status'] ?? 'active'));  // 'active' | 'all'

// ── Query ─────────────────────────────────────────────────────────────────
$params = [];
$where  = [];

if ($supplierFilter > 0) {
    $where[]        = 'sl.supplier_id = :sid';
    $params[':sid'] = $supplierFilter;
}
if ($statusFilter !== 'all') {
    $where[] = "sl.status = 'Active'";
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rows = $db->fetchAll(
    "SELECT
        /* supplier info */
        s.supplier_name,
        s.supplier_code,
        /* compound identifiers */
        c.id            AS compound_id,
        c.compound_name,
        c.cas_number,
        c.product_type,
        c.status        AS compound_status,
        /* chemical data */
        c.iupac_name,
        c.molecular_formula,
        c.molecular_weight,
        c.smiles,
        c.smiles_canonical,
        c.inchi,
        c.inchi_key,
        c.pubchem_cid,
        c.synonyms,
        c.image_url,
        /* classification */
        c.parent_drug,
        c.storage_condition,
        c.therapeutic_category,
        c.regulatory_ref,
        c.hazard_class,
        /* listing data */
        sl.id           AS listing_id,
        sl.catalog_number,
        sl.supplier_id,
        sl.purity,
        sl.purity_by_method,
        sl.availability,
        sl.stock_status,
        sl.min_order_qty,
        sl.unit,
        sl.quantity_available,
        sl.lead_time,
        sl.lot_number,
        sl.manufacture_date,
        sl.expiry_date,
        sl.supplier_notes,
        sl.status       AS listing_status
     FROM supplier_listings sl
     JOIN compounds c ON c.id = sl.compound_id
     JOIN suppliers s ON s.id = sl.supplier_id
     $whereSQL
     ORDER BY s.supplier_name, c.compound_name, sl.purity",
    $params
);

// ── Filename ──────────────────────────────────────────────────────────────
$supplierLabel = '';
if ($supplierFilter > 0) {
    $sup = $db->fetchOne("SELECT supplier_name FROM suppliers WHERE id = :id", [':id' => $supplierFilter]);
    if ($sup) {
        $supplierLabel = '_' . preg_replace('/[^a-z0-9]+/i', '_', $sup['supplier_name']);
    }
}
$safeDate     = date('Y-m-d');
$safeFilename = "compounds_export{$supplierLabel}_{$safeDate}.csv";

logAudit('export_products',
    "Admin exported compounds CSV ({$safeFilename}), " . count($rows) . " rows, supplier_id={$supplierFilter}"
);

// ── Stream CSV ────────────────────────────────────────────────────────────
ob_end_clean();   // discard any buffered output before sending headers

// Silence PHP 8.4 fputcsv() deprecation notices — they corrupt CSV downloads
error_reporting(0);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
header('X-Content-Type-Options: nosniff');
header('Pragma: no-cache');
header('Cache-Control: no-store, no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
// UTF-8 BOM for Excel
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

// 5th arg '\\' is the fputcsv escape char — required explicitly in PHP 8.4
if (empty($rows)) {
    fputcsv($out, ['compound_name', 'cas_number', 'supplier_name', 'catalog_number',
                   'purity', 'availability', 'listing_status'], ',', '"', '\\');
    fputcsv($out, ['(no matching records found)', '', '', '', '', '', ''], ',', '"', '\\');
    fclose($out);
    exit;
}

// Header row from first result's keys
fputcsv($out, array_keys($rows[0]), ',', '"', '\\');

foreach ($rows as $row) {
    $clean = [];
    foreach ($row as $val) {
        $val     = strval($val ?? '');
        $val     = strip_tags($val);
        $val     = str_replace(["\r\n", "\r", "\n"], ' | ', $val);
        $clean[] = $val;
    }
    fputcsv($out, $clean, ',', '"', '\\');
}

fclose($out);
exit;
