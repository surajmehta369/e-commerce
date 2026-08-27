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


$database = new Database();
$db = $database->connect();


$sql = "
    SELECT
        id,
        total_amount,
        payment_method,
        payment_status,
        order_status,
        created_at
    FROM orders
    WHERE user_id = :user_id
    ORDER BY created_at DESC
";

$stmt = $db->prepare($sql);

$stmt->execute([
    'user_id' => $_SESSION['user_id']
]);

$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>


<?php include "components/header.php"; ?>

<?php include "components/sidebar.php"; ?>


<main class="container py-5">


    <div class="d-flex
                justify-content-between
                align-items-center
                mb-4">

        <div>

            <h2 class="fw-bold mb-1">

                <i
                    class="fa-solid
                           fa-box
                           text-primary
                           me-2"
                ></i>

                My Orders

            </h2>

            <p class="text-muted mb-0">

                View and manage your orders.

            </p>

        </div>


        <a
            href="index.php"
            class="btn btn-primary
                   rounded-pill
                   px-4"
        >

            <i
                class="fa-solid
                       fa-bag-shopping
                       me-2"
            ></i>

            Continue Shopping

        </a>

    </div>

    <?php if (count($orders) === 0): ?>

        <div class="row justify-content-center">

            <div class="col-md-7">

                <div
                    class="card border-0
                           shadow-sm
                           rounded-4
                           text-center
                           p-5"
                >

                    <div class="mb-4">

                        <div
                            class="bg-light
                                   rounded-circle
                                   d-inline-flex
                                   align-items-center
                                   justify-content-center"
                            style="
                                width:110px;
                                height:110px;
                            "
                        >

                            <i
                                class="fa-solid
                                       fa-box-open
                                       text-primary"
                                style="
                                    font-size:50px;
                                "
                            ></i>

                        </div>

                    </div>


                    <h3 class="fw-bold">

                        No Orders Yet

                    </h3>


                    <p class="text-muted mb-4">

                        You haven't placed any orders yet.
                        Start shopping and find something you love!

                    </p>


                    <a
                        href="index.php"
                        class="btn btn-primary
                               rounded-pill
                               px-4"
                    >

                        <i
                            class="fa-solid
                                   fa-bag-shopping
                                   me-2"
                        ></i>

                        Start Shopping

                    </a>

                </div>

            </div>

        </div>


    <?php else: ?>


        <div class="row g-4">

            <?php foreach ($orders as $order): ?>

                <div class="col-12">

                    <div
                        class="card border-0
                               shadow-sm
                               rounded-4"
                    >

                        <div class="card-body p-4">
                            <div
                                class="d-flex
                                       flex-wrap
                                       justify-content-between
                                       align-items-center
                                       mb-3"
                            >

                                <div>

                                    <span
                                        class="text-muted
                                               small"
                                    >

                                        Order Number

                                    </span>


                                    <h5 class="fw-bold mb-1">

                                        #<?= htmlspecialchars(
                                            $order['id']
                                        ) ?>

                                    </h5>


                                    <small
                                        class="text-muted"
                                    >

                                        <?= date(
                                            'd M Y, h:i A',
                                            strtotime(
                                                $order['created_at']
                                            )
                                        ) ?>

                                    </small>

                                </div>


                                <!-- ORDER STATUS -->

                                <div>

                                    <?php

                                    $status =
                                        strtolower(
                                            $order['order_status']
                                        );

                                    $statusClass =
                                        'bg-secondary';

                                    if (
                                        $status === 'confirmed'
                                    ) {

                                        $statusClass =
                                            'bg-success';

                                    } elseif (
                                        $status === 'pending'
                                    ) {

                                        $statusClass =
                                            'bg-warning text-dark';

                                    } elseif (
                                        $status === 'cancelled'
                                    ) {

                                        $statusClass =
                                            'bg-danger';

                                    }

                                    ?>

                                    <span
                                        class="badge
                                               <?= $statusClass ?>
                                               px-3
                                               py-2"
                                    >

                                        <?= htmlspecialchars(
                                            ucfirst(
                                                $order['order_status']
                                            )
                                        ) ?>

                                    </span>

                                </div>

                            </div>



                            <hr>


                            <div class="row g-3">

                                <!-- TOTAL -->

                                <div class="col-md-4">

                                    <div
                                        class="bg-light
                                               rounded-3
                                               p-3
                                               h-100"
                                    >

                                        <small
                                            class="text-muted"
                                        >

                                            Total Amount

                                        </small>


                                        <h5
                                            class="fw-bold
                                                   text-primary
                                                   mb-0
                                                   mt-1"
                                        >

                                            ₹<?= number_format(
                                                $order['total_amount'],
                                                2
                                            ) ?>

                                        </h5>

                                    </div>

                                </div>



                                <!-- PAYMENT -->

                                <div class="col-md-4">

                                    <div
                                        class="bg-light
                                               rounded-3
                                               p-3
                                               h-100"
                                    >

                                        <small
                                            class="text-muted"
                                        >

                                            Payment Method

                                        </small>


                                        <h6
                                            class="fw-bold
                                                   mb-0
                                                   mt-2"
                                        >

                                            <i
                                                class="fa-solid
                                                       fa-money-bill-wave
                                                       text-success
                                                       me-1"
                                            ></i>

                                            Cash on Delivery

                                        </h6>

                                    </div>

                                </div>



                                <!-- PAYMENT STATUS -->

                                <div class="col-md-4">

                                    <div
                                        class="bg-light
                                               rounded-3
                                               p-3
                                               h-100"
                                    >

                                        <small
                                            class="text-muted"
                                        >

                                            Payment Status

                                        </small>


                                        <?php

                                        $paymentStatus =
                                            strtolower(
                                                $order['payment_status']
                                            );

                                        $paymentClass =
                                            'bg-secondary';

                                        if (
                                            $paymentStatus === 'pending'
                                        ) {

                                            $paymentClass =
                                                'bg-warning text-dark';

                                        } elseif (
                                            $paymentStatus === 'paid'
                                        ) {

                                            $paymentClass =
                                                'bg-success';

                                        }

                                        ?>


                                        <div class="mt-2">

                                            <span
                                                class="badge
                                                       <?= $paymentClass ?>
                                                       px-3
                                                       py-2"
                                            >

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


                            <div class="text-end mt-4">

                                <a
                                    href="order-details.php?id=<?= (int)$order['id'] ?>"
                                    class="btn btn-outline-primary
                                           rounded-pill
                                           px-4"
                                >

                                    <i
                                        class="fa-solid
                                               fa-eye
                                               me-2"
                                    ></i>

                                    View Order

                                </a>

                            </div>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>


    <?php endif; ?>

</main>


<?php include "components/footer.php"; ?>
