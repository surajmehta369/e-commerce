
<?php
require_once "auth.php";
require_once "../connection/dbconnect.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$database = new Database();
$db = $database->connect();

if (empty($_SESSION['orders_csrf_token'])) {
    $_SESSION['orders_csrf_token'] = bin2hex(random_bytes(32));
}

function orderEscape($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function orderMoney($amount) {
    return '₹' . number_format((float)$amount, 2);
}

$statusOptions = [
    'confirmed'  => 'Confirmed',
    'pending'    => 'Pending',
    'processing' => 'Processing',
    'shipped'    => 'Shipped',
    'delivered'  => 'Delivered',
    'cancelled'  => 'Cancelled'
];

$paymentOptions = [
    'pending'  => 'Pending',
    'paid'     => 'Paid',
    'completed'=> 'Completed',
    'failed'   => 'Failed',
    'refunded' => 'Refunded'
];

$message = '';
$messageType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order_status'])) {
    $postedToken = $_POST['csrf_token'] ?? '';
    $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    $newStatus = $_POST['order_status'] ?? '';

    if (!hash_equals($_SESSION['orders_csrf_token'], $postedToken)) {
        $message = 'Invalid request. Please refresh the page and try again.';
        $messageType = 'danger';
    } elseif (!$orderId || !array_key_exists($newStatus, $statusOptions)) {
        $message = 'Invalid order or status.';
        $messageType = 'danger';
    } else {
        $update = $db->prepare(
            "UPDATE orders SET order_status = :status WHERE id = :id"
        );
        $update->execute([
            ':status' => $newStatus,
            ':id' => $orderId
        ]);

        header("Location: orders.php?updated=1");
        exit;
    }
}

if (isset($_GET['updated'])) {
    $message = 'Order status updated successfully.';
}

$search = trim($_GET['search'] ?? '');
$orderStatus = $_GET['order_status'] ?? '';
$paymentStatus = $_GET['payment_status'] ?? '';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        CAST(o.id AS CHAR) LIKE :search_id
        OR u.name LIKE :search_name
        OR u.email LIKE :search_email
        OR o.shipping_name LIKE :search_shipping
        OR o.shipping_phone LIKE :search_phone
    )";

    $searchValue = '%' . $search . '%';
    $params[':search_id'] = $searchValue;
    $params[':search_name'] = $searchValue;
    $params[':search_email'] = $searchValue;
    $params[':search_shipping'] = $searchValue;
    $params[':search_phone'] = $searchValue;
}

if (array_key_exists($orderStatus, $statusOptions)) {
    $where[] = "o.order_status = :order_status";
    $params[':order_status'] = $orderStatus;
} else {
    $orderStatus = '';
}

