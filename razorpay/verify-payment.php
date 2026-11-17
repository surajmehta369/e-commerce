
<?php

session_start();

require_once __DIR__ . '/../config/razorpay.php';
require_once __DIR__ . '/../connection/dbconnect.php';

header('Content-Type: application/json');

$db = null;

try {

    if (
        !isset($_SESSION['user_id']) ||
        ($_SESSION['logged_in'] ?? false) !== true
    ) {
        throw new Exception('User is not logged in.');
    }
    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        throw new Exception('Invalid request data.');
    }

    $razorpayPaymentId = trim(
        $input['razorpay_payment_id'] ?? ''
    );

    $razorpayOrderId = trim(
        $input['razorpay_order_id'] ?? ''
    );

    $razorpaySignature = trim(
        $input['razorpay_signature'] ?? ''
    );

    if (
        $razorpayPaymentId === '' ||
        $razorpayOrderId === '' ||
        $razorpaySignature === ''
    ) {
        throw new Exception(
            'Incomplete Razorpay payment information.'
        );
    }
    $generatedSignature = hash_hmac(
        'sha256',
        $razorpayOrderId . '|' . $razorpayPaymentId,
        RAZORPAY_KEY_SECRET
    );

    if (!hash_equals(
        $generatedSignature,
        $razorpaySignature
    )) {
        throw new Exception(
            'Razorpay signature verification failed.'
        );
    }
    $ch = curl_init(
        RAZORPAY_BASE_URL .
        '/payments/' .
        rawurlencode($razorpayPaymentId)
    );

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_USERPWD =>
            RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $paymentResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($paymentResponse === false) {
        throw new Exception(
            'Unable to verify payment with Razorpay: ' . $curlError
        );
    }

    $payment = json_decode($paymentResponse, true);

    if (
        $httpCode < 200 ||
        $httpCode >= 300 ||
        !is_array($payment)
    ) {
        throw new Exception(
            'Unable to retrieve Razorpay payment details.'
        );
    }
    if (
        ($payment['status'] ?? '') !== 'captured' ||
        ($payment['currency'] ?? '') !== 'INR' ||
        (string) ($payment['order_id'] ?? '') !== $razorpayOrderId ||
        (string) ($payment['id'] ?? '') !== $razorpayPaymentId
    ) {
        throw new Exception(
            'Razorpay payment details could not be verified.'
        );
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
            razorpay_order_id,
            razorpay_payment_id
        FROM orders
        WHERE razorpay_order_id = :razorpay_order_id
          AND user_id = :user_id
          AND payment_method = 'razorpay'
        LIMIT 1
        FOR UPDATE
    ");

    $orderStmt->execute([
        'razorpay_order_id' => $razorpayOrderId,
        'user_id' => (int) $_SESSION['user_id']
    ]);

    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Local order not found.');
    }

    $localOrderId = (int) $order['id'];
    $expectedAmount = (int) round(
        (float) $order['total_amount'] * 100
    );

    $paidAmount = (int) ($payment['amount'] ?? -1);

    if ($paidAmount !== $expectedAmount) {
        throw new Exception(
            'Payment amount does not match the order total.'
        );
    }
    if ($order['payment_status'] === 'paid') {

        if (
            $order['razorpay_payment_id'] !== $razorpayPaymentId
        ) {
            throw new Exception(
                'This order was already paid using another payment.'
            );
        }

        $db->commit();

        $_SESSION['last_order_id'] = $localOrderId;

        echo json_encode([
            'success' => true,
            'message' => 'Payment was already verified.',
            'local_order_id' => $localOrderId
        ]);

        exit;
    }
    if (
        $order['payment_status'] !== 'pending' ||
        $order['order_status'] !== 'pending'
    ) {
        throw new Exception(
            'This order is no longer eligible for payment confirmation.'
        );
    }
    $itemsStmt = $db->prepare("
        SELECT
            product_id,
            variant_id,
            quantity,
            product_name
        FROM order_items
        WHERE order_id = :order_id
    ");

    $itemsStmt->execute([
        'order_id' => $localOrderId
    ]);

    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        throw new Exception('Order items were not found.');
    }
    foreach ($items as $item) {

        $productId = (int) $item['product_id'];
        $variantId = (int) ($item['variant_id'] ?? 0);
        $quantity = (int) $item['quantity'];

        if (
            $productId <= 0 ||
            $quantity <= 0
        ) {
            throw new Exception('Invalid order item.');
        }

        if ($variantId > 0) {
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
                    'Insufficient variant stock for: ' .
                    $item['product_name']
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
            $stockStmt = $db->prepare("
                UPDATE products
                SET stock = stock - :quantity
                WHERE id = :product_id
                  AND status = 1
                  AND stock >= :quantity
            ");

            $stockStmt->execute([
                'quantity' => $quantity,
                'product_id' => $productId
            ]);

            if ($stockStmt->rowCount() !== 1) {
                throw new Exception(
                    'Insufficient product stock for: ' .
                    $item['product_name']
                );
            }
        }
    }
    $updateStmt = $db->prepare("
        UPDATE orders
        SET
            payment_status = 'paid',
            order_status = 'confirmed',
            razorpay_payment_id = :payment_id,
            razorpay_signature = :signature
        WHERE id = :order_id
          AND user_id = :user_id
          AND payment_status = 'pending'
          AND order_status = 'pending'
    ");

    $updateStmt->execute([
        'payment_id' => $razorpayPaymentId,
        'signature' => $razorpaySignature,
        'order_id' => $localOrderId,
        'user_id' => (int) $_SESSION['user_id']
    ]);

    if ($updateStmt->rowCount() !== 1) {
        throw new Exception(
            'Unable to confirm the order.'
        );
    }
    $db->commit();

    $_SESSION['last_order_id'] = $localOrderId;

    unset(
        $_SESSION['razorpay_order_id'],
        $_SESSION['razorpay_order_id_local']
    );

    echo json_encode([
        'success' => true,
        'message' => 'Payment verified successfully.',
        'local_order_id' => $localOrderId,
        'razorpay_order_id' => $razorpayOrderId,
        'razorpay_payment_id' => $razorpayPaymentId
    ]);

} catch (Exception $e) {

    if (
        $db instanceof PDO &&
        $db->inTransaction()
    ) {
        $db->rollBack();
    }

    error_log(
        'Razorpay verification error: ' . $e->getMessage()
    );

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
