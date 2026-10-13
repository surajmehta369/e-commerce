
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

function detailEscape($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function detailMoney($amount) {
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

function detailStatusClass($status) {
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

$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$orderId || $orderId < 1) {
    http_response_code(400);
    exit('Invalid order ID. <a href="orders.php">Return to orders</a>');
}

$errorMessage = '';
$successMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order_status'])) {
    $postedToken = $_POST['csrf_token'] ?? '';
    $newStatus = $_POST['order_status'] ?? '';

    if (!hash_equals($_SESSION['orders_csrf_token'], $postedToken)) {
        $errorMessage = 'Invalid request. Please refresh the page and try again.';
    } elseif (!array_key_exists($newStatus, $statusOptions)) {
        $errorMessage = 'Please select a valid order status.';
    } else {
        $update = $db->prepare(
            "UPDATE orders SET order_status = :status WHERE id = :id"
        );
        $update->execute([
            ':status' => $newStatus,
            ':id' => $orderId
        ]);

        header("Location: order-details.php?id=" . $orderId . "&updated=1");
        exit;
    }
}

if (isset($_GET['updated'])) {
    $successMessage = 'Order status updated successfully.';
}
$orderStmt = $db->prepare("
    SELECT
        o.*,
        u.name AS customer_name,
        u.email AS customer_email
    FROM orders o
    LEFT JOIN users u ON u.id = o.user_id
    WHERE o.id = :id
    LIMIT 1
");
$orderStmt->execute([':id' => $orderId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    exit('Order not found. <a href="orders.php">Return to orders</a>');
}
$itemStmt = $db->prepare("
    SELECT
        id,
        product_id,
        product_name,
        product_image,
        price,
        quantity,
        subtotal
    FROM order_items
    WHERE order_id = :order_id
    ORDER BY id ASC
");
$itemStmt->execute([':order_id' => $orderId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details | Admin Dashboard</title>

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
            color: white;
        }

        .main-content {
            margin-left: 240px;
            padding: 30px;
        }

        .page-title {
            font-weight: 700;
            font-size: 28px;
        }

        .content-card {
            background: white;
            border: 0;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 22px;
            box-shadow: 0 3px 15px rgba(15, 23, 42, .05);
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .detail-label {
            color: #6b7280;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .detail-value {
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .product-image {
            width: 58px;
            height: 58px;
            object-fit: contain;
            background: #f8fafc;
            border-radius: 8px;
            padding: 4px;
        }

        .table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 12px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
            font-size: 14px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 9px 0;
        }

        .summary-total {
            border-top: 1px solid #e5e7eb;
            margin-top: 8px;
            padding-top: 16px;
            font-size: 19px;
            font-weight: 700;
        }

        .badge {
            padding: 7px 9px;
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
            <h1 class="page-title mb-1">Order #<?= (int)$order['id'] ?></h1>
            <p class="text-secondary mb-0">
                Placed on
                <?= detailEscape(date('d M Y, h:i A', strtotime($order['created_at']))) ?>
            </p>
        </div>

        <a href="orders.php" class="btn btn-outline-secondary">
            Back to Orders
        </a>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger"><?= detailEscape($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success"><?= detailEscape($successMessage) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-xl-8">

            <div class="content-card">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                    <h2 class="section-title mb-0">Ordered Products</h2>

                    <span class="badge text-bg-<?= detailStatusClass($order['order_status']) ?>">
                        <?= detailEscape(ucfirst($order['order_status'])) ?>
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                        <tr>
                            <th>Product</th>
                            <th>Price</th>
                            <th>Quantity</th>
                            <th>Subtotal</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($items): ?>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <?php if (!empty($item['product_image'])): ?>
                                                <img
                                                    src="<?= detailEscape($item['product_image']) ?>"
                                                    alt="<?= detailEscape($item['product_name']) ?>"
                                                    class="product-image"
                                                    onerror="this.style.display='none'">
                                            <?php else: ?>
                                                <div class="product-image d-flex align-items-center justify-content-center">
                                                    <span class="text-secondary small">No image</span>
                                                </div>
                                            <?php endif; ?>

                                            <div>
                                                <div class="fw-semibold">
                                                    <?= detailEscape($item['product_name']) ?>
                                                </div>
                                                <div class="text-secondary small">
                                                    Product ID: <?= (int)$item['product_id'] ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td><?= detailMoney($item['price']) ?></td>
                                    <td><?= (int)$item['quantity'] ?></td>
                                    <td class="fw-semibold"><?= detailMoney($item['subtotal']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center text-secondary py-4">
                                    No items were found for this order.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="row justify-content-end mt-3">
                    <div class="col-12 col-md-7 col-lg-6">
                        <div class="summary-row">
                            <span class="text-secondary">Products subtotal</span>
                            <strong>
                                <?= detailMoney(array_sum(array_map(
                                    fn($item) => (float)$item['subtotal'],
                                    $items
                                ))) ?>
                            </strong>
                        </div>

                        <div class="summary-row summary-total">
                            <span>Order Total</span>
                            <span><?= detailMoney($order['total_amount']) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-card">
                <h2 class="section-title">Shipping Information</h2>

                <div class="row g-4">
                    <div class="col-12 col-md-6">
                        <div class="detail-label">Recipient Name</div>
                        <div class="detail-value"><?= detailEscape($order['shipping_name']) ?></div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="detail-label">Phone Number</div>
                        <div class="detail-value"><?= detailEscape($order['shipping_phone']) ?></div>
                    </div>

                    <div class="col-12">
                        <div class="detail-label">Shipping Address</div>
                        <div class="detail-value"><?= nl2br(detailEscape($order['shipping_address'])) ?></div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="detail-label">City</div>
                        <div class="detail-value"><?= detailEscape($order['shipping_city']) ?></div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="detail-label">State</div>
                        <div class="detail-value"><?= detailEscape($order['shipping_state']) ?></div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="detail-label">PIN Code</div>
                        <div class="detail-value"><?= detailEscape($order['shipping_pincode']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">

            <div class="content-card">
                <h2 class="section-title">Customer Information</h2>

                <div class="mb-3">
                    <div class="detail-label">Customer Name</div>
                    <div class="detail-value">
                        <?= detailEscape($order['customer_name'] ?: $order['shipping_name']) ?>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="detail-label">Email Address</div>
                    <div class="detail-value">
                        <?= detailEscape($order['customer_email'] ?? 'Not available') ?>
                    </div>
                </div>

                <div>
                    <div class="detail-label">Customer ID</div>
                    <div class="detail-value"><?= (int)$order['user_id'] ?></div>
                </div>
            </div>

            <div class="content-card">
                <h2 class="section-title">Payment Information</h2>

                <div class="mb-3">
                    <div class="detail-label">Payment Method</div>
                    <div class="detail-value">
                        <?= detailEscape(ucwords(str_replace('_', ' ', $order['payment_method']))) ?>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="detail-label">Payment Status</div>
                    <span class="badge text-bg-<?= detailStatusClass($order['payment_status']) ?>">
                        <?= detailEscape(ucfirst($order['payment_status'])) ?>
                    </span>
                </div>

                <div>
                    <div class="detail-label">Order Total</div>
                    <div class="detail-value fs-4">
                        <?= detailMoney($order['total_amount']) ?>
                    </div>
                </div>
            </div>

            <div class="content-card">
                <h2 class="section-title">Update Order Status</h2>

                <form method="POST">
                    <input type="hidden" name="csrf_token"
                           value="<?= detailEscape($_SESSION['orders_csrf_token']) ?>">

                    <label for="order_status" class="form-label">Select Status</label>

                    <select name="order_status" id="order_status" class="form-select mb-3" required>
                        <?php foreach ($statusOptions as $value => $label): ?>
                            <option value="<?= detailEscape($value) ?>"
                                <?= $order['order_status'] === $value ? 'selected' : '' ?>>
                                <?= detailEscape($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" name="update_order_status"
                            class="btn btn-dark w-100">
                        Save Order Status
                    </button>
                </form>
            </div>
        </div>
    </div>
</main>

</body>
</html>
