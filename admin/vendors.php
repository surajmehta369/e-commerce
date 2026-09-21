<?php
require_once "auth.php";
require_once "../connection/dbconnect.php";
$database = new Database();
$db = $database->connect();
$message = "";
$messageType = "success";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $vendorId = (int) ($_POST['vendor_id'] ?? 0);

    if ($vendorId <= 0) {
        $message = "Invalid vendor.";
        $messageType = "danger";
    } else {

        try {

            if ($action === 'approve') {

                $sql = "UPDATE vendor_profiles
                        SET verification_status = 'approved'
                        WHERE user_id = :vendor_id";

                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':vendor_id' => $vendorId
                ]);

                $message = "Vendor approved successfully.";
            } elseif ($action === 'reject') {

                $sql = "UPDATE vendor_profiles
                        SET verification_status = 'rejected'
                        WHERE user_id = :vendor_id";

                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':vendor_id' => $vendorId
                ]);

                $message = "Vendor rejected successfully.";
            } elseif ($action === 'toggle_status') {

                $sql = "SELECT status
                        FROM users
                        WHERE id = :vendor_id
                        AND role = 'vendor'
                        LIMIT 1";

                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':vendor_id' => $vendorId
                ]);

                $vendor = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$vendor) {

                    $message = "Vendor not found.";
                    $messageType = "danger";
                } else {

                    $newStatus = ((int) $vendor['status'] === 1) ? 0 : 1;

                    $sql = "UPDATE users
                            SET status = :status
                            WHERE id = :vendor_id
                            AND role = 'vendor'";

                    $stmt = $db->prepare($sql);
                    $stmt->execute([
                        ':status' => $newStatus,
                        ':vendor_id' => $vendorId
                    ]);

                    $message = $newStatus === 1
                        ? "Vendor account activated successfully."
                        : "Vendor account deactivated successfully.";
                }
            }
        } catch (PDOException $e) {

            $message = "Something went wrong. Please try again.";
            $messageType = "danger";
        }
    }
}
$search = trim($_GET['search'] ?? '');
$verification = $_GET['verification'] ?? '';

$where = [
    "u.role = 'vendor'"
];

$params = [];

if ($search !== '') {

    $where[] = "(
        u.name LIKE :search
        OR u.email LIKE :search
        OR vp.store_name LIKE :search
        OR vp.business_name LIKE :search
        OR vp.phone LIKE :search
    )";

    $params[':search'] = '%' . $search . '%';
}

if (in_array($verification, ['pending', 'approved', 'rejected'], true)) {

    $where[] = "vp.verification_status = :verification";

    $params[':verification'] = $verification;
}

$whereSql = implode(" AND ", $where);
$sql = "
    SELECT
        u.id,
        u.name,
        u.email,
        u.status AS user_status,
        u.created_at AS user_created_at,
        u.last_login_at,

        vp.store_name,
        vp.business_name,
        vp.phone,
        vp.address,
        vp.city,
        vp.state,
        vp.pincode,
        vp.gst_number,
        vp.verification_status,
        vp.created_at AS vendor_created_at,

        (
            SELECT COUNT(*)
            FROM products p
            WHERE p.vendor_id = u.id
        ) AS product_count,

        (
            SELECT COUNT(DISTINCT o.id)
            FROM orders o
            INNER JOIN order_items oi
                ON oi.order_id = o.id
            INNER JOIN products p
                ON p.id = oi.product_id
            WHERE p.vendor_id = u.id
        ) AS order_count

    FROM users u

    INNER JOIN vendor_profiles vp
        ON vp.user_id = u.id

    WHERE $whereSql

    ORDER BY vp.created_at DESC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);

$vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
function verificationBadge($status)
{
    switch ($status) {

        case 'approved':
            return '<span class="badge bg-success">Approved</span>';

        case 'rejected':
            return '<span class="badge bg-danger">Rejected</span>';

        default:
            return '<span class="badge bg-warning text-dark">Pending</span>';
    }
}

