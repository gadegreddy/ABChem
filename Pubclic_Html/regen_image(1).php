<?php
/**
 * One-time script: regenerate molecule image for 25-Desacetyl Rifamycin
 * using RDKit CoordGen layout, then update image_url in DB.
 * DELETE after use.
 */
$pdo = new PDO(
    'mysql:host=localhost;dbname=u670463068_abchem_db;charset=utf8mb4',
    'u670463068_kishore_gade',
    '17Gopnag*'
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$row = $pdo->query("SELECT id, slug, smiles FROM compounds WHERE id = 1416")->fetch(PDO::FETCH_ASSOC);
echo "Compound: id={$row['id']}, slug={$row['slug']}\n";

$smiles     = $row['smiles'];
$slug       = $row['slug'];
$imageDir   = __DIR__ . '/compound_images/';
$cachePath  = $imageDir . $slug . '.png';
$scriptPath = __DIR__ . '/rdkit_search.py';

// Call rdkit_search.py with draw action
$payload = json_encode([
    'action'     => 'draw',
    'smiles'     => $smiles,
    'format'     => 'png',
    'width'      => 400,
    'height'     => 300,
    'cache_path' => $cachePath,
]);

$desc = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$proc = proc_open(['python3', $scriptPath], $desc, $pipes);
if (!is_resource($proc)) {
    die("ERROR: Could not start rdkit_search.py\n");
}
fwrite($pipes[0], $payload);
fclose($pipes[0]);

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($proc);

echo "Exit code: $exitCode\n";
if ($stderr) echo "Stderr: $stderr\n";

$result = json_decode($stdout, true);
echo "Result: " . json_encode($result) . "\n";

if (!empty($result['valid']) && file_exists($cachePath) && filesize($cachePath) > 200) {
    $newUrl = '/compound_images/' . $slug . '.png';
    $upd = $pdo->prepare("UPDATE compounds SET image_url = ? WHERE id = 1416");
    $upd->execute([$newUrl]);
    echo "SUCCESS: image_url updated to '$newUrl' (PNG size: " . filesize($cachePath) . " bytes)\n";
} else {
    echo "FAILED: image not generated.\n";
    // Fallback: at least fix the image_url to correct slug even without new image
    $newUrl = '/compound_images/' . $slug . '.png';
    $upd = $pdo->prepare("UPDATE compounds SET image_url = ? WHERE id = 1416");
    $upd->execute([$newUrl]);
    echo "URL updated to '$newUrl' (image may be missing until PubChem fetch runs)\n";
}
echo "Done. Delete this file!\n";
