<?php

require_once "auth.php";
require_once "../connection/dbconnect.php";
require_once "../shopify/functions.php";

$database = new Database();
$db = $database->connect();

$message = "";
$messageType = "";

function createUniqueSlug($db, $title)
{
    $slug = strtolower(trim($title));

    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');

    if ($slug === '') {
        $slug = 'product';
    }

    $baseSlug = $slug;
    $counter = 1;

    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM products
        WHERE slug = :slug
    ");

    while (true) {

        $stmt->execute([
            ':slug' => $slug
        ]);

        if ((int) $stmt->fetchColumn() === 0) {
            break;
        }

        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }

    return $slug;
}

$categoryStmt = $db->prepare("
    SELECT id, name, parent_id
    FROM categories
    WHERE status = 1
    ORDER BY name ASC
");

$categoryStmt->execute();
$categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
$brandStmt = $db->prepare("
    SELECT id, name
    FROM brands
    WHERE status = 1
    ORDER BY name ASC
");

$brandStmt->execute();
$brands = $brandStmt->fetchAll(PDO::FETCH_ASSOC);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $sku           = trim($_POST['sku'] ?? '');
    $title         = trim($_POST['title'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $price         = trim($_POST['price'] ?? '');
    $originalPrice = trim($_POST['original_price'] ?? '');
    $stock         = trim($_POST['stock'] ?? '');
    $categoryId    = (int) ($_POST['category_id'] ?? 0);
    $brandId       = (int) ($_POST['brand_id'] ?? 0);
    $status        = isset($_POST['status']) ? (int) $_POST['status'] : 1;

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
    } elseif ($stock === '' || !is_numeric($stock) || $stock < 0) {
        $message = "Please enter a valid stock quantity.";
        $messageType = "danger";
    } elseif ($categoryId <= 0) {
        $message = "Please select a category.";
        $messageType = "danger";
    } elseif ($brandId <= 0) {
        $message = "Please select a brand.";
        $messageType = "danger";
    } else {

        $skuStmt = $db->prepare("
            SELECT id
            FROM products
            WHERE sku = :sku
            LIMIT 1
        ");

        $skuStmt->execute([
            ':sku' => $sku
        ]);

        if ($skuStmt->fetch()) {

            $message = "This SKU already exists.";
            $messageType = "danger";
        } else {

            $priceValue = (float) $price;

            $originalPriceValue = $originalPrice !== ''
                ? (float) $originalPrice
                : $priceValue;

            $discount = 0;

            if ($originalPriceValue > 0 && $originalPriceValue > $priceValue) {

                $discount = round(
                    (($originalPriceValue - $priceValue) / $originalPriceValue) * 100,
                    2
                );
            }

            $slug = createUniqueSlug($db, $title);
            /*
|--------------------------------------------------------------------------
| Image Upload
|--------------------------------------------------------------------------
*/

            $imageName = null;

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

                    $fileType = mime_content_type($_FILES['image']['tmp_name']);

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

                        $fileName = uniqid('product_', true) . '.' . $extension;

                        $uploadDirectory = "../assets/uploads/";

                        if (!is_dir($uploadDirectory)) {
                            mkdir($uploadDirectory, 0755, true);
                        }

                        $uploadPath = $uploadDirectory . $fileName;

                        if (!move_uploaded_file(
                            $_FILES['image']['tmp_name'],
                            $uploadPath
                        )) {

                            $message = "Failed to upload product image.";
                            $messageType = "danger";
                        } else {

                            // Store relative path in database
                            $imageName = "assets/uploads/" . $fileName;
                        }
                    }
                }
            }

            if ($message === "") {

                try {

                    $stmt = $db->prepare("
                        INSERT INTO products
                        (
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
                            vendor_id
                        )
                        VALUES
                        (
                            :sku,
                            :title,
                            :slug,
                            :description,
                            :image,
                            :price,
                            :original_price,
                            :discount,
                            :stock,
                            :category_id,
                            :brand_id,
                            :status,
                            NULL
                        )
                    ");

                    $stmt->execute([
                        ':sku'            => $sku,
                        ':title'          => $title,
                        ':slug'           => $slug,
                        ':description'   => $description,
                        ':image'          => $imageName,
                        ':price'          => $priceValue,
                        ':original_price' => $originalPriceValue,
                        ':discount'       => $discount,
                        ':stock'          => (int) $stock,
                        ':category_id'    => $categoryId,
                        ':brand_id'       => $brandId,
                        ':status'         => $status
                    ]);
                    $localProductId = (int) $db->lastInsertId();
                    $shopifyResult = createShopifyProduct([
                        'title'       => $title,
                        'description' => $description,
                        'slug'        => $slug,
                        'status'      => $status
                    ]);


                    if (!empty($shopifyResult['success'])) {

                        $shopifyProductId = $shopifyResult['data']['id'] ?? null;

                        if (!$shopifyProductId) {

                            $message = "Shopify product was created, but Shopify Product ID was not returned.";
                            $messageType = "warning";
                        } else {

                            $shopifyUpdate = $db->prepare("
            UPDATE products
            SET
                shopify_product_id = :shopify_product_id,
                shopify_status = :shopify_status,
                shopify_synced_at = NOW()
            WHERE id = :id
        ");

                            $shopifyUpdate->execute([
                                ':shopify_product_id' => $shopifyProductId,
                                ':shopify_status'     => $status ? 'ACTIVE' : 'DRAFT',
                                ':id'                 => $localProductId
                            ]);

                            $variantResult = updateShopifyProductVariant(
                                $shopifyProductId,
                                $price,
                                $originalPrice,
                                $sku
                            );

                            $inventoryResult = updateShopifyInventory(
                                $shopifyProductId,
                                (int) $stock
                            );


                            $variantSuccess =
                                !empty($variantResult['success']);

                            $inventorySuccess =
                                !empty($inventoryResult['success']);


                            if ($variantSuccess && $inventorySuccess) {

                                $message =
                                    "Product created successfully and fully synced to Shopify.";

                                $messageType = "success";
                            } elseif ($variantSuccess) {

                                $message =
                                    "Product created and variant synced, but inventory sync failed.";

                                $messageType = "warning";
                            } elseif ($inventorySuccess) {

                                $message =
                                    "Product created and inventory synced, but variant sync failed.";

                                $messageType = "warning";
                            } else {

                                $message =
                                    "Product created in Shopify, but price, SKU and inventory synchronization failed.";

                                $messageType = "warning";
                            }
                        }
                    } else {

                        $message =
                            "Product created locally, but Shopify product creation failed.";

                        $messageType = "warning";
                    }

                    $sku = "";
                    $title = "";
                    $description = "";
                    $price = "";
                    $originalPrice = "";
                    $stock = "";
                    $categoryId = 0;
                    $brandId = 0;
                    $status = 1;
                } catch (PDOException $e) {

                    if ($imageName) {

                        $uploadedFile = "../assets/uploads/" . $imageName;

                        if (file_exists($uploadedFile)) {
                            unlink($uploadedFile);
                        }
                    }

                    $message = "Unable to create product. Please try again.";
                    $messageType = "danger";
                }
            }
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

    <title>Add Product | Admin</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        body {
            background: #f5f7fb;
        }

        .sidebar {
            min-height: 100vh;
            background: #111827;
        }

        .sidebar .brand {
            color: #fff;
            font-size: 21px;
            font-weight: 700;
            padding: 22px 20px;
            display: block;
            text-decoration: none;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }

        .sidebar .nav-link {
            color: #9ca3af;
            padding: 12px 20px;
            margin: 3px 10px;
            border-radius: 8px;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: #1f2937;
            color: #fff;
        }

        .main-content {
            padding: 30px;
        }

        .page-title {
            font-weight: 700;
            color: #111827;
        }

        .card {
            border: 0;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, .05);
        }

        .form-label {
            font-weight: 600;
            color: #374151;
        }

        .form-control,
        .form-select {
            min-height: 46px;
            border-radius: 8px;
        }

        textarea.form-control {
            min-height: 130px;
        }

        .required {
            color: #dc2626;
        }

        .image-note {
            font-size: 13px;
            color: #6b7280;
        }
    </style>

</head>

<body>

    <div class="container-fluid">

        <div class="row">
            <div class="col-md-3 col-lg-2 px-0 sidebar">

                <a
                    href="index.php"
                    class="brand">
                    Admin Panel
                </a>

                <nav class="nav flex-column mt-3">

                    <a
                        href="index.php"
                        class="nav-link">
                        <i class="bi bi-speedometer2 me-2"></i>
                        Dashboard
                    </a>

                    <a
                        href="vendors.php"
                        class="nav-link">
                        <i class="bi bi-shop me-2"></i>
                        Vendors
                    </a>

                    <a
                        href="products.php"
                        class="nav-link active">
                        <i class="bi bi-box-seam me-2"></i>
                        Products
                    </a>

                    <a
                        href="customers.php"
                        class="nav-link">
                        <i class="bi bi-people me-2"></i>
                        Customers
                    </a>

                    <a
                        href="categories.php"
                        class="nav-link">
                        <i class="bi bi-grid me-2"></i>
                        Categories
                    </a>

                    <a
                        href="brands.php"
                        class="nav-link">
                        <i class="bi bi-tags me-2"></i>
                        Brands
                    </a>

                    <a
                        href="orders.php"
                        class="nav-link">
                        <i class="bi bi-cart-check me-2"></i>
                        Orders
                    </a>

                    <a
                        href="profile.php"
                        class="nav-link">
                        <i class="bi bi-person-circle me-2"></i>
                        Admin Profile
                    </a>

                    <a
                        href="../logout.php"
                        class="nav-link text-danger mt-3">
                        <i class="bi bi-box-arrow-right me-2"></i>
                        Logout
                    </a>

                </nav>

            </div>
            <div class="col-md-9 col-lg-10 main-content">

                <div class="d-flex justify-content-between align-items-center mb-4">

                    <div>

                        <h2 class="page-title mb-1">
                            Add Product
                        </h2>

                        <p class="text-muted mb-0">
                            Create a new product for your store.
                        </p>

                    </div>

                    <a
                        href="products.php"
                        class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>
                        Back to Products
                    </a>

                </div>


                <?php if ($message !== ""): ?>

                    <div
                        class="alert alert-<?= htmlspecialchars($messageType); ?> alert-dismissible fade show"
                        role="alert">

                        <?= htmlspecialchars($message); ?>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="alert"></button>

                    </div>

                <?php endif; ?>


                <form
                    method="POST"
                    enctype="multipart/form-data">

                    <div class="card">

                        <div class="card-body p-4">

                            <div class="row g-4">
                                <div class="col-md-6">

                                    <label class="form-label">
                                        SKU <span class="required">*</span>
                                    </label>

                                    <input
                                        type="text"
                                        name="sku"
                                        class="form-control"
                                        value="<?= htmlspecialchars($sku ?? ''); ?>"
                                        placeholder="e.g. PROD-001"
                                        required>

                                </div>
                                <div class="col-md-6">

                                    <label class="form-label">
                                        Product Title <span class="required">*</span>
                                    </label>

                                    <input
                                        type="text"
                                        name="title"
                                        class="form-control"
                                        value="<?= htmlspecialchars($title ?? ''); ?>"
                                        placeholder="Enter product title"
                                        required>

                                </div>
                                <div class="col-md-6">

                                    <label class="form-label">
                                        Category <span class="required">*</span>
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
                                                value="<?= (int) $category['id']; ?>"
                                                <?= (($categoryId ?? 0) == $category['id']) ? 'selected' : ''; ?>>

                                                <?= htmlspecialchars($category['name']); ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>
                                <div class="col-md-6">

                                    <label class="form-label">
                                        Brand <span class="required">*</span>
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
                                                value="<?= (int) $brand['id']; ?>"
                                                <?= (($brandId ?? 0) == $brand['id']) ? 'selected' : ''; ?>>

                                                <?= htmlspecialchars($brand['name']); ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>
                                <div class="col-md-4">

                                    <label class="form-label">
                                        Selling Price <span class="required">*</span>
                                    </label>

                                    <input
                                        type="number"
                                        name="price"
                                        class="form-control"
                                        step="0.01"
                                        min="0"
                                        value="<?= htmlspecialchars($price ?? ''); ?>"
                                        placeholder="0.00"
                                        required>

                                </div>
                                <div class="col-md-4">

                                    <label class="form-label">
                                        Original Price
                                    </label>

                                    <input
                                        type="number"
                                        name="original_price"
                                        class="form-control"
                                        step="0.01"
                                        min="0"
                                        value="<?= htmlspecialchars($originalPrice ?? ''); ?>"
                                        placeholder="0.00">

                                    <div class="image-note mt-1">
                                        Discount is calculated automatically.
                                    </div>

                                </div>
                                <div class="col-md-4">

                                    <label class="form-label">
                                        Stock <span class="required">*</span>
                                    </label>

                                    <input
                                        type="number"
                                        name="stock"
                                        class="form-control"
                                        min="0"
                                        value="<?= htmlspecialchars($stock ?? ''); ?>"
                                        placeholder="0"
                                        required>

                                </div>

                                <div class="col-12">

                                    <label class="form-label">
                                        Description
                                    </label>

                                    <textarea
                                        name="description"
                                        class="form-control"
                                        placeholder="Enter product description"><?= htmlspecialchars($description ?? ''); ?></textarea>

                                </div>
                                <div class="col-md-8">

                                    <label class="form-label">
                                        Product Image
                                    </label>

                                    <input
                                        type="file"
                                        name="image"
                                        class="form-control"
                                        accept=".jpg,.jpeg,.png,.webp">

                                    <div class="image-note mt-1">
                                        JPG, PNG or WEBP. Maximum size: 5MB.
                                    </div>

                                </div>
                                <div class="col-md-4">

                                    <label class="form-label">
                                        Status
                                    </label>

                                    <select
                                        name="status"
                                        class="form-select">

                                        <option
                                            value="1"
                                            <?= (($status ?? 1) == 1) ? 'selected' : ''; ?>>
                                            Active
                                        </option>

                                        <option
                                            value="0"
                                            <?= (($status ?? 1) == 0) ? 'selected' : ''; ?>>
                                            Inactive
                                        </option>

                                    </select>

                                </div>

                            </div>
                            <hr class="my-4">
                            <div class="d-flex justify-content-end gap-2">

                                <a
                                    href="products.php"
                                    class="btn btn-light border">
                                    Cancel
                                </a>

                                <button
                                    type="submit"
                                    class="btn btn-primary px-4">
                                    <i class="bi bi-plus-circle me-1"></i>
                                    Create Product
                                </button>

                            </div>

                        </div>

                    </div>

                </form>

            </div>

        </div>

    </div>
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>