function accountBadge($status)
{
    if ((int) $status === 1) {
        return '<span class="badge bg-success">Active</span>';
    }

    return '<span class="badge bg-secondary">Inactive</span>';
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Vendor Management - Admin</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <style>
        body {
            background: #f5f7fb;
            font-family: Arial, sans-serif;
        }

        .sidebar {
            min-height: 100vh;
            background: #111827;
            color: #fff;
            position: fixed;
            width: 240px;
            left: 0;
            top: 0;
            bottom: 0;
            padding: 20px 15px;
        }

        .sidebar h4 {
            font-weight: 700;
            margin-bottom: 30px;
        }

        .sidebar a {
            display: block;
            color: #d1d5db;
            text-decoration: none;
            padding: 11px 14px;
            border-radius: 8px;
            margin-bottom: 5px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #1f2937;
            color: #fff;
        }

        .main {
            margin-left: 240px;
            padding: 30px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .page-header h2 {
            margin: 0;
            font-weight: 700;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
        }

        .table th {
            white-space: nowrap;
            font-size: 13px;
            color: #6b7280;
        }

        .table td {
            vertical-align: middle;
        }

        .store-name {
            font-weight: 600;
        }

        .small-text {
            font-size: 12px;
            color: #6b7280;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .action-buttons form {
            display: inline;
        }

        .filter-card {
            margin-bottom: 20px;
        }

        @media (max-width: 992px) {

            .sidebar {
                position: static;
                width: 100%;
                min-height: auto;
            }

            .main {
                margin-left: 0;
            }

        }
    </style>

</head>

<body>

    <div class="sidebar">

        <h4>E-Commerce Admin</h4>

        <a href="index.php">
            Dashboard
        </a>

        <a href="vendors.php" class="active">
            Vendors
        </a>

        <a href="products.php">
            Products
        </a>

        <a href="customers.php">
            Customers
        </a>

        <a href="categories.php">
            Categories
        </a>

        <a href="brands.php">
            Brands
        </a>

        <a href="orders.php">
            Orders
        </a>

        <a href="profile.php">
            Admin Profile
        </a>

        <a href="../logout.php">
            Logout
        </a>

    </div>
    <div class="main">

        <div class="page-header">

            <div>

                <h2>Vendor Management</h2>

                <p class="text-muted mb-0">
                    Manage vendor registrations, verification and accounts.
                </p>

            </div>

        </div>
        <?php if ($message !== ""): ?>

            <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show">

                <?php echo htmlspecialchars($message); ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert">
                </button>

            </div>

        <?php endif; ?>
        <div class="card filter-card">

            <div class="card-body">

                <form method="GET">

                    <div class="row g-3 align-items-end">

                        <div class="col-md-6">

                            <label class="form-label">
                                Search Vendor
                            </label>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                placeholder="Name, email, store, business or phone"
                                value="<?php echo htmlspecialchars($search); ?>">

                        </div>


                        <div class="col-md-3">

                            <label class="form-label">
                                Verification Status
                            </label>

                            <select name="verification" class="form-select">

                                <option value="">
                                    All Vendors
                                </option>

                                <option
                                    value="pending"
                                    <?php echo $verification === 'pending' ? 'selected' : ''; ?>>
                                    Pending
                                </option>

                                <option
                                    value="approved"
                                    <?php echo $verification === 'approved' ? 'selected' : ''; ?>>
                                    Approved
                                </option>

                                <option
                                    value="rejected"
                                    <?php echo $verification === 'rejected' ? 'selected' : ''; ?>>
                                    Rejected
                                </option>

                            </select>

                        </div>


                        <div class="col-md-3">

                            <div class="d-flex gap-2">

                                <button
                                    type="submit"
                                    class="btn btn-primary">
                                    Search
                                </button>

                                <a
                                    href="vendors.php"
                                    class="btn btn-outline-secondary">
                                    Reset
                                </a>

                            </div>

                        </div>

                    </div>

                </form>

            </div>

        </div>
        <div class="card">

            <div class="card-body">

                <div class="d-flex justify-content-between align-items-center mb-3">

                    <h5 class="mb-0">
                        Vendors
                    </h5>

                    <span class="text-muted">
                        <?php echo count($vendors); ?> vendor(s)
                    </span>

                </div>


                <div class="table-responsive">

                    <table class="table table-hover align-middle">

                        <thead>

                            <tr>

                                <th>#</th>

                                <th>Vendor</th>

                                <th>Store</th>

                                <th>Contact</th>

                                <th>Verification</th>

                                <th>Account</th>

                                <th>Products</th>

                                <th>Orders</th>

                                <th>Registered</th>

                                <th>Actions</th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php if (empty($vendors)): ?>

                                <tr>

                                    <td
                                        colspan="10"
                                        class="text-center py-5 text-muted">

                                        No vendors found.

                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($vendors as $vendor): ?>

                                    <tr>

                                        <td>
                                            <?php echo (int) $vendor['id']; ?>
                                        </td>


                                        <td>

                                            <div class="fw-semibold">
                                                <?php
                                                echo htmlspecialchars($vendor['name']);
                                                ?>
                                            </div>

                                            <div class="small-text">
                                                <?php
                                                echo htmlspecialchars($vendor['email']);
                                                ?>
                                            </div>

                                        </td>


                                        <td>

                                            <div class="store-name">

                                                <?php
                                                echo htmlspecialchars(
                                                    $vendor['store_name']
                                                );
                                                ?>

                                            </div>

                                            <?php if (!empty($vendor['business_name'])): ?>

                                                <div class="small-text">

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $vendor['business_name']
                                                    );
                                                    ?>

                                                </div>

                                            <?php endif; ?>

                                        </td>


                                        <td>

                                            <?php
                                            echo htmlspecialchars(
                                                $vendor['phone'] ?: '-'
                                            );
                                            ?>

                                        </td>


                                        <td>

                                            <?php
                                            echo verificationBadge(
                                                $vendor['verification_status']
                                            );
                                            ?>

                                        </td>


                                        <td>

                                            <?php
                                            echo accountBadge(
                                                $vendor['user_status']
                                            );
                                            ?>

                                        </td>


                                        <td>

                                            <span class="fw-semibold">

                                                <?php
                                                echo (int) $vendor['product_count'];
                                                ?>

                                            </span>

                                        </td>


                                        <td>

                                            <span class="fw-semibold">

                                                <?php
                                                echo (int) $vendor['order_count'];
                                                ?>

                                            </span>

                                        </td>


                                        <td>

                                            <?php
                                            echo date(
                                                'd M Y',
                                                strtotime($vendor['vendor_created_at'])
                                            );
                                            ?>

                                        </td>


                                        <td>

                                            <div class="action-buttons">

                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-primary"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#vendorModal<?php echo $vendor['id']; ?>">
                                                    View
                                                </button>
                                                <?php if ($vendor['verification_status'] !== 'approved'): ?>

                                                    <form method="POST">

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="approve">

                                                        <input
                                                            type="hidden"
                                                            name="vendor_id"
                                                            value="<?php echo (int) $vendor['id']; ?>">

                                                        <button
                                                            type="submit"
                                                            class="btn btn-sm btn-success"
                                                            onclick="return confirm('Approve this vendor?');">
                                                            Approve
                                                        </button>

                                                    </form>

                                                <?php endif; ?>
                                                <?php if ($vendor['verification_status'] !== 'rejected'): ?>

                                                    <form method="POST">

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="reject">

                                                        <input
                                                            type="hidden"
                                                            name="vendor_id"
                                                            value="<?php echo (int) $vendor['id']; ?>">

                                                        <button
                                                            type="submit"
                                                            class="btn btn-sm btn-outline-danger"
                                                            onclick="return confirm('Reject this vendor?');">
                                                            Reject
                                                        </button>

                                                    </form>

                                                <?php endif; ?>
                                                <form method="POST">

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="toggle_status">

                                                    <input
                                                        type="hidden"
                                                        name="vendor_id"
                                                        value="<?php echo (int) $vendor['id']; ?>">

                                                    <?php if ((int) $vendor['user_status'] === 1): ?>

                                                        <button
                                                            type="submit"
                                                            class="btn btn-sm btn-outline-secondary"
                                                            onclick="return confirm('Deactivate this vendor account?');">
                                                            Deactivate
                                                        </button>

                                                    <?php else: ?>

                                                        <button
                                                            type="submit"
                                                            class="btn btn-sm btn-outline-success"
                                                            onclick="return confirm('Activate this vendor account?');">
                                                            Activate
                                                        </button>

                                                    <?php endif; ?>

                                                </form>

                                            </div>

                                        </td>

                                    </tr>
                                    <div
                                        class="modal fade"
                                        id="vendorModal<?php echo $vendor['id']; ?>"
                                        tabindex="-1">

                                        <div class="modal-dialog modal-lg modal-dialog-centered">

                                            <div class="modal-content">

                                                <div class="modal-header">

                                                    <h5 class="modal-title">
                                                        Vendor Details
                                                    </h5>

                                                    <button
                                                        type="button"
                                                        class="btn-close"
                                                        data-bs-dismiss="modal">
                                                    </button>

                                                </div>


                                                <div class="modal-body">

                                                    <div class="row g-3">

                                                        <div class="col-md-6">

                                                            <strong>Owner Name</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $vendor['name']
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-6">

                                                            <strong>Email</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $vendor['email']
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-6">

                                                            <strong>Store Name</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $vendor['store_name']
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-6">

                                                            <strong>Business Name</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $vendor['business_name'] ?: '-'
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-6">

                                                            <strong>Phone</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $vendor['phone'] ?: '-'
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-6">

                                                            <strong>GST Number</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $vendor['gst_number'] ?: '-'
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-12">

                                                            <strong>Address</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $vendor['address'] ?: '-'
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>City</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $vendor['city'] ?: '-'
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>State</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $vendor['state'] ?: '-'
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Pincode</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $vendor['pincode'] ?: '-'
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Verification</strong>

                                                            <div>
                                                                <?php
                                                                echo verificationBadge(
                                                                    $vendor['verification_status']
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Account</strong>

                                                            <div>
                                                                <?php
                                                                echo accountBadge(
                                                                    $vendor['user_status']
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Last Login</strong>

                                                            <div>

                                                                <?php
                                                                echo !empty($vendor['last_login_at'])
                                                                    ? date(
                                                                        'd M Y H:i',
                                                                        strtotime($vendor['last_login_at'])
                                                                    )
                                                                    : 'Never';
                                                                ?>

                                                            </div>

                                                        </div>

                                                    </div>

                                                </div>


                                                <div class="modal-footer">

                                                    <button
                                                        type="button"
                                                        class="btn btn-secondary"
                                                        data-bs-dismiss="modal">
                                                        Close
                                                    </button>

                                                </div>

                                            </div>

                                        </div>

                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    </script>

</body>

</html>