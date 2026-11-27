<?php

require_once __DIR__ . '/includes/layout.php';

$vendorId = (int) $_SESSION['user_id'];

$database = new Database();
$db = $database->connect();

$productStmt = $db->prepare("
    SELECT COUNT(*)
    FROM products
    WHERE vendor_id = :vendor_id
");

$productStmt->execute([
    ':vendor_id' => $vendorId
]);

$productCount = (int) $productStmt->fetchColumn();



$orderStmt = $db->prepare("
    SELECT COUNT(DISTINCT o.id)
    FROM orders o
    INNER JOIN order_items oi
        ON oi.order_id = o.id
    INNER JOIN products p        ON p.id = oi.product_id
    WHERE p.vendor_id = :vendor_id
");

$orderStmt->execute([
    ':vendor_id' => $vendorId
]);

$orderCount = (int) $orderStmt->fetchColumn();

$storeName = $vendor['store_name'] ?? 'My Store';
$businessName = $vendor['business_name'] ?? '';
$verificationStatus = strtolower(
    $vendor['verification_status'] ?? 'pending'
);

vendorHeader('Dashboard', 'dashboard');

?>

<div class="container-fluid px-0">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

        <div>

            <h4 class="fw-bold mb-1">
                Welcome, <?= vendorEscape($_SESSION['user_name'] ?? 'Vendor'); ?>!
            </h4>

            <p class="text-muted mb-0">
                Here's an overview of your store.
            </p>

        </div>

        <div>

            <a href="product-add.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>
                Add New Product
            </a>

        </div>

    </div>
    <div class="card shadow-sm mb-4">

        <div class="card-body p-4">

            <div class="d-flex align-items-center gap-3">

                <div
                    class="d-flex align-items-center justify-content-center rounded-3"
                    style="
                        width: 55px;
                        height: 55px;
                        background: #eaf0ff;
                        color: #315efb;
                        font-size: 25px;
                    ">
                    <i class="bi bi-shop"></i>
                </div>

                <div>

                    <h5 class="fw-bold mb-1">
                        <?= vendorEscape($storeName); ?>
                    </h5>

                    <?php if ($businessName !== ''): ?>

                        <p class="text-muted mb-0">
                            <?= vendorEscape($businessName); ?>
                        </p>

                    <?php else: ?>

                        <p class="text-muted mb-0">
                            Manage your store and products.
                        </p>

                    <?php endif; ?>

                </div>

                <div class="ms-auto">

                    <a
                        href="profile.php"
                        class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-pencil-square me-1"></i>
                        Edit Profile
                    </a>

                </div>

            </div>

        </div>

    </div>


    <div class="row g-4 mb-4">

        <div class="col-md-6 col-xl-4">

            <div class="card shadow-sm h-100">

                <div class="card-body p-4">

                    <div class="d-flex justify-content-between align-items-center mb-3">

                        <div>

                            <p class="text-muted mb-1">
                                Total Products
                            </p>

                            <h2 class="fw-bold mb-0">
                                <?= $productCount; ?>
                            </h2>

                        </div>

                        <div
                            class="d-flex align-items-center justify-content-center rounded-3"
                            style="
                                width: 50px;
                                height: 50px;
                                background: #eaf0ff;
                                color: #315efb;
                                font-size: 23px;
                            ">
                            <i class="bi bi-box-seam"></i>
                        </div>

                    </div>

                    <a
                        href="products.php"
                        class="text-decoration-none small fw-semibold">
                        View Products
                        <i class="bi bi-arrow-right ms-1"></i>
                    </a>

                </div>

            </div>

        </div>
        <div class="col-md-6 col-xl-4">

            <div class="card shadow-sm h-100">

                <div class="card-body p-4">

                    <div class="d-flex justify-content-between align-items-center mb-3">

                        <div>

                            <p class="text-muted mb-1">
                                Total Orders
                            </p>

                            <h2 class="fw-bold mb-0">
                                <?= $orderCount; ?>
                            </h2>

                        </div>

                        <div
                            class="d-flex align-items-center justify-content-center rounded-3"
                            style="
                                width: 50px;
                                height: 50px;
                                background: #e5f8ee;
                                color: #198754;
                                font-size: 23px;
                            ">
                            <i class="bi bi-bag-check"></i>
                        </div>

                    </div>

                    <a
                        href="orders.php"
                        class="text-decoration-none small fw-semibold">
                        View Orders
                        <i class="bi bi-arrow-right ms-1"></i>
                    </a>

                </div>

            </div>

        </div>
        <div class="col-md-6 col-xl-4">

            <div class="card shadow-sm h-100">

                <div class="card-body p-4">

                    <div class="d-flex justify-content-between align-items-center mb-3">

                        <div>

                            <p class="text-muted mb-1">
                                Account Status
                            </p>

                            <h4 class="fw-bold mb-0">

                                <?php if ($verificationStatus === 'approved'): ?>

                                    <span class="text-success">
                                        Approved
                                    </span>

                                <?php elseif ($verificationStatus === 'rejected'): ?>

                                    <span class="text-danger">
                                        Rejected
                                    </span>

                                <?php else: ?>

                                    <span class="text-warning">
                                        Pending
                                    </span>

                                <?php endif; ?>

                            </h4>

                        </div>

                        <div
                            class="d-flex align-items-center justify-content-center rounded-3"
                            style="
                                width: 50px;
                                height: 50px;
                                background: #fff4df;
                                color: #d99a19;
                                font-size: 23px;
                            ">
                            <i class="bi bi-shield-check"></i>
                        </div>

                    </div>

                    <a
                        href="profile.php"
                        class="text-decoration-none small fw-semibold">
                        View Profile
                        <i class="bi bi-arrow-right ms-1"></i>
                    </a>

                </div>

            </div>

        </div>

    </div>

    <div class="card shadow-sm">

        <div class="card-header bg-white p-3">

            <h5 class="fw-bold mb-0">
                Quick Actions
            </h5>

        </div>

        <div class="card-body p-4">

            <div class="row g-3">

                <div class="col-md-4">

                    <a
                        href="product-add.php"
                        class="btn btn-outline-primary w-100 py-3">
                        <i class="bi bi-plus-circle me-2"></i>
                        Add Product
                    </a>

                </div>

                <div class="col-md-4">

                    <a
                        href="products.php"
                        class="btn btn-outline-primary w-100 py-3">
                        <i class="bi bi-box-seam me-2"></i>
                        Manage Products
                    </a>

                </div>

                <div class="col-md-4">

                    <a
                        href="orders.php"
                        class="btn btn-outline-primary w-100 py-3">
                        <i class="bi bi-bag-check me-2"></i>
                        Manage Orders
                    </a>

                </div>

            </div>

        </div>

    </div>

</div>

<?php

vendorFooter();

?>