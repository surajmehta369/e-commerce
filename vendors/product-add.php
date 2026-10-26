<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();

require_once "../connection/dbconnect.php";
require_once "../shopify/functions.php";
require_once "../zoho/zoho_functions.php";

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'vendor'
) {
    header("Location: ../outh/login.php");
    exit;
}

$vendorId = (int) $_SESSION['user_id'];

$database = new Database();
$db = $database->connect();

/* ---------------------------------------------------------
   VERIFY VENDOR
--------------------------------------------------------- */

$vendorStmt = $db->prepare("
    SELECT verification_status
    FROM vendor_profiles
    WHERE user_id = :user_id
    LIMIT 1
");
$vendorStmt->execute([':user_id' => $vendorId]);
$vendor = $vendorStmt->fetch(PDO::FETCH_ASSOC);

if (!$vendor || $vendor['verification_status'] !== 'approved') {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

/* ---------------------------------------------------------
   LOAD CATEGORIES AND BRANDS
--------------------------------------------------------- */

$categories = $db->query("
    SELECT id, name
    FROM categories
    WHERE status = 1
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$brands = $db->query("
    SELECT id, name
    FROM brands
    WHERE status = 1
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$message = '';
$messageType = '';

/* ---------------------------------------------------------
   HELPERS
--------------------------------------------------------- */

function generateSlug($text)
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);

    return trim($text, '-');
}

function uploadProductImage(array $file)
{
    if (
        !isset($file['error']) ||
        $file['error'] !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException('An image could not be uploaded.');
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Each image must be 5 MB or smaller.');
    }

    $tmpName = $file['tmp_name'] ?? '';

    if (!is_uploaded_file($tmpName)) {
        throw new RuntimeException('Invalid uploaded image.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpName);

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowedTypes[$mime])) {
        throw new RuntimeException(
            'Only JPG, PNG and WEBP images are allowed.'
        );
    }

    if (@getimagesize($tmpName) === false) {
        throw new RuntimeException('The uploaded file is not a valid image.');
    }

    $uploadDirectory = __DIR__ . '/../assets/uploads/';

    if (
        !is_dir($uploadDirectory) &&
        !mkdir($uploadDirectory, 0755, true) &&
        !is_dir($uploadDirectory)
    ) {
        throw new RuntimeException('Unable to create the image upload directory.');
    }

    $fileName = 'product_' . bin2hex(random_bytes(16))
        . '.' . $allowedTypes[$mime];

    $targetPath = $uploadDirectory . $fileName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Unable to save the uploaded image.');
    }

    return [
        'path' => 'assets/uploads/' . $fileName,
        'absolute_path' => $targetPath
    ];
}

function normalizeUploadedFiles(array $files): array
{
    $normalized = [];

    if (!isset($files['name']) || !is_array($files['name'])) {
        return $normalized;
    }

    foreach ($files['name'] as $key => $name) {
        $normalized[$key] = [
            'name' => $name,
            'type' => $files['type'][$key] ?? '',
            'tmp_name' => $files['tmp_name'][$key] ?? '',
            'error' => $files['error'][$key] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$key] ?? 0
        ];
    }

    return $normalized;
}


function normalizeNestedUploadedFiles(array $files, int $index): array
{
    $normalized = [];

    if (
        !isset($files['name'][$index]['images']) ||
        !is_array($files['name'][$index]['images'])
    ) {
        return $normalized;
    }

    foreach ($files['name'][$index]['images'] as $fileIndex => $name) {
        $normalized[] = [
            'name' => $name,
            'type' => $files['type'][$index]['images'][$fileIndex] ?? '',
            'tmp_name' => $files['tmp_name'][$index]['images'][$fileIndex] ?? '',
            'error' => $files['error'][$index]['images'][$fileIndex]
                ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index]['images'][$fileIndex] ?? 0
        ];
    }

    return $normalized;
}


