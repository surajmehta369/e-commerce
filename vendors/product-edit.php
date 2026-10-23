<?php

require_once "auth.php";
require_once "../connection/dbconnect.php";
require_once "../shopify/functions.php";
require_once "../zoho/zoho_functions.php";

$database = new Database();
$db = $database->connect();
function normalizeNestedUploadedFiles(array $files): array
{
    $normalized = [];

    if (
        !isset($files['name']) ||
        !is_array($files['name'])
    ) {
        return $normalized;
    }

    foreach ($files['name'] as $key => $name) {

        if (is_array($name)) {
            $childFiles = [
                'name' => $files['name'][$key] ?? [],
                'type' => $files['type'][$key] ?? [],
                'tmp_name' => $files['tmp_name'][$key] ?? [],
                'error' => $files['error'][$key] ?? [],
                'size' => $files['size'][$key] ?? []
            ];

            $normalized[$key] =
                normalizeNestedUploadedFiles($childFiles);
        } else {
            $normalized[$key] = [
                'name' => $name,
                'type' => $files['type'][$key] ?? '',
                'tmp_name' => $files['tmp_name'][$key] ?? '',
                'error' => $files['error'][$key] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$key] ?? 0
            ];
        }
    }

    return $normalized;
}

function uploadProductEditImage(array $file): array
{
    if (
        !isset($file['error']) ||
        $file['error'] !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException(
            'An image could not be uploaded successfully.'
        );
    }

    $maxFileSize = 5 * 1024 * 1024;

    if (($file['size'] ?? 0) > $maxFileSize) {
        throw new RuntimeException(
            'Each image must not exceed 5 MB.'
        );
    }

    $extension = strtolower(
        pathinfo($file['name'] ?? '', PATHINFO_EXTENSION)
    );

    $allowedExtensions = ['jpg', 'jpeg', 'jfif', 'png', 'webp'];

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException(
            'Only JPG, JPEG, JFIF, PNG and WEBP images are allowed.'
        );
    }

    if (
        empty($file['tmp_name']) ||
        !is_uploaded_file($file['tmp_name'])
    ) {
        throw new RuntimeException(
            'The uploaded image is invalid.'
        );
    }
    $imageInfo = @getimagesize($file['tmp_name']);

    if ($imageInfo === false) {
        throw new RuntimeException(
            'The uploaded file is not a valid image.'
        );
    }

    $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    if (
        !in_array(
            $imageInfo['mime'] ?? '',
            $allowedMimeTypes,
            true
        )
    ) {
        throw new RuntimeException(
            'Unsupported image format.'
        );
    }

    $uploadDirectory = __DIR__ . '/../assets/uploads/';

    if (
        !is_dir($uploadDirectory) &&
        !mkdir($uploadDirectory, 0755, true) &&
        !is_dir($uploadDirectory)
    ) {
        throw new RuntimeException(
            'The image upload directory could not be created.'
        );
    }

    $newFileName =
        'product_' .
        bin2hex(random_bytes(12)) .
        '.' .
        $extension;

    $targetPath = $uploadDirectory . $newFileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException(
            'Failed to save the uploaded image.'
        );
    }

    return [
        'path' => 'assets/uploads/' . $newFileName,
        'absolute_path' => $targetPath
    ];
}


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

$galleryImages = [];

