<?php
/**
 * USP Reference Standard Catalog Sync — standalone.
 *
 * Fetches the open-access USP CSV and replaces all USP rows in
 * pharmacopeia_sync_catalog.  EP is handled separately by cron_ep_sync.php.
 *
 * Cron (monthly, 03:00 on the 1st):
 *   0 3 1 * * /usr/bin/php /home/u670463068/domains/abchem.co.in/public_html/cron_usp_sync.php >> /home/u670463068/cron_usp_sync.log 2>&1
 *
 * Manual: visit /cron_usp_sync.php while logged in as Admin.
 *
 * Source: https://static.usp.org/doc/referenceStandards/usprefstd.csv  (USD, open access, CDN hosted)
 * Columns used: Catalog #, Status (Active only), Product Name, CAS#, Unit Price,
 *               Net Weight + Unit Of Measure (merged into quantity).
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '256M');
set_time_limit(300);

require_once __DIR__ . '/../private/functions.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!isset($_SESSION['user_id']) || !checkRole('Admin')) {
        http_response_code(403);
        die("Forbidden.\n");
    }
}

try {
    $db  = Database::getInstance();
    $pdo = $db->getPdo();
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage() . "\n");
}

// ── Ensure table exists ───────────────────────────────────────────────────────
$pdo->exec("
CREATE TABLE IF NOT EXISTS `pharmacopeia_sync_catalog` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `standard` varchar(10) NOT NULL,
  `catalog_number` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `cas_number` varchar(50) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `currency` varchar(10) DEFAULT NULL,
  `quantity` varchar(50) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cas_number` (`cas_number`),
  KEY `idx_standard` (`standard`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

/** Money string → float|null. Handles "$1,300.00" style. */
function parseMoneyUsd(string $raw): ?float {
    $clean = preg_replace('/[^0-9.]/', '', $raw);
    return ($clean !== '' && is_numeric($clean)) ? (float)$clean : null;
}

/** Replace all rows for one standard atomically; no-op if $rows is empty. */
function replaceStandard(PDO $pdo, string $standard, array $rows, string $updatedAt): int {
    if (empty($rows)) return 0;
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM pharmacopeia_sync_catalog WHERE standard = ?")->execute([$standard]);
        $ins = $pdo->prepare("INSERT INTO pharmacopeia_sync_catalog
            (standard, catalog_number, name, cas_number, price, currency, quantity, url, status, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $n = 0;
        foreach ($rows as $r) {
            $ins->execute([$standard, $r['catalog_number'], $r['name'], $r['cas'],
                $r['price'], $r['currency'], $r['quantity'], $r['url'], $r['status'], $updatedAt]);
            $n++;
        }
        $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "  ! DB write failed: " . $e->getMessage() . "\n";
        error_log("[cron_usp_sync] DB write failed: " . $e->getMessage());
        return 0;
    }
}

$updatedAt = date('Y-m-d H:i:s');
echo "[" . date('Y-m-d H:i:s') . "] Syncing USP...\n";

// Fetch CSV via cURL (browser UA to avoid UA-based blocks; USP is a static CDN so no session needed)
$ua  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36';
$ch  = curl_init("https://static.usp.org/doc/referenceStandards/usprefstd.csv");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 30,   CURLOPT_TIMEOUT        => 120,
    CURLOPT_ENCODING       => '',   CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false, CURLOPT_USERAGENT     => $ua,
]);
$csvData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
unset($ch);

echo "  fetch: HTTP {$httpCode}, " . strlen((string)$csvData) . " bytes"
   . ($curlErr ? " (cURL error: {$curlErr})" : "") . "\n";

if (!$csvData || $httpCode !== 200) {
    die("  Failed to fetch USP CSV — kept previous data.\n");
}

// Parse via real CSV reader (escape:"" = RFC-4180, silences PHP 8.4 deprecation)
$rows = [];
$fh   = fopen('php://temp', 'r+');
fwrite($fh, $csvData);
rewind($fh);
$headers = fgetcsv($fh, escape: "");
if ($headers) {
    $headers = array_map('trim', $headers);
    while (($row = fgetcsv($fh, escape: "")) !== false) {
        if (count($row) === 1 && trim((string)($row[0] ?? '')) === '') continue;
        $d = [];
        foreach ($headers as $i => $col) { $d[$col] = isset($row[$i]) ? trim((string)$row[$i]) : ''; }

        $cat    = $d['Catalog #'] ?? '';
        $status = $d['Status']    ?? 'Active';
        if ($cat === '' || $status !== 'Active') continue;

        $cas = $d['CAS#'] ?? '';
        if ($cas === '' || strtoupper($cas) === 'N/A') $cas = null;

        $qty = trim(($d['Net Weight'] ?? '') . ' ' . ($d['Unit Of Measure'] ?? ''));
        $rows[] = [
            'catalog_number' => $cat,
            'name'           => $d['Product Name'] ?? '',
            'cas'            => $cas,
            'price'          => parseMoneyUsd($d['Unit Price'] ?? ''),
            'currency'       => 'USD',
            'quantity'       => $qty !== '' ? $qty : null,
            'url'            => "https://store.usp.org/product/" . urlencode($cat),
            'status'         => 'Active',
        ];
    }
}
fclose($fh);

$n = replaceStandard($pdo, 'USP', $rows, $updatedAt);
echo "[" . date('Y-m-d H:i:s') . "] USP sync complete: {$n} active items.\n";
