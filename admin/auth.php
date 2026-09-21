<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    empty($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {
    header("Location: ../outh/login.php");
    exit;
}

if (
    empty($_SESSION['user_role']) ||
    $_SESSION['user_role'] !== 'admin'
) {
    if ($_SESSION['user_role'] === 'vendor') {
        header("Location: ../vendors/index.php");
        exit;
    }

    header("Location: ../index.php");
    exit;
}