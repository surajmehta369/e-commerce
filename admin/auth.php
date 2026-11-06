<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!function_exists('isAjaxRequest')) {
    function isAjaxRequest(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }
}
if (!function_exists('authDeny')) {
    function authDeny(string $location): void
    {
        if (isAjaxRequest()) {
            http_response_code(401);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Unauthorized';
            exit;
        }

        header("Location: $location");
        exit;
    }
}

if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    authDeny('../outh/login.php');
}

$authRole = $_SESSION['user_role'] ?? '';

if ($authRole !== 'admin') {
    if ($authRole === 'vendor') {
        authDeny('../vendors/index.php');
    }

    authDeny('../index.php');
}
