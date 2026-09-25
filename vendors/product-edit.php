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
        sku,
        title,
        slug,
        description,
        image,
        price,
        original_price,
        discount,
        stock,
        category_id,
        brand_id,
        status,
        vendor_id,
        shopify_product_id,
        shopify_status
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

$categoryStmt = $db->query("
    SELECT id, name
    FROM categories
    WHERE status = 1
    ORDER BY name ASC
");

$categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

$brandStmt = $db->query("
    SELECT id, name
    FROM brands
    WHERE status = 1
    ORDER BY name ASC
");

$brands = $brandStmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $sku = trim($_POST['sku'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $price = (float) ($_POST['price'] ?? 0);
    $originalPrice = (float) ($_POST['original_price'] ?? 0);

    $stock = (int) ($_POST['stock'] ?? 0);

    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $brandId = (int) ($_POST['brand_id'] ?? 0);

    if ($sku === '') {
        $errors[] = "SKU is required.";
    }

    if ($title === '') {
        $errors[] = "Product title is required.";
    }

    if ($price <= 0) {
        $errors[] = "Price must be greater than 0.";
    }

    if ($originalPrice <= 0) {
        $errors[] = "Original price must be greater than 0.";
    }

    if ($originalPrice < $price) {
        $errors[] = "Original price cannot be less than selling price.";
    }

    if ($stock < 0) {
        $errors[] = "Stock cannot be negative.";
    }

    if ($categoryId <= 0) {
        $errors[] = "Please select a category.";
    }

    if ($brandId <= 0) {
        $errors[] = "Please select a brand.";
    }

    if (empty($errors)) {

        $skuStmt = $db->prepare("
            SELECT id
            FROM products
            WHERE sku = :sku
              AND id != :id
            LIMIT 1
        ");

        $skuStmt->execute([
            ':sku' => $sku,
            ':id' => $productId
        ]);

        if ($skuStmt->fetch()) {
            $errors[] = "This SKU is already being used by another product.";
        }
    }

    if (empty($errors)) {

        $categoryCheck = $db->prepare("
            SELECT id
            FROM categories
            WHERE id = :id
              AND status = 1
            LIMIT 1
        ");

        $categoryCheck->execute([
            ':id' => $categoryId
        ]);

        if (!$categoryCheck->fetch()) {
            $errors[] = "Selected category is not available.";
        }
    }

    if (empty($errors)) {

        $brandCheck = $db->prepare("
            SELECT id
            FROM brands
            WHERE id = :id
              AND status = 1
            LIMIT 1
        ");

        $brandCheck->execute([
            ':id' => $brandId
        ]);

        if (!$brandCheck->fetch()) {
            $errors[] = "Selected brand is not available.";
        }
    }
    $discount = 0;

    if ($originalPrice > 0 && $price < $originalPrice) {
        $discount = (($originalPrice - $price) / $originalPrice) * 100;
        $discount = round($discount, 2);
    }
    $newImagePath = $product['image'];
    $uploadedNewImage = false;
    $uploadedFilePath = null;

    if (
        empty($errors) &&
        isset($_FILES['image']) &&
        $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {

            $errors[] = "There was an error uploading the image.";
        } else {

            $maxFileSize = 5 * 1024 * 1024;

            if ($_FILES['image']['size'] > $maxFileSize) {
                $errors[] = "Image size must not exceed 5 MB.";
            }

            $allowedExtensions = [
                'jpg',
                'jpeg',
                'png',
                'webp'
            ];

            $originalFileName = $_FILES['image']['name'];
            $extension = strtolower(
                pathinfo($originalFileName, PATHINFO_EXTENSION)
            );

            if (!in_array($extension, $allowedExtensions, true)) {
                $errors[] = "Only JPG, JPEG, PNG and WEBP images are allowed.";
            }

            if (empty($errors)) {

                $uploadDirectory = "../assets/uploads/";

                if (!is_dir($uploadDirectory)) {
                    mkdir($uploadDirectory, 0777, true);
                }

                $newFileName =
                    'product_' .
                    time() .
                    '_' .
                    bin2hex(random_bytes(5)) .
                    '.' .
                    $extension;

                $targetPath = $uploadDirectory . $newFileName;

                if (move_uploaded_file(
                    $_FILES['image']['tmp_name'],
                    $targetPath
                )) {

                    $newImagePath = "assets/uploads/" . $newFileName;

                    $uploadedNewImage = true;
                    $uploadedFilePath = $targetPath;
                } else {

                    $errors[] = "Failed to upload the image.";
                }
            }
        }
    }

    if (empty($errors)) {

        try {

            $db->beginTransaction();
            $updateStmt = $db->prepare("
                UPDATE products
                SET
                    sku = :sku,
                    title = :title,
                    description = :description,
                    image = :image,
                    price = :price,
                    original_price = :original_price,
                    discount = :discount,
                    stock = :stock,
                    category_id = :category_id,
                    brand_id = :brand_id
                WHERE id = :id
                  AND vendor_id = :vendor_id
            ");

            $updateStmt->execute([
                ':sku' => $sku,
                ':title' => $title,
                ':description' => $description,
                ':image' => $newImagePath,
                ':price' => $price,
                ':original_price' => $originalPrice,
                ':discount' => $discount,
                ':stock' => $stock,
                ':category_id' => $categoryId,
                ':brand_id' => $brandId,
                ':id' => $productId,
                ':vendor_id' => $vendorId
            ]);

            if (!empty($product['shopify_product_id'])) {

                $shopifyProductId =
                    $product['shopify_product_id'];

                $shopifyResult =
                    updateShopifyProduct(
                        $shopifyProductId,
                        [
                            'title' =>
                            $title,

                            'description' =>
                            $description,

                            'slug' =>
                            $product['slug'],

                            'status' =>
                            $product['status']
                        ]
                    );

                if (empty($shopifyResult['success'])) {

                    throw new Exception(
                        $shopifyResult['message']
                            ?? 'Unable to update Shopify product.'
                    );
                }


                $variantResult =
                    updateShopifyProductVariant(
                        $shopifyProductId,
                        $price,
                        $originalPrice,
                        $sku
                    );

                if (empty($variantResult['success'])) {

                    throw new Exception(
                        $variantResult['message']
                            ?? 'Unable to update Shopify variant.'
                    );
                }


                $inventoryResult =
                    updateShopifyInventory(
                        $shopifyProductId,
                        $stock
                    );

                if (empty($inventoryResult['success'])) {

                    throw new Exception(
                        $inventoryResult['message']
                            ?? 'Unable to update Shopify inventory.'
                    );
                }
                $collectionResult =
                    addShopifyProductToVendorCollection(
                        $shopifyProductId,
                        $vendorId
                    );

                if (empty($collectionResult['success'])) {

                    throw new Exception(
                        $collectionResult['message']
                            ?? 'Unable to update vendor Shopify collection.'
                    );
                }


                $shopifySyncStmt = $db->prepare("
        UPDATE products
        SET
            shopify_synced_at = NOW()
        WHERE id = :id
    ");

                $shopifySyncStmt->execute([
                    ':id' => $productId
                ]);
            }

            $db->commit();
            if ($uploadedNewImage && !empty($product['image'])) {

                $oldImagePath = "../" . $product['image'];

                if (
                    file_exists($oldImagePath) &&
                    is_file($oldImagePath)
                ) {
                    unlink($oldImagePath);
                }
            }
            $productStmt->execute([
                ':id' => $productId,
                ':vendor_id' => $vendorId
            ]);

            $product = $productStmt->fetch(PDO::FETCH_ASSOC);

            $success = "Product updated successfully.";
       } catch (Exception $e) {

    if ($db->inTransaction()) {
        $db->rollBack();
    }

    if (
        $uploadedNewImage &&
        $uploadedFilePath &&
        file_exists($uploadedFilePath)
    ) {
        unlink($uploadedFilePath);
    }

    $errors[] =
        "Failed to update product: " .
        $e->getMessage();
}
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Edit Product</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

</head>

<body>

    <div class="container-fluid">

        <div class="row">

            <!-- Sidebar -->

            <div class="col-md-2 bg-dark text-white min-vh-100 p-3">

                <h4 class="mb-4">
                    Vendor Panel
                </h4>

                <ul class="nav flex-column">

                    <li class="nav-item mb-2">

                        <a
                            href="index.php"
                            class="nav-link text-white">

                            Dashboard

                        </a>

                    </li>

                    <li class="nav-item mb-2">

                        <a
                            href="products.php"
                            class="nav-link text-white">

                            Products

                        </a>

                    </li>

                    <li class="nav-item mb-2">

                        <a
                            href="orders.php"
                            class="nav-link text-white">

                            Orders

                        </a>

                    </li>

                    <li class="nav-item mb-2">

                        <a
                            href="profile.php"
                            class="nav-link text-white">

                            Store Profile

                        </a>

                    </li>

                    <li class="nav-item mt-3">

                        <a
                            href="../outh/logout.php"
                            class="nav-link text-danger">

                            Logout

                        </a>

                    </li>

                </ul>

            </div>
            <div class="col-md-10 p-4">

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2>
                            Edit Product
                        </h2>
                        <p class="text-muted mb-0">
                            Update your product information
                        </p>

                    </div>

                    <a href="products.php"
                        class="btn btn-secondary">

                        Back to Products

                    </a>

                </div>
                <?php if (!empty($errors)): ?>

                    <div class="alert alert-danger">

                        <ul class="mb-0">

                            <?php foreach ($errors as $error): ?>

                                <li>
                                    <?= htmlspecialchars($error) ?>
                                </li>

                            <?php endforeach; ?>

                        </ul>

                    </div>

                <?php endif; ?>
                <?php if ($success): ?>

                    <div class="alert alert-success">

                        <?= htmlspecialchars($success) ?>

                    </div>

                <?php endif; ?>


                <div class="card shadow-sm">

                    <div class="card-body">

                        <form
                            method="POST"
                            enctype="multipart/form-data">

                            <div class="row">
                                <div class="col-md-6 mb-3">

                                    <label class="form-label">
                                        SKU
                                    </label>

                                    <input
                                        type="text"
                                        name="sku"
                                        class="form-control"
                                        value="<?= htmlspecialchars($product['sku'] ?? '') ?>"
                                        required>

                                </div>
                                <div class="col-md-6 mb-3">

                                    <label class="form-label">
                                        Product Title
                                    </label>

                                    <input
                                        type="text"
                                        name="title"
                                        class="form-control"
                                        value="<?= htmlspecialchars($product['title'] ?? '') ?>"
                                        required>

                                </div>
                                <div class="col-md-6 mb-3">

                                    <label class="form-label">
                                        Category
                                    </label>

                                    <select
                                        name="category_id"
                                        class="form-select"
                                        required>

                                        <option value="">
                                            Select Category
                                        </option>

                                        <?php foreach ($categories as $category): ?>

                                            <option
                                                value="<?= (int) $category['id'] ?>"
                                                <?= ((int) $product['category_id'] === (int) $category['id']) ? 'selected' : '' ?>>

                                                <?= htmlspecialchars($category['name']) ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>
                                <div class="col-md-6 mb-3">

                                    <label class="form-label">
                                        Brand
                                    </label>

                                    <select
                                        name="brand_id"
                                        class="form-select"
                                        required>

                                        <option value="">
                                            Select Brand
                                        </option>

                                        <?php foreach ($brands as $brand): ?>

                                            <option
                                                value="<?= (int) $brand['id'] ?>"
                                                <?= ((int) $product['brand_id'] === (int) $brand['id']) ? 'selected' : '' ?>>

                                                <?= htmlspecialchars($brand['name']) ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>
                                <div class="col-md-4 mb-3">

                                    <label class="form-label">
                                        Selling Price
                                    </label>

                                    <input
                                        type="number"
                                        name="price"
                                        class="form-control"
                                        step="0.01"
                                        min="0"
                                        value="<?= htmlspecialchars($product['price'] ?? '') ?>"
                                        required>

                                </div>
                                <div class="col-md-4 mb-3">

                                    <label class="form-label">
                                        Original Price
                                    </label>

                                    <input
                                        type="number"
                                        name="original_price"
                                        class="form-control"
                                        step="0.01"
                                        min="0"
                                        value="<?= htmlspecialchars($product['original_price'] ?? '') ?>"
                                        required>

                                </div>

                                <div class="col-md-4 mb-3">

                                    <label class="form-label">
                                        Stock
                                    </label>

                                    <input
                                        type="number"
                                        name="stock"
                                        class="form-control"
                                        min="0"
                                        value="<?= htmlspecialchars($product['stock'] ?? 0) ?>"
                                        required>

                                </div>
                                <div class="col-md-6 mb-3">

                                    <label class="form-label">
                                        Current Image
                                    </label>

                                    <div>

                                        <?php if (!empty($product['image'])): ?>

                                            <img
                                                src="../<?= htmlspecialchars($product['image']) ?>"
                                                alt="Product"
                                                style="width: 120px; height: 120px; object-fit: cover;"
                                                class="rounded border">

                                        <?php else: ?>

                                            <p class="text-muted">
                                                No image available
                                            </p>

                                        <?php endif; ?>

                                    </div>

                                </div>

                                <div class="col-md-6 mb-3">

                                    <label class="form-label">
                                        Replace Image
                                    </label>

                                    <input
                                        type="file"
                                        name="image"
                                        class="form-control"
                                        accept=".jpg,.jpeg,.png,.webp">

                                    <small class="text-muted">
                                        JPG, JPEG, PNG or WEBP. Maximum 5 MB.
                                    </small>

                                </div>
                                <div class="col-md-12 mb-3">

                                    <label class="form-label">
                                        Description
                                    </label>

                                    <textarea name="description" class="form-control" rows="6">

                                    <?= htmlspecialchars($product['description'] ?? '') ?></textarea>

                                </div>
                                <div class="col-md-12 mb-3">

                                    <label class="form-label">
                                        Current Status
                                    </label>

                                    <div>

                                        <?php if ((int) $product['status'] === 1): ?>

                                            <span class="badge bg-success">
                                                Active
                                            </span>

                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">
                                                Pending Approval
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">
                                        Product status is controlled by the admin.
                                    </small>

                                </div>
                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-primary">Update Product</button>
                                    <a href="products.php" class="btn btn-secondary">Cancel</a>

                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>