<?php

session_start();

require_once "connection/dbconnect.php";


if (
    !isset($_SESSION['user_id']) ||
    $_SESSION['logged_in'] !== true
) {
    header("Location: outh/login.php");
    exit;
}


if (
    !isset($_GET['id']) ||
    !is_numeric($_GET['id'])
) {
    header("Location: orders.php");
    exit;
}

$orderId = (int) $_GET['id'];


$database = new Database();
$db = $database->connect();


$orderSql = "
    SELECT
        id,
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
        shipping_pincode,
        created_at
    FROM orders
    WHERE id = :order_id
      AND user_id = :user_id
    LIMIT 1
";

$orderStmt = $db->prepare($orderSql);

$orderStmt->execute([
    'order_id' => $orderId,
    'user_id' => $_SESSION['user_id']
]);

$order = $orderStmt->fetch(PDO::FETCH_ASSOC);


if (!$order) {

    header("Location: orders.php");
    exit;
}


$itemSql = "
    SELECT
        id,
        product_id,
        product_name,
        product_image,
        price,
        quantity,
        subtotal
    FROM order_items
    WHERE order_id = :order_id
    ORDER BY id ASC
";

$itemStmt = $db->prepare($itemSql);

$itemStmt->execute([
    'order_id' => $orderId
]);

$orderItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

?>


<?php include "components/header.php"; ?>

<?php include "components/sidebar.php"; ?>


