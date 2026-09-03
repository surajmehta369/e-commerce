<?php

require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

$message = "";
$messageType = "";

$name = "";
$email = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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
    } elseif ($email === "") {

        $message = "Email is required.";
        $messageType = "danger";
    } elseif (mb_strlen($email) > 254) {

        $message = "Email address is too long.";
        $messageType = "danger";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $messageType = "danger";
    } elseif ($password === "") {

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

                $stmt->execute([
                    ":name" => $name,
                    ":email" => $email,
                    ":password" => $hashedPassword
                ]);


                $message = "Registration successful. You can now login.";
                $messageType = "success";

                $name = "";
                $email = "";
            }
        } catch (PDOException $e) {

            $message =
                "Something went wrong while creating your account. Please try again.";

            $messageType = "danger";

            error_log($e->getMessage());
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
        content="width=device-width, initial-scale=1.0">

    <title>Register</title>

    <script
        src="https://code.jquery.com/jquery-3.7.1.min.js">
    </script>


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

            font-family:
                Inter,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                Roboto,
                Helvetica,
                Arial,
                sans-serif;
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

            box-shadow:
                0 0 0 3px rgba(79, 156, 255, 0.15);
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

            transition:
                background 0.2s ease,
                transform 0.15s ease,
                box-shadow 0.2s ease;
        }


        button:hover {

            background: #3d8df5;

            box-shadow:
                0 8px 20px rgba(79, 156, 255, 0.2);
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

            background:
                rgba(34, 197, 94, 0.12);

            border:
                1px solid rgba(34, 197, 94, 0.3);

            color: #6ee7a0;
        }


        .message.danger {

            background:
                rgba(239, 68, 68, 0.12);

            border:
                1px solid rgba(239, 68, 68, 0.3);

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

            transition: color 0.2s ease;
        }


        .login-text a:hover {

            color: #75b4ff;

            text-decoration: underline;
        }

        .field-error {

            display: none;

            margin-top: 6px;

            color: #ff8d8d;

            font-size: 13px;

            line-height: 1.4;
        }


        .field-error.show {

            display: block;
        }


        .input-error {

            border-color: #ef4444 !important;

            box-shadow:
                0 0 0 3px rgba(239, 68, 68, 0.12) !important;
        }


        .input-success {

            border-color: #22c55e !important;

            box-shadow:
                0 0 0 3px rgba(34, 197, 94, 0.10) !important;
        }

        .password-rules {

            display: none;

            margin-top: 10px;

            padding: 10px 12px;

            background: #131a22;

            border: 1px solid #2b3947;

            border-radius: 8px;

            font-size: 13px;
        }


        .password-rules.show {

            display: block;
        }


        .password-rule {

            margin: 5px 0;

            color: #9ca9b6;
        }


        .password-rule.valid {

            color: #6ee7a0;
        }


        .password-rule i {

            width: 18px;

            margin-right: 5px;
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


        <h2>Create Account</h2>


        <p class="subtitle">
            Create your account to get started.
        </p>
        <form method="POST" id="registerForm">


            <!-- NAME -->

            <div class="form-group">

                <label for="name">Name</label>

                <input
                    type="text"
                    id="name"
                    name="name"
                    placeholder="Enter your name">

                <div id="name-error" class="field-error"></div>

            </div>



            <!-- EMAIL -->

            <div class="form-group">

                <label for="email">Email</label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Enter your email">

                <div id="email-error" class="field-error"></div>

            </div>


            <!-- PASSWORD -->

            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Create a password"
                    autocomplete="new-password">


                <div
                    id="password-rules"
                    class="password-rules">

                    <div
                        class="password-rule"
                        id="rule-length">
                        <i class="fa-solid fa-circle"></i>
                        8–72 characters
                    </div>


                    <div
                        class="password-rule"
                        id="rule-uppercase">
                        <i class="fa-solid fa-circle"></i>
                        At least one uppercase letter
                    </div>


                    <div
                        class="password-rule"
                        id="rule-lowercase">
                        <i class="fa-solid fa-circle"></i>
                        At least one lowercase letter
                    </div>


                    <div
                        class="password-rule"
                        id="rule-number">
                        <i class="fa-solid fa-circle"></i>
                        At least one number
                    </div>


                    <div
                        class="password-rule"
                        id="rule-special">
                        <i class="fa-solid fa-circle"></i>
                        At least one special character
                    </div>

                </div>


                <div
                    id="password-error"
                    class="field-error"></div>

            </div>


            <!-- CONFIRM PASSWORD -->

            <div class="form-group">

                <label for="confirm_password">
                    Confirm Password
                </label>

                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    placeholder="Confirm your password"
                    autocomplete="new-password">

                <div
                    id="confirm-password-error"
                    class="field-error"></div>

            </div>


            <button type="submit">

                Create Account

            </button>


        </form>


        <p class="login-text">

            Already have an account?

            <a href="login.php">
                Login
            </a>

        </p>


    </div>


    <script>
        $(document).ready(function() {

            function showError(input, error, message) {

                $(input)
                    .removeClass("input-success")
                    .addClass("input-error");


                $(error)
                    .text(message)
                    .addClass("show");
            }


            function showSuccess(input, error) {

                $(input)
                    .removeClass("input-error")
                    .addClass("input-success");


                $(error)
                    .text("")
                    .removeClass("show");
            }


            function clearError(input, error) {

                $(input)
                    .removeClass(
                        "input-error input-success"
                    );


                $(error)
                    .text("")
                    .removeClass("show");
            }

            function validateName() {

                const value =
                    $.trim($("#name").val());


                if (value === "") {

                    showError(
                        "#name",
                        "#name-error",
                        "Name is required."
                    );

                    return false;
                }


                if (value.length < 2) {

                    showError(
                        "#name",
                        "#name-error",
                        "Name must be at least 2 characters."
                    );

                    return false;
                }


                if (value.length > 50) {

                    showError(
                        "#name",
                        "#name-error",
                        "Name cannot be longer than 50 characters."
                    );

                    return false;
                }


                if (/\d/.test(value)) {

                    showError(
                        "#name",
                        "#name-error",
                        "Numbers are not allowed in the name."
                    );

                    return false;
                }


                if (/[^a-zA-Z\s'-]/.test(value)) {

                    showError(
                        "#name",
                        "#name-error",
                        "Only letters, spaces, apostrophes and hyphens are allowed."
                    );

                    return false;
                }


                showSuccess(
                    "#name",
                    "#name-error"
                );

                return true;
            }



            $("#name").on("input", function() {

                const value = $(this).val();


                if (value === "") {

                    clearError(
                        "#name",
                        "#name-error"
                    );

                    return;
                }


                if (/\d/.test(value)) {

                    showError(
                        "#name",
                        "#name-error",
                        "Numbers are not allowed in the name."
                    );

                    return;
                }


                if (/[^a-zA-Z\s'-]/.test(value)) {

                    showError(
                        "#name",
                        "#name-error",
                        "Only letters, spaces, apostrophes and hyphens are allowed."
                    );

                    return;
                }


                if ($.trim(value).length < 2) {

                    showError(
                        "#name",
                        "#name-error",
                        "Name must be at least 2 characters."
                    );

                    return;
                }


                showSuccess(
                    "#name",
                    "#name-error"
                );

            });
            function validateEmail() {

                const value =
                    $.trim($("#email").val());


                if (value === "") {

                    showError(
                        "#email",
                        "#email-error",
                        "Email is required."
                    );

                    return false;
                }


                if (value.length > 254) {

                    showError(
                        "#email",
                        "#email-error",
                        "Email address is too long."
                    );

                    return false;
                }


                const emailPattern =
                    /^[^\s@]+@[^\s@]+\.[^\s@]+$/;


                if (!emailPattern.test(value)) {

                    showError(
                        "#email",
                        "#email-error",
                        "Please enter a valid email address."
                    );

                    return false;
                }


                showSuccess(
                    "#email",
                    "#email-error"
                );

                return true;
            }

            $("#email").on("input", function() {

                const value =
                    $.trim($(this).val());


                if (value === "") {

                    clearError(
                        "#email",
                        "#email-error"
                    );

                    return;
                }


                const emailPattern =
                    /^[^\s@]+@[^\s@]+\.[^\s@]+$/;


                if (!emailPattern.test(value)) {

                    showError(
                        "#email",
                        "#email-error",
                        "Please enter a valid email address."
                    );

                    return;
                }


                showSuccess(
                    "#email",
                    "#email-error"
                );

            });
            function checkPasswordRules() {

                const password =
                    $("#password").val();


                const length =
                    password.length >= 8 &&
                    password.length <= 72;


                const uppercase =
                    /[A-Z]/.test(password);


                const lowercase =
                    /[a-z]/.test(password);


                const number =
                    /[0-9]/.test(password);


                const special =
                    /[^A-Za-z0-9]/.test(password);


                updateRule(
                    "#rule-length",
                    length
                );


                updateRule(
                    "#rule-uppercase",
                    uppercase
                );


                updateRule(
                    "#rule-lowercase",
                    lowercase
                );


                updateRule(
                    "#rule-number",
                    number
                );


                updateRule(
                    "#rule-special",
                    special
                );


                return (
                    length &&
                    uppercase &&
                    lowercase &&
                    number &&
                    special
                );
            }


            function updateRule(selector, valid) {

                const rule =
                    $(selector);


                const icon =
                    rule.find("i");


                if (valid) {

                    rule.addClass("valid");

                    icon.removeClass(
                        "fa-circle"
                    );

                    icon.addClass(
                        "fa-circle-check"
                    );

                } else {

                    rule.removeClass("valid");

                    icon.removeClass(
                        "fa-circle-check"
                    );

                    icon.addClass(
                        "fa-circle"
                    );
                }

            }
            function validatePassword() {

                const password =
                    $("#password").val();


                if (password === "") {

                    showError(
                        "#password",
                        "#password-error",
                        "Password is required."
                    );

                    return false;
                }


                if (!checkPasswordRules()) {

                    showError(
                        "#password",
                        "#password-error",
                        "Please meet all password requirements."
                    );

                    return false;
                }


                showSuccess(
                    "#password",
                    "#password-error"
                );

                return true;
            }

            $("#password").on("focus", function() {

                $("#password-rules").slideDown(150);

                checkPasswordRules();

            });
            $("#password").on("input", function() {

                const value =
                    $(this).val();


                if (value === "") {

                    $("#password-rules").slideUp(150);

                    clearError(
                        "#password",
                        "#password-error"
                    );

                    return;
                }


                $("#password-rules").show();


                const valid =
                    checkPasswordRules();


                if (valid) {

                    showSuccess(
                        "#password",
                        "#password-error"
                    );

                } else {

                    $(this).removeClass(
                        "input-success"
                    );
                }

                if (
                    $("#confirm_password").val() !== ""
                ) {

                    validateConfirmPassword();
                }

            });

            function validateConfirmPassword() {

                const password =
                    $("#password").val();


                const confirmPassword =
                    $("#confirm_password").val();


                if (confirmPassword === "") {

                    showError(
                        "#confirm_password",
                        "#confirm-password-error",
                        "Please confirm your password."
                    );

                    return false;
                }


                if (password !== confirmPassword) {

                    showError(
                        "#confirm_password",
                        "#confirm-password-error",
                        "Passwords do not match."
                    );

                    return false;
                }


                showSuccess(
                    "#confirm_password",
                    "#confirm-password-error"
                );

                return true;
            }
            $("#confirm_password").on("input", function() {

                if ($(this).val() === "") {

                    clearError(
                        "#confirm_password",
                        "#confirm-password-error"
                    );

                    return;
                }


                validateConfirmPassword();

            });

            $("#name").on(
                "blur",
                validateName
            );


            $("#email").on(
                "blur",
                validateEmail
            );


            $("#password").on(
                "blur",
                validatePassword
            );


            $("#confirm_password").on(
                "blur",
                validateConfirmPassword
            );
            $("#registerForm").on("submit", function(event) {

                const nameValid =
                    validateName();


                const emailValid =
                    validateEmail();


                const passwordValid =
                    validatePassword();


                const confirmValid =
                    validateConfirmPassword();


                if (
                    !nameValid ||
                    !emailValid ||
                    !passwordValid ||
                    !confirmValid
                ) {

                    event.preventDefault();
                    if (!nameValid) {

                        $("#name").focus();

                    } else if (!emailValid) {

                        $("#email").focus();

                    } else if (!passwordValid) {

                        $("#password").focus();

                    } else {

                        $("#confirm_password").focus();
                    }

                }

            });


        });
    </script>


</body>

</html>