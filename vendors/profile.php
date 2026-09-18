<?php
require_once "auth.php";
require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

$vendorId = (int) $_SESSION['user_id'];

$message = '';
$error = '';

$stmt = $db->prepare("
    SELECT
        vp.id,
        vp.user_id,
        vp.store_name,
        vp.business_name,
        vp.phone,
        vp.address,
        vp.city,
        vp.state,
        vp.pincode,
        vp.gst_number,
        vp.verification_status,
        vp.created_at,
        vp.updated_at,
        u.name AS owner_name,
        u.email AS owner_email
    FROM vendor_profiles vp
    INNER JOIN users u ON u.id = vp.user_id
    WHERE vp.user_id = :user_id
    LIMIT 1
");

$stmt->execute([
    ':user_id' => $vendorId
]);

$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    $error = "Vendor profile not found.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $profile) {

    $storeName    = trim($_POST['store_name'] ?? '');
    $businessName = trim($_POST['business_name'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $city         = trim($_POST['city'] ?? '');
    $state        = trim($_POST['state'] ?? '');
    $pincode      = trim($_POST['pincode'] ?? '');
    $gstNumber    = trim($_POST['gst_number'] ?? '');

    if ($storeName === '') {
        $error = "Store name is required.";
    } elseif (strlen($storeName) > 150) {
        $error = "Store name cannot exceed 150 characters.";
    } elseif (strlen($businessName) > 150) {
        $error = "Business name cannot exceed 150 characters.";
    } elseif (strlen($phone) > 30) {
        $error = "Phone number cannot exceed 30 characters.";
    } elseif (strlen($city) > 100) {
        $error = "City cannot exceed 100 characters.";
    } elseif (strlen($state) > 100) {
        $error = "State cannot exceed 100 characters.";
    } elseif (strlen($pincode) > 20) {
        $error = "Pincode cannot exceed 20 characters.";
    } elseif (strlen($gstNumber) > 50) {
        $error = "GST number cannot exceed 50 characters.";
    }
    if ($error === '') {

        try {

            $updateStmt = $db->prepare("
                UPDATE vendor_profiles
                SET
                    store_name = :store_name,
                    business_name = :business_name,
                    phone = :phone,
                    address = :address,
                    city = :city,
                    state = :state,
                    pincode = :pincode,
                    gst_number = :gst_number
                WHERE user_id = :user_id
            ");

            $updateStmt->execute([
                ':store_name'    => $storeName,
                ':business_name' => $businessName !== '' ? $businessName : null,
                ':phone'         => $phone !== '' ? $phone : null,
                ':address'       => $address !== '' ? $address : null,
                ':city'          => $city !== '' ? $city : null,
                ':state'         => $state !== '' ? $state : null,
                ':pincode'       => $pincode !== '' ? $pincode : null,
                ':gst_number'    => $gstNumber !== '' ? $gstNumber : null,
                ':user_id'       => $vendorId
            ]);

            $message = "Store profile updated successfully.";
            $stmt->execute([
                ':user_id' => $vendorId
            ]);

            $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {

            $error = "Unable to update store profile.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Store Profile</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

</head>

<body>

<div class="container-fluid">

    <div class="row">
        <div class="col-md-3 col-lg-2 bg-dark text-white min-vh-100 p-3">

            <h4 class="mb-4">
                Vendor Panel
            </h4>

            <div class="mb-4">

                <strong>
                    <?= htmlspecialchars($_SESSION['user_name'] ?? 'Vendor'); ?>
                </strong>

                <div class="small text-secondary">
                    Vendor
                </div>

            </div>

            <div class="nav flex-column">

                <a href="index.php"
                   class="nav-link text-white">
                    Dashboard
                </a>

                <a href="products.php"
                   class="nav-link text-white">
                    Products
                </a>

                <a href="product-add.php"
                   class="nav-link text-white">
                    Add Product
                </a>

                <a href="orders.php"
                   class="nav-link text-white">
                    Orders
                </a>

                <a href="profile.php"
                   class="nav-link text-white">
                    Store Profile
                </a>

                <a href="../outh/logout.php"
                   class="nav-link text-danger mt-3">
                    Logout
                </a>

            </div>

        </div>
        <div class="col-md-9 col-lg-10 p-4">

            <div class="d-flex justify-content-between align-items-center mb-4">

                <div>
                    <h2 class="mb-1">
                        Store Profile
                    </h2>

                    <p class="text-muted mb-0">
                        Manage your store and business information.
                    </p>
                </div>

            </div>
            <?php if ($message): ?>

                <div class="alert alert-success">
                    <?= htmlspecialchars($message); ?>
                </div>

            <?php endif; ?>


            <?php if ($error): ?>

                <div class="alert alert-danger">
                    <?= htmlspecialchars($error); ?>
                </div>

            <?php endif; ?>


            <?php if ($profile): ?>

                <div class="row">
                    <div class="col-lg-8">

                        <div class="card shadow-sm">

                            <div class="card-header bg-white">

                                <h5 class="mb-0">
                                    Store Information
                                </h5>

                            </div>

                            <div class="card-body">

                                <form method="POST">

                                    <div class="row">

                                        <div class="col-md-6 mb-3">

                                            <label class="form-label">
                                                Store Name
                                                <span class="text-danger">*</span>
                                            </label>

                                            <input
                                                type="text"
                                                name="store_name"
                                                class="form-control"
                                                maxlength="150"
                                                value="<?= htmlspecialchars($profile['store_name']); ?>"
                                                required
                                            >

                                        </div>
                                        <div class="col-md-6 mb-3">

                                            <label class="form-label">
                                                Business Name
                                            </label>

                                            <input
                                                type="text"
                                                name="business_name"
                                                class="form-control"
                                                maxlength="150"
                                                value="<?= htmlspecialchars($profile['business_name'] ?? ''); ?>"
                                            >

                                        </div>
                                        <div class="col-md-6 mb-3">

                                            <label class="form-label">
                                                Phone
                                            </label>

                                            <input
                                                type="text"
                                                name="phone"
                                                class="form-control"
                                                maxlength="30"
                                                value="<?= htmlspecialchars($profile['phone'] ?? ''); ?>"
                                            >

                                        </div>
                                        <div class="col-md-6 mb-3">

                                            <label class="form-label">
                                                GST Number
                                            </label>

                                            <input
                                                type="text"
                                                name="gst_number"
                                                class="form-control"
                                                maxlength="50"
                                                value="<?= htmlspecialchars($profile['gst_number'] ?? ''); ?>"
                                            >

                                        </div>
                                        <div class="col-12 mb-3">

                                            <label class="form-label">
                                                Address
                                            </label>

                                            <textarea
                                                name="address"
                                                class="form-control"
                                                rows="3"
                                            ><?= htmlspecialchars($profile['address'] ?? ''); ?></textarea>

                                        </div>
                                        <div class="col-md-4 mb-3">

                                            <label class="form-label">
                                                City
                                            </label>

                                            <input
                                                type="text"
                                                name="city"
                                                class="form-control"
                                                maxlength="100"
                                                value="<?= htmlspecialchars($profile['city'] ?? ''); ?>"
                                            >

                                        </div>
                                        <div class="col-md-4 mb-3">

                                            <label class="form-label">
                                                State
                                            </label>

                                            <input
                                                type="text"
                                                name="state"
                                                class="form-control"
                                                maxlength="100"
                                                value="<?= htmlspecialchars($profile['state'] ?? ''); ?>"
                                            >

                                        </div>

                                        <div class="col-md-4 mb-3">

                                            <label class="form-label">
                                                Pincode
                                            </label>

                                            <input
                                                type="text"
                                                name="pincode"
                                                class="form-control"
                                                maxlength="20"
                                                value="<?= htmlspecialchars($profile['pincode'] ?? ''); ?>"
                                            >

                                        </div>

                                    </div>


                                    <div class="mt-3">

                                        <button
                                            type="submit"
                                            class="btn btn-primary"
                                        >
                                            Update Store Profile
                                        </button>

                                    </div>

                                </form>

                            </div>

                        </div>

                    </div>

                    <div class="col-lg-4">

                        <div class="card shadow-sm mb-4">

                            <div class="card-header bg-white">

                                <h5 class="mb-0">
                                    Account Information
                                </h5>

                            </div>

                            <div class="card-body">

                                <div class="mb-3">

                                    <small class="text-muted">
                                        Owner Name
                                    </small>

                                    <div class="fw-semibold">
                                        <?= htmlspecialchars($profile['owner_name']); ?>
                                    </div>

                                </div>


                                <div class="mb-3">

                                    <small class="text-muted">
                                        Email
                                    </small>

                                    <div class="fw-semibold">
                                        <?= htmlspecialchars($profile['owner_email']); ?>
                                    </div>

                                </div>


                                <div class="mb-0">

                                    <small class="text-muted">
                                        Member Since
                                    </small>

                                    <div class="fw-semibold">
                                        <?= date('d M Y', strtotime($profile['created_at'])); ?>
                                    </div>

                                </div>

                            </div>

                        </div>
                        <div class="card shadow-sm">

                            <div class="card-header bg-white">

                                <h5 class="mb-0">
                                    Verification Status
                                </h5>

                            </div>

                            <div class="card-body">

                                <?php

                                $verificationStatus = $profile['verification_status'];

                                if ($verificationStatus === 'approved'):

                                ?>

                                    <span class="badge bg-success fs-6">
                                        Approved
                                    </span>

                                    <p class="text-muted mt-3 mb-0">
                                        Your vendor account has been approved.
                                    </p>

                                <?php elseif ($verificationStatus === 'rejected'): ?>

                                    <span class="badge bg-danger fs-6">
                                        Rejected
                                    </span>

                                    <p class="text-muted mt-3 mb-0">
                                        Your vendor account verification was rejected.
                                        Please contact the administrator.
                                    </p>

                                <?php else: ?>

                                    <span class="badge bg-warning text-dark fs-6">
                                        Pending
                                    </span>

                                    <p class="text-muted mt-3 mb-0">
                                        Your vendor account is waiting for admin verification.
                                    </p>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

</body>

</html>