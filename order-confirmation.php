<?php

session_start();

require_once "connection/dbconnect.php";

if (!isset($_SESSION['last_order_id'])) {

    header("Location: index.php");
    exit;
}


$orderId =
    (int) $_SESSION['last_order_id'];


if (
    !isset($_SESSION['user_id']) ||
    $_SESSION['logged_in'] !== true
) {

    header("Location: outh/login.php");
    exit;
}

$database =
    new Database();

$db =
    $database->connect();

$sql = "

    SELECT
        id,
        total_amount,
        payment_method,
        payment_status,
        order_status,
        created_at

    FROM orders

    WHERE id = :order_id
      AND user_id = :user_id

    LIMIT 1

";


$stmt =
    $db->prepare($sql);


$stmt->execute([

    'order_id' =>
    $orderId,

    'user_id' =>
    $_SESSION['user_id']

]);


$order =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );


if (!$order) {

    unset(
        $_SESSION['last_order_id']
    );

    header("Location: index.php");
    exit;
}
unset(
    $_SESSION['last_order_id']
);

$paymentMethod =
    $order['payment_method'];


if ($paymentMethod === 'stripe') {

    $paymentMethodText = 'Stripe';
} elseif ($paymentMethod === 'paypal') {

    $paymentMethodText = 'PayPal';
} elseif ($paymentMethod === 'cash_on_delivery') {

    $paymentMethodText = 'Cash on Delivery';
} else {

    $paymentMethodText = ucfirst(
        str_replace(
            '_',
            ' ',
            $paymentMethod
        )
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Order Confirmed</title>

</head>

<body>

    <?php include "components/header.php"; ?>

    <?php include "components/sidebar.php"; ?>


    <main class="container py-5">

        <div class="row justify-content-center">

            <div class="col-md-8">

                <div
                    class="card border-0
                       shadow-sm
                       rounded-4
                       text-center
                       p-5">

                    <div class="mb-4">

                        <div
                            class="bg-success
                               bg-opacity-10
                               rounded-circle
                               d-inline-flex
                               align-items-center
                               justify-content-center"
                            style="
                            width:110px;
                            height:110px;
                        ">

                            <i
                                class="fa-solid
                                   fa-check
                                   text-success"
                                style="
                                font-size:55px;
                            ">
                            </i>

                        </div>

                    </div>


                    <h1 class="fw-bold text-success">

                        Congratulations!

                    </h1>


                    <h3 class="fw-bold mt-3">

                        Your Order is Confirmed

                    </h3>


                    <p class="text-muted mt-3">

                        Thank you for shopping with us.

                        Your order has been successfully placed.

                    </p>


                    <div
                        class="bg-light
                           rounded-3
                           p-3
                           mt-4">

                        <p class="mb-1 text-muted">

                            Order Number

                        </p>


                        <h4 class="fw-bold mb-0">

                            #<?= htmlspecialchars(
                                    $order['id']
                                ) ?>

                        </h4>

                    </div>


                    <div class="mt-4">

                        <p class="mb-1">

                            <strong>
                                Payment Method:
                            </strong>

                        </p>


                        <p class="text-muted">

                            <?= htmlspecialchars(
                                $paymentMethodText
                            ) ?>

                        </p>

                    </div>


                    <div class="mt-3">

                        <p class="mb-1">

                            <strong>
                                Payment Status:
                            </strong>

                        </p>


                        <span
                            class="badge
                               bg-success
                               px-3
                               py-2">

                            <?= htmlspecialchars(
                                ucfirst(
                                    $order['payment_status']
                                )
                            ) ?>

                        </span>

                    </div>


                    <div class="mt-4">

                        <a
                            href="order-details.php?id=<?= (int)$order['id'] ?>"
                            class="btn btn-outline-primary
                               rounded-pill
                               px-4 me-2">

                            <i
                                class="fa-solid
                                   fa-eye
                                   me-2">
                            </i>

                            View Order

                        </a>


                        <a
                            href="index.php"
                            class="btn btn-primary
                               rounded-pill
                               px-4">

                            <i
                                class="fa-solid
                                   fa-bag-shopping
                                   me-2">
                            </i>

                            Continue Shopping

                        </a>

                    </div>

                </div>

            </div>

        </div>

    </main>


    <?php include "components/footer.php"; ?>


    <script>
        localStorage.removeItem("cart");
    </script>


</body>

</html>