<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/includes/layout.php";
require_once "../connection/dbconnect.php";

if (empty($_SESSION['orders_csrf_token'])) {
    $_SESSION['orders_csrf_token'] = bin2hex(random_bytes(32));
}

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

$allowedOrderStatuses = [
    'confirmed',
    'processing',
    'packed',
    'shipped',
    'out_for_delivery',
    'delivered',
    'completed',
    'cancelled',
    'returned',
    'failed'
];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update_order_status'
) {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !hash_equals(
            $_SESSION['orders_csrf_token'],
            $submittedToken
        )
    ) {
        die('Invalid security token. Please refresh the page.');
    }

    $newStatus = strtolower(
        trim($_POST['order_status'] ?? '')
    );

    $adminNote = trim($_POST['status_note'] ?? '');

    if (!in_array($newStatus, $allowedOrderStatuses, true)) {
        header("Location: order-details.php?id=" . $orderId . "&status_error=invalid");
        exit;
    }

    try {
        $db->beginTransaction();
        $currentStmt = $db->prepare("
            SELECT order_status
            FROM orders
            WHERE id = :order_id
            FOR UPDATE
        ");

        $currentStmt->execute([
            ':order_id' => $orderId
        ]);

        $currentStatus = $currentStmt->fetchColumn();

        if ($currentStatus === false) {
            throw new Exception('Order not found.');
        }

        $currentStatus = strtolower($currentStatus);

        if ($currentStatus === $newStatus) {
            $db->rollBack();

            header("Location: order-details.php?id=" . $orderId . "&status_error=same");
            exit;
        }
        $updateStmt = $db->prepare("
            UPDATE orders
            SET order_status = :status
            WHERE id = :order_id
        ");

        $updateStmt->execute([
            ':status' => $newStatus,
            ':order_id' => $orderId
        ]);
        $statusTitle = ucwords(
            str_replace('_', ' ', $newStatus)
        );

        if ($adminNote === '') {
            $adminNote = "Your order status has been updated to "
                . $statusTitle . ".";
        }

        $historyStmt = $db->prepare("
            INSERT INTO order_tracking_updates (
                order_id,
                shipment_id,
                event_type,
                previous_status,
                new_status,
                title,
                description,
                event_date,
                visible_to_customer
            )
            VALUES (
                :order_id,
                NULL,
                'order_status',
                :previous_status,
                :new_status,
                :title,
                :description,
                NOW(),
                1
            )
        ");

        $historyStmt->execute([
            ':order_id' => $orderId,
            ':previous_status' => $currentStatus,
            ':new_status' => $newStatus,
            ':title' => $statusTitle,
            ':description' => $adminNote
        ]);

        $db->commit();

        header("Location: order-details.php?id=" . $orderId . "&status_updated=1");
        exit;
    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log("Order status update error: " . $e->getMessage());

        header("Location: order-details.php?id=" . $orderId . "&status_error=save");
        exit;
    }
}
$allowedShipmentStatuses = [
    'pending',
    'shipped',
    'out_for_delivery',
    'delivered'
];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'save_shipment'
) {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !hash_equals(
            $_SESSION['orders_csrf_token'],
            $submittedToken
        )
    ) {
        die('Invalid security token. Please refresh the page.');
    }

    $courierName = trim($_POST['courier_name'] ?? '');
    $trackingNumber = trim($_POST['tracking_number'] ?? '');
    $trackingUrl = trim($_POST['tracking_url'] ?? '');

    $shipmentStatus = strtolower(
        trim($_POST['shipment_status'] ?? 'pending')
    );

    $shippedAtInput = trim($_POST['shipped_at'] ?? '');
    $estimatedDeliveryInput = trim(
        $_POST['estimated_delivery_at'] ?? ''
    );

    $shipmentNote = trim($_POST['shipment_note'] ?? '');

    if (
        !in_array(
            $shipmentStatus,
            $allowedShipmentStatuses,
            true
        )
    ) {
        header("Location: order-details.php?id=" . $orderId . "&shipment_error=invalid");
        exit;
    }

    if (
        $shipmentStatus !== 'pending' &&
        ($courierName === '' || $trackingNumber === '')
    ) {
        header("Location: order-details.php?id=" . $orderId . "&shipment_error=required");
        exit;
    }

    if (
        $trackingUrl !== '' &&
        !filter_var($trackingUrl, FILTER_VALIDATE_URL)
    ) {
        header("Location: order-details.php?id=" . $orderId . "&shipment_error=url");
        exit;
    }
    $convertShipmentDate = function ($value) {

        if ($value === '') {
            return null;
        }

        $date = DateTime::createFromFormat(
            'Y-m-d\TH:i',
            $value
        );

        if (!$date) {
            return false;
        }

        return $date->format('Y-m-d H:i:s');
    };

    $shippedAt = $convertShipmentDate($shippedAtInput);

    $estimatedDeliveryAt = $convertShipmentDate(
        $estimatedDeliveryInput
    );

    if (
        $shippedAt === false ||
        $estimatedDeliveryAt === false
    ) {
        header("Location: order-details.php?id=" . $orderId . "&shipment_error=date");
        exit;
    }

    if (
        $shippedAt &&
        $estimatedDeliveryAt &&
        strtotime($estimatedDeliveryAt) < strtotime($shippedAt)
    ) {
        header("Location: order-details.php?id=" . $orderId . "&shipment_error=date_order");
        exit;
    }

    try {

        $db->beginTransaction();
        $existingStmt = $db->prepare("
            SELECT *
            FROM order_shipments
            WHERE order_id = :order_id
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");

        $existingStmt->execute([
            ':order_id' => $orderId
        ]);

        $existingShipment = $existingStmt->fetch(
            PDO::FETCH_ASSOC
        );

        $oldShipmentStatus = $existingShipment
            ? $existingShipment['shipment_status']
            : null;

        $shipmentChanged =
            !$existingShipment ||
            strtolower($oldShipmentStatus) !== $shipmentStatus;

        $deliveredAt = null;

        if ($shipmentStatus === 'delivered') {
            $deliveredAt = $existingShipment['delivered_at']
                ?? date('Y-m-d H:i:s');
        }

        if ($existingShipment) {
            $shipmentStmt = $db->prepare("
                UPDATE order_shipments
                SET
                    courier_name = :courier_name,
                    tracking_number = :tracking_number,
                    tracking_url = :tracking_url,
                    shipped_at = :shipped_at,
                    estimated_delivery_at = :estimated_delivery_at,
                    delivered_at = :delivered_at,
                    shipment_status = :shipment_status
                WHERE id = :shipment_id
                  AND order_id = :order_id
            ");

            $shipmentStmt->execute([
                ':courier_name' => $courierName ?: null,
                ':tracking_number' => $trackingNumber ?: null,
                ':tracking_url' => $trackingUrl ?: null,
                ':shipped_at' => $shippedAt,
                ':estimated_delivery_at' => $estimatedDeliveryAt,
                ':delivered_at' => $deliveredAt,
                ':shipment_status' => $shipmentStatus,
                ':shipment_id' => $existingShipment['id'],
                ':order_id' => $orderId
            ]);

            $shipmentId = (int) $existingShipment['id'];
        } else {
            $shipmentStmt = $db->prepare("
                INSERT INTO order_shipments (
                    order_id,
                    courier_name,
                    tracking_number,
                    tracking_url,
                    shipped_at,
                    estimated_delivery_at,
                    delivered_at,
                    shipment_status
                )
                VALUES (
                    :order_id,
                    :courier_name,
                    :tracking_number,
                    :tracking_url,
                    :shipped_at,
                    :estimated_delivery_at,
                    :delivered_at,
                    :shipment_status
                )
            ");

            $shipmentStmt->execute([
                ':order_id' => $orderId,
                ':courier_name' => $courierName ?: null,
                ':tracking_number' => $trackingNumber ?: null,
                ':tracking_url' => $trackingUrl ?: null,
                ':shipped_at' => $shippedAt,
                ':estimated_delivery_at' => $estimatedDeliveryAt,
                ':delivered_at' => $deliveredAt,
                ':shipment_status' => $shipmentStatus
            ]);

            $shipmentId = (int) $db->lastInsertId();
        }
        if ($shipmentChanged) {

            $shipmentTitle = ucwords(
                str_replace('_', ' ', $shipmentStatus)
            );

            if ($shipmentNote === '') {

                if (!$existingShipment) {
                    $shipmentNote = 'Shipment details have been added to your order.';
                } else {
                    $shipmentNote = 'Your shipment status is now '
                        . $shipmentTitle . '.';
                }
            }

            $trackingStmt = $db->prepare("
                INSERT INTO order_tracking_updates (
                    order_id,
                    shipment_id,
                    event_type,
                    previous_status,
                    new_status,
                    title,
                    description,
                    event_date,
                    visible_to_customer
                )
                VALUES (
                    :order_id,
                    :shipment_id,
                    'shipment_update',
                    :previous_status,
                    :new_status,
                    :title,
                    :description,
                    NOW(),
                    1
                )
            ");

            $trackingStmt->execute([
                ':order_id' => $orderId,
                ':shipment_id' => $shipmentId,
                ':previous_status' => $oldShipmentStatus,
                ':new_status' => $shipmentStatus,
                ':title' => $shipmentTitle,
                ':description' => $shipmentNote
            ]);
        }
        $orderStatusRanks = [
            'confirmed' => 1,
            'processing' => 2,
            'packed' => 3,
            'shipped' => 4,
            'out_for_delivery' => 5,
            'delivered' => 6
        ];

        $mappedOrderStatus = [
            'shipped' => 'shipped',
            'out_for_delivery' => 'out_for_delivery',
            'delivered' => 'delivered'
        ];

        if (isset($mappedOrderStatus[$shipmentStatus])) {

            $currentOrderStatus = strtolower(
                $order['order_status']
            );

            $targetOrderStatus = $mappedOrderStatus[$shipmentStatus];

            $currentRank = $orderStatusRanks[$currentOrderStatus] ?? 0;

            $targetRank = $orderStatusRanks[$targetOrderStatus] ?? 0;

            if (
                $currentRank > 0 &&
                $targetRank > $currentRank
            ) {

                $orderUpdateStmt = $db->prepare("
                    UPDATE orders
                    SET order_status = :status
                    WHERE id = :order_id
                ");

                $orderUpdateStmt->execute([
                    ':status' => $targetOrderStatus,
                    ':order_id' => $orderId
                ]);
                $orderHistoryStmt = $db->prepare("
                    INSERT INTO order_tracking_updates (
                        order_id,
                        shipment_id,
                        event_type,
                        previous_status,
                        new_status,
                        title,
                        description,
                        event_date,
                        visible_to_customer
                    )
                    VALUES (
                        :order_id,
                        :shipment_id,
                        'order_status',
                        :previous_status,
                        :new_status,
                        :title,
                        :description,
                        NOW(),
                        1
                    )
                ");

                $orderHistoryStmt->execute([
                    ':order_id' => $orderId,
                    ':shipment_id' => $shipmentId,
                    ':previous_status' => $currentOrderStatus,
                    ':new_status' => $targetOrderStatus,
                    ':title' => ucwords(
                        str_replace('_', ' ', $targetOrderStatus)
                    ),
                    ':description' => 'Your order status has been updated to '
                        . ucwords(str_replace('_', ' ', $targetOrderStatus))
                        . '.'
                ]);
            }
        }

        $db->commit();

        header("Location: order-details.php?id=" . $orderId . "&shipment_saved=1");
        exit;
    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log(
            "Shipment save error: " . $e->getMessage()
        );

        header("Location: order-details.php?id=" . $orderId . "&shipment_error=save");
        exit;
    }
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


/* ---------- LOAD EXISTING SHIPMENT ---------- */

$shipmentStmt = $db->prepare("
    SELECT *
    FROM order_shipments
    WHERE order_id = :order_id
    ORDER BY id DESC
    LIMIT 1
");

$shipmentStmt->execute([
    ':order_id' => $orderId
]);

$shipment = $shipmentStmt->fetch(PDO::FETCH_ASSOC);


function orderStatusClass($status)
{
    switch (strtolower((string) $status)) {

        case 'confirmed':
        case 'delivered':
        case 'completed':
            return 'bg-success';

        case 'pending':
            return 'bg-warning text-dark';

        case 'processing':
        case 'packed':
            return 'bg-info text-dark';

        case 'shipped':
        case 'out_for_delivery':
            return 'bg-primary';

        case 'cancelled':
        case 'failed':
        case 'returned':
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
    switch (strtolower((string) $method)) {
        case 'paypal':
            return 'PayPal';

        case 'stripe':
            return 'Stripe';

        case 'razorpay':
            return 'Razorpay';

        default:
            return 'Cash on Delivery';
    }
}

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

<div class="card mb-4">
    <div class="card-body p-4">

        <h5 class="fw-bold mb-3">
            <i class="bi bi-arrow-repeat text-primary me-2"></i>
            Manage Order Status
        </h5>

        <?php if (isset($_GET['status_updated'])): ?>
            <div class="alert alert-success">
                Order status updated successfully.
                Timeline entry has also been saved.
            </div>
        <?php endif; ?>

        <?php if (($_GET['status_error'] ?? '') === 'invalid'): ?>
            <div class="alert alert-danger">
                Invalid order status selected.
            </div>
        <?php elseif (($_GET['status_error'] ?? '') === 'same'): ?>
            <div class="alert alert-warning">
                This order already has the selected status.
            </div>
        <?php elseif (($_GET['status_error'] ?? '') === 'save'): ?>
            <div class="alert alert-danger">
                Unable to update order status. Please try again.
            </div>
        <?php endif; ?>

        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="update_order_status">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['orders_csrf_token']) ?>">

            <div class="mb-3">

                <label class="form-label fw-semibold">
                    Select New Status
                </label>

                <select
                    name="order_status"
                    class="form-select"
                    required>

                    <?php foreach ($allowedOrderStatuses as $status): ?>

                        <option
                            value="<?= e($status) ?>"
                            <?= strtolower($order['order_status']) === $status ? 'selected' : '' ?>>
                            <?= e(ucwords(str_replace('_', ' ', $status))) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="mb-3">

                <label class="form-label fw-semibold">
                    Customer Update Message
                    <small class="text-muted">(Optional)</small>
                </label>

                <textarea
                    name="status_note"
                    class="form-control"
                    rows="3"
                    placeholder="Example: Your order has been packed and is ready for dispatch."></textarea>

                <small class="text-muted">
                    This message will appear in the customer's order timeline.
                </small>

            </div>

            <button
                type="submit"
                class="btn btn-primary rounded-pill px-4">
                <i class="bi bi-check-circle me-2"></i>
                Update Order Status
            </button>

        </form>

    </div>
</div>

<div class="card mb-4">
    <div class="card-body p-4">

        <h5 class="fw-bold mb-2">
            <i class="bi bi-truck text-primary me-2"></i>
            Shipment & Delivery Management
        </h5>

        <p class="text-muted mb-4">
            Add or edit courier information and delivery details for this order.
        </p>

        <?php if (isset($_GET['shipment_saved'])): ?>
            <div class="alert alert-success">
                Shipment details saved successfully.
            </div>
        <?php endif; ?>

        <?php
        $shipmentError = $_GET['shipment_error'] ?? '';
        ?>

        <?php if ($shipmentError === 'required'): ?>
            <div class="alert alert-warning">
                Courier name and tracking number are required when the shipment is dispatched.
            </div>
        <?php elseif ($shipmentError === 'url'): ?>
            <div class="alert alert-warning">
                Please enter a valid tracking URL, including https://.
            </div>
        <?php elseif ($shipmentError === 'date'): ?>
            <div class="alert alert-warning">
                Please enter valid shipment dates.
            </div>
        <?php elseif ($shipmentError === 'date_order'): ?>
            <div class="alert alert-warning">
                Estimated delivery cannot be earlier than the dispatch date.
            </div>
        <?php elseif ($shipmentError === 'invalid'): ?>
            <div class="alert alert-warning">
                Invalid shipment status selected.
            </div>
        <?php elseif ($shipmentError === 'save'): ?>
            <div class="alert alert-danger">
                Unable to save shipment details. Please check the server error log.
            </div>
        <?php endif; ?>

        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="save_shipment">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['orders_csrf_token']) ?>">

            <div class="row g-3">

                <div class="col-md-6">

                    <label class="form-label fw-semibold">
                        Courier Name
                    </label>

                    <input
                        type="text"
                        name="courier_name"
                        class="form-control"
                        placeholder="e.g. Delhivery"
                        value="<?= e($shipment['courier_name'] ?? '') ?>">

                </div>
                <div class="col-md-6">

                    <label class="form-label fw-semibold">
                        Tracking / AWB Number
                    </label>

                    <input
                        type="text"
                        name="tracking_number"
                        class="form-control"
                        placeholder="Enter tracking number"
                        value="<?= e($shipment['tracking_number'] ?? '') ?>">

                </div>
                <div class="col-12">

                    <label class="form-label fw-semibold">
                        Tracking URL
                    </label>

                    <input
                        type="url"
                        name="tracking_url"
                        class="form-control"
                        placeholder="https://courier-website.com/track"
                        value="<?= e($shipment['tracking_url'] ?? '') ?>">

                </div>
                <div class="col-md-6">

                    <label class="form-label fw-semibold">
                        Dispatch Date
                    </label>

                    <input
                        type="datetime-local"
                        name="shipped_at"
                        class="form-control"
                        value="<?= !empty($shipment['shipped_at']) ? e(date('Y-m-d\TH:i', strtotime($shipment['shipped_at']))) : '' ?>">

                </div>

                <div class="col-md-6">

                    <label class="form-label fw-semibold">
                        Estimated Delivery
                    </label>

                    <input
                        type="datetime-local"
                        name="estimated_delivery_at"
                        class="form-control"
                        value="<?= !empty($shipment['estimated_delivery_at']) ? e(date('Y-m-d\TH:i', strtotime($shipment['estimated_delivery_at']))) : '' ?>">

                </div>
                <div class="col-md-6">

                    <label class="form-label fw-semibold">
                        Shipment Status
                    </label>

                    <select
                        name="shipment_status"
                        class="form-select"
                        required>

                        <?php foreach ($allowedShipmentStatuses as $status): ?>

                            <option
                                value="<?= e($status) ?>"
                                <?= strtolower($shipment['shipment_status'] ?? 'pending') === $status ? 'selected' : '' ?>>
                                <?= e(ucwords(str_replace('_', ' ', $status))) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-12">

                    <label class="form-label fw-semibold">
                        Customer Tracking Message
                        <small class="text-muted">(Optional)</small>
                    </label>

                    <textarea
                        name="shipment_note"
                        class="form-control"
                        rows="3"
                        placeholder="Example: Your package has been handed over to Delhivery."></textarea>

                </div>

            </div>

            <div class="d-flex flex-wrap align-items-center gap-3 mt-4">

                <button
                    type="submit"
                    class="btn btn-primary rounded-pill px-4">
                    <i class="bi bi-truck me-2"></i>

                    <?= $shipment ? 'Update Shipment Details' : 'Save Shipment Details' ?>

                </button>

                <?php if (!empty($shipment['tracking_url'])): ?>

                    <a
                        href="<?= e($shipment['tracking_url']) ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="btn btn-outline-secondary rounded-pill px-4">
                        <i class="bi bi-box-arrow-up-right me-2"></i>
                        Open Tracking Page
                    </a>

                <?php endif; ?>

            </div>

        </form>

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
