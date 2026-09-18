<?php

require_once "auth.php";
require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

$vendorId = $_SESSION['user_id'];

$sql = "
    SELECT
        p.id,
        p.sku,
        p.title,
        p.price,
        p.original_price,
        p.discount,
        p.stock,
        p.status,
        p.created_at,
        c.name AS category_name,
        b.name AS brand_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN brands b ON b.id = p.brand_id
    WHERE p.vendor_id = :vendor_id
    ORDER BY p.id DESC
";

$stmt = $db->prepare($sql);

$stmt->execute([
    "vendor_id" => $vendorId
]);

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$productCount = count($products);

if (isset($_GET['deleted'])): ?>

    <div class="alert alert-success">
        Product deleted successfully.
    </div>

<?php endif; ?>

<?php if (isset($_GET['delete_error'])): ?>

    <div class="alert alert-danger">
        Unable to delete the product. Please try again.
    </div>

<?php endif; ?>


<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Vendor Products</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

</head>

<body>

    <div class="container-fluid">

        <div class="row">

            <div class="col-md-2 bg-dark text-white min-vh-100 p-3">

                <h4 class="mb-4">Vendor Panel</h4>

                <ul class="nav flex-column">

                    <li class="nav-item mb-2">

                        <a href="index.php" class="nav-link text-white">
                            Dashboard
                        </a>
                    </li>

                    <li class="nav-item mb-2">

                        <a href="products.php" class="nav-link text-white">
                            Products
                        </a>

                    </li>

                    <li class="nav-item mb-2">
                        <a href="orders.php" class="nav-link text-white">
                            Orders
                        </a>
                    </li>

                    <li class="nav-item mb-2">
                        <a href="profile.php" class="nav-link text-white">
                            Store Profile
                        </a>
                    </li>

                    <li class="nav-item mt-3">

                        <a href="../outh/logout.php" class="nav-link text-danger">
                            Logout
                        </a>
                    </li>
                </ul>
            </div>
            <div class="col-md-10 p-4">

                <div class="d-flex justify-content-between align-items-center mb-4">

                    <h2 class="mb-1">Products</h2>
                    <small class="text-muted">
                        Total Products: <?= $productCount; ?>
                    </small>

                    <a href="product-add.php" class="btn btn-primary">
                        Add Product
                    </a>

                </div>
                <div class="card shadow-sm">

                    <div class="card-body">

                        <div class="table-responsive">

                            <table class="table table-bordered table-hover align-middle">

                                <thead class="table-light">

                                    <tr>

                                        <th>ID</th>
                                        <th>SKU</th>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Brand</th>
                                        <th>Price</th>
                                        <th>Discount</th>
                                        <th>Stock</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Action</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php if (empty($products)): ?>

                                        <tr>

                                            <td
                                                colspan="11"
                                                class="text-center text-muted">
                                                No products found.
                                            </td>

                                        </tr>

                                    <?php else: ?>

                                        <?php foreach ($products as $product): ?>

                                            <tr>

                                                <td>
                                                    <?= (int) $product['id'] ?>
                                                </td>

                                                <td>
                                                    <?= htmlspecialchars($product['sku'] ?? '-') ?>
                                                </td>

                                                <td>
                                                    <?= htmlspecialchars($product['title']) ?>
                                                </td>

                                                <td>
                                                    <?= htmlspecialchars($product['category_name'] ?? '-') ?>
                                                </td>

                                                <td>
                                                    <?= htmlspecialchars($product['brand_name'] ?? '-') ?>
                                                </td>
                                                <td>
                                                    ₹<?= number_format(
                                                            (float) $product['price'],
                                                            2
                                                        ) ?>
                                                </td>

                                                <td>
                                                    <?= number_format(
                                                        (float) $product['discount'],
                                                        2
                                                    ) ?>%
                                                </td>

                                                <td>
                                                    <?= (int) $product['stock'] ?>
                                                </td>

                                                <td>

                                                    <?php if ((int) $product['stock'] <= 0): ?>

                                                        <span class="badge bg-danger">
                                                            Out of Stock
                                                        </span>

                                                    <?php elseif ((int) $product['status'] === 1): ?>

                                                        <span class="badge bg-success">
                                                            Active
                                                        </span>

                                                    <?php else: ?>

                                                        <span class="badge bg-warning text-dark">
                                                            Pending Approval
                                                        </span>

                                                    <?php endif; ?>

                                                </td>

                                                <td>
                                                    <?= htmlspecialchars(
                                                        $product['created_at']
                                                    ) ?>
                                                </td>

                                                <td>

                                                    <a
                                                        href="product-edit.php?id=<?= (int) $product['id'] ?>"
                                                        class="btn btn-sm btn-warning">
                                                        Edit
                                                    </a>

                                                    <a
                                                        href="product-delete.php?id=<?= (int) $product['id'] ?>"
                                                        class="btn btn-sm btn-danger"
                                                        onclick="return confirm('Are you sure you want to delete this product?');">
                                                        Delete
                                                    </a>

                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    <?php endif; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</body>

</html>