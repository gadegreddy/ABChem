<?php
require_once __DIR__ . '/../private/functions.php';
try {
    $db = Database::getInstance();
    $db->query("UPDATE compounds SET applications = NULL");
    echo "Success! The applications column has been cleared.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