$galleryStmt = $db->prepare("
    SELECT id, image_path, alt_text, sort_order
    FROM product_images
    WHERE product_id = :product_id
    ORDER BY sort_order ASC
");

$galleryStmt->execute([
    ':product_id' => $productId
]);

$galleryImages = $galleryStmt->fetchAll(PDO::FETCH_ASSOC);
$variantStmt = $db->prepare("
    SELECT
        id,
        product_id,
        variant_title,
        sku,
        price,
        original_price,
        stock,
        attributes,
        status
    FROM product_variants
    WHERE product_id = :product_id
    ORDER BY id ASC
");

$variantStmt->execute([
    ':product_id' => $productId
]);

$variants = $variantStmt->fetchAll(PDO::FETCH_ASSOC);
$variantImages = [];

if (!empty($variants)) {

    $variantImageStmt = $db->prepare("
        SELECT
            id,
            variant_id,
            image_path,
            alt_text,
            sort_order
        FROM product_variant_images
        WHERE variant_id = :variant_id
        ORDER BY sort_order ASC
    ");

    foreach ($variants as $variant) {

        $variantImageStmt->execute([
            ':variant_id' => (int) $variant['id']
        ]);

        $variantImages[(int) $variant['id']] =
            $variantImageStmt->fetchAll(PDO::FETCH_ASSOC);
    }
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

    $submittedVariants = $_POST['variants'] ?? [];

    if (!is_array($submittedVariants)) {
        $submittedVariants = [];
    }
    $uploadedVariantFiles = [];

    if (isset($_FILES['variants'])) {
        $uploadedVariantFiles =
            normalizeNestedUploadedFiles($_FILES['variants']);
    }

    $uploadedVariantImagePaths = [];
    $uploadedGalleryImagePaths = [];
    $removedImagePaths = [];
    $variantsToSave = [];
    $removeGalleryImageIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['remove_gallery_images'] ?? [])))));
    $removeVariantImageIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['remove_variant_images'] ?? [])))));
    $galleryUploadFiles = [];
    if (isset($_FILES['gallery_images']) && is_array($_FILES['gallery_images']['name'] ?? null)) {
        foreach ($_FILES['gallery_images']['name'] as $i => $name) {
            $galleryUploadFiles[] = [
                'name' => $name,
                'type' => $_FILES['gallery_images']['type'][$i] ?? '',
                'tmp_name' => $_FILES['gallery_images']['tmp_name'][$i] ?? '',
                'error' => $_FILES['gallery_images']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $_FILES['gallery_images']['size'][$i] ?? 0,
            ];
        }
    }

    $variantsWereSubmitted = isset($_POST['variants_submitted']);
    $hasSubmittedVariants = $variantsWereSubmitted && !empty($submittedVariants);


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
    // Validate that every requested image removal belongs to this product/vendor.
    if (empty($errors) && $removeGalleryImageIds) {
        $checkGalleryImage = $db->prepare("SELECT id FROM product_images WHERE id = :id AND product_id = :product_id LIMIT 1");
        foreach ($removeGalleryImageIds as $imageId) {
            $checkGalleryImage->execute([':id' => $imageId, ':product_id' => $productId]);
            if (!$checkGalleryImage->fetchColumn()) {
                $errors[] = 'An invalid product gallery image was selected for removal.';
                break;
            }
        }
    }
    if (empty($errors) && $removeVariantImageIds) {
        $checkVariantImage = $db->prepare("SELECT pvi.id FROM product_variant_images pvi INNER JOIN product_variants pv ON pv.id = pvi.variant_id WHERE pvi.id = :id AND pv.product_id = :product_id LIMIT 1");
        foreach ($removeVariantImageIds as $imageId) {
            $checkVariantImage->execute([':id' => $imageId, ':product_id' => $productId]);
            if (!$checkVariantImage->fetchColumn()) {
                $errors[] = 'An invalid variant image was selected for removal.';
                break;
            }
        }
    }

    $discount = 0;

    if ($originalPrice > 0 && $price < $originalPrice) {
        $discount = (($originalPrice - $price) / $originalPrice) * 100;
        $discount = round($discount, 2);
    }
    $removeMainImage = isset($_POST['remove_main_image']) && $_POST['remove_main_image'] === '1';
    $newImagePath = $removeMainImage ? '' : $product['image'];

    if (empty($errors) && $hasSubmittedVariants) {

        foreach ($submittedVariants as $index => $variant) {

            if (!is_array($variant)) {
                $errors[] = 'Invalid variant data submitted.';
                break;
            }

            $variantTitle = trim(
                (string) ($variant['variant_title'] ?? '')
            );

            $variantSku = trim(
                (string) ($variant['sku'] ?? '')
            );

            $variantPrice = filter_var(
                $variant['price'] ?? null,
                FILTER_VALIDATE_FLOAT
            );

            $variantOriginalPrice = filter_var(
                $variant['original_price'] ?? null,
                FILTER_VALIDATE_FLOAT
            );

            $variantStock = filter_var(
                $variant['stock'] ?? null,
                FILTER_VALIDATE_INT
            );

            $variantStatus = isset($variant['status'])
                ? (int) $variant['status']
                : 1;

            if ($variantTitle === '') {
                $errors[] =
                    'Variant ' . ($index + 1) . ': title is required.';
            }

            if ($variantSku === '') {
                $errors[] =
                    'Variant ' . ($index + 1) . ': SKU is required.';
            }

            if (
                $variantPrice === false ||
                $variantPrice <= 0
            ) {
                $errors[] =
                    'Variant ' . ($index + 1) . ': selling price must be greater than zero.';
            }

            if (
                $variantOriginalPrice === false ||
                $variantOriginalPrice <= 0
            ) {
                $errors[] =
                    'Variant ' . ($index + 1) . ': original price must be greater than zero.';
            }

            if (
                $variantPrice !== false &&
                $variantOriginalPrice !== false &&
                $variantOriginalPrice < $variantPrice
            ) {
                $errors[] =
                    'Variant ' . ($index + 1) . ': original price cannot be less than selling price.';
            }

            if (
                $variantStock === false ||
                $variantStock < 0
            ) {
                $errors[] =
                    'Variant ' . ($index + 1) . ': stock cannot be negative.';
            }

            if (!in_array($variantStatus, [0, 1], true)) {
                $errors[] =
                    'Variant ' . ($index + 1) . ': invalid status.';
            }

            if (
                $variantTitle !== '' &&
                $variantSku !== '' &&
                $variantPrice !== false &&
                $variantOriginalPrice !== false &&
                $variantStock !== false &&
                $variantPrice > 0 &&
                $variantOriginalPrice >= $variantPrice &&
                $variantStock >= 0 &&
                in_array($variantStatus, [0, 1], true)
            ) {
                $attributes = $variant['attributes'] ?? [];

                if (is_array($attributes)) {
                    $attributes = json_encode(
                        $attributes,
                        JSON_UNESCAPED_UNICODE
                    );
                } elseif (is_string($attributes)) {
                    $decodedAttributes = json_decode(
                        $attributes,
                        true
                    );

                    if (
                        json_last_error() !== JSON_ERROR_NONE ||
                        !is_array($decodedAttributes)
                    ) {
                        $errors[] =
                            'Variant ' . ($index + 1) . ': attributes must be valid JSON or an array.';
                        continue;
                    }

                    $attributes = json_encode(
                        $decodedAttributes,
                        JSON_UNESCAPED_UNICODE
                    );
                } else {
                    $errors[] =
                        'Variant ' . ($index + 1) . ': invalid attributes.';
                    continue;
                }

                $variantsToSave[] = [
                    'id' => (int) ($variant['id'] ?? 0),
                    'variant_title' => $variantTitle,
                    'sku' => $variantSku,
                    'price' => (float) $variantPrice,
                    'original_price' => (float) $variantOriginalPrice,
                    'stock' => (int) $variantStock,
                    'attributes' => $attributes,
                    'status' => $variantStatus,
                    'form_index' => $index
                ];
            }
        }



        if ($variantsWereSubmitted) {
            $seenVariantSkus = [];
            $seenVariantIds = [];

            foreach ($variantsToSave as $variant) {
                $variantSku = trim($variant['sku'] ?? '');

                if ($variantSku === '') {
                    $errors[] = 'Every variant must have a SKU.';
                    continue;
                }

                $skuKey = strtolower($variantSku);

                if (isset($seenVariantSkus[$skuKey])) {
                    $errors[] = 'Duplicate variant SKU: ' . $variantSku;
                } else {
                    $seenVariantSkus[$skuKey] = true;
                }

                if (!empty($variant['id'])) {
                    $variantId = (int) $variant['id'];

                    if (isset($seenVariantIds[$variantId])) {
                        $errors[] = 'The same variant was submitted more than once.';
                        continue;
                    }

                    $seenVariantIds[$variantId] = true;

                    $checkVariant = $db->prepare("
                SELECT id
                FROM product_variants
                WHERE id = :variant_id
                  AND product_id = :product_id
                LIMIT 1
            ");

                    $checkVariant->execute([
                        ':variant_id' => $variantId,
                        ':product_id' => $productId
                    ]);

                    if (!$checkVariant->fetchColumn()) {
                        $errors[] = 'An invalid variant was submitted.';
                    }
                }
            }
        }
    }

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
                'jfif',
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
    $uploadedVariantImagePaths = [];
    $uploadedGalleryImagePaths = [];
    $removedImagePaths = [];
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

            // Delete selected gallery images, but retain their file paths until commit succeeds.
            if ($removeGalleryImageIds) {
                $findRemovedGallery = $db->prepare("SELECT image_path FROM product_images WHERE id = :id AND product_id = :product_id");
                $deleteGallery = $db->prepare("DELETE FROM product_images WHERE id = :id AND product_id = :product_id");
                foreach ($removeGalleryImageIds as $imageId) {
                    $findRemovedGallery->execute([':id' => $imageId, ':product_id' => $productId]);
                    $oldPath = $findRemovedGallery->fetchColumn();
                    if ($oldPath !== false) $removedImagePaths[] = $oldPath;
                    $deleteGallery->execute([':id' => $imageId, ':product_id' => $productId]);
                }
            }

            if ($removeVariantImageIds) {
                $findRemovedVariant = $db->prepare("SELECT pvi.image_path FROM product_variant_images pvi INNER JOIN product_variants pv ON pv.id = pvi.variant_id WHERE pvi.id = :id AND pv.product_id = :product_id");
                $deleteVariantImage = $db->prepare("DELETE pvi FROM product_variant_images pvi INNER JOIN product_variants pv ON pv.id = pvi.variant_id WHERE pvi.id = :id AND pv.product_id = :product_id");
                foreach ($removeVariantImageIds as $imageId) {
                    $findRemovedVariant->execute([':id' => $imageId, ':product_id' => $productId]);
                    $oldPath = $findRemovedVariant->fetchColumn();
                    if ($oldPath !== false) $removedImagePaths[] = $oldPath;
                    $deleteVariantImage->execute([':id' => $imageId, ':product_id' => $productId]);
                }
            }

            // Append newly uploaded additional product gallery images.
            if ($galleryUploadFiles) {
                $sortStmt = $db->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM product_images WHERE product_id = :product_id");
                $sortStmt->execute([':product_id' => $productId]);
                $gallerySortOrder = (int) $sortStmt->fetchColumn() + 1;
                $insertGalleryImage = $db->prepare("INSERT INTO product_images (product_id, image_path, alt_text, sort_order) VALUES (:product_id, :image_path, :alt_text, :sort_order)");
                foreach ($galleryUploadFiles as $galleryFile) {
                    if (($galleryFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                    $uploadedGallery = uploadProductEditImage($galleryFile);
                    $uploadedGalleryImagePaths[] = $uploadedGallery['absolute_path'];
                    $insertGalleryImage->execute([
                        ':product_id' => $productId,
                        ':image_path' => $uploadedGallery['path'],
                        ':alt_text' => $title,
                        ':sort_order' => $gallerySortOrder++
                    ]);
                }
            }

            if ($variantsWereSubmitted) {

                $activeVariantStock = 0;

                $insertVariantStmt = $db->prepare("
        INSERT INTO product_variants
        (
            product_id,
            variant_title,
            sku,
            price,
            original_price,
            stock,
            attributes,
            status
        )
        VALUES
        (
            :product_id,
            :variant_title,
            :sku,
            :price,
            :original_price,
            :stock,
            :attributes,
            :status
        )
    ");

                $updateVariantStmt = $db->prepare("
        UPDATE product_variants
        SET
            variant_title = :variant_title,
            sku = :sku,
            price = :price,
            original_price = :original_price,
            stock = :stock,
            attributes = :attributes,
            status = :status
        WHERE id = :variant_id
          AND product_id = :product_id
    ");

                $insertVariantImageStmt = $db->prepare("
        INSERT INTO product_variant_images
        (
            variant_id,
            image_path,
            alt_text,
            sort_order
        )
        VALUES
        (
            :variant_id,
            :image_path,
            :alt_text,
            :sort_order
        )
    ");

                foreach ($variantsToSave as $variant) {

                    $variantData = [
                        ':product_id' => $productId,
                        ':variant_title' => $variant['variant_title'],
                        ':sku' => $variant['sku'],
                        ':price' => $variant['price'],
                        ':original_price' => $variant['original_price'],
                        ':stock' => $variant['stock'],
                        ':attributes' => $variant['attributes'],
                        ':status' => $variant['status']
                    ];
                    if (!empty($variant['id'])) {

                        $variantId = (int) $variant['id'];

                        $variantData[':variant_id'] = $variantId;

                        $updateVariantStmt->execute($variantData);
                    } else {

                        $insertVariantStmt->execute($variantData);

                        $variantId = (int) $db->lastInsertId();
                    }
                    $formIndex = $variant['form_index'] ?? null;

                    $variantImageFiles = [];

                    if (
                        $formIndex !== null &&
                        isset($uploadedVariantFiles[$formIndex]['images']) &&
                        is_array($uploadedVariantFiles[$formIndex]['images'])
                    ) {
                        $variantImageFiles = $uploadedVariantFiles[$formIndex]['images'];
                    }

                    $variantSortStmt = $db->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM product_variant_images WHERE variant_id = :variant_id");
                    $variantSortStmt->execute([':variant_id' => $variantId]);
                    $imageSortOrder = (int) $variantSortStmt->fetchColumn() + 1;

                    foreach ($variantImageFiles as $imageFile) {
                        if (
                            !isset($imageFile['error']) ||
                            $imageFile['error'] === UPLOAD_ERR_NO_FILE
                        ) {
                            continue;
                        }
                        $uploadedImage = uploadProductEditImage($imageFile);
                        $uploadedVariantImagePaths[] = $uploadedImage['absolute_path'];

                        $insertVariantImageStmt->execute([
                            ':variant_id' => $variantId,
                            ':image_path' => $uploadedImage['path'],
                            ':alt_text' => $variant['variant_title'],
                            ':sort_order' => $imageSortOrder
                        ]);

                        $imageSortOrder++;
                    }

                    if ((int) $variant['status'] === 1) {
                        $activeVariantStock += (int) $variant['stock'];
                    }
                }

                if (!empty($variantsToSave)) {

                    $updateMainStock = $db->prepare("
            UPDATE products
            SET stock = :stock
            WHERE id = :product_id
              AND vendor_id = :vendor_id
        ");

                    $updateMainStock->execute([
                        ':stock' => $activeVariantStock,
                        ':product_id' => $productId,
                        ':vendor_id' => $vendorId
                    ]);
                }
            }



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

            // Remove files only after the database transaction commits; restrict deletion to uploads folder.
            foreach ($removedImagePaths as $relativePath) {
                $normalizedPath = ltrim(str_replace('\\', '/', (string) $relativePath), '/');
                if (strpos($normalizedPath, 'assets/uploads/') === 0) {
                    $candidate = realpath(__DIR__ . '/../' . $normalizedPath);
                    $uploadRoot = realpath(__DIR__ . '/../assets/uploads');
                    if ($candidate && $uploadRoot && strpos($candidate, $uploadRoot . DIRECTORY_SEPARATOR) === 0 && is_file($candidate)) {
                        @unlink($candidate);
                    }
                }
            }
            if (($uploadedNewImage || $removeMainImage) && !empty($product['image'])) {

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
            $zohoResult = syncProductToZoho([
                'name' => $title,
                'sku' => $sku,
                'description' => $description,
                'rate' => (float) $price,
                'purchase_rate' => (float) $originalPrice,
                'stock' => (int) $stock
            ]);

            if (!empty($zohoResult['success'])) {

                $success =
                    "Product updated successfully in the local database, Shopify and Zoho Inventory.";
            } else {

                $zohoError = $zohoResult['message']
                    ?? 'Unknown Zoho synchronization error.';

                $success =
                    "Product updated locally and in Shopify, but Zoho synchronization failed: "
                    . $zohoError;
            }
        } catch (Exception $e) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }
            foreach (array_merge($uploadedVariantImagePaths, $uploadedGalleryImagePaths) as $uploadedPath) {
                if (is_file($uploadedPath)) {
                    unlink($uploadedPath);
                }
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
    <style>
        .image-tile { display: inline-block; position: relative; }
        .image-cut-btn { position: absolute; top: -7px; right: -7px; width: 24px; height: 24px; padding: 0; line-height: 20px; border-radius: 50%; font-weight: 700; }
    </style>

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

                                            <div class="image-tile position-relative" data-image-tile>
                                                <img
                                                    src="../<?= htmlspecialchars($product['image']) ?>"
                                                    alt="Product"
                                                    style="width: 120px; height: 120px; object-fit: cover;"
                                                    class="rounded border">
                                                <button type="button" class="btn btn-danger btn-sm image-cut-btn" aria-label="Remove main product image" data-remove-name="remove_main_image" data-remove-id="1">×</button>
                                            </div>

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
                                        accept=".jpg,.jpeg,.jfif,.png,.webp">

                                    <small class="text-muted">
                                        JPG, JPEG, JFIF, PNG or WEBP. Maximum 5 MB.
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

                                <!-- Variant management -->
                                <div class="card mt-4">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <div>
                                            <h5 class="mb-1">Product Variants</h5>
                                            <small class="text-muted">
                                                Manage sizes, colors, SKUs, prices and stock.
                                            </small>
                                        </div>

                                        <button type="button" class="btn btn-primary btn-sm" id="addVariantBtn">
                                            + Add Variant
                                        </button>
                                    </div>

                                    <div class="card-body">
                                        <input type="hidden" name="variants_submitted" value="1">

                                        <div id="variantsContainer"></div>

                                        <div id="noVariantsMessage" class="text-muted text-center py-3">
                                            No variants added yet. Click "Add Variant" to create one.
                                        </div>

                                        <small class="text-muted d-block mt-2">
                                            Deactivating a variant keeps its record but excludes its stock
                                            from the active variant stock total.
                                        </small>
                                    </div>
                                </div>

                                <template id="variantTemplate">
                                    <div class="variant-item border rounded p-3 mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h6 class="variant-heading mb-0">Variant</h6>

                                            <button type="button" class="btn btn-outline-danger btn-sm removeVariantBtn">
                                                Remove
                                            </button>
                                        </div>

                                        <input type="hidden" data-field="id" value="0">

                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Variant Name</label>
                                                <input type="text" class="form-control"
                                                    data-field="variant_title"
                                                    placeholder="e.g. Red - Large" required>
                                            </div>

                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">SKU</label>
                                                <input type="text" class="form-control"
                                                    data-field="sku"
                                                    placeholder="e.g. SHIRT-RED-L" required>
                                            </div>

                                            <div class="col-md-3 mb-3">
                                                <label class="form-label">Selling Price</label>
                                                <input type="number" class="form-control"
                                                    data-field="price" min="0" step="0.01" required>
                                            </div>

                                            <div class="col-md-3 mb-3">
                                                <label class="form-label">Original Price</label>
                                                <input type="number" class="form-control"
                                                    data-field="original_price" min="0" step="0.01" required>
                                            </div>

                                            <div class="col-md-3 mb-3">
                                                <label class="form-label">Stock</label>
                                                <input type="number" class="form-control"
                                                    data-field="stock" min="0" step="1" required>
                                            </div>

                                            <div class="col-md-3 mb-3">
                                                <label class="form-label">Status</label>
                                                <select class="form-select" data-field="status">
                                                    <option value="1">Active</option>
                                                    <option value="0">Inactive</option>
                                                </select>
                                            </div>

                                            <div class="col-12 mb-3">
                                                <label class="form-label">Attributes (JSON)</label>
                                                <textarea class="form-control"
                                                    data-field="attributes"
                                                    rows="2"
                                                    placeholder='{"Color":"Red","Size":"Large"}'></textarea>
                                                <small class="text-muted">
                                                    Example: {"Color":"Red","Size":"Large"}
                                                </small>
                                            </div>
                                        </div>
                                        <!-- <script>
                                            document.addEventListener('DOMContentLoaded', function() {
                                                const galleryInput = document.querySelector('input[name="gallery_images[]"]');
                                                const galleryPreview = document.getElementById('galleryPreview');

                                                if (!galleryInput || !galleryPreview) return;

                                                galleryInput.addEventListener('change', function() {
                                                    galleryPreview.innerHTML = '';

                                                    Array.from(galleryInput.files).forEach(file => {
                                                        const img = document.createElement('img');
                                                        img.src = URL.createObjectURL(file);
                                                        img.alt = file.name;
                                                        img.style.width = '85px';
                                                        img.style.height = '85px';
                                                        img.style.objectFit = 'cover';
                                                        img.className = 'border rounded';

                                                        galleryPreview.appendChild(img);
                                                    });
                                                });
                                            });
                                        </script> -->
                                    </div>
                                </template>
                                <div class="card mt-4">
                                    <div class="card-header">
                                        <h5 class="mb-0">Additional Product Images</h5>
                                    </div>

                                    <div class="card-body">
                                        <label class="form-label">Upload Gallery Images</label>
                                        <input type="file"
                                            name="gallery_images[]"
                                            class="form-control"
                                            accept=".jpg,.jpeg,.jfif,.png,.webp"
                                            multiple>

                                        <small class="text-muted d-block mt-2">
                                            Select multiple images. Your current main image and gallery images
                                            will remain unchanged unless explicitly removed.
                                        </small>

                                        <div id="galleryPreview" class="d-flex flex-wrap gap-2 mt-3">
                                            <?php foreach ($galleryImages as $galleryImage): ?>
                                                <div class="image-tile position-relative" data-image-tile>
                                                    <img
                                                        src="../<?= htmlspecialchars(ltrim($galleryImage['image_path'], '/'), ENT_QUOTES, 'UTF-8') ?>"
                                                        alt="<?= htmlspecialchars($galleryImage['alt_text'] ?? 'Product image', ENT_QUOTES, 'UTF-8') ?>"
                                                        style="width:85px;height:85px;object-fit:cover;"
                                                        class="border rounded">
                                                    <button type="button" class="btn btn-danger btn-sm image-cut-btn" aria-label="Remove gallery image" data-remove-name="remove_gallery_images[]" data-remove-id="<?= (int) $galleryImage['id'] ?>">×</button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
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
    <script>
        window.existingProductVariants = <?= json_encode(
                                                array_map(static function ($variant) use ($variantImages) {
                                                    $variantId = (int) $variant['id'];

                                                    return [
                                                        'id' => $variantId,
                                                        'variant_title' => $variant['variant_title'] ?? '',
                                                        'sku' => $variant['sku'] ?? '',
                                                        'price' => $variant['price'] ?? 0,
                                                        'original_price' => $variant['original_price'] ?? 0,
                                                        'stock' => $variant['stock'] ?? 0,
                                                        'status' => $variant['status'] ?? 1,
                                                        'attributes' => $variant['attributes'] ?? '{}',
                                                        'images' => array_values(array_map(
                                                            static function ($image) {
                                                                return [
                                                                    'id' => (int) ($image['id'] ?? 0),
                                                                    'image_path' => $image['image_path'] ?? '',
                                                                    'alt_text' => $image['alt_text'] ?? ''
                                                                ];
                                                            },
                                                            $variantImages[$variantId] ?? []
                                                        ))
                                                    ];
                                                }, $variants),
                                                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                                            ) ?>;


        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('variantsContainer');
            const template = document.getElementById('variantTemplate');
            const addButton = document.getElementById('addVariantBtn');
            const emptyMessage = document.getElementById('noVariantsMessage');

            if (!container || !template || !addButton) return;

            let nextIndex = 0;

            function refreshVariantNames() {
                const items = container.querySelectorAll('.variant-item');

                items.forEach((item, index) => {
                    item.querySelector('.variant-heading').textContent =
                        'Variant ' + (index + 1);

                    item.querySelectorAll('[data-field]').forEach(input => {
                        input.name = `variants[${index}][${input.dataset.field}]`;
                    });

                    const imageInput = item.querySelector('[data-variant-images]');

                    if (imageInput) {
                        imageInput.name = `variants[${index}][images][]`;
                    }
                });

                emptyMessage.style.display = items.length ? 'none' : 'block';
            }


            function addVariant(data = {}) {
                const fragment = template.content.cloneNode(true);
                const item = fragment.querySelector('.variant-item');
                item.querySelectorAll('[data-field]').forEach(input => {
                    const field = input.dataset.field;

                    if (Object.prototype.hasOwnProperty.call(data, field)) {
                        input.value = data[field] ?? '';
                    } else if (field === 'id') {
                        input.value = '0';
                    } else if (field === 'status') {
                        input.value = '1';
                    } else if (field === 'attributes') {
                        input.value = '{}';
                    } else {
                        input.value = '';
                    }
                });

                item.querySelector('.removeVariantBtn').addEventListener('click', function() {
                    item.remove();
                    refreshVariantNames();
                });
                const imageSection = document.createElement('div');
                imageSection.className = 'mb-3';

                imageSection.innerHTML = `
        <label class="form-label">Variant Images</label>
        <input type="file"
               class="form-control"
               data-variant-images
               accept=".jpg,.jpeg,.jfif,.png,.webp"
               multiple>
        <small class="text-muted">
            Select multiple images. Existing images will be preserved.
        </small>
        <div class="variant-image-preview d-flex flex-wrap gap-2 mt-2"></div>
    `;

                item.querySelector('.row').appendChild(imageSection);

                const preview = imageSection.querySelector('.variant-image-preview');
                const imageInput = imageSection.querySelector('[data-variant-images]');
                (Array.isArray(data.images) ? data.images : []).forEach(image => {
                    if (!image.image_path) return;

                    const img = document.createElement('img');
                    img.src = '/e-commerce/' + image.image_path.replace(/^\/+/, '');
                    img.alt = image.alt_text || 'Variant image';
                    img.style.width = '75px';
                    img.style.height = '75px';
                    img.style.objectFit = 'cover';
                    img.className = 'border rounded';

                    img.onerror = function() {
                        console.error('Could not load existing variant image:', img.src);
                    };

                    const tile = document.createElement('div');
                    tile.className = 'image-tile position-relative';
                    tile.dataset.imageTile = '1';
                    tile.appendChild(img);
                    if (image.id) {
                        const cutButton = document.createElement('button');
                        cutButton.type = 'button';
                        cutButton.className = 'btn btn-danger btn-sm image-cut-btn';
                        cutButton.textContent = '×';
                        cutButton.setAttribute('aria-label', 'Remove variant image');
                        cutButton.dataset.removeName = 'remove_variant_images[]';
                        cutButton.dataset.removeId = String(image.id);
                        tile.appendChild(cutButton);
                    }
                    preview.appendChild(tile);
                });
                imageInput.addEventListener('change', function() {
                    preview.querySelectorAll('[data-new-image-preview]').forEach(img => {
                        URL.revokeObjectURL(img.src);
                        img.remove();
                    });

                    Array.from(imageInput.files).forEach(file => {
                        const img = document.createElement('img');

                        img.src = URL.createObjectURL(file);
                        img.alt = file.name;
                        img.dataset.newImagePreview = '1';
                        img.style.width = '75px';
                        img.style.height = '75px';
                        img.style.objectFit = 'cover';
                        img.className = 'border rounded';

                        preview.appendChild(img);
                    });
                });

                container.appendChild(item);
                nextIndex++;
                refreshVariantNames();

                console.log('Variant data:', data);
                console.log('Variant images:', data.images);
            }


            addButton.addEventListener('click', () => addVariant());
            const existingVariants = window.existingProductVariants || [];

            existingVariants.forEach(variant => addVariant(variant));

            refreshVariantNames();
        });
        document.addEventListener('DOMContentLoaded', function() {
            const galleryInput = document.querySelector('input[name="gallery_images[]"]');
            const galleryPreview = document.getElementById('galleryPreview');

            if (!galleryInput || !galleryPreview) return;

            galleryInput.addEventListener('change', function() {
                galleryPreview.querySelectorAll('[data-new-gallery-preview]').forEach(node => {
                    if (node.querySelector('img')?.src) URL.revokeObjectURL(node.querySelector('img').src);
                    node.remove();
                });

                Array.from(galleryInput.files).forEach(file => {
                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(file);
                    img.alt = file.name;
                    img.style.width = '85px';
                    img.style.height = '85px';
                    img.style.objectFit = 'cover';
                    img.className = 'border rounded';
                    const tile = document.createElement('div');
                    tile.className = 'image-tile position-relative';
                    tile.dataset.newGalleryPreview = '1';
                    tile.appendChild(img);
                    galleryPreview.appendChild(tile);
                });
            });
        });
        document.addEventListener('click', function(event) {
            const button = event.target.closest('.image-cut-btn');
            if (!button) return;
            const form = button.closest('form');
            const tile = button.closest('[data-image-tile], .image-tile');
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = button.dataset.removeName;
            input.value = button.dataset.removeId;
            form.appendChild(input);
            if (tile) tile.remove();
        });
    </script>
</body>

</html>