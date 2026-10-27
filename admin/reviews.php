
<?php
require_once "auth.php";
require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

if (empty($_SESSION['admin_reviews_csrf'])) {
    $_SESSION['admin_reviews_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['admin_reviews_csrf'];

function escapeHtml($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
$message = $_SESSION['admin_reviews_message'] ?? '';
unset($_SESSION['admin_reviews_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($_SESSION['admin_reviews_csrf'], $postedToken)
    ) {
        $_SESSION['admin_reviews_message'] =
            "Invalid security token. Please try again.";
        header("Location: reviews.php");
        exit;
    }

    $reviewId = filter_input(INPUT_POST, 'review_id', FILTER_VALIDATE_INT);
    $newStatus = $_POST['new_status'] ?? '';

    if (
        !$reviewId ||
        !in_array($newStatus, ['published', 'hidden'], true)
    ) {
        $_SESSION['admin_reviews_message'] = "Invalid review action.";
        header("Location: reviews.php");
        exit;
    }

    try {
        $stmt = $db->prepare(
            "UPDATE product_reviews SET status = ? WHERE id = ?"
        );
        $stmt->execute([$newStatus, $reviewId]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['admin_reviews_message'] =
                $newStatus === 'hidden'
                    ? "Review hidden successfully."
                    : "Review published successfully.";
        } else {
            $check = $db->prepare(
                "SELECT status FROM product_reviews WHERE id = ?"
            );
            $check->execute([$reviewId]);
            $existingStatus = $check->fetchColumn();

            if ($existingStatus === false) {
                $_SESSION['admin_reviews_message'] = "Review not found.";
            } else {
                $_SESSION['admin_reviews_message'] =
                    $existingStatus === $newStatus
                        ? "The review already has that status."
                        : "Unable to update the review.";
            }
        }
    } catch (Throwable $e) {
        error_log("Admin review action failed: " . $e->getMessage());
        $_SESSION['admin_reviews_message'] = "Unable to update the review.";
    }

    $queryString = http_build_query([
        'search' => trim((string)($_POST['search'] ?? '')),
        'status' => (string)($_POST['status_filter'] ?? 'all'),
        'page' => max(1, (int)($_POST['page'] ?? 1))
    ]);

    header("Location: reviews.php?" . $queryString);
    exit;
}

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? 'all');

if (!in_array($statusFilter, ['all', 'published', 'hidden'], true)) {
    $statusFilter = 'all';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$where = [];
$params = [];

if ($statusFilter !== 'all') {
    $where[] = "r.status = ?";
    $params[] = $statusFilter;
}

if ($search !== '') {
    $where[] = "(p.title LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
    $searchTerm = '%' . $search . '%';

    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countSql = "
    SELECT COUNT(*)
    FROM product_reviews r
    INNER JOIN products p ON p.id = r.product_id
    INNER JOIN users u ON u.id = r.user_id
    $whereSql
";

$countStmt = $db->prepare($countSql);
$countStmt->execute($params);

$totalReviews = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalReviews / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listSql = "
    SELECT
        r.id,
        r.product_id,
        r.user_id,
        r.rating,
        r.review_title,
        r.review_text,
        r.is_verified_purchase,
        r.status,
        r.created_at,
        p.title AS product_title,
        u.name AS customer_name,
        u.email AS customer_email
    FROM product_reviews r
    INNER JOIN products p ON p.id = r.product_id
    INNER JOIN users u ON u.id = r.user_id
    $whereSql
    ORDER BY r.created_at DESC, r.id DESC
    LIMIT ? OFFSET ?
";

$listStmt = $db->prepare($listSql);
$position = 1;

foreach ($params as $param) {
    $listStmt->bindValue($position++, $param, PDO::PARAM_STR);
}

$listStmt->bindValue($position++, $perPage, PDO::PARAM_INT);
$listStmt->bindValue($position, $offset, PDO::PARAM_INT);

$listStmt->execute();
$reviews = $listStmt->fetchAll(PDO::FETCH_ASSOC);
$summaryStmt = $db->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'published'), 0) AS published,
        COALESCE(SUM(status = 'hidden'), 0) AS hidden
    FROM product_reviews
");

$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Reviews | Admin Dashboard</title>

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
            z-index: 1000;
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
            font-size: 14px;
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

        .stat-card,
        .table-card {
            background: #fff;
            border: 0;
            border-radius: 14px;
            box-shadow: 0 3px 15px rgba(15, 23, 42, .05);
        }

        .stat-card {
            padding: 22px;
            height: 100%;
        }

        .stat-label {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 27px;
            font-weight: 700;
        }

        .table-card {
            padding: 22px;
        }

        .table th {
            color: #64748b;
            font-size: 12px;
            text-transform: uppercase;
            white-space: nowrap;
            background: #f8fafc;
        }

        .table td {
            vertical-align: middle;
            font-size: 13px;
        }

        .review-text {
            min-width: 220px;
            max-width: 350px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            line-height: 1.5;
        }

        .review-title {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .review-stars {
            color: #f59e0b;
            white-space: nowrap;
            font-size: 16px;
        }

        .verified {
            display: inline-block;
            color: #166534;
            font-size: 11px;
            font-weight: 600;
            margin-top: 5px;
        }

        .small-muted {
            color: #6b7280;
            font-size: 12px;
        }

        .badge {
            font-weight: 600;
            padding: 7px 9px;
        }

        .pagination-area {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid #e5e7eb;
        }

        .pagination .page-link {
            color: #374151;
            border-radius: 6px;
            margin: 0 2px;
        }

        .pagination .page-item.active .page-link {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }

        .pagination .page-item.disabled .page-link {
            color: #9ca3af;
        }

        .empty-state {
            text-align: center;
            color: #6b7280;
            padding: 45px 15px !important;
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
    <a href="reviews.php" class="active">Product Reviews</a>
    <a href="profile.php">Admin Profile</a>
    <a href="../outh/logout.php">Logout</a>
</aside>

<main class="main-content">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="page-title mb-1">Product Reviews</h1>
            <p class="text-secondary mb-0">
                Manage customer ratings and product feedback.
            </p>
        </div>

        <div class="text-secondary small">
            Total reviews:
            <strong><?= (int)$summary['total'] ?></strong>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?= escapeHtml($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"
                    aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">

        <div class="col-6 col-xl-4">
            <div class="stat-card">
                <div class="stat-label">Total Reviews</div>
                <div class="stat-number"><?= (int)$summary['total'] ?></div>
            </div>
        </div>

        <div class="col-6 col-xl-4">
            <div class="stat-card">
                <div class="stat-label">Published Reviews</div>
                <div class="stat-number text-success">
                    <?= (int)$summary['published'] ?>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-4">
            <div class="stat-card">
                <div class="stat-label">Hidden Reviews</div>
                <div class="stat-number text-danger">
                    <?= (int)$summary['hidden'] ?>
                </div>
            </div>
        </div>

    </div>

    <div class="table-card">

        <form method="GET" action="reviews.php" class="row g-2 mb-4">

            <div class="col-12 col-lg-6">
                <input
                    type="search"
                    name="search"
                    class="form-control"
                    placeholder="Search product, customer, or email..."
                    value="<?= escapeHtml($search) ?>"
                >
            </div>

            <div class="col-12 col-md-5 col-lg-3">
                <select name="status" class="form-select">
                    <option value="all"
                        <?= $statusFilter === 'all' ? 'selected' : '' ?>>
                        All Reviews
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

            <div class="col-6 col-md-3 col-lg-1 d-grid">
                <button type="submit" class="btn btn-dark">Search</button>
            </div>

            <div class="col-6 col-md-4 col-lg-2 d-grid">
                <a href="reviews.php" class="btn btn-outline-secondary">Reset Filters</a>
            </div>

        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">

                <thead>
                <tr>
                    <th>Product</th>
                    <th>Customer</th>
                    <th>Rating</th>
                    <th>Review</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                </thead>

                <tbody>

                <?php if (!empty($reviews)): ?>

                    <?php foreach ($reviews as $review): ?>
                        <tr>

                            <td>
                                <div class="fw-semibold">
                                    <?= escapeHtml($review['product_title']) ?>
                                </div>
                                <div class="small-muted">
                                    ID: <?= (int)$review['product_id'] ?>
                                </div>
                            </td>

                            <td>
                                <div class="fw-semibold">
                                    <?= escapeHtml($review['customer_name']) ?>
                                </div>
                                <div class="small-muted">
                                    <?= escapeHtml($review['customer_email']) ?>
                                </div>
                            </td>

                            <td>
                                <div class="review-stars">
                                    <?= str_repeat('★', (int)$review['rating']) ?>
                                    <?= str_repeat('☆', 5 - (int)$review['rating']) ?>
                                </div>
                                <div class="small-muted">
                                    <?= (int)$review['rating'] ?>/5
                                </div>
                            </td>

                            <td>
                                <?php if (!empty($review['review_title'])): ?>
                                    <div class="review-title">
                                        <?= escapeHtml($review['review_title']) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="review-text">
                                    <?= escapeHtml($review['review_text']) ?>
                                </div>

                                <?php if ((int)$review['is_verified_purchase'] === 1): ?>
                                    <div class="verified">✓ Verified purchase</div>
                                <?php endif; ?>
                            </td>

                            <td class="text-nowrap">
                                <?= escapeHtml(date(
                                    'd M Y',
                                    strtotime($review['created_at'])
                                )) ?>

                                <div class="small-muted">
                                    <?= escapeHtml(date(
                                        'h:i A',
                                        strtotime($review['created_at'])
                                    )) ?>
                                </div>
                            </td>

                            <td>
                                <?php if ($review['status'] === 'published'): ?>
                                    <span class="badge text-bg-success">Published</span>
                                <?php else: ?>
                                    <span class="badge text-bg-danger">Hidden</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <form
                                    method="POST"
                                    action="reviews.php"
                                     class="review-action-form"
                                >
                                    <input type="hidden" name="csrf_token"
                                           value="<?= escapeHtml($csrfToken) ?>">

                                    <input type="hidden" name="review_id"
                                           value="<?= (int)$review['id'] ?>">

                                    <input type="hidden" name="search"
                                           value="<?= escapeHtml($search) ?>">

                                    <input type="hidden" name="status_filter"
                                           value="<?= escapeHtml($statusFilter) ?>">

                                    <input type="hidden" name="page"
                                           value="<?= (int)$page ?>">

                                    <?php if ($review['status'] === 'published'): ?>

                                        <input type="hidden" name="new_status"
                                               value="hidden">

                                        <button type="submit"
                                                class="btn btn-sm btn-outline-danger text-nowrap">
                                            Hide Review
                                        </button>

                                    <?php else: ?>

                                        <input type="hidden" name="new_status"
                                               value="published">

                                        <button type="submit"
                                                class="btn btn-sm btn-outline-success text-nowrap">
                                            Republish
                                        </button>

                                    <?php endif; ?>

                                </form>
                            </td>

                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="7" class="empty-state">
                            <div class="fs-5 mb-2">No reviews found</div>
                            <div>Try changing your search or status filter.</div>
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

        <div class="pagination-area">

            <div class="small text-secondary">
                Showing
                <strong><?= $totalReviews > 0 ? $offset + 1 : 0 ?></strong>
                to
                <strong><?= min($offset + $perPage, $totalReviews) ?></strong>
                of
                <strong><?= (int)$totalReviews ?></strong>
                reviews
                <span class="ms-1">(10 per page)</span>
            </div>

            <?php if ($totalPages > 1): ?>

                <?php
                $baseParams = [
                    'search' => $search,
                    'status' => $statusFilter
                ];

                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                ?>

                <nav aria-label="Review pagination">
                    <ul class="pagination pagination-sm mb-0">

                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <?php if ($page > 1): ?>
                                <a class="page-link"
                                   href="?<?= escapeHtml(http_build_query(
                                       $baseParams + ['page' => $page - 1]
                                   )) ?>">
                                    Previous
                                </a>
                            <?php else: ?>
                                <span class="page-link">Previous</span>
                            <?php endif; ?>
                        </li>

                        <?php if ($startPage > 1): ?>
                            <li class="page-item">
                                <a class="page-link"
                                   href="?<?= escapeHtml(http_build_query(
                                       $baseParams + ['page' => 1]
                                   )) ?>">1</a>
                            </li>

                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">…</span>
                                </li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <?php if ($i === $page): ?>
                                    <span class="page-link" aria-current="page">
                                        <?= $i ?>
                                    </span>
                                <?php else: ?>
                                    <a class="page-link"
                                       href="?<?= escapeHtml(http_build_query(
                                           $baseParams + ['page' => $i]
                                       )) ?>">
                                        <?= $i ?>
                                    </a>
                                <?php endif; ?>
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
                                   href="?<?= escapeHtml(http_build_query(
                                       $baseParams + ['page' => $totalPages]
                                   )) ?>">
                                    <?= $totalPages ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <?php if ($page < $totalPages): ?>
                                <a class="page-link"
                                   href="?<?= escapeHtml(http_build_query(
                                       $baseParams + ['page' => $page + 1]
                                   )) ?>">
                                    Next
                                </a>
                            <?php else: ?>
                                <span class="page-link">Next</span>
                            <?php endif; ?>
                        </li>

                    </ul>
                </nav>

            <?php endif; ?>

        </div>

    </div>

</main>
   <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.querySelectorAll('form.review-action-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        event.preventDefault();

        const statusInput = form.querySelector('[name="new_status"]');
        const action = statusInput.value;
        const isHiding = action === 'hidden';

        Swal.fire({
            title: isHiding ? 'Hide this review?' : 'Publish this review?',
            text: isHiding
                ? 'This review will no longer be visible on the storefront.'
                : 'This review will become visible on the storefront.',
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
        }).then(function (result) {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
});
</script>

</body>
</html>
