<?php
/**
 * indexnow_bulk_ping.php — One-time bulk IndexNow submission (FEAT-32)
 *
 * Submits ALL active compound URLs to IndexNow in batches of 100.
 * Run once from admin to fix the stale-index problem for all existing compounds.
 *
 * Access: admin only.  Delete or restrict this file after running.
 * Usage:  https://www.abchem.co.in/indexnow-bulk-ping
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
set_time_limit(120);

require_once __DIR__ . '/../private/functions.php';

// Admin only
if (!isset($_SESSION['role']) || !checkRole('Admin')) {
    http_response_code(403);
    exit('Unauthorised');
}

$db       = Database::getInstance();
$base     = 'https://www.abchem.co.in';
$key      = INDEXNOW_KEY;
$keyFile  = "https://www.abchem.co.in/{$key}.txt";
$endpoint = 'https://api.indexnow.org/indexnow';

// Fetch all active compound URLs
$compounds = $db->fetchAll(
    "SELECT ab_catalog_number, url_token, slug
     FROM compounds
     WHERE status = 'Active'
     ORDER BY id ASC"
);

$urls = [];
foreach ($compounds as $c) {
    $url = compoundPublicUrl($c);
    if ($url) $urls[] = $url;
}

// Always include static pages
$staticPages = ['/', '/catalog', '/about', '/contact', '/custom-synthesis', '/purification'];
foreach ($staticPages as $p) $urls[] = $base . $p;

$total   = count($urls);
$batches = array_chunk($urls, 100); // IndexNow max 10,000 per request; 100 is safe
$sent    = 0;
$errors  = [];

foreach ($batches as $batch) {
    $payload = json_encode([
        'host'        => 'www.abchem.co.in',
        'key'         => $key,
        'keyLocation' => $keyFile,
        'urlList'     => $batch,
    ]);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        $sent += count($batch);
    } else {
        $errors[] = "Batch of " . count($batch) . " → HTTP {$code}: " . substr($resp, 0, 200);
    }
    usleep(500000); // 0.5s between batches — polite rate
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>IndexNow Bulk Ping — AB Chem</title>
<link rel="stylesheet" href="/styles.css">
</head>
<body>
<?php include 'header.php'; ?>
<div style="max-width:700px;margin:40px auto;padding:0 20px;">
    <h1 style="font-size:1.5rem;margin-bottom:20px;">🔍 IndexNow Bulk Ping</h1>

    <div style="background:#dcfce7;border:1px solid #86efac;border-radius:8px;padding:20px;margin-bottom:16px;">
        <strong style="color:#166534">✅ Done</strong><br>
        Submitted <strong><?= $sent ?></strong> of <strong><?= $total ?></strong> URLs to IndexNow.
    </div>

    <?php if ($errors): ?>
    <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:16px;margin-bottom:16px;">
        <strong style="color:#991b1b">⚠️ <?= count($errors) ?> batch error(s):</strong>
        <ul style="margin:8px 0 0;padding-left:18px;">
        <?php foreach ($errors as $e): ?>
            <li style="font-size:.85rem"><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;font-size:.875rem;">
        <strong>What happens next:</strong>
        <ul style="margin:8px 0 0;padding-left:18px;line-height:1.8">
            <li>Bing and Yandex will re-crawl all submitted URLs within <strong>minutes to hours</strong></li>
            <li>Google follows IndexNow signals — expect re-indexing within <strong>1–2 days</strong></li>
            <li>InChIKey, CAS, synonyms, and SMILES will then be searchable in Google</li>
            <li>You still need to <strong>submit sitemap.xml to Google Search Console</strong> for fastest results</li>
        </ul>
    </div>

    <p style="margin-top:20px;color:#64748b;font-size:.8rem;">
        ⚠️ Delete or disable this file after running — it should only be used once.
        Going forward, IndexNow pings automatically whenever a compound is saved or PubChem-enriched.
    </p>

    <a href="/admin-products?action=list" style="display:inline-block;margin-top:16px;" class="btn btn-outline">← Back to Products</a>
</div>
<?php include 'footer.php'; ?>
</body>
</html>
