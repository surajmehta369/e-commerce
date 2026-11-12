
<?php

require_once __DIR__ . "/includes/layout.php";
require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!adminVerifyCsrf()) {
        $_SESSION['error_message'] = 'Security validation failed. Please try again.';
        header("Location: notifications.php");
        exit;
    }

    $action = $_POST['action'] ?? '';
    $messageId = (int) ($_POST['message_id'] ?? 0);

    if ($action === 'mark_replied' && $messageId > 0) {

        $stmt = $db->prepare("
            UPDATE contact_messages
            SET status = 'replied'
            WHERE id = ?
        ");

        $stmt->execute([$messageId]);

        $_SESSION['success_message'] = 'Message marked as replied successfully.';

        header("Location: notifications.php?id=" . $messageId);
        exit;
    }
}
$message = null;

if (isset($_GET['id'])) {

    $messageId = (int) $_GET['id'];

    if ($messageId > 0) {
        $stmt = $db->prepare("
            UPDATE contact_messages
            SET status = 'read'
            WHERE id = ?
            AND status = 'new'
        ");

        $stmt->execute([$messageId]);
        $stmt = $db->prepare("
            SELECT *
            FROM contact_messages
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$messageId]);

        $message = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

$stmt = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS unread,
        SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) AS read_count,
        SUM(CASE WHEN status = 'replied' THEN 1 ELSE 0 END) AS replied
    FROM contact_messages
");

$counts = $stmt->fetch(PDO::FETCH_ASSOC);

$totalMessages = (int) ($counts['total'] ?? 0);
$unreadMessages = (int) ($counts['unread'] ?? 0);
$readMessages = (int) ($counts['read_count'] ?? 0);
$repliedMessages = (int) ($counts['replied'] ?? 0);
$filter = $_GET['status'] ?? 'all';

$allowedFilters = ['all', 'new', 'read', 'replied'];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

if ($filter === 'all') {

    $stmt = $db->query("
        SELECT *
        FROM contact_messages
        ORDER BY created_at DESC
    ");

} else {

    $stmt = $db->prepare("
        SELECT *
        FROM contact_messages
        WHERE status = ?
        ORDER BY created_at DESC
    ");

    $stmt->execute([$filter]);
}

$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
function notificationStatusBadge($status)
{
    switch ($status) {

        case 'new':
            return '<span class="badge bg-primary">New</span>';

        case 'read':
            return '<span class="badge bg-secondary">Read</span>';

        case 'replied':
            return '<span class="badge bg-success">Replied</span>';

        default:
            return '<span class="badge bg-dark">Unknown</span>';
    }
}

adminHeader('Notifications', 'notifications');

?>

<div class="page-header">

    <div>
        <h2>Notifications</h2>

        <small class="text-muted">
            Manage customer enquiries received through Contact Us.
        </small>
    </div>

    <?php if ($message): ?>

        <a href="notifications.php"
           class="btn btn-outline-secondary">

            <i class="bi bi-arrow-left me-1"></i>
            Back to Notifications

        </a>

    <?php endif; ?>

</div>
<div class="row g-3 mb-4">

    <div class="col-6 col-xl-3">

        <div class="card h-100">
            <div class="card-body">

                <div class="text-muted small">
                    Total Messages
                </div>

                <h3 class="fw-bold mb-0 mt-2">
                    <?= $totalMessages; ?>
                </h3>

            </div>
        </div>

    </div>


    <div class="col-6 col-xl-3">

        <div class="card h-100">
            <div class="card-body">

                <div class="text-muted small">
                    Unread Messages
                </div>

                <h3 class="fw-bold text-primary mb-0 mt-2">
                    <?= $unreadMessages; ?>
                </h3>

            </div>
        </div>

    </div>


    <div class="col-6 col-xl-3">

        <div class="card h-100">
            <div class="card-body">

                <div class="text-muted small">
                    Read Messages
                </div>

                <h3 class="fw-bold text-secondary mb-0 mt-2">
                    <?= $readMessages; ?>
                </h3>

            </div>
        </div>

    </div>


    <div class="col-6 col-xl-3">

        <div class="card h-100">
            <div class="card-body">

                <div class="text-muted small">
                    Replied Messages
                </div>

                <h3 class="fw-bold text-success mb-0 mt-2">
                    <?= $repliedMessages; ?>
                </h3>

            </div>
        </div>

    </div>

</div>


<?php if ($message): ?>
    <?php if (!$message): ?>

        <div class="alert alert-warning">
            Notification not found.
        </div>

    <?php else: ?>

        <div class="card">

            <div class="card-header bg-white p-3">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                    <h5 class="mb-0">
                        Contact Enquiry Details
                    </h5>

                    <?= notificationStatusBadge($message['status']); ?>

                </div>

            </div>


            <div class="card-body p-4">

                <div class="row g-4">

                    <div class="col-md-6">

                        <label class="text-muted small">
                            Customer Name
                        </label>

                        <div class="fw-semibold">
                            <?= e($message['name']); ?>
                        </div>

                    </div>


                    <div class="col-md-6">

                        <label class="text-muted small">
                            Email Address
                        </label>

                        <div class="fw-semibold">
                            <a href="mailto:<?= e($message['email']); ?>">
                                <?= e($message['email']); ?>
                            </a>
                        </div>

                    </div>


                    <div class="col-md-6">

                        <label class="text-muted small">
                            Subject
                        </label>

                        <div class="fw-semibold">
                            <?= e($message['subject']); ?>
                        </div>

                    </div>


                    <div class="col-md-6">

                        <label class="text-muted small">
                            Received On
                        </label>

                        <div class="fw-semibold">
                            <?= date(
                                'd M Y, h:i A',
                                strtotime($message['created_at'])
                            ); ?>
                        </div>

                    </div>


                    <div class="col-12">

                        <hr>

                        <label class="text-muted small mb-2">
                            Customer Message
                        </label>

                        <div class="p-3 bg-light rounded"
                             style="white-space: pre-wrap; line-height: 1.7;">
                            <?= e($message['message']); ?>
                        </div>

                    </div>

                </div>


                <hr class="my-4">


                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                    <a href="mailto:<?= e($message['email']); ?>"
                       class="btn btn-outline-primary">

                        <i class="bi bi-envelope me-1"></i>
                        Reply Through Email

                    </a>


                    <?php if ($message['status'] !== 'replied'): ?>

                        <form method="POST"
                              action="notifications.php?id=<?= (int) $message['id']; ?>">

                            <input type="hidden"
                                   name="csrf_token"
                                   value="<?= e(adminCsrfToken()); ?>">

                            <input type="hidden"
                                   name="action"
                                   value="mark_replied">

                            <input type="hidden"
                                   name="message_id"
                                   value="<?= (int) $message['id']; ?>">

                            <button type="submit"
                                    class="btn btn-success">

                                <i class="bi bi-check-circle me-1"></i>
                                Mark as Replied

                            </button>

                        </form>

                    <?php else: ?>

                        <span class="text-success fw-semibold">

                            <i class="bi bi-check-circle-fill me-1"></i>
                            This message has been marked as replied.

                        </span>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    <?php endif; ?>


<?php else: ?>
    <div class="card">

        <div class="card-header bg-white p-3">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

                <h5 class="mb-0">
                    Customer Enquiries
                </h5>


                <div class="d-flex flex-wrap gap-2">

                    <a href="notifications.php"
                       class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary'; ?>">

                        All (<?= $totalMessages; ?>)

                    </a>


                    <a href="notifications.php?status=new"
                       class="btn btn-sm <?= $filter === 'new' ? 'btn-primary' : 'btn-outline-secondary'; ?>">

                        New (<?= $unreadMessages; ?>)

                    </a>


                    <a href="notifications.php?status=read"
                       class="btn btn-sm <?= $filter === 'read' ? 'btn-primary' : 'btn-outline-secondary'; ?>">

                        Read (<?= $readMessages; ?>)

                    </a>


                    <a href="notifications.php?status=replied"
                       class="btn btn-sm <?= $filter === 'replied' ? 'btn-primary' : 'btn-outline-secondary'; ?>">

                        Replied (<?= $repliedMessages; ?>)

                    </a>

                </div>

            </div>

        </div>


        <div class="card-body p-0">

            <div class="table-responsive">

                <table class="table table-hover align-middle mb-0">

                    <thead class="table-light">

                        <tr>

                            <th class="ps-3">Customer</th>

                            <th>Email</th>

                            <th>Subject</th>

                            <th>Date</th>

                            <th>Status</th>

                            <th class="text-end pe-3">Action</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (empty($messages)): ?>

                            <tr>

                                <td colspan="6"
                                    class="text-center text-muted py-5">

                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>

                                    No customer enquiries found.

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($messages as $item): ?>

                                <tr>

                                    <td class="ps-3">

                                        <div class="fw-semibold">
                                            <?= e($item['name']); ?>
                                        </div>

                                    </td>


                                    <td>
                                        <?= e($item['email']); ?>
                                    </td>


                                    <td>
                                        <?= e($item['subject']); ?>
                                    </td>


                                    <td class="text-nowrap">

                                        <?= date(
                                            'd M Y',
                                            strtotime($item['created_at'])
                                        ); ?>

                                    </td>


                                    <td>
                                        <?= notificationStatusBadge($item['status']); ?>
                                    </td>


                                    <td class="text-end pe-3">

                                        <a href="notifications.php?id=<?= (int) $item['id']; ?>"
                                           class="btn btn-sm btn-outline-primary">

                                            <i class="bi bi-eye me-1"></i>
                                            View

                                        </a>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

<?php endif; ?>


<?php adminFooter(); ?>
