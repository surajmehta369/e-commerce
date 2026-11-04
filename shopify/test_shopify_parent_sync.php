<?php

require_once __DIR__ . '/../connection/dbconnect.php';

require_once __DIR__ . '/functions.php';

echo "<h2>Shopify to Local Database Sync Test</h2>";

try {
    $result = syncShopifyProductsToDatabase();

    echo "<h3>Function Response</h3>";
    echo '<pre>';
    print_r($result);
    echo '</pre>';

    if (!empty($result['success'])) {
        echo "<h3>Local Product Data</h3>";

        $productId = (int) $result['product_id'];

        $stmt = $db->prepare("
            SELECT *
            FROM products
            WHERE id = :id
        ");
        $stmt->execute([':id' => $productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        echo '<pre>';
        print_r($product);
        echo '</pre>';

        echo "<h3>Local Product Variants</h3>";

        $stmt = $db->prepare("
            SELECT *
            FROM product_variants
            WHERE product_id = :product_id
            ORDER BY id ASC
        ");
        $stmt->execute([':product_id' => $productId]);
        $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo '<pre>';
        print_r($variants);
        echo '</pre>';
    }

} catch (Throwable $e) {
    echo "<h3>Test Error</h3>";
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}
