<?php

session_start();

require_once "connection/dbconnect.php";
require_once "customer-auth.php";


if (
    !isset($_SESSION['user_id']) ||
    $_SESSION['logged_in'] !== true
) {
    header("Location: outh/login.php");
    exit;
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
        id,
        user_id,
        total_amount,
        payment_method,
        payment_status,
        order_status,
        shipping_name,
        shipping_phone,
        shipping_address,
        shipping_city,
        shipping_state,
        shipping_pincode,
        created_at
    FROM orders
    WHERE id = :order_id
      AND user_id = :user_id
    LIMIT 1
";

$orderStmt = $db->prepare($orderSql);

$orderStmt->execute([
    'order_id' => $orderId,
    'user_id' => $_SESSION['user_id']
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
    'order_id' => $orderId
]);

$orderItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

$shipmentSql = "
    SELECT *
    FROM order_shipments
    WHERE order_id = :order_id
    ORDER BY id DESC
    LIMIT 1
";

$shipmentStmt = $db->prepare($shipmentSql);

$shipmentStmt->execute([
    'order_id' => $orderId
]);

$shipment = $shipmentStmt->fetch(PDO::FETCH_ASSOC);

$shipmentStatus = strtolower(
    trim($shipment['shipment_status'] ?? 'pending')
);
$trackingSql = "
    SELECT
        id,
        shipment_id,
        event_type,
        previous_status,
        new_status,
        title,
        description,
        event_date
    FROM order_tracking_updates
    WHERE order_id = :order_id
      AND visible_to_customer = 1
    ORDER BY event_date ASC, id ASC
";

$trackingStmt = $db->prepare($trackingSql);

$trackingStmt->execute([
    'order_id' => $orderId
]);

$trackingUpdates = $trackingStmt->fetchAll(PDO::FETCH_ASSOC);


// Fetch all packages belonging to this shipment.
$packages = [];

if (!empty($shipment['id'])) {
    $packageStmt = $db->prepare("
        SELECT
            id,
            package_number,
            length,
            width,
            height,
            total_weight,
            package_status
        FROM order_packages
        WHERE shipment_id = :shipment_id
        ORDER BY package_number ASC
    ");

    $packageStmt->execute([
        ':shipment_id' => $shipment['id']
    ]);

    $packages = $packageStmt->fetchAll(PDO::FETCH_ASSOC);
}

$codCollection = null;

if (strtolower($order['payment_method']) === 'cash_on_delivery') {
    $codStmt = $db->prepare("
        SELECT
            expected_amount,
            received_amount,
            collection_status,
            confirmed_at
        FROM cod_collections
        WHERE order_id = :order_id
        LIMIT 1
    ");

    $codStmt->execute([
        ':order_id' => $orderId
    ]);

    $codCollection = $codStmt->fetch(PDO::FETCH_ASSOC);
}

?>


<?php include "components/header.php"; ?>

<?php include "components/sidebar.php"; ?>


<main class="container py-5">


    <div
        class="d-flex
               justify-content-between
               align-items-center
               mb-4">

        <div>

            <h2 class="fw-bold mb-1">

                <i
                    class="fa-solid
                           fa-box
                           text-primary
                           me-2"></i>

                Order #<?= htmlspecialchars($order['id']) ?>

            </h2>


            <p class="text-muted mb-0">

                Order placed on

                <?= date(
                    'd M Y, h:i A',
                    strtotime($order['created_at'])
                ) ?>

            </p>

        </div>


        <a
            href="orders.php"
            class="btn btn-outline-primary
                   rounded-pill
                   px-4">

            <i
                class="fa-solid
                       fa-arrow-left
                       me-2"></i>

            Back to Orders

        </a>

    </div>


    <div
        class="card border-0
               shadow-sm
               rounded-4
               mb-4">

        <div class="card-body p-4">

            <div
                class="row
                       align-items-center
                       g-3">
                <div class="col-md-4">

                    <small class="text-muted d-block">

                        Order Status

                    </small>


                    <?php


                    $orderStatus = strtolower(
                        trim($order['order_status'] ?? '')
                    );

                    // Show the actual shipment stage to the customer.
                    $displayOrderStatus = $orderStatus;

                    if (
                        $orderStatus === 'shipped' &&
                        $shipmentStatus === 'pending'
                    ) {
                        $displayOrderStatus = 'awaiting_pickup';
                    }

                    $orderStatusClass = 'bg-secondary';

                    if ($displayOrderStatus === 'confirmed') {
                        $orderStatusClass = 'bg-success';
                    } elseif ($displayOrderStatus === 'pending') {
                        $orderStatusClass = 'bg-warning text-dark';
                    } elseif ($displayOrderStatus === 'processing') {
                        $orderStatusClass = 'bg-info text-dark';
                    } elseif ($displayOrderStatus === 'awaiting_pickup') {
                        $orderStatusClass = 'bg-info text-dark';
                    } elseif ($displayOrderStatus === 'shipped') {
                        $orderStatusClass = 'bg-primary';
                    } elseif (
                        in_array($displayOrderStatus, ['delivered', 'completed'], true)
                    ) {
                        $orderStatusClass = 'bg-success';
                    } elseif (
                        in_array($displayOrderStatus, ['cancelled', 'failed'], true)
                    ) {
                        $orderStatusClass = 'bg-danger';
                    } elseif ($displayOrderStatus === 'returned') {
                        $orderStatusClass = 'bg-warning text-dark';
                    }

                    ?>


                    <span
                        class="badge
                               <?= $orderStatusClass ?>
                               px-3
                               py-2
                               mt-2">
                        <?= htmlspecialchars(
                            ucwords(str_replace('_', ' ', $displayOrderStatus))
                        ) ?>

                    </span>

                </div>

                <div class="col-md-4">

                    <small class="text-muted d-block">

                        Payment Status

                    </small>


                    <?php

                    $paymentStatus =
                        strtolower(
                            $order['payment_status']
                        );

                    $paymentStatusClass =
                        'bg-secondary';

                    if (
                        $paymentStatus === 'pending'
                    ) {

                        $paymentStatusClass =
                            'bg-warning text-dark';
                    } elseif (
                        $paymentStatus === 'paid'
                    ) {

                        $paymentStatusClass =
                            'bg-success';
                    } elseif (
                        $paymentStatus === 'failed'
                    ) {

                        $paymentStatusClass =
                            'bg-danger';
                    }

                    ?>


                    <span
                        class="badge
                               <?= $paymentStatusClass ?>
                               px-3
                               py-2
                               mt-2">

                        <?= htmlspecialchars(
                            ucfirst(
                                $order['payment_status']
                            )
                        ) ?>

                    </span>

                </div>
                <div class="col-md-4 text-md-end">

                    <small class="text-muted d-block">

                        Order Total

                    </small>


                    <h4
                        class="fw-bold
                               text-primary
                               mb-0
                               mt-1">

                        ₹<?= number_format(
                                $order['total_amount'],
                                2
                            ) ?>

                    </h4>

                </div>

            </div>

        </div>

    </div>

    <?php if ($shipment): ?>

        <div class="card border-0 shadow-sm rounded-4 mb-4">

            <div class="card-body p-4">

                <h4 class="fw-bold mb-4">
                    <i class="fa-solid fa-truck-fast text-primary me-2"></i>
                    Shipment Tracking
                </h4>

                <div class="row g-3 mb-4">

                    <div class="col-md-6">
                        <small class="text-muted d-block">Courier</small>
                        <strong>
                            <?= htmlspecialchars($shipment['courier_name'] ?? 'Not available') ?>
                        </strong>
                    </div>

                    <div class="col-md-6">
                        <small class="text-muted d-block">Tracking Number</small>
                        <strong>
                            <?= htmlspecialchars($shipment['tracking_number'] ?? 'Not available') ?>
                        </strong>
                    </div>

                    <?php if (!empty($packages)): ?>
                        <hr class="my-4">

                        <h6 class="fw-bold mb-3">
                            Package Details (<?= count($packages) ?>)
                        </h6>

                        <?php foreach ($packages as $package): ?>
                            <?php
                            $packageStatus = ucwords(
                                str_replace(
                                    '_',
                                    ' ',
                                    $package['package_status']
                                )
                            );
                            ?>

                            <div class="border rounded p-3 mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong>
                                        Package #<?= (int) $package['package_number'] ?>
                                    </strong>

                                    <span class="badge bg-info text-dark">
                                        <?= htmlspecialchars($packageStatus) ?>
                                    </span>
                                </div>

                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <small class="text-muted">Dimensions</small>
                                        <div>
                                            <?= htmlspecialchars($package['length']) ?> ×
                                            <?= htmlspecialchars($package['width']) ?> ×
                                            <?= htmlspecialchars($package['height']) ?> cm
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <small class="text-muted">Total Weight</small>
                                        <div>
                                            <?= number_format((float) $package['total_weight']) ?> g
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="col-md-6">
                        <small class="text-muted d-block">Dispatch Date</small>
                        <strong>
                            <?= !empty($shipment['shipped_at'])
                                ? date('d M Y, h:i A', strtotime($shipment['shipped_at']))
                                : 'Not dispatched yet' ?>
                        </strong>
                    </div>

                    <div class="col-md-6">
                        <small class="text-muted d-block">Estimated Delivery</small>
                        <strong>
                            <?= !empty($shipment['estimated_delivery_at'])
                                ? date('d M Y, h:i A', strtotime($shipment['estimated_delivery_at']))
                                : 'Not available' ?>
                        </strong>
                    </div>

                </div>

                <?php if (!empty($shipment['tracking_url'])): ?>

                    <a
                        href="<?= htmlspecialchars($shipment['tracking_url']) ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="btn btn-primary rounded-pill px-4">

                        <i class="fa-solid fa-location-crosshairs me-2"></i>
                        Track Your Package

                    </a>

                <?php endif; ?>

                <hr class="my-4">

                <h5 class="fw-bold mb-4">
                    Tracking History
                </h5>


                <?php


                $currentOrderStatus = strtolower(
                    trim($order['order_status'] ?? '')
                );

                $currentShipmentStatus = strtolower(
                    trim($shipment['shipment_status'] ?? 'pending')
                );

                $exceptionalStatuses = [
                    'cancelled',
                    'failed',
                    'returned'
                ];

                $progressSteps = [
                    [
                        'status' => 'processing',
                        'title' => 'Processing',
                        'description' => 'Your order is being prepared.'
                    ],
                    [
                        'status' => 'pending',
                        'title' => 'Awaiting Pickup',
                        'description' => 'Your shipment has been created. The courier is yet to collect your package.'
                    ],
                    [
                        'status' => 'shipped',
                        'title' => 'Shipped',
                        'description' => 'Your package has been collected by the courier.'
                    ],
                    [
                        'status' => 'out_for_delivery',
                        'title' => 'Out for Delivery',
                        'description' => 'Your parcel is on its way to you.'
                    ],
                    [
                        'status' => 'delivered',
                        'title' => 'Delivered',
                        'description' => 'Your order has been delivered successfully.'
                    ]
                ];

                $shipmentStepMap = [
                    'pending' => 1,
                    'shipped' => 2,
                    'out_for_delivery' => 3,
                    'delivered' => 4
                ];

                $currentStep = $shipmentStepMap[$currentShipmentStatus] ?? 1;


                ?>

                <h5 class="fw-bold mb-4">
                    Shipment Progress
                </h5>

                <?php if (in_array($currentOrderStatus, $exceptionalStatuses, true)): ?>

                    <div class="alert alert-warning mb-0 rounded-3">

                        <h6 class="fw-bold mb-1">
                            <i class="fa-solid fa-circle-exclamation me-2"></i>

                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $currentOrderStatus))) ?>
                        </h6>

                        <p class="mb-0">
                            Your order is currently <?= htmlspecialchars($currentOrderStatus) ?>.
                            Please contact customer support if you need assistance.
                        </p>

                    </div>

                <?php else: ?>

                    <style>
                        .shipment-progress {
                            position: relative;
                            padding-left: 5px;
                        }

                        .shipment-step {
                            position: relative;
                            display: flex;
                            gap: 18px;
                            min-height: 85px;
                        }

                        .shipment-step:last-child {
                            min-height: auto;
                        }

                        .shipment-step-marker {
                            position: relative;
                            z-index: 2;
                            width: 30px;
                            height: 30px;
                            min-width: 30px;
                            border-radius: 50%;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            background: #e9ecef;
                            color: #6c757d;
                            font-size: 13px;
                        }

                        .shipment-step:not(:last-child)::after {
                            content: "";
                            position: absolute;
                            left: 14px;
                            top: 30px;
                            height: 55px;
                            width: 2px;
                            background: #dee2e6;
                        }

                        .shipment-step.completed .shipment-step-marker {
                            background: #198754;
                            color: #fff;
                        }

                        .shipment-step.completed:not(:last-child)::after {
                            background: #198754;
                        }

                        .shipment-step.active .shipment-step-marker {
                            background: #0d6efd;
                            color: #fff;
                            box-shadow: 0 0 0 5px rgba(13, 110, 253, 0.12);
                        }

                        .shipment-step.active .shipment-step-title {
                            color: #0d6efd;
                        }

                        .shipment-step.upcoming .shipment-step-title {
                            color: #6c757d;
                        }

                        .shipment-step-content {
                            padding-bottom: 20px;
                        }

                        .shipment-step-title {
                            font-weight: 600;
                            margin-bottom: 4px;
                        }

                        .shipment-step-description {
                            font-size: 13px;
                            color: #6c757d;
                            margin-bottom: 4px;
                        }

                        @media (max-width: 576px) {
                            .shipment-step {
                                gap: 13px;
                            }
                        }
                    </style>

                    <div class="shipment-progress">

                        <?php foreach ($progressSteps as $index => $step): ?>

                            <?php

                            if (in_array($currentOrderStatus, ['delivered', 'completed'], true)) {

                                $stepClass = 'completed';
                            } elseif ($index < $currentStep) {

                                $stepClass = 'completed';
                            } elseif ($index === $currentStep) {

                                $stepClass = 'active';
                            } else {

                                $stepClass = 'upcoming';
                            }

                            ?>

                            <div class="shipment-step <?= $stepClass ?>">

                                <div class="shipment-step-marker">

                                    <?php if ($stepClass === 'completed'): ?>

                                        <i class="fa-solid fa-check"></i>

                                    <?php elseif ($stepClass === 'active'): ?>

                                        <i class="fa-solid fa-location-dot"></i>

                                    <?php else: ?>

                                        <i class="fa-regular fa-circle"></i>

                                    <?php endif; ?>

                                </div>

                                <div class="shipment-step-content flex-grow-1">

                                    <div class="d-flex flex-wrap align-items-center gap-2">

                                        <h6 class="shipment-step-title mb-1">
                                            <?= htmlspecialchars($step['title']) ?>
                                        </h6>

                                        <?php if ($stepClass === 'completed'): ?>

                                            <span class="badge bg-success">
                                                Completed
                                            </span>

                                        <?php elseif ($stepClass === 'active'): ?>

                                            <span class="badge bg-primary">
                                                Current Stage
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                    <p class="shipment-step-description">
                                        <?= htmlspecialchars($step['description']) ?>
                                    </p>

                                </div>

                            </div>

                        <?php endforeach; ?>


                        <hr class="my-4">

                        <h5 class="fw-bold mb-4">
                            <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>
                            Complete Tracking History
                        </h5>

                        <?php if (!empty($trackingUpdates)): ?>

                            <div class="list-group list-group-flush">

                                <?php foreach ($trackingUpdates as $update): ?>

                                    <div class="list-group-item px-0 py-3 border-bottom">

                                        <div class="d-flex gap-3">

                                            <div class="text-primary pt-1">
                                                <i class="fa-solid fa-circle-check"></i>
                                            </div>

                                            <div class="flex-grow-1">

                                                <div class="d-flex flex-wrap justify-content-between gap-2">

                                                    <strong>
                                                        <?= htmlspecialchars(
                                                            $update['title'] ?: 'Shipment Update'
                                                        ) ?>
                                                    </strong>

                                                    <small class="text-muted">
                                                        <?= date(
                                                            'd M Y, h:i A',
                                                            strtotime($update['event_date'])
                                                        ) ?>
                                                    </small>

                                                </div>

                                                <?php if (!empty($update['description'])): ?>

                                                    <p class="text-muted mb-1 mt-2">
                                                        <?= nl2br(htmlspecialchars(
                                                            $update['description']
                                                        )) ?>
                                                    </p>

                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php else: ?>

                            <p class="text-muted mb-0">
                                No tracking updates are available yet.
                            </p>

                        <?php endif; ?>


                    </div>

                <?php endif; ?>


            </div>

        </div>

    <?php endif; ?>


    <div class="row g-4">


        <div class="col-lg-8">

            <div
                class="card border-0
                       shadow-sm
                       rounded-4">

                <div class="card-body p-4">

                    <h4 class="fw-bold mb-4">

                        <i
                            class="fa-solid
                                   fa-cart-shopping
                                   text-primary
                                   me-2"></i>

                        Order Items

                    </h4>


                    <?php foreach ($orderItems as $item): ?>

                        <div
                            class="d-flex
                                   align-items-center
                                   border-bottom
                                   pb-4
                                   mb-4">

                            <img
                                src="<?= htmlspecialchars(
                                            $item['product_image']
                                        ) ?>"
                                alt="<?= htmlspecialchars(
                                            $item['product_name']
                                        ) ?>"
                                style="
                                    width:100px;
                                    height:100px;
                                    object-fit:cover;
                                    border-radius:12px;
                                ">

                            <div
                                class="ms-3
                                       flex-grow-1">

                                <h5 class="fw-bold mb-2">

                                    <?= htmlspecialchars(
                                        $item['product_name']
                                    ) ?>

                                </h5>


                                <p
                                    class="text-muted
                                           mb-1">

                                    Price:

                                    ₹<?= number_format(
                                            $item['price'],
                                            2
                                        ) ?>

                                </p>


                                <p
                                    class="text-muted
                                           mb-0">

                                    Quantity:

                                    <?= (int)$item['quantity'] ?>

                                </p>

                            </div>

                            <div class="text-end">

                                <small
                                    class="text-muted
                                           d-block">

                                    Subtotal

                                </small>


                                <strong
                                    class="fs-5">

                                    ₹<?= number_format(
                                            $item['subtotal'],
                                            2
                                        ) ?>

                                </strong>

                            </div>

                        </div>

                    <?php endforeach; ?>
                    <div
                        class="d-flex
                               justify-content-between
                               align-items-center">

                        <h5 class="fw-bold mb-0">

                            Total

                        </h5>


                        <h4
                            class="fw-bold
                                   text-primary
                                   mb-0">

                            ₹<?= number_format(
                                    $order['total_amount'],
                                    2
                                ) ?>

                        </h4>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-lg-4">

            <div
                class="card border-0
                       shadow-sm
                       rounded-4
                       mb-4">

                <div class="card-body p-4">

                    <h5 class="fw-bold mb-4">

                        <i
                            class="fa-solid
                                   fa-location-dot
                                   text-primary
                                   me-2"></i>

                        Delivery Address

                    </h5>


                    <h6 class="fw-bold">

                        <?= htmlspecialchars(
                            $order['shipping_name']
                        ) ?>

                    </h6>


                    <p class="text-muted mb-2">

                        <i
                            class="fa-solid
                                   fa-phone
                                   me-2"></i>

                        <?= htmlspecialchars(
                            $order['shipping_phone']
                        ) ?>

                    </p>


                    <p class="text-muted mb-0">

                        <?= nl2br(
                            htmlspecialchars(
                                $order['shipping_address']
                            )
                        ) ?>

                        <br>

                        <?= htmlspecialchars(
                            $order['shipping_city']
                        ) ?>,

                        <?= htmlspecialchars(
                            $order['shipping_state']
                        ) ?>

                        -

                        <?= htmlspecialchars(
                            $order['shipping_pincode']
                        ) ?>

                    </p>

                </div>

            </div>


            <div
                class="card border-0
                       shadow-sm
                       rounded-4">

                <div class="card-body p-4">

                    <h5 class="fw-bold mb-4">

                        <i
                            class="fa-solid
                                   fa-credit-card
                                   text-primary
                                   me-2"></i>

                        Payment

                    </h5>


                    <div
                        class="d-flex
                               justify-content-between
                               mb-3">

                        <span class="text-muted">

                            Method

                        </span>


                        <strong>

                            <?php if ($order['payment_method'] === 'paypal'): ?>

                                PayPal
                            <?php elseif ($order['payment_method'] === 'stripe'): ?>

                                Stripe
                            <?php elseif ($order['payment_method'] === 'razorpay'): ?>

                                Razorpay

                            <?php else: ?>

                                Cash on Delivery

                            <?php endif; ?>

                        </strong>

                    </div>

                    <?php if (
                        strtolower($order['payment_method']) === 'cash_on_delivery' &&
                        !empty($codCollection)
                    ): ?>

                        <hr>

                        <h6 class="fw-bold mb-3">Cash on Delivery Details</h6>

                        <div class="mb-2">
                            <small class="text-muted">Expected COD Amount</small>
                            <div class="fw-semibold">
                                ₹<?= number_format(
                                        (float) $codCollection['expected_amount'],
                                        2
                                    ) ?>
                            </div>
                        </div>

                        <div class="mb-2">
                            <small class="text-muted">Collection Status</small>
                            <div>
                                <?php
                                $codStatus = $codCollection['collection_status'];

                                $codBadgeClass = match ($codStatus) {
                                    'collected' => 'bg-success',
                                    'failed' => 'bg-danger',
                                    default => 'bg-warning text-dark'
                                };
                                ?>

                                <span class="badge <?= $codBadgeClass ?>">
                                    <?= htmlspecialchars(ucwords($codStatus)) ?>
                                </span>
                            </div>
                        </div>

                        <?php if ($codStatus === 'collected'): ?>
                            <div class="mb-2">
                                <small class="text-muted">Amount Received</small>
                                <div class="fw-semibold">
                                    ₹<?= number_format(
                                            (float) $codCollection['received_amount'],
                                            2
                                        ) ?>
                                </div>
                            </div>

                            <?php if (!empty($codCollection['confirmed_at'])): ?>
                                <div>
                                    <small class="text-muted">Collection Confirmed</small>
                                    <div>
                                        <?= date(
                                            'd M Y, h:i A',
                                            strtotime($codCollection['confirmed_at'])
                                        ) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                    <?php endif; ?>
                    <div
                        class="d-flex
                               justify-content-between">

                        <span class="text-muted">

                            Status

                        </span>


                        <span
                            class="badge
                                   <?= $paymentStatusClass ?>
                                   px-3
                                   py-2">

                            <?= htmlspecialchars(
                                ucfirst(
                                    $order['payment_status']
                                )
                            ) ?>

                        </span>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <div class="text-center mt-5">

        <a
            href="index.php"
            class="btn btn-primary
                   rounded-pill
                   px-4">

            <i
                class="fa-solid
                       fa-bag-shopping
                       me-2"></i>

            Continue Shopping

        </a>

    </div>

</main>


<?php include "components/footer.php"; ?>