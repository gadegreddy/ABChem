<?php
// One-time: regenerate molecule image for compound id=1416 (25-Desacetyl Rifamycin)
// DELETE after use.
$pdo = new PDO(
    'mysql:host=localhost;dbname=u670463068_abchem_db;charset=utf8mb4',
    'u670463068_kishore_gade',
    '17Gopnag*'
);
$row        = $pdo->query("SELECT slug, smiles FROM compounds WHERE id = 1416")->fetch(PDO::FETCH_ASSOC);
$slug       = $row['slug'];
$cachePath  = __DIR__ . '/compound_images/' . $slug . '.png';
$scriptPath = __DIR__ . '/rdkit_search.py';

// Delete old cached image to force fresh generation
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
    echo "OK: {$slug}.png regenerated (" . filesize($cachePath) . " bytes)\n";
} else {
    echo "FAILED. Stderr: $err\nResult: $out\n";
}
