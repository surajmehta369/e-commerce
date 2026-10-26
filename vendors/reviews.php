<?php
require_once "auth.php";


$database = new Database();
$db = $database->connect();

$vendorId = (int) $_SESSION['user_id'];

if (empty($_SESSION['vendor_reviews_csrf'])) {
    $_SESSION['vendor_reviews_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['vendor_reviews_csrf'];

$message = '';
$error = '';

function escapeHtml($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        $error = 'Security validation failed. Please refresh the page.';
    } else {
        $reviewId = filter_var(
            $_POST['review_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $newStatus = $_POST['new_status'] ?? '';

        if (
            !$reviewId ||
            !in_array($newStatus, ['published', 'hidden'], true)
        ) {
            $error = 'Invalid review action.';
        } else {

            $updateStmt = $db->prepare("
                UPDATE product_reviews AS pr
                INNER JOIN products AS p ON p.id = pr.product_id
                SET pr.status = :new_status
                WHERE pr.id = :review_id
                  AND p.vendor_id = :vendor_id
                  AND pr.status <> :current_status
            ");

            $updateStmt->execute([
                'new_status' => $newStatus,
                'review_id' => $reviewId,
                'vendor_id' => $vendorId,
                'current_status' => $newStatus
            ]);

            if ($updateStmt->rowCount() > 0) {
                $message = $newStatus === 'hidden'
                    ? 'Review hidden successfully.'
                    : 'Review published successfully.';
            } else {
                $checkStmt = $db->prepare("
                    SELECT pr.status
                    FROM product_reviews pr
                    INNER JOIN products p ON p.id = pr.product_id
                    WHERE pr.id = :review_id
                      AND p.vendor_id = :vendor_id
                    LIMIT 1
                ");

                $checkStmt->execute([
                    'review_id' => $reviewId,
                    'vendor_id' => $vendorId
                ]);

                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    $error = 'Review not found or you do not have permission to manage it.';
                } else {
                    $message = 'The review already has this status.';
                }
            }
        }
    }
}

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';

if (!in_array($statusFilter, ['all', 'published', 'hidden'], true)) {
    $statusFilter = 'all';
}

$page = filter_var(
    $_GET['page'] ?? 1,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$page = $page ?: 1;

$perPage = 10;

$where = ['p.vendor_id = :vendor_id'];
$params = ['vendor_id' => $vendorId];

if ($search !== '') {
    $where[] = "(
        p.title LIKE :product_search
        OR u.name LIKE :customer_search
        OR u.email LIKE :email_search
        OR pr.review_title LIKE :title_search
        OR pr.review_text LIKE :text_search
    )";

    $term = '%' . $search . '%';

    $params['product_search'] = $term;
    $params['customer_search'] = $term;
    $params['email_search'] = $term;
    $params['title_search'] = $term;
    $params['text_search'] = $term;
}

if ($statusFilter !== 'all') {
    $where[] = 'pr.status = :status_filter';
    $params['status_filter'] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

$summaryStmt = $db->prepare("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(pr.status = 'published'), 0) AS published,
        COALESCE(SUM(pr.status = 'hidden'), 0) AS hidden
    FROM product_reviews pr
    INNER JOIN products p ON p.id = pr.product_id
    WHERE p.vendor_id = :vendor_id
");

$summaryStmt->execute(['vendor_id' => $vendorId]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

if (!$summary) {
    $summary = ['total' => 0, 'published' => 0, 'hidden' => 0];
}
$countStmt = $db->prepare("
    SELECT COUNT(*)
    FROM product_reviews pr
    INNER JOIN products p ON p.id = pr.product_id
    INNER JOIN users u ON u.id = pr.user_id
    WHERE $whereSql
");

$countStmt->execute($params);
$totalReviews = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalReviews / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;
$reviewsStmt = $db->prepare("
    SELECT
        pr.id,
        pr.product_id,
        pr.rating,
        pr.review_title,
        pr.review_text,
        pr.is_verified_purchase,
        pr.status,
        pr.created_at,
        p.title AS product_title,
        u.name AS customer_name,
        u.email AS customer_email
    FROM product_reviews pr
    INNER JOIN products p ON p.id = pr.product_id
    INNER JOIN users u ON u.id = pr.user_id
    WHERE $whereSql
    ORDER BY pr.created_at DESC, pr.id DESC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $reviewsStmt->bindValue(
        ':' . $key,
        $value,
        $key === 'vendor_id' ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}

$reviewsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$reviewsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$reviewsStmt->execute();
$reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

function reviewPageUrl(int $page, string $search, string $status): string
{
    return '?' . http_build_query([
        'search' => $search,
        'status' => $status,
        'page' => $page
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Product Reviews | Vendor Dashboard</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">
</head>

<body>

    <div class="container-fluid">
        <div class="row">
            <div class="col-md-2 bg-dark text-white min-vh-100 p-3">

                <h4 class="mb-4">Vendor Panel</h4>

                <ul class="nav flex-column">

                    <li class="nav-item mb-2">
                        <a href="index.php" class="nav-link text-white">
                            Dashboard
                        </a>
                    </li>

                    <li class="nav-item mb-2">
                        <a href="products.php" class="nav-link text-white">
                            Products
                        </a>
                    </li>

                    <li class="nav-item mb-2">
                        <a href="orders.php" class="nav-link text-white">
                            Orders
                        </a>
                    </li>

                    <li class="nav-item mb-2">
                        <a href="reviews.php"
                            class="nav-link active bg-secondary text-white rounded"
                            aria-current="page">
                            Product Reviews
                        </a>
                    </li>

                    <li class="nav-item mb-2">
                        <a href="profile.php" class="nav-link text-white">
                            Store Profile
                        </a>
                    </li>

                    <li class="nav-item mt-3">
                        <a href="../outh/logout.php" class="nav-link text-danger">
                            Logout
                        </a>
                    </li>

                </ul>
            </div>
            <div class="col-md-10 p-4">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                    <div>
                        <h2>Product Reviews</h2>
                        <p class="text-muted mb-0">
                            Welcome,
                            <?= escapeHtml($_SESSION['user_name'] ?? 'Vendor') ?>.
                            Manage feedback for your products.
                        </p>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= escapeHtml($message) ?>
                        <button type="button" class="btn-close"
                            data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= escapeHtml($error) ?>
                        <button type="button" class="btn-close"
                            data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <div class="row g-4 mb-4">

                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <h6 class="text-muted">Total Reviews</h6>
                                <h2><?= (int) $summary['total'] ?></h2>
                                <p class="text-muted small mb-0">
                                    Reviews on your products
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <h6 class="text-muted">Published Reviews</h6>
                                <h2 class="text-success">
                                    <?= (int) $summary['published'] ?>
                                </h2>
                                <p class="text-muted small mb-0">
                                    Currently visible on the storefront
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <h6 class="text-muted">Hidden Reviews</h6>
                                <h2 class="text-danger">
                                    <?= (int) $summary['hidden'] ?>
                                </h2>
                                <p class="text-muted small mb-0">
                                    Not currently visible on the storefront
                                </p>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="card shadow-sm mb-4">
                    <div class="card-body">

                        <h5 class="card-title mb-3">Search Reviews</h5>

                        <form method="GET" class="row g-3 align-items-end">

                            <div class="col-md-6">
                                <label for="search" class="form-label">
                                    Product, customer, email, or review
                                </label>
                                <input
                                    type="search"
                                    id="search"
                                    name="search"
                                    class="form-control"
                                    placeholder="Enter search text..."
                                    value="<?= escapeHtml($search) ?>">
                            </div>

                            <div class="col-md-3">
                                <label for="status" class="form-label">
                                    Status
                                </label>
                                <select name="status" id="status" class="form-select">
                                    <option value="all"
                                        <?= $statusFilter === 'all' ? 'selected' : '' ?>>
                                        All statuses
                                    </option>
                                    <option value="published"
                                        <?= $statusFilter === 'published' ? 'selected' : '' ?>>
                                        Published
                                    </option>
                                    <option value="hidden"
                                        <?= $statusFilter === 'hidden' ? 'selected' : '' ?>>
                                        Hidden
                                    </option>
                                </select>
                            </div>

                            <div class="col-md-3 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    Search
                                </button>
                                <a href="reviews.php" class="btn btn-outline-secondary">
                                    Reset
                                </a>
                            </div>

                        </form>
                    </div>
                </div>
                <div class="card shadow-sm">

                    <div class="card-body border-bottom">
                        <h5 class="card-title mb-1">Customer Feedback</h5>
                        <p class="text-muted small mb-0">
                            <?= $totalReviews ?> matching review(s)
                        </p>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">

                            <thead class="table-light">
                                <tr>
                                    <th>Product / Customer</th>
                                    <th>Rating</th>
                                    <th>Review</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php if (empty($reviews)): ?>

                                    <tr>
                                        <td colspan="6" class="text-center py-5">
                                            <h6>No reviews found</h6>
                                            <p class="text-muted mb-0">
                                                Try another search or filter.
                                            </p>
                                        </td>
                                    </tr>

                                <?php else: ?>

                                    <?php foreach ($reviews as $review): ?>
                                        <tr>

                                            <td>
                                                <strong>
                                                    <?= escapeHtml($review['product_title']) ?>
                                                </strong>

                                                <div class="mt-2">
                                                    <?= escapeHtml($review['customer_name']) ?>
                                                </div>

                                                <div class="text-muted small">
                                                    <?= escapeHtml($review['customer_email']) ?>
                                                </div>

                                                <?php if ((int) $review['is_verified_purchase'] === 1): ?>
                                                    <span class="badge bg-primary-subtle text-primary mt-2">
                                                        Verified purchase
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-nowrap">
                                                <span class="text-warning">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <?= $i <= (int) $review['rating'] ? '★' : '☆' ?>
                                                    <?php endfor; ?>
                                                </span>

                                                <div class="small text-muted">
                                                    <?= (int) $review['rating'] ?>/5
                                                </div>
                                            </td>

                                            <td style="min-width: 220px; max-width: 360px;">
                                                <?php if (!empty($review['review_title'])): ?>
                                                    <strong>
                                                        <?= escapeHtml($review['review_title']) ?>
                                                    </strong>
                                                    <br>
                                                <?php endif; ?>

                                                <div style="overflow-wrap:anywhere;">
                                                    <?= nl2br(escapeHtml($review['review_text'])) ?>
                                                </div>
                                            </td>

                                            <td>
                                                <?php if ($review['status'] === 'published'): ?>
                                                    <span class="badge bg-success">
                                                        Published
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">
                                                        Hidden
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-nowrap">
                                                <?= escapeHtml(date(
                                                    'd M Y',
                                                    strtotime($review['created_at'])
                                                )) ?>
                                            </td>

                                            <td>
                                                <form method="POST" class="review-action-form">

                                                    <input type="hidden"
                                                        name="csrf_token"
                                                        value="<?= escapeHtml($csrfToken) ?>">

                                                    <input type="hidden"
                                                        name="review_id"
                                                        value="<?= (int) $review['id'] ?>">

                                                    <?php if ($review['status'] === 'published'): ?>

                                                        <input type="hidden"
                                                            name="new_status"
                                                            value="hidden">

                                                        <button type="submit"
                                                            class="btn btn-sm btn-outline-danger">
                                                            Hide
                                                        </button>

                                                    <?php else: ?>

                                                        <input type="hidden"
                                                            name="new_status"
                                                            value="published">

                                                        <button type="submit"
                                                            class="btn btn-sm btn-outline-success">
                                                            Publish
                                                        </button>

                                                    <?php endif; ?>

                                                </form>
                                            </td>

                                        </tr>
                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </tbody>
                        </table>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <div class="card-body border-top d-flex flex-wrap justify-content-between align-items-center gap-3">

                            <span class="text-muted small">
                                Page <?= $page ?> of <?= $totalPages ?>
                            </span>

                            <nav aria-label="Review pages">
                                <ul class="pagination pagination-sm mb-0">

                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link"
                                            href="<?= escapeHtml(reviewPageUrl(max(1, $page - 1), $search, $statusFilter)) ?>">
                                            Previous
                                        </a>
                                    </li>

                                    <?php
                                    $startPage = max(1, $page - 2);
                                    $endPage = min($totalPages, $page + 2);
                                    ?>

                                    <?php if ($startPage > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link"
                                                href="<?= escapeHtml(reviewPageUrl(1, $search, $statusFilter)) ?>">
                                                1
                                            </a>
                                        </li>
                                        <?php if ($startPage > 2): ?>
                                            <li class="page-item disabled">
                                                <span class="page-link">…</span>
                                            </li>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                            <a class="page-link"
                                                href="<?= escapeHtml(reviewPageUrl($i, $search, $statusFilter)) ?>">
                                                <?= $i ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>

                                    <?php if ($endPage < $totalPages): ?>
                                        <?php if ($endPage < $totalPages - 1): ?>
                                            <li class="page-item disabled">
                                                <span class="page-link">…</span>
                                            </li>
                                        <?php endif; ?>

                                        <li class="page-item">
                                            <a class="page-link"
                                                href="<?= escapeHtml(reviewPageUrl($totalPages, $search, $statusFilter)) ?>">
                                                <?= $totalPages ?>
                                            </a>
                                        </li>
                                    <?php endif; ?>

                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                        <a class="page-link"
                                            href="<?= escapeHtml(reviewPageUrl(min($totalPages, $page + 1), $search, $statusFilter)) ?>">
                                            Next
                                        </a>
                                    </li>

                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>

                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        document.querySelectorAll('form.review-action-form').forEach(function(form) {
            form.addEventListener('submit', function(event) {
                event.preventDefault();

                const statusInput = form.querySelector('[name="new_status"]');
                const action = statusInput.value;
                const isHiding = action === 'hidden';

                Swal.fire({
                    title: isHiding ? 'Hide this review?' : 'Publish this review?',
                    text: isHiding ?
                        'This review will no longer be visible on the storefront.' :
                        'This review will become visible on the storefront.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: isHiding ? 'Yes, hide it' : 'Yes, publish it',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: isHiding ? '#dc3545' : '#198754',
                    cancelButtonColor: '#6c757d',
                    reverseButtons: true,
                    focusCancel: true,
                    customClass: {
                        popup: 'rounded-4',
                        confirmButton: 'rounded-3 px-4',
                        cancelButton: 'rounded-3 px-4'
                    },
                    buttonsStyling: true
                }).then(function(result) {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            });
        });
    </script>
</body>

</html>