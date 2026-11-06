<?php

require_once __DIR__ . "/includes/layout.php";
require_once "../connection/dbconnect.php";

if (
    !isset($_GET['id']) ||
    !is_numeric($_GET['id'])
) {
    header("Location: orders.php");
    exit;
}

$orderId = (int) $_GET['id'];

$database = new Database();
$db = $database->connect();

// Admin can open ANY order (no user_id filter), plus customer name / email.
$orderSql = "
    SELECT
        o.id,
        o.user_id,
        o.total_amount,
        o.payment_method,
        o.payment_status,
        o.order_status,
        o.shipping_name,
        o.shipping_phone,
        o.shipping_address,
        o.shipping_city,
        o.shipping_state,
        o.shipping_pincode,
        o.created_at,
        u.name  AS customer_name,
        u.email AS customer_email
    FROM orders o
    LEFT JOIN users u
        ON u.id = o.user_id
    WHERE o.id = :order_id
    LIMIT 1
";

$orderStmt = $db->prepare($orderSql);
$orderStmt->execute([
    ':order_id' => $orderId
]);

$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header("Location: orders.php");
    exit;
}

$itemSql = "
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
";

$itemStmt = $db->prepare($itemSql);
$itemStmt->execute([
    ':order_id' => $orderId
]);

$orderItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- helpers ---------- */

function orderStatusClass($status)
{
    switch (strtolower((string) $status)) {
        case 'confirmed':
        case 'delivered':
            return 'bg-success';
        case 'pending':
            return 'bg-warning text-dark';
        case 'processing':
            return 'bg-info text-dark';
        case 'shipped':
            return 'bg-primary';
        case 'cancelled':
            return 'bg-danger';
        default:
            return 'bg-secondary';
    }
}

function paymentStatusClass($status)
{
    switch (strtolower((string) $status)) {
        case 'paid':
            return 'bg-success';
        case 'pending':
            return 'bg-warning text-dark';
        case 'failed':
            return 'bg-danger';
        default:
            return 'bg-secondary';
    }
}

function paymentMethodLabel($method)
{
    switch ($method) {
        case 'paypal':
            return 'PayPal';
        case 'stripe':
            return 'Stripe';
        default:
            return 'Cash on Delivery';
    }
}

// Product images are saved relative to the site root; the admin lives one folder deeper.
function adminImageUrl($path)
{
    $path = trim((string) $path);

    if ($path === '') {
        return '';
    }

    if (preg_match('#^(https?:)?//#i', $path)) {
        return $path;
    }

    return '../' . ltrim($path, '/');
}

$orderStatusClass = orderStatusClass($order['order_status']);
$paymentStatusClass = paymentStatusClass($order['payment_status']);

adminHeader('Order #' . $order['id'], 'orders');
?>

<div class="page-header">
    <div>
        <h2 class="mb-1">
            <i class="bi bi-box-seam text-primary me-2"></i>
            Order #<?= e($order['id']) ?>
        </h2>

        <p class="text-muted mb-0">
            Order placed on <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?>
        </p>
    </div>

    <a href="orders.php" class="btn btn-outline-primary rounded-pill px-4">
        <i class="bi bi-arrow-left me-2"></i>
        Back to Orders
    </a>
</div>

<div class="card mb-4">
    <div class="card-body p-4">
        <div class="row align-items-center g-3">

            <div class="col-md-4">
                <small class="text-muted d-block">Order Status</small>
                <span class="badge <?= e($orderStatusClass) ?> px-3 py-2 mt-2">
                    <?= e(ucfirst($order['order_status'])) ?>
                </span>
            </div>

            <div class="col-md-4">
                <small class="text-muted d-block">Payment Status</small>
                <span class="badge <?= e($paymentStatusClass) ?> px-3 py-2 mt-2">
                    <?= e(ucfirst($order['payment_status'])) ?>
                </span>
            </div>

            <div class="col-md-4 text-md-end">
                <small class="text-muted d-block">Order Total</small>
                <h4 class="fw-bold text-primary mb-0 mt-1">
                    ₹<?= number_format((float) $order['total_amount'], 2) ?>
                </h4>
            </div>

        </div>
    </div>
