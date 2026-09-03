<?php if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header("Location: outh/login.php");
    exit;
}
require_once "connection/dbconnect.php";
$database = new Database();
$db = $database->connect();
$userId = (int) $_SESSION['user_id'];
$message = "";
$messageType = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? "";
    $newPassword = $_POST['new_password'] ?? "";
    $confirmPassword = $_POST['confirm_password'] ?? "";
    if ($currentPassword === "") {
        $message = "Current password is required.";
        $messageType = "danger";
    } elseif ($newPassword === "") {
        $message = "New password is required.";
        $messageType = "danger";
    } elseif ($confirmPassword === "") {
        $message = "Please confirm your new password.";
        $messageType = "danger";
    } elseif ($newPassword !== $confirmPassword) {
        $message = "New passwords do not match.";
        $messageType = "danger";
    } elseif (strlen($newPassword) < 8) {
        $message = "Password must be at least 8 characters.";
        $messageType = "danger";
    } elseif (strlen($newPassword) > 72) {
        $message = "Password cannot be longer than 72 characters.";
        $messageType = "danger";
    } elseif (!preg_match('/[A-Z]/', $newPassword)) {
        $message = "Password must contain at least one uppercase letter.";
        $messageType = "danger";
    } elseif (!preg_match('/[a-z]/', $newPassword)) {
        $message = "Password must contain at least one lowercase letter.";
        $messageType = "danger";
    } elseif (!preg_match('/[0-9]/', $newPassword)) {
        $message = "Password must contain at least one number.";
        $messageType = "danger";
    } elseif (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
        $message = "Password must contain at least one special character.";
        $messageType = "danger";
    } else {
        try {
            $sql = " SELECT password FROM users WHERE id = :user_id LIMIT 1 ";
            $stmt = $db->prepare($sql);
            $stmt->execute([':user_id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $_SESSION = [];
                session_destroy();
                header("Location: outh/login.php");
                exit;
            }
            if (!password_verify($currentPassword, $user['password'])) {
                $message = "Current password is incorrect.";
                $messageType = "danger";
            } elseif (password_verify($newPassword, $user['password'])) {
                $message = "Your new password must be different from your current password.";
                $messageType = "danger";
            } else {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $sql = " UPDATE users SET password = :password WHERE id = :user_id LIMIT 1 ";
                $stmt = $db->prepare($sql);
                $result = $stmt->execute([':password' => $hashedPassword, ':user_id' => $userId]);
                if ($result) {
                    $message = "Your password has been changed successfully.";
                    $messageType = "success";
                    $currentPassword = "";
                    $newPassword = "";
                    $confirmPassword = "";
                } else {
                    $message = "Unable to change your password. Please try again.";
                    $messageType = "danger";
                }
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $message = "Something went wrong. Please try again.";
            $messageType = "danger";
        }
    }
}
include "components/header.php";
include "components/sidebar.php"; ?> <main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="mb-4">
                <a
                    href="account.php"
                    class="text-decoration-none">

                    <i class="fa-solid fa-arrow-left me-2"></i>

                    Back to Account

                </a>

                <h1 class="fw-bold mt-3 mb-2">
                    Change Password
                </h1>

                <p class="text-muted mb-0">
                    Update your password to keep your account secure.
                </p>

            </div>

            <div class="card border-0 shadow-sm rounded-4">

                <div class="card-body p-4 p-md-5">

                    <?php if ($message !== ""): ?>

                        <div
                            class="alert
                            <?= $messageType === 'success'
                                ? 'alert-success'
                                : 'alert-danger'
                            ?>
                        "
                            role="alert">

                            <?= htmlspecialchars($message) ?>

                        </div>

                    <?php endif; ?>


                    <form method="POST">

                        <div class="mb-4">

                            <label
                                for="current_password"
                                class="form-label fw-semibold">
                                Current Password
                            </label>

                            <input
                                type="password"
                                id="current_password"
                                name="current_password"
                                class="form-control"
                                placeholder="Enter your current password"
                                autocomplete="current-password"
                                required>

                        </div>

                        <div class="mb-4">

                            <label
                                for="new_password"
                                class="form-label fw-semibold">
                                New Password
                            </label>

                            <input
                                type="password"
                                id="new_password"
                                name="new_password"
                                class="form-control"
                                placeholder="Create a new password"
                                autocomplete="new-password"
                                required>

                            <div class="form-text">

                                Password must contain:

                                <ul class="mb-0 mt-2">

                                    <li>At least 8 characters</li>

                                    <li>One uppercase letter</li>

                                    <li>One lowercase letter</li>

                                    <li>One number</li>

                                    <li>One special character</li>

                                </ul>

                            </div>

                        </div>

                        <div class="mb-4">

                            <label
                                for="confirm_password"
                                class="form-label fw-semibold">
                                Confirm New Password
                            </label>

                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                class="form-control"
                                placeholder="Confirm your new password"
                                autocomplete="new-password"
                                required>

                        </div>
                        <button
                            type="submit"
                            class="btn btn-primary w-100 rounded-pill py-2">

                            <i class="fa-solid fa-lock me-2"></i>

                            Change Password

                        </button>


                    </form>

                </div>

            </div>
            <div class="card border-0 bg-light rounded-4 mt-4">

                <div class="card-body p-4">

                    <h6 class="fw-bold">

                        <i class="fa-solid fa-shield-halved text-success me-2"></i>

                        Security Tip

                    </h6>

                    <p class="text-muted small mb-0">

                        Never share your password with anyone.
                        Use a unique password that you don't use
                        on other websites.

                    </p>

                </div>

            </div>

        </div>

    </div>

</main> <?php include "components/footer.php"; ?>