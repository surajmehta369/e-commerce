<?php

session_start();

require_once __DIR__ . '/paypal-api.php';
require_once __DIR__ . '/connection/dbconnect.php';

header('Content-Type: application/json');


try {

    // =================================================
    // CHECK LOGIN
    // =================================================

    if (
        !isset($_SESSION['user_id']) ||
        $_SESSION['logged_in'] !== true
    ) {

        throw new Exception(
            'User is not logged in.'
        );

    }


    // =================================================
    // READ REQUEST
    // =================================================

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );


    if (!is_array($input)) {

        throw new Exception(
            'Invalid request data.'
        );

    }


    $cart =
        $input['cart'] ?? [];

    $shipping =
        $input['shipping'] ?? [];


    if (
        !is_array($cart) ||
        count($cart) === 0
    ) {

        throw new Exception(
            'Your cart is empty.'
        );

    }


    // =================================================
    // VALIDATE SHIPPING
    // =================================================

    $shippingName =
        trim($shipping['name'] ?? '');

    $shippingPhone =
        trim($shipping['phone'] ?? '');

    $shippingAddress =
        trim($shipping['address'] ?? '');

    $shippingCity =
        trim($shipping['city'] ?? '');

    $shippingState =
        trim($shipping['state'] ?? '');

    $shippingPincode =
        trim($shipping['pincode'] ?? '');


    if (
        $shippingName === '' ||
        $shippingPhone === '' ||
        $shippingAddress === '' ||
        $shippingCity === '' ||
        $shippingState === '' ||
        $shippingPincode === ''
    ) {

        throw new Exception(
            'Please complete all delivery information.'
        );

    }


    // =================================================
    // DATABASE
    // =================================================

    $database =
        new Database();

    $db =
        $database->connect();


    $db->beginTransaction();


    // =================================================
    // CALCULATE CART FROM DATABASE
    // =================================================

    $totalINR = 0;

    $orderItems = [];


    foreach ($cart as $cartItem) {

        $productId =
            (int) ($cartItem['id'] ?? 0);

        $quantity =
            (int) ($cartItem['quantity'] ?? 0);


        if (
            $productId <= 0 ||
            $quantity <= 0
        ) {

            throw new Exception(
                'Invalid cart item.'
            );

        }


        // ---------------------------------------------
        // GET PRODUCT
        // ---------------------------------------------

        $productStmt =
            $db->prepare("

                SELECT

                    id,
                    title,
                    image,
                    price,
                    stock

                FROM products

                WHERE id = :id

                  AND status = 1

                LIMIT 1

            ");


        $productStmt->execute([

            'id' =>
                $productId

        ]);


        $product =
            $productStmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$product) {

            throw new Exception(
                'Product not found.'
            );

        }


        // ---------------------------------------------
        // CHECK STOCK
        // ---------------------------------------------

        if (
            $quantity >
            (int) $product['stock']
        ) {

            throw new Exception(
                'Not enough stock for: '
                . $product['title']
            );

        }


        // ---------------------------------------------
        // PRICE
        // ---------------------------------------------

        $price =
            (float) $product['price'];


        $subtotal =
            $price * $quantity;


        $totalINR +=
            $subtotal;


        $orderItems[] = [

            'product_id' =>
                $product['id'],

            'product_name' =>
                $product['title'],

            'product_image' =>
                $product['image'],

            'price' =>
                $price,

            'quantity' =>
                $quantity,

            'subtotal' =>
                $subtotal

        ];

    }


    // =================================================
    // INR → USD
    // =================================================

    /*
     * Temporary exchange rate for testing.
     *
     * ₹84 = $1
     *
     * We can replace this later with a live
     * exchange-rate API.
     */

    $usdRate = 84;


    $totalUSD =
        $totalINR / $usdRate;


    $paypalAmount =
        number_format(
            $totalUSD,
            2,
            '.',
            ''
        );


    // =================================================
    // CREATE LOCAL ORDER
    // =================================================

    $orderSql = "

        INSERT INTO orders (

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
            shipping_pincode

        )

        VALUES (

            :user_id,
            :total_amount,
            :payment_method,
            :payment_status,
            :order_status,

            :shipping_name,
            :shipping_phone,
            :shipping_address,
            :shipping_city,
            :shipping_state,
            :shipping_pincode

        )

    ";


    $orderStmt =
        $db->prepare($orderSql);


    $orderStmt->execute([

        'user_id' =>
            $_SESSION['user_id'],

        'total_amount' =>
            $totalINR,

        'payment_method' =>
            'paypal',

        'payment_status' =>
            'pending',

        'order_status' =>
            'pending',

        'shipping_name' =>
            $shippingName,

        'shipping_phone' =>
            $shippingPhone,

        'shipping_address' =>
            $shippingAddress,

        'shipping_city' =>
            $shippingCity,

        'shipping_state' =>
            $shippingState,

        'shipping_pincode' =>
            $shippingPincode

    ]);


    $localOrderId =
        $db->lastInsertId();


    // =================================================
    // CREATE ORDER ITEMS
    // =================================================

    $itemStmt =
        $db->prepare("

            INSERT INTO order_items (

                order_id,
                product_id,
                product_name,
                product_image,
                price,
                quantity,
                subtotal

            )

            VALUES (

                :order_id,
                :product_id,
                :product_name,
                :product_image,
                :price,
                :quantity,
                :subtotal

            )

        ");


    foreach ($orderItems as $item) {

        $itemStmt->execute([

            'order_id' =>
                $localOrderId,

            'product_id' =>
                $item['product_id'],

            'product_name' =>
                $item['product_name'],

            'product_image' =>
                $item['product_image'],

            'price' =>
                $item['price'],

            'quantity' =>
                $item['quantity'],

            'subtotal' =>
                $item['subtotal']

        ]);

    }


    // =================================================
    // CREATE PAYPAL ORDER
    // =================================================

    $paypalData = [

        'intent' =>
            'CAPTURE',

        'purchase_units' => [

            [

                'reference_id' =>
                    'ORDER_' . $localOrderId,

                'amount' => [

                    'currency_code' =>
                        'USD',

                    'value' =>
                        $paypalAmount

                ]

            ]

        ]

    ];


    $paypalOrder =
        paypalRequest(
            'POST',
            '/v2/checkout/orders',
            $paypalData
        );


    // =================================================
    // CHECK PAYPAL ORDER ID
    // =================================================

    if (
        !isset($paypalOrder['id']) ||
        empty($paypalOrder['id'])
    ) {

        throw new Exception(
            'PayPal Order ID was not returned.'
        );

    }


    $paypalOrderId =
        $paypalOrder['id'];


    // =================================================
    // SAVE PAYPAL ORDER ID
    // =================================================

    $updateStmt =
        $db->prepare("

            UPDATE orders

            SET paypal_order_id = :paypal_order_id

            WHERE id = :order_id

              AND user_id = :user_id

            LIMIT 1

        ");


    $updateStmt->execute([

        'paypal_order_id' =>
            $paypalOrderId,

        'order_id' =>
            $localOrderId,

        'user_id' =>
            $_SESSION['user_id']

    ]);


    // =================================================
    // COMMIT
    // =================================================

    $db->commit();


    // =================================================
    // SAVE SESSION
    // =================================================

    $_SESSION['paypal_order_id'] =
        $paypalOrderId;

    $_SESSION['paypal_order_id_local'] =
        $localOrderId;

    $_SESSION['paypal_total_inr'] =
        $totalINR;

    $_SESSION['paypal_total_usd'] =
        $paypalAmount;


    // =================================================
    // RESPONSE
    // =================================================

    echo json_encode([

        'success' =>
            true,

        'order' =>
            $paypalOrder,

        'local_order_id' =>
            $localOrderId,

        'paypal_order_id' =>
            $paypalOrderId,

        'amount' => [

            'inr' =>
                number_format(
                    $totalINR,
                    2,
                    '.',
                    ''
                ),

            'usd' =>
                $paypalAmount

        ]

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

        'success' =>
            false,

        'message' =>
            $e->getMessage()

    ]);

}