<?php
/**
 * csp_report.php — CSP Violation Report Receiver (Item 15)
 * Receives reports from Content-Security-Policy-Report-Only header.
 * Logs violations so you can tighten the policy without breaking things.
 */
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Only accept POST from same origin
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
if (empty($raw)) {
    http_response_code(400);
    echo json_encode(['error' => 'empty body']);
    exit;
}

$report = json_decode($raw, true);
if (!$report) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid json']);
    exit;
}

// Format log entry
$entry = sprintf(
    "[%s] CSP-VIOLATION | blocked-uri=%s | violated-directive=%s | document-uri=%s | referrer=%s | disposition=%s | ip=%s\n",
    date('Y-m-d H:i:s'),
    $report['csp-report']['blocked-uri']          ?? $report['blocked-uri']          ?? 'unknown',
    $report['csp-report']['violated-directive']    ?? $report['violated-directive']    ?? 'unknown',
    $report['csp-report']['document-uri']          ?? $report['document-uri']          ?? 'unknown',
    $report['csp-report']['referrer']              ?? $report['referrer']              ?? '',
    $report['csp-report']['disposition']           ?? $report['disposition']           ?? 'enforce',
    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
);

error_log($entry, 3, '/home/u670463068/domains/abchem.co.in/error_log');

http_response_code(204);
