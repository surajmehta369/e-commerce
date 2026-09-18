<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {

    if ($_SESSION['user_role'] === 'vendor') {
        header("Location: vendors/index.php");
        exit;
    }

    if ($_SESSION['user_role'] === 'admin') {
        header("Location: admin/index.php");
        exit;
    }

    // Customer is allowed to continue.
}