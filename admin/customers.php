<?php

require_once __DIR__ . "/includes/layout.php";
require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

$message = "";
$messageType = "success";

// CSRF protection (admin.js adds the token to every POST form automatically)
$csrfFailed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !adminVerifyCsrf()) {
    $csrfFailed = true;
    $message = 'Security token expired. Please try again.';
    $messageType = 'danger';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrfFailed) {

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
            } elseif ($action === 'deactivate') {

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
        u.name LIKE :search_name
        OR u.email LIKE :search_email
    )";

    $searchValue = '%' . $search . '%';
    $params[':search_name'] = $searchValue;
    $params[':search_email'] = $searchValue;
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
        return '<span class="badge bg-success">Active</span>';
    }

    return '<span class="badge bg-secondary">Inactive</span>';
}

function customerInitial($name)
{
    return strtoupper(substr(trim((string) $name), 0, 1));
}

adminHeader('Customers', 'customers');
?>

<style>
    .table th { font-size: 13px; color: #6b7280; }
    .table td { vertical-align: middle; }
    .customer-name { font-weight: 600; }
    .small-text { font-size: 12px; color: #6b7280; }
    .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
    .action-buttons form { display: inline; }
    .filter-card { margin-bottom: 20px; }
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
</style>

<div class="page-header">
    <div>
        <h2>Customer Management</h2>
        <p class="text-muted mb-0">
            Manage customer accounts and view customer activity.
        </p>
    </div>
</div>

<?php if ($message !== ""): ?>
    <div class="alert alert-<?= e($messageType) ?> alert-dismissible fade show">
        <?= e($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card filter-card">
    <div class="card-body">
        <form method="GET">
            <div class="row g-3 align-items-end">

                <div class="col-md-7">
                    <label class="form-label">Search Customer</label>
                    <input type="text" name="search" class="form-control"
                        placeholder="Search by name or email"
                        value="<?= e($search) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Account Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Customers</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="customers.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </div>

            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Customers</h5>
            <span class="text-muted"><?= count($customers) ?> customer(s)</span>
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
                            <td colspan="8" class="text-center py-5 text-muted">
                                No customers found.
                            </td>
                        </tr>

                    <?php else: ?>
                        <?php foreach ($customers as $index => $customer): ?>

                            <tr>
                                <td><?= $index + 1 ?></td>

                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="customer-avatar">
                                            <?= e(customerInitial($customer['name'])) ?>
                                        </div>
                                        <div>
                                            <div class="customer-name"><?= e($customer['name']) ?></div>
                                            <div class="small-text"><?= e($customer['email']) ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td><?= customerStatusBadge($customer['status']) ?></td>

                                <td><span class="fw-semibold"><?= (int) $customer['order_count'] ?></span></td>

                                <td><strong>₹<?= number_format((float) $customer['total_spent'], 2) ?></strong></td>

                                <td>
                                    <?php if (!empty($customer['last_login_at'])): ?>
                                        <?= date('d M Y H:i', strtotime($customer['last_login_at'])) ?>
                                    <?php else: ?>
                                        <span class="text-muted">Never</span>
                                    <?php endif; ?>
                                </td>

                                <td><?= date('d M Y', strtotime($customer['created_at'])) ?></td>

                                <td>
                                    <div class="action-buttons">

                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#customerModal<?= (int) $customer['id'] ?>">
                                            View
                                        </button>

                                        <?php if ((int) $customer['status'] === 0): ?>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="activate">
                                                <input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success"
                                                    onclick="return confirm('Activate this customer account?');">
                                                    Activate
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ((int) $customer['status'] === 1): ?>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="deactivate">
                                                <input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('Deactivate this customer account?');">
                                                    Deactivate
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                    </div>
                                </td>
                            </tr>

                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php /* Modals are rendered outside the table (a <div> inside <tbody> is invalid HTML). */ ?>
<?php foreach ($customers as $customer): ?>
    <div class="modal fade" id="customerModal<?= (int) $customer['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title">Customer Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <div class="text-center mb-4">
                        <div class="customer-avatar mx-auto mb-2">
                            <?= e(customerInitial($customer['name'])) ?>
                        </div>
                        <h5 class="mb-1"><?= e($customer['name']) ?></h5>
                        <div class="text-muted"><?= e($customer['email']) ?></div>
                    </div>

                    <div class="row g-3">

                        <div class="col-6">
                            <strong>Customer ID</strong>
                            <div>#<?= (int) $customer['id'] ?></div>
                        </div>

                        <div class="col-6">
                            <strong>Status</strong>
                            <div><?= customerStatusBadge($customer['status']) ?></div>
                        </div>

                        <div class="col-6">
                            <strong>Total Orders</strong>
                            <div><?= (int) $customer['order_count'] ?></div>
                        </div>

                        <div class="col-6">
                            <strong>Total Spent</strong>
                            <div>₹<?= number_format((float) $customer['total_spent'], 2) ?></div>
                        </div>

                        <div class="col-6">
                            <strong>Registered</strong>
                            <div><?= date('d M Y H:i', strtotime($customer['created_at'])) ?></div>
                        </div>

                        <div class="col-6">
                            <strong>Last Login</strong>
                            <div>
                                <?php if (!empty($customer['last_login_at'])): ?>
                                    <?= date('d M Y H:i', strtotime($customer['last_login_at'])) ?>
                                <?php else: ?>
                                    Never
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>

            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php adminFooter();