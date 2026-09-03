<?php
session_start();
require_once "connection/dbconnect.php";

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {

    header("Location: outh/login.php");
    exit;
}

$database = new Database();
$db = $database->connect();

$userId = (int) $_SESSION['user_id'];

$message = "";
$messageType = "";

$editingAddress = null;
function redirectToAccount($messageType, $message)
{
    $_SESSION['account_message'] = $message;
    $_SESSION['account_message_type'] = $messageType;

    header("Location: account.php");
    exit;
}

if (isset($_SESSION['account_message'])) {

    $message = $_SESSION['account_message'];

    $messageType =
        $_SESSION['account_message_type'] ?? 'success';

    unset($_SESSION['account_message']);
    unset($_SESSION['account_message_type']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? "";

    if ($action === 'update_profile') {

        $name = trim($_POST['name'] ?? "");
        $email = trim($_POST['email'] ?? "");

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

else {

            try {

                $sql = "
                    SELECT id
                    FROM users
                    WHERE email = :email
                    AND id != :user_id
                    LIMIT 1
                ";

                $stmt = $db->prepare($sql);

                $stmt->execute([
                    ':email' => $email,
                    ':user_id' => $userId
                ]);

                $existingUser = $stmt->fetch();


                if ($existingUser) {

                    $message = "An account with this email already exists.";
                    $messageType = "danger";
                } else {

                    $sql = "
                        UPDATE users
                        SET
                            name = :name,
                            email = :email
                        WHERE id = :user_id
                        LIMIT 1
                    ";

                    $stmt = $db->prepare($sql);

                    $stmt->execute([
                        ':name' => $name,
                        ':email' => $email,
                        ':user_id' => $userId
                    ]);


                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $email;


                    redirectToAccount(
                        'success',
                        'Profile updated successfully.'
                    );
                }
            } catch (PDOException $e) {

                error_log($e->getMessage());

                $message =
                    "Something went wrong. Please try again later.";

                $messageType = "danger";
            }
        }
    }

 elseif ($action === 'add_address') {

        $addressType =
            trim($_POST['address_type'] ?? 'Home');

        $fullName =
            trim($_POST['full_name'] ?? '');

        $phone =
            trim($_POST['phone'] ?? '');

        $addressLine1 =
            trim($_POST['address_line1'] ?? '');

        $addressLine2 =
            trim($_POST['address_line2'] ?? '');

        $city =
            trim($_POST['city'] ?? '');

        $state =
            trim($_POST['state'] ?? '');

        $pincode =
            trim($_POST['pincode'] ?? '');

        $isDefault =
            isset($_POST['is_default']) ? 1 : 0;

        if ($addressType === '') {

            $message = "Address type is required.";
            $messageType = "danger";
        } elseif (mb_strlen($addressType) > 30) {

            $message = "Address type cannot exceed 30 characters.";
            $messageType = "danger";
        } elseif ($fullName === '') {

            $message = "Full name is required.";
            $messageType = "danger";
        } elseif (mb_strlen($fullName) > 150) {

            $message = "Full name is too long.";
            $messageType = "danger";
        } elseif ($phone === '') {

            $message = "Phone number is required.";
            $messageType = "danger";
        } elseif (mb_strlen($phone) > 30) {

            $message = "Phone number is too long.";
            $messageType = "danger";
        } elseif ($addressLine1 === '') {

            $message = "Address line 1 is required.";
            $messageType = "danger";
        } elseif (mb_strlen($addressLine1) > 255) {

            $message = "Address line 1 is too long.";
            $messageType = "danger";
        } elseif (mb_strlen($addressLine2) > 255) {

            $message = "Address line 2 is too long.";
            $messageType = "danger";
        } elseif ($city === '') {

            $message = "City is required.";
            $messageType = "danger";
        } elseif (mb_strlen($city) > 100) {

            $message = "City name is too long.";
            $messageType = "danger";
        } elseif ($state === '') {

            $message = "State is required.";
            $messageType = "danger";
        } elseif (mb_strlen($state) > 100) {

            $message = "State name is too long.";
            $messageType = "danger";
        } elseif ($pincode === '') {

            $message = "Pincode is required.";
            $messageType = "danger";
        } elseif (!preg_match('/^[0-9A-Za-z\s-]{3,20}$/', $pincode)) {

            $message = "Please enter a valid pincode.";
            $messageType = "danger";
        }

 else {

            try {

                $db->beginTransaction();

                if ($isDefault === 1) {

                    $sql = "
                        UPDATE user_addresses
                        SET is_default = 0
                        WHERE user_id = :user_id
                    ";

                    $stmt = $db->prepare($sql);

                    $stmt->execute([
                        ':user_id' => $userId
                    ]);
                }

                if ($isDefault === 0) {

                    $sql = "
                        SELECT COUNT(*)
                        FROM user_addresses
                        WHERE user_id = :user_id
                    ";

                    $stmt = $db->prepare($sql);

                    $stmt->execute([
                        ':user_id' => $userId
                    ]);

                    $addressCount = (int) $stmt->fetchColumn();

                    if ($addressCount === 0) {

                        $isDefault = 1;
                    }
                }
                $sql = "
                    INSERT INTO user_addresses
                    (
                        user_id,
                        address_type,
                        full_name,
                        phone,
                        address_line1,
                        address_line2,
                        city,
                        state,
                        pincode,
                        is_default
                    )
                    VALUES
                    (
                        :user_id,
                        :address_type,
                        :full_name,
                        :phone,
                        :address_line1,
                        :address_line2,
                        :city,
                        :state,
                        :pincode,
                        :is_default
                    )
                ";

                $stmt = $db->prepare($sql);

                $stmt->execute([
                    ':user_id' => $userId,
                    ':address_type' => $addressType,
                    ':full_name' => $fullName,
                    ':phone' => $phone,
                    ':address_line1' => $addressLine1,
                    ':address_line2' =>
                    $addressLine2 !== ''
                        ? $addressLine2
                        : null,
                    ':city' => $city,
                    ':state' => $state,
                    ':pincode' => $pincode,
                    ':is_default' => $isDefault
                ]);


                $db->commit();


                redirectToAccount(
                    'success',
                    'Address added successfully.'
                );
            } catch (PDOException $e) {

                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                error_log($e->getMessage());

                $message =
                    "Unable to add the address. Please try again.";

                $messageType = "danger";
            }
        }
    }

 elseif ($action === 'update_address') {

        $addressId =
            (int) ($_POST['address_id'] ?? 0);

        $addressType =
            trim($_POST['address_type'] ?? 'Home');

        $fullName =
            trim($_POST['full_name'] ?? '');

        $phone =
            trim($_POST['phone'] ?? '');

        $addressLine1 =
            trim($_POST['address_line1'] ?? '');

        $addressLine2 =
            trim($_POST['address_line2'] ?? '');

        $city =
            trim($_POST['city'] ?? '');

        $state =
            trim($_POST['state'] ?? '');

        $pincode =
            trim($_POST['pincode'] ?? '');

        $isDefault =
            isset($_POST['is_default']) ? 1 : 0;

        if ($addressId <= 0) {

            $message = "Invalid address.";
            $messageType = "danger";
        } elseif ($addressType === '') {

            $message = "Address type is required.";
            $messageType = "danger";
        } elseif (mb_strlen($addressType) > 30) {

            $message = "Address type cannot exceed 30 characters.";
            $messageType = "danger";
        } elseif ($fullName === '') {

            $message = "Full name is required.";
            $messageType = "danger";
        } elseif (mb_strlen($fullName) > 150) {

            $message = "Full name is too long.";
            $messageType = "danger";
        } elseif ($phone === '') {

            $message = "Phone number is required.";
            $messageType = "danger";
        } elseif (mb_strlen($phone) > 30) {

            $message = "Phone number is too long.";
            $messageType = "danger";
        } elseif ($addressLine1 === '') {

            $message = "Address line 1 is required.";
            $messageType = "danger";
        } elseif (mb_strlen($addressLine1) > 255) {

            $message = "Address line 1 is too long.";
            $messageType = "danger";
        } elseif (mb_strlen($addressLine2) > 255) {

            $message = "Address line 2 is too long.";
            $messageType = "danger";
        } elseif ($city === '') {

            $message = "City is required.";
            $messageType = "danger";
        } elseif (mb_strlen($city) > 100) {

            $message = "City name is too long.";
            $messageType = "danger";
        } elseif ($state === '') {

            $message = "State is required.";
            $messageType = "danger";
        } elseif (mb_strlen($state) > 100) {

            $message = "State name is too long.";
            $messageType = "danger";
        } elseif ($pincode === '') {

            $message = "Pincode is required.";
            $messageType = "danger";
        } elseif (!preg_match('/^[0-9A-Za-z\s-]{3,20}$/', $pincode)) {

            $message = "Please enter a valid pincode.";
            $messageType = "danger";
        }


        /*
        |--------------------------------------------------------------------------
        | Update
        |--------------------------------------------------------------------------
        */ else {

            try {

                /*
                |--------------------------------------------------------------------------
                | Make Sure Address Belongs To Current User
                |--------------------------------------------------------------------------
                */

                $sql = "
                    SELECT id
                    FROM user_addresses
                    WHERE id = :address_id
                    AND user_id = :user_id
                    LIMIT 1
                ";

                $stmt = $db->prepare($sql);

                $stmt->execute([
                    ':address_id' => $addressId,
                    ':user_id' => $userId
                ]);

                $addressExists = $stmt->fetch();


                if (!$addressExists) {

                    $message =
                        "Address not found.";

                    $messageType = "danger";
                } else {

                    $db->beginTransaction();


                    /*
                    |--------------------------------------------------------------------------
                    | Remove Existing Default
                    |--------------------------------------------------------------------------
                    */

                    if ($isDefault === 1) {

                        $sql = "
                            UPDATE user_addresses
                            SET is_default = 0
                            WHERE user_id = :user_id
                        ";

                        $stmt = $db->prepare($sql);

                        $stmt->execute([
                            ':user_id' => $userId
                        ]);
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Update Address
                    |--------------------------------------------------------------------------
                    */

                    $sql = "
                        UPDATE user_addresses
                        SET
                            address_type = :address_type,
                            full_name = :full_name,
                            phone = :phone,
                            address_line1 = :address_line1,
                            address_line2 = :address_line2,
                            city = :city,
                            state = :state,
                            pincode = :pincode,
                            is_default = :is_default
                        WHERE id = :address_id
                        AND user_id = :user_id
                        LIMIT 1
                    ";

                    $stmt = $db->prepare($sql);

                    $stmt->execute([
                        ':address_type' => $addressType,
                        ':full_name' => $fullName,
                        ':phone' => $phone,
                        ':address_line1' => $addressLine1,
                        ':address_line2' =>
                        $addressLine2 !== ''
                            ? $addressLine2
                            : null,
                        ':city' => $city,
                        ':state' => $state,
                        ':pincode' => $pincode,
                        ':is_default' => $isDefault,
                        ':address_id' => $addressId,
                        ':user_id' => $userId
                    ]);


                    $db->commit();


                    redirectToAccount(
                        'success',
                        'Address updated successfully.'
                    );
                }
            } catch (PDOException $e) {

                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                error_log($e->getMessage());

                $message =
                    "Unable to update the address. Please try again.";

                $messageType = "danger";
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Delete Address
    |--------------------------------------------------------------------------
    */ elseif ($action === 'delete_address') {

        $addressId =
            (int) ($_POST['address_id'] ?? 0);


        if ($addressId <= 0) {

            $message = "Invalid address.";
            $messageType = "danger";
        } else {

            try {

                /*
                |--------------------------------------------------------------------------
                | Get Address First
                |--------------------------------------------------------------------------
                */

                $sql = "
                    SELECT id, is_default
                    FROM user_addresses
                    WHERE id = :address_id
                    AND user_id = :user_id
                    LIMIT 1
                ";

                $stmt = $db->prepare($sql);

                $stmt->execute([
                    ':address_id' => $addressId,
                    ':user_id' => $userId
                ]);

                $address = $stmt->fetch();


                if (!$address) {

                    $message = "Address not found.";
                    $messageType = "danger";
                } else {

                    $db->beginTransaction();


                    /*
                    |--------------------------------------------------------------------------
                    | Delete
                    |--------------------------------------------------------------------------
                    */

                    $sql = "
                        DELETE FROM user_addresses
                        WHERE id = :address_id
                        AND user_id = :user_id
                        LIMIT 1
                    ";

                    $stmt = $db->prepare($sql);

                    $stmt->execute([
                        ':address_id' => $addressId,
                        ':user_id' => $userId
                    ]);


                    /*
                    |--------------------------------------------------------------------------
                    | If Deleted Address Was Default,
                    | Make Another Address Default
                    |--------------------------------------------------------------------------
                    */

                    if ((int) $address['is_default'] === 1) {

                        $sql = "
                            SELECT id
                            FROM user_addresses
                            WHERE user_id = :user_id
                            ORDER BY id ASC
                            LIMIT 1
                        ";

                        $stmt = $db->prepare($sql);

                        $stmt->execute([
                            ':user_id' => $userId
                        ]);

                        $newDefault = $stmt->fetch();


                        if ($newDefault) {

                            $sql = "
                                UPDATE user_addresses
                                SET is_default = 1
                                WHERE id = :address_id
                                AND user_id = :user_id
                            ";

                            $stmt = $db->prepare($sql);

                            $stmt->execute([
                                ':address_id' =>
                                $newDefault['id'],
                                ':user_id' => $userId
                            ]);
                        }
                    }


                    $db->commit();


                    redirectToAccount(
                        'success',
                        'Address deleted successfully.'
                    );
                }
            } catch (PDOException $e) {

                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                error_log($e->getMessage());

                $message =
                    "Unable to delete the address. Please try again.";

                $messageType = "danger";
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Set Default Address
    |--------------------------------------------------------------------------
    */ elseif ($action === 'set_default_address') {

        $addressId =
            (int) ($_POST['address_id'] ?? 0);


        if ($addressId <= 0) {

            $message = "Invalid address.";
            $messageType = "danger";
        } else {

            try {

                /*
                |--------------------------------------------------------------------------
                | Verify Address Belongs To User
                |--------------------------------------------------------------------------
                */

                $sql = "
                    SELECT id
                    FROM user_addresses
                    WHERE id = :address_id
                    AND user_id = :user_id
                    LIMIT 1
                ";

                $stmt = $db->prepare($sql);

                $stmt->execute([
                    ':address_id' => $addressId,
                    ':user_id' => $userId
                ]);

                $address = $stmt->fetch();


                if (!$address) {

                    $message = "Address not found.";
                    $messageType = "danger";
                } else {

                    $db->beginTransaction();


                    /*
                    |--------------------------------------------------------------------------
                    | Remove Current Default
                    |--------------------------------------------------------------------------
                    */

                    $sql = "
                        UPDATE user_addresses
                        SET is_default = 0
                        WHERE user_id = :user_id
                    ";

                    $stmt = $db->prepare($sql);

                    $stmt->execute([
                        ':user_id' => $userId
                    ]);


                    /*
                    |--------------------------------------------------------------------------
                    | Set New Default
                    |--------------------------------------------------------------------------
                    */

                    $sql = "
                        UPDATE user_addresses
                        SET is_default = 1
                        WHERE id = :address_id
                        AND user_id = :user_id
                        LIMIT 1
                    ";

                    $stmt = $db->prepare($sql);

                    $stmt->execute([
                        ':address_id' => $addressId,
                        ':user_id' => $userId
                    ]);


                    $db->commit();


                    redirectToAccount(
                        'success',
                        'Default address updated successfully.'
                    );
                }
            } catch (PDOException $e) {

                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                error_log($e->getMessage());

                $message =
                    "Unable to update the default address.";

                $messageType = "danger";
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Get Logged-In User
|--------------------------------------------------------------------------
*/

try {

    $sql = "
        SELECT
            id,
            name,
            email,
            role,
            status,
            created_at,
            last_login_at
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);

    $stmt->execute([
        ':user_id' => $userId
    ]);

    $user = $stmt->fetch();


    if (!$user) {

        $_SESSION = [];

        session_destroy();

        header("Location: outh/login.php");
        exit;
    }


    if ((int) $user['status'] !== 1) {

        $_SESSION = [];

        session_destroy();

        header("Location: outh/login.php");
        exit;
    }
} catch (PDOException $e) {

    error_log($e->getMessage());

    die("Something went wrong. Please try again later.");
}


/*
|--------------------------------------------------------------------------
| Get User Addresses
|--------------------------------------------------------------------------
*/

try {

    $sql = "
        SELECT
            id,
            address_type,
            full_name,
            phone,
            address_line1,
            address_line2,
            city,
            state,
            pincode,
            is_default
        FROM user_addresses
        WHERE user_id = :user_id
        ORDER BY
            is_default DESC,
            id DESC
    ";

    $stmt = $db->prepare($sql);

    $stmt->execute([
        ':user_id' => $userId
    ]);

    $addresses = $stmt->fetchAll();
} catch (PDOException $e) {

    error_log($e->getMessage());

    $addresses = [];

    $message =
        "Unable to load your addresses.";

    $messageType = "danger";
}


/*
|--------------------------------------------------------------------------
| Existing Header
|--------------------------------------------------------------------------
*/

include "components/header.php";

?>

<main class="container py-5 account-page">

    <div class="row justify-content-center">

        <div class="col-12 col-lg-10 col-xl-9">


            <!-- =========================================================
                 PAGE HEADER
            ========================================================== -->

            <div class="mb-4">

                <h1 class="fw-bold mb-1">
                    My Account
                </h1>

                <p class="text-muted mb-0">
                    Manage your profile, addresses, orders and account settings.
                </p>

            </div>


            <!-- =========================================================
                 MESSAGE
            ========================================================== -->

            <?php if ($message !== ""): ?>

                <div
                    class="alert
                           alert-<?= htmlspecialchars($messageType) ?>
                           alert-dismissible
                           fade
                           show
                           rounded-3"
                    role="alert">

                    <?= htmlspecialchars($message) ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                        aria-label="Close"></button>

                </div>

            <?php endif; ?>


            <!-- =========================================================
                 WELCOME CARD
            ========================================================== -->

            <div class="card border-0 shadow-sm rounded-4 mb-4">

                <div class="card-body p-4">

                    <div class="d-flex align-items-center">

                        <div
                            class="account-avatar
                                   rounded-circle
                                   bg-primary
                                   text-white
                                   d-flex
                                   align-items-center
                                   justify-content-center
                                   flex-shrink-0">

                            <i class="fa-solid fa-user"></i>

                        </div>


                        <div class="ms-3">

                            <h3 class="fw-bold mb-1">

                                Welcome,
                                <?= htmlspecialchars($user['name']) ?>

                            </h3>

                            <p class="text-muted mb-0">

                                <?= htmlspecialchars($user['email']) ?>

                            </p>

                        </div>

                    </div>

                </div>

            </div>


            <!-- =========================================================
                 PROFILE
            ========================================================== -->

            <div class="card border-0 shadow-sm rounded-4 mb-4">

                <div class="card-body p-4">

                    <div
                        class="d-flex
                               justify-content-between
                               align-items-center
                               mb-4">

                        <div>

                            <h4 class="fw-bold mb-1">
                                Profile Information
                            </h4>

                            <p class="text-muted mb-0">
                                Update your name and email address.
                            </p>

                        </div>

                        <div class="profile-header-icon">

                            <i class="fa-solid fa-user-pen"></i>

                        </div>

                    </div>


                    <form method="POST">

                        <input
                            type="hidden"
                            name="action"
                            value="update_profile">


                        <div class="row g-4">

                            <div class="col-md-6">

                                <label
                                    for="name"
                                    class="form-label fw-semibold">
                                    Full Name
                                </label>

                                <input
                                    type="text"
                                    class="form-control account-input"
                                    id="name"
                                    name="name"
                                    value="<?= htmlspecialchars($user['name']) ?>"
                                    maxlength="50"
                                    required>

                                <div class="form-text">
                                    2–50 characters.
                                </div>

                            </div>


                            <div class="col-md-6">

                                <label
                                    for="email"
                                    class="form-label fw-semibold">
                                    Email Address
                                </label>

                                <input
                                    type="email"
                                    class="form-control account-input"
                                    id="email"
                                    name="email"
                                    value="<?= htmlspecialchars($user['email']) ?>"
                                    maxlength="254"
                                    required>

                                <div class="form-text">
                                    This email will be used for login.
                                </div>

                            </div>

                        </div>


                        <div class="mt-4">

                            <button
                                type="submit"
                                class="btn btn-primary rounded-pill px-4">

                                <i
                                    class="fa-solid
                                           fa-floppy-disk
                                           me-2"></i>

                                Save Changes

                            </button>

                        </div>

                    </form>

                </div>

            </div>


            <!-- =========================================================
                 ADD ADDRESS
            ========================================================== -->

            <div class="card border-0 shadow-sm rounded-4 mb-4">

                <div class="card-body p-4">

                    <div
                        class="d-flex
                               justify-content-between
                               align-items-center
                               mb-4">

                        <div>

                            <h4 class="fw-bold mb-1">
                                Add New Address
                            </h4>

                            <p class="text-muted mb-0">
                                Save a delivery address for faster checkout.
                            </p>

                        </div>

                        <div class="profile-header-icon">

                            <i class="fa-solid fa-location-dot"></i>

                        </div>

                    </div>


                    <form method="POST">

                        <input
                            type="hidden"
                            name="action"
                            value="add_address">


                        <div class="row g-3">


                            <!-- Address Type -->

                            <div class="col-md-4">

                                <label
                                    for="address_type"
                                    class="form-label fw-semibold">
                                    Address Type
                                </label>

                                <select
                                    class="form-select account-input"
                                    id="address_type"
                                    name="address_type">

                                    <option value="Home">
                                        Home
                                    </option>

                                    <option value="Work">
                                        Work
                                    </option>

                                    <option value="Other">
                                        Other
                                    </option>

                                </select>

                            </div>


                            <!-- Full Name -->

                            <div class="col-md-8">

                                <label
                                    for="full_name"
                                    class="form-label fw-semibold">
                                    Full Name
                                </label>

                                <input
                                    type="text"
                                    class="form-control account-input"
                                    id="full_name"
                                    name="full_name"
                                    maxlength="150"
                                    required>

                            </div>


                            <!-- Phone -->

                            <div class="col-md-6">

                                <label
                                    for="phone"
                                    class="form-label fw-semibold">
                                    Phone
                                </label>

                                <input
                                    type="tel"
                                    class="form-control account-input"
                                    id="phone"
                                    name="phone"
                                    maxlength="30"
                                    required>

                            </div>


                            <!-- Pincode -->

                            <div class="col-md-6">

                                <label
                                    for="pincode"
                                    class="form-label fw-semibold">
                                    Pincode
                                </label>

                                <input
                                    type="text"
                                    class="form-control account-input"
                                    id="pincode"
                                    name="pincode"
                                    maxlength="20"
                                    required>

                            </div>


                            <!-- Address Line 1 -->

                            <div class="col-12">

                                <label
                                    for="address_line1"
                                    class="form-label fw-semibold">
                                    Address Line 1
                                </label>

                                <input
                                    type="text"
                                    class="form-control account-input"
                                    id="address_line1"
                                    name="address_line1"
                                    maxlength="255"
                                    placeholder="House / Flat / Street"
                                    required>

                            </div>


                            <!-- Address Line 2 -->

                            <div class="col-12">

                                <label
                                    for="address_line2"
                                    class="form-label fw-semibold">
                                    Address Line 2
                                    <span class="text-muted fw-normal">
                                        (Optional)
                                    </span>
                                </label>

                                <input
                                    type="text"
                                    class="form-control account-input"
                                    id="address_line2"
                                    name="address_line2"
                                    maxlength="255"
                                    placeholder="Landmark, area, etc.">

                            </div>


                            <!-- City -->

                            <div class="col-md-4">

                                <label
                                    for="city"
                                    class="form-label fw-semibold">
                                    City
                                </label>

                                <input
                                    type="text"
                                    class="form-control account-input"
                                    id="city"
                                    name="city"
                                    maxlength="100"
                                    required>

                            </div>


                            <!-- State -->

                            <div class="col-md-4">

                                <label
                                    for="state"
                                    class="form-label fw-semibold">
                                    State
                                </label>

                                <input
                                    type="text"
                                    class="form-control account-input"
                                    id="state"
                                    name="state"
                                    maxlength="100"
                                    required>

                            </div>


                            <!-- Default -->

                            <div class="col-md-4 d-flex align-items-end">

                                <div class="form-check mb-2">

                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        id="is_default"
                                        name="is_default"
                                        value="1">

                                    <label
                                        class="form-check-label"
                                        for="is_default">
                                        Set as default address
                                    </label>

                                </div>

                            </div>


                        </div>


                        <div class="mt-4">

                            <button
                                type="submit"
                                class="btn btn-primary rounded-pill px-4">

                                <i
                                    class="fa-solid
                                           fa-plus
                                           me-2"></i>

                                Add Address

                            </button>

                        </div>

                    </form>

                </div>

            </div>


            <!-- =========================================================
                 SAVED ADDRESSES
            ========================================================== -->

            <div class="card border-0 shadow-sm rounded-4 mb-4">

                <div class="card-body p-4">

                    <div class="mb-4">

                        <h4 class="fw-bold mb-1">
                            Saved Addresses
                        </h4>

                        <p class="text-muted mb-0">
                            Manage your saved delivery addresses.
                        </p>

                    </div>


                    <?php if (empty($addresses)): ?>

                        <div class="text-center py-5">

                            <div
                                class="address-empty-icon
                                       mx-auto
                                       mb-3">

                                <i class="fa-solid fa-location-dot"></i>

                            </div>

                            <h5 class="fw-bold">
                                No saved addresses
                            </h5>

                            <p class="text-muted mb-0">
                                Add your first delivery address above.
                            </p>

                        </div>

                    <?php else: ?>


                        <div class="row g-4">

                            <?php foreach ($addresses as $address): ?>

                                <div class="col-12 col-md-6">

                                    <div
                                        class="address-card
                                               h-100
                                               position-relative
                                               <?= (int) $address['is_default'] === 1
                                                    ? 'default-address'
                                                    : '' ?>">


                                        <!-- Default Badge -->

                                        <?php if ((int) $address['is_default'] === 1): ?>

                                            <span
                                                class="badge
                                                       bg-success
                                                       rounded-pill
                                                       default-badge">

                                                <i
                                                    class="fa-solid
                                                           fa-check
                                                           me-1"></i>

                                                Default

                                            </span>

                                        <?php endif; ?>


                                        <!-- Address Type -->

                                        <div class="d-flex align-items-center mb-3">

                                            <div class="address-type-icon">

                                                <?php if ($address['address_type'] === 'Work'): ?>

                                                    <i class="fa-solid fa-briefcase"></i>

                                                <?php else: ?>

                                                    <i class="fa-solid fa-house"></i>

                                                <?php endif; ?>

                                            </div>

                                            <div class="ms-2">

                                                <h5 class="fw-bold mb-0">

                                                    <?= htmlspecialchars(
                                                        $address['address_type']
                                                    ) ?>

                                                </h5>

                                            </div>

                                        </div>


                                        <!-- Address Information -->

                                        <div class="address-details">

                                            <div class="fw-bold mb-1">

                                                <?= htmlspecialchars(
                                                    $address['full_name']
                                                ) ?>

                                            </div>


                                            <div class="text-muted mb-2">

                                                <i
                                                    class="fa-solid
                                                           fa-phone
                                                           me-1"></i>

                                                <?= htmlspecialchars(
                                                    $address['phone']
                                                ) ?>

                                            </div>


                                            <div class="text-muted">

                                                <?= htmlspecialchars(
                                                    $address['address_line1']
                                                ) ?>

                                                <?php if (!empty($address['address_line2'])): ?>

                                                    <br>

                                                    <?= htmlspecialchars(
                                                        $address['address_line2']
                                                    ) ?>

                                                <?php endif; ?>

                                                <br>

                                                <?= htmlspecialchars(
                                                    $address['city']
                                                ) ?>,

                                                <?= htmlspecialchars(
                                                    $address['state']
                                                ) ?>

                                                -

                                                <?= htmlspecialchars(
                                                    $address['pincode']
                                                ) ?>

                                            </div>

                                        </div>


                                        <!-- Actions -->

                                        <div
                                            class="address-actions
                                                   d-flex
                                                   flex-wrap
                                                   gap-2
                                                   mt-4">


                                            <!-- Edit -->

                                            <a
                                                href="account.php?edit_address=<?= (int) $address['id'] ?>"
                                                class="btn btn-sm
                                                       btn-outline-primary
                                                       rounded-pill
                                                       px-3">

                                                <i
                                                    class="fa-solid
                                                           fa-pen
                                                           me-1"></i>

                                                Edit

                                            </a>


                                            <!-- Set Default -->

                                            <?php if ((int) $address['is_default'] !== 1): ?>

                                                <form method="POST">

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="set_default_address">

                                                    <input
                                                        type="hidden"
                                                        name="address_id"
                                                        value="<?= (int) $address['id'] ?>">

                                                    <button
                                                        type="submit"
                                                        class="btn btn-sm
                                                               btn-outline-success
                                                               rounded-pill
                                                               px-3">

                                                        <i
                                                            class="fa-solid
                                                                   fa-star
                                                                   me-1"></i>

                                                        Set Default

                                                    </button>

                                                </form>

                                            <?php endif; ?>


                                            <!-- Delete -->

                                            <form
                                                method="POST"
                                                onsubmit="return confirm('Are you sure you want to delete this address?');">

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="delete_address">

                                                <input
                                                    type="hidden"
                                                    name="address_id"
                                                    value="<?= (int) $address['id'] ?>">

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm
                                                           btn-outline-danger
                                                           rounded-pill
                                                           px-3">

                                                    <i
                                                        class="fa-solid
                                                               fa-trash
                                                               me-1"></i>

                                                    Delete

                                                </button>

                                            </form>

                                        </div>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

            <?php

            if (isset($_GET['edit_address'])) {

                $editAddressId =
                    (int) $_GET['edit_address'];


                if ($editAddressId > 0) {

                    try {

                        $sql = "
                            SELECT
                                id,
                                address_type,
                                full_name,
                                phone,
                                address_line1,
                                address_line2,
                                city,
                                state,
                                pincode,
                                is_default
                            FROM user_addresses
                            WHERE id = :address_id
                            AND user_id = :user_id
                            LIMIT 1
                        ";

                        $stmt = $db->prepare($sql);

                        $stmt->execute([
                            ':address_id' => $editAddressId,
                            ':user_id' => $userId
                        ]);

                        $editingAddress = $stmt->fetch();
                    } catch (PDOException $e) {

                        error_log($e->getMessage());
                    }
                }
            }

            ?>


            <?php if ($editingAddress): ?>

                <div
                    class="card
                           border-0
                           shadow-sm
                           rounded-4
                           mb-4"
                    id="edit-address">

                    <div class="card-body p-4">

                        <div
                            class="d-flex
                                   justify-content-between
                                   align-items-center
                                   mb-4">

                            <div>

                                <h4 class="fw-bold mb-1">
                                    Edit Address
                                </h4>

                                <p class="text-muted mb-0">
                                    Update your saved address.
                                </p>

                            </div>

                            <a
                                href="account.php"
                                class="btn btn-sm
                                       btn-outline-secondary
                                       rounded-pill">

                                Cancel

                            </a>

                        </div>


                        <form method="POST">

                            <input
                                type="hidden"
                                name="action"
                                value="update_address">

                            <input
                                type="hidden"
                                name="address_id"
                                value="<?= (int) $editingAddress['id'] ?>">


                            <div class="row g-3">


                                <div class="col-md-4">

                                    <label
                                        for="edit_address_type"
                                        class="form-label fw-semibold">
                                        Address Type
                                    </label>

                                    <select
                                        class="form-select account-input"
                                        id="edit_address_type"
                                        name="address_type">

                                        <option
                                            value="Home"
                                            <?= $editingAddress['address_type'] === 'Home'
                                                ? 'selected'
                                                : '' ?>>
                                            Home
                                        </option>

                                        <option
                                            value="Work"
                                            <?= $editingAddress['address_type'] === 'Work'
                                                ? 'selected'
                                                : '' ?>>
                                            Work
                                        </option>

                                        <option
                                            value="Other"
                                            <?= $editingAddress['address_type'] === 'Other'
                                                ? 'selected'
                                                : '' ?>>
                                            Other
                                        </option>

                                    </select>

                                </div>


                                <div class="col-md-8">

                                    <label
                                        for="edit_full_name"
                                        class="form-label fw-semibold">
                                        Full Name
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control account-input"
                                        id="edit_full_name"
                                        name="full_name"
                                        maxlength="150"
                                        value="<?= htmlspecialchars(
                                                    $editingAddress['full_name']
                                                ) ?>"
                                        required>

                                </div>


                                <div class="col-md-6">

                                    <label
                                        for="edit_phone"
                                        class="form-label fw-semibold">
                                        Phone
                                    </label>

                                    <input
                                        type="tel"
                                        class="form-control account-input"
                                        id="edit_phone"
                                        name="phone"
                                        maxlength="30"
                                        value="<?= htmlspecialchars(
                                                    $editingAddress['phone']
                                                ) ?>"
                                        required>

                                </div>


                                <div class="col-md-6">

                                    <label
                                        for="edit_pincode"
                                        class="form-label fw-semibold">
                                        Pincode
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control account-input"
                                        id="edit_pincode"
                                        name="pincode"
                                        maxlength="20"
                                        value="<?= htmlspecialchars(
                                                    $editingAddress['pincode']
                                                ) ?>"
                                        required>

                                </div>


                                <div class="col-12">

                                    <label
                                        for="edit_address_line1"
                                        class="form-label fw-semibold">
                                        Address Line 1
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control account-input"
                                        id="edit_address_line1"
                                        name="address_line1"
                                        maxlength="255"
                                        value="<?= htmlspecialchars(
                                                    $editingAddress['address_line1']
                                                ) ?>"
                                        required>

                                </div>


                                <div class="col-12">

                                    <label
                                        for="edit_address_line2"
                                        class="form-label fw-semibold">
                                        Address Line 2
                                        <span class="text-muted fw-normal">
                                            (Optional)
                                        </span>
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control account-input"
                                        id="edit_address_line2"
                                        name="address_line2"
                                        maxlength="255"
                                        value="<?= htmlspecialchars(
                                                    $editingAddress['address_line2'] ?? ''
                                                ) ?>">

                                </div>


                                <div class="col-md-4">

                                    <label
                                        for="edit_city"
                                        class="form-label fw-semibold">
                                        City
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control account-input"
                                        id="edit_city"
                                        name="city"
                                        maxlength="100"
                                        value="<?= htmlspecialchars(
                                                    $editingAddress['city']
                                                ) ?>"
                                        required>

                                </div>


                                <div class="col-md-4">

                                    <label
                                        for="edit_state"
                                        class="form-label fw-semibold">
                                        State
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control account-input"
                                        id="edit_state"
                                        name="state"
                                        maxlength="100"
                                        value="<?= htmlspecialchars(
                                                    $editingAddress['state']
                                                ) ?>"
                                        required>

                                </div>


                                <div class="col-md-4 d-flex align-items-end">

                                    <div class="form-check mb-2">

                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            id="edit_is_default"
                                            name="is_default"
                                            value="1"
                                            <?= (int) $editingAddress['is_default'] === 1
                                                ? 'checked'
                                                : '' ?>>

                                        <label
                                            class="form-check-label"
                                            for="edit_is_default">
                                            Set as default
                                        </label>

                                    </div>

                                </div>

                            </div>


                            <div class="mt-4">

                                <button
                                    type="submit"
                                    class="btn btn-primary
                                           rounded-pill
                                           px-4">

                                    <i
                                        class="fa-solid
                                               fa-floppy-disk
                                               me-2"></i>

                                    Update Address

                                </button>

                            </div>

                        </form>

                    </div>

                </div>

            <?php endif; ?>

            <div class="row g-4">

                <div class="col-md-6">

                    <div
                        class="card
                               border-0
                               shadow-sm
                               rounded-4
                               h-100">

                        <div class="card-body p-4">

                            <div class="account-section-icon mb-3">

                                <i class="fa-solid fa-box"></i>

                            </div>

                            <h5 class="fw-bold">
                                My Orders
                            </h5>

                            <p class="text-muted">

                                View your orders, order status
                                and payment information.

                            </p>

                            <a
                                href="orders.php"
                                class="btn btn-dark rounded-pill px-4">

                                View Orders

                            </a>

                        </div>

                    </div>

                </div>

                <div class="col-md-6">

                    <div
                        class="card
                               border-0
                               shadow-sm
                               rounded-4
                               h-100">

                        <div class="card-body p-4">

                            <div class="account-section-icon mb-3">

                                <i class="fa-solid fa-lock"></i>

                            </div>

                            <h5 class="fw-bold">
                                Security
                            </h5>

                            <p class="text-muted">

                                Manage your password and account security.

                            </p>

                            <a
                                href="change_password.php"
                                class="btn btn-danger rounded-pill px-4 mt-2">
                                <i class="fa-solid fa-lock me-2"></i>
                                Change Password
                            </a>


                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</main>

<?php

include "components/footer.php";

?>