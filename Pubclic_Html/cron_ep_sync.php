<?php
/**
 * EP Reference Standard Catalog Sync — standalone, file-based.
 *
 * EDQM's server returns HTTP 504 for automated/datacenter requests, so we
 * read from a locally-uploaded snapshot instead of a live fetch.
 *
 * HOW TO UPDATE:
 *   1. Open https://crs.edqm.eu/db/4DCGI/web_catalog_XML.xml in a browser.
 *   2. Save Page As → save to your PC.
 *   3. Upload the file to:  private/ep_catalog.xml
 *   4. Run this script via cron or visit /cron_ep_sync.php as Admin.
 *
 * Cron (monthly, 03:30 on the 1st — run after manually uploading ep_catalog.xml):
 *   30 3 1 * * /usr/bin/php /home/u670463068/domains/abchem.co.in/public_html/cron_ep_sync.php >> /home/u670463068/cron_ep_sync.log 2>&1
 *
 * XML structure: <Catalogue> → <Reference Order_Code="Y0000309">
 *   <Reference_Standard>, <CAS_Registry_Number>, <Quantity_per_vial>, <Price>
 * ~3263 rows, ~3089 with CAS.  Price format: "90€".
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
        error_log("[cron_ep_sync] DB write failed: " . $e->getMessage());
        return 0;
    }
}

$updatedAt = date('Y-m-d H:i:s');
$epFile    = __DIR__ . '/../private/ep_catalog.xml';

echo "[" . date('Y-m-d H:i:s') . "] Syncing EP from local snapshot...\n";

// ── Load the snapshot ─────────────────────────────────────────────────────────
if (!is_file($epFile)) {
    die("  Error: snapshot not found at {$epFile}\n"
      . "  Download https://crs.edqm.eu/db/4DCGI/web_catalog_XML.xml in a browser\n"
      . "  and upload it to private/ep_catalog.xml, then re-run.\n");
}

$xmlRaw  = file_get_contents($epFile);
$ageDays = floor((time() - filemtime($epFile)) / 86400);
echo "  file: " . strlen($xmlRaw) . " bytes, {$ageDays} days old"
   . ($ageDays > 40 ? " ⚠️ snapshot is over 40 days old — consider refreshing" : "") . "\n";

if (strpos($xmlRaw, '<Reference ') === false) {
    die("  Error: file has no <Reference> elements — not a valid EDQM XML catalog.\n");
}

// ── Parse ─────────────────────────────────────────────────────────────────────
libxml_use_internal_errors(true);
$xml = simplexml_load_string($xmlRaw);
if (!$xml || !isset($xml->Reference)) {
    echo "  XML parse failed:\n";
    foreach (libxml_get_errors() as $err) echo "\t" . trim($err->message) . "\n";
    libxml_clear_errors();
    die("  Kept previous EP data.\n");
}

$rows = [];
foreach ($xml->Reference as $ref) {
    $catalogNumber = trim((string)$ref['Order_Code']);
    if ($catalogNumber === '') continue;

    $cas = trim((string)$ref->CAS_Registry_Number);
    if ($cas === '') $cas = null;

    // Price: "90€" / "300€" — extract the integer part
    preg_match('/(\d+(?:[.,]\d+)?)/', (string)$ref->Price, $m);
    $price = isset($m[1]) ? (float)str_replace(',', '.', $m[1]) : null;

    $qty = trim((string)$ref->Quantity_per_vial);

    $rows[] = [
        'catalog_number' => $catalogNumber,
        'name'           => trim((string)$ref->Reference_Standard),
        'cas'            => $cas,
        'price'          => $price,
        'currency'       => 'EUR',
        'quantity'       => $qty !== '' ? $qty : null,
        'url'            => "https://crs.edqm.eu/db/4DCGI/View=" . urlencode($catalogNumber),
        'status'         => 'Active',
    ];
}
libxml_clear_errors();

$n = replaceStandard($pdo, 'EP', $rows, $updatedAt);
echo "[" . date('Y-m-d H:i:s') . "] EP sync complete: {$n} items ingested from snapshot.\n";
