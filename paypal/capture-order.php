<?php

session_start();

require_once __DIR__ . '/paypal-api.php';
require_once __DIR__ . '/connection/dbconnect.php';

header('Content-Type: application/json');


try {

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );


    if (
        !isset($input['orderID']) ||
        empty($input['orderID'])
    ) {

        throw new Exception(
            'PayPal Order ID is missing.'
        );

    }


    $paypalOrderId =
        $input['orderID'];


    $result =
        paypalRequest(
            'POST',
            '/v2/checkout/orders/'
            . urlencode($paypalOrderId)
            . '/capture'
        );

    if (
        !isset($result['status']) ||
        $result['status'] !== 'COMPLETED'
    ) {

        throw new Exception(
            'PayPal payment was not completed.'
        );

    }

    $captureId = null;


    if (
        isset(
            $result['purchase_units'][0]
                ['payments']
                ['captures'][0]
                ['id']
        )
    ) {

        $captureId =
            $result['purchase_units'][0]
                ['payments']
                ['captures'][0]
                ['id'];

    }


    if (!$captureId) {

        throw new Exception(
            'PayPal capture ID was not returned.'
        );

    }

    $database =
        new Database();

    $db =
        $database->connect();

    $orderId =
        $_SESSION['paypal_order_id_local']
        ?? null;


    if (!$orderId) {

        $orderId = null;

    }


    if ($orderId) {

        $db->beginTransaction();
        $updateOrder = $db->prepare("

            UPDATE orders

            SET

                payment_status = 'paid',

                order_status = 'confirmed',

                paypal_order_id = :paypal_order_id,

                paypal_capture_id = :paypal_capture_id

            WHERE id = :order_id

              AND user_id = :user_id

            LIMIT 1

        ");


        $updateOrder->execute([

            'paypal_order_id' =>
                $paypalOrderId,

            'paypal_capture_id' =>
                $captureId,

            'order_id' =>
                $orderId,

            'user_id' =>
                $_SESSION['user_id']

        ]);

        $itemsStmt = $db->prepare("

            SELECT

                product_id,

                quantity

            FROM order_items

            WHERE order_id = :order_id

        ");


        $itemsStmt->execute([

            'order_id' =>
                $orderId

        ]);


        $items =
            $itemsStmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        foreach ($items as $item) {

            $stockStmt = $db->prepare("

                UPDATE products

                SET stock = stock - :quantity

                WHERE id = :product_id

                  AND stock >= :quantity

            ");


            $stockStmt->execute([

                'quantity' =>
                    $item['quantity'],

                'product_id' =>
                    $item['product_id']

            ]);


            if (
                $stockStmt->rowCount() !== 1
            ) {

                throw new Exception(
                    'Unable to update product stock.'
                );

            }

        }


        $db->commit();

        $_SESSION['last_order_id'] =
            $orderId;

    }

    echo json_encode([

        'success' => true,

        'message' =>
            'Payment captured successfully.',

        'paypal_order_id' =>
            $paypalOrderId,

        'paypal_capture_id' =>
            $captureId,

        'local_order_id' =>
            $orderId,

        'paypal_response' =>
            $result

    ]);


} catch (Exception $e) {


    if (
        isset($db) &&
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