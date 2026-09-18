<?php

require_once "auth.php";
require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

$message = "";
$messageType = "success";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $customerId = (int) ($_POST['customer_id'] ?? 0);

    if ($customerId <= 0) {

        $message = "Invalid customer.";
        $messageType = "danger";

    } else {

        try {

            if ($action === 'activate') {

                $sql = "UPDATE users
                        SET status = 1
                        WHERE id = :id
                        AND role = 'customer'";

                $stmt = $db->prepare($sql);

                $stmt->execute([
                    ':id' => $customerId
                ]);

                $message = "Customer activated successfully.";
            }

            elseif ($action === 'deactivate') {

                $sql = "UPDATE users
                        SET status = 0
                        WHERE id = :id
                        AND role = 'customer'";

                $stmt = $db->prepare($sql);

                $stmt->execute([
                    ':id' => $customerId
                ]);

                $message = "Customer deactivated successfully.";
            }

        } catch (PDOException $e) {

            $message = "Something went wrong. Please try again.";
            $messageType = "danger";
        }
    }
}

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

$where = [
    "u.role = 'customer'"
];

$params = [];

if ($search !== '') {

    $where[] = "(
        u.name LIKE :search
        OR u.email LIKE :search
    )";

    $params[':search'] = '%' . $search . '%';
}


if ($status === 'active') {

    $where[] = "u.status = 1";

} elseif ($status === 'inactive') {

    $where[] = "u.status = 0";
}


$whereSql = implode(" AND ", $where);
$sql = "
    SELECT
        u.id,
        u.name,
        u.email,
        u.status,
        u.created_at,
        u.updated_at,
        u.last_login_at,

        COUNT(DISTINCT o.id) AS order_count,

        COALESCE(
            SUM(
                CASE
                    WHEN o.order_status != 'cancelled'
                    THEN o.total_amount
                    ELSE 0
                END
            ),
            0
        ) AS total_spent

    FROM users u

    LEFT JOIN orders o
        ON o.user_id = u.id

    WHERE $whereSql

    GROUP BY
        u.id,
        u.name,
        u.email,
        u.status,
        u.created_at,
        u.updated_at,
        u.last_login_at

    ORDER BY u.created_at DESC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);

$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

