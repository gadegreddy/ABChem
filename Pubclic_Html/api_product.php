<?php
/**
 * Single product API with chemical identifiers
 */
require_once __DIR__ . '/../private/functions.php';

$slug = $_GET['slug'] ?? '';
$product = getProductBySlug($slug);

if (!$product) {
    http_response_code(404);
    echo json_encode(['error' => 'Product not found']);
    exit;
}

// Return full product data including chemical identifiers
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'product' => $product
], JSON_PRETTY_PRINT);
?>