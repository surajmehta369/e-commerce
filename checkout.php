<?php

session_start();

require_once "connection/dbconnect.php";
require_once "customer-auth.php";

$database = new Database();
$db = $database->connect();

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'add_checkout_address'
) {
    $userId = (int) $_SESSION['user_id'];

    $addressType = trim($_POST['address_type'] ?? 'Home');
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $addressLine1 = trim($_POST['address_line1'] ?? '');
    $addressLine2 = trim($_POST['address_line2'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $pincode = trim($_POST['pincode'] ?? '');

    try {
        if (
            $fullName === '' ||
            $phone === '' ||
            $addressLine1 === '' ||
            $city === '' ||
            $state === '' ||
            $pincode === ''
        ) {
            throw new Exception('Please complete all address fields.');
        }

        if (!preg_match('/^[0-9A-Za-z\s-]{3,20}$/', $pincode)) {
            throw new Exception('Please enter a valid pincode.');
        }

        $db->beginTransaction();

        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM user_addresses
            WHERE user_id = :user_id
        ");
        $countStmt->execute(['user_id' => $userId]);

        $addressCount = (int) $countStmt->fetchColumn();
        $isDefault = ($addressCount === 0) ? 1 : 0;

        $insertStmt = $db->prepare("
            INSERT INTO user_addresses (
                user_id,
                address_type,
                full_name,
                phone,
                address_line1,
                address_line2,
                city,
                state,
                pincode,
                is_default
            )
            VALUES (
                :user_id,
                :address_type,
                :full_name,
                :phone,
                :address_line1,
                :address_line2,
                :city,
                :state,
                :pincode,
                :is_default
            )
        ");

        $insertStmt->execute([
            'user_id' => $userId,
            'address_type' => $addressType,
            'full_name' => $fullName,
            'phone' => $phone,
            'address_line1' => $addressLine1,
            'address_line2' => $addressLine2 !== '' ? $addressLine2 : null,
            'city' => $city,
            'state' => $state,
            'pincode' => $pincode,
            'is_default' => $isDefault
        ]);

        $newAddressId = (int) $db->lastInsertId();

        $db->commit();
        $_SESSION['checkout_selected_address_id'] = $newAddressId;
        $_SESSION['checkout_address_message'] = 'New delivery address saved successfully.';

        header('Location: checkout.php');
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        die("Unable to save address: " . htmlspecialchars($e->getMessage()));
    }
}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $userId = (int) $_SESSION['user_id'];
    $selectedAddressId = (int) ($_POST['selected_address_id'] ?? 0);

    if ($selectedAddressId <= 0) {
        die("Please select a delivery address.");
    }

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

    $selectedAddress = $addressStmt->fetch(PDO::FETCH_ASSOC);

    if (!$selectedAddress) {
        die("Selected delivery address was not found.");
    }
    $shippingName = $selectedAddress['full_name'];
    $shippingPhone = $selectedAddress['phone'];

    $shippingAddress = $selectedAddress['address_line1'];

    if (!empty($selectedAddress['address_line2'])) {
        $shippingAddress .= ', ' . $selectedAddress['address_line2'];
    }

    $shippingCity = $selectedAddress['city'];
    $shippingState = $selectedAddress['state'];
    $shippingPincode = $selectedAddress['pincode'];

    $paymentMethod = $_POST['payment_method'] ?? '';
    $cartData = $_POST['cart_data'] ?? '';

    if (
        $shippingName === '' ||
        $shippingPhone === '' ||
        $shippingAddress === '' ||
        $shippingCity === '' ||
        $shippingState === '' ||
        $shippingPincode === ''
    ) {

        die("Please fill all delivery information.");
    }

    $allowedPaymentMethods = [
        'cash_on_delivery',
        'stripe',
        'paypal',
        'razorpay'
    ];

    if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {

        die("Invalid payment method.");
    }
    $cart = json_decode(
        $cartData,
        true
    );


    if (
        !is_array($cart) ||
        count($cart) === 0
    ) {

        die("Your cart is empty.");
    }
    try {


        $db->beginTransaction();
        $totalAmount = 0;

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
                    "Invalid cart item."
                );
            }

            $productSql = "
    SELECT
        id,
        title,
        image,
        price,
        stock,
        weight,
        length,
        width,
        height
    FROM products
    WHERE id = :id
      AND status = 1
    LIMIT 1
";

            $productStmt = $db->prepare($productSql);
            $productStmt->execute(['id' => $productId]);
            $product = $productStmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new Exception("Product not found.");
            }

            $variantId = (int) ($cartItem['variant_id'] ?? 0);
            $variant = null;

            $productImage = $product['image'];

            if ($variantId > 0) {

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
            }

            if ($variantId > 0) {
                $variantSql = "
    SELECT
        id,
        product_id,
        variant_title,
        sku,
        price,
        stock,
        weight,
        length,
        width,
        height
    FROM product_variants
    WHERE id = :variant_id
      AND product_id = :product_id
      AND status = 1
    LIMIT 1
";

                $variantStmt = $db->prepare($variantSql);
                $variantStmt->execute([
                    'variant_id' => $variantId,
                    'product_id' => $productId
                ]);

                $variant = $variantStmt->fetch(PDO::FETCH_ASSOC);

                if (!$variant) {
                    throw new Exception(
                        "Selected variant is invalid for: " . $product['title']
                    );
                }

                $availableStock = (int) $variant['stock'];
                $price = (float) $variant['price'];

                $productName = $product['title'] . ' - ' . $variant['variant_title'];
            } else {
                $availableStock = (int) $product['stock'];
                $price = (float) $product['price'];

                $productName = $product['title'];
            }

            if ($quantity > $availableStock) {
                throw new Exception(
                    "Not enough stock for: " . $productName
                );
            }

            $subtotal = $price * $quantity;
            $totalAmount += $subtotal;

            $orderItems[] = [
                'product_id' => (int) $product['id'],
                'variant_id' => $variant ? (int) $variant['id'] : null,
                'product_name' => $productName,
                'product_image' => $productImage,
                'price' => $price,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
                'weight' => (float) ($variant ? $variant['weight'] : $product['weight']),
                'length' => (float) ($variant ? $variant['length'] : $product['length']),
                'width'  => (float) ($variant ? $variant['width'] : $product['width']),
                'height' => (float) ($variant ? $variant['height'] : $product['height'])
            ];
        }

        if ($paymentMethod === 'cash_on_delivery') {

            $orderStatus = 'confirmed';
        } else {

            $orderStatus = 'pending';
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
            $totalAmount,

            'payment_method' =>
            $paymentMethod,

            'payment_status' =>
            'pending',

            'order_status' =>
            $orderStatus,

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
        $orderId =
            $db->lastInsertId();

        $itemSql = "
    INSERT INTO order_items (
        order_id,
        product_id,
        variant_id,
        product_name,
        product_image,
        price,
        quantity,
        subtotal,
        weight,
        length,
        width,
        height
    )
    VALUES (
        :order_id,
        :product_id,
        :variant_id,
        :product_name,
        :product_image,
        :price,
        :quantity,
        :subtotal,
        :weight,
        :length,
        :width,
        :height
    )
";

        $itemStmt = $db->prepare($itemSql);

        foreach ($orderItems as $item) {
            $itemStmt->execute([
                'order_id' => $orderId,
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'],
                'product_name' => $item['product_name'],
                'product_image' => $item['product_image'],
                'price' => $item['price'],
                'quantity' => $item['quantity'],
                'subtotal' => $item['subtotal'],
                'weight' => $item['weight'],
                'length' => $item['length'],
                'width' => $item['width'],
                'height' => $item['height']
            ]);
        }


        if ($paymentMethod === 'cash_on_delivery') {
            $updatedVariantProductIds = [];

            foreach ($orderItems as $item) {

                if (!empty($item['variant_id'])) {
                    $stockSql = "
                UPDATE product_variants
                SET stock = stock - :quantity
                WHERE id = :variant_id
                  AND product_id = :product_id
                  AND status = 1
                  AND stock >= :quantity
            ";

                    $stockStmt = $db->prepare($stockSql);

                    $stockStmt->execute([
                        'quantity'   => $item['quantity'],
                        'variant_id' => $item['variant_id'],
                        'product_id' => $item['product_id']
                    ]);

                    if ($stockStmt->rowCount() !== 1) {
                        throw new Exception(
                            "Unable to update stock for: " . $item['product_name']
                        );
                    }
                    $updatedVariantProductIds[] = (int) $item['product_id'];
                } else {
                    $stockSql = "
                UPDATE products
                SET stock = stock - :quantity
                WHERE id = :product_id
                  AND stock >= :quantity
            ";

                    $stockStmt = $db->prepare($stockSql);

                    $stockStmt->execute([
                        'quantity'   => $item['quantity'],
                        'product_id' => $item['product_id']
                    ]);

                    if ($stockStmt->rowCount() !== 1) {
                        throw new Exception(
                            "Unable to update stock for: " . $item['product_name']
                        );
                    }
                }
            }
            $updatedVariantProductIds = array_unique(
                $updatedVariantProductIds
            );

            $sumStockStmt = $db->prepare("
        SELECT COALESCE(SUM(stock), 0)
        FROM product_variants
        WHERE product_id = :product_id
          AND status = 1
    ");

            $updateProductStockStmt = $db->prepare("
        UPDATE products
        SET stock = :stock
        WHERE id = :product_id
    ");

            foreach ($updatedVariantProductIds as $productId) {

                $sumStockStmt->execute([
                    'product_id' => $productId
                ]);

                $totalVariantStock = (int) $sumStockStmt->fetchColumn();

                $updateProductStockStmt->execute([
                    'stock'      => $totalVariantStock,
                    'product_id' => $productId
                ]);
            }
            $db->commit();

            $_SESSION['last_order_id'] = $orderId;

            header("Location: order-confirmation.php");
            exit;
        }


        if ($paymentMethod === 'stripe') {

            require_once __DIR__ . '/config/stripe.php';

            $stripeLineItems = [];

            foreach ($orderItems as $item) {

                $productData = [
                    'name' => $item['product_name']
                ];
                if (!empty($item['product_image'])) {

                    $imagePath = $item['product_image'];
                    if (
                        filter_var($imagePath, FILTER_VALIDATE_URL) &&
                        strpos($imagePath, 'https://') === 0
                    ) {
                        $productData['images'] = [$imagePath];
                    }
                }

                $stripeLineItems[] = [

                    'price_data' => [

                        'currency' => 'inr',

                        'product_data' => $productData,

                        'unit_amount' => (int) round(
                            $item['price'] * 100
                        )

                    ],

                    'quantity' => $item['quantity']

                ];
            }

            $checkoutSession = \Stripe\Checkout\Session::create([

                'mode' => 'payment',

                'line_items' => $stripeLineItems,

                'success_url' =>
                'http://localhost/e-commerce/stripe-success.php?session_id={CHECKOUT_SESSION_ID}',

                'cancel_url' =>
                'http://localhost/e-commerce/stripe-cancel.php?order_id=' . $orderId,

                'customer_email' =>
                $_SESSION['user_email'] ?? null,

                'metadata' => [

                    'order_id' => (string) $orderId,

                    'user_id' => (string) $_SESSION['user_id']

                ]

            ]);

            $_SESSION['stripe_order_id'] = $orderId;

            $_SESSION['stripe_session_id'] = $checkoutSession->id;

            $db->commit();

            header("Location: " . $checkoutSession->url);

            exit;
        }
    } catch (Exception $e) {

        if ($db->inTransaction()) {

            $db->rollBack();
        }
        die("Order could not be placed: "
            . htmlspecialchars(
                $e->getMessage()
            ));
    }
}
$userId = (int) $_SESSION['user_id'];

$addressListStmt = $db->prepare("
    SELECT
        id,
        address_type,
        full_name,
        phone,
        address_line1,
        address_line2,
        city,
        state,
        pincode,
        is_default
    FROM user_addresses
    WHERE user_id = :user_id
    ORDER BY is_default DESC, id DESC
");

$addressListStmt->execute([
    'user_id' => $userId
]);

$addresses = $addressListStmt->fetchAll(PDO::FETCH_ASSOC);
$selectedAddressId = (int) (
    $_SESSION['checkout_selected_address_id'] ?? 0
);

unset($_SESSION['checkout_selected_address_id']);

$validSelectedAddress = false;

foreach ($addresses as $address) {
    if ((int) $address['id'] === $selectedAddressId) {
        $validSelectedAddress = true;
        break;
    }
}

if (!$validSelectedAddress) {
    $selectedAddressId = 0;

    foreach ($addresses as $address) {
        if ((int) $address['is_default'] === 1) {
            $selectedAddressId = (int) $address['id'];
            break;
        }
    }
}

$checkoutAddressMessage =
    $_SESSION['checkout_address_message'] ?? '';

unset($_SESSION['checkout_address_message']);

include "components/header.php";

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Checkout</title>
</head>

<body>

    <?php include "components/sidebar.php"; ?>


    <main class="container py-5">

        <div class="row g-4">



            <div class="col-lg-7">

                <div class="card border-0 shadow-sm rounded-4 p-4">

                    <h2 class="fw-bold mb-4">

                        <i class="fa-solid fa-location-dot
                              text-primary me-2"></i>

                        Delivery Information

                    </h2>

                    <div class="mb-4">

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h5 class="fw-bold mb-1">Select Delivery Address</h5>
                                <p class="text-muted small mb-0">
                                    Choose where you want your order delivered.
                                </p>
                            </div>
                        </div>

                        <?php if ($checkoutAddressMessage !== ''): ?>
                            <div class="alert alert-success rounded-3">
                                <?= htmlspecialchars($checkoutAddressMessage); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($addresses)): ?>

                            <div class="row g-3">

                                <?php foreach ($addresses as $address): ?>

                                    <?php
                                    $addressId = (int) $address['id'];
                                    $isSelected = ($addressId === $selectedAddressId);
                                    ?>

                                    <div class="col-12">

                                        <label
                                            class="checkout-address-card d-block border rounded-3 p-3 <?= $isSelected ? 'selected' : ''; ?>"
                                            for="address_<?= $addressId; ?>"

                                            data-name="<?= htmlspecialchars($address['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-phone="<?= htmlspecialchars($address['phone'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-address1="<?= htmlspecialchars($address['address_line1'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-address2="<?= htmlspecialchars($address['address_line2'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                            data-city="<?= htmlspecialchars($address['city'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-state="<?= htmlspecialchars($address['state'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-pincode="<?= htmlspecialchars($address['pincode'], ENT_QUOTES, 'UTF-8'); ?>"

                                            style="cursor:pointer;">

                                            <div class="d-flex gap-3">

                                                <input
                                                    type="radio"
                                                    class="form-check-input checkout-address-radio mt-1"
                                                    name="checkout_address_choice"
                                                    id="address_<?= $addressId; ?>"
                                                    value="<?= $addressId; ?>"
                                                    <?= $isSelected ? 'checked' : ''; ?>>

                                                <div class="flex-grow-1">

                                                    <div class="d-flex justify-content-between align-items-center mb-2">

                                                        <strong>
                                                            <?= htmlspecialchars($address['address_type']); ?>
                                                        </strong>

                                                        <?php if ((int) $address['is_default'] === 1): ?>
                                                            <span class="badge bg-success-subtle text-success">
                                                                Default
                                                            </span>
                                                        <?php endif; ?>

                                                    </div>

                                                    <div class="fw-semibold">
                                                        <?= htmlspecialchars($address['full_name']); ?>
                                                    </div>

                                                    <div class="text-muted small">
                                                        <?= htmlspecialchars($address['phone']); ?>
                                                    </div>

                                                    <div class="text-muted small mt-1">
                                                        <?= htmlspecialchars($address['address_line1']); ?>

                                                        <?php if (!empty($address['address_line2'])): ?>
                                                            , <?= htmlspecialchars($address['address_line2']); ?>
                                                        <?php endif; ?>
                                                    </div>

                                                    <div class="text-muted small">
                                                        <?= htmlspecialchars($address['city']); ?>,
                                                        <?= htmlspecialchars($address['state']); ?> -
                                                        <?= htmlspecialchars($address['pincode']); ?>
                                                    </div>

                                                    <div class="selected-address-label text-primary small fw-semibold mt-2"
                                                        <?= !$isSelected ? 'style="display:none;"' : ''; ?>>
                                                        <i class="fa-solid fa-circle-check me-1"></i>
                                                        Selected delivery address
                                                    </div>

                                                </div>

                                            </div>

                                        </label>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php else: ?>

                            <div class="alert alert-info rounded-3">
                                You don't have any saved addresses yet. Add your first delivery address below.
                            </div>

                        <?php endif; ?>

                    </div>
                    <div
                        id="newCheckoutAddressForm"
                        class="card border rounded-3 p-3 mb-4"
                        style="display:none;">

                        <h5 class="fw-bold mb-3">Add a New Address</h5>

                        <form method="POST" action="checkout.php">

                            <input
                                type="hidden"
                                name="action"
                                value="add_checkout_address">

                            <div class="mb-3">
                                <label class="form-label">Address Type</label>
                                <select name="address_type" class="form-select">
                                    <option value="Home">Home</option>
                                    <option value="Work">Work</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Full Name</label>
                                <input
                                    type="text"
                                    name="full_name"
                                    class="form-control"
                                    maxlength="150"
                                    required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Phone Number</label>
                                <input
                                    type="tel"
                                    name="phone"
                                    class="form-control"
                                    maxlength="30"
                                    required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Address Line 1</label>
                                <input
                                    type="text"
                                    name="address_line1"
                                    class="form-control"
                                    maxlength="255"
                                    placeholder="House number, street, area"
                                    required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Address Line 2 (Optional)</label>
                                <input
                                    type="text"
                                    name="address_line2"
                                    class="form-control"
                                    maxlength="255"
                                    placeholder="Landmark, apartment, etc.">
                            </div>

                            <div class="row">

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">City</label>
                                    <input
                                        type="text"
                                        name="city"
                                        class="form-control"
                                        maxlength="100"
                                        required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">State</label>
                                    <input
                                        type="text"
                                        name="state"
                                        class="form-control"
                                        maxlength="100"
                                        required>
                                </div>

                            </div>

                            <div class="mb-3">
                                <label class="form-label">Pincode</label>
                                <input
                                    type="text"
                                    name="pincode"
                                    class="form-control"
                                    maxlength="20"
                                    required>
                            </div>

                            <div class="d-flex gap-2">

                                <button type="submit" class="btn btn-primary rounded-pill">
                                    <i class="fa-solid fa-save me-1"></i>
                                    Save Address
                                </button>

                                <button
                                    type="button"
                                    class="btn btn-light rounded-pill"
                                    id="cancelNewAddress">
                                    Cancel
                                </button>

                            </div>

                        </form>

                    </div>

                    <form id="checkoutForm" method="POST" action="checkout.php">

                        <input type="hidden" name="cart_data" id="cart_data">

                        <input
                            type="hidden"
                            name="selected_address_id"
                            id="selected_address_id"
                            value="<?= (int) $selectedAddressId; ?>">

                        <div class="mb-3">

                            <label for="shipping_name" class="form-label fw-semibold">
                                Full Name
                            </label>

                            <input type="text" class="form-control" id="shipping_name" name="shipping_name"
                                value="<?= htmlspecialchars($_SESSION['user_name']) ?>" required>

                        </div>


                        <div class="mb-3">

                            <label for="shipping_phone" class="form-label fw-semibold">
                                Phone Number
                            </label>

                            <input type="tel" class="form-control" id="shipping_phone" name="shipping_phone"
                                placeholder="Enter phone number" required>

                        </div>


                        <div class="mb-3">

                            <label for="shipping_address" class="form-label fw-semibold">
                                Address
                            </label>

                            <textarea class="form-control" id="shipping_address" name="shipping_address" rows="3"
                                placeholder="House number, street, area" required></textarea>

                        </div>


                        <div class="row">

                            <div class="col-md-6 mb-3">

                                <label for="shipping_city" class="form-label fw-semibold">
                                    City
                                </label>

                                <input type="text" class="form-control" id="shipping_city" name="shipping_city"
                                    required>

                            </div>
                            <div class="col-md-6 mb-3">

                                <label for="shipping_state" class="form-label fw-semibold">
                                    State
                                </label>

                                <input type="text" class="form-control" id="shipping_state" name="shipping_state"
                                    required>

                            </div>

                        </div>


                        <div class="mb-4">

                            <label for="shipping_pincode" class="form-label fw-semibold">
                                Pincode
                            </label>

                            <input type="text" class="form-control" id="shipping_pincode" name="shipping_pincode"
                                maxlength="10" required>

                        </div>

                        <h4 class="fw-bold mb-3">
                            Payment Method
                        </h4>

                        <div class="border rounded-3 p-3 mb-3">

                            <div class="form-check">

                                <input
                                    class="form-check-input"
                                    type="radio"
                                    name="payment_method"
                                    id="cod"
                                    value="cash_on_delivery"
                                    checked>

                                <label
                                    class="form-check-label"
                                    for="cod">

                                    <i class="fa-solid fa-money-bill-wave text-success me-2"></i>

                                    <strong>
                                        Cash on Delivery
                                    </strong>

                                    <br>

                                    <small class="text-muted ms-4">
                                        Pay when your order is delivered.
                                    </small>

                                </label>

                            </div>

                        </div>


                        <div class="border rounded-3 p-3 mb-4">

                            <div class="form-check">

                                <input
                                    class="form-check-input"
                                    type="radio"
                                    name="payment_method"
                                    id="stripe"
                                    value="stripe">

                                <label
                                    class="form-check-label"
                                    for="stripe">

                                    <i class="fa-brands fa-stripe text-primary me-2"></i>

                                    <strong>
                                        Pay Online with Stripe
                                    </strong>

                                    <br>

                                    <small class="text-muted ms-4">
                                        Secure payment using credit/debit card.
                                    </small>

                                </label>

                            </div>

                        </div>

                        <div class="border rounded-3 p-3 mb-4">

                            <div class="form-check">

                                <input
                                    class="form-check-input"
                                    type="radio"
                                    name="payment_method"
                                    id="paypal-payment"
                                    value="paypal">

                                <label
                                    class="form-check-label"
                                    for="paypal">

                                    <i class="fa-brands fa-paypal text-primary me-2"></i>

                                    <strong>
                                        Pay with PayPal
                                    </strong>

                                    <br>

                                    <small class="text-muted ms-4">
                                        Secure payment using your PayPal account.
                                    </small>

                                </label>

                            </div>

                        </div>

                        <div
                            id="paypal-button-container"
                            class="mt-3">
                        </div>

                        <div class="border rounded-3 p-3 mb-4">

                            <div class="form-check">

                                <input
                                    class="form-check-input"
                                    type="radio"
                                    name="payment_method"
                                    id="razorpay-payment"
                                    value="razorpay">

                                <label
                                    class="form-check-label"
                                    for="razorpay-payment">

                                    <i class="fa-solid fa-credit-card text-primary me-2"></i>

                                    <strong>
                                        Pay with Razorpay
                                    </strong>

                                    <br>

                                    <small class="text-muted ms-4">
                                        Secure payment using UPI, cards, net banking and more.
                                    </small>

                                </label>

                            </div>

                        </div>

                        <div
                            id="razorpay-button-container"
                            class="mt-3">
                        </div>



                        <button
                            type="submit"
                            class="btn btn-primary rounded-pill px-4 py-2 w-100"
                            id="placeOrderButton">

                            <i class="fa-solid fa-lock me-2"></i>

                            Continue to Payment

                        </button>

                    </form>

                </div>

            </div>

            <div class="col-lg-5">

                <div class="card border-0 shadow-sm rounded-4 p-4">

                    <h3 class="fw-bold mb-4">

                        <i class="fa-solid
                              fa-bag-shopping
                              text-primary me-2"></i>

                        Order Summary

                    </h3>


                    <div id="checkout-cart">

                        <div class="text-center py-4">

                            <div class="spinner-border text-primary" role="status"></div>

                            <p class="text-muted mt-2 mb-0">

                                Loading your order...

                            </p>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </main>

    <script src="https://www.paypal.com/sdk/js?client-id=BAAHpGX9MgmkZykVaLi0DkQhyZK9d8yaFvCcjk56dMB-392LSaMvXHC6vJ4CgJ89M0rrRfCQbCN-bsLIDE&currency=USD"></script>

    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>

    <?php include "components/footer.php"; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {

            const addressRadios = document.querySelectorAll(
                '.checkout-address-radio'
            );

            const selectedAddressInput = document.getElementById(
                'selected_address_id'
            );

            const fields = {
                name: document.getElementById('shipping_name'),
                phone: document.getElementById('shipping_phone'),
                address: document.getElementById('shipping_address'),
                city: document.getElementById('shipping_city'),
                state: document.getElementById('shipping_state'),
                pincode: document.getElementById('shipping_pincode')
            };

            function selectAddress(radio) {

                const card = radio.closest('.checkout-address-card');

                if (!card) {
                    console.error('Address card not found.');
                    return;
                }

                console.log('Selected address:', radio.value);
                if (selectedAddressInput) {
                    selectedAddressInput.value = radio.value;
                } else {
                    console.error('selected_address_id input is missing.');
                }
                fields.name.value = card.dataset.name || '';
                fields.phone.value = card.dataset.phone || '';

                fields.address.value = [
                    card.dataset.address1,
                    card.dataset.address2
                ].filter(Boolean).join(', ');

                fields.city.value = card.dataset.city || '';
                fields.state.value = card.dataset.state || '';
                fields.pincode.value = card.dataset.pincode || '';
                Object.values(fields).forEach(function(field) {
                    field.readOnly = true;
                });
                document.querySelectorAll('.checkout-address-card').forEach(
                    function(item) {

                        item.classList.remove('selected');

                        const selectedLabel = item.querySelector(
                            '.selected-address-label'
                        );

                        if (selectedLabel) {
                            selectedLabel.style.display = 'none';
                        }
                    }
                );

                card.classList.add('selected');

                const selectedLabel = card.querySelector(
                    '.selected-address-label'
                );

                if (selectedLabel) {
                    selectedLabel.style.display = 'block';
                }
            }
            addressRadios.forEach(function(radio) {

                radio.addEventListener('change', function() {
                    selectAddress(this);
                });

            });
            const initiallySelected = document.querySelector(
                '.checkout-address-radio:checked'
            );

            if (initiallySelected) {
                selectAddress(initiallySelected);
            }

        });
    </script>
</body>

</html>