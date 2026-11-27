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

        // Lock and fetch the order.
        $currentStmt = $db->prepare("
            SELECT
                order_status,
                payment_method,
                payment_status
            FROM orders
            WHERE id = :order_id
            FOR UPDATE
        ");

        $currentStmt->execute([
            ':order_id' => $orderId
        ]);

        $orderData = $currentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$orderData) {
            throw new Exception('Order not found.');
        }

        $currentStatus = strtolower(
            $orderData['order_status']
        );

        $paymentMethod = strtolower(
            trim($orderData['payment_method'] ?? '')
        );

        $paymentStatus = strtolower(
            trim($orderData['payment_status'] ?? '')
        );

        if ($currentStatus === $newStatus) {
            $db->rollBack();

            header("Location: order-details.php?id=" . $orderId . "&status_error=same");
            exit;
        }

        // Lock existing shipment, if any.
        $shipmentStmt = $db->prepare("
            SELECT id
            FROM order_shipments
            WHERE order_id = :order_id
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");

        $shipmentStmt->execute([
            ':order_id' => $orderId
        ]);

        $existingShipmentId = $shipmentStmt->fetchColumn();

        $shipmentId = $existingShipmentId !== false
            ? (int) $existingShipmentId
            : null;

        /*
         * Automatic courier assignment when status becomes Shipped.
         */
        if ($newStatus === 'shipped') {

            // Check payment eligibility.
            if (
                $paymentMethod === 'cash_on_delivery'
            ) {
                // COD orders can be shipped before payment collection.
            } elseif (
                in_array(
                    $paymentMethod,
                    ['stripe', 'paypal', 'razorpay'],
                    true
                ) &&
                $paymentStatus !== 'paid'
            ) {
                throw new Exception(
                    'Prepaid order must be paid before shipment.'
                );
            }

            // Create automatic shipment only if one does not exist.
            if ($shipmentId === null) {

                require_once __DIR__ . '/courier/packing_functions.php';

                $assignmentResult = assignCourierAndCreateShipment(
                    $db,
                    $orderId,
                    'shipped'
                );

                if (
                    empty($assignmentResult['success'])
                ) {
                    throw new Exception(
                        $assignmentResult['message']
                            ?? 'Automatic courier assignment failed.'
                    );
                }

                $shipmentId = (int) $assignmentResult['shipment_id'];
            } else {
                // Existing manual shipment: reuse it.
                $updateStmt = $db->prepare("
                    UPDATE orders
                    SET order_status = :status
                    WHERE id = :order_id
                ");

                $updateStmt->execute([
                    ':status' => $newStatus,
                    ':order_id' => $orderId
                ]);
            }
        } else {

            // Normal status update for other statuses.
            $updateStmt = $db->prepare("
                UPDATE orders
                SET order_status = :status
                WHERE id = :order_id
            ");

            $updateStmt->execute([
                ':status' => $newStatus,
                ':order_id' => $orderId
            ]);
        }

        // Save order status history.
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

        $historyStmt->execute([
            ':order_id' => $orderId,
            ':shipment_id' => $shipmentId,
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

        error_log(
            "Order status update error: " . $e->getMessage()
        );

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
    ($_POST['action'] ?? '') === 'confirm_pickup'
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

    try {
        $db->beginTransaction();

        // Lock the order.
        $orderStmt = $db->prepare("
            SELECT id, order_status
            FROM orders
            WHERE id = :order_id
            FOR UPDATE
        ");

        $orderStmt->execute([
            ':order_id' => $orderId
        ]);

        $lockedOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$lockedOrder) {
            throw new Exception('Order not found.');
        }

        // Lock the latest shipment.
        $shipmentStmt = $db->prepare("
            SELECT *
            FROM order_shipments
            WHERE order_id = :order_id
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");

        $shipmentStmt->execute([
            ':order_id' => $orderId
        ]);

        $pickupShipment = $shipmentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$pickupShipment) {
            throw new Exception('No shipment exists for this order.');
        }

        if (!empty($pickupShipment['shipped_at'])) {
            throw new Exception('Pickup has already been confirmed.');
        }

        if (
            in_array(
                strtolower($pickupShipment['shipment_status']),
                ['out_for_delivery', 'delivered', 'cancelled'],
                true
            )
        ) {
            throw new Exception(
                'Pickup cannot be confirmed for the current shipment status.'
            );
        }

        $shipmentId = (int) $pickupShipment['id'];

        // Record the actual pickup confirmation time.
        $pickupStmt = $db->prepare("
            UPDATE order_shipments
            SET
                shipment_status = 'shipped',
                shipped_at = NOW()
            WHERE id = :shipment_id
              AND order_id = :order_id
              AND shipped_at IS NULL
        ");

        $pickupStmt->execute([
            ':shipment_id' => $shipmentId,
            ':order_id' => $orderId
        ]);

        if ($pickupStmt->rowCount() !== 1) {
            throw new Exception(
                'Pickup could not be confirmed. Please refresh and check the shipment.'
            );
        }

        // Mark every package in this shipment as picked up.
        $packageStmt = $db->prepare("
            UPDATE order_packages
            SET package_status = 'shipped'
            WHERE shipment_id = :shipment_id
        ");

        $packageStmt->execute([
            ':shipment_id' => $shipmentId
        ]);

        // Update the order status if it has not reached shipped yet.
        $currentOrderStatus = strtolower(
            $lockedOrder['order_status']
        );

        $orderRanks = [
            'confirmed' => 1,
            'processing' => 2,
            'packed' => 3,
            'shipped' => 4,
            'out_for_delivery' => 5,
            'delivered' => 6
        ];

        if (
            ($orderRanks[$currentOrderStatus] ?? 0) < 4
        ) {
            $orderUpdateStmt = $db->prepare("
                UPDATE orders
                SET order_status = 'shipped'
                WHERE id = :order_id
            ");

            $orderUpdateStmt->execute([
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
                    'shipped',
                    'Shipped',
                    'Your order has been picked up by the courier.',
                    NOW(),
                    1
                )
            ");

            $orderHistoryStmt->execute([
                ':order_id' => $orderId,
                ':shipment_id' => $shipmentId,
                ':previous_status' => $currentOrderStatus
            ]);
        }

        // Add the customer-visible pickup event.
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
                'shipped',
                'Picked Up',
                'Your package has been picked up by the courier.',
                NOW(),
                1
            )
        ");

        $trackingStmt->execute([
            ':order_id' => $orderId,
            ':shipment_id' => $shipmentId,
            ':previous_status' => $pickupShipment['shipment_status']
        ]);

        $db->commit();

        header(
            "Location: order-details.php?id="
                . $orderId
                . "&pickup_confirmed=1"
        );
        exit;
    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log(
            "Pickup confirmation error: " . $e->getMessage()
        );

        header(
            "Location: order-details.php?id="
                . $orderId
                . "&pickup_error=1"
        );
        exit;
    }
}


if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'confirm_out_for_delivery'
) {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !isset($_SESSION['orders_csrf_token']) ||
        !hash_equals($_SESSION['orders_csrf_token'], $submittedToken)
    ) {
        die('Invalid security token. Please refresh the page.');
    }

    try {
        $db->beginTransaction();

        // Lock order.
        $orderStmt = $db->prepare("
            SELECT
            id,
            order_status,
            payment_method,
            payment_status,
            total_amount
        FROM orders
            WHERE id = :order_id
            FOR UPDATE
        ");
        $orderStmt->execute([':order_id' => $orderId]);
        $lockedOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$lockedOrder) {
            throw new Exception('Order not found.');
        }

        // Lock shipment.
        $shipmentStmt = $db->prepare("
            SELECT *
            FROM order_shipments
            WHERE order_id = :order_id
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $shipmentStmt->execute([':order_id' => $orderId]);
        $shipment = $shipmentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$shipment) {
            throw new Exception('Shipment not found.');
        }

        if (
            strtolower($shipment['shipment_status']) !== 'shipped' ||
            empty($shipment['shipped_at'])
        ) {
            throw new Exception(
                'Confirm actual courier pickup before marking Out for Delivery.'
            );
        }

        if (strtolower($lockedOrder['order_status']) !== 'shipped') {
            throw new Exception(
                'The order must have Shipped status before Out for Delivery.'
            );
        }

        $shipmentId = (int) $shipment['id'];

        // Ensure this shipment has packages.
        $packageCheck = $db->prepare("
            SELECT COUNT(*)
            FROM order_packages
            WHERE shipment_id = :shipment_id
        ");
        $packageCheck->execute([':shipment_id' => $shipmentId]);

        if ((int) $packageCheck->fetchColumn() < 1) {
            throw new Exception('No packages are linked to this shipment.');
        }

        // Update shipment.
        $updateShipment = $db->prepare("
            UPDATE order_shipments
            SET shipment_status = 'out_for_delivery'
            WHERE id = :shipment_id
              AND shipment_status = 'shipped'
              AND shipped_at IS NOT NULL
        ");
        $updateShipment->execute([
            ':shipment_id' => $shipmentId
        ]);

        if ($updateShipment->rowCount() !== 1) {
            throw new Exception(
                'Shipment status could not be updated. Please refresh.'
            );
        }

        // Update all packages in this shipment.
        $updatePackages = $db->prepare("
            UPDATE order_packages
            SET package_status = 'out_for_delivery'
            WHERE shipment_id = :shipment_id
        ");
        $updatePackages->execute([
            ':shipment_id' => $shipmentId
        ]);

        // Update order status.
        $updateOrder = $db->prepare("
            UPDATE orders
            SET order_status = 'out_for_delivery'
            WHERE id = :order_id
              AND order_status = 'shipped'
        ");
        $updateOrder->execute([
            ':order_id' => $orderId
        ]);

        if ($updateOrder->rowCount() !== 1) {
            throw new Exception('Order status could not be updated.');
        }

        // Customer-visible shipment event.
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
                'shipped',
                'out_for_delivery',
                'Out for Delivery',
                'Your order is out for delivery and will arrive soon.',
                NOW(),
                1
            )
        ");
        $trackingStmt->execute([
            ':order_id' => $orderId,
            ':shipment_id' => $shipmentId
        ]);

        // Order status history.
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
                'shipped',
                'out_for_delivery',
                'Out for Delivery',
                'Your order is now out for delivery.',
                NOW(),
                1
            )
        ");
        $orderHistoryStmt->execute([
            ':order_id' => $orderId,
            ':shipment_id' => $shipmentId
        ]);

        // Create the COD collection record when applicable.
        if (
            strtolower($lockedOrder['payment_method']) === 'cash_on_delivery'
        ) {
            $expectedCodAmount = round(
                (float) $lockedOrder['total_amount'],
                2
            );

            if ($expectedCodAmount <= 0) {
                throw new Exception('Invalid expected COD amount.');
            }

            $codStmt = $db->prepare("
        INSERT INTO cod_collections (
            order_id,
            expected_amount,
            collection_status
        )
        VALUES (
            :order_id,
            :expected_amount,
            'pending'
        )
        ON DUPLICATE KEY UPDATE
            expected_amount = IF(
                collection_status = 'collected',
                expected_amount,
                VALUES(expected_amount)
            )
    ");

            $codStmt->execute([
                ':order_id' => $orderId,
                ':expected_amount' => $expectedCodAmount
            ]);
        }

        $db->commit();

        header(
            "Location: order-details.php?id=" .
                $orderId .
                "&out_for_delivery_confirmed=1"
        );
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log(
            "Out for delivery confirmation error: " . $e->getMessage()
        );

        header(
            "Location: order-details.php?id=" .
                $orderId .
                "&out_for_delivery_error=1"
        );
        exit;
    }
}



if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'confirm_cod_collection'
) {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !isset($_SESSION['orders_csrf_token']) ||
        !hash_equals($_SESSION['orders_csrf_token'], $submittedToken)
    ) {
        die('Invalid security token. Please refresh the page.');
    }

    $receivedAmountInput = trim($_POST['received_amount'] ?? '');
    $codNotes = trim($_POST['cod_notes'] ?? '');

    if (
        $receivedAmountInput === '' ||
        !is_numeric($receivedAmountInput) ||
        (float) $receivedAmountInput < 0
    ) {
        header("Location: order-details.php?id={$orderId}&cod_error=amount");
        exit;
    }

    try {
        $db->beginTransaction();

        $orderStmt = $db->prepare("
            SELECT id, payment_method, payment_status, order_status
            FROM orders
            WHERE id = :order_id
            FOR UPDATE
        ");
        $orderStmt->execute([':order_id' => $orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception('Order not found.');
        }

        if (strtolower($order['payment_method']) !== 'cash_on_delivery') {
            throw new Exception('This is not a COD order.');
        }

        if (strtolower($order['order_status']) !== 'out_for_delivery') {
            throw new Exception('Order must be Out for Delivery.');
        }

        $shipmentStmt = $db->prepare("
            SELECT id, shipment_status
            FROM order_shipments
            WHERE order_id = :order_id
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $shipmentStmt->execute([':order_id' => $orderId]);
        $shipment = $shipmentStmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$shipment ||
            strtolower($shipment['shipment_status']) !== 'out_for_delivery'
        ) {
            throw new Exception('Shipment is not Out for Delivery.');
        }

        $codStmt = $db->prepare("
            SELECT *
            FROM cod_collections
            WHERE order_id = :order_id
            FOR UPDATE
        ");
        $codStmt->execute([':order_id' => $orderId]);
        $cod = $codStmt->fetch(PDO::FETCH_ASSOC);

        if (!$cod) {
            throw new Exception('COD collection record not found.');
        }

        if ($cod['collection_status'] === 'collected') {
            throw new Exception('COD collection is already confirmed.');
        }

        $receivedCents = (int) round((float) $receivedAmountInput * 100);
        $expectedCents = (int) round((float) $cod['expected_amount'] * 100);

        if ($receivedCents !== $expectedCents) {
            throw new Exception(
                'Received amount does not match the expected COD amount.'
            );
        }

        $updateCod = $db->prepare("
            UPDATE cod_collections
            SET
                received_amount = :received_amount,
                collection_status = 'collected',
                confirmed_at = NOW(),
                notes = :notes
            WHERE order_id = :order_id
              AND collection_status <> 'collected'
        ");
        $updateCod->execute([
            ':received_amount' => $receivedCents / 100,
            ':notes' => $codNotes ?: null,
            ':order_id' => $orderId
        ]);

        if ($updateCod->rowCount() !== 1) {
            throw new Exception('COD collection could not be confirmed.');
        }

        $paymentStmt = $db->prepare("
            UPDATE orders
            SET payment_status = 'paid'
            WHERE id = :order_id
              AND payment_method = 'cash_on_delivery'
        ");
        $paymentStmt->execute([':order_id' => $orderId]);

        $db->commit();

        header(
            "Location: order-details.php?id={$orderId}&cod_collected=1"
        );
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log('COD collection error: ' . $e->getMessage());

        $errorCode = str_contains($e->getMessage(), 'does not match')
            ? 'amount'
            : 'save';

        header(
            "Location: order-details.php?id={$orderId}&cod_error={$errorCode}"
        );
        exit;
    }
}


if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'confirm_delivered'
) {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !isset($_SESSION['orders_csrf_token']) ||
        !hash_equals($_SESSION['orders_csrf_token'], $submittedToken)
    ) {
        die('Invalid security token. Please refresh the page.');
    }

    try {
        $db->beginTransaction();

        // Lock order.
        $orderStmt = $db->prepare("
            SELECT id, order_status, payment_method, payment_status
            FROM orders
            WHERE id = :order_id
            FOR UPDATE
        ");
        $orderStmt->execute([':order_id' => $orderId]);
        $lockedOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$lockedOrder) {
            throw new Exception('Order not found.');
        }

        if (strtolower($lockedOrder['order_status']) !== 'out_for_delivery') {
            throw new Exception('Order must be Out for Delivery.');
        }

        // Lock shipment.
        $shipmentStmt = $db->prepare("
            SELECT *
            FROM order_shipments
            WHERE order_id = :order_id
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $shipmentStmt->execute([':order_id' => $orderId]);
        $shipment = $shipmentStmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$shipment ||
            strtolower($shipment['shipment_status']) !== 'out_for_delivery' ||
            empty($shipment['shipped_at'])
        ) {
            throw new Exception(
                'Shipment must be picked up and Out for Delivery first.'
            );
        }

        $shipmentId = (int) $shipment['id'];

        // Every package must be Out for Delivery.
        $packageStmt = $db->prepare("
            SELECT
                COUNT(*) AS total_packages,
                SUM(
                    CASE
                        WHEN package_status = 'out_for_delivery'
                        THEN 0
                        ELSE 1
                    END
                ) AS invalid_packages
            FROM order_packages
            WHERE shipment_id = :shipment_id
        ");
        $packageStmt->execute([':shipment_id' => $shipmentId]);
        $packageCheck = $packageStmt->fetch(PDO::FETCH_ASSOC);

        if (
            (int) $packageCheck['total_packages'] < 1 ||
            (int) $packageCheck['invalid_packages'] > 0
        ) {
            throw new Exception(
                'All packages must be Out for Delivery before delivery confirmation.'
            );
        }

        // Payment validation.
        $paymentMethod = strtolower($lockedOrder['payment_method']);
        $paymentStatus = strtolower($lockedOrder['payment_status']);

        if ($paymentMethod === 'cash_on_delivery') {
            $codStmt = $db->prepare("
                SELECT collection_status, expected_amount, received_amount
                FROM cod_collections
                WHERE order_id = :order_id
                FOR UPDATE
            ");
            $codStmt->execute([':order_id' => $orderId]);
            $cod = $codStmt->fetch(PDO::FETCH_ASSOC);

            if (
                !$cod ||
                $cod['collection_status'] !== 'collected' ||
                $cod['received_amount'] === null ||
                (int) round((float) $cod['received_amount'] * 100) !==
                (int) round((float) $cod['expected_amount'] * 100) ||
                $paymentStatus !== 'paid'
            ) {
                throw new Exception(
                    'COD collection must be confirmed and payment marked paid before delivery.'
                );
            }
        } elseif ($paymentStatus !== 'paid') {
            throw new Exception(
                'Payment must be completed before confirming delivery.'
            );
        }

        // Update shipment delivery time and status.
        $updateShipment = $db->prepare("
            UPDATE order_shipments
            SET
                shipment_status = 'delivered',
                delivered_at = NOW()
            WHERE id = :shipment_id
              AND shipment_status = 'out_for_delivery'
        ");
        $updateShipment->execute([
            ':shipment_id' => $shipmentId
        ]);

        if ($updateShipment->rowCount() !== 1) {
            throw new Exception('Shipment delivery update failed.');
        }

        // Update all packages.
        $updatePackages = $db->prepare("
            UPDATE order_packages
            SET package_status = 'delivered'
            WHERE shipment_id = :shipment_id
              AND package_status = 'out_for_delivery'
        ");
        $updatePackages->execute([
            ':shipment_id' => $shipmentId
        ]);

        // Update order.
        $updateOrder = $db->prepare("
            UPDATE orders
            SET order_status = 'delivered'
            WHERE id = :order_id
              AND order_status = 'out_for_delivery'
        ");
        $updateOrder->execute([
            ':order_id' => $orderId
        ]);

        if ($updateOrder->rowCount() !== 1) {
            throw new Exception('Order delivery update failed.');
        }

        // Shipment tracking event.
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
                'out_for_delivery',
                'delivered',
                'Order Delivered',
                'Your order has been delivered successfully.',
                NOW(),
                1
            )
        ");
        $trackingStmt->execute([
            ':order_id' => $orderId,
            ':shipment_id' => $shipmentId
        ]);

        // Order status history.
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
                'out_for_delivery',
                'delivered',
                'Delivered',
                'Your order has been delivered successfully.',
                NOW(),
                1
            )
        ");
        $orderHistoryStmt->execute([
            ':order_id' => $orderId,
            ':shipment_id' => $shipmentId
        ]);

        $db->commit();

        header(
            "Location: order-details.php?id=" .
                $orderId .
                "&delivered_confirmed=1"
        );
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log('Delivery confirmation error: ' . $e->getMessage());

        header(
            "Location: order-details.php?id=" .
                $orderId .
                "&delivered_error=1"
        );
        exit;
    }
}


if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'confirm_delivery_failed'
) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $failureReason = trim($_POST['failure_reason'] ?? '');

    if (
        !isset($_SESSION['orders_csrf_token']) ||
        !hash_equals($_SESSION['orders_csrf_token'], $submittedToken)
    ) {
        die('Invalid security token. Please refresh the page.');
    }

    if ($failureReason === '' || mb_strlen($failureReason) > 500) {
        header("Location: order-details.php?id=" . $orderId . "&delivery_failed_error=1");
        exit;
    }

    try {
        $db->beginTransaction();

        // Lock order.
        $orderStmt = $db->prepare("
            SELECT id, order_status
            FROM orders
            WHERE id = :order_id
            FOR UPDATE
        ");
        $orderStmt->execute([':order_id' => $orderId]);
        $lockedOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$lockedOrder ||
            strtolower($lockedOrder['order_status']) !== 'out_for_delivery'
        ) {
            throw new Exception('Order must be Out for Delivery.');
        }

        // Lock shipment.
        $shipmentStmt = $db->prepare("
            SELECT *
            FROM order_shipments
            WHERE order_id = :order_id
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $shipmentStmt->execute([':order_id' => $orderId]);
        $shipment = $shipmentStmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$shipment ||
            strtolower($shipment['shipment_status']) !== 'out_for_delivery'
        ) {
            throw new Exception('Shipment must be Out for Delivery.');
        }

        $shipmentId = (int) $shipment['id'];

        // Verify every package is Out for Delivery.
        $packageStmt = $db->prepare("
            SELECT COUNT(*) AS total_packages,
                   SUM(CASE WHEN package_status = 'out_for_delivery'
                       THEN 0 ELSE 1 END) AS invalid_packages
            FROM order_packages
            WHERE shipment_id = :shipment_id
        ");
        $packageStmt->execute([':shipment_id' => $shipmentId]);
        $packageCheck = $packageStmt->fetch(PDO::FETCH_ASSOC);

        if (
            (int) $packageCheck['total_packages'] < 1 ||
            (int) $packageCheck['invalid_packages'] > 0
        ) {
            throw new Exception('All packages must be Out for Delivery.');
        }

        // Update shipment.
        $updateShipment = $db->prepare("
            UPDATE order_shipments
            SET shipment_status = 'delivery_failed'
            WHERE id = :shipment_id
              AND shipment_status = 'out_for_delivery'
        ");
        $updateShipment->execute([':shipment_id' => $shipmentId]);

        if ($updateShipment->rowCount() !== 1) {
            throw new Exception('Shipment update failed.');
        }

        // Update packages.
        $updatePackages = $db->prepare("
            UPDATE order_packages
            SET package_status = 'delivery_failed'
            WHERE shipment_id = :shipment_id
              AND package_status = 'out_for_delivery'
        ");
        $updatePackages->execute([':shipment_id' => $shipmentId]);

        if ($updatePackages->rowCount() !== (int) $packageCheck['total_packages']) {
            throw new Exception('Package status update failed.');
        }

        // Update order.
        $updateOrder = $db->prepare("
            UPDATE orders
            SET order_status = 'failed'
            WHERE id = :order_id
              AND order_status = 'out_for_delivery'
        ");
        $updateOrder->execute([':order_id' => $orderId]);

        if ($updateOrder->rowCount() !== 1) {
            throw new Exception('Order status update failed.');
        }

        // Customer-visible shipment history.
        $trackingStmt = $db->prepare("
            INSERT INTO order_tracking_updates (
                order_id, shipment_id, event_type,
                previous_status, new_status,
                title, description, event_date, visible_to_customer
            )
            VALUES (
                :order_id, :shipment_id, 'shipment_update',
                'out_for_delivery', 'delivery_failed',
                'Delivery Attempt Failed', :description, NOW(), 1
            )
        ");
        $trackingStmt->execute([
            ':order_id' => $orderId,
            ':shipment_id' => $shipmentId,
            ':description' => 'Delivery could not be completed. Reason: ' . $failureReason
        ]);

        // Order status history.
        $orderHistoryStmt = $db->prepare("
            INSERT INTO order_tracking_updates (
                order_id, shipment_id, event_type,
                previous_status, new_status,
                title, description, event_date, visible_to_customer
            )
            VALUES (
                :order_id, :shipment_id, 'order_status',
                'out_for_delivery', 'failed',
                'Delivery Failed', :description, NOW(), 1
            )
        ");
        $orderHistoryStmt->execute([
            ':order_id' => $orderId,
            ':shipment_id' => $shipmentId,
            ':description' => 'Delivery attempt failed. Reason: ' . $failureReason
        ]);

        $db->commit();

        header("Location: order-details.php?id=" . $orderId . "&delivery_failed_confirmed=1");
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log('Delivery failure confirmation error: ' . $e->getMessage());

        header("Location: order-details.php?id=" . $orderId . "&delivery_failed_error=1");
        exit;
    }
}


if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'confirm_rto_initiated'
) {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !isset($_SESSION['orders_csrf_token']) ||
        !hash_equals($_SESSION['orders_csrf_token'], $submittedToken)
    ) {
        die('Invalid security token. Please refresh the page.');
    }

    try {
        $db->beginTransaction();

        $orderStmt = $db->prepare("
            SELECT id, order_status
            FROM orders
            WHERE id = :order_id
            FOR UPDATE
        ");
        $orderStmt->execute([':order_id' => $orderId]);
        $lockedOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$lockedOrder ||
            strtolower($lockedOrder['order_status']) !== 'failed'
        ) {
            throw new Exception('Order must have a failed delivery attempt.');
        }

        $shipmentStmt = $db->prepare("
            SELECT *
            FROM order_shipments
            WHERE order_id = :order_id
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $shipmentStmt->execute([':order_id' => $orderId]);
        $shipment = $shipmentStmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$shipment ||
            strtolower($shipment['shipment_status']) !== 'delivery_failed'
        ) {
            throw new Exception('Shipment must be marked Delivery Failed.');
        }

        $shipmentId = (int) $shipment['id'];

        $packageStmt = $db->prepare("
            SELECT COUNT(*) AS total_packages,
                   SUM(CASE WHEN package_status = 'delivery_failed'
                       THEN 0 ELSE 1 END) AS invalid_packages
            FROM order_packages
            WHERE shipment_id = :shipment_id
        ");
        $packageStmt->execute([':shipment_id' => $shipmentId]);
        $packageCheck = $packageStmt->fetch(PDO::FETCH_ASSOC);

        if (
            (int) $packageCheck['total_packages'] < 1 ||
            (int) $packageCheck['invalid_packages'] > 0
        ) {
            throw new Exception('All packages must be marked Delivery Failed.');
        }

        $updateShipment = $db->prepare("
            UPDATE order_shipments
            SET shipment_status = 'rto_initiated'
            WHERE id = :shipment_id
              AND shipment_status = 'delivery_failed'
        ");
        $updateShipment->execute([':shipment_id' => $shipmentId]);

        if ($updateShipment->rowCount() !== 1) {
            throw new Exception('RTO shipment update failed.');
        }

        $updatePackages = $db->prepare("
            UPDATE order_packages
            SET package_status = 'rto_initiated'
            WHERE shipment_id = :shipment_id
              AND package_status = 'delivery_failed'
        ");
        $updatePackages->execute([':shipment_id' => $shipmentId]);

        if ($updatePackages->rowCount() !== (int) $packageCheck['total_packages']) {
            throw new Exception('RTO package update failed.');
        }

        $updateOrder = $db->prepare("
            UPDATE orders
            SET order_status = 'returned'
            WHERE id = :order_id
              AND order_status = 'failed'
        ");
        $updateOrder->execute([':order_id' => $orderId]);

        if ($updateOrder->rowCount() !== 1) {
            throw new Exception('Order RTO status update failed.');
        }

        $trackingStmt = $db->prepare("
            INSERT INTO order_tracking_updates (
                order_id, shipment_id, event_type,
                previous_status, new_status,
                title, description, event_date, visible_to_customer
            )
            VALUES (
                :order_id, :shipment_id, 'shipment_update',
                'delivery_failed', 'rto_initiated',
                'Return to Seller Initiated',
                'Your shipment is being returned to the seller.',
                NOW(), 1
            )
        ");
        $trackingStmt->execute([
            ':order_id' => $orderId,
            ':shipment_id' => $shipmentId
        ]);

        $orderHistoryStmt = $db->prepare("
            INSERT INTO order_tracking_updates (
                order_id, shipment_id, event_type,
                previous_status, new_status,
                title, description, event_date, visible_to_customer
            )
            VALUES (
                :order_id, :shipment_id, 'order_status',
                'failed', 'returned',
                'Return Initiated',
                'Your order is being returned to the seller.',
                NOW(), 1
            )
        ");
        $orderHistoryStmt->execute([
            ':order_id' => $orderId,
            ':shipment_id' => $shipmentId
        ]);

        $db->commit();

        header("Location: order-details.php?id=" . $orderId . "&rto_initiated_confirmed=1");
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log('RTO initiation error: ' . $e->getMessage());

        header("Location: order-details.php?id=" . $orderId . "&rto_initiated_error=1");
        exit;
    }
}


if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'confirm_rto_received'
) {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !isset($_SESSION['orders_csrf_token']) ||
        !hash_equals($_SESSION['orders_csrf_token'], $submittedToken)
    ) {
        die('Invalid security token. Please refresh the page.');
    }

    try {
        $db->beginTransaction();

        $orderStmt = $db->prepare("
            SELECT id, order_status
            FROM orders
            WHERE id = :order_id
            FOR UPDATE
        ");
        $orderStmt->execute([':order_id' => $orderId]);
        $lockedOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$lockedOrder ||
            strtolower($lockedOrder['order_status']) !== 'returned'
        ) {
            throw new Exception('Order must be marked Returned.');
        }

        $shipmentStmt = $db->prepare("
            SELECT *
            FROM order_shipments
            WHERE order_id = :order_id
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $shipmentStmt->execute([':order_id' => $orderId]);
        $shipment = $shipmentStmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$shipment ||
            strtolower($shipment['shipment_status']) !== 'rto_initiated'
        ) {
            throw new Exception('RTO must be initiated before receipt confirmation.');
        }

        $shipmentId = (int) $shipment['id'];

        $packageStmt = $db->prepare("
            SELECT COUNT(*) AS total_packages,
                   SUM(CASE WHEN package_status = 'rto_initiated'
                       THEN 0 ELSE 1 END) AS invalid_packages
            FROM order_packages
            WHERE shipment_id = :shipment_id
        ");
        $packageStmt->execute([':shipment_id' => $shipmentId]);
        $packageCheck = $packageStmt->fetch(PDO::FETCH_ASSOC);

        if (
            (int) $packageCheck['total_packages'] < 1 ||
            (int) $packageCheck['invalid_packages'] > 0
        ) {
            throw new Exception('All packages must be RTO Initiated.');
        }

        $updateShipment = $db->prepare("
            UPDATE order_shipments
            SET shipment_status = 'rto_received'
            WHERE id = :shipment_id
              AND shipment_status = 'rto_initiated'
        ");
        $updateShipment->execute([':shipment_id' => $shipmentId]);

        if ($updateShipment->rowCount() !== 1) {
            throw new Exception('RTO receipt update failed.');
        }

        $updatePackages = $db->prepare("
            UPDATE order_packages
            SET package_status = 'rto_received'
            WHERE shipment_id = :shipment_id
              AND package_status = 'rto_initiated'
        ");
        $updatePackages->execute([':shipment_id' => $shipmentId]);

        if ($updatePackages->rowCount() !== (int) $packageCheck['total_packages']) {
            throw new Exception('RTO package receipt update failed.');
        }

        $trackingStmt = $db->prepare("
            INSERT INTO order_tracking_updates (
                order_id, shipment_id, event_type,
                previous_status, new_status,
                title, description, event_date, visible_to_customer
            )
            VALUES (
                :order_id, :shipment_id, 'shipment_update',
                'rto_initiated', 'rto_received',
                'Return Received by Seller',
                'Your returned shipment has reached the seller.',
                NOW(), 1
            )
        ");
        $trackingStmt->execute([
            ':order_id' => $orderId,
            ':shipment_id' => $shipmentId
        ]);

        $orderHistoryStmt = $db->prepare("
            INSERT INTO order_tracking_updates (
                order_id, shipment_id, event_type,
                previous_status, new_status,
                title, description, event_date, visible_to_customer
            )
            VALUES (
                :order_id, :shipment_id, 'order_status',
                'returned', 'returned',
                'Return Received',
                'The returned shipment has been received by the seller.',
                NOW(), 1
            )
        ");
        $orderHistoryStmt->execute([
            ':order_id' => $orderId,
            ':shipment_id' => $shipmentId
        ]);

        $db->commit();

        header("Location: order-details.php?id=" . $orderId . "&rto_received_confirmed=1");
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log('RTO receipt confirmation error: ' . $e->getMessage());

        header("Location: order-details.php?id=" . $orderId . "&rto_received_error=1");
        exit;
    }
}


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

    if ($shipmentStatus !== 'pending') {
        header("Location: order-details.php?id=" . $orderId . "&shipment_error=invalid");
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

    $estimatedDeliveryAt = $convertShipmentDate(
        $estimatedDeliveryInput
    );

    if ($estimatedDeliveryAt === false) {
        header("Location: order-details.php?id=" . $orderId . "&shipment_error=date");
        exit;
    }

    try {

        $db->beginTransaction();

        $lockedOrderStmt = $db->prepare("
            SELECT
                id,
                payment_method,
                payment_status,
                order_status
            FROM orders
            WHERE id = :order_id
            FOR UPDATE
        ");

        $lockedOrderStmt->execute([
            ':order_id' => $orderId
        ]);

        $lockedOrder = $lockedOrderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$lockedOrder) {
            throw new Exception('Order not found while saving shipment.');
        }

        $lockedOrderStatus = strtolower($lockedOrder['order_status']);
        $lockedPaymentMethod = strtolower($lockedOrder['payment_method']);
        $lockedPaymentStatus = strtolower($lockedOrder['payment_status']);

        $blockedOrderStatuses = [
            'cancelled',
            'returned',
            'failed',
            'completed',
            'delivered'
        ];

        $shipmentEligibleStatuses = [
            'confirmed',
            'processing',
            'packed',
            'shipped',
            'out_for_delivery'
        ];

        $orderCanHaveShipment =
            !in_array($lockedOrderStatus, $blockedOrderStatuses, true) &&
            in_array($lockedOrderStatus, $shipmentEligibleStatuses, true);

        if ($lockedPaymentMethod === 'cash_on_delivery') {
            $paymentAllowsShipment = true;
        } elseif (in_array($lockedPaymentMethod, ['stripe', 'paypal', 'razorpay'], true)) {
            $paymentAllowsShipment = ($lockedPaymentStatus === 'paid');
        } else {
            $paymentAllowsShipment = false;
        }

        if (!$orderCanHaveShipment || !$paymentAllowsShipment) {
            $db->rollBack();
            header("Location: order-details.php?id=" . $orderId . "&shipment_error=not_eligible");
            exit;
        }
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

        $effectiveShippedAt = null;

        if (!empty($existingShipment['shipped_at'])) {
            $effectiveShippedAt = $existingShipment['shipped_at'];
        }

        $shipmentChanged =
            !$existingShipment ||
            strtolower($oldShipmentStatus) !== $shipmentStatus;

        $deliveredAt = $existingShipment['delivered_at'] ?? null;
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
            if (
                $existingShipment &&
                !empty($existingShipment['shipped_at'])
            ) {
                throw new Exception(
                    'Courier pickup is already confirmed. Shipment details cannot be changed through this form.'
                );
            }
            $shipmentStmt->execute([
                ':courier_name' => $courierName ?: null,
                ':tracking_number' => $trackingNumber ?: null,
                ':tracking_url' => $trackingUrl ?: null,
                ':shipped_at' => $effectiveShippedAt,
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
                ':shipped_at' => $effectiveShippedAt,
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
        oi.id,
        oi.product_id,
        oi.variant_id,
        oi.product_name,
        oi.product_image,
        oi.price,
        oi.quantity,
        oi.subtotal,
        oi.weight,
        oi.length,
        oi.width,
        oi.height,

        p.vendor_id,
        v.name AS vendor_name,

        COALESCE(vos.status, 'confirmed') AS vendor_status,
        vos.cancellation_reason,
        vos.cancellation_requested_at

    FROM order_items oi

    LEFT JOIN products p
        ON p.id = oi.product_id

    LEFT JOIN users v
        ON v.id = p.vendor_id

    LEFT JOIN vendor_order_item_statuses vos
        ON vos.order_item_id = oi.id
        AND vos.vendor_id = p.vendor_id

    WHERE oi.order_id = :order_id

    ORDER BY oi.id ASC
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
        <?php elseif ($shipmentError === 'not_eligible'): ?>
            <div class="alert alert-warning">
                This order is not eligible for shipment updates. Check its order status and payment status.
            </div>
        <?php elseif ($shipmentError === 'save'): ?>
            <div class="alert alert-danger">
                Unable to save shipment details. Please check the server error log.
            </div>
        <?php endif; ?>


        <?php if (isset($_GET['pickup_confirmed'])): ?>
            <div class="alert alert-success">
                Courier pickup confirmed successfully. Dispatch date has been recorded.
            </div>
        <?php elseif (isset($_GET['pickup_error'])): ?>
            <div class="alert alert-danger">
                Unable to confirm pickup. Please check the shipment and server error log.
            </div>
        <?php endif; ?>

        <?php
        $canConfirmPickup =
            !empty($shipment) &&
            empty($shipment['shipped_at']) &&
            !in_array(
                strtolower($shipment['shipment_status'] ?? ''),
                ['out_for_delivery', 'delivered', 'cancelled'],
                true
            );
        ?>

        <?php if ($canConfirmPickup): ?>
            <div class="border rounded p-3 mb-4">
                <h6 class="fw-bold mb-2">Courier Pickup Confirmation</h6>

                <p class="text-muted mb-3">
                    Confirm only after the courier has physically collected the packages.
                </p>

                <form method="POST"
                    onsubmit="return confirm('Confirm that the courier has physically picked up this shipment?');">

                    <input type="hidden" name="action" value="confirm_pickup">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['orders_csrf_token']) ?>">

                    <button type="submit" class="btn btn-success rounded-pill px-4">
                        <i class="bi bi-box-seam me-2"></i>
                        Confirm Pickup
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['out_for_delivery_confirmed'])): ?>
            <div class="alert alert-success">
                Shipment successfully marked Out for Delivery.
            </div>
        <?php elseif (isset($_GET['out_for_delivery_error'])): ?>
            <div class="alert alert-danger">
                Unable to update shipment. Check the server error log.
            </div>
        <?php endif; ?>

        <?php
        $canConfirmOutForDelivery =
            !empty($shipment) &&
            strtolower($shipment['shipment_status'] ?? '') === 'shipped' &&
            !empty($shipment['shipped_at']);
        ?>

        <?php if ($canConfirmOutForDelivery): ?>
            <div class="border rounded p-3 mb-4">
                <h6 class="fw-bold mb-2">
                    <i class="bi bi-truck me-2"></i>
                    Out for Delivery Confirmation
                </h6>

                <p class="text-muted mb-3">
                    Confirm when the shipment has actually gone out for delivery.
                </p>

                <form method="POST"
                    onsubmit="return confirm('Confirm that this shipment is out for delivery?');">

                    <input type="hidden"
                        name="action"
                        value="confirm_out_for_delivery">

                    <input type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['orders_csrf_token']) ?>">

                    <button type="submit" class="btn btn-primary rounded-pill px-4">
                        <i class="bi bi-truck me-2"></i>
                        Confirm Out for Delivery
                    </button>
                </form>
            </div>
        <?php endif; ?>


        <?php
        $isCodOrder =
            strtolower($order['payment_method'] ?? '') === 'cash_on_delivery';

        $codCollectionStmt = $db->prepare("
    SELECT *
    FROM cod_collections
    WHERE order_id = :order_id
    LIMIT 1
");
        $codCollectionStmt->execute([':order_id' => $orderId]);
        $codCollection = $codCollectionStmt->fetch(PDO::FETCH_ASSOC);

        $canConfirmCod =
            $isCodOrder &&
            strtolower($order['order_status'] ?? '') === 'out_for_delivery' &&
            $codCollection &&
            $codCollection['collection_status'] !== 'collected';
        ?>

        <?php if (isset($_GET['cod_collected'])): ?>
            <div class="alert alert-success">
                COD payment collection confirmed successfully.
            </div>
        <?php elseif (($_GET['cod_error'] ?? '') === 'amount'): ?>
            <div class="alert alert-warning">
                Received amount does not match the expected COD amount. Payment remains pending.
            </div>
        <?php elseif (($_GET['cod_error'] ?? '') === 'save'): ?>
            <div class="alert alert-danger">
                Unable to confirm COD collection. Check the server error log.
            </div>
        <?php endif; ?>

        <?php if ($isCodOrder && $codCollection): ?>
            <div class="border rounded p-3 mb-4">
                <h6 class="fw-bold">Cash on Delivery Collection</h6>

                <p class="mb-1">
                    Expected amount:
                    <strong>₹<?= number_format((float)$codCollection['expected_amount'], 2) ?></strong>
                </p>

                <p>
                    Collection status:
                    <strong>
                        <?= e(ucfirst($codCollection['collection_status'])) ?>
                    </strong>
                </p>

                <?php if ($canConfirmCod): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="confirm_cod_collection">
                        <input type="hidden" name="csrf_token"
                            value="<?= e($_SESSION['orders_csrf_token']) ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Actual Cash Received (₹)
                            </label>

                            <input
                                type="number"
                                name="received_amount"
                                class="form-control"
                                min="0"
                                step="0.01"
                                required
                                placeholder="Enter actual amount received">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Collection Notes (Optional)
                            </label>

                            <textarea name="cod_notes"
                                class="form-control"
                                rows="2"></textarea>
                        </div>

                        <button type="submit"
                            class="btn btn-success rounded-pill"
                            onclick="return confirm('Confirm that you have received the exact COD amount?');">
                            Confirm COD Collection
                        </button>
                    </form>
                <?php elseif ($codCollection['collection_status'] === 'collected'): ?>
                    <p class="text-success mb-0">
                        Received: ₹<?= number_format((float)$codCollection['received_amount'], 2) ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>


        <?php if (isset($_GET['delivered_confirmed'])): ?>
            <div class="alert alert-success">
                Order successfully marked as Delivered.
            </div>
        <?php elseif (isset($_GET['delivered_error'])): ?>
            <div class="alert alert-danger">
                Unable to confirm delivery. Check the server error log and payment collection status.
            </div>
        <?php endif; ?>

        <?php
        $canConfirmDelivered =
            !empty($shipment) &&
            strtolower($shipment['shipment_status'] ?? '') === 'out_for_delivery' &&
            !empty($shipment['shipped_at']);
        ?>

        <?php if ($canConfirmDelivered): ?>
            <div class="border rounded p-3 mb-4">
                <h6 class="fw-bold mb-2">
                    <i class="bi bi-check-circle me-2"></i>
                    Delivery Confirmation
                </h6>

                <p class="text-muted mb-3">
                    Confirm only after the customer has actually received the order.
                    COD orders also require confirmed cash collection.
                </p>

                <form method="POST"
                    onsubmit="return confirm('Has the customer actually received the complete order?');">

                    <input type="hidden"
                        name="action"
                        value="confirm_delivered">

                    <input type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['orders_csrf_token']) ?>">

                    <button type="submit"
                        class="btn btn-success rounded-pill px-4">
                        <i class="bi bi-check-circle me-2"></i>
                        Confirm Delivered
                    </button>
                </form>
            </div>
        <?php endif; ?>


        <?php if (isset($_GET['delivery_failed_confirmed'])): ?>
            <div class="alert alert-success">
                Delivery failure successfully recorded.
            </div>
        <?php elseif (isset($_GET['delivery_failed_error'])): ?>
            <div class="alert alert-danger">
                Unable to record delivery failure. Check the server error log.
            </div>
        <?php endif; ?>

        <?php
        $canConfirmDeliveryFailed =
            !empty($shipment) &&
            strtolower($shipment['shipment_status'] ?? '') === 'out_for_delivery' &&
            !empty($shipment['shipped_at']);
        ?>

        <?php if ($canConfirmDeliveryFailed): ?>
            <div class="border rounded p-3 mb-4">
                <h6 class="fw-bold mb-2">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Delivery Failure
                </h6>

                <p class="text-muted">
                    Confirm only after an actual unsuccessful delivery attempt.
                </p>

                <form method="POST"
                    onsubmit="return confirm('Confirm that this delivery attempt failed?');">

                    <input type="hidden" name="action"
                        value="confirm_delivery_failed">

                    <input type="hidden" name="csrf_token"
                        value="<?= e($_SESSION['orders_csrf_token']) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Reason for delivery failure
                        </label>

                        <textarea name="failure_reason"
                            class="form-control"
                            rows="3"
                            maxlength="500"
                            required
                            placeholder="Example: Customer unavailable at delivery address."></textarea>
                    </div>

                    <button type="submit"
                        class="btn btn-danger rounded-pill px-4">
                        <i class="bi bi-exclamation-circle me-2"></i>
                        Confirm Delivery Failed
                    </button>
                </form>
            </div>
        <?php endif; ?>


        <?php if (isset($_GET['rto_initiated_confirmed'])): ?>
            <div class="alert alert-success">
                Return to seller successfully initiated.
            </div>
        <?php elseif (isset($_GET['rto_initiated_error'])): ?>
            <div class="alert alert-danger">
                Unable to initiate RTO. Check the server error log.
            </div>
        <?php endif; ?>

        <?php
        $canInitiateRto =
            !empty($shipment) &&
            strtolower($shipment['shipment_status'] ?? '') === 'delivery_failed';
        ?>

        <?php if ($canInitiateRto): ?>
            <div class="border rounded p-3 mb-4">
                <h6 class="fw-bold mb-2">
                    <i class="bi bi-arrow-return-left me-2"></i>
                    Return to Seller
                </h6>

                <p class="text-muted">
                    Confirm only after the return shipment has actually been initiated.
                </p>

                <form method="POST"
                    onsubmit="return confirm('Has the shipment actually started its return journey?');">

                    <input type="hidden" name="action"
                        value="confirm_rto_initiated">

                    <input type="hidden" name="csrf_token"
                        value="<?= e($_SESSION['orders_csrf_token']) ?>">

                    <button type="submit"
                        class="btn btn-warning rounded-pill px-4">
                        <i class="bi bi-arrow-return-left me-2"></i>
                        Confirm RTO Initiated
                    </button>
                </form>
            </div>
        <?php endif; ?>


        <?php if (isset($_GET['rto_received_confirmed'])): ?>
            <div class="alert alert-success">
                Returned shipment successfully received.
            </div>
        <?php elseif (isset($_GET['rto_received_error'])): ?>
            <div class="alert alert-danger">
                Unable to confirm RTO receipt. Check the server error log.
            </div>
        <?php endif; ?>

        <?php
        $canConfirmRtoReceived =
            !empty($shipment) &&
            strtolower($shipment['shipment_status'] ?? '') === 'rto_initiated';
        ?>

        <?php if ($canConfirmRtoReceived): ?>
            <div class="border rounded p-3 mb-4">
                <h6 class="fw-bold mb-2">
                    <i class="bi bi-box-seam me-2"></i>
                    Returned Shipment Receipt
                </h6>

                <p class="text-muted">
                    Confirm only after all returned packages have physically arrived.
                </p>

                <form method="POST"
                    onsubmit="return confirm('Have all returned packages physically reached your business?');">

                    <input type="hidden" name="action"
                        value="confirm_rto_received">

                    <input type="hidden" name="csrf_token"
                        value="<?= e($_SESSION['orders_csrf_token']) ?>">

                    <button type="submit"
                        class="btn btn-success rounded-pill px-4">
                        <i class="bi bi-box-seam me-2"></i>
                        Confirm RTO Received
                    </button>
                </form>
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
                        class="form-control"
                        value="<?= !empty($shipment['shipped_at'])
                                    ? e(date('Y-m-d\TH:i', strtotime($shipment['shipped_at'])))
                                    : '' ?>"
                        readonly>

                    <small class="text-muted">
                        Recorded automatically when actual courier pickup is confirmed.
                    </small>


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


                            <?php
                            $vendorStatus = strtolower(
                                $item['vendor_status'] ?? 'confirmed'
                            );

                            $vendorStatusLabels = [
                                'confirmed' => 'Confirmed',
                                'processing' => 'Processing',
                                'packed' => 'Packed',
                                'cancellation_requested' => 'Cancellation Requested'
                            ];

                            $vendorStatusClasses = [
                                'confirmed' => 'bg-secondary',
                                'processing' => 'bg-info text-dark',
                                'packed' => 'bg-success',
                                'cancellation_requested' => 'bg-warning text-dark'
                            ];

                            $vendorStatusLabel = $vendorStatusLabels[$vendorStatus]
                                ?? ucwords(str_replace('_', ' ', $vendorStatus));

                            $vendorStatusClass = $vendorStatusClasses[$vendorStatus]
                                ?? 'bg-secondary';
                            ?>

                            <div class="mt-3">
                                <small class="text-muted d-block mb-1">
                                    <i class="bi bi-shop me-1"></i>
                                    Vendor: <strong><?= e($item['vendor_name'] ?: 'Unknown Vendor') ?></strong>
                                </small>

                                <small class="text-muted me-2">Vendor Status:</small>

                                <span class="badge <?= e($vendorStatusClass) ?>">
                                    <?= e($vendorStatusLabel) ?>
                                </span>

                                <?php if ($vendorStatus === 'cancellation_requested'): ?>

                                    <div class="alert alert-warning mt-2 mb-0">
                                        <strong>
                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                            Vendor Cancellation Request
                                        </strong>

                                        <?php if (!empty($item['cancellation_reason'])): ?>
                                            <p class="mb-1 mt-2">
                                                <strong>Reason:</strong>
                                                <?= nl2br(e($item['cancellation_reason'])) ?>
                                            </p>
                                        <?php endif; ?>

                                        <?php if (!empty($item['cancellation_requested_at'])): ?>
                                            <small class="text-muted">
                                                Requested on:
                                                <?= date(
                                                    'd M Y, h:i A',
                                                    strtotime($item['cancellation_requested_at'])
                                                ) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>

                                <?php endif; ?>
                            </div>

                            <div class="mt-2">
                                <small class="text-muted d-block">
                                    <i class="bi bi-box me-1"></i>
                                    Shipping Dimensions
                                </small>

                                <small class="text-muted">
                                    Weight:
                                    <strong>
                                        <?= number_format((float) $item['weight'], 2) ?> g
                                    </strong>
                                    |
                                    Length:
                                    <strong>
                                        <?= number_format((float) $item['length'], 2) ?> cm
                                    </strong>
                                    |
                                    Width:
                                    <strong>
                                        <?= number_format((float) $item['width'], 2) ?> cm
                                    </strong>
                                    |
                                    Height:
                                    <strong>
                                        <?= number_format((float) $item['height'], 2) ?> cm
                                    </strong>
                                </small>
                            </div>
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
