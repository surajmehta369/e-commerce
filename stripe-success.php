<?php

session_start();

require_once "connection/dbconnect.php";
require_once __DIR__ . '/config/stripe.php';

if (
    !isset($_SESSION['user_id']) ||
    $_SESSION['logged_in'] !== true
) {

    header("Location: outh/login.php");
    exit;
}

$sessionId =
    $_GET['session_id'] ?? '';


if ($sessionId === '') {

    die("Invalid Stripe payment session.");
}
try {

    $checkoutSession =
        \Stripe\Checkout\Session::retrieve(
            $sessionId
        );

    if (
        $checkoutSession->payment_status
        !== 'paid'
    ) {

        die("Payment was not completed.");
    }

    $orderId =
        $checkoutSession->metadata->order_id
        ?? null;


    if (!$orderId) {

        die("Order information was not found.");
    }
    $orderId =
        (int) $orderId;

    $database =
        new Database();

    $db =
        $database->connect();


    $db->beginTransaction();

    $orderSql = "

        SELECT
            id,
            user_id,
            total_amount,
            payment_method,
            payment_status,
            order_status

        FROM orders

        WHERE id = :order_id
          AND user_id = :user_id

        LIMIT 1

    ";
    $orderStmt =
        $db->prepare($orderSql);


    $orderStmt->execute([

        'order_id' =>
        $orderId,

        'user_id' =>
        $_SESSION['user_id']

    ]);


    $order =
        $orderStmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$order) {

        throw new Exception(
            "Order not found."
        );
    }

    if (
        $order['payment_status']
        !== 'paid'
    ) {


        $itemSql = "

            SELECT
                product_id,
                quantity

            FROM order_items

            WHERE order_id = :order_id

        ";


        $itemStmt =
            $db->prepare($itemSql);


        $itemStmt->execute([

            'order_id' =>
            $orderId

        ]);


        $items =
            $itemStmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        foreach ($items as $item) {

            $stockSql = "

                UPDATE products

                SET stock = stock - :quantity

                WHERE id = :id
                  AND stock >= :quantity

            ";


            $stockStmt =
                $db->prepare($stockSql);


            $stockStmt->execute([

                'quantity' =>
                (int) $item['quantity'],

                'id' =>
                (int) $item['product_id']

            ]);


            if (
                $stockStmt->rowCount()
                !== 1
            ) {

                throw new Exception(
                    "Unable to update product stock."
                );
            }
        }

        $updateSql = "

            UPDATE orders

            SET
                payment_status = 'paid',
                order_status = 'confirmed'

            WHERE id = :order_id

        ";


        $updateStmt =
            $db->prepare($updateSql);


        $updateStmt->execute([

            'order_id' =>
            $orderId

        ]);
    }
    $db->commit();

    $_SESSION['last_order_id'] =
        $orderId;


    unset(
        $_SESSION['stripe_order_id']
    );

    unset(
        $_SESSION['stripe_session_id']
    );

    header(
        "Location: order-confirmation.php"
    );

    exit;
} catch (Exception $e) {

    if (
        isset($db) &&
        $db->inTransaction()
    ) {

        $db->rollBack();
    }


    die("Payment verification failed: "
        . htmlspecialchars(
            $e->getMessage()
        ));
}
