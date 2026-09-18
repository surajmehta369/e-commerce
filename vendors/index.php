<?php
require_once "auth.php";
require_once "../connection/dbconnect.php";


/**
 * @var array{
 *     store_name: string,
 *     business_name: ?string,
 *     verification_status: string
 * } $vendor
 */

$database = new Database();
$db = $database->connect();

$vendorId = (int) $_SESSION['user_id'];
$productCountStmt = $db->prepare("
    SELECT COUNT(*)
    FROM products
    WHERE vendor_id = :vendor_id
");

$productCountStmt->execute([
    ':vendor_id' => $vendorId
]);

$productCount = (int) $productCountStmt->fetchColumn();

$orderCountStmt = $db->prepare("
    SELECT COUNT(DISTINCT o.id)
    FROM orders o
    INNER JOIN order_items oi
        ON oi.order_id = o.id
    INNER JOIN products p
        ON p.id = oi.product_id
    WHERE p.vendor_id = :vendor_id
");

$orderCountStmt->execute([
    ':vendor_id' => $vendorId
]);

$orderCount = (int) $orderCountStmt->fetchColumn();


?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Vendor Dashboard</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

</head>

<body>

    <div class="container-fluid">

        <div class="row">
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
                            Vendor Dashboard
                        </h2>

                        <p class="text-muted mb-0">

                            Welcome,
                            <?= htmlspecialchars($_SESSION['user_name']) ?>

                        </p>

                    </div>

                </div>

                <div class="card mb-4">

                    <div class="card-body">

                        <h5 class="card-title">
                            Store Information
                        </h5>

                        <p class="mb-1">

                            <strong>Store Name:</strong>

                            <?= htmlspecialchars($vendor['store_name']) ?>

                        </p>

                        <?php if (!empty($vendor['business_name'])): ?>

                            <p class="mb-0">

                                <strong>Business Name:</strong>

                                <?= htmlspecialchars($vendor['business_name']) ?>

                            </p>

                        <?php endif; ?>

                    </div>

                </div>
                <div class="row g-4">

                    <div class="col-md-4">

                        <div class="card shadow-sm">

                            <div class="card-body">

                                <h6 class="text-muted">
                                    Products
                                </h6>

                                <h2><?= $productCount; ?></h2>

                                <a
                                    href="products.php"
                                    class="btn btn-primary btn-sm">
                                    Manage Products
                                </a>

                            </div>

                        </div>

                    </div>


                    <div class="col-md-4">

                        <div class="card shadow-sm">

                            <div class="card-body">

                                <h6 class="text-muted">
                                    Orders
                                </h6>

                                     <h2><?= $orderCount; ?></h2>

                                <a
                                    href="orders.php"
                                    class="btn btn-primary btn-sm">
                                    View Orders
                                </a>

                            </div>

                        </div>

                    </div>


                    <div class="col-md-4">

                        <div class="card shadow-sm">

                            <div class="card-body">

                                <h6 class="text-muted">
                                    Account Status
                                </h6>

                                <h5 class="text-success">
                                    Approved
                                </h5>

                                <a
                                    href="profile.php"
                                    class="nav-link text-white">
                                    Store Profile
                                </a>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    </script>

</body>

</html>