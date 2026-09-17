<?php

require_once "auth.php";
require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

$vendorId = (int) $_SESSION['user_id'];
$sql = "
    SELECT
        o.id AS order_id,
        o.user_id,
        o.total_amount,
        o.payment_method,
        o.payment_status,
        o.order_status,
        o.shipping_name,
        o.shipping_phone,
        o.shipping_city,
        o.shipping_state,
        o.shipping_pincode,
        o.created_at,

        oi.id AS order_item_id,
        oi.product_id,
        oi.product_name,
        oi.product_image,
        oi.price,
        oi.quantity,
        oi.subtotal

    FROM orders o

    INNER JOIN order_items oi
        ON oi.order_id = o.id

    INNER JOIN products p
        ON p.id = oi.product_id

    WHERE p.vendor_id = :vendor_id

    ORDER BY o.id DESC, oi.id ASC
";

$stmt = $db->prepare($sql);

$stmt->execute([
    ':vendor_id' => $vendorId
]);

$orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
$orders = [];

foreach ($orderItems as $item) {

    $orderId = (int) $item['order_id'];

    if (!isset($orders[$orderId])) {

        $orders[$orderId] = [
            'order_id' => $orderId,
            'user_id' => $item['user_id'],
            'total_amount' => $item['total_amount'],
            'payment_method' => $item['payment_method'],
            'payment_status' => $item['payment_status'],
            'order_status' => $item['order_status'],
            'shipping_name' => $item['shipping_name'],
            'shipping_phone' => $item['shipping_phone'],
            'shipping_city' => $item['shipping_city'],
            'shipping_state' => $item['shipping_state'],
            'shipping_pincode' => $item['shipping_pincode'],
            'created_at' => $item['created_at'],
            'items' => []
        ];
    }

    $orders[$orderId]['items'][] = $item;
}

$orderCount = count($orders);

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Vendor Orders</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

</head>

<body>

