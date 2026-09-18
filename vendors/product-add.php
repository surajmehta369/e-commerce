<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();

require_once "../connection/dbconnect.php";


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

$vendorStmt = $db->prepare("
    SELECT verification_status
    FROM vendor_profiles
    WHERE user_id = :user_id
    LIMIT 1
");

$vendorStmt->execute([
    ':user_id' => $vendorId
]);

$vendor = $vendorStmt->fetch(PDO::FETCH_ASSOC);

if (
    !$vendor ||
    $vendor['verification_status'] !== 'approved'
) {
    session_destroy();

    header("Location: ../login.php");
    exit;
}

$categoryStmt = $db->prepare("
    SELECT
        id,
        name
    FROM categories
    WHERE status = 1
    ORDER BY name ASC
");

$categoryStmt->execute();

$categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);


$brandStmt = $db->prepare("
    SELECT
        id,
        name
    FROM brands
    WHERE status = 1
    ORDER BY name ASC
");

$brandStmt->execute();

$brands = $brandStmt->fetchAll(PDO::FETCH_ASSOC);

$message = "";
$messageType = "";

function generateSlug($text)
{
    $text = strtolower(trim($text));

    $text = preg_replace('/[^a-z0-9]+/', '-', $text);

    $text = trim($text, '-');

    return $text;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $sku = trim($_POST['sku'] ?? '');

    $title = trim($_POST['title'] ?? '');

    $description = trim($_POST['description'] ?? '');

    $price = trim($_POST['price'] ?? '');

    $originalPrice = trim($_POST['original_price'] ?? '');

    $stock = trim($_POST['stock'] ?? '');

    $categoryId = (int) ($_POST['category_id'] ?? 0);

    $brandId = (int) ($_POST['brand_id'] ?? 0);
    if ($sku === '') {

        $message = "SKU is required.";
        $messageType = "danger";
    } elseif ($title === '') {

        $message = "Product title is required.";
        $messageType = "danger";
    } elseif ($price === '' || !is_numeric($price)) {

        $message = "Please enter a valid product price.";
        $messageType = "danger";
    } elseif ((float)$price < 0) {

        $message = "Product price cannot be negative.";
        $messageType = "danger";
    } elseif (
        $originalPrice !== '' &&
        (!is_numeric($originalPrice) || (float)$originalPrice < 0)
    ) {

        $message = "Please enter a valid original price.";
        $messageType = "danger";
    } elseif ($stock === '' || !ctype_digit($stock)) {

        $message = "Please enter a valid stock quantity.";
        $messageType = "danger";
    } elseif ((int)$stock < 0) {

        $message = "Stock cannot be negative.";
        $messageType = "danger";
    } elseif ($categoryId <= 0) {

        $message = "Please select a category.";
        $messageType = "danger";
    } elseif ($brandId <= 0) {

        $message = "Please select a brand.";
        $messageType = "danger";
    } else {

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

            $message = "Invalid category selected.";
            $messageType = "danger";
        } else {
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

                $message = "Invalid brand selected.";
                $messageType = "danger";
            } else {
                $skuCheck = $db->prepare("
                    SELECT id
                    FROM products
                    WHERE sku = :sku
                    LIMIT 1
                ");

                $skuCheck->execute([
                    ':sku' => $sku
                ]);

                if ($skuCheck->fetch()) {

                    $message = "This SKU already exists.";
                    $messageType = "danger";
                } else {
                    $price = (float) $price;

                    if ($originalPrice === '') {

                        $originalPrice = $price;
                    } else {

                        $originalPrice = (float) $originalPrice;
                    }

                    $discount = 0;

                    if (
                        $originalPrice > 0 &&
                        $originalPrice > $price
                    ) {

                        $discount =
                            (($originalPrice - $price) / $originalPrice) * 100;

                        $discount = round($discount, 2);
                    }

                    $slug = generateSlug($title);

                    $originalSlug = $slug;

                    $counter = 1;

                    while (true) {

                        $slugCheck = $db->prepare("
                            SELECT id
                            FROM products
                            WHERE slug = :slug
                            LIMIT 1
                        ");

                        $slugCheck->execute([
                            ':slug' => $slug
                        ]);

                        if (!$slugCheck->fetch()) {
                            break;
                        }

                        $slug = $originalSlug . '-' . $counter;

                        $counter++;
                    }
                    $imagePath = "";


                    if (
                        isset($_FILES['image']) &&
                        $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE
                    ) {

                        if (
                            $_FILES['image']['error'] !==
                            UPLOAD_ERR_OK
                        ) {

                            $message =
                                "There was an error uploading the image.";

                            $messageType = "danger";
                        } else {

                            $allowedExtensions = [
                                'jpg',
                                'jpeg',
                                'png',
                                'webp'
                            ];

                            $fileName =
                                $_FILES['image']['name'];

                            $tmpName =
                                $_FILES['image']['tmp_name'];

                            $fileSize =
                                $_FILES['image']['size'];

                            $extension =
                                strtolower(
                                    pathinfo(
                                        $fileName,
                                        PATHINFO_EXTENSION
                                    )
                                );


                            if (
                                !in_array(
                                    $extension,
                                    $allowedExtensions,
                                    true
                                )
                            ) {

                                $message =
                                    "Only JPG, JPEG, PNG and WEBP images are allowed.";

                                $messageType = "danger";
                            } elseif ($fileSize > 5 * 1024 * 1024) {

                                $message =
                                    "Image size must not exceed 5MB.";

                                $messageType = "danger";
                            } else {

                                $imageInfo =
                                    getimagesize($tmpName);

                                if ($imageInfo === false) {

                                    $message =
                                        "Invalid image file.";

                                    $messageType = "danger";
                                } else {

                                    $uploadDirectory =
                                        "../assets/uploads/";

                                    if (
                                        !is_dir(
                                            $uploadDirectory
                                        )
                                    ) {

                                        mkdir(
                                            $uploadDirectory,
                                            0755,
                                            true
                                        );
                                    }

                                    $newFileName =
                                        'product_' .
                                        uniqid('', true) .
                                        '.' .
                                        $extension;

                                    $destination =
                                        $uploadDirectory .
                                        $newFileName;


                                    if (
                                        move_uploaded_file(
                                            $tmpName,
                                            $destination
                                        )
                                    ) {

                                        $imagePath =
                                            "assets/uploads/" .
                                            $newFileName;
                                    } else {

                                        $message =
                                            "Unable to save uploaded image.";

                                        $messageType = "danger";
                                    }
                                }
                            }
                        }
                    }
                    if ($message === '') {

                        $status = 0;


                        $sql = "
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
                            )
                            VALUES (
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
                                :vendor_id
                            )
                        ";


                        $stmt = $db->prepare($sql);


                        $stmt->execute([

                            ':sku' =>
                            $sku,

                            ':title' =>
                            $title,

                            ':slug' =>
                            $slug,

                            ':description' =>
                            $description,

                            ':image' =>
                            $imagePath,

                            ':price' =>
                            $price,

                            ':original_price' =>
                            $originalPrice,

                            ':discount' =>
                            $discount,

                            ':stock' =>
                            (int)$stock,

                            ':category_id' =>
                            $categoryId,

                            ':brand_id' =>
                            $brandId,

                            ':status' =>
                            $status,

                            ':vendor_id' =>
                            $vendorId
                        ]);


                        $message =
                            "Product submitted successfully. It is waiting for admin approval.";

                        $messageType = "success";

                        $_POST = [];
                    }
                }
            }
        }
    }
}

?>

<!doctype html>
<html lang="en" data-bs-theme="light">
    <head>
        <title>Title</title>
        <!-- Required meta tags -->
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />

        <!-- Bootstrap CSS v5.3.8 -->
        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
            rel="stylesheet"
            integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
            crossorigin="anonymous"
        />
    </head>

    <body>

      <main
    class="container-fluid"
    style="margin-top:50px;">

    <div class="container-fluid">

        <div class="d-flex justify-content-between align-items-center mb-4">

            <div>

                <h2 class="fw-bold mb-1">
                    Add Product
                </h2>

                <p class="text-muted mb-0">
                    Add a new product to your vendor store.
                </p>

            </div>

            <a
                href="products.php"
                class="btn btn-outline-secondary rounded-pill px-4">

                <i class="fa-solid fa-arrow-left me-2"></i>

                Back to Products

            </a>

        </div>


        <?php if ($message !== ''): ?>

            <div
                class="alert alert-<?= htmlspecialchars($messageType); ?>
                       alert-dismissible fade show rounded-4"
                role="alert">

                <?= htmlspecialchars($message); ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert">
                </button>

            </div>

        <?php endif; ?>


        <div class="card border-0 shadow-sm rounded-4">

            <div class="card-body p-4 p-lg-5">

                <form
                    method="POST"
                    enctype="multipart/form-data">

                    <div class="row g-4">


                        <!-- SKU -->

                        <div class="col-md-6">

                            <label
                                class="form-label fw-semibold">

                                SKU
                                <span class="text-danger">*</span>

                            </label>

                            <input
                                type="text"
                                name="sku"
                                class="form-control"
                                value="<?= htmlspecialchars($_POST['sku'] ?? ''); ?>"
                                placeholder="e.g. PROD-1001"
                                required>

                        </div>


                        <!-- TITLE -->

                        <div class="col-md-6">

                            <label
                                class="form-label fw-semibold">

                                Product Title
                                <span class="text-danger">*</span>

                            </label>

                            <input
                                type="text"
                                name="title"
                                class="form-control"
                                value="<?= htmlspecialchars($_POST['title'] ?? ''); ?>"
                                placeholder="Enter product title"
                                required>

                        </div>


                        <!-- CATEGORY -->

                        <div class="col-md-6">

                            <label
                                class="form-label fw-semibold">

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
                                        value="<?= (int)$category['id']; ?>"
                                        <?= (
                                            (int)($_POST['category_id'] ?? 0)
                                            ===
                                            (int)$category['id']
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>>

                                        <?= htmlspecialchars(
                                            $category['name']
                                        ); ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- BRAND -->

                        <div class="col-md-6">

                            <label
                                class="form-label fw-semibold">

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
                                        value="<?= (int)$brand['id']; ?>"
                                        <?= (
                                            (int)($_POST['brand_id'] ?? 0)
                                            ===
                                            (int)$brand['id']
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>>

                                        <?= htmlspecialchars(
                                            $brand['name']
                                        ); ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- DESCRIPTION -->

                        <div class="col-12">

                            <label
                                class="form-label fw-semibold">

                                Description

                            </label>

                            <textarea
                                name="description"
                                rows="5"
                                class="form-control"
                                placeholder="Describe your product"><?= htmlspecialchars(
                                                                        $_POST['description'] ?? ''
                                                                    ); ?></textarea>

                        </div>


                        <!-- IMAGE -->

                        <div class="col-md-6">

                            <label
                                class="form-label fw-semibold">

                                Product Image

                            </label>

                            <input
                                type="file"
                                name="image"
                                class="form-control"
                                accept=".jpg,.jpeg,.png,.webp">

                            <small class="text-muted">
                                JPG, JPEG, PNG or WEBP. Maximum 5MB.
                            </small>

                        </div>


                        <!-- STOCK -->

                        <div class="col-md-6">

                            <label
                                class="form-label fw-semibold">

                                Stock
                                <span class="text-danger">*</span>

                            </label>

                            <input
                                type="number"
                                name="stock"
                                min="0"
                                class="form-control"
                                value="<?= htmlspecialchars($_POST['stock'] ?? ''); ?>"
                                placeholder="Enter stock quantity"
                                required>

                        </div>


                        <!-- PRICE -->

                        <div class="col-md-4">

                            <label
                                class="form-label fw-semibold">

                                Selling Price
                                <span class="text-danger">*</span>

                            </label>

                            <div class="input-group">

                                <span class="input-group-text">
                                    ₹
                                </span>

                                <input
                                    type="number"
                                    name="price"
                                    min="0"
                                    step="0.01"
                                    class="form-control"
                                    value="<?= htmlspecialchars($_POST['price'] ?? ''); ?>"
                                    placeholder="0.00"
                                    required>

                            </div>

                        </div>


                        <!-- ORIGINAL PRICE -->

                        <div class="col-md-4">

                            <label
                                class="form-label fw-semibold">

                                Original Price

                            </label>

                            <div class="input-group">

                                <span class="input-group-text">
                                    ₹
                                </span>

                                <input
                                    type="number"
                                    name="original_price"
                                    min="0"
                                    step="0.01"
                                    class="form-control"
                                    value="<?= htmlspecialchars($_POST['original_price'] ?? ''); ?>"
                                    placeholder="0.00">

                            </div>

                        </div>


                        <!-- DISCOUNT -->

                        <div class="col-md-4">

                            <label
                                class="form-label fw-semibold">

                                Discount

                            </label>

                            <div class="input-group">

                                <input
                                    type="text"
                                    class="form-control"
                                    value="Automatically calculated"
                                    disabled>

                                <span class="input-group-text">
                                    %
                                </span>

                            </div>

                        </div>

                        <div class="col-12">

                            <div
                                class="alert alert-info
                                       border-0 rounded-4">

                                <i
                                    class="fa-solid
                                           fa-circle-info
                                           me-2"></i>

                                Your product will be submitted for
                                administrator approval before it appears
                                on the customer website.

                            </div>

                        </div>
                        <div class="col-12">

                            <hr class="my-2">

                            <div
                                class="d-flex
                                       justify-content-end
                                       gap-2">

                                <a
                                    href="products.php"
                                    class="btn btn-light rounded-pill px-4">

                                    Cancel

                                </a>

                                <button
                                    type="submit"
                                    class="btn btn-primary rounded-pill px-4">

                                    <i
                                        class="fa-solid
                                               fa-plus
                                               me-2"></i>

                                    Add Product

                                </button>

                            </div>

                        </div>

                    </div>

                </form>

            </div>

        </div>

    </div>

</main>
    </body>
</html>
