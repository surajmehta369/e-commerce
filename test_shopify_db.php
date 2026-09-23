<?php

header('Content-Type: application/json');

try {

    require_once __DIR__ . '/connection/dbconnect.php';

    $database = new Database();
    $conn = $database->connect();

    $shopifyProductGid = 'gid://shopify/Product/9526290710766';

    $stmt = $conn->prepare("
        SELECT
            id,
            shopify_product_id,
            title,
            slug,
            shopify_status,
            shopify_synced_at
        FROM products
        WHERE shopify_product_id = :shopify_product_id
        LIMIT 1
    ");

    $stmt->execute([
        ':shopify_product_id' => $shopifyProductGid
    ]);

    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'database_connection' => 'OK',
        'product_found' => $product ? true : false,
        'product' => $product
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database test failed.',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}