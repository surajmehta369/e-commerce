<?php

require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

$message = "";
$messageType = "";

$name = "";
$email = "";
$storeName = "";
$businessName = "";
$phone = "";
$address = "";
$city = "";
$state = "";
$pincode = "";
$gstNumber = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['name'] ?? "");
    $email = trim($_POST['email'] ?? "");
    $storeName = trim($_POST['store_name'] ?? "");
    $businessName = trim($_POST['business_name'] ?? "");
    $phone = trim($_POST['phone'] ?? "");
    $address = trim($_POST['address'] ?? "");
    $city = trim($_POST['city'] ?? "");
    $state = trim($_POST['state'] ?? "");
    $pincode = trim($_POST['pincode'] ?? "");
    $gstNumber = trim($_POST['gst_number'] ?? "");

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
    } elseif ($storeName === "") {

        $message = "Store name is required.";
        $messageType = "danger";
    } elseif (mb_strlen($storeName) < 2) {

        $message = "Store name must be at least 2 characters.";
        $messageType = "danger";
    } elseif (mb_strlen($storeName) > 150) {

        $message = "Store name cannot be longer than 150 characters.";
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
    } elseif ($confirmPassword === "") {

        $message = "Please confirm your password.";
        $messageType = "danger";
    } elseif ($password !== $confirmPassword) {

        $message = "Passwords do not match.";
        $messageType = "danger";
    } else {

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

                $db->beginTransaction();
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
                        'vendor',
                        1
                    )
                ";

                $stmt = $db->prepare($sql);

                $stmt->execute([
                    ":name" => $name,
                    ":email" => $email,
                    ":password" => $hashedPassword
                ]);

                $userId = $db->lastInsertId();
                $sql = "
                    INSERT INTO vendor_profiles
                    (
                        user_id,
                        store_name,
                        business_name,
                        phone,
                        address,
                        city,
                        state,
                        pincode,
                        gst_number
                    )
                    VALUES
                    (
                        :user_id,
                        :store_name,
                        :business_name,
                        :phone,
                        :address,
                        :city,
                        :state,
                        :pincode,
                        :gst_number
                    )
                ";

                $stmt = $db->prepare($sql);

                $stmt->execute([
                    ":user_id" => $userId,
                    ":store_name" => $storeName,
                    ":business_name" => $businessName !== "" ? $businessName : null,
                    ":phone" => $phone !== "" ? $phone : null,
                    ":address" => $address !== "" ? $address : null,
                    ":city" => $city !== "" ? $city : null,
                    ":state" => $state !== "" ? $state : null,
                    ":pincode" => $pincode !== "" ? $pincode : null,
                    ":gst_number" => $gstNumber !== "" ? $gstNumber : null
                ]);

                $db->commit();

                $message = "Vendor registration successful. Your account is pending verification. You can now login.";
                $messageType = "success";

                $name = "";
                $email = "";
                $storeName = "";
                $businessName = "";
                $phone = "";
                $address = "";
                $city = "";
                $state = "";
                $pincode = "";
                $gstNumber = "";
            }
        } catch (PDOException $e) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $message = "Something went wrong while creating your vendor account. Please try again.";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Registration</title> <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-body p-4 p-md-5">
                        <h2 class="text-center mb-2"> Vendor Registration </h2>
                        <p class="text-center text-muted mb-4"> Create your vendor account and start selling. </p> <?php if ($message !== ""): ?> <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"> <?php echo htmlspecialchars($message); ?> </div> <?php endif; ?> <form method="POST" action=""> <!-- Personal Information -->
                            <h5 class="mb-3"> Personal Information </h5>
                            <div class="row">
                                <div class="col-md-6 mb-3"> <label for="name" class="form-label"> Full Name <span class="text-danger">*</span> </label> <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($name); ?>" maxlength="50" required> </div>
                                <div class="col-md-6 mb-3"> <label for="email" class="form-label"> Email <span class="text-danger">*</span> </label> <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" maxlength="254" required> </div>
                            </div> <!-- Store Information -->
                            <h5 class="mb-3 mt-3"> Store Information </h5>
                            <div class="row">
                                <div class="col-md-6 mb-3"> <label for="store_name" class="form-label"> Store Name <span class="text-danger">*</span> </label> <input type="text" class="form-control" id="store_name" name="store_name" value="<?php echo htmlspecialchars($storeName); ?>" maxlength="150" required> </div>
                                <div class="col-md-6 mb-3"> <label for="business_name" class="form-label"> Business Name </label> <input type="text" class="form-control" id="business_name" name="business_name" value="<?php echo htmlspecialchars($businessName); ?>" maxlength="150"> </div>
                            </div>
                            <div class="mb-3"> <label for="phone" class="form-label"> Phone </label> <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($phone); ?>" maxlength="30"> </div> <!-- Address -->
                            <h5 class="mb-3 mt-4"> Business Address </h5>
                            <div class="mb-3"> <label for="address" class="form-label"> Address </label> <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($address); ?></textarea> </div>
                            <div class="row">
                                <div class="col-md-4 mb-3"> <label for="city" class="form-label"> City </label> <input type="text" class="form-control" id="city" name="city" value="<?php echo htmlspecialchars($city); ?>" maxlength="100"> </div>
                                <div class="col-md-4 mb-3"> <label for="state" class="form-label"> State </label> <input type="text" class="form-control" id="state" name="state" value="<?php echo htmlspecialchars($state); ?>" maxlength="100"> </div>
                                <div class="col-md-4 mb-3"> <label for="pincode" class="form-label"> Pincode </label> <input type="text" class="form-control" id="pincode" name="pincode" value="<?php echo htmlspecialchars($pincode); ?>" maxlength="20"> </div>
                            </div>
                            <div class="mb-3"> <label for="gst_number" class="form-label"> GST Number </label> <input type="text" class="form-control" id="gst_number" name="gst_number" value="<?php echo htmlspecialchars($gstNumber); ?>" maxlength="50"> </div> <!-- Password -->
                            <h5 class="mb-3 mt-4"> Account Security </h5>
                            <div class="row">
                                <div class="col-md-6 mb-3"> <label for="password" class="form-label"> Password <span class="text-danger">*</span> </label> <input type="password" class="form-control" id="password" name="password" minlength="8" maxlength="72" required> <small class="text-muted"> Minimum 8 characters with uppercase, lowercase, number and special character. </small> </div>
                                <div class="col-md-6 mb-3"> <label for="confirm_password" class="form-label"> Confirm Password <span class="text-danger">*</span> </label> <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" maxlength="72" required> </div>
                            </div> <!-- Submit -->
                            <div class="d-grid mt-4"> <button type="submit" class="btn btn-primary btn-lg"> Create Vendor Account </button> </div>
                            <div class="text-center mt-3"> <span class="text-muted"> Already have an account? </span> <a href="login.php"> Login </a> </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>