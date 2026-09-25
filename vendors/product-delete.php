<?php

require_once "auth.php";
require_once "../connection/dbconnect.php";
require_once "../shopify/functions.php";

$database = new Database();
$db = $database->connect();

$vendorId = (int) $_SESSION['user_id'];
$productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($productId <= 0) {
    header("Location: products.php");
    exit;
}
$productStmt = $db->prepare("
    SELECT
        id,
        image,
        shopify_product_id
    FROM products
    WHERE id = :id
      AND vendor_id = :vendor_id
    LIMIT 1
");

$productStmt->execute([
    ':id' => $productId,
    ':vendor_id' => $vendorId
]);

$product = $productStmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header("Location: products.php");
    exit;
}

if (!empty($product['shopify_product_id'])) {

    $shopifyResult = deleteShopifyProduct(
        $product['shopify_product_id']
    );
if (empty($shopifyResult['success'])) {

    echo '<pre>';
    print_r($shopifyResult);
    echo '</pre>';
    exit;
}
}


try {

    $db->beginTransaction();
    $deleteStmt = $db->prepare("
        DELETE FROM products
        WHERE id = :id
          AND vendor_id = :vendor_id
    ");

    $deleteStmt->execute([
        ':id' => $productId,
        ':vendor_id' => $vendorId
    ]);

    if ($deleteStmt->rowCount() !== 1) {

        $db->rollBack();

        header("Location: products.php");
        exit;
    }

    $db->commit();
    if (!empty($product['image'])) {

        $imagePath = "../" . $product['image'];

        if (
            file_exists($imagePath) &&
            is_file($imagePath)
        ) {
            unlink($imagePath);
        }
    }

    header("Location: products.php?deleted=1");
    exit;


} catch (Exception $e) {

    if ($db->inTransaction()) {
        $db->rollBack();
    }

    header(
        "Location: products.php?delete_error=1"
    );

    exit;
}