<?php

require_once "auth.php";
require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

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

            if ($action === 'approve') {

                $sql = "UPDATE products
                        SET status = 1
                        WHERE id = :id";

                $stmt = $db->prepare($sql);

                $stmt->execute([
                    ':id' => $productId
                ]);

                $message = "Product approved successfully.";
            } elseif ($action === 'activate') {

                $sql = "UPDATE products
                        SET status = 1
                        WHERE id = :id";

                $stmt = $db->prepare($sql);

                $stmt->execute([
                    ':id' => $productId
                ]);

                $message = "Product activated successfully.";
            } elseif ($action === 'deactivate') {

                $sql = "UPDATE products
                        SET status = 0
                        WHERE id = :id";

                $stmt = $db->prepare($sql);

                $stmt->execute([
                    ':id' => $productId
                ]);

                $message = "Product deactivated successfully.";
            } elseif ($action === 'delete') {

                $sql = "SELECT image
                        FROM products
                        WHERE id = :id
                        LIMIT 1";

                $stmt = $db->prepare($sql);

                $stmt->execute([
                    ':id' => $productId
                ]);

                $product = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {

                    $message = "Product not found.";
                    $messageType = "danger";
                } else {

                    $sql = "DELETE FROM products
                            WHERE id = :id";

                    $stmt = $db->prepare($sql);

                    $stmt->execute([
                        ':id' => $productId
                    ]);

                    if (!empty($product['image'])) {

                        $imagePath = "../" . ltrim(
                            $product['image'],
                            "/"
                        );

                        if (file_exists($imagePath)) {
                            @unlink($imagePath);
                        }
                    }

                    $message = "Product deleted successfully.";
                }
            }
        } catch (PDOException $e) {

            $message = "Something went wrong. Please try again.";
            $messageType = "danger";
        }
    }
}

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
";

$stmt = $db->prepare($sql);
$stmt->execute($params);

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

        <a href="profile.php">
            Admin Profile
        </a>

        <a href="../logout.php">
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
                        <?php echo count($products); ?> product(s)
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


                                <?php foreach ($products as $product): ?>

                                    <tr>

                                        <td>
                                            <?php echo (int) $product['id']; ?>
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
            </div>
        </div>

    </div>
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    </script>

</body>

</html>