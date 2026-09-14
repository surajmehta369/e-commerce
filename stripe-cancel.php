<?php

session_start();

require_once "connection/dbconnect.php";

$orderId =
    isset($_GET['order_id'])
    ? (int) $_GET['order_id']
    : 0;


if ($orderId <= 0) {

    header("Location: checkout.php");
    exit;
}

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

    UPDATE orders

    SET
        order_status = 'cancelled',
        payment_status = 'failed'

    WHERE id = :order_id
      AND user_id = :user_id
      AND payment_method = 'stripe'
      AND payment_status = 'pending'

";


$stmt =
    $db->prepare($sql);


$stmt->execute([

    'order_id' =>
    $orderId,

    'user_id' =>
    $_SESSION['user_id']

]);


unset(
    $_SESSION['stripe_order_id']
);

unset(
    $_SESSION['stripe_session_id']
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Payment Cancelled</title>

</head>

<body>

    <?php include "components/header.php"; ?>

    <?php include "components/sidebar.php"; ?>


    <main class="container py-5">

        <div class="row justify-content-center">

            <div class="col-md-7">

                <div
                    class="card border-0
                       shadow-sm
                       rounded-4
                       text-center
                       p-5">

                    <div class="mb-4">

                        <div
                            class="bg-warning
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
                                   fa-xmark
                                   text-warning"
                                style="
                                font-size:55px;
                            ">
                            </i>

                        </div>

                    </div>


                    <h2 class="fw-bold">

                        Payment Cancelled

                    </h2>


                    <p class="text-muted mt-3">

                        Your Stripe payment was cancelled.

                        Your order has not been confirmed.

                    </p>


                    <div class="mt-4">

                        <a
                            href="checkout.php"
                            class="btn btn-primary
                               rounded-pill
                               px-4">

                            <i
                                class="fa-solid
                                   fa-credit-card
                                   me-2">
                            </i>

                            Try Payment Again

                        </a>


                        <a
                            href="index.php"
                            class="btn btn-outline-secondary
                               rounded-pill
                               px-4 ms-2">

                            Continue Shopping

                        </a>

                    </div>

                </div>

            </div>

        </div>

    </main>


    <?php include "components/footer.php"; ?>

</body>

</html>