<?php

require_once "auth.php";
require_once "../connection/dbconnect.php";
require_once "../shopify/functions.php";
require_once "../zoho/zoho_functions.php";

$database = new Database();
$db = $database->connect();

// TEMPORARY: Enable JSON testing without saving products.
$testStockJson = isset($_GET['test_stock']) &&
    $_GET['test_stock'] === '1';

$message = "";
$messageType = "";

$sku = "";
$title = "";
$description = "";
$price = "";
$originalPrice = "";
$stock = "";
$categoryId = 0;
$brandId = 0;
$status = 1;

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

    $sku = trim($_POST['sku'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = trim($_POST['price'] ?? '');
    $originalPrice = trim($_POST['original_price'] ?? '');
    $stock = trim($_POST['stock'] ?? '');

    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $brandId = (int) ($_POST['brand_id'] ?? 0);

    $status = isset($_POST['status'])
        ? (int) $_POST['status']
        : 1;

    $uploadedPaths = [];
    $uploadedVariantPaths = [];
    $imageName = null;
    $variantsToSave = [];


    try {

        if ($sku === '' || strlen($sku) > 100) {
            throw new RuntimeException(
                "Please enter a valid SKU (maximum 100 characters)."
            );
        }

        if ($title === '') {
            throw new RuntimeException(
                "Product title is required."
            );
        }

        if (
            $price === '' ||
            !is_numeric($price) ||
            !is_finite((float) $price) ||
            (float) $price < 0 ||
            (float) $price > 99999999.99
        ) {
            throw new RuntimeException(
                "Please enter a valid selling price."
            );
        }

        if (
            $originalPrice !== '' &&
            (
                !is_numeric($originalPrice) ||
                !is_finite((float) $originalPrice) ||
                (float) $originalPrice < 0 ||
                (float) $originalPrice > 99999999.99
            )
        ) {
            throw new RuntimeException(
                "Please enter a valid original price."
            );
        }
        if ($categoryId <= 0) {
            throw new RuntimeException(
                "Please select a category."
            );
        }

        if ($brandId <= 0) {
            throw new RuntimeException(
                "Please select a brand."
            );
        }

        if (!in_array($status, [0, 1], true)) {
            throw new RuntimeException(
                "Please select a valid product status."
            );
        }

        $priceValue = round((float) $price, 2);

        $originalPriceValue = $originalPrice !== ''
            ? round((float) $originalPrice, 2)
            : $priceValue;

        $stockValue = (int) $stock;

        $discount = 0;

        if (
            $originalPriceValue > 0 &&
            $originalPriceValue > $priceValue
        ) {
            $discount = round(
                (
                    ($originalPriceValue - $priceValue)
                    / $originalPriceValue
                ) * 100,
                2
            );
        }

        $skuStmt = $db->prepare("
            SELECT id
            FROM products
            WHERE sku = :sku

            UNION ALL

            SELECT id
            FROM product_variants
            WHERE sku = :variant_sku

            LIMIT 1
        ");

        $skuStmt->execute([
            ':sku' => $sku,
            ':variant_sku' => $sku
        ]);

        if ($skuStmt->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException(
                "This SKU already exists in a product or variant."
            );
        }


        $submittedVariants = $_POST['variants'] ?? [];

        if (!is_array($submittedVariants)) {
            throw new RuntimeException(
                "Invalid variant information."
            );
        }

        $seenVariantSkus = [strtolower($sku) => true];

        foreach ($submittedVariants as $formIndex => $variant) {

            if (!is_array($variant)) {
                continue;
            }

            $variantTitle = trim($variant['title'] ?? '');
            $variantSku = trim($variant['sku'] ?? '');
            $variantPrice = trim($variant['price'] ?? '');
            $variantOriginal = trim(
                $variant['original_price'] ?? ''
            );
            $variantStock = trim($variant['stock'] ?? '');
            $attributeText = trim($variant['attributes'] ?? '');
            if (
                $variantTitle === '' &&
                $variantSku === '' &&
                $variantPrice === '' &&
                $variantOriginal === '' &&
                $variantStock === '' &&
                $attributeText === ''
            ) {
                continue;
            }

            if (
                $variantTitle === '' ||
                strlen($variantTitle) > 255
            ) {
                throw new RuntimeException(
                    "Every variant needs a title of at most 255 characters."
                );
            }

            if (
                $variantSku === '' ||
                strlen($variantSku) > 100
            ) {
                throw new RuntimeException(
                    "Every variant needs a SKU of at most 100 characters."
                );
            }

            $normalizedSku = strtolower($variantSku);

            if (isset($seenVariantSkus[$normalizedSku])) {
                throw new RuntimeException(
                    "The main product SKU and variant SKUs must all be unique."
                );
            }

            $seenVariantSkus[$normalizedSku] = true;

            if (
                $variantPrice === '' ||
                !is_numeric($variantPrice) ||
                !is_finite((float) $variantPrice) ||
                (float) $variantPrice < 0 ||
                (float) $variantPrice > 99999999.99
            ) {
                throw new RuntimeException(
                    "Enter a valid selling price for every variant."
                );
            }

            if (
                $variantOriginal !== '' &&
                (
                    !is_numeric($variantOriginal) ||
                    !is_finite((float) $variantOriginal) ||
                    (float) $variantOriginal < 0 ||
                    (float) $variantOriginal > 99999999.99
                )
            ) {
                throw new RuntimeException(
                    "Enter a valid original price for every variant."
                );
            }

            if (
                $variantStock === '' ||
                filter_var(
                    $variantStock,
                    FILTER_VALIDATE_INT
                ) === false ||
                (int) $variantStock < 0
            ) {
                throw new RuntimeException(
                    "Enter a valid whole-number stock for every variant."
                );
            }
            $attributes = [];

            if ($attributeText !== '') {

                $attributeParts = preg_split(
                    '/[,;\r\n]+/',
                    $attributeText
                );

                foreach ($attributeParts as $part) {

                    $part = trim($part);

                    if ($part === '') {
                        continue;
                    }

                    $separator = strpos($part, ':');

                    if ($separator === false) {
                        $attributes['Options'] = $part;
                        continue;
                    }

                    $key = trim(substr($part, 0, $separator));
                    $value = trim(substr($part, $separator + 1));

                    if ($key !== '' && $value !== '') {
                        $attributes[$key] = $value;
                    }
                }
            }

            $variantsToSave[] = [
                'form_index' => (string) $formIndex,
                'title' => $variantTitle,
                'sku' => $variantSku,
                'price' => round((float) $variantPrice, 2),
                'original_price' => $variantOriginal !== ''
                    ? round((float) $variantOriginal, 2)
                    : null,
                'stock' => (int) $variantStock,
                'attributes' => $attributes
                    ? json_encode(
                        $attributes,
                        JSON_UNESCAPED_UNICODE
                    )
                    : null
            ];
        }


        if (!empty($variantsToSave)) {

            $stockValue = array_sum(
                array_column($variantsToSave, 'stock')
            );
        } else {

            if (
                $stock === '' ||
                filter_var($stock, FILTER_VALIDATE_INT) === false ||
                (int) $stock < 0
            ) {
                throw new RuntimeException(
                    "Please enter a valid whole-number stock quantity."
                );
            }

            $stockValue = (int) $stock;
        }

        // TEMPORARY JSON TEST: Do not save or sync anything.
        if ($testStockJson) {

            if (!empty($variantsToSave)) {
                $stockValue = array_sum(
                    array_column($variantsToSave, 'stock')
                );
            } else {
                if (
                    $stock === '' ||
                    filter_var($stock, FILTER_VALIDATE_INT) === false ||
                    (int) $stock < 0
                ) {
                    throw new RuntimeException(
                        "Please enter a valid whole-number stock quantity."
                    );
                }

                $stockValue = (int) $stock;
            }

            header('Content-Type: application/json; charset=utf-8');

            echo json_encode([
                'test_mode' => true,
                'database_saved' => false,
                'shopify_synced' => false,
                'zoho_synced' => false,
                'product' => [
                    'sku' => $sku,
                    'title' => $title,
                    'main_stock' => $stockValue
                ],
                'variants' => array_map(function ($variant) {
                    return [
                        'title' => $variant['title'],
                        'sku' => $variant['sku'],
                        'stock' => $variant['stock']
                    ];
                }, $variantsToSave),
                'variant_stock_total' => array_sum(
                    array_column($variantsToSave, 'stock')
                ),
                'stock_matches' => empty($variantsToSave)
                    ? true
                    : $stockValue === array_sum(
                        array_column($variantsToSave, 'stock')
                    )
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($variantsToSave) {

            $checkVariantSku = $db->prepare("
                SELECT id
                FROM products
                WHERE sku = :product_sku

                UNION ALL

                SELECT id
                FROM product_variants
                WHERE sku = :variant_sku

                LIMIT 1
            ");

            foreach ($variantsToSave as $variant) {

                $checkVariantSku->execute([
                    ':product_sku' => $variant['sku'],
                    ':variant_sku' => $variant['sku']
                ]);

                if ($checkVariantSku->fetch(PDO::FETCH_ASSOC)) {
                    throw new RuntimeException(
                        "Variant SKU already exists: " .
                            $variant['sku']
                    );
                }
            }
        }

        $imageFiles = [];

        if (
            isset($_FILES['images']) &&
            is_array($_FILES['images']['name'])
        ) {
            $fileCount = count($_FILES['images']['name']);

            for ($i = 0; $i < $fileCount; $i++) {

                $error = $_FILES['images']['error'][$i];

                if ($error === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                if ($error !== UPLOAD_ERR_OK) {
                    throw new RuntimeException(
                        "An image upload failed. Please check your PHP upload limits."
                    );
                }

                $imageFiles[] = [
                    'tmp_name' => $_FILES['images']['tmp_name'][$i],
                    'size' => (int) $_FILES['images']['size'][$i]
                ];
            }
        }

        $uploadDirectory = "../assets/uploads/";

        if (
            !is_dir($uploadDirectory) &&
            !mkdir($uploadDirectory, 0755, true) &&
            !is_dir($uploadDirectory)
        ) {
            throw new RuntimeException(
                "Could not create the image upload directory."
            );
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);

        $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];

        foreach ($imageFiles as $file) {

            if ($file['size'] > 5 * 1024 * 1024) {
                throw new RuntimeException(
                    "Each image must not exceed 5MB."
                );
            }

            if (!is_uploaded_file($file['tmp_name'])) {
                throw new RuntimeException(
                    "Invalid image upload."
                );
            }

            $mimeType = $finfo->file($file['tmp_name']);

            if (!isset($allowedMimeTypes[$mimeType])) {
                throw new RuntimeException(
                    "Only JPG, PNG and WEBP images are allowed."
                );
            }

            $extension = $allowedMimeTypes[$mimeType];

            $fileName = bin2hex(random_bytes(16))
                . '.'
                . $extension;

            $destination = $uploadDirectory . $fileName;

            if (
                !move_uploaded_file(
                    $file['tmp_name'],
                    $destination
                )
            ) {
                throw new RuntimeException(
                    "Failed to save an uploaded image."
                );
            }

            $relativePath = "assets/uploads/" . $fileName;

            $uploadedPaths[] = $relativePath;
        }
        if ($uploadedPaths) {
            $imageName = $uploadedPaths[0];
        }

        $slug = createUniqueSlug($db, $title);

        $db->beginTransaction();

        $insertProduct = $db->prepare("
            INSERT INTO products (
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
            ) VALUES (
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

        $insertProduct->execute([
            ':sku' => $sku,
            ':title' => $title,
            ':slug' => $slug,
            ':description' => $description,
            ':image' => $imageName,
            ':price' => $priceValue,
            ':original_price' => $originalPriceValue,
            ':discount' => $discount,
            ':stock' => $stockValue,
            ':category_id' => $categoryId,
            ':brand_id' => $brandId,
            ':status' => $status
        ]);

        $localProductId = (int) $db->lastInsertId();
        $insertImage = $db->prepare("
            INSERT INTO product_images (
                product_id,
                image_path,
                alt_text,
                sort_order,
                status
            ) VALUES (
                :product_id,
                :image_path,
                :alt_text,
                :sort_order,
                1
            )
        ");

        for ($i = 1; $i < count($uploadedPaths); $i++) {

            $insertImage->execute([
                ':product_id' => $localProductId,
                ':image_path' => $uploadedPaths[$i],
                ':alt_text' => $title,
                ':sort_order' => $i
            ]);
        }
        $insertVariant = $db->prepare("
            INSERT INTO product_variants (
                product_id,
                variant_title,
                sku,
                price,
                original_price,
                stock,
                attributes,
                status
            ) VALUES (
                :product_id,
                :variant_title,
                :sku,
                :price,
                :original_price,
                :stock,
                :attributes,
                1
            )
        ");


        $insertVariantImage = $db->prepare("
    INSERT INTO product_variant_images (
        variant_id,
        image_path,
        alt_text,
        sort_order,
        status
    ) VALUES (
        :variant_id,
        :image_path,
        :alt_text,
        :sort_order,
        1
    )
");

        $finfo = new finfo(FILEINFO_MIME_TYPE);

        $allowedVariantImageTypes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp'
        ];

        $variantUploadDirectory = "../assets/uploads/";

        foreach ($variantsToSave as $variant) {
            $insertVariant->execute([
                ':product_id' => $localProductId,
                ':variant_title' => $variant['title'],
                ':sku' => $variant['sku'],
                ':price' => $variant['price'],
                ':original_price' => $variant['original_price'],
                ':stock' => $variant['stock'],
                ':attributes' => $variant['attributes']
            ]);

            $variantId = (int) $db->lastInsertId();
            $formIndex = $variant['form_index'];
            if (
                !isset($_FILES['variant_images']['name'][$formIndex]) ||
                !is_array($_FILES['variant_images']['name'][$formIndex])
            ) {
                continue;
            }

            $files = $_FILES['variant_images'];
            $fileCount = count($files['name'][$formIndex]);

            for ($i = 0; $i < $fileCount; $i++) {

                $error = $files['error'][$formIndex][$i];

                if ($error === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                if ($error !== UPLOAD_ERR_OK) {
                    throw new RuntimeException(
                        "A variant image upload failed. Check PHP upload limits."
                    );
                }

                $tmpName = $files['tmp_name'][$formIndex][$i];
                $fileSize = (int) $files['size'][$formIndex][$i];

                if ($fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
                    throw new RuntimeException(
                        "Each variant image must be smaller than or equal to 5MB."
                    );
                }

                if (!is_uploaded_file($tmpName)) {
                    throw new RuntimeException(
                        "Invalid variant image upload."
                    );
                }

                $mimeType = $finfo->file($tmpName);

                if (!isset($allowedVariantImageTypes[$mimeType])) {
                    throw new RuntimeException(
                        "Variant images must be JPG, PNG, or WEBP."
                    );
                }

                $extension = $allowedVariantImageTypes[$mimeType];

                $fileName = bin2hex(random_bytes(16)) . '.' . $extension;

                $destination = $variantUploadDirectory . $fileName;

                if (!move_uploaded_file($tmpName, $destination)) {
                    throw new RuntimeException(
                        "Failed to save a variant image."
                    );
                }

                $relativePath = "assets/uploads/" . $fileName;

                $uploadedVariantPaths[] = $relativePath;

                $insertVariantImage->execute([
                    ':variant_id' => $variantId,
                    ':image_path' => $relativePath,
                    ':alt_text' => $variant['title'],
                    ':sort_order' => $i
                ]);
            }
        }


        $db->commit();
        $syncMessages = [];
        $syncWarning = false;

        try {

            $shopifyResult = createShopifyProduct([
                'title' => $title,
                'description' => $description,
                'slug' => $slug,
                'status' => $status
            ]);

            if (!empty($shopifyResult['success'])) {

                $shopifyProductId =
                    $shopifyResult['data']['id'] ?? null;

                if ($shopifyProductId) {

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
                        ':shopify_status' => $status ? 'ACTIVE' : 'DRAFT',
                        ':id' => $localProductId
                    ]);

                    $variantResult = updateShopifyProductVariant(
                        $shopifyProductId,
                        $priceValue,
                        $originalPriceValue,
                        $sku
                    );

                    $inventoryResult = updateShopifyInventory(
                        $shopifyProductId,
                        $stockValue
                    );

                    if (
                        !empty($variantResult['success']) &&
                        !empty($inventoryResult['success'])
                    ) {
                        $syncMessages[] =
                            "Parent product synced to Shopify.";
                    } else {
                        $syncMessages[] =
                            "Shopify product was created, but price/SKU or inventory sync needs checking.";
                        $syncWarning = true;
                    }
                } else {
                    $syncMessages[] =
                        "Shopify created a product but did not return its product ID.";
                    $syncWarning = true;
                }
            } else {
                $syncMessages[] =
                    "Shopify sync failed; the product is saved locally.";
                $syncWarning = true;
            }
        } catch (Throwable $e) {

            error_log("Shopify product sync error: " . $e->getMessage());

            $syncMessages[] =
                "Shopify sync encountered an error; the product is saved locally.";
            $syncWarning = true;
        }

        try {

            $zohoResult = syncProductToZoho([
                'name' => $title,
                'sku' => $sku,
                'description' => $description,
                'rate' => $priceValue,
                'purchase_rate' => $originalPriceValue,
                'stock' => $stockValue
            ]);

            $zohoItemId = trim((string) (
                $zohoResult['item_id']
                ?? $zohoResult['item']['item_id']
                ?? $zohoResult['response']['item']['item_id']
                ?? ''
            ));

            if (
                !empty($zohoResult['success']) &&
                $zohoItemId === ''
            ) {
                $lookupResult = findZohoItemBySKU($sku);

                if (
                    !empty($lookupResult['success']) &&
                    !empty($lookupResult['found'])
                ) {
                    $zohoItemId = trim((string) (
                        $lookupResult['item']['item_id'] ?? ''
                    ));
                }
            }

            if (
                !empty($zohoResult['success']) &&
                $zohoItemId !== ''
            ) {
                $saveZohoId = $db->prepare("
                    UPDATE products
                    SET zoho_item_id = :zoho_item_id
                    WHERE id = :id
                ");

                $saveZohoId->execute([
                    ':zoho_item_id' => $zohoItemId,
                    ':id' => $localProductId
                ]);

                $syncMessages[] = "Zoho item synced.";
            } else {
                $syncMessages[] =
                    "Zoho sync failed or its item ID could not be retrieved.";
                $syncWarning = true;
            }
        } catch (Throwable $e) {

            error_log("Zoho product sync error: " . $e->getMessage());

            $syncMessages[] =
                "Zoho sync encountered an error; the product is saved locally.";
            $syncWarning = true;
        }

        $message = "Product saved locally with "
            . count($uploadedPaths)
            . " image(s) and "
            . count($variantsToSave)
            . " variant(s). "
            . implode(' ', $syncMessages);

        $messageType = $syncWarning ? "warning" : "success";
        $sku = "";
        $title = "";
        $description = "";
        $price = "";
        $originalPrice = "";
        $stock = "";
        $categoryId = 0;
        $brandId = 0;
        $status = 1;
    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }
        foreach ($uploadedPaths as $relativePath) {

            $fullPath = "../" . $relativePath;

            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }
        foreach ($uploadedVariantPaths as $relativePath) {

            $fullPath = "../" . $relativePath;

            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }

        if ($e instanceof RuntimeException) {
            $message = $e->getMessage();
        } else {
            error_log("Admin product creation error: " . $e->getMessage());

            $message =
                "Unable to save the product. Please check the server error log.";
        }

        $messageType = "danger";
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
                        href="../outh/logout.php"
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
                                        id="productStock"
                                        class="form-control"
                                        min="0"
                                        value="<?= htmlspecialchars($stock ?? ''); ?>"
                                        placeholder="0"
                                        required>
                                    <div class="image-note mt-1">
                                        When variants are added, total stock is calculated automatically.
                                    </div>

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


                                <div class="col-12">
                                    <label for="productImages" class="form-label">
                                        Select Product Images
                                    </label>

                                    <input
                                        type="file"
                                        name="images[]"
                                        id="productImages"
                                        class="form-control"
                                        accept="image/jpeg,image/png,image/webp"
                                        multiple>

                                    <small class="text-muted">
                                        You can select multiple images at once.
                                    </small>

                                    <div id="imagePreview" class="d-flex flex-wrap gap-3 mt-3"></div>
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

                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h5 class="mb-1">Product Variants</h5>
                                    <p class="text-muted small mb-0">
                                        Optional. Add variants such as Small/Red or Large/Blue.
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    class="btn btn-outline-primary"
                                    id="addVariantBtn">
                                    <i class="bi bi-plus-circle me-1"></i>
                                    Add Variant
                                </button>
                            </div>

                            <div id="variantsContainer"></div>

                            <template id="variantTemplate">
                                <div class="variant-card border rounded-3 p-3 mb-3">

                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <strong class="variant-heading">Variant</strong>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger remove-variant">
                                            Remove
                                        </button>
                                    </div>

                                    <div class="row g-3">

                                        <div class="col-md-6">
                                            <label class="form-label">Variant Title *</label>
                                            <input
                                                type="text"
                                                data-field="title"
                                                class="form-control"
                                                maxlength="255"
                                                placeholder="e.g. Small / Red">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Variant SKU *</label>
                                            <input
                                                type="text"
                                                data-field="sku"
                                                class="form-control"
                                                maxlength="100"
                                                placeholder="e.g. SHIRT-S-RED">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Selling Price *</label>
                                            <input
                                                type="number"
                                                data-field="price"
                                                class="form-control"
                                                min="0"
                                                max="99999999.99"
                                                step="0.01"
                                                placeholder="0.00">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Original Price</label>
                                            <input
                                                type="number"
                                                data-field="original_price"
                                                class="form-control"
                                                min="0"
                                                max="99999999.99"
                                                step="0.01"
                                                placeholder="0.00">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Stock *</label>
                                            <input
                                                type="number"
                                                data-field="stock"
                                                class="form-control"
                                                min="0"
                                                step="1"
                                                placeholder="0">
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Attributes</label>
                                            <input
                                                type="text"
                                                data-field="attributes"
                                                class="form-control"
                                                placeholder="Size: Small, Color: Red">

                                            <div class="image-note mt-1">
                                                Optional. Separate attributes with commas.
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Variant Images</label>
                                            <input
                                                type="file"
                                                class="form-control variant-images"
                                                accept="image/jpeg,image/png,image/webp"
                                                multiple>

                                            <div class="image-note mt-1">
                                                Select multiple JPG, PNG, or WEBP images for this variant.
                                            </div>

                                            <div class="variant-image-preview d-flex flex-wrap gap-2 mt-3"></div>
                                        </div>

                                    </div>
                                </div>
                            </template>

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


    <script>
        document.addEventListener("DOMContentLoaded", function() {

            const addVariantBtn = document.getElementById("addVariantBtn");
            const variantsContainer = document.getElementById("variantsContainer");
            const variantTemplate = document.getElementById("variantTemplate");

            const productStock = document.getElementById("productStock");

            function updateProductStock() {
                const variantCards = variantsContainer.querySelectorAll(".variant-card");

                if (variantCards.length > 0) {
                    let totalStock = 0;

                    variantCards.forEach(function(card) {
                        const stockInput = card.querySelector('[data-field="stock"]');
                        totalStock += Math.max(0, parseInt(stockInput.value, 10) || 0);
                    });

                    productStock.value = totalStock;
                    productStock.readOnly = true;
                } else {
                    productStock.readOnly = false;
                }
            }

            variantsContainer.addEventListener("input", function(event) {
                if (event.target.matches('[data-field="stock"]')) {
                    updateProductStock();
                }
            });

            const productImages = document.getElementById("productImages");
            const imagePreview = document.getElementById("imagePreview");

            let variantIndex = 0;
            let previewUrls = [];

            addVariantBtn.addEventListener("click", function() {

                const fragment = variantTemplate.content.cloneNode(true);
                const card = fragment.querySelector(".variant-card");

                card.querySelectorAll("[data-field]").forEach(function(input) {
                    const field = input.dataset.field;
                    input.name = `variants[${variantIndex}][${field}]`;
                    if (["title", "sku", "price", "stock"].includes(field)) {
                        input.required = true;
                    }
                });

                const variantImageInput = card.querySelector(".variant-images");

                variantImageInput.name =
                    `variant_images[${variantIndex}][]`;

                const variantImagePreview =
                    card.querySelector(".variant-image-preview");

                variantImageInput.addEventListener("change", function() {
                    variantImagePreview.replaceChildren();

                    Array.from(variantImageInput.files).forEach(function(file) {
                        if (![
                                "image/jpeg",
                                "image/png",
                                "image/webp"
                            ].includes(file.type)) {
                            return;
                        }

                        const wrapper = document.createElement("div");
                        wrapper.className = "text-center";

                        const image = document.createElement("img");
                        image.src = URL.createObjectURL(file);
                        image.alt = file.name;
                        image.style.width = "90px";
                        image.style.height = "90px";
                        image.style.objectFit = "cover";
                        image.className = "rounded border";

                        wrapper.appendChild(image);
                        variantImagePreview.appendChild(wrapper);
                    });
                });


                card.querySelector(".variant-heading").textContent =
                    `Variant ${variantIndex + 1}`;

                card.querySelector(".remove-variant").addEventListener(
                    "click",
                    function() {
                        card.remove();
                        updateProductStock();
                    }
                );

                variantsContainer.appendChild(fragment);
                variantIndex++;
                updateProductStock();
            });

            productImages.addEventListener("change", function() {

                previewUrls.forEach(function(url) {
                    URL.revokeObjectURL(url);
                });

                previewUrls = [];
                imagePreview.replaceChildren();

                Array.from(productImages.files).forEach(function(file) {

                    const allowedTypes = [
                        "image/jpeg",
                        "image/png",
                        "image/webp"
                    ];

                    if (!allowedTypes.includes(file.type)) {
                        return;
                    }

                    const wrapper = document.createElement("div");
                    wrapper.className = "text-center";

                    const image = document.createElement("img");
                    const objectUrl = URL.createObjectURL(file);

                    previewUrls.push(objectUrl);

                    image.src = objectUrl;
                    image.alt = file.name;
                    image.style.width = "110px";
                    image.style.height = "110px";
                    image.style.objectFit = "cover";
                    image.className = "rounded border";

                    const caption = document.createElement("div");
                    caption.className = "small text-muted mt-1";
                    caption.textContent = file.name;

                    if (imagePreview.children.length === 0) {
                        caption.textContent += " (Main image)";
                    }

                    wrapper.appendChild(image);
                    wrapper.appendChild(caption);
                    imagePreview.appendChild(wrapper);
                });
            });

        });
    </script>

</body>

</html>