<main class="container py-5">


    <div
        class="d-flex
               justify-content-between
               align-items-center
               mb-4">

        <div>

            <h2 class="fw-bold mb-1">

                <i
                    class="fa-solid
                           fa-box
                           text-primary
                           me-2"></i>

                Order #<?= htmlspecialchars($order['id']) ?>

            </h2>


            <p class="text-muted mb-0">

                Order placed on

                <?= date(
                    'd M Y, h:i A',
                    strtotime($order['created_at'])
                ) ?>

            </p>

        </div>


        <a
            href="orders.php"
            class="btn btn-outline-primary
                   rounded-pill
                   px-4">

            <i
                class="fa-solid
                       fa-arrow-left
                       me-2"></i>

            Back to Orders

        </a>

    </div>


    <div
        class="card border-0
               shadow-sm
               rounded-4
               mb-4">

        <div class="card-body p-4">

            <div
                class="row
                       align-items-center
                       g-3">

                <!-- ORDER STATUS -->

                <div class="col-md-4">

                    <small class="text-muted d-block">

                        Order Status

                    </small>


                    <?php

                    $orderStatus =
                        strtolower(
                            $order['order_status']
                        );

                    $orderStatusClass =
                        'bg-secondary';

                    if (
                        $orderStatus === 'confirmed'
                    ) {

                        $orderStatusClass =
                            'bg-success';
                    } elseif (
                        $orderStatus === 'pending'
                    ) {

                        $orderStatusClass =
                            'bg-warning text-dark';
                    } elseif (
                        $orderStatus === 'processing'
                    ) {

                        $orderStatusClass =
                            'bg-info text-dark';
                    } elseif (
                        $orderStatus === 'shipped'
                    ) {

                        $orderStatusClass =
                            'bg-primary';
                    } elseif (
                        $orderStatus === 'delivered'
                    ) {

                        $orderStatusClass =
                            'bg-success';
                    } elseif (
                        $orderStatus === 'cancelled'
                    ) {

                        $orderStatusClass =
                            'bg-danger';
                    }

                    ?>


                    <span
                        class="badge
                               <?= $orderStatusClass ?>
                               px-3
                               py-2
                               mt-2">

                        <?= htmlspecialchars(
                            ucfirst(
                                $order['order_status']
                            )
                        ) ?>

                    </span>

                </div>



                <!-- PAYMENT STATUS -->

                <div class="col-md-4">

                    <small class="text-muted d-block">

                        Payment Status

                    </small>


                    <?php

                    $paymentStatus =
                        strtolower(
                            $order['payment_status']
                        );

                    $paymentStatusClass =
                        'bg-secondary';

                    if (
                        $paymentStatus === 'pending'
                    ) {

                        $paymentStatusClass =
                            'bg-warning text-dark';
                    } elseif (
                        $paymentStatus === 'paid'
                    ) {

                        $paymentStatusClass =
                            'bg-success';
                    } elseif (
                        $paymentStatus === 'failed'
                    ) {

                        $paymentStatusClass =
                            'bg-danger';
                    }

                    ?>


                    <span
                        class="badge
                               <?= $paymentStatusClass ?>
                               px-3
                               py-2
                               mt-2">

                        <?= htmlspecialchars(
                            ucfirst(
                                $order['payment_status']
                            )
                        ) ?>

                    </span>

                </div>



                <!-- TOTAL -->

                <div class="col-md-4 text-md-end">

                    <small class="text-muted d-block">

                        Order Total

                    </small>


                    <h4
                        class="fw-bold
                               text-primary
                               mb-0
                               mt-1">

                        ₹<?= number_format(
                                $order['total_amount'],
                                2
                            ) ?>

                    </h4>

                </div>

            </div>

        </div>

    </div>



    <div class="row g-4">


        <div class="col-lg-8">

            <div
                class="card border-0
                       shadow-sm
                       rounded-4">

                <div class="card-body p-4">

                    <h4 class="fw-bold mb-4">

                        <i
                            class="fa-solid
                                   fa-cart-shopping
                                   text-primary
                                   me-2"></i>

                        Order Items

                    </h4>


                    <?php foreach ($orderItems as $item): ?>

                        <div
                            class="d-flex
                                   align-items-center
                                   border-bottom
                                   pb-4
                                   mb-4">

                            <!-- PRODUCT IMAGE -->

                            <img
                                src="<?= htmlspecialchars(
                                            $item['product_image']
                                        ) ?>"
                                alt="<?= htmlspecialchars(
                                            $item['product_name']
                                        ) ?>"
                                style="
                                    width:100px;
                                    height:100px;
                                    object-fit:cover;
                                    border-radius:12px;
                                ">


                            <!-- PRODUCT INFO -->

                            <div
                                class="ms-3
                                       flex-grow-1">

                                <h5 class="fw-bold mb-2">

                                    <?= htmlspecialchars(
                                        $item['product_name']
                                    ) ?>

                                </h5>


                                <p
                                    class="text-muted
                                           mb-1">

                                    Price:

                                    ₹<?= number_format(
                                            $item['price'],
                                            2
                                        ) ?>

                                </p>


                                <p
                                    class="text-muted
                                           mb-0">

                                    Quantity:

                                    <?= (int)$item['quantity'] ?>

                                </p>

                            </div>


                            <!-- SUBTOTAL -->

                            <div class="text-end">

                                <small
                                    class="text-muted
                                           d-block">

                                    Subtotal

                                </small>


                                <strong
                                    class="fs-5">

                                    ₹<?= number_format(
                                            $item['subtotal'],
                                            2
                                        ) ?>

                                </strong>

                            </div>

                        </div>

                    <?php endforeach; ?>


                    <!-- TOTAL -->

                    <div
                        class="d-flex
                               justify-content-between
                               align-items-center">

                        <h5 class="fw-bold mb-0">

                            Total

                        </h5>


                        <h4
                            class="fw-bold
                                   text-primary
                                   mb-0">

                            ₹<?= number_format(
                                    $order['total_amount'],
                                    2
                                ) ?>

                        </h4>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-lg-4">

            <div
                class="card border-0
                       shadow-sm
                       rounded-4
                       mb-4">

                <div class="card-body p-4">

                    <h5 class="fw-bold mb-4">

                        <i
                            class="fa-solid
                                   fa-location-dot
                                   text-primary
                                   me-2"></i>

                        Delivery Address

                    </h5>


                    <h6 class="fw-bold">

                        <?= htmlspecialchars(
                            $order['shipping_name']
                        ) ?>

                    </h6>


                    <p class="text-muted mb-2">

                        <i
                            class="fa-solid
                                   fa-phone
                                   me-2"></i>

                        <?= htmlspecialchars(
                            $order['shipping_phone']
                        ) ?>

                    </p>


                    <p class="text-muted mb-0">

                        <?= nl2br(
                            htmlspecialchars(
                                $order['shipping_address']
                            )
                        ) ?>

                        <br>

                        <?= htmlspecialchars(
                            $order['shipping_city']
                        ) ?>,

                        <?= htmlspecialchars(
                            $order['shipping_state']
                        ) ?>

                        -

                        <?= htmlspecialchars(
                            $order['shipping_pincode']
                        ) ?>

                    </p>

                </div>

            </div>


            <div
                class="card border-0
                       shadow-sm
                       rounded-4">

                <div class="card-body p-4">

                    <h5 class="fw-bold mb-4">

                        <i
                            class="fa-solid
                                   fa-credit-card
                                   text-primary
                                   me-2"></i>

                        Payment

                    </h5>


                    <div
                        class="d-flex
                               justify-content-between
                               mb-3">

                        <span class="text-muted">

                            Method

                        </span>


                        <strong>

                            <?php if ($order['payment_method'] === 'stripe'): ?>

                                Stripe

                            <?php else: ?>

                                Cash on Delivery

                            <?php endif; ?>

                        </strong>

                    </div>


                    <div
                        class="d-flex
                               justify-content-between">

                        <span class="text-muted">

                            Status

                        </span>


                        <span
                            class="badge
                                   <?= $paymentStatusClass ?>
                                   px-3
                                   py-2">

                            <?= htmlspecialchars(
                                ucfirst(
                                    $order['payment_status']
                                )
                            ) ?>

                        </span>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <div class="text-center mt-5">

        <a
            href="index.php"
            class="btn btn-primary
                   rounded-pill
                   px-4">

            <i
                class="fa-solid
                       fa-bag-shopping
                       me-2"></i>

            Continue Shopping

        </a>

    </div>

</main>


<?php include "components/footer.php"; ?>