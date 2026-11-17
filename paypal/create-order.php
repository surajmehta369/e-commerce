
<?php

session_start();

require_once __DIR__ . '/paypal-api.php';
require_once __DIR__ . '/../connection/dbconnect.php';

header('Content-Type: application/json');

$db = null;

try {

    if (
        empty($_SESSION['user_id']) ||
        ($_SESSION['logged_in'] ?? false) !== true
    ) {
        throw new Exception('User is not logged in.');
    }

    $userId = (int) $_SESSION['user_id'];
    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        throw new Exception('Invalid request data.');
    }

    $cart = $input['cart'] ?? [];

    if (!is_array($cart) || empty($cart)) {
        throw new Exception('Your cart is empty.');
    }

    $selectedAddressId = (int) (
        $input['selected_address_id'] ?? 0
    );

    if ($selectedAddressId <= 0) {
        throw new Exception('Please select a delivery address.');
    }
    $database = new Database();
    $db = $database->connect();
    $addressStmt = $db->prepare("
        SELECT
            full_name,
            phone,
            address_line1,
            address_line2,
            city,
            state,
            pincode
        FROM user_addresses
        WHERE id = :address_id
          AND user_id = :user_id
        LIMIT 1
    ");

    $addressStmt->execute([
        'address_id' => $selectedAddressId,
        'user_id' => $userId
    ]);

    $selectedAddress = $addressStmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$selectedAddress) {
        throw new Exception(
            'Selected delivery address was not found.'
        );
    }

    $shippingName = $selectedAddress['full_name'];
    $shippingPhone = $selectedAddress['phone'];

    $shippingAddress = trim(
        $selectedAddress['address_line1'] . ' ' .
        ($selectedAddress['address_line2'] ?? '')
    );

    $shippingCity = $selectedAddress['city'];
    $shippingState = $selectedAddress['state'];
    $shippingPincode = $selectedAddress['pincode'];
    $db->beginTransaction();

    $totalINR = 0;
    $orderItems = [];
    foreach ($cart as $cartItem) {

        $productId = (int) (
            $cartItem['id'] ?? 0
        );

        $variantId = (int) (
            $cartItem['variant_id'] ?? 0
        );

        $quantity = (int) (
            $cartItem['quantity'] ?? 0
        );

        if (
            $productId <= 0 ||
            $quantity <= 0
        ) {
            throw new Exception('Invalid cart item.');
        }
       $productStmt = $db->prepare("
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
            FOR UPDATE
        ");

        $productStmt->execute([
            'id' => $productId
        ]);

        $product = $productStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$product) {
            throw new Exception('Product not found.');
        }
        $productImage = $product['image'];

        if ($variantId > 0) {

            $variantStmt = $db->prepare("
                SELECT
                    id,
                    product_id,
                    variant_title,
                    price,
                    stock
                FROM product_variants
                WHERE id = :variant_id
                  AND product_id = :product_id
                  AND status = 1
                LIMIT 1
                FOR UPDATE
            ");

            $variantStmt->execute([
                'variant_id' => $variantId,
                'product_id' => $productId
            ]);

            $variant = $variantStmt->fetch(
                PDO::FETCH_ASSOC
            );

            if (!$variant) {
                throw new Exception(
                    'Selected variant is unavailable for: ' .
                    $product['title']
                );
            }
            if ($quantity > (int) $variant['stock']) {
                throw new Exception(
                    'Not enough stock for variant: ' .
                    $variant['variant_title']
                );
            }

            $price = (float) $variant['price'];
            $variantImageStmt = $db->prepare("
                SELECT image_path
                FROM product_variant_images
                WHERE variant_id = :variant_id
                  AND status = 1
                ORDER BY sort_order ASC, id ASC
                LIMIT 1
            ");

            $variantImageStmt->execute([
                'variant_id' => $variantId
            ]);

            $variantImage = $variantImageStmt->fetchColumn();
            if (!empty($variantImage)) {
                $productImage = $variantImage;
            }

            $savedVariantId = $variantId;

        } else {
            $variantCountStmt = $db->prepare("
                SELECT COUNT(*)
                FROM product_variants
                WHERE product_id = :product_id
                  AND status = 1
            ");

            $variantCountStmt->execute([
                'product_id' => $productId
            ]);

            $variantCount = (int) $variantCountStmt->fetchColumn();

            if ($variantCount > 0) {
                throw new Exception(
                    'Please select a variant for: ' .
                    $product['title']
                );
            }

            if ($quantity > (int) $product['stock']) {
                throw new Exception(
                    'Not enough stock for: ' .
                    $product['title']
                );
            }

            $price = (float) $product['price'];

            $savedVariantId = null;
        }
        $subtotal = round(
            $price * $quantity,
            2
        );

        $totalINR += $subtotal;
        $orderItems[] = [

            'product_id' => (int) $product['id'],

            'variant_id' => $savedVariantId,

            'product_name' => $product['title'],

            'product_image' => $productImage,

            'price' => $price,

            'quantity' => $quantity,

            'subtotal' => $subtotal

        ];
    }

    $totalINR = round($totalINR, 2);

    $usdRate = 84;

    $totalUSD = $totalINR / $usdRate;

    $paypalAmount = number_format(
        $totalUSD,
        2,
        '.',
        ''
    );

    if ((float) $paypalAmount <= 0) {
        throw new Exception('Invalid PayPal order amount.');
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

    $orderStmt = $db->prepare($orderSql);

    $orderStmt->execute([

        'user_id' => $userId,

        'total_amount' => $totalINR,

        'payment_method' => 'paypal',

        'payment_status' => 'pending',

        'order_status' => 'pending',

        'shipping_name' => $shippingName,

        'shipping_phone' => $shippingPhone,

        'shipping_address' => $shippingAddress,

        'shipping_city' => $shippingCity,

        'shipping_state' => $shippingState,

        'shipping_pincode' => $shippingPincode

    ]);

    $localOrderId = $db->lastInsertId();
    $itemStmt = $db->prepare("

        INSERT INTO order_items (

            order_id,
            product_id,
            variant_id,
            product_name,
            product_image,
            price,
            quantity,
            subtotal

        )

        VALUES (

            :order_id,
            :product_id,
            :variant_id,
            :product_name,
            :product_image,
            :price,
            :quantity,
            :subtotal

        )

    ");

    foreach ($orderItems as $item) {

        $itemStmt->execute([

            'order_id' => $localOrderId,

            'product_id' => $item['product_id'],

            'variant_id' => $item['variant_id'],

            'product_name' => $item['product_name'],

            'product_image' => $item['product_image'],

            'price' => $item['price'],

            'quantity' => $item['quantity'],

            'subtotal' => $item['subtotal']

        ]);
    }

    $paypalData = [

        'intent' => 'CAPTURE',

        'purchase_units' => [

            [

                'reference_id' => 'ORDER_' . $localOrderId,

                'amount' => [

                    'currency_code' => 'USD',

                    'value' => $paypalAmount

                ]

            ]

        ]

    ];

    $paypalOrder = paypalRequest(
        'POST',
        '/v2/checkout/orders',
        $paypalData
    );

    if (
        !isset($paypalOrder['id']) ||
        empty($paypalOrder['id'])
    ) {
        throw new Exception(
            'PayPal Order ID was not returned.'
        );
    }

    $paypalOrderId = $paypalOrder['id'];

    $updateStmt = $db->prepare("

        UPDATE orders

        SET paypal_order_id = :paypal_order_id

        WHERE id = :order_id
          AND user_id = :user_id

        LIMIT 1

    ");

    $updateStmt->execute([

        'paypal_order_id' => $paypalOrderId,

        'order_id' => $localOrderId,

        'user_id' => $userId

    ]);

    $db->commit();

    $_SESSION['paypal_order_id'] = $paypalOrderId;

    $_SESSION['paypal_order_id_local'] = $localOrderId;

    $_SESSION['paypal_total_inr'] = $totalINR;

    $_SESSION['paypal_total_usd'] = $paypalAmount;


    echo json_encode([

        'success' => true,

        'order' => $paypalOrder,

        'local_order_id' => $localOrderId,

        'paypal_order_id' => $paypalOrderId,

        'amount' => [

            'inr' => number_format(
                $totalINR,
                2,
                '.',
                ''
            ),

            'usd' => $paypalAmount

        ]

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

        'message' => $e->getMessage()

    ]);
}
