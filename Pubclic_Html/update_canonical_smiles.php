<?php
/**
 * update_canonical_smiles.php
 * Generates canonical SMILES for all products using RDKit
 * Can be run via CLI or web (admin only)
 */

require_once __DIR__ . '/../private/functions.php';

// CLI mode check
$isCLI = (php_sapi_name() === 'cli');

// Web mode: enforce admin access
if (!$isCLI) {
    enforceSessionTimeout(900);
    if (!isset($_SESSION['role']) || !checkRole('Admin')) {
        header('Location: /signin');
        exit;
    }
}

$db = Database::getInstance();
$script = __DIR__ . '/generate_canonical_smiles.py';

// Get compounds that have SMILES but no canonical SMILES
$products = $db->fetchAll(
    "SELECT id, compound_name AS product_name, smiles FROM compounds
     WHERE status = 'Active'
       AND smiles IS NOT NULL
       AND smiles != ''
       AND smiles != 'NA'
       AND (smiles_canonical IS NULL OR smiles_canonical = '' OR smiles_canonical = 'NA')
     ORDER BY id"
);

if (empty($products)) {
    $msg = "✅ All products already have canonical SMILES!";
    if ($isCLI) {
        echo $msg . "\n";
        exit(0);
    }
    ?><!DOCTYPE html>
    <html><head><title>Update Canonical SMILES</title>
    <link rel="stylesheet" href="/styles.css"></head><body>
    <?php include 'header.php'; ?>
    <main style="max-width:800px;margin:40px auto;padding:20px;">
        <div class="card" style="padding:24px;text-align:center;">
            <h2><?= $msg ?></h2>
            <a href="/admin" class="btn btn-primary" style="margin-top:16px;">← Back to Admin</a>
        </div>
    </main>
    <?php include 'footer.php'; ?>
    </body></html>
    <?php
    exit;
}

$total = count($products);
$updated = 0;
$errors = 0;
$results = [];

// Process in batches of 100
$batchSize = 100;
$batches = array_chunk($products, $batchSize);

foreach ($batches as $batchIndex => $batch) {
    // Prepare input for python script
    $input = '';
    foreach ($batch as $p) {
        $input .= "{$p['id']}\t{$p['smiles']}\n";
    }
    
    // Run RDKit script
    $descriptorspec = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];
    
    $process = proc_open(
        "python3 " . escapeshellarg($script) . " --batch",
        $descriptorspec,
        $pipes
    );
    
    if (!is_resource($process)) {
        $errors += count($batch);
        error_log("Failed to start RDKit process for batch $batchIndex");
        continue;
    }
    
    // Write input and close stdin
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    
    // Read output
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    
    // Read any errors
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    
    $exitCode = proc_close($process);
    
    if ($exitCode !== 0) {
        $errors += count($batch);
        error_log("RDKit process exited with code $exitCode: $stderr");
        continue;
    }
    
    $data = json_decode($output, true);
    if (!$data || !isset($data['results'])) {
        $errors += count($batch);
        error_log("Failed to parse RDKit output: " . substr($output, 0, 500));
        continue;
    }
    
    // Update database
    foreach ($data['results'] as $r) {
        try {
            $db->update(
                'products',
                [
                    'smiles_canonical' => $r['canonical_smiles'],
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                'id = :id',
                ['id' => intval($r['id'])]
            );
            $updated++;
            $results[] = [
                'id' => $r['id'],
                'canonical' => $r['canonical_smiles']
            ];
        } catch (Exception $e) {
            $errors++;
            error_log("DB update failed for product {$r['id']}: " . $e->getMessage());
        }
    }
    
    // Rate limiting between batches
    if (count($batches) > 1) {
        usleep(100000); // 0.1 second
    }
}

$message = "Canonical SMILES updated: $updated succeeded, $errors failed, $total total";

if ($isCLI) {
    echo $message . "\n";
    if (!empty($results)) {
        echo "\nSample results:\n";
        foreach (array_slice($results, 0, 5) as $r) {
            echo "  ID {$r['id']}: {$r['canonical']}\n";
        }
    }
    exit($errors > 0 ? 1 : 0);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Update Canonical SMILES | AB Chem</title>
<link rel="stylesheet" href="/styles.css">
<style>
.card { background: white; border-radius: 12px; padding: 28px; border: 1px solid #e2e8f0; margin-bottom: 20px; }
.success { color: #16a34a; }
.error-count { color: #ef4444; }
.result-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
.result-table th { background: #0f172a; color: white; padding: 10px 14px; text-align: left; }
.result-table td { padding: 10px 14px; border-bottom: 1px solid #e2e8f0; }
.result-table tr:hover td { background: #f8fafc; }
.result-table code { background: #f1f5f9; padding: 2px 8px; border-radius: 4px; font-size: .85rem; word-break: break-all; }
</style>
</head>
<body>
<?php include 'header.php'; ?>
<main style="max-width:1000px;margin:40px auto;padding:20px;">
    <div class="card">
        <h2>⚗️ Generate Canonical SMILES</h2>
        <p style="color:var(--muted);margin-bottom:20px;">
            Uses RDKit to generate canonical SMILES from existing SMILES data.
        </p>
        
        <div class="alert alert-success">
            <?= nl2br(e($message)) ?>
        </div>
        
        <?php if (!empty($results)): ?>
        <h3 style="margin-top:24px;">Updated Products (first 50)</h3>
        <table class="result-table">
            <thead>
                <tr><th>ID</th><th>Canonical SMILES</th></tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($results, 0, 50) as $r): ?>
                <tr>
                    <td><?= $r['id'] ?></td>
                    <td><code><?= e($r['canonical']) ?></code></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <div style="margin-top:20px;">
            <a href="/admin" class="btn btn-outline">← Back to Admin</a>
        </div>
    </div>
</main>
<?php include 'footer.php'; ?>
</body>
</html>