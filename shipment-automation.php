<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Access denied. Run this script through PHP CLI.');
}

set_time_limit(0);

$logDirectory = __DIR__ . '/logs';

if (!is_dir($logDirectory)) {
    mkdir($logDirectory, 0755, true);
}

$logFile = $logDirectory . '/shipment-automation.log';

ini_set('log_errors', '1');
ini_set('error_log', $logFile);

$lockFile = $logDirectory . '/shipment-automation.lock';
$lockHandle = fopen($lockFile, 'c');

if ($lockHandle === false) {
    exit("Unable to open shipment automation lock file." . PHP_EOL);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fclose($lockHandle);
    exit("Another shipment automation process is already running. Skipping." . PHP_EOL);
}

register_shutdown_function(function () use ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

require_once __DIR__ . '/connection/dbconnect.php';

$database = new Database();
$db = $database->connect();

$pendingToShippedSeconds = 60;
$shippedToOutForDeliverySeconds = 120;
$outForDeliveryToDeliveredSeconds = 120;

function shipmentLog($message)
{
    global $logFile;

    $logMessage = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;

    file_put_contents(
        $logFile,
        $logMessage,
        FILE_APPEND | LOCK_EX
    );

    echo $logMessage;
}

function isShipmentEligible($order)
{
    $status = $order['order_status'];
    $blockedStatuses = [
        'cancelled',
        'returned',
        'failed',
        'completed',
        'delivered'
    ];

    if (in_array($status, $blockedStatuses, true)) {
        return false;
    }
    if ($order['payment_method'] === 'cash_on_delivery') {
        return in_array(
            $status,
            ['confirmed', 'processing', 'packed', 'shipped', 'out_for_delivery'],
            true
        );
    }
    if (in_array(
        $order['payment_method'],
        ['stripe', 'paypal', 'razorpay'],
        true
    )) {
        return (
            $order['payment_status'] === 'paid' &&
            in_array(
                $status,
                ['confirmed', 'processing', 'packed', 'shipped', 'out_for_delivery'],
                true
            )
        );
    }

    return false;
}

function addShipmentTrackingEvent(
    $db,
    $orderId,
    $shipmentId,
    $previousStatus,
    $newStatus,
    $title,
    $description
) {
    $stmt = $db->prepare("
        INSERT INTO order_tracking_updates
        (
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
        VALUES
        (
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

    $stmt->execute([
        ':order_id' => $orderId,
        ':shipment_id' => $shipmentId,
        ':previous_status' => $previousStatus,
        ':new_status' => $newStatus,
        ':title' => $title,
        ':description' => $description
    ]);
}

shipmentLog('Shipment automation started.');
shipmentLog('Checking orders for shipment creation...');

$stmt = $db->query("
    SELECT id
    FROM orders
    WHERE order_status = 'confirmed'
      AND (
            (
                payment_method = 'cash_on_delivery'
            )
            OR
            (
                payment_method IN ('stripe', 'paypal', 'razorpay')
                AND payment_status = 'paid'
            )
      )
      AND NOT EXISTS (
          SELECT 1
          FROM order_shipments s
          WHERE s.order_id = orders.id
      )
    ORDER BY created_at ASC
    LIMIT 100
");

$eligibleOrderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($eligibleOrderIds as $orderId) {

    try {

        $db->beginTransaction();
        $stmt = $db->prepare("
            SELECT
                id,
                payment_method,
                payment_status,
                order_status
            FROM orders
            WHERE id = :order_id
            FOR UPDATE
        ");

        $stmt->execute([
            ':order_id' => $orderId
        ]);

        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order || $order['order_status'] !== 'confirmed') {
            $db->rollBack();
            continue;
        }

        if (!isShipmentEligible($order)) {
            $db->rollBack();
            continue;
        }
        $stmt = $db->prepare("
            SELECT id
            FROM order_shipments
            WHERE order_id = :order_id
            LIMIT 1
        ");

        $stmt->execute([
            ':order_id' => $orderId
        ]);

        if ($stmt->fetch()) {
            $db->rollBack();
            continue;
        }

        $trackingNumber = 'DEMO' . strtoupper(bin2hex(random_bytes(5)));

        $estimatedDelivery = date(
            'Y-m-d H:i:s',
            time() + 300
        );

        $stmt = $db->prepare("
            INSERT INTO order_shipments
            (
                order_id,
                courier_name,
                tracking_number,
                tracking_url,
                estimated_delivery_at,
                shipment_status
            )
            VALUES
            (
                :order_id,
                'Demo Express',
                :tracking_number,
                NULL,
                :estimated_delivery_at,
                'pending'
            )
        ");

        $stmt->execute([
            ':order_id' => $orderId,
            ':tracking_number' => $trackingNumber,
            ':estimated_delivery_at' => $estimatedDelivery
        ]);

        $shipmentId = (int) $db->lastInsertId();

        addShipmentTrackingEvent(
            $db,
            $orderId,
            $shipmentId,
            null,
            'pending',
            'Shipment created',
            'Your order has been assigned to Demo Express and is being prepared for shipment.'
        );

        $db->commit();

        shipmentLog(
            "Shipment created for Order #{$orderId}. Tracking: {$trackingNumber}"
        );
    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        shipmentLog(
            "Shipment creation failed for Order #{$orderId}: " . $e->getMessage()
        );
    }
}

shipmentLog('Checking existing shipments for status updates...');

$stmt = $db->query("
    SELECT id, order_id
    FROM order_shipments
    WHERE shipment_status IN (
        'pending',
        'shipped',
        'out_for_delivery'
    )
    ORDER BY id ASC
    LIMIT 200
");

$shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($shipments as $shipmentRow) {

    $shipmentId = (int) $shipmentRow['id'];
    $orderId = (int) $shipmentRow['order_id'];

    try {

        $db->beginTransaction();

        // Lock order first, then shipment.
        $stmt = $db->prepare("
            SELECT
                id,
                payment_method,
                payment_status,
                order_status
            FROM orders
            WHERE id = :order_id
            FOR UPDATE
        ");

        $stmt->execute([
            ':order_id' => $orderId
        ]);

        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order || !isShipmentEligible($order)) {
            $db->rollBack();
            continue;
        }

        $stmt = $db->prepare("
            SELECT *
            FROM order_shipments
            WHERE id = :shipment_id
              AND order_id = :order_id
            FOR UPDATE
        ");

        $stmt->execute([
            ':shipment_id' => $shipmentId,
            ':order_id' => $orderId
        ]);

        $shipment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$shipment) {
            $db->rollBack();
            continue;
        }

        $currentStatus = $shipment['shipment_status'];

        $nextStatus = null;
        $title = '';
        $description = '';
        $requiredSeconds = 0;

        if ($currentStatus === 'pending') {

            $nextStatus = 'shipped';
            $requiredSeconds = $pendingToShippedSeconds;

            $title = 'Order shipped';
            $description = 'Your order has been shipped by Demo Express.';
        } elseif ($currentStatus === 'shipped') {

            $nextStatus = 'out_for_delivery';
            $requiredSeconds = $shippedToOutForDeliverySeconds;

            $title = 'Out for delivery';
            $description = 'Your order is out for delivery and will arrive soon.';
        } elseif ($currentStatus === 'out_for_delivery') {

            $nextStatus = 'delivered';
            $requiredSeconds = $outForDeliveryToDeliveredSeconds;

            $title = 'Order delivered';
            $description = 'Your order has been delivered successfully.';
        }

        if ($nextStatus === null) {
            $db->rollBack();
            continue;
        }
        $stmt = $db->prepare("
    SELECT TIMESTAMPDIFF(
        SECOND,
        event_date,
        NOW()
    ) AS elapsed_seconds
    FROM order_tracking_updates
    WHERE shipment_id = :shipment_id
      AND event_type = 'shipment_update'
      AND new_status = :current_status
    ORDER BY id DESC
    LIMIT 1
");

        $stmt->execute([
            ':shipment_id' => $shipmentId,
            ':current_status' => $currentStatus
        ]);

        $elapsedSeconds = $stmt->fetchColumn();

        if (
            $elapsedSeconds === false ||
            (int) $elapsedSeconds < $requiredSeconds
        ) {
            $db->rollBack();
            continue;
        }
        if ($nextStatus === 'shipped') {

            $stmt = $db->prepare("
                UPDATE order_shipments
                SET
                    shipment_status = 'shipped',
                    shipped_at = NOW()
                WHERE id = :shipment_id
            ");
        } elseif ($nextStatus === 'out_for_delivery') {

            $stmt = $db->prepare("
                UPDATE order_shipments
                SET shipment_status = 'out_for_delivery'
                WHERE id = :shipment_id
            ");
        } else {

            $stmt = $db->prepare("
                UPDATE order_shipments
                SET
                    shipment_status = 'delivered',
                    delivered_at = NOW()
                WHERE id = :shipment_id
            ");
        }

        $stmt->execute([
            ':shipment_id' => $shipmentId
        ]);
        $stmt = $db->prepare("
            UPDATE orders
            SET order_status = :new_status
            WHERE id = :order_id
        ");

        $stmt->execute([
            ':new_status' => $nextStatus,
            ':order_id' => $orderId
        ]);

        addShipmentTrackingEvent(
            $db,
            $orderId,
            $shipmentId,
            $currentStatus,
            $nextStatus,
            $title,
            $description
        );

        $db->commit();

        shipmentLog(
            "Order #{$orderId}: {$currentStatus} -> {$nextStatus}"
        );
    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        shipmentLog(
            "Shipment update failed for Order #{$orderId}: " . $e->getMessage()
        );
    }
}

shipmentLog('Shipment automation completed.');
