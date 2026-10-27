<?php

require_once "auth.php";
require_once "../connection/dbconnect.php";
require_once "../shopify/functions.php";
require_once "../zoho/zoho_functions.php";


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
$removedImagePaths = [];
$removeMainImage = false;

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
$imageStmt = $pdo->prepare("
    SELECT id, image_path, alt_text, sort_order, status
    FROM product_images
    WHERE product_id = :product_id
    ORDER BY sort_order ASC, id ASC
");

$imageStmt->execute([
    ':product_id' => $productId
]);

$productImages = $imageStmt->fetchAll(PDO::FETCH_ASSOC);

$variantStmt = $pdo->prepare("
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
$variantImageStmt = $pdo->prepare("
    SELECT
        id,
        variant_id,
        image_path,
        alt_text,
        sort_order,
        status
    FROM product_variant_images
    WHERE variant_id = :variant_id
    ORDER BY sort_order ASC, id ASC
");

foreach ($variants as &$variant) {
    $variantImageStmt->execute([
        ':variant_id' => (int) $variant['id']
    ]);

    $variant['images'] = $variantImageStmt->fetchAll(
        PDO::FETCH_ASSOC
    );
    $decodedAttributes = json_decode(
        $variant['attributes'] ?? '{}',
        true
    );

    $variant['decoded_attributes'] = is_array($decodedAttributes)
        ? $decodedAttributes
        : [];
}

unset($variant);

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
        try {
            $submittedVariants = $_POST['variants'] ?? [];
            $deletedVariantIds = $_POST['deleted_variant_ids'] ?? [];
            $removeMainImage = isset($_POST['remove_main_image']) && $_POST['remove_main_image'] === '1';
            $removeGalleryIds = $_POST['remove_gallery_images'] ?? [];
            $removeVariantImageIds = $_POST['remove_variant_images'] ?? [];
            if (!is_array($removeGalleryIds) || !is_array($removeVariantImageIds)) {
                throw new RuntimeException('Invalid image removal request.');
            }
            $removeGalleryIds = array_values(array_unique(array_filter(array_map('intval', $removeGalleryIds), static fn($v) => $v > 0)));
            $removeVariantImageIds = array_values(array_unique(array_filter(array_map('intval', $removeVariantImageIds), static fn($v) => $v > 0)));

            if (!is_array($submittedVariants) || !is_array($deletedVariantIds)) {
                throw new RuntimeException("Invalid variant form data.");
            }

            $normalisedVariants = [];
            $seenVariantSkus = [];
            $seenVariantIds = [];
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

            if ($skuStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException("This product SKU already exists.");
            }
            $deleteIds = [];

            foreach ($deletedVariantIds as $deletedId) {
                $deletedId = filter_var($deletedId, FILTER_VALIDATE_INT);

                if ($deletedId === false || $deletedId <= 0) {
                    throw new RuntimeException("Invalid deleted variant ID.");
                }

                $deleteIds[] = (int) $deletedId;
            }

            $deleteIds = array_values(array_unique($deleteIds));
            if ($deleteIds) {
                $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));

                $deleteCheck = $pdo->prepare("
                SELECT id
                FROM product_variants
                WHERE product_id = ?
                  AND id IN ($placeholders)
            ");

                $deleteCheck->execute(array_merge([$productId], $deleteIds));

                if (count($deleteCheck->fetchAll(PDO::FETCH_COLUMN)) !== count($deleteIds)) {
                    throw new RuntimeException("A variant selected for removal does not belong to this product.");
                }
            }
            foreach ($submittedVariants as $index => $variant) {
                if (!is_array($variant)) {
                    throw new RuntimeException("Invalid variant data.");
                }

                $variantId = trim((string) ($variant['id'] ?? ''));
                $variantTitle = trim((string) ($variant['title'] ?? ''));
                $variantSku = trim((string) ($variant['sku'] ?? ''));
                $variantPrice = trim((string) ($variant['price'] ?? ''));
                $variantOriginalPrice = trim((string) ($variant['original_price'] ?? ''));
                $variantStock = trim((string) ($variant['stock'] ?? ''));
                $attributeText = trim((string) ($variant['attributes'] ?? ''));

                if ($variantTitle === '' || $variantSku === '') {
                    throw new RuntimeException("Every variant needs a title and SKU.");
                }

                if (
                    $variantPrice === '' ||
                    !is_numeric($variantPrice) ||
                    (float) $variantPrice < 0
                ) {
                    throw new RuntimeException("Enter a valid selling price for every variant.");
                }

                if (
                    $variantOriginalPrice !== '' &&
                    (!is_numeric($variantOriginalPrice) || (float) $variantOriginalPrice < 0)
                ) {
                    throw new RuntimeException("Enter a valid original price for every variant.");
                }

                if (
                    $variantStock === '' ||
                    filter_var($variantStock, FILTER_VALIDATE_INT) === false ||
                    (int) $variantStock < 0
                ) {
                    throw new RuntimeException("Enter a valid whole-number stock for every variant.");
                }
                $skuKey = strtolower($variantSku);

                if (isset($seenVariantSkus[$skuKey])) {
                    throw new RuntimeException("Duplicate variant SKU: " . $variantSku);
                }

                $seenVariantSkus[$skuKey] = true;

                $id = null;

                if ($variantId !== '') {
                    $id = filter_var($variantId, FILTER_VALIDATE_INT);

                    if ($id === false || $id <= 0 || in_array((int) $id, $deleteIds, true)) {
                        throw new RuntimeException("Invalid or conflicting variant ID.");
                    }

                    $id = (int) $id;

                    if (isset($seenVariantIds[$id])) {
                        throw new RuntimeException("A variant was submitted more than once.");
                    }

                    $seenVariantIds[$id] = true;
                }
                $attributes = [];

                if ($attributeText !== '') {
                    $parts = preg_split('/[,;\r\n]+/', $attributeText);

                    foreach ($parts as $part) {
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

                $normalisedVariants[] = [
                    'form_index' => $index,
                    'id' => $id,
                    'title' => $variantTitle,
                    'sku' => $variantSku,
                    'price' => round((float) $variantPrice, 2),
                    'original_price' => $variantOriginalPrice !== ''
                        ? round((float) $variantOriginalPrice, 2)
                        : null,
                    'stock' => (int) $variantStock,
                    'attributes' => $attributes
                        ? json_encode($attributes, JSON_UNESCAPED_UNICODE)
                        : null
                ];
            }
            $existingIds = array_values(array_filter(array_column($normalisedVariants, 'id')));

            if ($existingIds) {
                $placeholders = implode(',', array_fill(0, count($existingIds), '?'));

                $existingCheck = $pdo->prepare("
                SELECT id
                FROM product_variants
                WHERE product_id = ?
                  AND id IN ($placeholders)
            ");

                $existingCheck->execute(array_merge([$productId], $existingIds));

                if (count($existingCheck->fetchAll(PDO::FETCH_COLUMN)) !== count($existingIds)) {
                    throw new RuntimeException("A submitted variant does not belong to this product.");
                }
            }
            foreach ($normalisedVariants as $variant) {
                $checkSku = $pdo->prepare("
                SELECT id
                FROM products
                WHERE sku = :product_sku

                UNION ALL

                SELECT id
                FROM product_variants
                WHERE sku = :variant_sku
                  AND (:variant_id IS NULL OR id != :exclude_variant_id)

                LIMIT 1
            ");

                $checkSku->execute([
                    ':product_sku' => $variant['sku'],
                    ':variant_sku' => $variant['sku'],
                    ':variant_id' => $variant['id'],
                    ':exclude_variant_id' => $variant['id']
                ]);

                $conflict = $checkSku->fetch(PDO::FETCH_ASSOC);
                if ($conflict) {
                    $isOwnProductSku = false;

                    $ownSkuCheck = $pdo->prepare("
                    SELECT id
                    FROM products
                    WHERE id = :id AND sku = :sku
                    LIMIT 1
                ");

                    $ownSkuCheck->execute([
                        ':id' => $productId,
                        ':sku' => $variant['sku']
                    ]);

                    $isOwnProductSku = (bool) $ownSkuCheck->fetch(PDO::FETCH_ASSOC);

                    if ($isOwnProductSku) {
                        throw new RuntimeException(
                            "Variant SKU cannot be the same as the main product SKU: " . $variant['sku']
                        );
                    }
                    $ownVariantCheck = $pdo->prepare("
                    SELECT id
                    FROM product_variants
                    WHERE product_id = :product_id
                      AND sku = :sku
                      AND id = :variant_id
                    LIMIT 1
                ");

                    $ownVariantCheck->execute([
                        ':product_id' => $productId,
                        ':sku' => $variant['sku'],
                        ':variant_id' => $variant['id'] ?? 0
                    ]);

                    if (!$ownVariantCheck->fetch(PDO::FETCH_ASSOC)) {
                        throw new RuntimeException("Variant SKU already exists: " . $variant['sku']);
                    }
                }
            }
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $messageType = "danger";
        } catch (Throwable $e) {
            error_log("Variant validation error: " . $e->getMessage());
            $message = "Unable to validate variants. Please check the server error log.";
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
    $imageName = $removeMainImage ? '' : ($product['image'] ?? '');
    $uploadedPaths = [];
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
                    $message = "An additional image upload failed. Please check your PHP upload limits.";
                    $messageType = "danger";
                    break;
                }

                $imageFiles[] = [
                    'tmp_name' => $_FILES['images']['tmp_name'][$i],
                    'size' => (int) $_FILES['images']['size'][$i]
                ];
            }
        }

        if ($message === '' && !empty($imageFiles)) {
            $uploadDirectory = "../assets/uploads/";

            if (
                !is_dir($uploadDirectory) &&
                !mkdir($uploadDirectory, 0755, true) &&
                !is_dir($uploadDirectory)
            ) {
                $message = "Could not create the image upload directory.";
                $messageType = "danger";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);

                $allowedMimeTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp'
                ];

                foreach ($imageFiles as $file) {
                    if ($file['size'] > 5 * 1024 * 1024) {
                        $message = "Each image must not exceed 5MB.";
                        $messageType = "danger";
                        break;
                    }

                    if (!is_uploaded_file($file['tmp_name'])) {
                        $message = "Invalid image upload.";
                        $messageType = "danger";
                        break;
                    }

                    $mimeType = $finfo->file($file['tmp_name']);

                    if (!isset($allowedMimeTypes[$mimeType])) {
                        $message = "Only JPG, PNG and WEBP images are allowed.";
                        $messageType = "danger";
                        break;
                    }

                    $extension = $allowedMimeTypes[$mimeType];
                    $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
                    $destination = $uploadDirectory . $fileName;

                    if (!move_uploaded_file($file['tmp_name'], $destination)) {
                        $message = "Failed to save an additional image.";
                        $messageType = "danger";
                        break;
                    }

                    $uploadedPaths[] = "assets/uploads/" . $fileName;
                }
            }
        }
    }
    if ($message === '') {


        $pdo->beginTransaction();

        try {
            $stockToSave = $stock;

            // Validate image IDs against this product before deleting any records.
            if ($removeGalleryIds) {
                $marks = implode(',', array_fill(0, count($removeGalleryIds), '?'));
                $check = $pdo->prepare("SELECT id, image_path FROM product_images WHERE product_id = ? AND id IN ($marks)");
                $check->execute(array_merge([$productId], $removeGalleryIds));
                $rows = $check->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) !== count($removeGalleryIds)) {
                    throw new RuntimeException('A gallery image does not belong to this product.');
                }
                foreach ($rows as $row) $removedImagePaths[] = $row['image_path'];
                $del = $pdo->prepare("DELETE FROM product_images WHERE product_id = ? AND id IN ($marks)");
                $del->execute(array_merge([$productId], $removeGalleryIds));
            }

            if ($removeVariantImageIds) {
                $marks = implode(',', array_fill(0, count($removeVariantImageIds), '?'));
                $check = $pdo->prepare("SELECT pvi.id, pvi.image_path FROM product_variant_images pvi INNER JOIN product_variants pv ON pv.id = pvi.variant_id WHERE pv.product_id = ? AND pvi.id IN ($marks)");
                $check->execute(array_merge([$productId], $removeVariantImageIds));
                $rows = $check->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) !== count($removeVariantImageIds)) {
                    throw new RuntimeException('A variant image does not belong to this product.');
                }
                foreach ($rows as $row) $removedImagePaths[] = $row['image_path'];
                $del = $pdo->prepare("DELETE pvi FROM product_variant_images pvi INNER JOIN product_variants pv ON pv.id = pvi.variant_id WHERE pv.product_id = ? AND pvi.id IN ($marks)");
                $del->execute(array_merge([$productId], $removeVariantImageIds));
            }

            $updateProduct = $pdo->prepare("
        UPDATE products
        SET
            sku = :sku,
            title = :title,
            category_id = :category_id,
            brand_id = :brand_id,
            price = :price,
            original_price = :original_price,
            discount = :discount,
            stock = :stock,
            description = :description,
            status = :status,
            image = :image
        WHERE id = :id
    ");
            if (!empty($deleteIds)) {
                $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                $deleteImages = $pdo->prepare("
            DELETE FROM product_variant_images
            WHERE variant_id IN ($placeholders)
        ");
                $deleteImages->execute($deleteIds);
                $deleteVariants = $pdo->prepare("
            DELETE FROM product_variants
            WHERE product_id = ?
              AND id IN ($placeholders)
        ");
                $deleteVariants->execute(
                    array_merge([$productId], $deleteIds)
                );
            }

            foreach ($normalisedVariants as $variant) {
                if ($variant['id'] !== null) {
                    $variantId = (int) $variant['id'];

                    $saveVariant = $pdo->prepare("
            UPDATE product_variants
            SET
                variant_title = :variant_title,
                sku = :sku,
                price = :price,
                original_price = :original_price,
                stock = :stock,
                attributes = :attributes
            WHERE id = :id
              AND product_id = :product_id
        ");

                    $saveVariant->execute([
                        ':variant_title' => $variant['title'],
                        ':sku' => $variant['sku'],
                        ':price' => $variant['price'],
                        ':original_price' => $variant['original_price'],
                        ':stock' => $variant['stock'],
                        ':attributes' => $variant['attributes'],
                        ':id' => $variantId,
                        ':product_id' => $productId
                    ]);
                } else {
                    $saveVariant = $pdo->prepare("
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

                    $saveVariant->execute([
                        ':product_id' => $productId,
                        ':variant_title' => $variant['title'],
                        ':sku' => $variant['sku'],
                        ':price' => $variant['price'],
                        ':original_price' => $variant['original_price'],
                        ':stock' => $variant['stock'],
                        ':attributes' => $variant['attributes']
                    ]);
                    $variantId = (int) $pdo->lastInsertId();
                }
                $formIndex = $variant['form_index'];

                if (
                    isset($_FILES['variants']['name'][$formIndex]['images']) &&
                    is_array($_FILES['variants']['name'][$formIndex]['images'])
                ) {
                    $imageNames = $_FILES['variants']['name'][$formIndex]['images'];
                    $imageTemps = $_FILES['variants']['tmp_name'][$formIndex]['images'];
                    $imageErrors = $_FILES['variants']['error'][$formIndex]['images'];
                    $imageSizes = $_FILES['variants']['size'][$formIndex]['images'];

                    $sortStmt = $pdo->prepare("
            SELECT COALESCE(MAX(sort_order), -1)
            FROM product_variant_images
            WHERE variant_id = ?
        ");
                    $sortStmt->execute([$variantId]);
                    $sortOrder = (int) $sortStmt->fetchColumn() + 1;

                    foreach ($imageNames as $imageIndex => $originalName) {
                        $uploadError = $imageErrors[$imageIndex] ?? UPLOAD_ERR_NO_FILE;

                        if ($uploadError === UPLOAD_ERR_NO_FILE) {
                            continue;
                        }

                        if ($uploadError !== UPLOAD_ERR_OK) {
                            throw new RuntimeException(
                                'A variant image failed to upload.'
                            );
                        }

                        $tmpName = $imageTemps[$imageIndex] ?? '';
                        $fileSize = (int) ($imageSizes[$imageIndex] ?? 0);

                        if ($fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
                            throw new RuntimeException(
                                'Each variant image must be smaller than 5 MB.'
                            );
                        }

                        if (!is_uploaded_file($tmpName)) {
                            throw new RuntimeException(
                                'Invalid variant image upload.'
                            );
                        }

                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mimeType = $finfo->file($tmpName);

                        $allowedTypes = [
                            'image/jpeg' => 'jpg',
                            'image/png'  => 'png',
                            'image/webp' => 'webp'
                        ];

                        if (!isset($allowedTypes[$mimeType])) {
                            throw new RuntimeException(
                                'Variant images must be JPG, PNG, or WEBP.'
                            );
                        }

                        $uploadDirectory = __DIR__ . '/../assets/uploads/';

                        if (
                            !is_dir($uploadDirectory) &&
                            !mkdir($uploadDirectory, 0755, true) &&
                            !is_dir($uploadDirectory)
                        ) {
                            throw new RuntimeException(
                                'Could not create the image upload directory.'
                            );
                        }

                        $newFileName = bin2hex(random_bytes(16))
                            . '.' . $allowedTypes[$mimeType];

                        $destination = $uploadDirectory . $newFileName;
                        $relativePath = 'assets/uploads/' . $newFileName;

                        if (!move_uploaded_file($tmpName, $destination)) {
                            throw new RuntimeException(
                                'Could not save a variant image.'
                            );
                        }

                        $insertImage = $pdo->prepare("
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

                        $insertImage->execute([
                            ':variant_id' => $variantId,
                            ':image_path' => $relativePath,
                            ':alt_text' => $variant['title'],
                            ':sort_order' => $sortOrder
                        ]);

                        $sortOrder++;
                    }
                }
            }

            $stockStmt = $pdo->prepare("
        SELECT COUNT(*), COALESCE(SUM(stock), 0)
        FROM product_variants
        WHERE product_id = :product_id
          AND status = 1
    ");

            $stockStmt->execute([':product_id' => $productId]);

            [$activeVariantCount, $variantStockTotal] = $stockStmt->fetch(
                PDO::FETCH_NUM
            );

            if ((int) $activeVariantCount > 0) {
                $stockToSave = (int) $variantStockTotal;
            }
            $updateProduct->execute([
                ':sku' => $sku,
                ':title' => $title,
                ':category_id' => $categoryId,
                ':brand_id' => $brandId,
                ':price' => $price,
                ':original_price' => $originalPrice,
                ':discount' => $discount,
                ':stock' => $stockToSave,
                ':description' => $description,
                ':status' => $status,
                ':image' => $imageName,
                ':id' => $productId
            ]);

            $insertImage = $pdo->prepare("
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

            if (!empty($uploadedPaths)) {
                $orderStmt = $pdo->prepare("
        SELECT COALESCE(MAX(sort_order), -1) + 1
        FROM product_images
        WHERE product_id = :product_id
    ");

                $orderStmt->execute([
                    ':product_id' => $productId
                ]);

                $nextImageOrder = (int) $orderStmt->fetchColumn();

                foreach ($uploadedPaths as $uploadedPath) {
                    $insertImage->execute([
                        ':product_id' => $productId,
                        ':image_path' => $uploadedPath,
                        ':alt_text' => $title,
                        ':sort_order' => $nextImageOrder
                    ]);

                    $nextImageOrder++;
                }
            }


            $pdo->commit();
            if (($removeMainImage || ($imageName !== ($product['image'] ?? ''))) && !empty($product['image'])) {
                $removedImagePaths[] = $product['image'];
            }
            foreach (array_unique($removedImagePaths) as $relativePath) {
                $relativePath = ltrim((string) $relativePath, '/\\');
                if (strpos($relativePath, 'assets/uploads/') !== 0) continue;
                $base = realpath(__DIR__ . '/../assets/uploads');
                $candidate = realpath(__DIR__ . '/../' . $relativePath);
                if ($base && $candidate && strpos($candidate, $base . DIRECTORY_SEPARATOR) === 0 && is_file($candidate)) {
                    @unlink($candidate);
                }
            }
            $_SESSION['success_message'] = "Product updated successfully!";

            header("Location: products.php");
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Product and variant save error: " . $e->getMessage());

            $message = "Unable to save the product and its variants. Please check the server error log.";
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
        content="width=device-width, initial-scale=1.0">

    <title>Edit Product - Admin</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">

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

        .image-tile {
            position: relative;
            display: inline-block;
            margin: 4px 8px 8px 0;
        }

        .image-cut-btn {
            position: absolute;
            top: -7px;
            right: -7px;
            z-index: 2;
            width: 23px;
            height: 23px;
            border: 0;
            border-radius: 50%;
            background: #dc3545;
            color: #fff;
            font-size: 17px;
            line-height: 20px;
            padding: 0;
            cursor: pointer;
            box-shadow: 0 1px 4px #999;
        }

        .image-cut-btn:hover {
            background: #bb2d3b;
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
                <a href="reviews.php">
                    <i class="bi bi-cart-check me-2"></i>
                    Product Reviews
                </a>

                <a href="profile.php">
                    <i class="bi bi-person-circle me-2"></i>
                    Admin Profile
                </a>

                <a href="../outh/logout.php">
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
                        class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>
                        Back to Products
                    </a>

                </div>


                <?php if ($message !== ''): ?>

                    <div
                        class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show"
                        role="alert">

                        <?= htmlspecialchars($message) ?>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="alert"></button>

                    </div>

                <?php endif; ?>


                <div class="card">

                    <div class="card-body p-4">

                        <form
                            method="POST"
                            enctype="multipart/form-data">

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
                                        required>

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
                                        required>

                                </div>
                                <div class="col-md-6">

                                    <label class="form-label">
                                        Category
                                        <span class="text-danger">*</span>
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

                                <div class="col-md-6">

                                    <label class="form-label">
                                        Brand
                                        <span class="text-danger">*</span>
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
                                        min="0"
                                        step="0.01"
                                        value="<?= htmlspecialchars($product['original_price'] ?? '') ?>">

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
                                        required>

                                </div>
                                <div class="col-md-12">

                                    <label class="form-label">
                                        Description
                                    </label>

                                    <textarea
                                        name="description"
                                        class="form-control"
                                        rows="5"><?= htmlspecialchars($product['description'] ?? '') ?></textarea>

                                </div>


                                <!-- PRODUCT VARIANTS -->
                                <hr class="my-4">

                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h5 class="mb-1">Product Variants</h5>
                                        <p class="text-muted small mb-0">
                                            Edit existing variants or add new options.
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

                                <div id="variantsContainer">

                                    <?php foreach ($variants as $index => $variant): ?>

                                        <div class="variant-card border rounded-3 p-3 mb-3">

                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <strong class="variant-heading">
                                                    Variant <?= $index + 1 ?>
                                                </strong>

                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-danger remove-variant">
                                                    Remove
                                                </button>
                                            </div>

                                            <input
                                                type="hidden"
                                                name="variants[<?= $index ?>][id]"
                                                value="<?= (int) $variant['id'] ?>">

                                            <div class="row g-3">

                                                <div class="col-md-6">
                                                    <label class="form-label">Variant Title *</label>
                                                    <input
                                                        type="text"
                                                        name="variants[<?= $index ?>][title]"
                                                        class="form-control"
                                                        maxlength="255"
                                                        value="<?= htmlspecialchars($variant['variant_title'] ?? '') ?>"
                                                        required>
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label">Variant SKU *</label>
                                                    <input
                                                        type="text"
                                                        name="variants[<?= $index ?>][sku]"
                                                        class="form-control"
                                                        maxlength="100"
                                                        value="<?= htmlspecialchars($variant['sku'] ?? '') ?>"
                                                        required>
                                                </div>

                                                <div class="col-md-4">
                                                    <label class="form-label">Selling Price *</label>
                                                    <input
                                                        type="number"
                                                        name="variants[<?= $index ?>][price]"
                                                        class="form-control"
                                                        min="0"
                                                        max="99999999.99"
                                                        step="0.01"
                                                        value="<?= htmlspecialchars($variant['price'] ?? '') ?>"
                                                        required>
                                                </div>

                                                <div class="col-md-4">
                                                    <label class="form-label">Original Price</label>
                                                    <input
                                                        type="number"
                                                        name="variants[<?= $index ?>][original_price]"
                                                        class="form-control"
                                                        min="0"
                                                        max="99999999.99"
                                                        step="0.01"
                                                        value="<?= htmlspecialchars($variant['original_price'] ?? '') ?>">
                                                </div>

                                                <div class="col-md-4">
                                                    <label class="form-label">Stock *</label>
                                                    <input
                                                        type="number"
                                                        name="variants[<?= $index ?>][stock]"
                                                        class="form-control"
                                                        min="0"
                                                        step="1"
                                                        value="<?= (int) $variant['stock'] ?>"
                                                        required>
                                                </div>

                                                <div class="col-12">
                                                    <label class="form-label">Attributes</label>
                                                    <input
                                                        type="text"
                                                        name="variants[<?= $index ?>][attributes]"
                                                        class="form-control"
                                                        value="<?= htmlspecialchars(
                                                                    implode(', ', array_map(
                                                                        static function ($key, $value) {
                                                                            return $key . ': ' . $value;
                                                                        },
                                                                        array_keys($variant['decoded_attributes']),
                                                                        $variant['decoded_attributes']
                                                                    ))
                                                                ) ?>"
                                                        placeholder="Size: Small, Color: Red">
                                                </div>

                                                <div class="col-12">
                                                    <label class="form-label">Existing Variant Images</label>

                                                    <?php if (!empty($variant['images'])): ?>

                                                        <div class="d-flex flex-wrap gap-3">

                                                            <?php foreach ($variant['images'] as $variantImage): ?>

                                                                <?php
                                                                $variantImagePath = $variantImage['image_path'];

                                                                if (
                                                                    strpos($variantImagePath, 'assets/uploads/') !== 0
                                                                ) {
                                                                    $variantImagePath = 'assets/uploads/' . $variantImagePath;
                                                                }
                                                                ?>

                                                                <div class="image-tile text-center">
                                                                    <img src="../<?= htmlspecialchars($variantImagePath) ?>" alt="<?= htmlspecialchars($variantImage['alt_text'] ?? $variant['variant_title']) ?>" style="width:90px;height:90px;object-fit:cover" class="rounded border">
                                                                    <button type="button" class="image-cut-btn" data-remove-name="remove_variant_images[]" data-remove-id="<?= (int) $variantImage['id'] ?>" aria-label="Remove variant image">×</button>
                                                                    <div class="small text-muted mt-1">Image #<?= (int) $variantImage['id'] ?></div>
                                                                </div>

                                                            <?php endforeach; ?>

                                                        </div>

                                                    <?php else: ?>

                                                        <p class="text-muted small mb-0">
                                                            No variant images saved.
                                                        </p>

                                                    <?php endif; ?>
                                                </div>

                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Variant Images</label>

                                                <input
                                                    type="file"
                                                    class="form-control"
                                                    name="variants[<?= $index ?>][images][]"
                                                    accept="image/jpeg,image/png,image/webp"
                                                    multiple>

                                                <small class="text-muted">
                                                    Select multiple JPG, PNG or WEBP images. Existing images will be preserved.
                                                </small>

                                                <?php if (!empty($variant['images'])): ?>
                                                    <div class="d-flex flex-wrap gap-2 mt-3">
                                                        <?php foreach ($variant['images'] as $variantImage): ?>
                                                            <div class="border rounded p-2">
                                                                <img
                                                                    src="../<?= htmlspecialchars($variantImage['image_path']) ?>"
                                                                    alt="<?= htmlspecialchars($variantImage['alt_text'] ?? $variant['variant_title']) ?>"
                                                                    style="width: 75px; height: 75px; object-fit: cover;">
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                        </div>


                                    <?php endforeach; ?>

                                </div>
                                <div id="deletedVariantsContainer"></div>

                                <template id="variantTemplate">
                                    <div class="variant-card border rounded-3 p-3 mb-3">

                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <strong class="variant-heading">New Variant</strong>

                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger remove-variant">
                                                Remove
                                            </button>
                                        </div>

                                        <div class="row g-3">

                                            <div class="col-md-6">
                                                <label class="form-label">Variant Title *</label>
                                                <input type="text" data-field="title"
                                                    class="form-control" maxlength="255" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Variant SKU *</label>
                                                <input type="text" data-field="sku"
                                                    class="form-control" maxlength="100" required>
                                            </div>

                                            <div class="col-md-4">
                                                <label class="form-label">Selling Price *</label>
                                                <input type="number" data-field="price"
                                                    class="form-control" min="0"
                                                    max="99999999.99" step="0.01" required>
                                            </div>

                                            <div class="col-md-4">
                                                <label class="form-label">Original Price</label>
                                                <input type="number" data-field="original_price"
                                                    class="form-control" min="0"
                                                    max="99999999.99" step="0.01">
                                            </div>

                                            <div class="col-md-4">
                                                <label class="form-label">Stock *</label>
                                                <input type="number" data-field="stock"
                                                    class="form-control" min="0"
                                                    step="1" required>
                                            </div>

                                            <div class="col-12">
                                                <label class="form-label">Attributes</label>
                                                <input type="text" data-field="attributes"
                                                    class="form-control"
                                                    placeholder="Size: Small, Color: Red">
                                            </div>


                                            <div class="mb-3">
                                                <label class="form-label">Variant Images</label>

                                                <input
                                                    type="file"
                                                    class="form-control"
                                                    data-image-upload="1"
                                                    accept="image/jpeg,image/png,image/webp"
                                                    multiple>

                                                <small class="text-muted">
                                                    Select multiple JPG, PNG or WEBP images.
                                                </small>
                                            </div>


                                        </div>
                                    </div>
                                </template>


                                <div class="col-12">
                                    <label class="form-label d-block">Existing Additional Product Images</label>
                                    <?php if (!empty($productImages)): ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($productImages as $galleryImage): ?>
                                                <?php $galleryPath = $galleryImage['image_path'];
                                                if (strpos($galleryPath, 'assets/uploads/') !== 0) $galleryPath = 'assets/uploads/' . ltrim($galleryPath, '/'); ?>
                                                <div class="image-tile">
                                                    <img src="../<?= htmlspecialchars($galleryPath) ?>" alt="<?= htmlspecialchars($galleryImage['alt_text'] ?? $title) ?>" style="width:90px;height:90px;object-fit:cover" class="rounded border">
                                                    <button type="button" class="image-cut-btn" data-remove-name="remove_gallery_images[]" data-remove-id="<?= (int) $galleryImage['id'] ?>" aria-label="Remove gallery image">×</button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="small text-muted">No additional product images saved.</p>
                                    <?php endif; ?>
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

                                        <div class="image-tile">
                                            <img src="../<?= htmlspecialchars($currentImage) ?>" alt="Product Image" class="current-image">
                                            <button type="button" class="image-cut-btn" data-remove-name="remove_main_image" data-remove-id="1" aria-label="Remove main product image">×</button>
                                        </div>

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
                                        accept=".jpg,.jpeg,.png,.webp">

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
                                        class="form-select">

                                        <option
                                            value="1"
                                            <?= ((int) $product['status'] === 1) ? 'selected' : '' ?>>
                                            Active / Approved
                                        </option>

                                        <option
                                            value="0"
                                            <?= ((int) $product['status'] === 0) ? 'selected' : '' ?>>
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
                                        readonly>

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
                                    class="btn btn-primary">
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
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Handle removal of existing main, gallery, and variant images.
            // Use delegation so the handler works for images inside existing and dynamic variant cards.
            document.addEventListener("click", function(event) {
                const button = event.target.closest(".image-cut-btn");
                if (!button) return;

                event.preventDefault();
                event.stopPropagation();

                const form = button.closest("form") || document.querySelector('form[enctype="multipart/form-data"]');
                if (!form) {
                    alert("Could not find the product form. Please refresh the page and try again.");
                    return;
                }

                const fieldName = button.dataset.removeName;
                const fieldId = button.dataset.removeId;
                if (!fieldName) return;

                if (fieldName === "remove_main_image") {
                    let hidden = form.querySelector('input[name="remove_main_image"]');
                    if (!hidden) {
                        hidden = document.createElement("input");
                        hidden.type = "hidden";
                        hidden.name = "remove_main_image";
                        form.appendChild(hidden);
                    }
                    hidden.value = "1";
                } else {
                    if (!fieldId) return;
                    const alreadyAdded = Array.from(form.querySelectorAll('input[type="hidden"][name="' + fieldName + '"]'))
                        .some(function(input) {
                            return input.value === fieldId;
                        });
                    if (!alreadyAdded) {
                        const hidden = document.createElement("input");
                        hidden.type = "hidden";
                        hidden.name = fieldName;
                        hidden.value = fieldId;
                        form.appendChild(hidden);
                    }
                }

                const tile = button.closest(".image-tile");
                if (tile) tile.remove();
            });

            const container = document.getElementById("variantsContainer");
            const deletedContainer = document.getElementById("deletedVariantsContainer");
            const template = document.getElementById("variantTemplate");
            const addButton = document.getElementById("addVariantBtn");

            let nextIndex = <?= count($variants) ?>;

            function bindRemoveButtons() {
                container.querySelectorAll(".remove-variant").forEach(function(button) {
                    if (button.dataset.bound === "1") return;

                    button.dataset.bound = "1";

                    button.addEventListener("click", function() {
                        if (confirm("Remove this variant from the form?")) {
                            const card = button.closest(".variant-card");
                            const idInput = card.querySelector('input[name$="[id]"]');

                            if (idInput && idInput.value) {
                                const deletedId = document.createElement("input");
                                deletedId.type = "hidden";
                                deletedId.name = "deleted_variant_ids[]";
                                deletedId.value = idInput.value;

                                deletedContainer.appendChild(deletedId);
                            }

                            card.remove();
                        }
                    });
                });
            }

            addButton.addEventListener("click", function() {
                const fragment = template.content.cloneNode(true);
                const card = fragment.querySelector(".variant-card");

                card.querySelectorAll("[data-field]").forEach(function(input) {
                    input.name = `variants[${nextIndex}][${input.dataset.field}]`;
                });

                card.querySelectorAll("[data-image-upload]").forEach(function(input) {
                    input.name = `variants[${nextIndex}][images][]`;
                });

                card.querySelector(".variant-heading").textContent =
                    "New Variant " + (nextIndex + 1);

                container.appendChild(fragment);
                nextIndex++;

                bindRemoveButtons();
            });

            bindRemoveButtons();
        });
    </script>

</body>

</html>