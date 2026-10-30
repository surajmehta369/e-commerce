<?php

require_once __DIR__ . '/../connection/dbconnect.php';

header('Content-Type: application/json; charset=utf-8');

$database = new Database();
$db = $database->connect();

$productId = 82;

// Fetch parent product
$productStmt = $db->prepare("
    SELECT
        id,
        title,
        sku,
        stock,
        shopify_product_id,
        shopify_status
    FROM products
    WHERE id = :product_id
");

$productStmt->execute([
    ':product_id' => $productId
]);

$product = $productStmt->fetch(PDO::FETCH_ASSOC);

// Fetch variants
$variantStmt = $db->prepare("
    SELECT
        id,
        variant_title,
        sku,
        price,
        original_price,
        stock,
        shopify_variant_id,
        shopify_inventory_item_id,
        shopify_sync_status
    FROM product_variants
    WHERE product_id = :product_id
");

$variantStmt->execute([
    ':product_id' => $productId
]);

$variants = $variantStmt->fetchAll(PDO::FETCH_ASSOC);

// Output
echo json_encode([
    'success' => true,
    'product' => $product,
    'variants' => $variants
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);