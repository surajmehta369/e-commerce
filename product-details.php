<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once "connection/dbconnect.php";

$productId = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;


if ($productId <= 0) {

    header("Location: index.php");
    exit;
}


$database = new Database();
$db = $database->connect();


$sql = "
    SELECT
        p.id,
        p.sku,
        p.title,
        p.description,
        p.image,
        p.price,
        p.original_price,
        p.discount,
        p.stock,
        p.category_id,
        p.brand_id,
        p.status,

        c.name AS category_name,
        b.name AS brand_name

    FROM products p

    LEFT JOIN categories c
        ON p.category_id = c.id

    LEFT JOIN brands b
        ON p.brand_id = b.id

    WHERE p.id = :product_id
      AND p.status = 1

    LIMIT 1
";

$stmt = $db->prepare($sql);

$stmt->execute([
    ':product_id' => $productId
]);

$product = $stmt->fetch(PDO::FETCH_ASSOC);


$galleryImages = [];

$image = $product['image'] ?? '';
$title = $product['title'] ?? '';

if (!empty($image)) {
    $galleryImages[] = [
        'image_path' => $image,
        'alt_text' => $title
    ];
}

$imageStmt = $db->prepare("
    SELECT image_path, alt_text
    FROM product_images
    WHERE product_id = :product_id
      AND status = 1
    ORDER BY sort_order ASC, id ASC
");

$imageStmt->execute([
    ':product_id' => $productId
]);

$additionalImages = $imageStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($additionalImages as $galleryImage) {
    if (empty($galleryImage['image_path'])) {
        continue;
    }

    $alreadyExists = false;

    foreach ($galleryImages as $existingImage) {
        if ($existingImage['image_path'] === $galleryImage['image_path']) {
            $alreadyExists = true;
            break;
        }
    }

    if (!$alreadyExists) {
        $galleryImages[] = $galleryImage;
    }
}


if (!$product) {

    http_response_code(404);

    include "components/header.php";
    include "components/sidebar.php";

?>

    <main class="container-fluid" style="margin-top:100px;">

        <div class="card border-0 shadow-sm rounded-4 p-5 text-center">

            <div class="mb-3">

                <i
                    class="fa-solid fa-box-open text-muted"
                    style="font-size:60px;"></i>

            </div>

            <h2 class="fw-bold">
                Product Not Found
            </h2>

            <p class="text-muted">
                The product you are looking for does not exist
                or is no longer available.
            </p>

            <div>

                <a
                    href="index.php"
                    class="btn btn-primary rounded-pill px-4">
                    <i class="fa-solid fa-arrow-left me-2"></i>
                    Back to Shop
                </a>

            </div>

        </div>

    </main>

<?php

    include "components/footer.php";

    exit;
}


$title = $product['title'];

$description = $product['description'];

$image = $product['image'];

$price = (float)$product['price'];

$originalPrice = (float)$product['original_price'];

$discount = (float)$product['discount'];

$stock = (int)$product['stock'];

$categoryId = (int)$product['category_id'];

$brandId = (int)$product['brand_id'];

$categoryName = $product['category_name'] ?? 'Uncategorized';

$brandName = $product['brand_name'] ?? 'Unknown Brand';

$sku = $product['sku'];

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['review_csrf_token'])) {
    $_SESSION['review_csrf_token'] = bin2hex(random_bytes(32));
}

$reviewCsrfToken = $_SESSION['review_csrf_token'];
if (empty($_SESSION['wishlist_csrf_token'])) {
    $_SESSION['wishlist_csrf_token'] = bin2hex(random_bytes(32));
}

$wishlistCsrfToken = $_SESSION['wishlist_csrf_token'];
$isWishlisted = false;

