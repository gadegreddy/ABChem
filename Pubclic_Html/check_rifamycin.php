<?php
$pdo = new PDO(
    'mysql:host=localhost;dbname=u670463068_abchem_db;charset=utf8mb4',
    'u670463068_kishore_gade',
    '17Gopnag*'
);
$stmt = $pdo->query("SELECT id, compound_name, slug, image_url, smiles FROM compounds WHERE id = 1416");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