function customerStatusBadge($status)
{
    if ((int) $status === 1) {

        return '<span class="badge bg-success">
                    Active
                </span>';
    }

    return '<span class="badge bg-secondary">
                Inactive
            </span>';
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

    <title>Customer Management - Admin</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

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

        .filter-card {
            margin-bottom: 20px;
        }

        .table th {
            white-space: nowrap;
            font-size: 13px;
            color: #6b7280;
        }

        .table td {
            vertical-align: middle;
        }

        .customer-name {
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

        .customer-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #374151;
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

    <a href="vendors.php">
        Vendors
    </a>

    <a href="products.php">
        Products
    </a>

    <a href="customers.php" class="active">
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

        <h2>Customer Management</h2>

        <p class="text-muted mb-0">
            Manage customer accounts and view customer activity.
        </p>

    </div>

    <?php if ($message !== ""): ?>

        <div
            class="alert alert-<?php echo htmlspecialchars($messageType); ?>
                   alert-dismissible fade show"
        >

            <?php echo htmlspecialchars($message); ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>
    <div class="card filter-card">

        <div class="card-body">

            <form method="GET">

                <div class="row g-3 align-items-end">

                    <div class="col-md-7">

                        <label class="form-label">
                            Search Customer
                        </label>

                        <input
                            type="text"
                            name="search"
                            class="form-control"
                            placeholder="Search by name or email"
                            value="<?php echo htmlspecialchars($search); ?>"
                        >

                    </div>


                    <div class="col-md-3">

                        <label class="form-label">
                            Account Status
                        </label>

                        <select
                            name="status"
                            class="form-select"
                        >

                            <option value="">
                                All Customers
                            </option>

                            <option
                                value="active"
                                <?php
                                echo $status === 'active'
                                    ? 'selected'
                                    : '';
                                ?>
                            >
                                Active
                            </option>

                            <option
                                value="inactive"
                                <?php
                                echo $status === 'inactive'
                                    ? 'selected'
                                    : '';
                                ?>
                            >
                                Inactive
                            </option>

                        </select>

                    </div>


                    <div class="col-md-2">

                        <div class="d-flex gap-2">

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                Filter
                            </button>

                            <a
                                href="customers.php"
                                class="btn btn-outline-secondary"
                            >
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
                    Customers
                </h5>

                <span class="text-muted">
                    <?php echo count($customers); ?> customer(s)
                </span>

            </div>


            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead>

                        <tr>

                            <th>#</th>

                            <th>Customer</th>

                            <th>Status</th>

                            <th>Orders</th>

                            <th>Total Spent</th>

                            <th>Last Login</th>

                            <th>Registered</th>

                            <th>Actions</th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php if (empty($customers)): ?>

                        <tr>

                            <td
                                colspan="8"
                                class="text-center py-5 text-muted"
                            >

                                No customers found.

                            </td>

                        </tr>

                    <?php else: ?>


                        <?php foreach ($customers as $customer): ?>

                            <?php

                            $initial = strtoupper(
                                substr(
                                    trim($customer['name']),
                                    0,
                                    1
                                )
                            );

                            ?>

                            <tr>

                                <td>
                                    <?php echo (int) $customer['id']; ?>
                                </td>
                                <td>

                                    <div class="d-flex align-items-center gap-3">

                                        <div class="customer-avatar">

                                            <?php
                                            echo htmlspecialchars(
                                                $initial
                                            );
                                            ?>

                                        </div>

                                        <div>

                                            <div class="customer-name">

                                                <?php
                                                echo htmlspecialchars(
                                                    $customer['name']
                                                );
                                                ?>

                                            </div>

                                            <div class="small-text">

                                                <?php
                                                echo htmlspecialchars(
                                                    $customer['email']
                                                );
                                                ?>

                                            </div>

                                        </div>

                                    </div>

                                </td>
                                <td>

                                    <?php
                                    echo customerStatusBadge(
                                        $customer['status']
                                    );
                                    ?>

                                </td>
                                <td>

                                    <span class="fw-semibold">

                                        <?php
                                        echo (int) $customer['order_count'];
                                        ?>

                                    </span>

                                </td>
                                <td>

                                    <strong>

                                        $<?php
                                        echo number_format(
                                            (float) $customer['total_spent'],
                                            2
                                        );
                                        ?>

                                    </strong>

                                </td>
                                <td>

                                    <?php if (!empty($customer['last_login_at'])): ?>

                                        <?php
                                        echo date(
                                            'd M Y H:i',
                                            strtotime(
                                                $customer['last_login_at']
                                            )
                                        );
                                        ?>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            Never
                                        </span>

                                    <?php endif; ?>

                                </td>
                                <td>

                                    <?php
                                    echo date(
                                        'd M Y',
                                        strtotime(
                                            $customer['created_at']
                                        )
                                    );
                                    ?>

                                </td>
                                <td>

                                    <div class="action-buttons">
                                    <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#customerModal<?php echo $customer['id']; ?>"
                                        >
                                            View
                                        </button>
                                        <?php if ((int) $customer['status'] === 0): ?>

                                            <form method="POST">

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="activate"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="customer_id"
                                                    value="<?php echo (int) $customer['id']; ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-success"
                                                    onclick="return confirm('Activate this customer account?');"
                                                >
                                                    Activate
                                                </button>

                                            </form>

                                        <?php endif; ?>
                                        <?php if ((int) $customer['status'] === 1): ?>

                                            <form method="POST">

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="deactivate"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="customer_id"
                                                    value="<?php echo (int) $customer['id']; ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('Deactivate this customer account?');"
                                                >
                                                    Deactivate
                                                </button>

                                            </form>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>
                            <div
                                class="modal fade"
                                id="customerModal<?php echo $customer['id']; ?>"
                                tabindex="-1"
                            >

                                <div
                                    class="modal-dialog
                                           modal-dialog-centered"
                                >

                                    <div class="modal-content">

                                        <div class="modal-header">

                                            <h5 class="modal-title">
                                                Customer Details
                                            </h5>

                                            <button
                                                type="button"
                                                class="btn-close"
                                                data-bs-dismiss="modal"
                                            ></button>

                                        </div>


                                        <div class="modal-body">
                                            <div class="text-center mb-4">
                                                <div
                                                    class="customer-avatar
                                                           mx-auto mb-2"
                                                >

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $initial
                                                    );
                                                    ?>

                                                </div>

                                                <h5 class="mb-1">

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $customer['name']
                                                    );
                                                    ?>

                                                </h5>

                                                <div class="text-muted">

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $customer['email']
                                                    );
                                                    ?>

                                                </div>

                                            </div>
                                            <div class="row g-3">

                                                <div class="col-6">

                                                    <strong>
                                                        Customer ID
                                                    </strong>

                                                    <div>
                                                        #
                                                        <?php
                                                        echo (int)
                                                            $customer['id'];
                                                        ?>
                                                    </div>
                                                </div>
                                                <div class="col-6">

                                                    <strong>
                                                        Status
                                                    </strong>

                                                    <div>

                                                        <?php
                                                        echo customerStatusBadge(
                                                            $customer['status']
                                                        );
                                                        ?>

                                                    </div>

                                                </div>

                                                <div class="col-6">

                                                    <strong>
                                                        Total Orders
                                                    </strong>

                                                    <div>

                                                        <?php
                                                        echo (int)
                                                            $customer['order_count'];
                                                        ?>

                                                    </div>
                                                </div>
                                                <div class="col-6">

                                                    <strong>
                                                        Total Spent
                                                    </strong>

                                                    <div>

                                                        $<?php
                                                        echo number_format(
                                                            (float)
                                                            $customer['total_spent'],
                                                            2
                                                        );
                                                        ?>

                                                    </div>
                                                </div>
                                                <div class="col-6">

                                                    <strong>
                                                        Registered
                                                    </strong>

                                                    <div>

                                                        <?php
                                                        echo date(
                                                            'd M Y H:i',
                                                            strtotime(
                                                                $customer['created_at']
                                                            )
                                                        );
                                                        ?>

                                                    </div>
                                                </div>
                                                <div class="col-6">

                                                    <strong>
                                                        Last Login
                                                    </strong>

                                                    <div>

                                                        <?php if (!empty($customer['last_login_at'])): ?>

                                                            <?php
                                                            echo date(
                                                                'd M Y H:i',
                                                                strtotime(
                                                                    $customer['last_login_at']
                                                                )
                                                            );
                                                            ?>

                                                        <?php else: ?>

                                                            Never

                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button
                                                type="button"
                                                class="btn btn-secondary"
                                                data-bs-dismiss="modal"
                                            >
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