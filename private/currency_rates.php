<?php
/**
 * Live exchange rate helper — ECB/frankfurter.app, no API key required.
 *
 * Caches to private/exchange_rates.json; refreshes automatically when the
 * cache is older than 23 hours (so each calendar day is covered with one fetch).
 * On fetch failure the stale cache is returned; on first-run failure a sensible
 * fallback is used so product pages never 500.
 *
 * Usage:
 *   require_once __DIR__ . '/currency_rates.php';
 *   $r = getCurrencyRates();
 *   // $r['EUR_TO_USD'] — e.g. 1.0823  (how many USD per 1 EUR)
 *   // $r['USD_TO_INR'] — e.g. 83.52   (how many INR per 1 USD)
 *   // $r['source_date'] — ECB rate date, e.g. "2026-05-29"
 *   // $r['source']      — "frankfurter.app" | "cache" | "stale_cache" | "fallback"
 */

define('RATES_CACHE_FILE', __DIR__ . '/exchange_rates.json');
define('RATES_TTL_SECONDS', 23 * 3600); // one fetch per calendar day

// Hard fallback — only used if the very first live fetch ever fails.
// Update these manually if the values drift badly before a live fetch works.
define('RATES_FALLBACK_EUR_TO_USD', 1.08);
define('RATES_FALLBACK_USD_TO_INR', 84.00);

/**
 * Return current EUR→USD and USD→INR rates, refreshing the cache when stale.
 *
 * @param bool $forceRefresh Ignore cache TTL and fetch immediately (used by cron).
 */
function getCurrencyRates(bool $forceRefresh = false): array {
    // ── Load cache ────────────────────────────────────────────────────────────
    $cached = null;
    if (is_file(RATES_CACHE_FILE)) {
        $raw    = @file_get_contents(RATES_CACHE_FILE);
        $cached = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    }

    // Return cache if it is still fresh
    if (!$forceRefresh && $cached && !empty($cached['fetched_at'])) {
        $age = time() - (int)strtotime($cached['fetched_at']);
        if ($age < RATES_TTL_SECONDS) {
            $cached['source'] = 'cache';
            return $cached;
        }
    }

    // ── Fetch from frankfurter.app ────────────────────────────────────────────
    // USD as base → rates.EUR = USD→EUR, rates.INR = USD→INR
    $url  = 'https://api.frankfurter.app/latest?from=USD&to=EUR,INR';
    $body = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'ABChem/1.0',
        ]);
        $body    = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        unset($ch);
        if ($httpCode !== 200 || $body === false) {
            error_log("[currency_rates] fetch failed — HTTP {$httpCode}" . ($curlErr ? " / {$curlErr}" : ''));
            $body = false;
        }
    } else {
        $ctx  = stream_context_create([
            'http' => ['timeout' => 10, 'header' => "User-Agent: ABChem/1.0\r\n"],
            'ssl'  => ['verify_peer' => false],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            error_log('[currency_rates] file_get_contents fetch failed (no cURL)');
        }
    }

    if ($body !== false) {
        $api = json_decode($body, true);
        $eur = (float)($api['rates']['EUR'] ?? 0);
        $inr = (float)($api['rates']['INR'] ?? 0);

        if ($eur > 0 && $inr > 0) {
            // API gives USD→EUR and USD→INR.
            // We store EUR→USD (for "how many USD is 1 EUR") and USD→INR.
            $rates = [
                'EUR_TO_USD'  => round(1.0 / $eur, 6),
                'USD_TO_INR'  => round($inr, 4),
                'EUR_TO_INR'  => round((1.0 / $eur) * $inr, 4),
                'fetched_at'  => date('Y-m-d H:i:s'),
                'source_date' => $api['date'] ?? '',
                'source'      => 'frankfurter.app',
            ];
            @file_put_contents(RATES_CACHE_FILE, json_encode($rates, JSON_PRETTY_PRINT));
            return $rates;
        }
        error_log('[currency_rates] unexpected API payload: ' . substr($body, 0, 200));
    }

    // ── Fallback: stale cache → hard defaults ─────────────────────────────────
    if ($cached) {
        $cached['source'] = 'stale_cache';
        error_log('[currency_rates] using stale cache from ' . ($cached['fetched_at'] ?? 'unknown'));
        return $cached;
    }

    error_log('[currency_rates] using hard fallback rates — no cache, no live fetch');
    return [
        'EUR_TO_USD'  => RATES_FALLBACK_EUR_TO_USD,
        'USD_TO_INR'  => RATES_FALLBACK_USD_TO_INR,
        'EUR_TO_INR'  => round(RATES_FALLBACK_EUR_TO_USD * RATES_FALLBACK_USD_TO_INR, 4),
        'fetched_at'  => '',
        'source_date' => '',
        'source'      => 'fallback',
    ];
}
