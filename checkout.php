<?php

session_start();

require_once "connection/dbconnect.php";


if (
    !isset($_SESSION['user_id']) ||
    $_SESSION['logged_in'] !== true
) {

    $_SESSION['checkout_redirect'] = 'checkout.php';

    header("Location: outh/login.php");
    exit;
}

$database = new Database();
$db = $database->connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $shippingName =
        trim($_POST['shipping_name'] ?? '');

    $shippingPhone =
        trim($_POST['shipping_phone'] ?? '');

    $shippingAddress =
        trim($_POST['shipping_address'] ?? '');

    $shippingCity =
        trim($_POST['shipping_city'] ?? '');

    $shippingState =
        trim($_POST['shipping_state'] ?? '');

    $shippingPincode =
        trim($_POST['shipping_pincode'] ?? '');

    $paymentMethod =
        $_POST['payment_method'] ?? '';

    $cartData =
        $_POST['cart_data'] ?? '';

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
                    stock
                FROM products
                WHERE id = :id
                  AND status = 1
                LIMIT 1
            ";
            $productStmt =
                $db->prepare($productSql);


            $productStmt->execute([
                'id' => $productId
            ]);
            $product =
                $productStmt->fetch(PDO::FETCH_ASSOC);


            if (!$product) {

                throw new Exception(
                    "Product not found."
                );
            }

            if (
                $quantity >
                (int) $product['stock']
            ) {

                throw new Exception(
                    "Not enough stock for: "
                        . $product['title']
                );
            }
            $price =
                (float) $product['price'];
            $subtotal =
                $price * $quantity;


            $totalAmount += $subtotal;
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

        ";
        $itemStmt =
            $db->prepare($itemSql);


        foreach ($orderItems as $item) {

            $itemStmt->execute([

                'order_id' =>
                $orderId,

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
        if ($paymentMethod === 'cash_on_delivery') {

            foreach ($orderItems as $item) {

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
                    $item['quantity'],

                    'id' =>
                    $item['product_id']

                ]);
                if ($stockStmt->rowCount() !== 1) {

                    throw new Exception(
                        "Unable to update stock for product."
                    );
                }
            }
            $db->commit();

            $_SESSION['last_order_id'] =
                $orderId;
            header(
                "Location: order-confirmation.php"
            );
            exit;
        }

        if ($paymentMethod === 'stripe') {

            require_once __DIR__ . '/config/stripe.php';
            $stripeLineItems = [];
            foreach ($orderItems as $item) {

                $stripeLineItems[] = [

                    'price_data' => [

                        'currency' => 'inr',

                        'product_data' => [

                            'name' =>
                            $item['product_name']

                        ],

                        'unit_amount' =>
                        (int) round(
                            $item['price'] * 100
                        )

                    ],

                    'quantity' =>
                    $item['quantity']

                ];
            }

            $checkoutSession =
                \Stripe\Checkout\Session::create([

                    'mode' =>
                    'payment',

                    'line_items' =>
                    $stripeLineItems,

                    'success_url' =>
                    'http://localhost/e-commerce/stripe-success.php?session_id={CHECKOUT_SESSION_ID}',

                    'cancel_url' =>
                    'http://localhost/e-commerce/stripe-cancel.php?order_id='
                        . $orderId,

                    'customer_email' =>
                    $_SESSION['user_email'] ?? null,

                    'metadata' => [

                        'order_id' =>
                        (string) $orderId,

                        'user_id' =>
                        (string) $_SESSION['user_id']

                    ]

                ]);
            $_SESSION['stripe_order_id'] =
                $orderId;

            $_SESSION['stripe_session_id'] =
                $checkoutSession->id;

            $db->commit();
            header(
                "Location: "
                    . $checkoutSession->url
            );

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


                    <form id="checkoutForm" method="POST" action="checkout.php">

                        <input type="hidden" name="cart_data" id="cart_data">


                        <!-- NAME -->

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
</body>

</html>