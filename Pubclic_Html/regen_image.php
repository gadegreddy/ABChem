<?php
// One-time: regenerate molecule image for ABC01199CT-FCA689
// DELETE after use.
$pdo = new PDO(
    'mysql:host=localhost;dbname=u670463068_abchem_db;charset=utf8mb4',
    'u670463068_kishore_gade',
    '17Gopnag*'
);

$stmt = $pdo->prepare(
    "SELECT id, compound_name, slug, smiles, image_url, ab_catalog_number, url_token
     FROM compounds
     WHERE ab_catalog_number = ? AND url_token = ?
     LIMIT 1"
);
$stmt->execute(['ABC01199CT', 'FCA689']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) { die("Compound not found.\n"); }

echo "Compound: {$row['compound_name']} (id={$row['id']}, slug={$row['slug']})\n";
echo "SMILES: " . ($row['smiles'] ?: 'EMPTY') . "\n";
echo "Current image_url: {$row['image_url']}\n";

if (empty($row['smiles']) || $row['smiles'] === 'NA') {
    die("No SMILES available — cannot generate image.\n");
}

$slug       = $row['slug'];
$cachePath  = __DIR__ . '/compound_images/' . $slug . '.png';
$scriptPath = __DIR__ . '/rdkit_search.py';

if (file_exists($cachePath)) { unlink($cachePath); echo "Deleted old PNG.\n"; }

$payload = json_encode([
    'action'     => 'draw',
    'smiles'     => $row['smiles'],
    'format'     => 'png',
    'width'      => 400,
    'height'     => 300,
    'cache_path' => $cachePath,
]);

$desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
$proc = proc_open(['python3', $scriptPath], $desc, $pipes);
fwrite($pipes[0], $payload); fclose($pipes[0]);
$out  = stream_get_contents($pipes[1]); fclose($pipes[1]);
$err  = stream_get_contents($pipes[2]); fclose($pipes[2]);
proc_close($proc);

$result = json_decode($out, true);
if (!empty($result['valid']) && file_exists($cachePath)) {
    $newUrl = '/compound_images/' . $slug . '.png';
    $pdo->prepare("UPDATE compounds SET image_url = ? WHERE id = ?")->execute([$newUrl, $row['id']]);
    echo "OK: {$slug}.png regenerated (" . filesize($cachePath) . " bytes)\n";
    echo "image_url updated to: $newUrl\n";
} else {
    echo "FAILED.\n";
    if ($err)  echo "Stderr: $err\n";
    if (!empty($result['errors'])) echo "Errors: " . implode(', ', $result['errors']) . "\n";
    else echo "Raw output: " . substr($out, 0, 300) . "\n";
}
