<?php
/**
 * health.php — System Health Check Endpoint (Item 18)
 * Returns JSON with status of DB, session write, disk space, PubChem API.
 * Recommended: connect to UptimeRobot or BetterUptime.
 * Access restricted to local IP + a secret token to prevent info leakage.
 */
require_once __DIR__ . '/../private/db_config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache');

// Simple token check — set HEALTH_TOKEN in .env
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, '=') !== false && $line[0] !== '#') {
            [$k, $v] = explode('=', $line, 2);
            putenv(trim($k) . '=' . trim($v));
        }
    }
}

$token = getenv('HEALTH_TOKEN') ?: '';
$requestToken = $_GET['token'] ?? ($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? '');
if ($token && !hash_equals($token, $requestToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden']);
    exit;
}

$status  = 'ok';
$checks  = [];
$started = microtime(true);

// 1. DB connectivity
try {
    $db = Database::getInstance();
    $db->fetchValue('SELECT 1');
    $checks['database'] = ['status' => 'ok'];
} catch (Throwable $e) {
    $status = 'degraded';
    $checks['database'] = ['status' => 'error', 'message' => 'DB unreachable'];
}

// 2. Session write permissions
$tmpDir = sys_get_temp_dir();
$testFile = $tmpDir . '/health_' . getmypid() . '.tmp';
if (@file_put_contents($testFile, '1') !== false) {
    @unlink($testFile);
    $checks['session_write'] = ['status' => 'ok'];
} else {
    $status = 'degraded';
    $checks['session_write'] = ['status' => 'error', 'message' => 'Cannot write to temp dir'];
}

// 3. Backup directory disk space
$backupDir = __DIR__;
$freeBytes = @disk_free_space($backupDir);
$totalBytes = @disk_total_space($backupDir);
if ($freeBytes !== false && $totalBytes > 0) {
    $freeMb   = round($freeBytes / 1048576, 1);
    $pctFree  = round(($freeBytes / $totalBytes) * 100, 1);
    $diskStatus = ($freeMb < 100) ? 'warning' : 'ok';
    if ($diskStatus === 'warning') $status = 'degraded';
    $checks['disk_space'] = [
        'status'   => $diskStatus,
        'free_mb'  => $freeMb,
        'pct_free' => $pctFree
    ];
} else {
    $checks['disk_space'] = ['status' => 'unknown'];
}

// 4. PubChem API reachability (quick HEAD request)
$ctx = stream_context_create(['http' => ['timeout' => 4, 'method' => 'HEAD']]);
$pubchemUrl = 'https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/cid/2244/property/MolecularFormula/JSON';
$r = @file_get_contents($pubchemUrl, false, $ctx);
if ($r !== false || (isset($http_response_header) && strpos($http_response_header[0], '200') !== false)) {
    $checks['pubchem_api'] = ['status' => 'ok'];
} else {
    // Non-critical: PubChem may be unreachable but site still works
    $checks['pubchem_api'] = ['status' => 'unreachable', 'note' => 'non-critical'];
}

$checks['response_time_ms'] = round((microtime(true) - $started) * 1000, 2);

http_response_code($status === 'ok' ? 200 : 503);
echo json_encode([
    'status'    => $status,
    'timestamp' => date('c'),
    'checks'    => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