function parseVariantAttributes(string $rawAttributes): array
{
    $attributes = [];

    $parts = preg_split('/[,;\r\n]+/', $rawAttributes);

    foreach ($parts as $part) {
        $part = trim($part);

        if ($part === '') {
            continue;
        }

        if (strpos($part, ':') !== false) {
            [$key, $value] = explode(':', $part, 2);

            $key = trim($key);
            $value = trim($value);

            if ($key !== '' && $value !== '') {
                $attributes[$key] = $value;
            }
        } else {
            $attributes['Options'][] = $part;
        }
    }

    return $attributes;
}

/* ---------------------------------------------------------
   HANDLE FORM SUBMISSION
--------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $uploadedFiles = [];
    $localProductId = 0;
    $shopifyProductId = null;

    try {

        $sku = trim($_POST['sku'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $priceInput = trim($_POST['price'] ?? '');
        $originalPriceInput = trim($_POST['original_price'] ?? '');
        $stockInput = trim($_POST['stock'] ?? '');

        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $brandId = (int) ($_POST['brand_id'] ?? 0);

        if ($sku === '' || strlen($sku) > 100) {
            throw new RuntimeException('SKU is required and must not exceed 100 characters.');
        }

        if ($title === '' || strlen($title) > 255) {
            throw new RuntimeException('Product title is required and must not exceed 255 characters.');
        }

        if (
            $priceInput === '' ||
            !is_numeric($priceInput) ||
            (float) $priceInput <= 0 ||
            (float) $priceInput > 99999999.99
        ) {
            throw new RuntimeException('Enter a valid selling price greater than zero.');
        }

        $price = round((float) $priceInput, 2);

        if ($originalPriceInput === '') {
            $originalPrice = $price;
        } elseif (
            !is_numeric($originalPriceInput) ||
            (float) $originalPriceInput < 0 ||
            (float) $originalPriceInput > 99999999.99
        ) {
            throw new RuntimeException('Enter a valid original price.');
        } else {
            $originalPrice = round((float) $originalPriceInput, 2);
        }

        if ($originalPrice < $price) {
            throw new RuntimeException(
                'Original price cannot be less than the selling price.'
            );
        }

        if ($categoryId <= 0 || $brandId <= 0) {
            throw new RuntimeException('Please select a category and brand.');
        }

        $categoryCheck = $db->prepare("
            SELECT id FROM categories
            WHERE id = :id AND status = 1
            LIMIT 1
        ");
        $categoryCheck->execute([':id' => $categoryId]);

        if (!$categoryCheck->fetch()) {
            throw new RuntimeException('The selected category is unavailable.');
        }

        $brandCheck = $db->prepare("
            SELECT id FROM brands
            WHERE id = :id AND status = 1
            LIMIT 1
        ");
        $brandCheck->execute([':id' => $brandId]);

        if (!$brandCheck->fetch()) {
            throw new RuntimeException('The selected brand is unavailable.');
        }

        /* ---------------------------------------------
           VALIDATE VARIANTS
        --------------------------------------------- */

        $submittedVariants = $_POST['variants'] ?? [];

        if (!is_array($submittedVariants)) {
            throw new RuntimeException('Invalid variant data.');
        }

        $variantsToSave = [];
        $allSkus = [strtolower($sku)];

        foreach ($submittedVariants as $index => $variant) {

            if (!is_array($variant)) {
                throw new RuntimeException('Invalid variant information.');
            }

            $variantTitle = trim($variant['title'] ?? '');
            $variantSku = trim($variant['sku'] ?? '');
            $variantPriceInput = trim($variant['price'] ?? '');
            $variantOriginalInput = trim($variant['original_price'] ?? '');
            $variantStockInput = trim((string) ($variant['stock'] ?? ''));
            $attributesInput = trim($variant['attributes'] ?? '');

            // Ignore a completely empty variant row.
            if (
                $variantTitle === '' &&
                $variantSku === '' &&
                $variantPriceInput === '' &&
                $variantOriginalInput === '' &&
                $variantStockInput === '' &&
                $attributesInput === ''
            ) {
                continue;
            }

            if ($variantTitle === '' || strlen($variantTitle) > 255) {
                throw new RuntimeException(
                    'Every variant needs a title of up to 255 characters.'
                );
            }

            if ($variantSku === '' || strlen($variantSku) > 100) {
                throw new RuntimeException(
                    'Every variant needs a SKU of up to 100 characters.'
                );
            }

            $normalizedSku = strtolower($variantSku);

            if (in_array($normalizedSku, $allSkus, true)) {
                throw new RuntimeException(
                    'The main product SKU and every variant SKU must be unique.'
                );
            }

            $allSkus[] = $normalizedSku;

            if (
                $variantPriceInput === '' ||
                !is_numeric($variantPriceInput) ||
                (float) $variantPriceInput <= 0 ||
                (float) $variantPriceInput > 99999999.99
            ) {
                throw new RuntimeException(
                    "Enter a valid selling price for variant {$variantTitle}."
                );
            }

            $variantPrice = round((float) $variantPriceInput, 2);

            if ($variantOriginalInput === '') {
                $variantOriginalPrice = $variantPrice;
            } elseif (
                !is_numeric($variantOriginalInput) ||
                (float) $variantOriginalInput < 0 ||
                (float) $variantOriginalInput > 99999999.99
            ) {
                throw new RuntimeException(
                    "Enter a valid original price for variant {$variantTitle}."
                );
            } else {
                $variantOriginalPrice = round((float) $variantOriginalInput, 2);
            }

            if ($variantOriginalPrice < $variantPrice) {
                throw new RuntimeException(
                    "Original price cannot be less than selling price for {$variantTitle}."
                );
            }

            if (
                $variantStockInput === '' ||
                !ctype_digit($variantStockInput) ||
                (float) $variantStockInput > 2147483647
            ) {
                throw new RuntimeException(
                    "Enter a whole, non-negative stock quantity for {$variantTitle}."
                );
            }

            $variantAttributes = parseVariantAttributes($attributesInput);

            $variantsToSave[] = [
                'form_index' => (string) $index,
                'title' => $variantTitle,
                'sku' => $variantSku,
                'price' => $variantPrice,
                'original_price' => $variantOriginalPrice,
                'stock' => (int) $variantStockInput,
                'attributes' => $variantAttributes
            ];
        }

        /* ---------------------------------------------
           CHECK ALL PRODUCT AND VARIANT SKUS
        --------------------------------------------- */

        $skuCheck = $db->prepare("
            SELECT id
            FROM products
            WHERE LOWER(sku) = LOWER(:sku)
            LIMIT 1
        ");
        $skuCheck->execute([':sku' => $sku]);

        if ($skuCheck->fetch()) {
            throw new RuntimeException('This product SKU already exists.');
        }

        $variantSkuCheck = $db->prepare("
            SELECT id
            FROM product_variants
            WHERE LOWER(sku) = LOWER(:sku)
            LIMIT 1
        ");

        foreach ($allSkus as $normalizedSku) {
            $variantSkuCheck->execute([':sku' => $normalizedSku]);

            if ($variantSkuCheck->fetch()) {
                throw new RuntimeException(
                    "SKU '{$normalizedSku}' is already being used by a variant."
                );
            }

            $productSkuCheck = $db->prepare("
                SELECT id
                FROM products
                WHERE LOWER(sku) = LOWER(:sku)
                LIMIT 1
            ");
            $productSkuCheck->execute([':sku' => $normalizedSku]);

            if ($productSkuCheck->fetch()) {
                throw new RuntimeException(
                    "SKU '{$normalizedSku}' is already being used by a product."
                );
            }
        }

        /* ---------------------------------------------
           CALCULATE STOCK AND PRICE
        --------------------------------------------- */

        if (!empty($variantsToSave)) {
            $stock = array_sum(array_column($variantsToSave, 'stock'));

            // Product-level price follows the cheapest variant.
            $price = min(array_column($variantsToSave, 'price'));

            $variantOriginalPrices = array_column(
                $variantsToSave,
                'original_price'
            );

            $originalPrice = min($variantOriginalPrices);

            if ($originalPrice < $price) {
                $originalPrice = $price;
            }
        } else {

            if (
                $stockInput === '' ||
                !ctype_digit($stockInput) ||
                (float) $stockInput > 2147483647
            ) {
                throw new RuntimeException(
                    'Enter a whole, non-negative stock quantity.'
                );
            }

            $stock = (int) $stockInput;
        }

        $discount = 0;

        if ($originalPrice > $price && $originalPrice > 0) {
            $discount = round(
                (($originalPrice - $price) / $originalPrice) * 100,
                2
            );
        }

        /* ---------------------------------------------
           UPLOAD MAIN PRODUCT IMAGES
           First image becomes products.image.
        --------------------------------------------- */

        $mainImages = [];

        if (isset($_FILES['images'])) {
            $imageFiles = normalizeUploadedFiles($_FILES['images']);

            foreach ($imageFiles as $file) {
                if ($file['error'] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                $uploaded = uploadProductImage($file);
                $uploadedFiles[] = $uploaded['absolute_path'];
                $mainImages[] = $uploaded['path'];
            }
        }

        if (count($mainImages) === 0 && isset($_FILES['image'])) {
            // Compatibility with the old single-image input.
            if ($_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploaded = uploadProductImage($_FILES['image']);
                $uploadedFiles[] = $uploaded['absolute_path'];
                $mainImages[] = $uploaded['path'];
            }
        }

        $imagePath = $mainImages[0] ?? '';

        /* ---------------------------------------------
           UPLOAD VARIANT IMAGES
           Expected input: variants[index][images][]
        --------------------------------------------- */

$variantImages = [];

if (isset($_FILES['variants'])) {
    foreach ($variantsToSave as $variant) {

        $formIndex = $variant['form_index'];

        $files = normalizeNestedUploadedFiles(
            $_FILES['variants'],
            (int) $formIndex
        );

        foreach ($files as $file) {

            // No image selected for this variant.
            if (
                !isset($file['error']) ||
                $file['error'] === UPLOAD_ERR_NO_FILE
            ) {
                continue;
            }

            // Report the actual PHP upload error.
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE   => 'Image exceeds the server upload limit.',
                    UPLOAD_ERR_FORM_SIZE  => 'Image exceeds the form upload limit.',
                    UPLOAD_ERR_PARTIAL    => 'Image was only partially uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
                    UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded image.',
                    UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the image upload.'
                ];

                throw new RuntimeException(
                    $uploadErrors[$file['error']]
                    ?? 'Image upload failed with error code ' . $file['error']
                );
            }

            $uploaded = uploadProductImage($file);

            $uploadedFiles[] = $uploaded['absolute_path'];

            $variantImages[$formIndex][] = $uploaded['path'];
        }
    }
}

        /* ---------------------------------------------
           CREATE SLUG
        --------------------------------------------- */

        $baseSlug = generateSlug($title);

        if ($baseSlug === '') {
            $baseSlug = 'product';
        }

        $slug = $baseSlug;
        $counter = 1;

        $slugCheck = $db->prepare("
            SELECT id
            FROM products
            WHERE slug = :slug
            LIMIT 1
        ");

        while (true) {
            $slugCheck->execute([':slug' => $slug]);

            if (!$slugCheck->fetch()) {
                break;
            }

            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        /* ---------------------------------------------
           SAVE LOCAL PRODUCT AND VARIANTS
        --------------------------------------------- */

        $db->beginTransaction();

        $productStmt = $db->prepare("
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
                0,
                :vendor_id
            )
        ");

        $productStmt->execute([
            ':sku' => $sku,
            ':title' => $title,
            ':slug' => $slug,
            ':description' => $description,
            ':image' => $imagePath,
            ':price' => $price,
            ':original_price' => $originalPrice,
            ':discount' => $discount,
            ':stock' => $stock,
            ':category_id' => $categoryId,
            ':brand_id' => $brandId,
            ':vendor_id' => $vendorId
        ]);

        $localProductId = (int) $db->lastInsertId();

        /* Additional product images */
        if (count($mainImages) > 1) {
            $imageStmt = $db->prepare("
                INSERT INTO product_images (
                    product_id,
                    image_path,
                    alt_text,
                    sort_order
                ) VALUES (
                    :product_id,
                    :image_path,
                    :alt_text,
                    :sort_order
                )
            ");

            foreach (array_slice($mainImages, 1) as $position => $path) {
                $imageStmt->execute([
                    ':product_id' => $localProductId,
                    ':image_path' => $path,
                    ':alt_text' => $title,
                    ':sort_order' => $position + 1
                ]);
            }
        }

        /* Insert variants and their images */
        if (!empty($variantsToSave)) {

            $variantStmt = $db->prepare("
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
                    :title,
                    :sku,
                    :price,
                    :original_price,
                    :stock,
                    :attributes,
                    1
                )
            ");

            $variantImageStmt = $db->prepare("
                INSERT INTO product_variant_images (
                    variant_id,
                    image_path,
                    alt_text,
                    sort_order
                ) VALUES (
                    :variant_id,
                    :image_path,
                    :alt_text,
                    :sort_order
                )
            ");

            foreach ($variantsToSave as $variant) {

                $variantStmt->execute([
                    ':product_id' => $localProductId,
                    ':title' => $variant['title'],
                    ':sku' => $variant['sku'],
                    ':price' => $variant['price'],
                    ':original_price' => $variant['original_price'],
                    ':stock' => $variant['stock'],
                    ':attributes' => json_encode(
                        $variant['attributes'],
                        JSON_UNESCAPED_UNICODE
                    )
                ]);

                $variantId = (int) $db->lastInsertId();

                $paths = $variantImages[$variant['form_index']] ?? [];

                foreach ($paths as $position => $path) {
                    $variantImageStmt->execute([
                        ':variant_id' => $variantId,
                        ':image_path' => $path,
                        ':alt_text' => $variant['title'],
                        ':sort_order' => $position
                    ]);
                }
            }
        }

        $db->commit();

        /* ---------------------------------------------
           SHOPIFY SYNC
           Local product is already safely saved.
        --------------------------------------------- */

        $shopifyMessages = [];

        try {

            $shopifyResult = createShopifyProduct([
                'title' => $title,
                'description' => $description,
                'slug' => $slug,
                'status' => 0
            ]);

            if (
                empty($shopifyResult['success']) ||
                empty($shopifyResult['data']['id'])
            ) {
                throw new RuntimeException(
                    $shopifyResult['message']
                        ?? 'Shopify did not return a product ID.'
                );
            }

            $shopifyProductId = $shopifyResult['data']['id'];

            $shopifyUpdateStmt = $db->prepare("
                UPDATE products
                SET
                    shopify_product_id = :shopify_product_id,
                    shopify_status = 'DRAFT',
                    shopify_synced_at = NOW()
                WHERE id = :id
                  AND vendor_id = :vendor_id
            ");

            $shopifyUpdateStmt->execute([
                ':shopify_product_id' => $shopifyProductId,
                ':id' => $localProductId,
                ':vendor_id' => $vendorId
            ]);

            $variantResult = updateShopifyProductVariant(
                $shopifyProductId,
                $price,
                $originalPrice,
                $sku
            );

            if (empty($variantResult['success'])) {
                $shopifyMessages[] = 'Shopify price/SKU sync failed.';
            }

            $inventoryResult = updateShopifyInventory(
                $shopifyProductId,
                $stock
            );

            if (empty($inventoryResult['success'])) {
                $shopifyMessages[] = 'Shopify inventory sync failed.';
            }

            $collectionResult = addShopifyProductToVendorCollection(
                $shopifyProductId,
                $vendorId
            );

            if (empty($collectionResult['success'])) {
                $shopifyMessages[] = 'Adding the product to the Shopify vendor collection failed.';
            }

        } catch (Throwable $e) {
            $shopifyMessages[] = 'Shopify sync failed: ' . $e->getMessage();
        }

        /* ---------------------------------------------
           ZOHO SYNC
        --------------------------------------------- */

        $zohoMessage = '';

        try {

            $zohoResult = syncProductToZoho([
                'name' => $title,
                'sku' => $sku,
                'description' => $description,
                'rate' => (float) $price,
                'purchase_rate' => (float) $originalPrice,
                'stock' => (int) $stock
            ]);

            if (empty($zohoResult['success'])) {
                $zohoMessage = 'Zoho sync failed: ' .
                    ($zohoResult['message'] ?? 'Unknown synchronization error.');
            }

        } catch (Throwable $e) {
            $zohoMessage = 'Zoho sync failed: ' . $e->getMessage();
        }

        $message = 'Product saved successfully and is waiting for admin approval.';

        if (!empty($variantsToSave)) {
            $message .= ' ' . count($variantsToSave) . ' variant(s) added.';
        }

        if (!empty($shopifyMessages)) {
            $message .= ' ' . implode(' ', $shopifyMessages);
        } else {
            $message .= ' Shopify product is saved as Draft.';
        }

        if ($zohoMessage !== '') {
            $message .= ' ' . $zohoMessage;
        }

        $messageType = (
            !empty($shopifyMessages) || $zohoMessage !== ''
        ) ? 'warning' : 'success';

        $_POST = [];

    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        foreach ($uploadedFiles as $filePath) {
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }

        $message = 'Unable to save product: ' . $e->getMessage();
        $messageType = 'danger';
    }
}
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Add Product</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <style>
        .variant-card {
            border: 1px solid #dee2e6;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 18px;
            background: #fff;
        }

        .preview-image {
            width: 85px;
            height: 85px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            margin: 5px;
        }

        .section-heading {
            font-weight: 700;
            margin-bottom: 16px;
        }
    </style>
</head>

<body>
<main class="container-fluid py-5">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1">Add Product</h2>
            <p class="text-muted mb-0">Add product details, variants and images.</p>
        </div>

        <a href="products.php" class="btn btn-outline-secondary rounded-pill px-4">
            Back to Products
        </a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show rounded-4">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4 p-lg-5">

            <form method="POST" enctype="multipart/form-data" id="productForm">
                <div class="row g-4">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Product SKU *</label>
                        <input
                            type="text"
                            name="sku"
                            maxlength="100"
                            class="form-control"
                            value="<?= htmlspecialchars($_POST['sku'] ?? '') ?>"
                            required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Product Title *</label>
                        <input
                            type="text"
                            name="title"
                            maxlength="255"
                            class="form-control"
                            value="<?= htmlspecialchars($_POST['title'] ?? '') ?>"
                            required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Category *</label>
                        <select name="category_id" class="form-select" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option
                                    value="<?= (int) $category['id'] ?>"
                                    <?= (int) ($_POST['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Brand *</label>
                        <select name="brand_id" class="form-select" required>
                            <option value="">Select Brand</option>
                            <?php foreach ($brands as $brand): ?>
                                <option
                                    value="<?= (int) $brand['id'] ?>"
                                    <?= (int) ($_POST['brand_id'] ?? 0) === (int) $brand['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($brand['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea
                            name="description"
                            rows="5"
                            class="form-control"
                            placeholder="Describe your product"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    </div>

                    <div class="col-12">
                        <hr>
                        <h5 class="section-heading">Product Images</h5>

                        <label class="form-label">Select multiple images</label>
                        <input
                            type="file"
                            name="images[]"
                            id="mainImages"
                            class="form-control"
                            accept=".jpg,.jpeg,.png,.webp"
                            multiple>

                        <small class="text-muted">
                            Maximum 5 MB per image. The first image becomes the main product image.
                        </small>

                        <div id="mainImagePreview" class="d-flex flex-wrap mt-2"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Selling Price *</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input
                                type="number"
                                name="price"
                                id="mainPrice"
                                min="0.01"
                                max="99999999.99"
                                step="0.01"
                                class="form-control"
                                value="<?= htmlspecialchars($_POST['price'] ?? '') ?>"
                                required>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Original Price</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input
                                type="number"
                                name="original_price"
                                id="mainOriginalPrice"
                                min="0"
                                max="99999999.99"
                                step="0.01"
                                class="form-control"
                                value="<?= htmlspecialchars($_POST['original_price'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Discount</label>
                        <input
                            type="text"
                            id="discountDisplay"
                            class="form-control"
                            value="Automatically calculated"
                            readonly>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Total Stock *</label>
                        <input
                            type="number"
                            name="stock"
                            id="mainStock"
                            min="0"
                            step="1"
                            class="form-control"
                            value="<?= htmlspecialchars($_POST['stock'] ?? '0') ?>"
                            required>

                        <small id="stockHelp" class="text-muted">
                            Enter stock if this product has no variants.
                        </small>
                    </div>

                    <div class="col-12">
                        <hr>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h5 class="section-heading mb-1">Product Variants</h5>
                                <p class="text-muted mb-0">
                                    Add sizes, colors or other options. Each variant can have its own price, SKU, stock and images.
                                </p>
                            </div>

                            <button type="button" id="addVariant" class="btn btn-outline-primary">
                                + Add Variant
                            </button>
                        </div>

                        <div id="variantsContainer" class="mt-3"></div>
                    </div>

                    <div class="col-12">
                        <div class="alert alert-info border-0 rounded-4 mb-0">
                            New vendor products are submitted for admin approval. Shopify products are created as drafts.
                        </div>
                    </div>

                    <div class="col-12">
                        <hr>
                        <div class="d-flex justify-content-end gap-2">
                            <a href="products.php" class="btn btn-light rounded-pill px-4">
                                Cancel
                            </a>

                            <button type="submit" class="btn btn-primary rounded-pill px-4">
                                Save Product
                            </button>
                        </div>
                    </div>

                </div>
            </form>
        </div>
    </div>
</main>

<template id="variantTemplate">
    <div class="variant-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold mb-0 variant-heading">Variant</h6>
            <button type="button" class="btn btn-sm btn-outline-danger remove-variant">
                Remove Variant
            </button>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Variant Title *</label>
                <input type="text" class="form-control variant-title" required>
            </div>

            <div class="col-md-6">
                <label class="form-label">Variant SKU *</label>
                <input type="text" class="form-control variant-sku" maxlength="100" required>
            </div>

            <div class="col-md-4">
                <label class="form-label">Selling Price *</label>
                <input type="number" class="form-control variant-price" min="0.01" max="99999999.99" step="0.01" required>
            </div>

            <div class="col-md-4">
                <label class="form-label">Original Price</label>
                <input type="number" class="form-control variant-original" min="0" max="99999999.99" step="0.01">
            </div>

            <div class="col-md-4">
                <label class="form-label">Stock *</label>
                <input type="number" class="form-control variant-stock" min="0" step="1" value="0" required>
            </div>

            <div class="col-12">
                <label class="form-label">Attributes</label>
                <input
                    type="text"
                    class="form-control variant-attributes"
                    placeholder="Color: Black, Size: Large">
                <small class="text-muted">
                    Separate options with commas. Example: Color: Black, Size: Large
                </small>
            </div>

            <div class="col-12">
                <label class="form-label">Variant Images</label>
                <input
                    type="file"
                    class="form-control variant-images"
                    accept=".jpg,.jpeg,.png,.webp"
                    multiple>
                <div class="variant-image-preview d-flex flex-wrap mt-2"></div>
            </div>
        </div>
    </div>
</template>

<script>
(() => {
    const container = document.getElementById('variantsContainer');
    const template = document.getElementById('variantTemplate');
    const addButton = document.getElementById('addVariant');

    const mainStock = document.getElementById('mainStock');
    const stockHelp = document.getElementById('stockHelp');
    const mainPrice = document.getElementById('mainPrice');
    const mainOriginalPrice = document.getElementById('mainOriginalPrice');
    const discountDisplay = document.getElementById('discountDisplay');

    let nextIndex = 0;

    function updateStock() {
        const cards = [...container.querySelectorAll('.variant-card')];

        if (cards.length === 0) {
            mainStock.readOnly = false;
            stockHelp.textContent = 'Enter stock if this product has no variants.';
            return;
        }

        const total = cards.reduce((sum, card) => {
            const value = parseInt(card.querySelector('.variant-stock').value, 10);
            return sum + (Number.isFinite(value) && value >= 0 ? value : 0);
        }, 0);

        mainStock.value = total;
        mainStock.readOnly = true;
        stockHelp.textContent = 'Total stock is calculated from the variants.';
    }

    function updateDiscount() {
        const price = parseFloat(mainPrice.value);
        const original = parseFloat(mainOriginalPrice.value);

        if (
            Number.isFinite(price) &&
            Number.isFinite(original) &&
            original > price &&
            original > 0
        ) {
            discountDisplay.value =
                (((original - price) / original) * 100).toFixed(2) + '%';
        } else {
            discountDisplay.value = '0%';
        }
    }

    function previewFiles(input, preview) {
        preview.replaceChildren();

        [...input.files].forEach(file => {
            if (!file.type.startsWith('image/')) return;

            const image = document.createElement('img');
            image.className = 'preview-image';
            image.alt = file.name;
            image.src = URL.createObjectURL(file);

            preview.appendChild(image);
        });
    }

    function addVariant() {
        const fragment = template.content.cloneNode(true);
        const card = fragment.querySelector('.variant-card');
        const index = nextIndex++;

        card.querySelector('.variant-heading').textContent =
            'Variant ' + (container.querySelectorAll('.variant-card').length + 1);

        const fields = {
            '.variant-title': `variants[${index}][title]`,
            '.variant-sku': `variants[${index}][sku]`,
            '.variant-price': `variants[${index}][price]`,
            '.variant-original': `variants[${index}][original_price]`,
            '.variant-stock': `variants[${index}][stock]`,
            '.variant-attributes': `variants[${index}][attributes]`,
            '.variant-images': `variants[${index}][images][]`
        };

        Object.entries(fields).forEach(([selector, name]) => {
            card.querySelector(selector).name = name;
        });

        card.querySelector('.remove-variant').addEventListener('click', () => {
            card.remove();
            updateStock();

            [...container.querySelectorAll('.variant-card')].forEach((item, i) => {
                item.querySelector('.variant-heading').textContent =
                    'Variant ' + (i + 1);
            });
        });

        card.querySelector('.variant-stock').addEventListener('input', updateStock);

        card.querySelector('.variant-images').addEventListener('change', event => {
            previewFiles(
                event.target,
                card.querySelector('.variant-image-preview')
            );
        });

        container.appendChild(card);
        updateStock();
    }

    addButton.addEventListener('click', addVariant);

    document.getElementById('mainImages').addEventListener('change', event => {
        previewFiles(event.target, document.getElementById('mainImagePreview'));
    });

    mainPrice.addEventListener('input', updateDiscount);
    mainOriginalPrice.addEventListener('input', updateDiscount);

    updateStock();
    updateDiscount();
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>