
<?php
require_once "auth.php";
require_once "../connection/dbconnect.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$database = new Database();
$db = $database->connect();

function profileEscape($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['profile_csrf_token'])) {
    $_SESSION['profile_csrf_token'] = bin2hex(random_bytes(32));
}
$adminId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);

if (!$adminId || $adminId < 1) {
    http_response_code(401);
    exit('Admin session ID was not found. Please check the session variable in auth.php.');
}
$checkAdmin = $db->prepare(
    "SELECT id, name, email, role, created_at
     FROM users
     WHERE id = :id AND role = 'admin'
     LIMIT 1"
);
$checkAdmin->execute([':id' => $adminId]);
$admin = $checkAdmin->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    http_response_code(403);
    exit('Access denied. An admin account is required.');
}

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['profile_csrf_token'], $token)) {
        $errorMessage = 'Invalid request. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if ($name === '' || $email === '') {
                $errorMessage = 'Name and email are required.';
            } elseif (mb_strlen($name) > 100) {
                $errorMessage = 'Name cannot exceed 100 characters.';
            } elseif (mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errorMessage = 'Please enter a valid email address.';
            } else {
                $duplicate = $db->prepare(
                    "SELECT id FROM users
                     WHERE email = :email AND id != :id
                     LIMIT 1"
                );
                $duplicate->execute([
                    ':email' => $email,
                    ':id' => $adminId
                ]);

                if ($duplicate->fetch()) {
                    $errorMessage = 'This email address is already used by another account.';
                } else {
                    $update = $db->prepare(
                        "UPDATE users
                         SET name = :name, email = :email
                         WHERE id = :id AND role = 'admin'"
                    );
                    $update->execute([
                        ':name' => $name,
                        ':email' => $email,
                        ':id' => $adminId
                    ]);

                    $successMessage = 'Profile updated successfully.';
                }
            }
        } elseif ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            $passwordQuery = $db->prepare(
                "SELECT password FROM users
                 WHERE id = :id AND role = 'admin'
                 LIMIT 1"
            );
            $passwordQuery->execute([':id' => $adminId]);
            $passwordHash = $passwordQuery->fetchColumn();

            if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
                $errorMessage = 'Your current password is incorrect.';
            } elseif (strlen($newPassword) < 8) {
                $errorMessage = 'Your new password must be at least 8 characters.';
            } elseif ($newPassword !== $confirmPassword) {
                $errorMessage = 'The new password and confirmation do not match.';
            } elseif ($currentPassword === $newPassword) {
                $errorMessage = 'Your new password must be different from your current password.';
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                $updatePassword = $db->prepare(
                    "UPDATE users
                     SET password = :password
                     WHERE id = :id AND role = 'admin'"
                );
                $updatePassword->execute([
                    ':password' => $newHash,
                    ':id' => $adminId
                ]);

                $successMessage = 'Password changed successfully.';
            }
        } else {
            $errorMessage = 'Invalid form submission.';
        }
    }
    $checkAdmin->execute([':id' => $adminId]);
    $admin = $checkAdmin->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile | Admin Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        body {
            background: #f5f7fb;
            font-family: Arial, sans-serif;
            color: #1f2937;
        }

        .sidebar {
            width: 240px;
            min-height: 100vh;
            background: #111827;
            position: fixed;
            top: 0;
            left: 0;
            padding: 25px 16px;
            color: white;
        }

        .sidebar h4 {
            font-size: 21px;
            margin: 0 0 30px 10px;
            font-weight: 700;
        }

        .sidebar a {
            display: block;
            color: #cbd5e1;
            text-decoration: none;
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 5px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #263449;
            color: #fff;
        }

        .main-content {
            margin-left: 240px;
            padding: 30px;
        }

        .page-title {
            font-weight: 700;
            font-size: 28px;
        }

        .content-card {
            background: #fff;
            border: 0;
            border-radius: 14px;
            padding: 25px;
            box-shadow: 0 3px 15px rgba(15, 23, 42, .05);
            height: 100%;
        }

        .section-title {
            font-size: 19px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .section-description {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 24px;
        }

        .profile-avatar {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            background: #e5e7eb;
            color: #111827;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 700;
        }

        .form-label {
            font-size: 14px;
            font-weight: 600;
        }

        .form-control {
            padding: 11px 12px;
        }

        .account-detail {
            padding: 13px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .account-detail:last-child {
            border-bottom: 0;
        }

        .detail-label {
            color: #6b7280;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .detail-value {
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        @media (max-width: 900px) {
            .sidebar {
                position: static;
                width: 100%;
                min-height: auto;
            }

            .main-content {
                margin-left: 0;
                padding: 18px;
            }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <h4>Admin Panel</h4>
    <a href="index.php">Dashboard</a>
    <a href="vendors.php">Vendors</a>
    <a href="products.php">Products</a>
    <a href="customers.php">Customers</a>
    <a href="categories.php">Categories</a>
    <a href="brands.php">Brands</a>
    <a href="orders.php">Orders</a>
    <a href="reviews.php">Product Reviews</a>
    <a href="profile.php" class="active">Profile</a>
    <a href="../outh/logout.php">Logout</a>
</aside>

<main class="main-content">
    <div class="mb-4">
        <h1 class="page-title mb-1">My Profile</h1>
        <p class="text-secondary mb-0">Manage your admin account and password.</p>
    </div>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= profileEscape($successMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= profileEscape($errorMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-xl-8">
            <div class="content-card">
                <h2 class="section-title">Personal Information</h2>
                <p class="section-description">
                    Update the name and email address associated with your admin account.
                </p>

                <form method="POST">
                    <input type="hidden" name="csrf_token"
                           value="<?= profileEscape($_SESSION['profile_csrf_token']) ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="mb-3">
                        <label for="name" class="form-label">Full Name</label>
                        <input type="text" class="form-control" id="name" name="name"
                               maxlength="100" required
                               value="<?= profileEscape($admin['name']) ?>">
                    </div>

                    <div class="mb-4">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email"
                               maxlength="150" required
                               value="<?= profileEscape($admin['email']) ?>">
                    </div>

                    <button type="submit" class="btn btn-dark">
                        Save Changes
                    </button>
                </form>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="content-card">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <div class="profile-avatar">
                        <?= profileEscape(strtoupper(substr($admin['name'], 0, 1))) ?>
                    </div>

                    <div>
                        <h2 class="section-title mb-1">
                            <?= profileEscape($admin['name']) ?>
                        </h2>
                        <span class="badge text-bg-dark">Administrator</span>
                    </div>
                </div>

                <div class="account-detail">
                    <div class="detail-label">Email Address</div>
                    <div class="detail-value"><?= profileEscape($admin['email']) ?></div>
                </div>

                <div class="account-detail">
                    <div class="detail-label">Account Role</div>
                    <div class="detail-value"><?= profileEscape(ucfirst($admin['role'])) ?></div>
                </div>

                <div class="account-detail">
                    <div class="detail-label">Account Created</div>
                    <div class="detail-value">
                        <?= profileEscape(date('d M Y', strtotime($admin['created_at']))) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="content-card">
                <h2 class="section-title">Change Password</h2>
                <p class="section-description">
                    Enter your current password before choosing a new one.
                    Use at least 8 characters.
                </p>

                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf_token"
                           value="<?= profileEscape($_SESSION['profile_csrf_token']) ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label for="current_password" class="form-label">
                                Current Password
                            </label>
                            <input type="password" class="form-control"
                                   id="current_password" name="current_password"
                                   autocomplete="current-password" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="new_password" class="form-label">
                                New Password
                            </label>
                            <input type="password" class="form-control"
                                   id="new_password" name="new_password"
                                   minlength="8" autocomplete="new-password" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="confirm_password" class="form-label">
                                Confirm New Password
                            </label>
                            <input type="password" class="form-control"
                                   id="confirm_password" name="confirm_password"
                                   minlength="8" autocomplete="new-password" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-dark mt-4">
                        Change Password
                    </button>
                </form>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
