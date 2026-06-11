<?php
/**
 * admin_run_migration.php — One-time web runner for DB migrations.
 *
 * SECURITY: Admin session required. Delete this file after running.
 * URL: https://www.abchem.co.in/admin-run-migration
 */
require_once __DIR__ . '/../private/functions.php';

session_start();
if (!isset($_SESSION['role']) || !checkRole('Admin')) {
    http_response_code(403);
    die('Access denied. Admin login required.');
}

$db  = Database::getInstance();
$pdo = $db->getConnection();

$log = [];

// ── Step 1: Add url_slug column ───────────────────────────────────────────────
$cols = $pdo->query("SHOW COLUMNS FROM compounds LIKE 'url_slug'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE compounds
                ADD COLUMN url_slug VARCHAR(250) DEFAULT NULL AFTER url_token");
    $pdo->exec("CREATE INDEX idx_compounds_url_slug ON compounds(url_slug)");
    $log[] = '✅ Column url_slug added and indexed.';
} else {
    $log[] = 'ℹ️ Column url_slug already exists — skipping ALTER.';
}

// ── Step 2: Populate slugs ────────────────────────────────────────────────────
$rows = $pdo->query(
    "SELECT id, compound_name FROM compounds
     WHERE (url_slug IS NULL OR url_slug = '')
       AND compound_name IS NOT NULL AND compound_name != ''"
)->fetchAll(PDO::FETCH_ASSOC);

$log[] = 'ℹ️ ' . count($rows) . ' rows need slug generation.';

$stmt    = $pdo->prepare("UPDATE compounds SET url_slug = :s WHERE id = :id");
$updated = 0;
foreach ($rows as $row) {
    $slug = makeUrlSlug($row['compound_name']);
    if ($slug === '') continue;
    $stmt->execute(['s' => $slug, 'id' => $row['id']]);
    $updated++;
}

$log[] = "✅ $updated compounds updated with url_slug.";
$log[] = '🏁 Migration complete. <strong>Delete this file from the server now.</strong>';
?>
<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Migration 002 — url_slug</title>
<style>body{font-family:monospace;padding:40px;background:#0f172a;color:#e2e8f0;}
pre{background:#1e293b;padding:24px;border-radius:8px;font-size:15px;line-height:1.8;}</style>
</head><body>
<h2 style="color:#38bdf8">Migration 002 — url_slug</h2>
<pre><?php foreach ($log as $line) echo $line . "\n"; ?></pre>
</body></html>
