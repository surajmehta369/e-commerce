<?php

require_once "auth.php";
require_once "../connection/dbconnect.php";
require_once "../shopify/functions.php";

$database = new Database();
$pdo = $database->connect();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: products.php");
    exit;
}

$productId = (int) $_GET['id'];

$message = '';
$messageType = '';

function createUniqueSlug(PDO $pdo, string $title, int $productId = 0): string
{
    $slug = strtolower(trim($title));

    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');

    if ($slug === '') {
        $slug = 'product';
    }

    $baseSlug = $slug;
    $counter = 1;

    while (true) {

        if ($productId > 0) {

            $stmt = $pdo->prepare("
                SELECT id
                FROM products
                WHERE slug = :slug
                AND id != :id
                LIMIT 1
            ");

            $stmt->execute([
                ':slug' => $slug,
                ':id'   => $productId
            ]);

        } else {

            $stmt = $pdo->prepare("
                SELECT id
                FROM products
                WHERE slug = :slug
                LIMIT 1
            ");

            $stmt->execute([
                ':slug' => $slug
            ]);
        }

        if (!$stmt->fetch()) {
            return $slug;
        }

        $counter++;
        $slug = $baseSlug . '-' . $counter;
    }
}

$stmt = $pdo->prepare("
    SELECT *
    FROM products
    WHERE id = :id
    LIMIT 1
");

$stmt->execute([
    ':id' => $productId
]);

$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header("Location: products.php");
    exit;
}
$categoryStmt = $pdo->query("
    SELECT id, name
    FROM categories
    WHERE status = 1
    ORDER BY name ASC
");

$categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

$brandStmt = $pdo->query("
    SELECT id, name
    FROM brands
    WHERE status = 1
    ORDER BY name ASC
");

$brands = $brandStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $sku = trim($_POST['sku'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $brandId = (int) ($_POST['brand_id'] ?? 0);

    $price = trim($_POST['price'] ?? '');
    $originalPrice = trim($_POST['original_price'] ?? '');
    $stock = trim($_POST['stock'] ?? '');

    $description = trim($_POST['description'] ?? '');
    $status = isset($_POST['status']) ? (int) $_POST['status'] : 0;

    if ($sku === '') {

        $message = "SKU is required.";
        $messageType = "danger";

    } elseif ($title === '') {

        $message = "Product title is required.";
        $messageType = "danger";

    } elseif ($price === '' || !is_numeric($price) || $price < 0) {

        $message = "Please enter a valid price.";
        $messageType = "danger";

    } elseif (
        $originalPrice !== '' &&
        (!is_numeric($originalPrice) || $originalPrice < 0)
    ) {

        $message = "Please enter a valid original price.";
        $messageType = "danger";

    } elseif (
        $stock === '' ||
        !is_numeric($stock) ||
        $stock < 0
    ) {

        $message = "Please enter a valid stock quantity.";
        $messageType = "danger";

    } elseif ($categoryId <= 0) {

        $message = "Please select a category.";
        $messageType = "danger";

    } elseif ($brandId <= 0) {

        $message = "Please select a brand.";
        $messageType = "danger";

    } elseif (!in_array($status, [0, 1], true)) {

        $message = "Invalid product status.";
        $messageType = "danger";
    }
    if ($message === '') {

        $skuStmt = $pdo->prepare("
            SELECT id
            FROM products
            WHERE sku = :sku
            AND id != :id
            LIMIT 1
        ");

        $skuStmt->execute([
            ':sku' => $sku,
            ':id'  => $productId
        ]);

        if ($skuStmt->fetch()) {

            $message = "This SKU already exists.";
            $messageType = "danger";
        }
    }
    $discount = 0;

    if (
        $originalPrice !== '' &&
        (float) $originalPrice > 0 &&
        (float) $originalPrice > (float) $price
    ) {

        $discount = round(
            (
                ((float) $originalPrice - (float) $price)
                / (float) $originalPrice
            ) * 100,
            2
        );
    }
    $imageName = $product['image'];

    if ($message === '') {

        if (
            isset($_FILES['image']) &&
            $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE
        ) {

            if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {

                $message = "There was an error uploading the image.";
                $messageType = "danger";

            } else {

                $allowedTypes = [
                    'image/jpeg',
                    'image/png',
                    'image/webp'
                ];

                $fileType = mime_content_type(
                    $_FILES['image']['tmp_name']
                );

                if (!in_array($fileType, $allowedTypes, true)) {

                    $message = "Only JPG, PNG and WEBP images are allowed.";
                    $messageType = "danger";

                } elseif ($_FILES['image']['size'] > 5 * 1024 * 1024) {

                    $message = "Image size must not exceed 5MB.";
                    $messageType = "danger";

                } else {

                    $extension = strtolower(
                        pathinfo(
                            $_FILES['image']['name'],
                            PATHINFO_EXTENSION
                        )
                    );

                    $fileName = uniqid(
                        'product_',
                        true
                    ) . '.' . $extension;

                    $uploadDirectory = "../assets/uploads/";

                    if (!is_dir($uploadDirectory)) {
                        mkdir($uploadDirectory, 0755, true);
                    }

                    $uploadPath = $uploadDirectory . $fileName;

                    if (
                        !move_uploaded_file(
                            $_FILES['image']['tmp_name'],
                            $uploadPath
                        )
                    ) {

                        $message = "Failed to upload product image.";
                        $messageType = "danger";

                    } else {

                        $imageName = "assets/uploads/" . $fileName;
                    }
                }
            }
        }
    }
if ($message === '') {

    try {

        $pdo->beginTransaction();

        $slug = createUniqueSlug(
            $pdo,
            $title,
            $productId
        );
        $updateStmt = $pdo->prepare("
            UPDATE products
            SET
                sku = :sku,
                title = :title,
                slug = :slug,
                description = :description,
                image = :image,
                price = :price,
                original_price = :original_price,
                discount = :discount,
                stock = :stock,
                category_id = :category_id,
                brand_id = :brand_id,
                status = :status
            WHERE id = :id
        ");

        $updateStmt->execute([
            ':sku'            => $sku,
            ':title'          => $title,
            ':slug'           => $slug,
            ':description'    => $description,
            ':image'          => $imageName,
            ':price'          => $price,
            ':original_price' => $originalPrice !== ''
                ? $originalPrice
                : null,
            ':discount'       => $discount,
            ':stock'          => $stock,
            ':category_id'    => $categoryId,
            ':brand_id'       => $brandId,
            ':status'         => $status,
            ':id'             => $productId
        ]);

        $pdo->commit();

        if (
            isset($_FILES['image']) &&
            $_FILES['image']['error'] === UPLOAD_ERR_OK &&
            !empty($product['image']) &&
            $product['image'] !== $imageName
        ) {

            $oldImagePath = "../" . $product['image'];

            if (
                file_exists($oldImagePath) &&
                is_file($oldImagePath)
            ) {
                @unlink($oldImagePath);
            }
        }
        $stmt = $pdo->prepare("
            SELECT *
            FROM products
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $productId
        ]);

        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        $shopifyProductId =
            trim($product['shopify_product_id'] ?? '');

        if ($shopifyProductId !== '') {

            $shopifyProductResult =
                updateShopifyProduct(
                    $shopifyProductId,
                    [
                        'title'       => $title,
                        'description' => $description,
                        'slug'        => $slug,
                        'status'      => $status
                    ]
                );

            $shopifyVariantResult =
                updateShopifyProductVariant(
                    $shopifyProductId,
                    $price,
                    $originalPrice,
                    $sku
                );

            $shopifyInventoryResult =
                updateShopifyInventory(
                    $shopifyProductId,
                    $stock
                );

            $shopifyProductSuccess =
                !empty($shopifyProductResult['success']);

            $shopifyVariantSuccess =
                !empty($shopifyVariantResult['success']);

            $shopifyInventorySuccess =
                !empty($shopifyInventoryResult['success']);

            if (
                $shopifyProductSuccess &&
                $shopifyVariantSuccess &&
                $shopifyInventorySuccess
            ) {

                $syncStmt = $pdo->prepare("
                    UPDATE products
                    SET
                        shopify_status = :shopify_status,
                        shopify_synced_at = NOW()
                    WHERE id = :id
                ");

                $syncStmt->execute([
                    ':shopify_status' =>
                        $status == 1
                            ? 'ACTIVE'
                            : 'DRAFT',
                    ':id' => $productId
                ]);

                $message =
                    "Product updated successfully and synced with Shopify.";

                $messageType = "success";

            } else {

                $failedParts = [];

                if (!$shopifyProductSuccess) {
                    $failedParts[] = "product";
                }

                if (!$shopifyVariantSuccess) {
                    $failedParts[] = "price/SKU";
                }

                if (!$shopifyInventorySuccess) {
                    $failedParts[] = "inventory";
                }

                $message =
                    "Product updated locally, but Shopify sync failed for: "
                    . implode(', ', $failedParts)
                    . ".";

                $messageType = "warning";
            }

        } else {
            $message =
                "Product updated successfully. "
                . "No Shopify product is linked.";

            $messageType = "warning";
        }


    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $message =
            "Something went wrong while updating the product.";

        $messageType = "danger";
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
        content="width=device-width, initial-scale=1.0"
    >

    <title>Edit Product - Admin</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <style>

        body {
            background: #f5f7fb;
        }

        .sidebar {
            min-height: 100vh;
            background: #212529;
        }

        .sidebar .brand {
            font-size: 20px;
            font-weight: 600;
            color: #fff;
            padding: 20px;
            display: block;
            text-decoration: none;
        }

        .sidebar a {
            color: #adb5bd;
            text-decoration: none;
            display: block;
            padding: 11px 20px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #343a40;
            color: #fff;
        }

        .main-content {
            padding: 30px;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        }

        .form-label {
            font-weight: 500;
        }

        .current-image {
            width: 110px;
            height: 110px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid #dee2e6;
        }

    </style>

</head>

<body>

<div class="container-fluid">

    <div class="row">
        <div class="col-md-2 px-0 sidebar">

            <a href="index.php" class="brand">
                <i class="bi bi-speedometer2 me-2"></i>
                Admin Panel
            </a>

            <a href="index.php">
                <i class="bi bi-grid me-2"></i>
                Dashboard
            </a>

            <a href="vendors.php">
                <i class="bi bi-shop me-2"></i>
                Vendors
            </a>

            <a href="products.php" class="active">
                <i class="bi bi-box-seam me-2"></i>
                Products
            </a>

            <a href="customers.php">
                <i class="bi bi-people me-2"></i>
                Customers
            </a>

            <a href="categories.php">
                <i class="bi bi-folder me-2"></i>
                Categories
            </a>

            <a href="brands.php">
                <i class="bi bi-tags me-2"></i>
                Brands
            </a>

            <a href="orders.php">
                <i class="bi bi-cart-check me-2"></i>
                Orders
            </a>

            <a href="profile.php">
                <i class="bi bi-person-circle me-2"></i>
                Admin Profile
            </a>

            <a href="../logout.php">
                <i class="bi bi-box-arrow-right me-2"></i>
                Logout
            </a>

        </div>
        <div class="col-md-10 main-content">

            <div class="d-flex justify-content-between align-items-center mb-4">

                <div>
                    <h2 class="mb-1">Edit Product</h2>

                    <p class="text-muted mb-0">
                        Update product information
                    </p>
                </div>

                <a
                    href="products.php"
                    class="btn btn-outline-secondary"
                >
                    <i class="bi bi-arrow-left me-1"></i>
                    Back to Products
                </a>

            </div>


            <?php if ($message !== ''): ?>

                <div
                    class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show"
                    role="alert"
                >

                    <?= htmlspecialchars($message) ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                    ></button>

                </div>

            <?php endif; ?>


            <div class="card">

                <div class="card-body p-4">

                    <form
                        method="POST"
                        enctype="multipart/form-data"
                    >

                        <div class="row g-4">

                            <!-- SKU -->
                            <div class="col-md-6">

                                <label class="form-label">
                                    SKU
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="sku"
                                    class="form-control"
                                    value="<?= htmlspecialchars($product['sku'] ?? '') ?>"
                                    required
                                >

                            </div>
                            <div class="col-md-6">

                                <label class="form-label">
                                    Product Title
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="title"
                                    class="form-control"
                                    value="<?= htmlspecialchars($product['title'] ?? '') ?>"
                                    required
                                >

                            </div>
                            <div class="col-md-6">

                                <label class="form-label">
                                    Category
                                    <span class="text-danger">*</span>
                                </label>

                                <select
                                    name="category_id"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Select Category
                                    </option>

                                    <?php foreach ($categories as $category): ?>

                                        <option
                                            value="<?= (int) $category['id'] ?>"
                                            <?= ((int) $product['category_id'] === (int) $category['id']) ? 'selected' : '' ?>
                                        >
                                            <?= htmlspecialchars($category['name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="col-md-6">

                                <label class="form-label">
                                    Brand
                                    <span class="text-danger">*</span>
                                </label>

                                <select
                                    name="brand_id"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Select Brand
                                    </option>

                                    <?php foreach ($brands as $brand): ?>

                                        <option
                                            value="<?= (int) $brand['id'] ?>"
                                            <?= ((int) $product['brand_id'] === (int) $brand['id']) ? 'selected' : '' ?>
                                        >
                                            <?= htmlspecialchars($brand['name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>
                            <div class="col-md-4">

                                <label class="form-label">
                                    Price
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="number"
                                    name="price"
                                    class="form-control"
                                    min="0"
                                    step="0.01"
                                    value="<?= htmlspecialchars($product['price'] ?? '') ?>"
                                    required
                                >

                            </div>
                            <div class="col-md-4">

                                <label class="form-label">
                                    Original Price
                                </label>

                                <input
                                    type="number"
                                    name="original_price"
                                    class="form-control"
                                    min="0"
                                    step="0.01"
                                    value="<?= htmlspecialchars($product['original_price'] ?? '') ?>"
                                >

                                <small class="text-muted">
                                    Discount is calculated automatically.
                                </small>

                            </div>
                            <div class="col-md-4">

                                <label class="form-label">
                                    Stock
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="number"
                                    name="stock"
                                    class="form-control"
                                    min="0"
                                    value="<?= htmlspecialchars($product['stock'] ?? '') ?>"
                                    required
                                >

                            </div>
                            <div class="col-md-12">

                                <label class="form-label">
                                    Description
                                </label>

                                <textarea
                                    name="description"
                                    class="form-control"
                                    rows="5"
                                ><?= htmlspecialchars($product['description'] ?? '') ?></textarea>

                            </div>
                            <div class="col-md-6">

                                <label class="form-label d-block">
                                    Current Image
                                </label>

                                <?php if (!empty($product['image'])): ?>

                                    <?php
                                    $currentImage = $product['image'];
                                    if (
                                        strpos($currentImage, 'assets/uploads/') !== 0
                                    ) {
                                        $currentImage =
                                            'assets/uploads/' . $currentImage;
                                    }
                                    ?>

                                    <img
                                        src="../<?= htmlspecialchars($currentImage) ?>"
                                        alt="Product Image"
                                        class="current-image"
                                    >

                                <?php else: ?>

                                    <div class="text-muted">
                                        No image available.
                                    </div>

                                <?php endif; ?>

                            </div>

                            <div class="col-md-6">

                                <label class="form-label">
                                    Replace Image
                                </label>

                                <input
                                    type="file"
                                    name="image"
                                    class="form-control"
                                    accept=".jpg,.jpeg,.png,.webp"
                                >

                                <small class="text-muted">
                                    JPG, PNG or WEBP. Maximum 5MB.
                                </small>

                            </div>
                            <div class="col-md-6">

                                <label class="form-label">
                                    Product Status
                                </label>

                                <select
                                    name="status"
                                    class="form-select"
                                >

                                    <option
                                        value="1"
                                        <?= ((int) $product['status'] === 1) ? 'selected' : '' ?>
                                    >
                                        Active / Approved
                                    </option>

                                    <option
                                        value="0"
                                        <?= ((int) $product['status'] === 0) ? 'selected' : '' ?>
                                    >
                                        Pending / Inactive
                                    </option>

                                </select>

                            </div>

                            <div class="col-md-6">

                                <label class="form-label">
                                    Product ID
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    value="<?= (int) $product['id'] ?>"
                                    readonly
                                >

                            </div>

                        </div>


                        <hr class="my-4">


                        <div class="d-flex justify-content-end gap-2">

                            <a
                                href="products.php"
                                class="btn btn-light border"
                            >
                                Cancel
                            </a>

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                <i class="bi bi-check-circle me-1"></i>
                                Update Product
                            </button>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>