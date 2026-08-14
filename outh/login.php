<?php
session_start();
require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

$message = "";
$messageType = "";


if($_SERVER['REQUEST_METHOD']==='POST'){
        $email = trim($_POST['email'] ?? "");
        $password = $_POST['password'] ?? "";


        if($email === "" || $password === ""){
            $message = "Email and Password is required";
            $messageType = "danger";
        }elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)){
            $message = "Please enter a valid email";
            $messageType = "danger";
        }else{
            $sql = "select id,name,email,password,role,status from users where email = :email LIMIT 1";
            $sql = $db->prepare($sql);
            $sql->execute(["email"=> $email]);

            $user = $sql->fetch();

                    if (!$user) {

            $message = "Invalid email or password.";
            $messageType = "danger";

        } elseif ($user['status'] != 1) {

            $message = "Your account is inactive.";
            $messageType = "danger";

        } elseif (!password_verify($password, $user['password'])) {

            $message = "Invalid email or password.";
            $messageType = "danger";

        } else {

        
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['logged_in'] = true;

            $sql = "
                UPDATE users
                SET last_login_at = NOW()
                WHERE id = :id
            ";

        
            if ($user['role'] === 'admin') {

                header("Location: ../admin/index.php");
                exit;

            } elseif ($user['role'] === 'vendor') {

                header("Location: ../vendor/index.php");
                exit;

            } else {

                header("Location: ../index.php");
                exit;
            }
        }
        }

}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Login</title>

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

            background: whitesmoke  ;
            color: #ffffff;

            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI",
                         Roboto, Helvetica, Arial, sans-serif;
        }

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

            transition: 0.2s ease;
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
            transition: 0.2s ease;
        }

        button:hover {
            background: #3d8df5;
            box-shadow: 0 8px 20px rgba(79, 156, 255, 0.2);
        }

        button:active {
            transform: translateY(1px);
        }

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
            transition: 0.2s ease;
        }

        .login-text a:hover {
            color: #75b4ff;
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .register-container {
                padding: 25px 20px;
            }

            h2 {
                font-size: 24px;
            }
        }
    </style>

</head>

<body>

    <div class="register-container">

        <h2>Welcome Back</h2>

        <p class="subtitle">
            Login to your account to continue.
        </p>

        <?php if ($message !== ""): ?>

            <div class="message <?= htmlspecialchars($messageType) ?>">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>

        <form method="POST">

            <div class="form-group">
                <label for="email">Email</label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Enter your email"
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    required
                >
            </div>

            <button type="submit">
                Login
            </button>

        </form>

        <p class="login-text">
            Don't have an account?
            <a href="register.php">Create an account</a>
        </p>

    </div>

</body>
</html>


