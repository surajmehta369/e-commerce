<?php

session_start();

require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();


if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {

    header("Location: ../outh/login.php");
    exit;
}

if (
    !isset($_SESSION['user_role']) ||
    $_SESSION['user_role'] !== 'vendor'
) {

    header("Location: ../index.php");
    exit;
}


$sql = "
    SELECT
        store_name,
        business_name,
        verification_status
    FROM vendor_profiles
    WHERE user_id = :user_id
    LIMIT 1
";

$stmt = $db->prepare($sql);

$stmt->execute([
    "user_id" => $_SESSION['user_id']
]);

$vendor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vendor) {

    session_unset();
    session_destroy();

    header("Location: ../outh/login.php");
    exit;
}

if ($vendor['verification_status'] !== 'approved') {

    session_unset();
    session_destroy();

    header("Location: ../outh/login.php");
    exit;
}