<?php

session_start();


if (!isset($_SESSION['last_order_id'])) {

    header("Location: index.php");
    exit;

}


$orderId =
    $_SESSION['last_order_id'];

unset($_SESSION['last_order_id']);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Order Confirmed</title>

</head>

<body>

    <?php include "components/header.php"; ?>

    <?php include "components/sidebar.php"; ?>


    <main class="container py-5">

        <div class="row justify-content-center">

            <div class="col-md-8">

                <div class="card border-0
                       shadow-sm
                       rounded-4
                       text-center
                       p-5">

                    <div class="mb-4">

                        <div class="bg-success
                               bg-opacity-10
                               rounded-circle
                               d-inline-flex
                               align-items-center
                               justify-content-center" style="
                            width:110px;
                            height:110px;
                        ">

                            <i class="fa-solid
                                   fa-check
                                   text-success" style="
                                font-size:55px;
                            "></i>

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


                    <div class="bg-light
                           rounded-3
                           p-3
                           mt-4">

                        <p class="mb-1 text-muted">

                            Order Number

                        </p>


                        <h4 class="fw-bold mb-0">

                            #<?= htmlspecialchars($orderId) ?>

                        </h4>

                    </div>


                    <div class="mt-4">

                        <p class="mb-1">

                            <strong>
                                Payment Method:
                            </strong>

                        </p>

                        <p class="text-muted">

                            Cash on Delivery

                        </p>

                    </div>


                    <div class="mt-4">

                        <a href="index.php" class="btn btn-primary
                               rounded-pill
                               px-4">

                            <i class="fa-solid
                                   fa-bag-shopping
                                   me-2"></i>

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