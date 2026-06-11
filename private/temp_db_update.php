<?php
require_once __DIR__ . '/functions.php';
try {
    $db = Database::getInstance();
    $db->query("UPDATE compounds SET applications = NULL");
    echo "Success\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
