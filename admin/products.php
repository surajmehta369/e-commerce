<?php

require_once "auth.php";
require_once "../connection/dbconnect.php";
require_once "../shopify/functions.php";
require_once "../zoho/zoho_functions.php";

$database = new Database();
$db = $database->connect();

// syncShopifyProductsToDatabase();
// syncZohoWebhookToDatabase();
// syncZohoWebhookItemToShopify();
$message = "";
$messageType = "success";


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $productId = (int) ($_POST['product_id'] ?? 0);

    if ($productId <= 0) {

        $message = "Invalid product.";
        $messageType = "danger";
    } else {

        try {
            $productStmt = $db->prepare("
    
SELECT
    id,
    title,
    sku,
    description,
    price,
    original_price,
    stock,
    image,
    status,
    shopify_product_id,
    zoho_item_id
FROM products
WHERE id = :id
LIMIT 1
            ");

            $productStmt->execute([
                ':id' => $productId
            ]);

            $product = $productStmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {

                $message = "Product not found.";
                $messageType = "danger";
            } else {

                $shopifyProductId =
                    trim($product['shopify_product_id'] ?? '');



                if ($action === 'approve') {

                    $zohoProduct = [
                        'name' => $product['title'],
                        'sku' => $product['sku'],
                        'description' => $product['description'] ?? '',
                        'rate' => (float) $product['price'],
                        'purchase_rate' => (
                            $product['original_price'] !== null
                            && $product['original_price'] !== ''
                        ) ? (float) $product['original_price']
                            : (float) $product['price'],
                        'stock' => (int) $product['stock'],
                        'zoho_item_id' => $product['zoho_item_id'] ?? ''
                    ];
                    $shopifyResult = [
                        'success' => false,
                        'message' => 'No Shopify product is linked.'
                    ];

                    if ($shopifyProductId !== '') {
                        $shopifyResult = updateShopifyProduct(
                            $shopifyProductId,
                            [
                                'title' => $product['title'],
                                'status' => 1
                            ]
                        );
                    }
                    $zohoResult = syncApprovedProductToZoho($zohoProduct);
                    $returnedZohoId = $zohoResult['item_id']
                        ?? ($zohoResult['item']['item_id'] ?? '');

                    if ($returnedZohoId !== '') {
                        $saveId = $db->prepare("
            UPDATE products
            SET zoho_item_id = :zoho_item_id
            WHERE id = :id
        ");

                        $saveId->execute([
                            ':zoho_item_id' => $returnedZohoId,
                            ':id' => $productId
                        ]);
                    }

                    $shopifySuccess = !empty($shopifyResult['success']);
                    $zohoSuccess = !empty($zohoResult['success']);

                    if ($shopifySuccess && $zohoSuccess) {

                        $stmt = $db->prepare("
            UPDATE products
            SET status = 1,
                shopify_status = 'ACTIVE',
                shopify_synced_at = NOW()
            WHERE id = :id
        ");

                        $stmt->execute([':id' => $productId]);

                        $message = 'Product approved and activated in Shopify and Zoho.';
                        $messageType = 'success';
                    } else {
                        if ($shopifySuccess && $shopifyProductId !== '') {
                            updateShopifyProduct(
                                $shopifyProductId,
                                [
                                    'title' => $product['title'],
                                    'status' => 0
                                ]
                            );
                        }

                        if ($zohoSuccess && $returnedZohoId !== '') {
                            setZohoItemStatus($returnedZohoId, 'inactive');
                        }

                        $details = [];

                        if (!$shopifySuccess) {
                            $details[] = 'Shopify: ' .
                                ($shopifyResult['message'] ?? 'Activation failed.');
                        }

                        if (!$zohoSuccess) {
                            $details[] = 'Zoho: ' .
                                ($zohoResult['message'] ?? 'Synchronization failed.');
                        }

                        $message = 'Approval not completed. ' . implode(' ', $details);
                        $messageType = 'warning';
                    }
                } elseif ($action === 'activate') {

                    $zohoItemId = trim($product['zoho_item_id'] ?? '');
                    $shopifyResult = [
                        'success' => false,
                        'message' => 'No Shopify product is linked.'
                    ];

                    if ($shopifyProductId !== '') {
                        $shopifyResult = updateShopifyProduct(
                            $shopifyProductId,
                            [
                                'title' => $product['title'],
                                'status' => 1
                            ]
                        );
                    }
                    $zohoResult = [
                        'success' => false,
                        'message' => 'No Zoho item is linked.'
                    ];

                    if ($zohoItemId !== '') {
                        $zohoResult = setZohoItemStatus(
                            $zohoItemId,
                            'active'
                        );
                    }

                    $shopifySuccess = !empty($shopifyResult['success']);
                    $zohoSuccess = !empty($zohoResult['success']);
                    if ($shopifySuccess && $zohoSuccess) {

                        $stmt = $db->prepare("
            UPDATE products
            SET
                status = 1,
                shopify_status = 'ACTIVE',
                shopify_synced_at = NOW()
            WHERE id = :id
        ");

                        $stmt->execute([
                            ':id' => $productId
                        ]);

                        $message = 'Product activated successfully in Shopify and Zoho.';
                        $messageType = 'success';
                    } else {
                        if ($shopifySuccess && $shopifyProductId !== '') {
                            updateShopifyProduct(
                                $shopifyProductId,
                                [
                                    'title' => $product['title'],
                                    'status' => 0
                                ]
                            );
                        }

                        $details = [];

                        if (!$shopifySuccess) {
                            $details[] = 'Shopify: ' .
                                ($shopifyResult['message'] ?? 'Activation failed.');
                        }

                        if (!$zohoSuccess) {
                            $details[] = 'Zoho: ' .
                                ($zohoResult['message'] ?? 'Activation failed.');
                        }

                        $message = 'Product activation was not completed. ' .
                            implode(' ', $details);

                        $messageType = 'warning';
                    }
                } elseif ($action === 'deactivate') {

                    $sql = "
                        UPDATE products
                        SET status = 0
                        WHERE id = :id
                    ";

                    $stmt = $db->prepare($sql);

                    $stmt->execute([
                        ':id' => $productId
                    ]);

                    if ($shopifyProductId !== '') {

                        $shopifyResult =
                            updateShopifyProduct(
                                $shopifyProductId,
                                [
                                    'title' => $product['title'],
                                    'status' => 0
                                ]
                            );

                        if (!empty($shopifyResult['success'])) {

                            $syncStmt = $db->prepare("
                                UPDATE products
                                SET
                                    shopify_status = 'ARCHIVED',
                                    shopify_synced_at = NOW()
                                WHERE id = :id
                            ");

                            $syncStmt->execute([
                                ':id' => $productId
                            ]);

                            $message =
                                "Product deactivated and archived on Shopify.";

                            $messageType = "success";
                        } else {

                            $message =
                                "Product deactivated locally, but Shopify archive failed.";

                            $messageType = "warning";
                        }
                    } else {

                        $message =
                            "Product deactivated successfully. No Shopify product linked.";

                        $messageType = "warning";
                    }

                    $zohoItemId = trim($product['zoho_item_id'] ?? '');

                    if ($zohoItemId !== '') {
                        $zohoResult = setZohoItemStatus(
                            $zohoItemId,
                            'inactive'
                        );

                        if (empty($zohoResult['success'])) {
                            $message = 'Shopify was deactivated, but Zoho deactivation failed: '
                                . ($zohoResult['message'] ?? 'Unknown error.');

                            $messageType = 'warning';
                        } else {
                            $message = 'Product deactivated in Shopify and Zoho.';
                            $messageType = 'success';
                        }
                    } else {
                        $message = 'Product deactivated in Shopify. No Zoho item is linked.';
                        $messageType = 'warning';
                    }
                } elseif ($action === 'delete') {

                    $shopifySuccess = true;

                    if ($shopifyProductId !== '') {

                        $shopifyResult =
                            updateShopifyProduct(
                                $shopifyProductId,
                                [
                                    'title' => $product['title'],
                                    'status' => 0
                                ]
                            );

                        if (empty($shopifyResult['success'])) {

                            $shopifySuccess = false;
                        }
                    }

                    if (!$shopifySuccess) {

                        $message =
                            "Product was not deleted because Shopify archive failed.";

                        $messageType = "danger";
                    } else {

                        $sql = "
                            DELETE FROM products
                            WHERE id = :id
                        ";

                        $stmt = $db->prepare($sql);

                        $stmt->execute([
                            ':id' => $productId
                        ]);

                        if (!empty($product['image'])) {

                            $imagePath =
                                "../" .
                                ltrim(
                                    $product['image'],
                                    "/"
                                );

                            if (
                                file_exists($imagePath) &&
                                is_file($imagePath)
                            ) {

                                @unlink($imagePath);
                            }
                        }


                        $message =
                            "Product deleted successfully and archived on Shopify.";

                        $messageType = "success";
                    }

                    $zohoItemId = trim($product['zoho_item_id'] ?? '');

                    if ($zohoItemId !== '') {
                        $zohoResult = setZohoItemStatus(
                            $zohoItemId,
                            'inactive'
                        );

                        if (empty($zohoResult['success'])) {
                            $message = 'Product was not deleted because Zoho deactivation failed: '
                                . ($zohoResult['message'] ?? 'Unknown error.');

                            $messageType = 'danger';
                        }
                    }
                } else {

                    $message = "Invalid product action.";
                    $messageType = "danger";
                }
            }
        } catch (PDOException $e) {

            $message =
                "Something went wrong. Please try again.";

            $messageType = "danger";
        }
    }
}

$allowedPerPage = [5, 10, 15, 20, 30, 40];

$perPage = (int) ($_GET['per_page'] ?? 10);

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 10;
}

$page = max(1, (int) ($_GET['page'] ?? 1));

$search = trim($_GET['search'] ?? '');
$categoryId = (int) ($_GET['category_id'] ?? 0);
$brandId = (int) ($_GET['brand_id'] ?? 0);
$status = $_GET['status'] ?? '';

$where = [];
$params = [];

if ($search !== '') {

    $where[] = "(
        p.title LIKE :search
        OR p.sku LIKE :search
        OR p.slug LIKE :search
        OR u.name LIKE :search
        OR vp.store_name LIKE :search
    )";

    $params[':search'] = '%' . $search . '%';
}
if ($categoryId > 0) {

    $where[] = "p.category_id = :category_id";

    $params[':category_id'] = $categoryId;
}

if ($brandId > 0) {

    $where[] = "p.brand_id = :brand_id";

    $params[':brand_id'] = $brandId;
}

if ($status === 'pending') {

    $where[] = "p.status = 0";
} elseif ($status === 'active') {

    $where[] = "p.status = 1";
}


$whereSql = "";

if (!empty($where)) {
    $whereSql = "WHERE " . implode(" AND ", $where);
}

$sql = "SELECT id, name
        FROM categories
        WHERE status = 1
        ORDER BY name ASC";

$stmt = $db->prepare($sql);
$stmt->execute();

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sql = "SELECT id, name
        FROM brands
        WHERE status = 1
        ORDER BY name ASC";

$stmt = $db->prepare($sql);
$stmt->execute();

$brands = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countSql = "
    SELECT COUNT(*)
    FROM products p

    LEFT JOIN users u
        ON u.id = p.vendor_id
        AND u.role = 'vendor'

    LEFT JOIN vendor_profiles vp
        ON vp.user_id = p.vendor_id

    $whereSql
";

$countStmt = $db->prepare($countSql);
$countStmt->execute($params);

$totalProducts = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalProducts / $perPage));

