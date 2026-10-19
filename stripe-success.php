
<?php

session_start();

require_once "connection/dbconnect.php";
require_once __DIR__ . '/config/stripe.php';

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['logged_in'] ?? false) !== true
) {
    header("Location: outh/login.php");
    exit;
}

$sessionId = $_GET['session_id'] ?? '';

if ($sessionId === '') {
    die("Invalid Stripe payment session.");
}

$db = null;

try {
    // 1. Retrieve and verify the Stripe Checkout Session.
    $checkoutSession = \Stripe\Checkout\Session::retrieve($sessionId);

    if (
        $checkoutSession->payment_status !== 'paid' ||
        $checkoutSession->mode !== 'payment' ||
        strtolower((string) $checkoutSession->currency) !== 'inr'
    ) {
        throw new Exception("Stripe payment is not verified as paid.");
    }

    $orderId = (int) ($checkoutSession->metadata->order_id ?? 0);
    $metadataUserId = (int) ($checkoutSession->metadata->user_id ?? 0);
    $sessionUserId = (int) $_SESSION['user_id'];

    if (
        $orderId <= 0 ||
        $metadataUserId !== $sessionUserId
    ) {
        throw new Exception("Invalid Stripe order or customer information.");
    }

    // 2. Connect to the database.
    $database = new Database();
    $db = $database->connect();

    $db->beginTransaction();

    // Lock the order to prevent duplicate stock deductions.
    $orderSql = "
        SELECT
            id,
            user_id,
            total_amount,
            payment_method,
            payment_status,
            order_status
        FROM orders
        WHERE id = :order_id
          AND user_id = :user_id
        LIMIT 1
        FOR UPDATE
    ";

    $orderStmt = $db->prepare($orderSql);
    $orderStmt->execute([
        'order_id' => $orderId,
        'user_id' => $sessionUserId
    ]);

    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("Order not found.");
    }

    if ($order['payment_method'] !== 'stripe') {
        throw new Exception("This order was not created for Stripe payment.");
    }

    // Verify that the paid amount matches the order total.
    $expectedAmount = (int) round((float) $order['total_amount'] * 100);
    $paidAmount = (int) ($checkoutSession->amount_total ?? -1);

    if ($paidAmount !== $expectedAmount) {
        throw new Exception("Stripe payment amount does not match the order total.");
    }

    // 3. If already paid, do not deduct stock again.
    if ($order['payment_status'] !== 'paid') {

        $itemSql = "
            SELECT
                product_id,
                variant_id,
                quantity,
                product_name
            FROM order_items
            WHERE order_id = :order_id
        ";

        $itemStmt = $db->prepare($itemSql);
        $itemStmt->execute(['order_id' => $orderId]);

        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$items) {
            throw new Exception("No items were found for this order.");
        }

        // 4. Deduct stock from the correct table.
        foreach ($items as $item) {

            $productId = (int) $item['product_id'];
            $variantId = (int) ($item['variant_id'] ?? 0);
            $quantity = (int) $item['quantity'];

            if ($productId <= 0 || $quantity <= 0) {
                throw new Exception("Invalid order item.");
            }

            if ($variantId > 0) {

                // Variant order: deduct from product_variants.
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
                    'quantity' => $quantity,
                    'variant_id' => $variantId,
                    'product_id' => $productId
                ]);

            } else {

                // Regular product order: deduct from products.
                $stockSql = "
                    UPDATE products
                    SET stock = stock - :quantity
                    WHERE id = :product_id
                      AND status = 1
                      AND stock >= :quantity
                ";

                $stockStmt = $db->prepare($stockSql);
                $stockStmt->execute([
                    'quantity' => $quantity,
                    'product_id' => $productId
                ]);
            }

            if ($stockStmt->rowCount() !== 1) {
                throw new Exception(
                    "Insufficient stock or invalid item: " .
                    $item['product_name']
                );
            }
        }

        // 5. Mark the order paid in the same transaction.
        $updateSql = "
            UPDATE orders
            SET payment_status = 'paid',
                order_status = 'confirmed'
            WHERE id = :order_id
              AND user_id = :user_id
              AND payment_status <> 'paid'
        ";

        $updateStmt = $db->prepare($updateSql);
        $updateStmt->execute([
            'order_id' => $orderId,
            'user_id' => $sessionUserId
        ]);

        if ($updateStmt->rowCount() !== 1) {
            throw new Exception("Unable to update the order payment status.");
        }
    }

    $db->commit();

    $_SESSION['last_order_id'] = $orderId;

    unset(
        $_SESSION['stripe_order_id'],
        $_SESSION['stripe_session_id']
    );

    header("Location: order-confirmation.php");
    exit;

} catch (Exception $e) {

    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log("Stripe success processing error: " . $e->getMessage());

    http_response_code(400);
    die(
        "Payment verification or order processing failed. " .
        "Please contact support with your order ID."
    );
}
