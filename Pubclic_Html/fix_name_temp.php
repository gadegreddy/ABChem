<?php
// Temporary one-time fix — fixes slug typo too
$pdo = new PDO(
    'mysql:host=localhost;dbname=u670463068_abchem_db;charset=utf8mb4',
    'u670463068_kishore_gade',
    '17Gopnag*'
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Fix slug typo: desaceyl -> desacetyl
$upd = $pdo->prepare("UPDATE compounds SET slug = '25-desacetyl-rifamycin' WHERE id = 1416 AND slug = '25-desaceyl-rifamycin'");
$upd->execute();
echo "Slug rows updated: " . $upd->rowCount() . "\n";

// Verify both fields
$stmt = $pdo->query("SELECT id, compound_name, slug FROM compounds WHERE id = 1416");
$row  = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Final: " . json_encode($row) . "\n";

// Also delete any cached SVG for the old slug
$oldCache = '/home/u670463068/domains/abchem.co.in/public_html/compound_images/25-desaceyl-rifamycin.svg';
if (file_exists($oldCache)) {
    unlink($oldCache);
    echo "Deleted old SVG cache.\n";
} else {
    echo "No cached SVG found at old slug path.\n";
}
echo "Done. Delete this file!\n";
