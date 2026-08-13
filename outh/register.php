<?php

require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

$message = "";
$messageType = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Get form values
    $name = trim($_POST['name'] ?? "");
    $email = trim($_POST['email'] ?? "");
    $password = $_POST['password'] ?? "";
    $confirmPassword = $_POST['confirm_password'] ?? "";
    if ($name === "") {

        $message = "Name is required.";
        $messageType = "danger";

    } elseif (mb_strlen($name) < 2) {

        $message = "Name must be at least 2 characters.";
        $messageType = "danger";

    } elseif (mb_strlen($name) > 50) {

        $message = "Name cannot be longer than 50 characters.";
        $messageType = "danger";

    } elseif (!preg_match("/[a-zA-Z]/", $name)) {

        $message = "Name must contain at least one letter.";
        $messageType = "danger";

    } elseif (!preg_match("/^[a-zA-Z\s'-]+$/", $name)) {

        $message = "Name can only contain letters, spaces, apostrophes, and hyphens.";
        $messageType = "danger";

    } elseif (preg_match('/\d/', $name)) {

        $message = "Name cannot contain numbers.";
        $messageType = "danger";

    }

    elseif ($email === "") {

        $message = "Email is required.";
        $messageType = "danger";

    } elseif (mb_strlen($email) > 254) {

        $message = "Email address is too long.";
        $messageType = "danger";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $messageType = "danger";

    }


    elseif ($password === "") {

        $message = "Password is required.";
        $messageType = "danger";

    } elseif (strlen($password) < 8) {

        $message = "Password must be at least 8 characters.";
        $messageType = "danger";

    } elseif (strlen($password) > 72) {

        $message = "Password cannot be longer than 72 characters.";
        $messageType = "danger";

    } elseif (!preg_match('/[A-Z]/', $password)) {

        $message = "Password must contain at least one uppercase letter.";
        $messageType = "danger";

    } elseif (!preg_match('/[a-z]/', $password)) {

        $message = "Password must contain at least one lowercase letter.";
        $messageType = "danger";

    } elseif (!preg_match('/[0-9]/', $password)) {

        $message = "Password must contain at least one number.";
        $messageType = "danger";

    } elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {

        $message = "Password must contain at least one special character.";
        $messageType = "danger";

    }

    elseif ($confirmPassword === "") {

        $message = "Please confirm your password.";
        $messageType = "danger";

    } elseif ($password !== $confirmPassword) {

        $message = "Passwords do not match.";
        $messageType = "danger";

    }


    else {

        try {


            $sql = "
                SELECT id
                FROM users
                WHERE email = :email    
                LIMIT 1
            ";

            $stmt = $db->prepare($sql);

            $stmt->execute([
                ":email" => $email
            ]);

            $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);


            if ($existingUser) {

                $message = "An account with this email already exists.";
                $messageType = "danger";

            } else {

                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );


                $sql = "
                    INSERT INTO users
                    (
                        name,
                        email,
                        password,
                        role,
                        status
                    )
                    VALUES
                    (
                        :name,
                        :email,
                        :password,
                        'customer',
                        1
                    )
                ";

                $stmt = $db->prepare($sql);

                $result = $stmt->execute([
                    ":name" => $name,
                    ":email" => $email,
                    ":password" => $hashedPassword
                ]);

                if ($result) {

                    $message = "Registration successful. You can now login.";
                    $messageType = "success";

                    // Clear form values after successful registration
                    $name = "";
                    $email = "";

                } else {

                    $message = "Something went wrong. Please try again.";
                    $messageType = "danger";
                }
            }

        } catch (PDOException $e) {

            $message = "Something went wrong while creating your account. Please try again.";
            $messageType = "danger";

            // Log the actual error on the server
            error_log($e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Register</title>

</head>

<body>

    <div class="register-container">

        <h2>Create Account</h2>

        <p class="subtitle">
            Create your account to get started.
        </p>

        <?php if ($message !== ""): ?>

            <div class="message <?= htmlspecialchars($messageType) ?>">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>

        <form method="POST">

            <div class="form-group">
                <label for="name">Name</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    placeholder="Enter your name"
                    value="<?= htmlspecialchars($_POST["name"] ?? "") ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Enter your email"
                    value="<?= htmlspecialchars($_POST["email"] ?? "") ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Create a password"
                    required
                >
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    placeholder="Confirm your password"
                    required
                >
            </div>

            <button type="submit">
                Create Account
            </button>

        </form>

        <p class="login-text">
            Already have an account?
            <a href="login.php">Login</a>
        </p>

    </div>

</body>

</html>

<style>
    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;

        background: whitesmoke;
        color: #ffffff;

        font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI",
                     Roboto, Helvetica, Arial, sans-serif;
    }

    /* Register Card */
    .register-container {
        width: 100%;
        max-width: 430px;
        padding: 35px;

        background: #1b2530;
        border: 1px solid #2b3947;
        border-radius: 16px;

        box-shadow:
            0 20px 50px rgba(0, 0, 0, 0.45),
            0 0 0 1px rgba(255, 255, 255, 0.02);
    }

    h2 {
        margin-bottom: 8px;

        color: #ffffff;
        font-size: 28px;
        font-weight: 700;
        text-align: center;
        letter-spacing: -0.5px;
    }

    .subtitle {
        margin-bottom: 28px;

        color: #9ca9b6;
        font-size: 14px;
        text-align: center;
    }

    .form-group {
        margin-bottom: 20px;
    }

    label {
        display: block;
        margin-bottom: 8px;

        color: #e8edf2;
        font-size: 14px;
        font-weight: 600;
    }

    input {
        width: 100%;
        height: 48px;
        padding: 0 14px;

        background: #131a22;
        color: #ffffff;

        border: 1px solid #344352;
        border-radius: 9px;

        outline: none;
        font-size: 15px;

        transition:
            border-color 0.2s ease,
            box-shadow 0.2s ease,
            background 0.2s ease;
    }

    input::placeholder {
        color: #687684;
    }

    input:hover {
        border-color: #465767;
    }

    input:focus {
        border-color: #4f9cff;

        background: #151e27;

        box-shadow: 0 0 0 3px rgba(79, 156, 255, 0.15);
    }

    /* Button */
    button {
        width: 100%;
        height: 48px;
        margin-top: 5px;

        border: none;
        border-radius: 9px;

        background: #4f9cff;
        color: #ffffff;

        font-size: 15px;
        font-weight: 600;

        cursor: pointer;

        transition:
            background 0.2s ease,
            transform 0.15s ease,
            box-shadow 0.2s ease;
    }

    button:hover {
        background: #3d8df5;
        box-shadow: 0 8px 20px rgba(79, 156, 255, 0.2);
    }

    button:active {
        transform: translateY(1px);
    }

    /* Messages */
    .message {
        margin-bottom: 20px;
        padding: 12px 14px;

        border-radius: 8px;

        font-size: 14px;
        line-height: 1.5;
    }

    .message.success {
        background: rgba(34, 197, 94, 0.12);
        border: 1px solid rgba(34, 197, 94, 0.3);
        color: #6ee7a0;
    }

    .message.error {
        background: rgba(239, 68, 68, 0.12);
        border: 1px solid rgba(239, 68, 68, 0.3);
        color: #ff8d8d;
    }

    /* Login link */
    .login-text {
        margin-top: 24px;

        color: #9ca9b6;
        font-size: 14px;
        text-align: center;
    }

    .login-text a {
        color: #4f9cff;
        font-weight: 600;
        text-decoration: none;

        transition: color 0.2s ease;
    }

    .login-text a:hover {
        color: #75b4ff;
        text-decoration: underline;
    }

    /* Mobile */
    @media (max-width: 480px) {
        .register-container {
            padding: 25px 20px;
        }

        h2 {
            font-size: 24px;
        }
    }
</style>