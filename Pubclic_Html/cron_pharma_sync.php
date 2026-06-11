<?php
/**
 * FEAT-Pharmacopeia Sync: Automated USP/EP reference-standard catalog ingestion.
 *
 * Cron (monthly, 03:00 on the 1st):
 *   0 3 1 * * /usr/bin/php /home/u670463068/domains/abchem.co.in/public_html/cron_pharma_sync.php >> /home/u670463068/cron_pharma_sync.log 2>&1
 *
 * Manual: visit /cron_pharma_sync.php while logged in as Admin. Random visitors
 * are blocked (this does heavy downloads + table writes).
 *
 * Sources (open access):
 *   USP → https://static.usp.org/doc/referenceStandards/usprefstd.csv   (USD)  — fetches fine.
 *   EP  → https://crs.edqm.eu/db/4DCGI/web_catalog_XML.xml              (EUR)  — EDQM 504s/blocks
 *         datacenter IPs, so the cron falls back to an admin-uploaded snapshot at
 *         private/ep_catalog.xml (download the XML in a browser, upload it there).
 *
 * Robustness: each standard is replaced in its OWN transaction and ONLY when its
 * download/parse yields rows — a failed EP fetch leaves last run's EP data intact.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
set_time_limit(600);

require_once __DIR__ . '/../private/functions.php';

// ── Access guard: CLI (cron) OR an authenticated Admin via browser ─────────────
$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!isset($_SESSION['user_id']) || !checkRole('Admin')) {
        http_response_code(403);
        die("Forbidden: runs from CLI (cron) or an admin browser session only.\n");
    }
}

try {
    $db  = Database::getInstance();
    $pdo = $db->getPdo();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

// ── 1. Ensure table exists ────────────────────────────────────────────────────
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

$updatedAt = date('Y-m-d H:i:s');
$stats = ['USP' => 0, 'EP' => 0];

// ── Helpers ───────────────────────────────────────────────────────────────────
/**
 * Fetch a URL via cURL with a browser-like profile.
 *
 * @param string      $url      The resource to download.
 * @param string|null $primeUrl If set, GET this first on the SAME handle so its
 *                              Set-Cookie (e.g. EDQM's 4D session) is replayed on
 *                              the real request.
 * @param string|null $diag     Out-param: HTTP code / byte count or error string.
 */