</div>

<div class="row g-4">

    <div class="col-lg-8">
        <div class="card">
            <div class="card-body p-4">

                <h4 class="fw-bold mb-4">
                    <i class="bi bi-cart3 text-primary me-2"></i>
                    Order Items
                </h4>

                <?php if (empty($orderItems)): ?>
                    <p class="text-muted">No items found for this order.</p>
                <?php endif; ?>

                <?php foreach ($orderItems as $item): ?>

                    <div class="d-flex align-items-center border-bottom pb-4 mb-4">

                        <?php $imageUrl = adminImageUrl($item['product_image']); ?>

                        <?php if ($imageUrl !== ''): ?>
                            <img src="<?= e($imageUrl) ?>"
                                alt="<?= e($item['product_name']) ?>"
                                style="width:100px;height:100px;object-fit:cover;border-radius:12px;">
                        <?php else: ?>
                            <div class="bg-light d-flex align-items-center justify-content-center text-muted"
                                style="width:100px;height:100px;border-radius:12px;">
                                <i class="bi bi-image fs-3"></i>
                            </div>
                        <?php endif; ?>

                        <div class="ms-3 flex-grow-1">
                            <h5 class="fw-bold mb-2"><?= e($item['product_name']) ?></h5>

                            <p class="text-muted mb-1">
                                Price: ₹<?= number_format((float) $item['price'], 2) ?>
                            </p>

                            <p class="text-muted mb-0">
                                Quantity: <?= (int) $item['quantity'] ?>
                            </p>
                        </div>

                        <div class="text-end">
                            <small class="text-muted d-block">Subtotal</small>
                            <strong class="fs-5">
                                ₹<?= number_format((float) $item['subtotal'], 2) ?>
                            </strong>
                        </div>

                    </div>

                <?php endforeach; ?>

                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0">Total</h5>
                    <h4 class="fw-bold text-primary mb-0">
                        ₹<?= number_format((float) $order['total_amount'], 2) ?>
                    </h4>
                </div>

            </div>
        </div>
    </div>

    <div class="col-lg-4">

        <div class="card mb-4">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i class="bi bi-person-circle text-primary me-2"></i>
                    Customer
                </h5>

                <h6 class="fw-bold mb-1">
                    <?= e($order['customer_name'] ?: 'Deleted user') ?>
                </h6>

                <?php if (!empty($order['customer_email'])): ?>
                    <p class="text-muted mb-0">
                        <i class="bi bi-envelope me-2"></i><?= e($order['customer_email']) ?>
                    </p>
                <?php endif; ?>

            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i class="bi bi-geo-alt text-primary me-2"></i>
                    Delivery Address
                </h5>

                <h6 class="fw-bold"><?= e($order['shipping_name']) ?></h6>

                <p class="text-muted mb-2">
                    <i class="bi bi-telephone me-2"></i><?= e($order['shipping_phone']) ?>
                </p>

                <p class="text-muted mb-0">
                    <?= nl2br(e($order['shipping_address'])) ?>
                    <br>
                    <?= e($order['shipping_city']) ?>,
                    <?= e($order['shipping_state']) ?>
                    -
                    <?= e($order['shipping_pincode']) ?>
                </p>

            </div>
        </div>

        <div class="card">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i class="bi bi-credit-card text-primary me-2"></i>
                    Payment
                </h5>

                <div class="d-flex justify-content-between mb-3">
                    <span class="text-muted">Method</span>
                    <strong><?= e(paymentMethodLabel($order['payment_method'])) ?></strong>
                </div>

                <div class="d-flex justify-content-between">
                    <span class="text-muted">Status</span>
                    <span class="badge <?= e($paymentStatusClass) ?> px-3 py-2">
                        <?= e(ucfirst($order['payment_status'])) ?>
                    </span>
                </div>

            </div>
        </div>

    </div>
</div>

<?php adminFooter();