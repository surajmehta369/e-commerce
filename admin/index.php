<?php

require_once __DIR__ . "/includes/layout.php";
require_once "../connection/dbconnect.php";
require_once "../zoho/zoho_functions.php";

// syncZohoWebhookToDatabase();
// syncZohoWebhookItemToShopify();

$database = new Database();
$db = $database->connect();

$stmt = $db->query("
    SELECT COUNT(*)
    FROM users
    WHERE role = 'customer'
");
$totalCustomers = (int) $stmt->fetchColumn();

$stmt = $db->query("
    SELECT COUNT(*)
    FROM users
    WHERE role = 'vendor'
");
$totalVendors = (int) $stmt->fetchColumn();

$stmt = $db->query("
    SELECT COUNT(*)
    FROM vendor_profiles
    WHERE verification_status = 'pending'
");
$pendingVendors = (int) $stmt->fetchColumn();

$stmt = $db->query("
    SELECT COUNT(*)
    FROM vendor_profiles
    WHERE verification_status = 'approved'
");
$approvedVendors = (int) $stmt->fetchColumn();

$stmt = $db->query("
    SELECT COUNT(*)
    FROM products
");
$totalProducts = (int) $stmt->fetchColumn();

$stmt = $db->query("
    SELECT COUNT(*)
    FROM products
    WHERE status = 0
");
$pendingProducts = (int) $stmt->fetchColumn();

$stmt = $db->query("
    SELECT COUNT(*)
    FROM products
    WHERE status = 1
");
$activeProducts = (int) $stmt->fetchColumn();

$stmt = $db->query("
    SELECT COUNT(*)
    FROM orders
");
$totalOrders = (int) $stmt->fetchColumn();

$stmt = $db->query("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM orders
    WHERE order_status != 'cancelled'
");
$totalSales = (float) $stmt->fetchColumn();

$stmt = $db->query("
    SELECT COUNT(*)
    FROM users
    WHERE status = 1
");
$activeUsers = (int) $stmt->fetchColumn();

$stmt = $db->query("
    SELECT
        o.id,
        o.total_amount,
        o.payment_method,
        o.payment_status,
        o.order_status,
        o.created_at,
        u.name AS customer_name
    FROM orders o
    LEFT JOIN users u ON u.id = o.user_id
    ORDER BY o.id DESC
    LIMIT 5
");

$recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->query("
    SELECT
        vp.id,
        vp.store_name,
        vp.business_name,
        vp.verification_status,
        vp.created_at,
        u.name AS owner_name,
        u.email
    FROM vendor_profiles vp
    INNER JOIN users u ON u.id = vp.user_id
    ORDER BY vp.id DESC
    LIMIT 5
");

$recentVendors = $stmt->fetchAll(PDO::FETCH_ASSOC);

function verificationBadge($status)
{
    if ($status === 'approved') {
        return '<span class="badge bg-success">Approved</span>';
    }

    if ($status === 'rejected') {
        return '<span class="badge bg-danger">Rejected</span>';
    }

    return '<span class="badge bg-warning text-dark">Pending</span>';
}


function orderStatusBadge($status)
{
    switch ($status) {

        case 'confirmed':
            return '<span class="badge bg-primary">Confirmed</span>';

        case 'processing':
            return '<span class="badge bg-info text-dark">Processing</span>';

        case 'shipped':
            return '<span class="badge bg-warning text-dark">Shipped</span>';

        case 'delivered':
            return '<span class="badge bg-success">Delivered</span>';

        case 'cancelled':
            return '<span class="badge bg-danger">Cancelled</span>';

        default:
            return '<span class="badge bg-secondary">'
                . htmlspecialchars($status)
                . '</span>';
    }
}


adminHeader('Dashboard', 'dashboard');
?>

<div class="page-header">
    <div>
        <h2>Dashboard</h2>
        <small class="text-muted">
            Welcome back, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?>
        </small>
    </div>
    <span class="badge bg-dark px-3 py-2">Administrator</span>
</div>

<style>
    .dashboard-card { border: 0; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
    .stat-number { font-size: 28px; font-weight: 700; }
    .stat-title { color: #6c757d; font-size: 14px; }
    .section-card { border: 0; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
    .table th { font-size: 13px; color: #6c757d; font-weight: 600; white-space: nowrap; }
    .table td { vertical-align: middle; }
</style>


                    <div class="row g-4 mb-4">

                        <div class="col-sm-6 col-xl-3">

                            <div class="card dashboard-card h-100">

                                <div class="card-body">

                                    <div class="stat-title">
                                        Total Customers
                                    </div>

                                    <div class="stat-number mt-2">
                                        <?= $totalCustomers; ?>
                                    </div>

                                    <a
                                        href="customers.php"
                                        class="small text-decoration-none">
                                        Manage Customers →
                                    </a>

                                </div>

                            </div>

                        </div>

                        <div class="col-sm-6 col-xl-3">

                            <div class="card dashboard-card h-100">

                                <div class="card-body">

                                    <div class="stat-title">
                                        Total Vendors
                                    </div>

                                    <div class="stat-number mt-2">
                                        <?= $totalVendors; ?>
                                    </div>

                                    <a
                                        href="vendors.php"
                                        class="small text-decoration-none">
                                        Manage Vendors →
                                    </a>

                                </div>

                            </div>

                        </div>

                        <div class="col-sm-6 col-xl-3">

                            <div class="card dashboard-card h-100">

                                <div class="card-body">

                                    <div class="stat-title">
                                        Total Products
                                    </div>

                                    <div class="stat-number mt-2">
                                        <?= $totalProducts; ?>
                                    </div>

                                    <a
                                        href="products.php"
                                        class="small text-decoration-none">
                                        Manage Products →
                                    </a>

                                </div>

                            </div>

                        </div>
                        <div class="col-sm-6 col-xl-3">

                            <div class="card dashboard-card h-100">

                                <div class="card-body">

                                    <div class="stat-title">
                                        Total Orders
                                    </div>

                                    <div class="stat-number mt-2">
                                        <?= $totalOrders; ?>
                                    </div>

                                    <a
                                        href="orders.php"
                                        class="small text-decoration-none">
                                        Manage Orders →
                                    </a>

                                </div>

                            </div>

                        </div>

                    </div>
                    <div class="row g-4 mb-4">

                        <div class="col-md-6 col-xl-3">

                            <div class="card dashboard-card h-100">

                                <div class="card-body">

                                    <div class="stat-title">
                                        Pending Vendors
                                    </div>

                                    <div class="stat-number mt-2 text-warning">
                                        <?= $pendingVendors; ?>
                                    </div>

                                    <a
                                        href="vendors.php?status=pending"
                                        class="small text-decoration-none">
                                        Review Vendors →
                                    </a>

                                </div>

                            </div>

                        </div>

                        <div class="col-md-6 col-xl-3">

                            <div class="card dashboard-card h-100">

                                <div class="card-body">

                                    <div class="stat-title">
                                        Approved Vendors
                                    </div>

                                    <div class="stat-number mt-2 text-success">
                                        <?= $approvedVendors; ?>
                                    </div>

                                </div>

                            </div>

                        </div>


                        <div class="col-md-6 col-xl-3">

                            <div class="card dashboard-card h-100">

                                <div class="card-body">

                                    <div class="stat-title">
                                        Pending Products
                                    </div>

                                    <div class="stat-number mt-2 text-warning">
                                        <?= $pendingProducts; ?>
                                    </div>

                                    <a
                                        href="products.php?status=pending"
                                        class="small text-decoration-none">
                                        Review Products →
                                    </a>

                                </div>

                            </div>

                        </div>

                        <div class="col-md-6 col-xl-3">

                            <div class="card dashboard-card h-100">

                                <div class="card-body">

                                    <div class="stat-title">
                                        Total Sales
                                    </div>

                                    <div class="stat-number mt-2">
                                        ₹<?= number_format($totalSales, 2); ?>
                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>


                    <div class="row g-4 mb-4">


                        <div class="col-md-4">

                            <div class="card dashboard-card">

                                <div class="card-body">

                                    <h6 class="text-muted">
                                        Active Products
                                    </h6>

                                    <h3 class="mb-0">
                                        <?= $activeProducts; ?>
                                    </h3>

                                </div>

                            </div>

                        </div>


                        <div class="col-md-4">

                            <div class="card dashboard-card">

                                <div class="card-body">

                                    <h6 class="text-muted">
                                        Active Users
                                    </h6>

                                    <h3 class="mb-0">
                                        <?= $activeUsers; ?>
                                    </h3>

                                </div>

                            </div>

                        </div>


                        <div class="col-md-4">

                            <div class="card dashboard-card">

                                <div class="card-body">

                                    <h6 class="text-muted">
                                        Pending Actions
                                    </h6>

                                    <h3 class="mb-0">
                                        <?= $pendingVendors + $pendingProducts; ?>
                                    </h3>

                                    <small class="text-muted">
                                        Vendors + Products
                                    </small>

                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="card section-card mb-4">

                        <div class="card-header bg-white border-0 p-3">

                            <div class="d-flex justify-content-between align-items-center">

                                <h5 class="mb-0">
                                    Recent Orders
                                </h5>

                                <a
                                    href="orders.php"
                                    class="btn btn-sm btn-outline-primary">
                                    View All
                                </a>

                            </div>

                        </div>

                        <div class="card-body p-0">

                            <div class="table-responsive">

                                <table class="table table-hover mb-0">

                                    <thead>

                                        <tr>

                                            <th class="ps-3">
                                                Order
                                            </th>

                                            <th>
                                                Customer
                                            </th>

                                            <th>
                                                Amount
                                            </th>

                                            <th>
                                                Payment
                                            </th>

                                            <th>
                                                Status
                                            </th>

                                            <th>
                                                Date
                                            </th>

                                        </tr>

                                    </thead>

                                    <tbody>

                                        <?php if (empty($recentOrders)): ?>

                                            <tr>

                                                <td
                                                    colspan="6"
                                                    class="text-center text-muted py-4">
                                                    No orders found.
                                                </td>

                                            </tr>

                                        <?php else: ?>

                                                        <?php foreach ($recentOrders as $index => $order): ?>

                                                <tr>

                                                    <td class="ps-3 fw-semibold">
                                                        <?= $index + 1; ?>
                                                    </td>


                                                    <td>
                                                        <?= htmlspecialchars(
                                                            $order['customer_name'] ?? 'Guest'
                                                        ); ?>
                                                    </td>

                                                    <td>
                                                        ₹<?= number_format(
                                                                (float) $order['total_amount'],
                                                                2
                                                            ); ?>
                                                    </td>

                                                    <td>
                                                        <?= htmlspecialchars(
                                                            ucfirst(
                                                                str_replace(
                                                                    '_',
                                                                    ' ',
                                                                    $order['payment_method']
                                                                )
                                                            )
                                                        ); ?>
                                                    </td>

                                                    <td>
                                                        <?= orderStatusBadge(
                                                            $order['order_status']
                                                        ); ?>
                                                    </td>

                                                    <td>
                                                        <?= date(
                                                            'd M Y',
                                                            strtotime($order['created_at'])
                                                        ); ?>
                                                    </td>

                                                </tr>

                                            <?php endforeach; ?>

                                        <?php endif; ?>

                                    </tbody>

                                </table>

                            </div>

                        </div>

                    </div>
                    <div class="card section-card">

                        <div class="card-header bg-white border-0 p-3">

                            <div class="d-flex justify-content-between align-items-center">

                                <h5 class="mb-0">
                                    Recent Vendor Registrations
                                </h5>

                                <a
                                    href="vendors.php"
                                    class="btn btn-sm btn-outline-primary">
                                    Manage Vendors
                                </a>

                            </div>

                        </div>

                        <div class="card-body p-0">

                            <div class="table-responsive">

                                <table class="table table-hover mb-0">

                                    <thead>

                                        <tr>

                                            <th class="ps-3">
                                                Store
                                            </th>

                                            <th>
                                                Owner
                                            </th>

                                            <th>
                                                Email
                                            </th>

                                            <th>
                                                Verification
                                            </th>

                                            <th>
                                                Registered
                                            </th>

                                        </tr>

                                    </thead>

                                    <tbody>

                                        <?php if (empty($recentVendors)): ?>

                                            <tr>

                                                <td
                                                    colspan="5"
                                                    class="text-center text-muted py-4">
                                                    No vendors found.
                                                </td>

                                            </tr>

                                        <?php else: ?>

                                            <?php foreach ($recentVendors as $vendor): ?>

                                                <tr>

                                                    <td class="ps-3">

                                                        <div class="fw-semibold">
                                                            <?= htmlspecialchars(
                                                                $vendor['store_name']
                                                            ); ?>
                                                        </div>

                                                        <?php if (!empty($vendor['business_name'])): ?>

                                                            <small class="text-muted">
                                                                <?= htmlspecialchars(
                                                                    $vendor['business_name']
                                                                ); ?>
                                                            </small>

                                                        <?php endif; ?>

                                                    </td>

                                                    <td>
                                                        <?= htmlspecialchars(
                                                            $vendor['owner_name']
                                                        ); ?>
                                                    </td>

                                                    <td>
                                                        <?= htmlspecialchars(
                                                            $vendor['email']
                                                        ); ?>
                                                    </td>

                                                    <td>
                                                        <?= verificationBadge(
                                                            $vendor['verification_status']
                                                        ); ?>
                                                    </td>

                                                    <td>
                                                        <?= date(
                                                            'd M Y',
                                                            strtotime($vendor['created_at'])
                                                        ); ?>
                                                    </td>

                                                </tr>

                                            <?php endforeach; ?>

                                        <?php endif; ?>

                                    </tbody>

                                </table>

                            </div>

                        </div>

                    </div>

<?php adminFooter();