function getHttpContent(string $url, ?string $primeUrl = null, ?string &$diag = null): string|false {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_ENCODING       => '',     // accept gzip/deflate
            CURLOPT_SSL_VERIFYPEER => false,  // shared-host CA bundles are often stale
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_COOKIEFILE     => '',     // enable the in-memory cookie engine
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/xml,text/xml,text/csv,*/*',
                'Accept-Language: en-US,en;q=0.9',
            ],
        ]);

        if ($primeUrl) {
            curl_setopt($ch, CURLOPT_URL, $primeUrl);
            curl_exec($ch); // body ignored — we only want the Set-Cookie
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        unset($ch); // curl_close is a no-op / deprecated in modern PHP

        if ($body === false || $body === '') {
            $diag = "curl failed (HTTP {$code}): " . ($err !== '' ? $err : 'empty body');
            return false;
        }
        $diag = "HTTP {$code}, " . strlen($body) . " bytes";
        return $body;
    }

    // Fallback (no cURL): plain stream.
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'header' => "User-Agent: {$ua}\r\n", 'timeout' => 120, 'follow_location' => 1],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $diag = $body === false ? 'file_get_contents failed' : ('file_get_contents, ' . strlen($body) . ' bytes');
    return $body;
}

/** Money string → float|null. Strips currency symbols & thousands separators (US format). */
function parseMoneyUsd(string $raw): ?float {
    $clean = preg_replace('/[^0-9.]/', '', $raw); // "$1,300.00" → "1300.00"
    return ($clean !== '' && is_numeric($clean)) ? (float)$clean : null;
}

/**
 * Replace all rows for one standard in a single transaction — but only if $rows
 * is non-empty. A failed download (empty $rows) is a no-op, preserving last data.
 */
function replaceStandard(PDO $pdo, string $standard, array $rows, string $updatedAt): int {
    if (empty($rows)) return 0;
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM pharmacopeia_sync_catalog WHERE standard = ?")
            ->execute([$standard]);

        $ins = $pdo->prepare("INSERT INTO pharmacopeia_sync_catalog
            (standard, catalog_number, name, cas_number, price, currency, quantity, url, status, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $n = 0;
        foreach ($rows as $r) {
            $ins->execute([
                $standard, $r['catalog_number'], $r['name'], $r['cas'], $r['price'],
                $r['currency'], $r['quantity'], $r['url'], $r['status'], $updatedAt,
            ]);
            $n++;
        }
        $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "  ! {$standard} DB write failed — kept previous data: " . $e->getMessage() . "\n";
        error_log("[cron_pharma_sync] {$standard} write failed: " . $e->getMessage());
        return 0;
    }
}

// ── Sync USP (CSV) ────────────────────────────────────────────────────────────
echo "Syncing USP...\n";
$uspDiag = null;
$uspCsv = getHttpContent("https://static.usp.org/doc/referenceStandards/usprefstd.csv", null, $uspDiag);
echo "  fetch: {$uspDiag}\n";
if ($uspCsv !== false && $uspCsv !== '') {
    $rows = [];
    // Real CSV reader so quoted fields with commas/newlines don't desync rows.
    // escape: "" → RFC-4180 mode + silences the PHP 8.4 fgetcsv $escape deprecation.
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $uspCsv);
    rewind($fh);

    $headers = fgetcsv($fh, escape: "");
    if ($headers) {
        $headers = array_map('trim', $headers);
        while (($row = fgetcsv($fh, escape: "")) !== false) {
            if (count($row) === 1 && trim((string)($row[0] ?? '')) === '') continue; // blank line

            $data = [];
            foreach ($headers as $i => $col) {
                $data[$col] = isset($row[$i]) ? trim((string)$row[$i]) : '';
            }

            $catalogNumber = $data['Catalog #'] ?? '';
            $status        = $data['Status'] ?? 'Active';
            if ($catalogNumber === '' || $status !== 'Active') continue; // active items only

            $cas = $data['CAS#'] ?? '';
            if ($cas === '' || strtoupper($cas) === 'N/A') $cas = null;

            // Merge Net Weight + Unit Of Measure into one quantity string (e.g. "200 mg")
            $quantity = trim(($data['Net Weight'] ?? '') . ' ' . ($data['Unit Of Measure'] ?? ''));

            $rows[] = [
                'catalog_number' => $catalogNumber,
                'name'           => $data['Product Name'] ?? '',
                'cas'            => $cas,
                'price'          => parseMoneyUsd($data['Unit Price'] ?? ''),
                'currency'       => 'USD',
                'quantity'       => $quantity !== '' ? $quantity : null,
                'url'            => "https://store.usp.org/product/" . urlencode($catalogNumber),
                'status'         => 'Active',
            ];
        }
    }
    fclose($fh);

    $stats['USP'] = replaceStandard($pdo, 'USP', $rows, $updatedAt);
    echo "USP sync complete: {$stats['USP']} active items.\n";
} else {
    echo "Failed to fetch USP CSV — kept previous USP data.\n";
}

// ── Sync EP (XML) ─────────────────────────────────────────────────────────────
echo "Syncing EP...\n";
// EDQM's 4D server returns HTTP 504 / an HTML shell to datacenter requests, so
// each attempt primes the session at the site root, then fetches the XML on the
// same handle, with retries.
$epXmlStr = false;
for ($attempt = 1; $attempt <= 3; $attempt++) {
    $epDiag = null;
    $body = getHttpContent("https://crs.edqm.eu/db/4DCGI/web_catalog_XML.xml", "https://crs.edqm.eu/", $epDiag);
    if ($body !== false && strpos($body, '<Reference ') !== false) {
        echo "  fetch attempt {$attempt}: {$epDiag} — OK\n";
        $epXmlStr = $body;
        break;
    }
    echo "  fetch attempt {$attempt}: {$epDiag} — no <Reference> (504 / HTML shell / cold server)"
       . ($attempt < 3 ? ", retrying in 5s...\n" : " — live fetch failed.\n");
    if ($attempt < 3) sleep(5);
}

// Fallback: EDQM frequently 504s / blocks datacenter IPs, so the live fetch may
// never succeed from this host even though a browser download works. If so,
// ingest an admin-provided snapshot: download the XML in a browser from the EDQM
// URL and upload it to private/ep_catalog.xml.
if ($epXmlStr === false) {
    $epFile = __DIR__ . '/../private/ep_catalog.xml';
    if (is_file($epFile)) {
        $body = file_get_contents($epFile);
        if ($body !== false && strpos($body, '<Reference ') !== false) {
            $epXmlStr = $body;
            $ageDays  = floor((time() - filemtime($epFile)) / 86400);
            echo "  using local fallback {$epFile} (" . strlen($body) . " bytes, {$ageDays} days old)\n";
        } else {
            echo "  local fallback {$epFile} present but has no <Reference> — ignored.\n";
        }
    } else {
        echo "  no local fallback at {$epFile} — keeping previous EP data.\n";
    }
}

if ($epXmlStr !== false && $epXmlStr !== '') {
    // Clean invalid UTF-8 characters that cause simplexml_load_string to fail on some servers
    $epXmlStr = mb_convert_encoding($epXmlStr, 'UTF-8', 'UTF-8');
    $epXmlStr = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/', '', $epXmlStr);

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($epXmlStr);

    if ($xml && isset($xml->Reference)) {
        $rows = [];
        foreach ($xml->Reference as $ref) {
            $catalogNumber = trim((string)$ref['Order_Code']);   // attribute
            if ($catalogNumber === '') continue;

            $cas = trim((string)$ref->CAS_Registry_Number);
            if ($cas === '') $cas = null;

            // Price like "90€" / "1.234,50€" → grab number, normalise comma-decimal.
            preg_match('/(\d+(?:[.,]\d+)?)/', (string)$ref->Price, $m);
            $price = isset($m[1]) ? (float)str_replace(',', '.', $m[1]) : null;

            $quantity = trim((string)$ref->Quantity_per_vial);

            $rows[] = [
                'catalog_number' => $catalogNumber,
                'name'           => trim((string)$ref->Reference_Standard),
                'cas'            => $cas,
                'price'          => $price,
                'currency'       => 'EUR',
                'quantity'       => $quantity !== '' ? $quantity : null,
                'url'            => "https://crs.edqm.eu/db/4DCGI/View=" . urlencode($catalogNumber),
                'status'         => 'Active',
            ];
        }

        $stats['EP'] = replaceStandard($pdo, 'EP', $rows, $updatedAt);
        echo "EP sync complete: {$stats['EP']} items.\n";
    } else {
        echo "Failed to parse EP XML — kept previous EP data.\n";
        foreach (libxml_get_errors() as $error) echo "\t" . trim($error->message) . "\n";
        libxml_clear_errors();
    }
} else {
    echo "EP not updated (no live data and no usable fallback) — kept previous EP data.\n";
}

echo "Sync finished. USP: {$stats['USP']}, EP: {$stats['EP']}\n";
