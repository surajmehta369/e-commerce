
<?php

session_start();

require_once __DIR__ . '/paypal-api.php';
require_once __DIR__ . '/../connection/dbconnect.php';

header('Content-Type: application/json');

$db = null;

try {

    if (empty($_SESSION['user_id'])) {
        throw new Exception('Please login to continue.');
    }

    $userId = (int) $_SESSION['user_id'];
    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    $paypalOrderId = trim($input['orderID'] ?? '');

    if ($paypalOrderId === '') {
        throw new Exception('PayPal Order ID is missing.');
    }

    $orderId = (int) ($_SESSION['paypal_order_id_local'] ?? 0);

    if ($orderId <= 0) {
        throw new Exception('Local order information is missing. Please try again.');
    }

    $database = new Database();
    $db = $database->connect();

    $db->beginTransaction();

    $orderStmt = $db->prepare("
        SELECT
            id,
            user_id,
            total_amount,
            payment_status,
            order_status,
            payment_method,
            paypal_order_id,
            paypal_capture_id

        FROM orders

        WHERE id = :order_id
          AND user_id = :user_id

        LIMIT 1
        FOR UPDATE
    ");

    $orderStmt->execute([
        'order_id' => $orderId,
        'user_id' => $userId
    ]);

    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found.');
    }
    if (strtolower($order['payment_method']) !== 'paypal') {
        throw new Exception('Invalid payment method for this order.');
    }

    if (
        empty($order['paypal_order_id']) ||
        !hash_equals(
            (string) $order['paypal_order_id'],
            $paypalOrderId
        )
    ) {
        throw new Exception('PayPal Order ID does not match this order.');
    }
    if ($order['payment_status'] === 'paid') {

        if (empty($order['paypal_capture_id'])) {
            throw new Exception('Order is marked paid but capture information is missing. Please contact support.');
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'This payment has already been processed.',
            'paypal_order_id' => $paypalOrderId,
            'paypal_capture_id' => $order['paypal_capture_id'],
            'local_order_id' => $orderId
        ]);

        exit;
    }
    $result = paypalRequest(
        'POST',
        '/v2/checkout/orders/'
        . urlencode($paypalOrderId)
        . '/capture'
    );

    if (
        !isset($result['status']) ||
        $result['status'] !== 'COMPLETED'
    ) {
        throw new Exception('PayPal payment was not completed.');
    }
    $capture = $result['purchase_units'][0]['payments']['captures'][0] ?? null;

    if (!$capture || empty($capture['id'])) {
        throw new Exception('PayPal capture ID was not returned.');
    }

    $captureId = $capture['id'];
    $capturedCurrency = $capture['amount']['currency_code'] ?? '';
    $capturedAmount = $capture['amount']['value'] ?? '';

    if ($capturedCurrency !== 'USD') {
        throw new Exception('PayPal payment currency does not match.');
    }
    $usdRate = 84;

    $expectedAmount = number_format(
        ((float) $order['total_amount']) / $usdRate,
        2,
        '.',
        ''
    );

    if (
        !is_numeric($capturedAmount) ||
        number_format((float) $capturedAmount, 2, '.', '') !== $expectedAmount
    ) {
        throw new Exception('PayPal payment amount does not match the order total.');
    }
    $itemsStmt = $db->prepare("
        SELECT
            product_id,
            variant_id,
            quantity

        FROM order_items

        WHERE order_id = :order_id
    ");

    $itemsStmt->execute([
        'order_id' => $orderId
    ]);

    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        throw new Exception('No products were found in this order.');
    }
    foreach ($items as $item) {

        $productId = (int) $item['product_id'];
        $quantity = (int) $item['quantity'];
        $variantId = !empty($item['variant_id'])
            ? (int) $item['variant_id']
            : null;

        if ($quantity <= 0) {
            throw new Exception('Invalid product quantity in order.');
        }
        if ($variantId !== null) {

            $stockStmt = $db->prepare("
                UPDATE product_variants

                SET stock = stock - :quantity

                WHERE id = :variant_id
                  AND product_id = :product_id
                  AND status = 1
                  AND stock >= :quantity
            ");

            $stockStmt->execute([
                'quantity' => $quantity,
                'variant_id' => $variantId,
                'product_id' => $productId
            ]);

            if ($stockStmt->rowCount() !== 1) {
                throw new Exception(
                    'Insufficient stock for one of the selected product variants.'
                );
            }
            $parentStockStmt = $db->prepare("
                UPDATE products

                SET stock = (
                    SELECT COALESCE(SUM(stock), 0)

                    FROM product_variants

                    WHERE product_id = :product_id
                )

                WHERE id = :product_id
            ");

            $parentStockStmt->execute([
                'product_id' => $productId
            ]);

        } else {
            $variantCheck = $db->prepare("
                SELECT COUNT(*)

                FROM product_variants

                WHERE product_id = :product_id
                  AND status = 1
            ");

            $variantCheck->execute([
                'product_id' => $productId
            ]);

            if ((int) $variantCheck->fetchColumn() > 0) {
                throw new Exception(
                    'A variant must be selected for one of the products in this order.'
                );
            }

            $stockStmt = $db->prepare("
                UPDATE products

                SET stock = stock - :quantity

                WHERE id = :product_id
                  AND stock >= :quantity
            ");

            $stockStmt->execute([
                'quantity' => $quantity,
                'product_id' => $productId
            ]);

            if ($stockStmt->rowCount() !== 1) {
                throw new Exception(
                    'Insufficient stock for one of the products.'
                );
            }
        }
    }
    $updateOrder = $db->prepare("
        UPDATE orders

        SET
            payment_status = 'paid',
            order_status = 'confirmed',
            paypal_capture_id = :capture_id

        WHERE id = :order_id
          AND user_id = :user_id
          AND payment_status != 'paid'

        LIMIT 1
    ");

    $updateOrder->execute([
        'capture_id' => $captureId,
        'order_id' => $orderId,
        'user_id' => $userId
    ]);

    if ($updateOrder->rowCount() !== 1) {
        throw new Exception('Unable to update the order payment status.');
    }
    $db->commit();

    $_SESSION['last_order_id'] = $orderId;

    echo json_encode([
        'success' => true,
        'message' => 'Payment captured successfully.',
        'paypal_order_id' => $paypalOrderId,
        'paypal_capture_id' => $captureId,
        'local_order_id' => $orderId
    ]);

} catch (Throwable $e) {

    if (
        $db instanceof PDO &&
        $db->inTransaction()
    ) {
        $db->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
