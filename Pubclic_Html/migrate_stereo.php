<?php
/**
 * migrate_stereo.php — One-time DB migration for FEAT-37 (stereo columns)
 * Run once via SSH, then delete immediately.
 *
 * Adds to compounds table:
 *   stereo_status  ENUM('achiral','verified','unverified','manual_review')
 *   stereo_source  VARCHAR(50)
 *   smiles_stereo  TEXT
 */
$pdo = new PDO(
    'mysql:host=localhost;dbname=u670463068_abchem_db;charset=utf8mb4',
    'u670463068_kishore_gade',
    '17Gopnag*'
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$steps = [
    "Add stereo_status column" =>
        "ALTER TABLE compounds
         ADD COLUMN IF NOT EXISTS stereo_status
             ENUM('achiral','verified','unverified','manual_review') DEFAULT NULL
             COMMENT 'Stereo assignment quality: achiral=no stereocenters, verified=cross-DB confirmed, unverified=needs check, manual_review=unresolved'",

    "Add stereo_source column" =>
        "ALTER TABLE compounds
         ADD COLUMN IF NOT EXISTS stereo_source
             VARCHAR(50) DEFAULT NULL
             COMMENT 'Source of verified stereo SMILES: gsrs|chembl|manual'",

    "Add smiles_stereo column" =>
        "ALTER TABLE compounds
         ADD COLUMN IF NOT EXISTS smiles_stereo
             TEXT DEFAULT NULL
             COMMENT 'Stereospecific SMILES from authoritative source, separate from supplier SMILES'",

    "Add index on stereo_status" =>
        "ALTER TABLE compounds
         ADD INDEX IF NOT EXISTS idx_stereo_status (stereo_status)",
];

foreach ($steps as $desc => $sql) {
    try {
        $pdo->exec($sql);
        echo "  OK  $desc\n";
    } catch (PDOException $e) {
        // Ignore "Duplicate column" errors — column already exists
        if (str_contains($e->getMessage(), 'Duplicate column') ||
            str_contains($e->getMessage(), 'already exists')) {
            echo "SKIP  $desc (already exists)\n";
        } else {
            echo "FAIL  $desc: " . $e->getMessage() . "\n";
        }
    }
}

// Verify
$cols = $pdo->query("SHOW COLUMNS FROM compounds LIKE 'stereo%'")->fetchAll(PDO::FETCH_ASSOC);
echo "\nVerification — stereo columns now in compounds table:\n";
foreach ($cols as $c) {
    echo "  {$c['Field']}  {$c['Type']}  default={$c['Default']}\n";
}

// Stats
$row = $pdo->query("SELECT COUNT(*) as total FROM compounds WHERE status='Active'")->fetch();
echo "\nActive compounds: {$row['total']} — all have stereo_status=NULL (will be set by api_stereo.php)\n";
echo "\nDone. DELETE this file immediately!\n";
