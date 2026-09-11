<?php

session_start();

require_once __DIR__ . '/../config/razorpay.php';
require_once __DIR__ . '/../connection/dbconnect.php';

header('Content-Type: application/json');

$db = null;

try {

    if (
        !isset($_SESSION['user_id']) ||
        $_SESSION['logged_in'] !== true
    ) {

        throw new Exception(
            'User is not logged in.'
        );
    }

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {

        throw new Exception(
            'Invalid request data.'
        );
    }

    $razorpayPaymentId =
        trim($input['razorpay_payment_id'] ?? '');

    $razorpayOrderId =
        trim($input['razorpay_order_id'] ?? '');

    $razorpaySignature =
        trim($input['razorpay_signature'] ?? '');

    if (
        $razorpayPaymentId === '' ||
        $razorpayOrderId === '' ||
        $razorpaySignature === ''
    ) {

        throw new Exception(
            'Incomplete Razorpay payment information.'
        );
    }

    $database =
        new Database();

    $db =
        $database->connect();
    $orderStmt = $db->prepare("

        SELECT

            id,
            user_id,
            total_amount,
            payment_status,
            order_status,
            razorpay_order_id

        FROM orders

        WHERE razorpay_order_id = :razorpay_order_id

          AND user_id = :user_id

          AND payment_method = 'razorpay'

        LIMIT 1

    ");

    $orderStmt->execute([

        'razorpay_order_id' =>
        $razorpayOrderId,

        'user_id' =>
        $_SESSION['user_id']

    ]);

    $order =
        $orderStmt->fetch(
            PDO::FETCH_ASSOC
        );

    if (!$order) {

        throw new Exception(
            'Order not found.'
        );
    }

    $localOrderId =
        (int) $order['id'];

    $serverRazorpayOrderId =
        $order['razorpay_order_id'];

    $generatedSignature =
        hash_hmac(
            'sha256',
            $serverRazorpayOrderId
                . '|'
                . $razorpayPaymentId,
            RAZORPAY_KEY_SECRET
        );

    if (
        !hash_equals(
            $generatedSignature,
            $razorpaySignature
        )
    ) {

        throw new Exception(
            'Razorpay payment signature verification failed.'
        );
    }

    $db->beginTransaction();

    if (
        $order['payment_status'] === 'paid'
    ) {

        $db->commit();

        $_SESSION['last_order_id'] =
            $localOrderId;

        echo json_encode([

            'success' => true,

            'message' =>
            'Payment was already verified.',

            'local_order_id' =>
            $localOrderId

        ]);

        exit;
    }

    $itemsStmt = $db->prepare("

        SELECT

            product_id,
            quantity

        FROM order_items

        WHERE order_id = :order_id

    ");

    $itemsStmt->execute([

        'order_id' =>
        $localOrderId

    ]);

    $items =
        $itemsStmt->fetchAll(
            PDO::FETCH_ASSOC
        );

    if (!$items) {

        throw new Exception(
            'Order items were not found.'
        );
    }

    foreach ($items as $item) {

        $stockStmt = $db->prepare("

            UPDATE products

            SET stock = stock - :quantity

            WHERE id = :product_id

              AND stock >= :quantity

        ");

        $stockStmt->execute([

            'quantity' =>
            (int) $item['quantity'],

            'product_id' =>
            (int) $item['product_id']

        ]);

        if (
            $stockStmt->rowCount() !== 1
        ) {

            throw new Exception(
                'Unable to update product stock.'
            );
        }
    }
    $updateStmt = $db->prepare("

        UPDATE orders

        SET

            payment_status = 'paid',

            order_status = 'confirmed',

            razorpay_payment_id =
                :razorpay_payment_id,

            razorpay_signature =
                :razorpay_signature

        WHERE id = :order_id

          AND user_id = :user_id

        LIMIT 1

    ");

    $updateStmt->execute([

        'razorpay_payment_id' =>
        $razorpayPaymentId,

        'razorpay_signature' =>
        $razorpaySignature,

        'order_id' =>
        $localOrderId,

        'user_id' =>
        $_SESSION['user_id']

    ]);

    $db->commit();

    $_SESSION['last_order_id'] =
        $localOrderId;

    unset(
        $_SESSION['razorpay_order_id']
    );

    unset(
        $_SESSION['razorpay_order_id_local']
    );

    echo json_encode([

        'success' => true,

        'message' =>
        'Payment verified successfully.',

        'local_order_id' =>
        $localOrderId,

        'razorpay_order_id' =>
        $serverRazorpayOrderId,

        'razorpay_payment_id' =>
        $razorpayPaymentId

    ]);
} catch (Exception $e) {

    if (
        $db &&
        $db->inTransaction()
    ) {

        $db->rollBack();
    }

    http_response_code(500);

    echo json_encode([

        'success' => false,

        'message' =>
        $e->getMessage()

    ]);
}
