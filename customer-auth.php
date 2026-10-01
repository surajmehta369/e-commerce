<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (
    empty($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {

    $_SESSION['checkout_redirect'] = 'checkout.php';

    header("Location: /e-commerce/outh/login.php?from=checkout");
    exit;
}

if (
    empty($_SESSION['user_role']) ||
    $_SESSION['user_role'] !== 'customer'
) {

    $_SESSION['checkout_redirect'] = 'checkout.php';

    header("Location: /e-commerce/outh/login.php?from=checkout");
    exit;
}
