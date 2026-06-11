<?php
require_once __DIR__ . '/../private/functions.php';


enforceSessionTimeout(900);
if (!isset($_SESSION['role']) || !checkRole('Admin')) {
    http_response_code(403);
    die("Access denied. Admin only.");
}

require_once __DIR__ . '/../private/db_config.php';


putenv('MYSQL_PWD=' . DB_PASS);

$backupDir = dirname(__DIR__) . '/backups'; 
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0750, true); 
}

$filename = $backupDir . '/abchem_backup_' . date('Y-m-d_H-i-s') . '.sql';

// Build command WITHOUT password in arguments
$command = sprintf(
    'mysqldump --host=%s --user=%s %s > %s 2>&1',
    escapeshellarg(DB_HOST),
    escapeshellarg(DB_USER),
    escapeshellarg(DB_NAME),
    escapeshellarg($filename)
);

exec($command, $output, $returnCode);

// Clear environment variable immediately
putenv('MYSQL_PWD');

if ($returnCode === 0) {
    // Set restrictive permissions on backup file
    chmod($filename, 0600);
    
    // Keep only last 7 backups (reduce from 30 to save space)
    $files = glob($backupDir . '/abchem_backup_*.sql');
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    foreach (array_slice($files, 7) as $file) {
        unlink($file);
    }
    
    logAudit('backup_created', "Database backup created: " . basename($filename));
    echo "✅ Backup created successfully: " . basename($filename) . "\n";
} else {
    error_log("Database backup failed: " . implode("\n", $output));
    echo "❌ Backup failed. Check error log.\n";
}