if (
    !empty($_SESSION['logged_in']) &&
    (int) ($_SESSION['user_id'] ?? 0) > 0 &&
    ($_SESSION['user_role'] ?? '') === 'customer'
) {
    $wishlistCheckStmt = $db->prepare("
        SELECT 1
        FROM wishlists
        WHERE user_id = :user_id
          AND product_id = :product_id
        LIMIT 1
    ");

    $wishlistCheckStmt->execute([
        ':user_id' => (int) $_SESSION['user_id'],
        ':product_id' => $productId
    ]);

    $isWishlisted = (bool) $wishlistCheckStmt->fetchColumn();
}


$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentUserRole = $_SESSION['user_role'] ?? '';
$isLoggedInCustomer =
    !empty($_SESSION['logged_in']) &&
    $currentUserId > 0 &&
    $currentUserRole === 'customer';

$reviewMessage = $_GET['review_message'] ?? '';

$reviewMessages = [
    'login_required'       => ['Please log in with a customer account to submit a review.', 'warning'],
    'invalid_request'      => ['Your request could not be verified. Please try again.', 'danger'],
    'invalid_rating'       => ['Please select a rating from 1 to 5 stars.', 'warning'],
    'title_too_long'       => ['The review title must be 150 characters or fewer.', 'warning'],
    'invalid_review_text'  => ['Please enter a review of no more than 5,000 characters.', 'warning'],
    'account_not_eligible' => ['Your account is not eligible to submit a review.', 'warning'],
    'product_unavailable'  => ['This product is no longer available for review.', 'warning'],
    'purchase_required'    => ['You must have an eligible order for this product before reviewing it.', 'warning'],
    'review_saved'         => ['Your review has been saved successfully.', 'success'],
    'save_failed'          => ['We could not save your review. Please try again.', 'danger'],
];

$reviewNotice = $reviewMessages[$reviewMessage] ?? null;

$reviewStatsStmt = $db->prepare("
    SELECT
        COUNT(*) AS review_count,
        COALESCE(AVG(rating), 0) AS average_rating
    FROM product_reviews
    WHERE product_id = :product_id
      AND status = 'published'
");
$reviewStatsStmt->execute([':product_id' => $productId]);
$reviewStats = $reviewStatsStmt->fetch(PDO::FETCH_ASSOC);

$reviewCount = (int) ($reviewStats['review_count'] ?? 0);
$averageRating = (float) ($reviewStats['average_rating'] ?? 0);

$ratingDistribution = array_fill(1, 5, 0);

$distributionStmt = $db->prepare("
    SELECT rating, COUNT(*) AS total
    FROM product_reviews
    WHERE product_id = :product_id
      AND status = 'published'
    GROUP BY rating
");
$distributionStmt->execute([':product_id' => $productId]);

foreach ($distributionStmt->fetchAll(PDO::FETCH_ASSOC) as $distributionRow) {
    $ratingDistribution[(int) $distributionRow['rating']] =
        (int) $distributionRow['total'];
}

$reviewsStmt = $db->prepare("
    SELECT
        r.id,
        r.user_id,
        r.rating,
        r.review_title,
        r.review_text,
        r.is_verified_purchase,
        r.created_at,
        u.name AS customer_name
    FROM product_reviews r
    INNER JOIN users u ON u.id = r.user_id
    WHERE r.product_id = :product_id
      AND r.status = 'published'
    ORDER BY r.created_at DESC, r.id DESC
");
$reviewsStmt->execute([':product_id' => $productId]);
$productReviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);
$canReviewProduct = false;
$existingCustomerReview = null;

if ($isLoggedInCustomer) {
    $purchaseStmt = $db->prepare("
        SELECT 1
        FROM orders o
        INNER JOIN order_items oi ON oi.order_id = o.id
        WHERE o.user_id = :user_id
          AND oi.product_id = :product_id
          AND (
              (
                  o.payment_method = 'cash_on_delivery'
                  AND o.order_status = 'confirmed'
                  AND o.payment_status IN ('pending', 'paid')
              )
              OR
              (
                  o.payment_status = 'paid'
                  AND o.order_status <> 'cancelled'
              )
          )
        LIMIT 1
    ");

    $purchaseStmt->execute([
        ':user_id' => $currentUserId,
        ':product_id' => $productId,
    ]);

    $canReviewProduct = (bool) $purchaseStmt->fetchColumn();

    if ($canReviewProduct) {
        $existingReviewStmt = $db->prepare("
            SELECT id, rating, review_title, review_text
            FROM product_reviews
            WHERE product_id = :product_id
              AND user_id = :user_id
            LIMIT 1
        ");

        $existingReviewStmt->execute([
            ':product_id' => $productId,
            ':user_id' => $currentUserId,
        ]);

        $existingCustomerReview =
            $existingReviewStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}


$variantStmt = $db->prepare("
    SELECT id, product_id, variant_title, sku, price,
           original_price, stock, attributes, status
    FROM product_variants
    WHERE product_id = :product_id
      AND status = 1
    ORDER BY id ASC
");

$variantStmt->execute([
    ':product_id' => $productId
]);

$variants = $variantStmt->fetchAll(PDO::FETCH_ASSOC);

$hasVariants = !empty($variants);
$selectedVariant = null;


$variantImagesById = [];

if ($hasVariants) {
    $variantIds = array_map('intval', array_column($variants, 'id'));

    if (!empty($variantIds)) {
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));

        $variantImageStmt = $db->prepare("
            SELECT variant_id, image_path, alt_text
            FROM product_variant_images
            WHERE status = 1
              AND variant_id IN ($placeholders)
            ORDER BY variant_id ASC, sort_order ASC, id ASC
        ");

        $variantImageStmt->execute($variantIds);

        $variantImageRows = $variantImageStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($variantImageRows as $variantImage) {
            $variantId = (int) $variantImage['variant_id'];

            $variantImagesById[$variantId][] = [
                'image_path' => $variantImage['image_path'],
                'alt_text' => $variantImage['alt_text'] ?? ''
            ];
        }
    }
}


if ($hasVariants) {
    foreach ($variants as $variant) {
        if ((int)$variant['stock'] > 0) {
            $selectedVariant = $variant;
            break;
        }
    }
    if ($selectedVariant === null) {
        $selectedVariant = $variants[0];
    }
    $price = (float)$selectedVariant['price'];
    $originalPrice = (float)($selectedVariant['original_price'] ?? 0);
    $stock = (int)$selectedVariant['stock'];
    $sku = $selectedVariant['sku'] ?: $sku;
}

if ($stock <= 0) {
    $stockText = "Out of Stock";
    $stockClass = "text-danger";
} elseif ($stock <= 5) {
    $stockText = "Only {$stock} left in stock";
    $stockClass = "text-warning";
} else {
    $stockText = "In Stock";
    $stockClass = "text-success";
}



if ($stock <= 0) {

    $stockText = "Out of Stock";
    $stockClass = "text-danger";
} elseif ($stock <= 5) {

    $stockText = "Only {$stock} left in stock";
    $stockClass = "text-warning";
} else {

    $stockText = "In Stock";
    $stockClass = "text-success";
}


include "components/header.php";

include "components/sidebar.php";

?>

<style>
    /* Product details page */
    .product-details-card {
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        padding: 28px;
        background: #fff;
    }

    /* Main product image */
    .product-details-image {
        min-height: 420px;
        max-height: 560px;
        overflow: hidden;
        position: relative;
        background: #f8f9fa !important;
        border: 1px solid #e9ecef;
        border-radius: 14px;
        cursor: zoom-in;
    }

    .product-details-image img {
        width: 100%;
        height: 420px;
        object-fit: contain;
        transition: transform 0.3s ease;
    }

    .product-details-image:hover img {
        transform: scale(1.06);
    }

    /* Product information */
    .product-info-panel {
        padding: 8px 12px;
    }

    .product-info-panel h1 {
        font-size: clamp(1.5rem, 2.2vw, 2rem);
        line-height: 1.4;
        color: #212529;
    }

    .product-brand-badge {
        display: inline-block;
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #e2e8f0;
        padding: 7px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
    }

    #productPrice {
        color: #b12704 !important;
        font-size: 2rem;
    }

    #productVariant {
        min-height: 46px;
        border-color: #adb5bd;
    }

    #productVariant:focus {
        border-color: #f0ad4e;
        box-shadow: 0 0 0 3px rgba(240, 173, 78, 0.2);
    }

    #productStock {
        display: inline-block;
        font-size: 0.95rem;
    }

    .shop-btn.add-to-cart {
        min-height: 46px;
        width: 100%;
        border: 0;
        border-radius: 24px;
        font-weight: 600;
        transition: opacity 0.2s ease;
    }

    .shop-btn.add-to-cart:disabled {
        cursor: not-allowed;
        opacity: 0.6;
    }

    @media (max-width: 767px) {
        .product-details-card {
            padding: 14px;
        }

        .product-details-image {
            min-height: 280px;
        }

        .product-details-image img {
            height: 280px;
        }

        .product-info-panel {
            padding: 8px 0;
        }
    }


    .product-gallery {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        width: 100%;
    }

    .product-thumbnails {
        display: flex;
        flex-direction: column;
        gap: 10px;
        width: 68px;
        flex-shrink: 0;
        max-height: 430px;
        overflow-y: auto;
        padding: 2px;
    }

    .product-thumbnail {
        width: 64px;
        height: 64px;
        padding: 4px;
        border: 1px solid #d5d9d9;
        border-radius: 8px;
        background: #fff;
        cursor: pointer;
        flex-shrink: 0;
    }

    .product-thumbnail img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .product-thumbnail:hover,
    .product-thumbnail.active,
    .product-thumbnail:focus-visible {
        border: 2px solid #f0ad4e;
        outline: none;
    }

    .product-details-image {
        flex: 1;
        min-width: 0;
        min-height: 420px;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 16px;
        overflow: hidden;
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 12px;
    }

    .product-details-image img {
        width: 100%;
        height: 420px;
        object-fit: contain;
        transition: transform 0.25s ease;
    }

    .product-details-image:hover img {
        transform: scale(1.04);
    }

    @media (max-width: 767px) {
        .product-gallery {
            flex-direction: column-reverse;
        }

        .product-thumbnails {
            flex-direction: row;
            width: 100%;
            max-width: 100%;
            max-height: none;
            overflow-x: auto;
            overflow-y: hidden;
        }

        .product-details-image {
            width: 100%;
            min-height: 280px;
        }

        .product-details-image img {
            height: 280px;
        }
    }
</style>

<main class="container-fluid" style="margin-top:100px;">

    <div class="container-fluid">

        <div class="card product-details-card shadow-sm">

            <div class="row g-5 align-items-start">


                <div class="col-lg-6 col-md-6">


                    <div class="product-gallery">
                        <div class="product-thumbnails" id="productThumbnails">
                            <?php foreach ($galleryImages as $index => $galleryImage): ?>
                                <button
                                    type="button"
                                    class="product-thumbnail <?= $index === 0 ? 'active' : ''; ?>"
                                    data-image="<?= htmlspecialchars(
                                                    $galleryImage['image_path'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>"
                                    data-alt="<?= htmlspecialchars(
                                                    $galleryImage['alt_text'] ?: $title,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>"
                                    aria-label="View product image <?= $index + 1; ?>"
                                    aria-pressed="<?= $index === 0 ? 'true' : 'false'; ?>">

                                    <img
                                        src="<?= htmlspecialchars(
                                                    $galleryImage['image_path'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>"
                                        alt="<?= htmlspecialchars(
                                                    $galleryImage['alt_text'] ?: $title,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>"
                                        loading="lazy">
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <div class="product-details-image" id="productImageViewer">
                            <img
                                id="mainProductImage"
                                src="<?= htmlspecialchars($image ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                alt="<?= htmlspecialchars($title ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                loading="eager">
                        </div>
                    </div>



                </div>


                <div class="col-lg-6 col-md-6 product-info-panel">

                    <div class="mb-2">

                        <span class="product-brand-badge">

                            <?= htmlspecialchars($brandName); ?>

                        </span>

                    </div>

                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                        <h1 class="fw-bold mb-0">
                            <?= htmlspecialchars($title); ?>
                        </h1>

                        <form method="POST"
                            action="wishlist-action.php"
                            class="flex-shrink-0">

                            <input type="hidden"
                                name="product_id"
                                value="<?= (int) $productId; ?>">

                            <input type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars($wishlistCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                            <input type="hidden"
                                name="return_to"
                                value="product-details.php?id=<?= (int) $productId; ?>">

                            <button type="submit"
                                name="action"
                                value="<?= $isWishlisted ? 'remove' : 'add'; ?>"
                                class="btn <?= $isWishlisted ? 'btn-danger' : 'btn-outline-danger'; ?> rounded-circle"
                                style="width:46px;height:46px;"
                                aria-label="<?= $isWishlisted ? 'Remove from wishlist' : 'Add to wishlist'; ?>"
                                title="<?= $isWishlisted ? 'Remove from wishlist' : 'Add to wishlist'; ?>">

                                <i class="<?= $isWishlisted ? 'fa-solid' : 'fa-regular'; ?> fa-heart"></i>
                            </button>
                        </form>
                    </div>


                    <p class="text-muted mb-3">

                        SKU:
                        <strong id="productSku">
                            <?= htmlspecialchars($sku); ?>
                        </strong>

                    </p>

                    <p class="mb-3">

                        <span class="text-muted">
                            Category:
                        </span>

                        <strong>
                            <?= htmlspecialchars($categoryName); ?>
                        </strong>

                    </p>


                    <?php if ($hasVariants): ?>
                        <div class="mb-3">
                            <label for="productVariant" class="form-label fw-semibold">
                                Select Variant
                            </label>

                            <select
                                id="productVariant"
                                class="form-select rounded-3">
                                <?php foreach ($variants as $variant): ?>
                                    <?php
                                    $variantAttributes = json_decode(
                                        $variant['attributes'] ?? '',
                                        true
                                    );

                                    $variantLabel = $variant['variant_title'];

                                    if (is_array($variantAttributes)) {
                                        $attributeText = [];

                                        foreach ($variantAttributes as $key => $value) {
                                            $attributeText[] = $key . ': ' . $value;
                                        }

                                        if (!empty($attributeText)) {
                                            $variantLabel .= ' (' .
                                                implode(', ', $attributeText) . ')';
                                        }
                                    }
                                    ?>

                                    <option
                                        value="<?= (int)$variant['id']; ?>"
                                        data-price="<?= htmlspecialchars((string)$variant['price'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-original-price="<?= htmlspecialchars((string)($variant['original_price'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-stock="<?= (int)$variant['stock']; ?>"
                                        data-sku="<?= htmlspecialchars($variant['sku'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-title="<?= htmlspecialchars($variant['variant_title'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-attributes="<?= htmlspecialchars($variant['attributes'] ?? '{}', ENT_QUOTES, 'UTF-8'); ?>"
                                        <?= (int)$variant['id'] === (int)$selectedVariant['id'] ? 'selected' : ''; ?>

                                        data-images="<?= htmlspecialchars(
                                                            json_encode(
                                                                $variantImagesById[(int)$variant['id']] ?? [],
                                                                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                                                            ),
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ); ?>">
                                        <?= htmlspecialchars($variantLabel, ENT_QUOTES, 'UTF-8'); ?>
                                        — ₹<?= number_format((float)$variant['price'], 2); ?>
                                        <?= (int)$variant['stock'] <= 0 ? '(Out of Stock)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <hr>


                    <?php
                    $displayDiscount = $originalPrice > $price
                        ? (int) round((($originalPrice - $price) / $originalPrice) * 100)
                        : 0;
                    ?>

                    <div class="mb-3">
                        <div class="d-flex align-items-center flex-wrap gap-2">

                            <span id="productPrice" class="fs-2 fw-bold text-primary">
                                ₹<?= number_format($price, 2); ?>
                            </span>

                            <span
                                id="productOriginalPrice"
                                class="text-muted text-decoration-line-through <?= $originalPrice > $price ? '' : 'd-none'; ?>">
                                <?= $originalPrice > $price
                                    ? '₹' . number_format($originalPrice, 2)
                                    : ''; ?>
                            </span>

                            <span
                                id="productDiscount"
                                class="badge bg-danger <?= $displayDiscount > 0 ? '' : 'd-none'; ?>">
                                <?= $displayDiscount > 0 ? $displayDiscount . '% OFF' : ''; ?>
                            </span>

                        </div>
                    </div>


                    <div class="mb-4">

                        <strong id="productStock" class="<?= $stockClass; ?>">

                            <i
                                class="fa-solid fa-circle-check me-1"></i>

                            <?= htmlspecialchars($stockText); ?>

                        </strong>

                    </div>


                    <?php if (!empty($description)): ?>

                        <div class="mb-4">

                            <h5 class="fw-bold">
                                About this product
                            </h5>

                            <p class="text-muted">

                                <?= nl2br(
                                    htmlspecialchars($description)
                                ); ?>

                            </p>

                        </div>

                    <?php endif; ?>



                    <?php if ($stock > 0): ?>

                        <CodeBlock language="php" editable>
                            <button
                                type="button"
                                class="shop-btn add-to-cart"
                                data-id="<?= (int)$product['id']; ?>"
                                data-title="<?= htmlspecialchars($title ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                data-price="<?= htmlspecialchars((string)$price, ENT_QUOTES, 'UTF-8'); ?>"
                                data-image="<?= htmlspecialchars($image ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                data-stock="<?= (int)$stock; ?>"
                                data-variant-id="<?= $hasVariants ? (int)$selectedVariant['id'] : ''; ?>"
                                data-variant-title="<?= htmlspecialchars($hasVariants ? ($selectedVariant['variant_title'] ?? '') : '', ENT_QUOTES, 'UTF-8'); ?>"
                                data-variant-options="<?= htmlspecialchars($hasVariants ? ($selectedVariant['attributes'] ?? '{}') : '{}', ENT_QUOTES, 'UTF-8'); ?>"
                                data-sku="<?= htmlspecialchars($hasVariants ? ($selectedVariant['sku'] ?? '') : ($sku ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-cart-plus me-2"></i>
                                Add to Cart
                            </button>
                        </CodeBlock>
                    <?php else: ?>

                        <button
                            type="button"
                            class="btn btn-secondary rounded-pill px-4"
                            disabled>

                            Out of Stock

                        </button>

                    <?php endif; ?>



                </div>

            </div>

        </div>

    </div>


    <!-- ================= RATINGS & REVIEWS ================= -->
    <section class="container-fluid my-5" id="reviews">
        <div class="card border-0 shadow-sm rounded-4 p-4 p-lg-5">
            <h2 class="fw-bold mb-4">Customer Reviews</h2>

            <?php if ($reviewNotice): ?>
                <div class="alert alert-<?= htmlspecialchars($reviewNotice[1]); ?> rounded-3">
                    <?= htmlspecialchars($reviewNotice[0]); ?>
                </div>
            <?php endif; ?>

            <div class="row g-4 mb-5">
                <div class="col-md-4">
                    <div class="border rounded-4 p-4 h-100 text-center">
                        <div class="display-4 fw-bold">
                            <?= number_format($averageRating, 1); ?>
                        </div>

                        <div class="text-warning fs-4" aria-label="<?= number_format($averageRating, 1); ?> out of 5 stars">
                            <?php for ($star = 1; $star <= 5; $star++): ?>
                                <i class="fa-solid fa-star <?= $star <= round($averageRating) ? '' : 'text-secondary opacity-25'; ?>"></i>
                            <?php endfor; ?>
                        </div>

                        <p class="text-muted mb-0 mt-2">
                            <?= $reviewCount; ?>
                            <?= $reviewCount === 1 ? 'review' : 'reviews'; ?>
                        </p>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="border rounded-4 p-4 h-100">
                        <?php for ($star = 5; $star >= 1; $star--): ?>
                            <?php
                            $starCount = $ratingDistribution[$star];
                            $barPercent = $reviewCount > 0
                                ? ($starCount / $reviewCount) * 100
                                : 0;
                            ?>

                            <div class="d-flex align-items-center gap-3 mb-2">
                                <span class="text-nowrap"><?= $star; ?> star</span>

                                <div class="progress flex-grow-1" style="height: 10px;">
                                    <div
                                        class="progress-bar bg-warning"
                                        role="progressbar"
                                        style="width: <?= $barPercent; ?>%;"
                                        aria-valuenow="<?= round($barPercent); ?>"
                                        aria-valuemin="0"
                                        aria-valuemax="100">
                                    </div>
                                </div>

                                <span class="text-muted small" style="min-width: 25px;">
                                    <?= $starCount; ?>
                                </span>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <div class="border rounded-4 p-4 mb-5">
                <h4 class="fw-bold mb-3">
                    <?= $existingCustomerReview ? 'Edit Your Review' : 'Write a Review'; ?>
                </h4>

                <?php if (!$isLoggedInCustomer): ?>
                    <p class="text-muted mb-3">
                        Log in with a customer account and purchase this product to write a review.
                    </p>
                    <a href="login.php" class="btn btn-outline-primary rounded-pill px-4">
                        Log In
                    </a>

                <?php elseif (!$canReviewProduct): ?>
                    <p class="text-muted mb-0">
                        Reviews are available to customers with an eligible order for this product.
                    </p>

                <?php else: ?>
                    <form method="POST" action="submit-review.php">
                        <input type="hidden" name="product_id" value="<?= (int) $productId; ?>">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($reviewCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-3">
                            <label for="reviewRating" class="form-label fw-semibold">
                                Your Rating <span class="text-danger">*</span>
                            </label>

                            <select
                                class="form-select"
                                id="reviewRating"
                                name="rating"
                                required>
                                <option value="">Choose a rating</option>
                                <?php for ($ratingOption = 5; $ratingOption >= 1; $ratingOption--): ?>
                                    <option
                                        value="<?= $ratingOption; ?>"
                                        <?= (int) ($existingCustomerReview['rating'] ?? 0) === $ratingOption ? 'selected' : ''; ?>>
                                        <?= $ratingOption; ?> star<?= $ratingOption > 1 ? 's' : ''; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="reviewTitle" class="form-label">
                                Review Title <span class="text-muted">(optional)</span>
                            </label>
                            <input
                                type="text"
                                class="form-control"
                                id="reviewTitle"
                                name="review_title"
                                maxlength="150"
                                value="<?= htmlspecialchars($existingCustomerReview['review_title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="Summarize your experience">
                        </div>

                        <div class="mb-3">
                            <label for="reviewText" class="form-label fw-semibold">
                                Your Review <span class="text-danger">*</span>
                            </label>
                            <textarea
                                class="form-control"
                                id="reviewText"
                                name="review_text"
                                rows="5"
                                maxlength="5000"
                                required
                                placeholder="What did you think about this product?"><?= htmlspecialchars($existingCustomerReview['review_text'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary rounded-pill px-4">
                            <?= $existingCustomerReview ? 'Update Review' : 'Submit Review'; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div>
                <h4 class="fw-bold mb-4">Customer Feedback</h4>

                <?php if (empty($productReviews)): ?>
                    <p class="text-muted">No published reviews yet. Be the first to share your experience after an eligible purchase.</p>
                <?php else: ?>
                    <?php foreach ($productReviews as $review): ?>
                        <article class="border-bottom pb-4 mb-4">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <div>
                                    <h5 class="fw-semibold mb-1">
                                        <?= htmlspecialchars($review['customer_name'] ?: 'Customer'); ?>
                                    </h5>

                                    <?php if ((int) $review['is_verified_purchase'] === 1): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                                            <i class="fa-solid fa-circle-check me-1"></i>
                                            Verified Purchase
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <small class="text-muted">
                                    <?= date('d M Y', strtotime($review['created_at'])); ?>
                                </small>
                            </div>

                            <div class="text-warning my-2" aria-label="<?= (int) $review['rating']; ?> out of 5 stars">
                                <?php for ($star = 1; $star <= 5; $star++): ?>
                                    <i class="fa-solid fa-star <?= $star <= (int) $review['rating'] ? '' : 'text-secondary opacity-25'; ?>"></i>
                                <?php endfor; ?>
                            </div>

                            <?php if (!empty($review['review_title'])): ?>
                                <h6 class="fw-bold">
                                    <?= htmlspecialchars($review['review_title']); ?>
                                </h6>
                            <?php endif; ?>

                            <p class="text-muted mb-0" style="white-space: pre-line;"><?= htmlspecialchars($review['review_text']); ?></p>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <section class="container-fluid my-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold mb-0"> Related Products </h3> <a href="related-products.php?id=<?= (int)$product['id']; ?>" class="btn btn-outline-primary rounded-pill"> Add Filter </a>
        </div>
        <div id="relatedProducts" class="row g-4" data-product-id="<?= (int)$product['id']; ?>">
            <div class="col-12 text-center py-5">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="mt-2 text-muted"> Loading related products... </p>
            </div>
        </div>
    </section>
</main>
<div
    class="modal fade"
    id="quantityModal"
    tabindex="-1"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div
            class="modal-content
                   border-0
                   rounded-4
                   shadow">

            <div class="modal-header border-0">

                <h5 class="modal-title fw-bold">
                    Add to Cart
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"></button>

            </div>


            <div class="modal-body text-center px-4">


                <img
                    id="modalProductImage"
                    src=""
                    alt=""
                    style="
                        width:120px;
                        height:120px;
                        object-fit:cover;
                        border-radius:12px;
                    "
                    class="mb-3">


                <h5
                    id="modalProductTitle"
                    class="fw-bold mb-2"></h5>


                <p
                    id="modalProductPrice"
                    class="text-primary fw-bold fs-5"></p>

                <div class="mt-4">

                    <label
                        class="fw-semibold d-block mb-2">
                        Quantity
                    </label>


                    <div
                        class="d-flex
                               justify-content-center
                               align-items-center
                               gap-3">

                        <button
                            type="button"
                            id="quantityMinus"
                            class="btn
                                   btn-outline-secondary
                                   rounded-circle"
                            style="
                                width:40px;
                                height:40px;
                            ">
                            −
                        </button>


                        <input
                            type="number"
                            id="quantityInput"
                            value="1"
                            min="1"
                            class="form-control
                                   text-center
                                   fw-bold"
                            style="width:70px;">


                        <button
                            type="button"
                            id="quantityPlus"
                            class="btn
                                   btn-outline-primary
                                   rounded-circle"
                            style="
                                width:40px;
                                height:40px;
                            ">
                            +
                        </button>

                    </div>

                </div>

            </div>


            <div
                class="modal-footer
                       border-0
                       justify-content-center
                       pb-4">

                <button
                    type="button"
                    class="btn
                           btn-secondary
                           rounded-pill
                           px-4"
                    data-bs-dismiss="modal">
                    Cancel
                </button>


                <button
                    type="button"
                    id="confirmAddToCart"
                    class="btn
                           btn-primary
                           rounded-pill
                           px-4">

                    <i
                        class="fa-solid
                               fa-cart-plus
                               me-2"></i>

                    Add to Cart

                </button>

            </div>

        </div>

    </div>

</div>


<script>
    document.addEventListener("DOMContentLoaded", function() {
        const variantSelect = document.getElementById("productVariant");
        const addButton = document.querySelector(".add-to-cart");

        if (!variantSelect || !addButton) {
            return;
        }


        function updateSelectedVariant() {
            const option = variantSelect.options[variantSelect.selectedIndex];

            if (!option) return;

            const price = Number(option.dataset.price || 0);
            const originalPrice = Number(option.dataset.originalPrice || 0);
            const stock = Number(option.dataset.stock || 0);
            addButton.dataset.variantId = option.value;
            addButton.dataset.variantTitle = option.dataset.title || "";
            addButton.dataset.sku = option.dataset.sku || "";
            addButton.dataset.variantOptions = option.dataset.attributes || "{}";
            addButton.dataset.price = String(price);
            addButton.dataset.stock = String(stock);
            document.getElementById("productPrice").textContent =
                "₹" + price.toLocaleString("en-IN", {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            const originalPriceElement =
                document.getElementById("productOriginalPrice");

            if (originalPriceElement) {
                if (originalPrice > price) {
                    originalPriceElement.textContent =
                        "₹" + originalPrice.toLocaleString("en-IN", {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });

                    originalPriceElement.classList.remove("d-none");
                } else {
                    originalPriceElement.textContent = "";
                    originalPriceElement.classList.add("d-none");
                }
            }
            const discountElement = document.getElementById("productDiscount");
            const discount = originalPrice > price ?
                Math.round(((originalPrice - price) / originalPrice) * 100) :
                0;

            if (discountElement) {
                discountElement.textContent = discount > 0 ?
                    discount + "% OFF" :
                    "";

                discountElement.classList.toggle("d-none", discount <= 0);
            }
            const skuElement = document.getElementById("productSku");

            if (skuElement) {
                skuElement.textContent = option.dataset.sku || "";
            }
            const stockElement = document.getElementById("productStock");

            if (stockElement) {
                stockElement.classList.remove(
                    "text-success",
                    "text-warning",
                    "text-danger"
                );

                if (stock <= 0) {
                    stockElement.textContent = "Out of Stock";
                    stockElement.classList.add("text-danger");
                } else if (stock <= 5) {
                    stockElement.textContent = `Only ${stock} left in stock`;
                    stockElement.classList.add("text-warning");
                } else {
                    stockElement.textContent = "In Stock";
                    stockElement.classList.add("text-success");
                }
            }
            addButton.disabled = stock <= 0;

            const buttonLabel = addButton.querySelector(".add-to-cart-label");

            if (buttonLabel) {
                buttonLabel.textContent = stock <= 0 ?
                    "Out of Stock" :
                    "Add to Cart";
            }

            console.log("Selected variant:", {
                variant_id: option.value,
                variant_title: option.dataset.title,
                price: price,
                original_price: originalPrice,
                stock: stock,
                sku: option.dataset.sku
            });
        }


        variantSelect.addEventListener("change", updateSelectedVariant);
        updateSelectedVariant();
    });



    document.addEventListener("DOMContentLoaded", function() {
        const thumbnailsContainer = document.getElementById("productThumbnails");
        const mainImage = document.getElementById("mainProductImage");
        const variantSelect = document.getElementById("productVariant");
        const addButton = document.querySelector(".add-to-cart");

        if (!thumbnailsContainer || !mainImage) {
            return;
        }

        // Save the original product gallery as a fallback.
        const productImages = Array.from(
            thumbnailsContainer.querySelectorAll(".product-thumbnail")
        ).map(function(thumbnail) {
            return {
                image_path: thumbnail.dataset.image || "",
                alt_text: thumbnail.dataset.alt || ""
            };
        }).filter(function(image) {
            return image.image_path !== "";
        });

        // Display the selected gallery.
        function renderGallery(images) {
            thumbnailsContainer.innerHTML = "";

            images.forEach(function(image, index) {
                const imagePath = image.image_path || "";
                const imageAlt = image.alt_text || "";

                if (!imagePath) return;

                const button = document.createElement("button");
                button.type = "button";
                button.className =
                    "product-thumbnail" + (index === 0 ? " active" : "");

                button.dataset.image = imagePath;
                button.dataset.alt = imageAlt;
                button.setAttribute("aria-label", "View product image " + (index + 1));
                button.setAttribute("aria-pressed", index === 0 ? "true" : "false");

                const thumbnailImage = document.createElement("img");
                thumbnailImage.src = imagePath;
                thumbnailImage.alt = imageAlt;
                thumbnailImage.loading = "lazy";

                button.appendChild(thumbnailImage);
                thumbnailsContainer.appendChild(button);
            });

            // Show the first image in the main viewer.
            if (images.length > 0 && images[0].image_path) {
                mainImage.src = images[0].image_path;
                mainImage.alt = images[0].alt_text || "";

                if (addButton) {
                    addButton.dataset.image = images[0].image_path;
                }
            } else {
                mainImage.removeAttribute("src");

                if (addButton) {
                    addButton.dataset.image = "";
                }
            }
        }

        function updateVariantGallery() {
            if (!variantSelect) {
                renderGallery(productImages);
                return;
            }

            const option = variantSelect.options[variantSelect.selectedIndex];

            if (!option) {
                renderGallery(productImages);
                return;
            }

            let variantImages = [];

            try {
                variantImages = JSON.parse(option.dataset.images || "[]");
            } catch (error) {
                console.error("Could not read variant images:", error);
            }
            if (!Array.isArray(variantImages) || variantImages.length === 0) {
                renderGallery(productImages);
            } else {
                renderGallery(variantImages);
            }
        }
        thumbnailsContainer.addEventListener("click", function(event) {
            const thumbnail = event.target.closest(".product-thumbnail");

            if (!thumbnail || !thumbnailsContainer.contains(thumbnail)) {
                return;
            }

            const imagePath = thumbnail.dataset.image;
            const imageAlt = thumbnail.dataset.alt || "";

            if (!imagePath) return;

            mainImage.src = imagePath;
            mainImage.alt = imageAlt;

            thumbnailsContainer.querySelectorAll(".product-thumbnail").forEach(
                function(item) {
                    const isActive = item === thumbnail;
                    item.classList.toggle("active", isActive);
                    item.setAttribute("aria-pressed", isActive ? "true" : "false");
                }
            );

            if (addButton) {
                addButton.dataset.image = imagePath;
            }
        });
        if (variantSelect) {
            variantSelect.addEventListener("change", updateVariantGallery);
        }
        updateVariantGallery();

    });
</script>
<?php

include "components/footer.php";

?>