$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;


$sql = "
    SELECT
        p.id,
        p.sku,
        p.title,
        p.slug,
        p.description,
        p.image,
        p.price,
        p.original_price,
        p.discount,
        p.stock,
        p.category_id,
        p.brand_id,
        p.status,
        p.vendor_id,
        p.created_at,

        c.name AS category_name,
        b.name AS brand_name,

        u.name AS vendor_name,
        u.email AS vendor_email,

        vp.store_name AS vendor_store

    FROM products p

    LEFT JOIN categories c
        ON c.id = p.category_id

    LEFT JOIN brands b
        ON b.id = p.brand_id

    LEFT JOIN users u
        ON u.id = p.vendor_id
        AND u.role = 'vendor'

    LEFT JOIN vendor_profiles vp
        ON vp.user_id = p.vendor_id

    $whereSql

    ORDER BY p.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);


function productStatusBadge($status)
{
    if ((int) $status === 1) {

        return '<span class="badge bg-success">
                    Active
                </span>';
    }

    return '<span class="badge bg-warning text-dark">
                Pending / Inactive
            </span>';
}


function stockBadge($stock)
{
    $stock = (int) $stock;

    if ($stock <= 0) {

        return '<span class="badge bg-danger">
                    Out of Stock
                </span>';
    } elseif ($stock <= 5) {

        return '<span class="badge bg-warning text-dark">
                    Low Stock
                </span>';
    }

    return '<span class="badge bg-success">
                ' . $stock . '
            </span>';
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Product Management - Admin</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <style>
        body {
            background: #f5f7fb;
            font-family: Arial, sans-serif;
        }

        .sidebar {
            min-height: 100vh;
            background: #111827;
            color: #fff;
            position: fixed;
            width: 240px;
            left: 0;
            top: 0;
            bottom: 0;
            padding: 20px 15px;
        }

        .sidebar h4 {
            font-weight: 700;
            margin-bottom: 30px;
        }

        .sidebar a {
            display: block;
            color: #d1d5db;
            text-decoration: none;
            padding: 11px 14px;
            border-radius: 8px;
            margin-bottom: 5px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #1f2937;
            color: #fff;
        }

        .main {
            margin-left: 240px;
            padding: 30px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .page-header h2 {
            margin: 0;
            font-weight: 700;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
        }

        .filter-card {
            margin-bottom: 20px;
        }

        .table th {
            white-space: nowrap;
            font-size: 13px;
            color: #6b7280;
        }

        .table td {
            vertical-align: middle;
        }

        .product-image {
            width: 55px;
            height: 55px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
        }

        .product-title {
            font-weight: 600;
            max-width: 220px;
        }

        .small-text {
            font-size: 12px;
            color: #6b7280;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .action-buttons form {
            display: inline;
        }

        .modal-product-image {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
        }

        @media (max-width: 992px) {

            .sidebar {
                position: static;
                width: 100%;
                min-height: auto;
            }

            .main {
                margin-left: 0;
            }

        }
    </style>

</head>

<body>
    <div class="sidebar">

        <h4>E-Commerce Admin</h4>

        <a href="index.php">
            Dashboard
        </a>

        <a href="vendors.php">
            Vendors
        </a>

        <a href="products.php" class="active">
            Products
        </a>

        <a href="customers.php">
            Customers
        </a>

        <a href="categories.php">
            Categories
        </a>

        <a href="brands.php">
            Brands
        </a>

        <a href="orders.php">
            Orders
        </a>
        <a href="reviews.php">
            Product Reviews
        </a>

        <a href="profile.php">
            Admin Profile
        </a>

        <a href="../outh/logout.php">
            Logout
        </a>

    </div>
    <div class="main">

        <div class="page-header">

            <div>

                <h2>Product Management</h2>

                <p class="text-muted mb-0">
                    Manage products, approvals, stock and availability.
                </p>

            </div>
            <a href="product-add.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>
                Add Product
            </a>

        </div>
        <?php if ($message !== ""): ?>

            <div
                class="alert alert-<?php echo htmlspecialchars($messageType); ?>
                   alert-dismissible fade show">

                <?php echo htmlspecialchars($message); ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert">
                </button>

            </div>

        <?php endif; ?>
        <div class="card filter-card">

            <div class="card-body">

                <form method="GET">

                    <div class="row g-3 align-items-end">

                        <div class="col-lg-4 col-md-6">

                            <label class="form-label">
                                Search Product
                            </label>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                placeholder="Title, SKU or vendor"
                                value="<?php echo htmlspecialchars($search); ?>">

                        </div>


                        <div class="col-lg-2 col-md-6">

                            <label class="form-label">
                                Category
                            </label>

                            <select
                                name="category_id"
                                class="form-select">

                                <option value="">
                                    All Categories
                                </option>

                                <?php foreach ($categories as $category): ?>

                                    <option
                                        value="<?php echo (int) $category['id']; ?>"
                                        <?php
                                        echo $categoryId == $category['id']
                                            ? 'selected'
                                            : '';
                                        ?>>

                                        <?php
                                        echo htmlspecialchars(
                                            $category['name']
                                        );
                                        ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="col-lg-2 col-md-6">

                            <label class="form-label">
                                Brand
                            </label>

                            <select
                                name="brand_id"
                                class="form-select">

                                <option value="">
                                    All Brands
                                </option>

                                <?php foreach ($brands as $brand): ?>

                                    <option
                                        value="<?php echo (int) $brand['id']; ?>"
                                        <?php
                                        echo $brandId == $brand['id']
                                            ? 'selected'
                                            : '';
                                        ?>>

                                        <?php
                                        echo htmlspecialchars(
                                            $brand['name']
                                        );
                                        ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="col-lg-2 col-md-6">

                            <label class="form-label">
                                Status
                            </label>

                            <select
                                name="status"
                                class="form-select">

                                <option value="">
                                    All Status
                                </option>

                                <option
                                    value="pending"
                                    <?php
                                    echo $status === 'pending'
                                        ? 'selected'
                                        : '';
                                    ?>>
                                    Pending / Inactive
                                </option>

                                <option
                                    value="active"
                                    <?php
                                    echo $status === 'active'
                                        ? 'selected'
                                        : '';
                                    ?>>
                                    Active
                                </option>

                            </select>

                        </div>


                        <div class="col-lg-2 col-md-6">
                            <label class="form-label">Products per page</label>

                            <select name="per_page" class="form-select">
                                <?php foreach ([5, 10, 15, 20, 30, 40] as $option): ?>
                                    <option
                                        value="<?php echo $option; ?>"
                                        <?php echo $perPage === $option ? 'selected' : ''; ?>>
                                        <?php echo $option; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-2 col-md-6">

                            <div class="d-flex gap-2">

                                <button
                                    type="submit"
                                    class="btn btn-primary">
                                    Filter
                                </button>

                                <a
                                    href="products.php"
                                    class="btn btn-outline-secondary">
                                    Reset
                                </a>

                            </div>

                        </div>

                    </div>

                </form>

            </div>

        </div>
        <div class="card">

            <div class="card-body">

                <div class="d-flex justify-content-between align-items-center mb-3">

                    <h5 class="mb-0">
                        Products
                    </h5>

                    <span class="text-muted">
                        Showing <?php echo count($products); ?>
                        of <?php echo $totalProducts; ?> products
                    </span>

                </div>


                <div class="table-responsive">

                    <table class="table table-hover align-middle">

                        <thead>

                            <tr>

                                <th>#</th>

                                <th>Product</th>

                                <th>Vendor</th>

                                <th>Category</th>

                                <th>Brand</th>

                                <th>Price</th>

                                <th>Discount</th>

                                <th>Stock</th>

                                <th>Status</th>

                                <th>Created</th>

                                <th>Actions</th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php if (empty($products)): ?>

                                <tr>

                                    <td
                                        colspan="11"
                                        class="text-center py-5 text-muted">

                                        No products found.

                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($products as $index => $product): ?>

                                    <tr>

                                        <td>
                                            <?php echo $offset + $index + 1; ?>
                                        </td>

                                        <td>

                                            <div class="d-flex align-items-center gap-3">

                                                <?php if (!empty($product['image'])): ?>

                                                    <img
                                                        src="../<?php echo htmlspecialchars(
                                                                    ltrim($product['image'], '/')
                                                                ); ?>"
                                                        class="product-image"
                                                        alt="Product">

                                                <?php else: ?>

                                                    <div
                                                        class="product-image d-flex
                                                       align-items-center
                                                       justify-content-center">
                                                        <span class="small-text">
                                                            No Image
                                                        </span>
                                                    </div>

                                                <?php endif; ?>


                                                <div>

                                                    <div class="product-title">

                                                        <?php
                                                        echo htmlspecialchars(
                                                            $product['title']
                                                        );
                                                        ?>

                                                    </div>

                                                    <div class="small-text">

                                                        SKU:
                                                        <?php
                                                        echo htmlspecialchars(
                                                            $product['sku'] ?: '-'
                                                        );
                                                        ?>

                                                    </div>

                                                </div>

                                            </div>

                                        </td>
                                        <td>

                                            <?php if (!empty($product['vendor_id'])): ?>

                                                <div class="fw-semibold">

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $product['vendor_store']
                                                            ?: $product['vendor_name']
                                                            ?: 'Vendor'
                                                    );
                                                    ?>

                                                </div>

                                                <div class="small-text">

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $product['vendor_email'] ?: '-'
                                                    );
                                                    ?>

                                                </div>

                                            <?php else: ?>

                                                <span class="badge bg-info text-dark">
                                                    Admin / General
                                                </span>

                                            <?php endif; ?>

                                        </td>
                                        <td>

                                            <?php
                                            echo htmlspecialchars(
                                                $product['category_name'] ?: '-'
                                            );
                                            ?>

                                        </td>
                                        <td>

                                            <?php
                                            echo htmlspecialchars(
                                                $product['brand_name'] ?: '-'
                                            );
                                            ?>

                                        </td>
                                        <td>

                                            <strong>
                                                $<?php
                                                    echo number_format(
                                                        (float) $product['price'],
                                                        2
                                                    );
                                                    ?>
                                            </strong>

                                            <?php
                                            if (
                                                !empty($product['original_price'])
                                                &&
                                                $product['original_price']
                                                > $product['price']
                                            ):
                                            ?>

                                                <div class="small-text">

                                                    <del>
                                                        $<?php
                                                            echo number_format(
                                                                (float) $product['original_price'],
                                                                2
                                                            );
                                                            ?>
                                                    </del>

                                                </div>

                                            <?php endif; ?>

                                        </td>
                                        <td>

                                            <?php
                                            echo number_format(
                                                (float) $product['discount'],
                                                2
                                            );
                                            ?>%

                                        </td>
                                        <td>

                                            <?php
                                            echo stockBadge(
                                                $product['stock']
                                            );
                                            ?>

                                        </td>
                                        <td>

                                            <?php
                                            echo productStatusBadge(
                                                $product['status']
                                            );
                                            ?>

                                        </td>
                                        <td>

                                            <?php
                                            echo date(
                                                'd M Y',
                                                strtotime($product['created_at'])
                                            );
                                            ?>

                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a
                                                    href="product-edit.php?id=<?php echo (int) $product['id']; ?>"
                                                    class="btn btn-sm btn-outline-warning"
                                                    title="Edit Product">
                                                    Edit
                                                </a>


                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-primary"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#productModal<?php echo $product['id']; ?>">
                                                    View
                                                </button>
                                                <?php if ((int) $product['status'] === 0): ?>

                                                    <form method="POST">

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="approve">

                                                        <input
                                                            type="hidden"
                                                            name="product_id"
                                                            value="<?php echo (int) $product['id']; ?>">

                                                        <button
                                                            type="submit"
                                                            class="btn btn-sm btn-success"
                                                            onclick="return confirm('Approve this product?');">
                                                            Approve
                                                        </button>

                                                    </form>

                                                <?php endif; ?>
                                                <?php if ((int) $product['status'] === 0): ?>

                                                    <form method="POST">

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="activate">

                                                        <input
                                                            type="hidden"
                                                            name="product_id"
                                                            value="<?php echo (int) $product['id']; ?>">

                                                        <button
                                                            type="submit"
                                                            class="btn btn-sm btn-outline-success"
                                                            onclick="return confirm('Activate this product?');">
                                                            Activate
                                                        </button>

                                                    </form>

                                                <?php endif; ?>
                                                <?php if ((int) $product['status'] === 1): ?>

                                                    <form method="POST">

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="deactivate">

                                                        <input
                                                            type="hidden"
                                                            name="product_id"
                                                            value="<?php echo (int) $product['id']; ?>">

                                                        <button
                                                            type="submit"
                                                            class="btn btn-sm btn-outline-secondary"
                                                            onclick="return confirm('Deactivate this product?');">
                                                            Deactivate
                                                        </button>

                                                    </form>

                                                <?php endif; ?>
                                                <form method="POST">

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="delete">

                                                    <input
                                                        type="hidden"
                                                        name="product_id"
                                                        value="<?php echo (int) $product['id']; ?>">

                                                    <button
                                                        type="submit"
                                                        class="btn btn-sm btn-outline-danger"
                                                        onclick="return confirm('Delete this product permanently? This action cannot be undone.');">
                                                        Delete
                                                    </button>

                                                </form>

                                            </div>

                                        </td>

                                    </tr>
                                    <div
                                        class="modal fade"
                                        id="productModal<?php echo $product['id']; ?>"
                                        tabindex="-1">

                                        <div
                                            class="modal-dialog modal-lg
                                           modal-dialog-centered">

                                            <div class="modal-content">

                                                <div class="modal-header">

                                                    <h5 class="modal-title">
                                                        Product Details
                                                    </h5>

                                                    <button
                                                        type="button"
                                                        class="btn-close"
                                                        data-bs-dismiss="modal">
                                                    </button>

                                                </div>


                                                <div class="modal-body">

                                                    <div class="row g-4">

                                                        <!-- Image -->

                                                        <div class="col-md-4 text-center">

                                                            <?php if (!empty($product['image'])): ?>

                                                                <img
                                                                    src="../<?php
                                                                            echo htmlspecialchars(
                                                                                ltrim(
                                                                                    $product['image'],
                                                                                    '/'
                                                                                )
                                                                            );
                                                                            ?>"
                                                                    class="modal-product-image"
                                                                    alt="Product">

                                                            <?php else: ?>

                                                                <div
                                                                    class="modal-product-image
                                                                   d-flex
                                                                   align-items-center
                                                                   justify-content-center
                                                                   mx-auto">

                                                                    <span class="text-muted">
                                                                        No Image
                                                                    </span>

                                                                </div>

                                                            <?php endif; ?>

                                                        </div>


                                                        <!-- Details -->

                                                        <div class="col-md-8">

                                                            <h4>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $product['title']
                                                                );
                                                                ?>
                                                            </h4>

                                                            <p class="text-muted">

                                                                <?php
                                                                echo nl2br(
                                                                    htmlspecialchars(
                                                                        $product['description']
                                                                            ?: 'No description available.'
                                                                    )
                                                                );
                                                                ?>

                                                            </p>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>SKU</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $product['sku'] ?: '-'
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Category</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $product['category_name']
                                                                        ?: '-'
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Brand</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $product['brand_name']
                                                                        ?: '-'
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Price</strong>

                                                            <div>
                                                                $<?php
                                                                    echo number_format(
                                                                        (float) $product['price'],
                                                                        2
                                                                    );
                                                                    ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Original Price</strong>

                                                            <div>
                                                                $<?php
                                                                    echo number_format(
                                                                        (float) $product['original_price'],
                                                                        2
                                                                    );
                                                                    ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Discount</strong>

                                                            <div>
                                                                <?php
                                                                echo number_format(
                                                                    (float) $product['discount'],
                                                                    2
                                                                );
                                                                ?>%
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Stock</strong>

                                                            <div>
                                                                <?php
                                                                echo (int) $product['stock'];
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Status</strong>

                                                            <div>
                                                                <?php
                                                                echo productStatusBadge(
                                                                    $product['status']
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Created</strong>

                                                            <div>
                                                                <?php
                                                                echo date(
                                                                    'd M Y H:i',
                                                                    strtotime(
                                                                        $product['created_at']
                                                                    )
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>
                                                        <div class="col-md-12">

                                                            <strong>Vendor</strong>

                                                            <div>

                                                                <?php if (!empty($product['vendor_id'])): ?>

                                                                    <?php
                                                                    echo htmlspecialchars(
                                                                        $product['vendor_store']
                                                                            ?: $product['vendor_name']
                                                                            ?: '-'
                                                                    );
                                                                    ?>

                                                                    <?php if (!empty($product['vendor_email'])): ?>

                                                                        <span class="text-muted">

                                                                            -
                                                                            <?php
                                                                            echo htmlspecialchars(
                                                                                $product['vendor_email']
                                                                            );
                                                                            ?>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                <?php else: ?>

                                                                    Admin / General Product

                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button
                                                        type="button"
                                                        class="btn btn-secondary"
                                                        data-bs-dismiss="modal">
                                                        Close
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>


                <?php
                $queryParams = $_GET;
                $queryParams['per_page'] = $perPage;
                ?>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-3">

                    <span class="text-muted">
                        Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                    </span>

                    <nav aria-label="Product pagination">
                        <ul class="pagination mb-0">

                            <?php $queryParams['page'] = max(1, $page - 1); ?>

                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link"
                                    href="?<?php echo htmlspecialchars(http_build_query($queryParams)); ?>">
                                    Previous
                                </a>
                            </li>

                            <?php $queryParams['page'] = min($totalPages, $page + 1); ?>

                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link"
                                    href="?<?php echo htmlspecialchars(http_build_query($queryParams)); ?>">
                                    Next
                                </a>
                            </li>

                        </ul>
                    </nav>

                </div>

            </div>
        </div>

    </div>
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    </script>

</body>

</html>