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

    $database =
        new Database();

    $db =
        $database->connect();

    $db->beginTransaction();
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

        if (
            $quantity >
            (int) $product['stock']
        ) {

            throw new Exception(
                'Not enough stock for: '
                . $product['title']
            );

        }


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

    $amountInPaise =
        (int) round(
            $totalINR * 100
        );


    if ($amountInPaise <= 0) {

        throw new Exception(
            'Invalid order amount.'
        );

    }

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
            'razorpay',

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
    $razorpayPayload = [

        'amount' =>
            $amountInPaise,

        'currency' =>
            'INR',

        'receipt' =>
            'ORDER_' . $localOrderId,

        'notes' => [

            'local_order_id' =>
                (string) $localOrderId,

            'user_id' =>
                (string) $_SESSION['user_id']

        ]

    ];
    $ch =
        curl_init(
            RAZORPAY_BASE_URL . '/orders'
        );


    curl_setopt_array($ch, [

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_POST =>
            true,

        CURLOPT_POSTFIELDS =>
            json_encode(
                $razorpayPayload
            ),

        CURLOPT_USERPWD =>
            RAZORPAY_KEY_ID
            . ':'
            . RAZORPAY_KEY_SECRET,

        CURLOPT_HTTPHEADER => [

            'Content-Type: application/json',

            'Accept: application/json'

        ],

        CURLOPT_TIMEOUT =>
            30

    ]);

    $razorpayResponse =
        curl_exec($ch);


    $curlError =
        curl_error($ch);

    $httpCode =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    if ($razorpayResponse === false) {

        throw new Exception(
            'Unable to connect to Razorpay: '
            . $curlError
        );

    }
    $razorpayOrder =
        json_decode(
            $razorpayResponse,
            true
        );


    if (
        $httpCode < 200 ||
        $httpCode >= 300 ||
        !is_array($razorpayOrder)
    ) {
        $razorpayMessage =
            $razorpayOrder['error']['description']
            ?? 'Unable to create Razorpay order.';

        throw new Exception(
            $razorpayMessage
        );
    }

    if (
        empty(
            $razorpayOrder['id']
        )
    ) {
        throw new Exception(
            'Razorpay Order ID was not returned.'
        );
    }
    $razorpayOrderId =
        $razorpayOrder['id'];
    $updateStmt =
        $db->prepare("

            UPDATE orders

            SET razorpay_order_id = :razorpay_order_id

            WHERE id = :order_id

              AND user_id = :user_id

            LIMIT 1

        ");

    $updateStmt->execute([

        'razorpay_order_id' =>
            $razorpayOrderId,

        'order_id' =>
            $localOrderId,

        'user_id' =>
            $_SESSION['user_id']

    ]);

    $db->commit();

    $_SESSION['razorpay_order_id'] =
        $razorpayOrderId;

    $_SESSION['razorpay_order_id_local'] =
        $localOrderId;

    echo json_encode([

        'success' => true,
        'local_order_id' => $localOrderId,
        'razorpay_order_id' => $razorpayOrderId,
        'key_id' => RAZORPAY_KEY_ID,
        'amount' => $amountInPaise,
        'currency' =>'INR'
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
        'success' =>
            false,
        'message' =>
         $e->getMessage()
    ]);
}
