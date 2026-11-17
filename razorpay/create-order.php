
<?php

session_start();

require_once __DIR__ . '/../config/razorpay.php';
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

    $selectedAddressId = (int) (
        $input['selected_address_id'] ?? 0
    );

    if (!is_array($cart) || empty($cart)) {
        throw new Exception('Your cart is empty.');
    }

    if ($selectedAddressId <= 0) {
        throw new Exception('Please select a delivery address.');
    }
    $database = new Database();
    $db = $database->connect();
    $addressStmt = $db->prepare("
        SELECT
            id,
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
            'Selected delivery address is invalid.'
        );
    }

    $shippingName = $selectedAddress['full_name'];
    $shippingPhone = $selectedAddress['phone'];

    $shippingAddress = $selectedAddress['address_line1'];

    if (!empty($selectedAddress['address_line2'])) {
        $shippingAddress .= ', ' .
            $selectedAddress['address_line2'];
    }

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
            throw new Exception(
                'Invalid cart item.'
            );
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
            throw new Exception(
                'Product not found.'
            );
        }
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
            if (
                $quantity > (int) $variant['stock']
            ) {
                throw new Exception(
                    'Not enough stock for variant: ' .
                        $variant['variant_title']
                );
            }


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

            $productImage = $variantImage ?: $product['image'];

            $price = (float) $variant['price'];

            $savedVariantId = (int) $variant['id'];
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

            if (
                $quantity > (int) $product['stock']
            ) {
                throw new Exception(
                    'Not enough stock for: ' .
                        $product['title']
                );
            }
            $productImage = $product['image'];
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

    $amountInPaise = (int) round(
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

    $orderStmt = $db->prepare($orderSql);

    $orderStmt->execute([

        'user_id' => $userId,

        'total_amount' => $totalINR,

        'payment_method' => 'razorpay',

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
    $razorpayPayload = [

        'amount' => $amountInPaise,

        'currency' => 'INR',

        'receipt' => 'ORDER_' . $localOrderId,

        'notes' => [

            'local_order_id' => (string) $localOrderId,

            'user_id' => (string) $userId

        ]

    ];

    $ch = curl_init(
        RAZORPAY_BASE_URL . '/orders'
    );

    curl_setopt_array($ch, [

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS => json_encode(
            $razorpayPayload
        ),

        CURLOPT_USERPWD =>
        RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,

        CURLOPT_HTTPHEADER => [

            'Content-Type: application/json',

            'Accept: application/json'

        ],

        CURLOPT_TIMEOUT => 30

    ]);

    $razorpayResponse = curl_exec($ch);

    $curlError = curl_error($ch);

    $httpCode = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    if ($razorpayResponse === false) {
        throw new Exception(
            'Unable to connect to Razorpay: ' . $curlError
        );
    }

    $razorpayOrder = json_decode(
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

        throw new Exception($razorpayMessage);
    }

    if (empty($razorpayOrder['id'])) {
        throw new Exception(
            'Razorpay Order ID was not returned.'
        );
    }

    $razorpayOrderId = $razorpayOrder['id'];
    $updateStmt = $db->prepare("

        UPDATE orders

        SET razorpay_order_id = :razorpay_order_id

        WHERE id = :order_id
          AND user_id = :user_id

        LIMIT 1

    ");

    $updateStmt->execute([

        'razorpay_order_id' => $razorpayOrderId,

        'order_id' => $localOrderId,

        'user_id' => $userId

    ]);


    $db->commit();

    $_SESSION['razorpay_order_id'] = $razorpayOrderId;

    $_SESSION['razorpay_order_id_local'] = $localOrderId;


    // 13. RETURN RESPONSE
    // ---------------------------------------

    echo json_encode([

        'success' => true,

        'local_order_id' => $localOrderId,

        'razorpay_order_id' => $razorpayOrderId,

        'key_id' => RAZORPAY_KEY_ID,

        'amount' => $amountInPaise,

        'currency' => 'INR'

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