if (array_key_exists($paymentStatus, $paymentOptions)) {
    $where[] = "o.payment_status = :payment_status";
    $params[':payment_status'] = $paymentStatus;
} else {
    $paymentStatus = '';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        o.id,
        o.user_id,
        o.total_amount,
        o.payment_method,
        o.payment_status,
        o.order_status,
        o.shipping_name,
        o.shipping_phone,
        o.created_at,
        u.name AS customer_name,
        u.email AS customer_email,
        (
            SELECT COALESCE(SUM(oi.quantity), 0)
            FROM order_items oi
            WHERE oi.order_id = o.id
        ) AS item_quantity
    FROM orders o
    LEFT JOIN users u ON u.id = o.user_id
    $whereSql
    ORDER BY o.created_at DESC, o.id DESC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalOrders = (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn();

$pendingOrders = (int)$db->query(
    "SELECT COUNT(*) FROM orders WHERE order_status = 'pending'"
)->fetchColumn();

$processingOrders = (int)$db->query(
    "SELECT COUNT(*) FROM orders WHERE order_status = 'processing'"
)->fetchColumn();

$deliveredOrders = (int)$db->query(
    "SELECT COUNT(*) FROM orders WHERE order_status = 'delivered'"
)->fetchColumn();

function orderStatusClass($status) {
    switch (strtolower((string)$status)) {
        case 'delivered':
            return 'success';
        case 'confirmed':
        case 'paid':
        case 'completed':
            return 'primary';
        case 'processing':
            return 'info';
        case 'shipped':
            return 'dark';
        case 'cancelled':
        case 'failed':
            return 'danger';
        case 'pending':
            return 'warning';
        case 'refunded':
            return 'secondary';
        default:
            return 'secondary';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders | Admin Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        body {
            background: #f5f7fb;
            font-family: Arial, sans-serif;
            color: #1f2937;
        }

        .sidebar {
            width: 240px;
            min-height: 100vh;
            background: #111827;
            position: fixed;
            top: 0;
            left: 0;
            padding: 25px 16px;
            color: white;
        }

        .sidebar h4 {
            font-size: 21px;
            margin: 0 0 30px 10px;
            font-weight: 700;
        }

        .sidebar a {
            display: block;
            color: #cbd5e1;
            text-decoration: none;
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 5px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #263449;
            color: #fff;
        }

        .main-content {
            margin-left: 240px;
            padding: 30px;
        }

        .page-title {
            font-weight: 700;
            font-size: 28px;
        }

        .stat-card,
        .table-card {
            background: #fff;
            border: 0;
            border-radius: 14px;
            box-shadow: 0 3px 15px rgba(15, 23, 42, .05);
        }

        .stat-card {
            padding: 22px;
            height: 100%;
        }

        .stat-label {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 27px;
            font-weight: 700;
        }

        .table-card {
            padding: 22px;
        }

        .table th {
            color: #64748b;
            font-size: 12px;
            text-transform: uppercase;
            white-space: nowrap;
            background: #f8fafc;
        }

        .table td {
            vertical-align: middle;
            font-size: 14px;
        }

        .badge {
            font-weight: 600;
            padding: 7px 9px;
        }

        .customer-name {
            font-weight: 600;
            color: #111827;
        }

        .small-muted {
            color: #6b7280;
            font-size: 12px;
        }

        @media (max-width: 900px) {
            .sidebar {
                position: static;
                width: 100%;
                min-height: auto;
            }

            .main-content {
                margin-left: 0;
                padding: 18px;
            }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <h4>Admin Panel</h4>
    <a href="index.php">Dashboard</a>
    <a href="vendors.php">Vendors</a>
    <a href="products.php">Products</a>
    <a href="customers.php">Customers</a>
    <a href="categories.php">Categories</a>
    <a href="brands.php">Brands</a>
    <a href="orders.php" class="active">Orders</a>
    <a href="profile.php">Admin Profile</a>
    <a href="../outh/logout.php">Logout</a>
</aside>

<main class="main-content">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="page-title mb-1">Orders</h1>
            <p class="text-secondary mb-0">Manage customer orders and their status.</p>
        </div>
        <div class="text-secondary small">
            Total orders: <strong><?= $totalOrders ?></strong>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= orderEscape($messageType) ?> alert-dismissible fade show">
            <?= orderEscape($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-label">Total Orders</div>
                <div class="stat-number"><?= $totalOrders ?></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-label">Pending Orders</div>
                <div class="stat-number"><?= $pendingOrders ?></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-label">Processing Orders</div>
                <div class="stat-number"><?= $processingOrders ?></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-label">Delivered Orders</div>
                <div class="stat-number"><?= $deliveredOrders ?></div>
            </div>
        </div>
    </div>

    <div class="table-card">
        <form method="GET" class="row g-2 mb-4">
            <div class="col-12 col-lg-4">
                <input type="text" name="search" class="form-control"
                       placeholder="Search order number, customer, email, phone..."
                       value="<?= orderEscape($search) ?>">
            </div>

            <div class="col-12 col-md-5 col-lg-3">
                <select name="order_status" class="form-select">
                    <option value="">All order statuses</option>
                    <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= orderEscape($value) ?>"
                            <?= $orderStatus === $value ? 'selected' : '' ?>>
                            <?= orderEscape($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-md-5 col-lg-3">
                <select name="payment_status" class="form-select">
                    <option value="">All payment statuses</option>
                    <?php foreach ($paymentOptions as $value => $label): ?>
                        <option value="<?= orderEscape($value) ?>"
                            <?= $paymentStatus === $value ? 'selected' : '' ?>>
                            <?= orderEscape($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-2 col-lg-1 d-grid">
                <button class="btn btn-dark" type="submit">Search</button>
            </div>

            <div class="col-6 col-lg-1 d-grid">
                <a href="orders.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>S.No.</th>
                    <th>Order No.</th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th>Date</th>
                    <th>Total</th>
                    <th>Payment</th>
                    <th>Order Status</th>
                    <th>Update Status</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($orders): ?>
                    <?php foreach ($orders as $index => $order): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>

                            <td>
                                <strong>#<?= (int)$order['id'] ?></strong>
                            </td>

                            <td>
                                <div class="customer-name">
                                    <?= orderEscape($order['customer_name'] ?: $order['shipping_name']) ?>
                                </div>
                                <div class="small-muted">
                                    <?= orderEscape($order['customer_email'] ?? '') ?>
                                </div>
                            </td>

                            <td><?= (int)$order['item_quantity'] ?></td>

                            <td>
                                <?= orderEscape(date('d M Y', strtotime($order['created_at']))) ?>
                                <div class="small-muted">
                                    <?= orderEscape(date('h:i A', strtotime($order['created_at']))) ?>
                                </div>
                            </td>

                            <td><strong><?= orderMoney($order['total_amount']) ?></strong></td>

                            <td>
                                <div><?= orderEscape(ucwords(str_replace('_', ' ', $order['payment_method']))) ?></div>
                                <span class="badge text-bg-<?= orderStatusClass($order['payment_status']) ?>">
                                    <?= orderEscape(ucfirst($order['payment_status'])) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge text-bg-<?= orderStatusClass($order['order_status']) ?>">
                                    <?= orderEscape(ucfirst($order['order_status'])) ?>
                                </span>
                            </td>

                            <td>
                                <form method="POST" class="d-flex gap-1">
                                    <input type="hidden" name="csrf_token"
                                           value="<?= orderEscape($_SESSION['orders_csrf_token']) ?>">
                                    <input type="hidden" name="order_id"
                                           value="<?= (int)$order['id'] ?>">

                                    <select name="order_status"
                                            class="form-select form-select-sm"
                                            aria-label="Order status">
                                        <?php foreach ($statusOptions as $value => $label): ?>
                                            <option value="<?= orderEscape($value) ?>"
                                                <?= $order['order_status'] === $value ? 'selected' : '' ?>>
                                                <?= orderEscape($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button type="submit" name="update_order_status"
                                            class="btn btn-sm btn-dark">Save</button>
                                </form>
                            </td>

                            <td>
                                <a href="order-details.php?id=<?= (int)$order['id'] ?>"
                                   class="btn btn-sm btn-outline-primary">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="text-center text-secondary py-5">
                            No orders found.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
