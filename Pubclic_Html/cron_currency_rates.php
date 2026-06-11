<?php
/**
 * Currency Rate Refresher
 *
 * Forces a live fetch of EUR/INR rates from frankfurter.app and writes the
 * result to private/exchange_rates.json.  Product pages then read this cache
 * and never hit the API directly on page load.
 *
 * Cron (daily at 08:00 IST = 02:30 UTC — after ECB morning update):
 *   30 2 * * * /usr/bin/php /home/u670463068/domains/abchem.co.in/public_html/cron_currency_rates.php >> /home/u670463068/cron_currency_rates.log 2>&1
 *
 * Manual: visit /cron_currency_rates.php while logged in as Admin.
 *
 * Source: https://api.frankfurter.app  (ECB data, free, no API key, ~300 req/day)
 */

require_once __DIR__ . '/../private/functions.php';
require_once __DIR__ . '/../private/currency_rates.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!isset($_SESSION['user_id']) || !checkRole('Admin')) {
        http_response_code(403);
        die("Forbidden.\n");
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Fetching live exchange rates from frankfurter.app...\n";
$rates = getCurrencyRates(forceRefresh: true);

echo "  Source:       {$rates['source']}\n";
echo "  Rate date:    " . ($rates['source_date'] ?: 'n/a') . "\n";
echo "  EUR → USD:    {$rates['EUR_TO_USD']}\n";
echo "  USD → INR:    {$rates['USD_TO_INR']}\n";
echo "  EUR → INR:    {$rates['EUR_TO_INR']}\n";
echo "  Cache file:   " . RATES_CACHE_FILE . "\n";

if ($rates['source'] === 'frankfurter.app') {
    echo "[" . date('Y-m-d H:i:s') . "] ✓ Rates updated successfully.\n";
} elseif ($rates['source'] === 'stale_cache') {
    echo "[" . date('Y-m-d H:i:s') . "] ⚠ Live fetch failed — serving stale cache (fetched {$rates['fetched_at']}).\n";
    exit(1);
} else {
    echo "[" . date('Y-m-d H:i:s') . "] ✗ Live fetch failed — hard fallback in use. Check server connectivity.\n";
    exit(1);
}
