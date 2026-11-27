<?php

require_once __DIR__ . '/includes/layout.php';

$vendorId = (int) $_SESSION['user_id'];

$database = new Database();
$db = $database->connect();

/*
|--------------------------------------------------------------------------
| Validate Order ID
|--------------------------------------------------------------------------
*/

$orderId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$orderId || $orderId <= 0) {

    http_response_code(400);

    vendorHeader('Order Details', 'orders');
?>

    <div class="container-fluid py-4">

        <div class="alert alert-danger">
            Invalid order ID.
        </div>

        <a href="orders.php" class="btn btn-secondary">
            Back to Orders
        </a>

    </div>

<?php
    vendorFooter();
    exit;
}

/*
|--------------------------------------------------------------------------
| Handle Vendor Order Item Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!vendorVerifyCsrf()) {

        $_SESSION['vendor_flash'][] = [
            'type' => 'danger',
            'message' => 'Security verification failed. Please try again.'
        ];

        header('Location: order-details.php?id=' . $orderId);
        exit;
    }

    $orderItemId = filter_input(
        INPUT_POST,
        'order_item_id',
        FILTER_VALIDATE_INT
    );

    $action = trim($_POST['action'] ?? '');

    $message = '';
    $messageType = 'danger';

    if (!$orderItemId || $orderItemId <= 0) {

        $message = 'Invalid order item.';
    } else {

        /*
        |--------------------------------------------------------------------------
        | Verify This Order Item Belongs to Logged-in Vendor
        |--------------------------------------------------------------------------
        */

        $verifySql = "
            SELECT
                oi.id,
                oi.order_id,
                p.vendor_id,
                COALESCE(vos.status, 'confirmed') AS vendor_status

            FROM order_items oi

            INNER JOIN products p
                ON p.id = oi.product_id

            LEFT JOIN vendor_order_item_statuses vos
                ON vos.order_item_id = oi.id
                AND vos.vendor_id = p.vendor_id

            WHERE
                oi.id = :order_item_id
                AND oi.order_id = :order_id
                AND p.vendor_id = :vendor_id

            LIMIT 1
        ";

        $verifyStmt = $db->prepare($verifySql);

        $verifyStmt->execute([
            ':order_item_id' => $orderItemId,
            ':order_id' => $orderId,
            ':vendor_id' => $vendorId
        ]);

        $ownedItem = $verifyStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ownedItem) {

            $message = 'You are not authorized to update this order item.';
        } else {

            /*
            |--------------------------------------------------------------------------
            | Ensure Status Record Exists
            |--------------------------------------------------------------------------
            */

            $insertStatusSql = "
                INSERT IGNORE INTO vendor_order_item_statuses (
                    order_item_id,
                    order_id,
                    vendor_id,
                    status
                )
                VALUES (
                    :order_item_id,
                    :order_id,
                    :vendor_id,
                    'confirmed'
                )
            ";

            $insertStatusStmt = $db->prepare($insertStatusSql);

            $insertStatusStmt->execute([
                ':order_item_id' => $orderItemId,
                ':order_id' => $orderId,
                ':vendor_id' => $vendorId
            ]);

            $currentStatus = strtolower(
                $ownedItem['vendor_status']
            );

            $newStatus = '';
            $reason = '';

            /*
            |--------------------------------------------------------------------------
            | Confirmed -> Processing
            |--------------------------------------------------------------------------
            */

            if ($action === 'start_processing') {

                if ($currentStatus === 'confirmed') {

                    $newStatus = 'processing';
                    $message = 'Order item marked as processing.';
                    $messageType = 'success';
                } else {

                    $message = 'This item cannot be moved to processing from its current status.';
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Processing -> Packed
            |--------------------------------------------------------------------------
            */ elseif ($action === 'mark_packed') {

                if ($currentStatus === 'processing') {

                    $newStatus = 'packed';
                    $message = 'Order item marked as packed.';
                    $messageType = 'success';
                } else {

                    $message = 'Only processing items can be marked as packed.';
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Request Cancellation
            |--------------------------------------------------------------------------
            */ elseif ($action === 'request_cancellation') {

                $reason = trim(
                    $_POST['cancellation_reason'] ?? ''
                );

                if (!in_array(
                    $currentStatus,
                    ['confirmed', 'processing', 'packed'],
                    true
                )) {

                    $message = 'Cancellation cannot be requested for this item.';
                } elseif (strlen($reason) < 5) {

                    $message = 'Please enter a cancellation reason of at least 5 characters.';
                } elseif (strlen($reason) > 1000) {

                    $message = 'Cancellation reason cannot exceed 1000 characters.';
                } else {

                    $newStatus = 'cancellation_requested';
                    $message = 'Cancellation request submitted for admin approval.';
                    $messageType = 'success';
                }
            } else {

                $message = 'Invalid action.';
            }

            /*
            |--------------------------------------------------------------------------
            | Save Status Update
            |--------------------------------------------------------------------------
            */

            if ($newStatus !== '') {

                if ($newStatus === 'cancellation_requested') {

                    $updateSql = "
                        UPDATE vendor_order_item_statuses

                        SET
                            status = :new_status,
                            cancellation_reason = :reason,
                            cancellation_requested_at = NOW()

                        WHERE
                            order_item_id = :order_item_id
                            AND order_id = :order_id
                            AND vendor_id = :vendor_id
                            AND status = :current_status
                    ";

                    $updateStmt = $db->prepare($updateSql);

                    $updateStmt->execute([
                        ':new_status' => $newStatus,
                        ':reason' => $reason,
                        ':order_item_id' => $orderItemId,
                        ':order_id' => $orderId,
                        ':vendor_id' => $vendorId,
                        ':current_status' => $currentStatus
                    ]);
                } else {

                    $updateSql = "
                        UPDATE vendor_order_item_statuses

                        SET status = :new_status

                        WHERE
                            order_item_id = :order_item_id
                            AND order_id = :order_id
                            AND vendor_id = :vendor_id
                            AND status = :current_status
                    ";

                    $updateStmt = $db->prepare($updateSql);

                    $updateStmt->execute([
                        ':new_status' => $newStatus,
                        ':order_item_id' => $orderItemId,
                        ':order_id' => $orderId,
                        ':vendor_id' => $vendorId,
                        ':current_status' => $currentStatus
                    ]);
                }

                if ($updateStmt->rowCount() !== 1) {

                    $message = 'Status was not updated. Please refresh and try again.';
                    $messageType = 'warning';
                }
            }
        }
    }

    $_SESSION['vendor_flash'][] = [
        'type' => $messageType,
        'message' => $message
    ];

    header('Location: order-details.php?id=' . $orderId);
    exit;
}

/*
|--------------------------------------------------------------------------
| Fetch Only This Vendor's Order Items
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        o.id AS order_id,
        o.user_id,
        o.payment_method,
        o.payment_status,
        o.order_status,
        o.shipping_name,
        o.shipping_phone,
        o.shipping_city,
        o.shipping_state,
        o.shipping_pincode,
        o.created_at,

        oi.id AS order_item_id,
        oi.product_id,
        oi.product_name,
        oi.product_image,
        oi.price,
        oi.quantity,
        oi.subtotal,

        COALESCE(vos.status, 'confirmed') AS vendor_status,
        vos.cancellation_reason,
        vos.cancellation_requested_at

    FROM orders o

    INNER JOIN order_items oi
        ON oi.order_id = o.id

    INNER JOIN products p
        ON p.id = oi.product_id

    LEFT JOIN vendor_order_item_statuses vos
        ON vos.order_item_id = oi.id
        AND vos.vendor_id = p.vendor_id

    WHERE
        o.id = :order_id
        AND p.vendor_id = :vendor_id

    ORDER BY oi.id ASC
";

$stmt = $db->prepare($sql);

$stmt->execute([
    ':order_id' => $orderId,
    ':vendor_id' => $vendorId
]);

$orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Verify Order Ownership
|--------------------------------------------------------------------------
*/

if (empty($orderItems)) {

    http_response_code(404);

    vendorHeader('Order Details', 'orders');
?>

    <div class="container-fluid py-4">

        <div class="alert alert-warning">

            Order not found, or this order does not contain
            any products belonging to your account.

        </div>

        <a href="orders.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i>
            Back to Orders
        </a>

    </div>

<?php
    vendorFooter();
    exit;
}

/*
|--------------------------------------------------------------------------
| Prepare Order Information
|--------------------------------------------------------------------------
*/

$order = $orderItems[0];

$vendorOrderTotal = 0;

foreach ($orderItems as $item) {
    $vendorOrderTotal += (float) $item['subtotal'];
}

$paymentMethod = ucfirst(
    str_replace('_', ' ', $order['payment_method'] ?? '')
);

$paymentStatus = strtolower(
    $order['payment_status'] ?? ''
);

$orderStatus = strtolower(
    $order['order_status'] ?? ''
);

$statusClasses = [
    'confirmed' => 'primary',
    'processing' => 'info',
    'packed' => 'secondary',
    'cancellation_requested' => 'warning',
    'cancelled' => 'danger',
    'shipped' => 'warning',
    'out_for_delivery' => 'warning',
    'delivered' => 'success',
    'completed' => 'success',
    'returned' => 'danger',
    'failed' => 'danger'
];

$orderStatusClass = $statusClasses[$orderStatus] ?? 'secondary';

vendorHeader('Order Details', 'orders');

?>

<div class="container-fluid py-4">

    <!-- Page Header -->

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

        <div>

            <h2 class="mb-1">
                Order Details
            </h2>

            <p class="text-muted mb-0">
                Order #<?= (int) $order['order_id']; ?>
            </p>

        </div>

        <a href="orders.php" class="btn btn-outline-secondary">

            <i class="bi bi-arrow-left"></i>
            Back to Orders

        </a>

    </div>


    <!-- Order Information -->

    <div class="card shadow-sm mb-4">

        <div class="card-header bg-white">

            <h5 class="mb-0">
                Order Information
            </h5>

        </div>

        <div class="card-body">

            <div class="row g-4">

                <div class="col-md-3">

                    <small class="text-muted">
                        Order ID
                    </small>

                    <h6 class="mt-1">
                        #<?= (int) $order['order_id']; ?>
                    </h6>

                </div>

                <div class="col-md-3">

                    <small class="text-muted">
                        Order Date
                    </small>

                    <h6 class="mt-1">
                        <?= vendorEscape($order['created_at']); ?>
                    </h6>

                </div>

                <div class="col-md-3">

                    <small class="text-muted">
                        Payment Method
                    </small>

                    <h6 class="mt-1">
                        <?= vendorEscape($paymentMethod); ?>
                    </h6>

                </div>

                <div class="col-md-3">

                    <small class="text-muted">
                        Payment Status
                    </small>

                    <div class="mt-1">

                        <?php if ($paymentStatus === 'paid'): ?>

                            <span class="badge bg-success">
                                Paid
                            </span>

                        <?php elseif ($paymentStatus === 'failed'): ?>

                            <span class="badge bg-danger">
                                Failed
                            </span>

                        <?php else: ?>

                            <span class="badge bg-warning text-dark">
                                <?= vendorEscape(ucfirst($paymentStatus)); ?>
                            </span>

                        <?php endif; ?>

                    </div>

                </div>

                <div class="col-md-12">

                    <small class="text-muted">
                        Overall Order Status
                    </small>

                    <div class="mt-1">

                        <span class="badge bg-<?= vendorEscape($orderStatusClass); ?>">

                            <?= vendorEscape(
                                ucfirst(str_replace('_', ' ', $orderStatus))
                            ); ?>

                        </span>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- Customer Information -->

    <div class="card shadow-sm mb-4">

        <div class="card-header bg-white">

            <h5 class="mb-0">
                Customer Information
            </h5>

        </div>

        <div class="card-body">

            <div class="row g-4">

                <div class="col-md-6">

                    <small class="text-muted">
                        Customer Name
                    </small>

                    <h6 class="mt-1">
                        <?= vendorEscape($order['shipping_name']); ?>
                    </h6>

                </div>

                <div class="col-md-6">

                    <small class="text-muted">
                        Phone Number
                    </small>

                    <h6 class="mt-1">
                        <?= vendorEscape($order['shipping_phone']); ?>
                    </h6>

                </div>

                <div class="col-md-12">

                    <small class="text-muted">
                        Shipping Address
                    </small>

                    <h6 class="mt-1">

                        <?= vendorEscape($order['shipping_city']); ?>,

                        <?= vendorEscape($order['shipping_state']); ?>

                        -

                        <?= vendorEscape($order['shipping_pincode']); ?>

                    </h6>

                </div>

            </div>

        </div>

    </div>


    <!-- Vendor Products -->

    <div class="card shadow-sm mb-4">

        <div class="card-header bg-white">

            <h5 class="mb-0">
                Your Products in This Order
            </h5>

        </div>

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-bordered align-middle">

                    <thead class="table-light">

                        <tr>

                            <th>Product</th>
                            <th>Product ID</th>
                            <th>Price</th>
                            <th>Quantity</th>
                            <th>Subtotal</th>
                            <th style="min-width: 260px;">Vendor Status / Action</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($orderItems as $item): ?>

                            <?php
                            $vendorStatus = strtolower(
                                $item['vendor_status'] ?? 'confirmed'
                            );

                            $vendorStatusClass =
                                $statusClasses[$vendorStatus] ?? 'secondary';
                            ?>

                            <tr>

                                <td>

                                    <div class="d-flex align-items-center gap-3">

                                        <?php if (!empty($item['product_image'])): ?>

                                            <img
                                                src="../<?= vendorEscape($item['product_image']); ?>"
                                                alt="Product"
                                                class="rounded border"
                                                style="
                                                    width: 60px;
                                                    height: 60px;
                                                    object-fit: cover;
                                                ">

                                        <?php endif; ?>

                                        <strong>
                                            <?= vendorEscape($item['product_name']); ?>
                                        </strong>

                                    </div>

                                </td>

                                <td>
                                    <?= (int) $item['product_id']; ?>
                                </td>

                                <td>
                                    ₹<?= number_format((float) $item['price'], 2); ?>
                                </td>

                                <td>
                                    <?= (int) $item['quantity']; ?>
                                </td>

                                <td>
                                    <strong>
                                        ₹<?= number_format((float) $item['subtotal'], 2); ?>
                                    </strong>
                                </td>

                                <td>

                                    <span class="badge bg-<?= vendorEscape($vendorStatusClass); ?> mb-2">

                                        <?= vendorEscape(
                                            ucfirst(str_replace('_', ' ', $vendorStatus))
                                        ); ?>

                                    </span>


                                    <?php if ($vendorStatus === 'confirmed'): ?>

                                        <form method="POST" class="mb-2">

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= vendorEscape(vendorCsrfToken()); ?>">

                                            <input
                                                type="hidden"
                                                name="order_item_id"
                                                value="<?= (int) $item['order_item_id']; ?>">

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="start_processing">

                                            <button
                                                type="submit"
                                                class="btn btn-info btn-sm text-dark">

                                                <i class="bi bi-play-circle"></i>
                                                Start Processing

                                            </button>

                                        </form>

                                    <?php elseif ($vendorStatus === 'processing'): ?>

                                        <form method="POST" class="mb-2">

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= vendorEscape(vendorCsrfToken()); ?>">

                                            <input
                                                type="hidden"
                                                name="order_item_id"
                                                value="<?= (int) $item['order_item_id']; ?>">

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="mark_packed">

                                            <button
                                                type="submit"
                                                class="btn btn-secondary btn-sm">

                                                <i class="bi bi-box-seam"></i>
                                                Mark as Packed

                                            </button>

                                        </form>

                                    <?php endif; ?>


                                    <?php if (in_array(
                                        $vendorStatus,
                                        ['confirmed', 'processing', 'packed'],
                                        true
                                    )): ?>

                                        <form method="POST" class="mt-2">

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= vendorEscape(vendorCsrfToken()); ?>">

                                            <input
                                                type="hidden"
                                                name="order_item_id"
                                                value="<?= (int) $item['order_item_id']; ?>">

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="request_cancellation">

                                            <textarea
                                                name="cancellation_reason"
                                                class="form-control form-control-sm mb-2"
                                                rows="2"
                                                maxlength="1000"
                                                minlength="5"
                                                placeholder="Reason for cancellation"
                                                required></textarea>

                                            <button
                                                type="submit"
                                                class="btn btn-outline-danger btn-sm">

                                                <i class="bi bi-x-circle"></i>
                                                Request Cancellation

                                            </button>

                                        </form>

                                    <?php elseif ($vendorStatus === 'cancellation_requested'): ?>

                                        <span class="badge bg-warning text-dark">
                                            Cancellation Requested
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                    <tfoot>

                        <tr>

                            <th colspan="4" class="text-end">
                                Your Products Total:
                            </th>

                            <th colspan="2">
                                ₹<?= number_format($vendorOrderTotal, 2); ?>
                            </th>

                        </tr>

                    </tfoot>

                </table>

            </div>

        </div>

    </div>


    <!-- Shipment Information -->

    <div class="alert alert-info">

        <h6 class="alert-heading">

            <i class="bi bi-info-circle"></i>
            Important Information

        </h6>

        <p class="mb-0">

            Processing and packing statuses apply only to your
            products.

            Shipment, delivery, payment, COD collection and
            overall order status remain managed through the
            admin and courier workflow.

            Cancellation requests require admin approval.

        </p>

    </div>

</div>

<?php vendorFooter(); ?>