<div class="container-fluid">

    <div class="row">
        <div class="col-md-2 bg-dark text-white min-vh-100 p-3">

            <h4 class="mb-4">
                Vendor Panel
            </h4>

            <ul class="nav flex-column">

                <li class="nav-item mb-2">

                    <a
                        href="index.php"
                        class="nav-link text-white">

                        Dashboard

                    </a>

                </li>

                <li class="nav-item mb-2">

                    <a
                        href="products.php"
                        class="nav-link text-white">

                        Products

                    </a>

                </li>

                <li class="nav-item mb-2">

                    <a
                        href="orders.php"
                        class="nav-link text-white">

                        Orders

                    </a>

                </li>

                <li class="nav-item mb-2">

                    <a
                        href="profile.php"
                        class="nav-link text-white">

                        Store Profile

                    </a>

                </li>

                <li class="nav-item mt-3">

                    <a
                        href="../outh/logout.php"
                        class="nav-link text-danger">

                        Logout

                    </a>

                </li>

            </ul>

        </div>
        <div class="col-md-10 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">

                <div>

                    <h2 class="mb-1">
                        Orders
                    </h2>

                    <small class="text-muted">
                        Total Orders: <?= $orderCount; ?>
                    </small>

                </div>

            </div>


            <?php if (empty($orders)): ?>

                <div class="card shadow-sm">

                    <div class="card-body text-center py-5">

                        <h5 class="text-muted">
                            No orders found.
                        </h5>

                        <p class="text-muted mb-0">
                            Orders containing your products will appear here.
                        </p>

                    </div>

                </div>

            <?php else: ?>
                <?php foreach ($orders as $order): ?>

                    <div class="card shadow-sm mb-4">

                        <!-- Order Header -->

                        <div class="card-header bg-white">

                            <div class="row align-items-center">

                                <div class="col-md-3">

                                    <strong>
                                        Order #<?= (int) $order['order_id']; ?>
                                    </strong>

                                </div>

                                <div class="col-md-3">

                                    <small class="text-muted">
                                        Date:
                                    </small>

                                    <br>

                                    <?= htmlspecialchars($order['created_at']); ?>

                                </div>

                                <div class="col-md-3">

                                    <small class="text-muted">
                                        Payment:
                                    </small>

                                    <br>

                                    <?= htmlspecialchars(
                                        ucfirst(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $order['payment_method']
                                            )
                                        )
                                    ); ?>

                                </div>

                                <div class="col-md-3">

                                    <small class="text-muted">
                                        Payment Status:
                                    </small>

                                    <br>

                                    <?php
                                    $paymentStatus = strtolower(
                                        $order['payment_status']
                                    );
                                    ?>

                                    <?php if ($paymentStatus === 'paid'): ?>

                                        <span class="badge bg-success">
                                            Paid
                                        </span>

                                    <?php elseif ($paymentStatus === 'failed'): ?>

                                        <span class="badge bg-danger">
                                            Failed
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-warning text-dark">
                                            <?= htmlspecialchars(
                                                ucfirst($paymentStatus)
                                            ); ?>
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>


                        <!-- Order Body -->

                        <div class="card-body">

                            <!-- Customer -->

                            <div class="row mb-4">

                                <div class="col-md-6">

                                    <h6>
                                        Customer
                                    </h6>

                                    <p class="mb-1">
                                        <strong>
                                            <?= htmlspecialchars(
                                                $order['shipping_name']
                                            ); ?>
                                        </strong>
                                    </p>

                                    <p class="mb-1">
                                        <?= htmlspecialchars(
                                            $order['shipping_phone']
                                        ); ?>
                                    </p>

                                </div>

                                <div class="col-md-6">

                                    <h6>
                                        Shipping Address
                                    </h6>

                                    <p class="mb-0">

                                        <?= htmlspecialchars(
                                            $order['shipping_city']
                                        ); ?>,

                                        <?= htmlspecialchars(
                                            $order['shipping_state']
                                        ); ?>

                                        -

                                        <?= htmlspecialchars(
                                            $order['shipping_pincode']
                                        ); ?>

                                    </p>

                                </div>

                            </div>
                            <h6 class="mb-3">
                                Your Products
                            </h6>

                            <div class="table-responsive">

                                <table class="table table-bordered align-middle">

                                    <thead class="table-light">

                                        <tr>

                                            <th>
                                                Product
                                            </th>

                                            <th>
                                                Price
                                            </th>

                                            <th>
                                                Quantity
                                            </th>

                                            <th>
                                                Subtotal
                                            </th>

                                        </tr>

                                    </thead>

                                    <tbody>

                                        <?php
                                        $vendorOrderTotal = 0;
                                        ?>

                                        <?php foreach ($order['items'] as $item): ?>

                                            <?php
                                            $vendorOrderTotal +=
                                                (float) $item['subtotal'];
                                            ?>

                                            <tr>

                                                <td>

                                                    <div class="d-flex align-items-center">

                                                        <?php if (!empty($item['product_image'])): ?>

                                                            <img
                                                                src="../<?= htmlspecialchars(
                                                                    $item['product_image']
                                                                ); ?>"
                                                                alt="Product"
                                                                style="
                                                                    width:60px;
                                                                    height:60px;
                                                                    object-fit:cover;
                                                                "
                                                                class="rounded border me-3">

                                                        <?php endif; ?>

                                                        <div>

                                                            <strong>
                                                                <?= htmlspecialchars(
                                                                    $item['product_name']
                                                                ); ?>
                                                            </strong>

                                                            <br>

                                                            <small class="text-muted">
                                                                Product ID:
                                                                <?= (int) $item['product_id']; ?>
                                                            </small>

                                                        </div>

                                                    </div>

                                                </td>

                                                <td>
                                                    ₹<?= number_format(
                                                        (float) $item['price'],
                                                        2
                                                    ); ?>
                                                </td>

                                                <td>
                                                    <?= (int) $item['quantity']; ?>
                                                </td>

                                                <td>

                                                    <strong>
                                                        ₹<?= number_format(
                                                            (float) $item['subtotal'],
                                                            2
                                                        ); ?>
                                                    </strong>

                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    </tbody>

                                    <tfoot>

                                        <tr>

                                            <th
                                                colspan="3"
                                                class="text-end">

                                                Your Order Total:

                                            </th>

                                            <th>

                                                ₹<?= number_format(
                                                    $vendorOrderTotal,
                                                    2
                                                ); ?>

                                            </th>

                                        </tr>

                                    </tfoot>

                                </table>

                            </div>
                            <div class="mt-3">

                                <strong>
                                    Order Status:
                                </strong>

                                <?php
                                $orderStatus = strtolower(
                                    $order['order_status']
                                );
                                ?>

                                <?php if ($orderStatus === 'confirmed'): ?>

                                    <span class="badge bg-primary">
                                        Confirmed
                                    </span>

                                <?php elseif ($orderStatus === 'processing'): ?>

                                    <span class="badge bg-info text-dark">
                                        Processing
                                    </span>

                                <?php elseif ($orderStatus === 'shipped'): ?>

                                    <span class="badge bg-warning text-dark">
                                        Shipped
                                    </span>

                                <?php elseif ($orderStatus === 'delivered'): ?>

                                    <span class="badge bg-success">
                                        Delivered
                                    </span>

                                <?php elseif ($orderStatus === 'cancelled'): ?>

                                    <span class="badge bg-danger">
                                        Cancelled
                                    </span>

                                <?php else: ?>

                                    <span class="badge bg-secondary">
                                        <?= htmlspecialchars(
                                            ucfirst($orderStatus)
                                        ); ?>
                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>

</div>

</body>

</html>