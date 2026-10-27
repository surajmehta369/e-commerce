<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/connection/dbconnect.php';

$database = new Database();
$db = $database->connect();
if (
    empty($_SESSION['logged_in']) ||
    empty($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'customer'
) {
    $_SESSION['wishlist_message'] = [
        'type' => 'warning',
        'text' => 'Please log in as a customer to view your wishlist.'
    ];

    $_SESSION['wishlist_login_redirect'] = 'wishlist.php';

    header('Location: outh/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

if (empty($_SESSION['wishlist_csrf_token'])) {
    $_SESSION['wishlist_csrf_token'] = bin2hex(random_bytes(32));
}

$wishlistCsrfToken = $_SESSION['wishlist_csrf_token'];
$wishlistMessage = $_SESSION['wishlist_message'] ?? null;
unset($_SESSION['wishlist_message']);

$stmt = $db->prepare("
    SELECT
        w.id AS wishlist_id,
        w.variant_id,
        w.image_path AS wishlist_image,
        w.created_at,

        p.id,
        p.title,
        p.image,
        p.price,
        p.original_price,
        p.stock,
        p.sku,

        pv.id AS saved_variant_id,
        pv.variant_title AS saved_variant_title,
        pv.price AS saved_variant_price,
        pv.original_price AS saved_variant_original_price,
        pv.stock AS saved_variant_stock,
        pv.sku AS saved_variant_sku,

        (
            SELECT pvi.image_path
            FROM product_variant_images pvi
            WHERE pvi.variant_id = pv.id
              AND pvi.status = 1
              AND pvi.image_path IS NOT NULL
              AND pvi.image_path <> ''
            ORDER BY pvi.sort_order ASC, pvi.id ASC
            LIMIT 1
        ) AS saved_variant_image,

        (
            SELECT pvi.image_path
            FROM product_variants pv2
            INNER JOIN product_variant_images pvi
                ON pvi.variant_id = pv2.id
            WHERE pv2.product_id = p.id
              AND pv2.status = 1
              AND pvi.status = 1
              AND pvi.image_path IS NOT NULL
              AND pvi.image_path <> ''
            ORDER BY pv2.id ASC, pvi.sort_order ASC, pvi.id ASC
            LIMIT 1
        ) AS variant_image,

        (
            SELECT COUNT(*)
            FROM product_variants pv2
            WHERE pv2.product_id = p.id
              AND pv2.status = 1
        ) AS variant_count,

        (
            SELECT MIN(pv2.price)
            FROM product_variants pv2
            WHERE pv2.product_id = p.id
              AND pv2.status = 1
              AND pv2.stock > 0
        ) AS lowest_variant_price,

        (
            SELECT SUM(pv2.stock)
            FROM product_variants pv2
            WHERE pv2.product_id = p.id
              AND pv2.status = 1
        ) AS total_variant_stock

    FROM wishlists w

    INNER JOIN products p
        ON p.id = w.product_id

    LEFT JOIN product_variants pv
        ON pv.id = w.variant_id
       AND pv.product_id = p.id

    WHERE w.user_id = :user_id
      AND p.status = 1

    ORDER BY w.created_at DESC, w.id DESC
");

$stmt->execute([':user_id' => $userId]);
$wishlistProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/components/header.php';
include __DIR__ . '/components/sidebar.php';
?>

<style>
    .wishlist-page {
        margin-top: 100px;
        padding-bottom: 50px;
    }

    .wishlist-card {
        height: 100%;
        background: #fff;
        border: 1px solid #e9ecef;
        border-radius: 16px;
        overflow: hidden;
        transition: transform .2s ease, box-shadow .2s ease;
    }

    .wishlist-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 28px rgba(0, 0, 0, .08);
    }

    .wishlist-image {
        height: 220px;
        background: #f8f9fa;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }

    .wishlist-image img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .wishlist-product-title {
        color: #212529;
        font-weight: 600;
        text-decoration: none;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        min-height: 48px;
    }

    .wishlist-product-title:hover {
        color: #0d6efd;
    }

    .wishlist-price {
        font-size: 1.35rem;
        font-weight: 700;
        color: #b12704;
    }

    .wishlist-empty {
        padding: 70px 20px;
        text-align: center;
        background: #fff;
        border: 1px dashed #ced4da;
        border-radius: 18px;
    }

    .wishlist-empty-icon {
        font-size: 64px;
        color: #dc3545;
        margin-bottom: 20px;
    }

    .wishlist-remove-form {
        display: inline;
    }

    @media (max-width: 575px) {
        .wishlist-image {
            height: 180px;
        }
    }
</style>

<main class="container-fluid wishlist-page">

    <div class="container-fluid">

        <div class="d-flex flex-wrap justify-content-between
                    align-items-center gap-3 mb-4">

            <div>
                <h1 class="fw-bold mb-1">
                    <i class="fa-solid fa-heart text-danger me-2"></i>
                    My Wishlist
                </h1>

                <p class="text-muted mb-0">
                    Products you've saved for later.
                </p>
            </div>

            <a href="index.php"
                class="btn btn-outline-primary rounded-pill px-4">
                <i class="fa-solid fa-arrow-left me-2"></i>
                Continue Shopping
            </a>

        </div>

        <?php if ($wishlistMessage && is_array($wishlistMessage)): ?>
            <div class="alert alert-<?=
                                    htmlspecialchars(
                                        $wishlistMessage['type'] ?? 'info',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    );
                                    ?> alert-dismissible fade show rounded-3" role="alert">

                <?= htmlspecialchars(
                    $wishlistMessage['text'] ?? '',
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>

                <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (empty($wishlistProducts)): ?>

            <div class="wishlist-empty shadow-sm">

                <div class="wishlist-empty-icon">
                    <i class="fa-regular fa-heart"></i>
                </div>

                <h2 class="fw-bold mb-3">
                    Your wishlist is empty
                </h2>

                <p class="text-muted mb-4">
                    Save products you love by clicking the heart icon
                    on a product's details page.
                </p>

                <a href="index.php"
                    class="btn btn-primary rounded-pill px-4 py-2">
                    <i class="fa-solid fa-bag-shopping me-2"></i>
                    Explore Products
                </a>

            </div>

        <?php else: ?>

            <div class="d-flex justify-content-between
                        align-items-center mb-3">

                <p class="text-muted mb-0">
                    <strong><?= count($wishlistProducts); ?></strong>
                    saved <?= count($wishlistProducts) === 1
                                ? 'product'
                                : 'products'; ?>
                </p>

            </div>

            <div class="row g-4">

                <?php foreach ($wishlistProducts as $item): ?>

                    <?php

                    $productId = (int) $item['id'];

                    $hasSavedVariant =
                        $item['variant_id'] !== null &&
                        $item['saved_variant_id'] !== null;

                    $hasVariants = (int) $item['variant_count'] > 0;

                    if ($hasSavedVariant) {
                        $displayPrice = (float) $item['saved_variant_price'];
                        $originalPrice = (float) ($item['saved_variant_original_price'] ?? 0);
                        $availableStock = (int) $item['saved_variant_stock'];
                        $displaySku = $item['saved_variant_sku'] ?? $item['sku'];
                        $displayVariantTitle = $item['saved_variant_title'] ?? '';
                    } elseif ($hasVariants) {
                        $displayPrice = $item['lowest_variant_price'] !== null
                            ? (float) $item['lowest_variant_price']
                            : (float) $item['price'];

                        $originalPrice = (float) ($item['original_price'] ?? 0);
                        $availableStock = (int) ($item['total_variant_stock'] ?? 0);
                        $displaySku = $item['sku'];
                        $displayVariantTitle = '';
                    } else {
                        $displayPrice = (float) $item['price'];
                        $originalPrice = (float) ($item['original_price'] ?? 0);
                        $availableStock = (int) $item['stock'];
                        $displaySku = $item['sku'];
                        $displayVariantTitle = '';
                    }

                    $isAvailable = $availableStock > 0;
                    $productImage = trim((string) ($item['wishlist_image'] ?? ''));

                    if ($productImage === '') {
                        $productImage = trim((string) ($item['saved_variant_image'] ?? ''));
                    }

                    if ($productImage === '') {
                        $productImage = trim((string) ($item['image'] ?? ''));
                    }

                    $discountPercent = $originalPrice > $displayPrice && $originalPrice > 0
                        ? (int) round(
                            (($originalPrice - $displayPrice) / $originalPrice) * 100
                        )
                        : 0;

                    ?>

                    <div class="col-12 col-sm-6 col-lg-4 col-xl-3">

                        <article class="wishlist-card">

                            <a href="product-details.php?id=<?= $productId; ?>"
                                class="wishlist-image"
                                aria-label="View <?= htmlspecialchars(
                                                        $item['title'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ); ?>">

                                <?php if ($productImage !== ''): ?>

                                    <img
                                        src="<?= htmlspecialchars(
                                                    $productImage,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>"
                                        alt="<?= htmlspecialchars(
                                                    $item['title'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>"
                                        loading="lazy">

                                <?php else: ?>

                                    <i class="fa-regular fa-image
                                              text-secondary"
                                        style="font-size: 48px;"></i>

                                <?php endif; ?>

                            </a>

                            <div class="p-3">

                                <a href="product-details.php?id=<?= $productId; ?>"
                                    class="wishlist-product-title">

                                    <?= htmlspecialchars(
                                        $item['title'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ); ?>

                                </a>


                                <?php if ($hasSavedVariant): ?>
                                    <div class="mt-2">
                                        <span class="badge bg-primary-subtle text-primary border">
                                            <?= htmlspecialchars(
                                                $displayVariantTitle,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?>
                                        </span>
                                    </div>
                                <?php elseif ($hasVariants): ?>
                                    <span class="badge bg-light text-dark border mt-2">
                                        Multiple options
                                    </span>
                                <?php endif; ?>


                                <div class="d-flex flex-wrap
                                            align-items-center gap-2 mt-3">

                                    <span class="wishlist-price">
                                        ₹<?= number_format($displayPrice, 2); ?>
                                    </span>

                                    <?php if ($discountPercent > 0): ?>
                                        <span class="badge bg-danger">
                                            <?= $discountPercent; ?>% OFF
                                        </span>
                                    <?php endif; ?>

                                </div>

                                <?php if ($isAvailable): ?>

                                    <p class="text-success small mt-2 mb-3">
                                        <i class="fa-solid fa-circle-check me-1"></i>
                                        In Stock
                                    </p>

                                <?php else: ?>

                                    <p class="text-danger small mt-2 mb-3">
                                        <i class="fa-solid fa-circle-xmark me-1"></i>
                                        Out of Stock
                                    </p>

                                <?php endif; ?>

                                <div class="d-grid gap-2">

                                    <a href="product-details.php?id=<?= $productId; ?>"
                                        class="btn btn-primary rounded-pill">

                                        <i class="fa-solid fa-eye me-2"></i>
                                        View Product

                                    </a>

                                    <form method="POST"
                                        action="wishlist-action.php"
                                        class="wishlist-remove-form">

                                        <input type="hidden"
                                            name="product_id"
                                            value="<?= $productId; ?>">

                                        <input type="hidden"
                                            name="variant_id"
                                            value="<?= $item['variant_id'] === null
                                                        ? ''
                                                        : (int) $item['variant_id']; ?>">


                                        <input type="hidden"
                                            name="csrf_token"
                                            value="<?= htmlspecialchars(
                                                        $wishlistCsrfToken,
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ); ?>">

                                        <input type="hidden"
                                            name="return_to"
                                            value="wishlist.php">

                                        <button type="submit"
                                            name="action"
                                            value="remove"
                                            class="btn btn-outline-danger rounded-pill w-100 wishlist-remove-btn">

                                            <i class="fa-solid fa-heart-crack me-2"></i>
                                            Remove from Wishlist

                                        </button>

                                    </form>

                                </div>

                            </div>

                        </article>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</main>


<script>
    document.addEventListener('DOMContentLoaded', function() {
        const removeForms = document.querySelectorAll('.wishlist-remove-form');

        removeForms.forEach(function(form) {
            form.addEventListener('submit', function(event) {
                event.preventDefault();

                if (typeof Swal === 'undefined') {
                    if (confirm('Remove this product from your wishlist?')) {
                        submitRemoveForm(form);
                    }
                    return;
                }

                Swal.fire({
                    title: 'Remove from Wishlist?',
                    text: 'This product will be removed from your saved items.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, remove it',
                    cancelButtonText: 'Keep it',
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    reverseButtons: true,
                    focusCancel: true
                }).then(function(result) {
                    if (result.isConfirmed) {
                        submitRemoveForm(form);
                    }
                });
            });
        });

        function submitRemoveForm(form) {

            let actionInput = form.querySelector('input[name="action"]');

            if (!actionInput) {
                actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                form.appendChild(actionInput);
            }

            actionInput.value = 'remove';
            form.submit();
        }
    });
</script>


<?php include __DIR__ . '/components/footer.php'; ?>