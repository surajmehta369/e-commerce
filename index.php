<?php

include "components/header.php";
include "components/sidebar.php";

require_once "connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

$banners = [
    [
        'image' => 'assets/uploads/main.jpg',
        'alt'   => 'Main Shop Banner',
        'link'  => '#'
    ],
    [
        'image' => 'assets/uploads/main2.jpg',
        'alt'   => 'Shop Banner 2',
        'link'  => '#'
    ],
    [
        'image' => 'assets/uploads/main3.jpg',
        'alt'   => 'Shop Banner 3',
        'link'  => '#'
    ]
];

$productsStmt = $db->prepare("
    SELECT
        id,
        sku,
        title,
        slug,
        description,
        image,
        price,
        original_price,
        discount,
        stock,
        category_id,
        brand_id
    FROM products
    WHERE status = 1
      AND stock > 0
    ORDER BY id DESC
    LIMIT 12
");

$productsStmt->execute();

$products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

$recommendationStmt = $db->prepare("
    SELECT
        id,
        title,
        description,
        image,
        price,
        original_price,
        discount,
        stock
    FROM products
    WHERE status = 1
      AND stock > 0
    ORDER BY id DESC
    LIMIT 4
");

$recommendationStmt->execute();

$recommendationProducts =
    $recommendationStmt->fetchAll(PDO::FETCH_ASSOC);

$smartphoneStmt = $db->prepare("
    SELECT
        id,
        title,
        image,
        price,
        original_price,
        discount,
        stock
    FROM products
    WHERE status = 1
      AND stock > 0
      AND category_id = 4
    ORDER BY id DESC
    LIMIT 4
");

$smartphoneStmt->execute();

$smartphoneProducts =
    $smartphoneStmt->fetchAll(PDO::FETCH_ASSOC);

$dealsStmt = $db->prepare("
    SELECT
        id,
        title,
        image,
        price,
        original_price,
        discount,
        stock
    FROM products
    WHERE status = 1
      AND stock > 0
    ORDER BY discount DESC, id DESC
    LIMIT 4
");

$dealsStmt->execute();

$dealProducts =
    $dealsStmt->fetchAll(PDO::FETCH_ASSOC);

$todayDealsStmt = $db->prepare("
    SELECT
        id,
        title,
        image,
        price,
        original_price,
        discount,
        stock
    FROM products
    WHERE status = 1
      AND stock > 0
    ORDER BY discount DESC, id DESC
    LIMIT 12
");

$todayDealsStmt->execute();

$todayDeals =
    $todayDealsStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<main class="homepage">

    <section class="hero-section">

        <div
            id="mainBannerCarousel"
            class="carousel slide hero-carousel"
            data-bs-ride="carousel"
            data-bs-interval="3500">

            <div class="carousel-inner">

                <?php foreach ($banners as $index => $banner): ?>

                    <div
                        class="carousel-item
                        <?= $index === 0 ? 'active' : ''; ?>">

                        <a href="<?= htmlspecialchars($banner['link']); ?>">

                            <img
                                src="<?= htmlspecialchars($banner['image']); ?>"
                                class="d-block w-100 main-banner"
                                alt="<?= htmlspecialchars($banner['alt']); ?>">

                        </a>

                    </div>

                <?php endforeach; ?>

            </div>


            <?php if (count($banners) > 1): ?>

                <button
                    class="carousel-control-prev"
                    type="button"
                    data-bs-target="#mainBannerCarousel"
                    data-bs-slide="prev">

                    <span class="carousel-control-prev-icon"></span>

                    <span class="visually-hidden">
                        Previous
                    </span>

                </button>


                <button
                    class="carousel-control-next"
                    type="button"
                    data-bs-target="#mainBannerCarousel"
                    data-bs-slide="next">

                    <span class="carousel-control-next-icon"></span>

                    <span class="visually-hidden">
                        Next
                    </span>

                </button>

            <?php endif; ?>

        </div>

    </section>

    <section
        id="searchResults"
        class="featured-section"
        style="display: none;">
    </section>

    <section
        class="featured-section"
        id="defaultProducts">

        <div class="container-fluid homepage-container">

            <div class="section-heading">

                <div>

                    <span class="section-label">
                        Featured
                    </span>

                    <h2>
                        Explore popular products
                    </h2>

                </div>

                <a href="related-products.php">
                    View all
                    <i class="fa-solid fa-arrow-right"></i>
                </a>

            </div>


            <?php if (empty($products)): ?>

                <div class="empty-products">
                    <i class="fa-solid fa-box-open"></i>

                    <h4>
                        No products available
                    </h4>

                    <p>
                        Please check back soon.
                    </p>
                </div>

            <?php else: ?>

                <div class="row g-4">

                    <?php foreach ($products as $product): ?>

                        <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6">

                            <article class="shop-card">


                                <!-- PRODUCT IMAGE -->

                                <div class="shop-card-image">

                                    <a
                                        href="product-details.php?id=<?= (int)$product['id']; ?>">

                                        <img
                                            src="<?= htmlspecialchars($product['image']); ?>"
                                            alt="<?= htmlspecialchars($product['title']); ?>"
                                            loading="lazy">

                                    </a>


                                    <?php if ((float)$product['discount'] > 0): ?>

                                        <span class="product-discount">

                                            <?= number_format(
                                                (float)$product['discount'],
                                                0
                                            ); ?>% OFF

                                        </span>

                                    <?php endif; ?>

                                </div>


                                <!-- PRODUCT DETAILS -->

                                <div class="shop-card-body">

                                    <h3>

                                        <a
                                            href="product-details.php?id=<?= (int)$product['id']; ?>">
                                            <?= htmlspecialchars($product['title']); ?>
                                        </a>

                                    </h3>


                                    <p class="product-description">

                                        <?= htmlspecialchars(
                                            $product['description']
                                        ); ?>

                                    </p>


                                    <div class="product-price-row">

                                        <span class="product-price">

                                            ₹<?= number_format(
                                                    (float)$product['price'],
                                                    2
                                                ); ?>

                                        </span>


                                        <?php if (
                                            !empty($product['original_price']) &&
                                            (float)$product['original_price'] >
                                            (float)$product['price']
                                        ): ?>

                                            <span class="product-original-price">

                                                ₹<?= number_format(
                                                        (float)$product['original_price'],
                                                        2
                                                    ); ?>

                                            </span>

                                        <?php endif; ?>

                                    </div>


                                    <?php if ((int)$product['stock'] <= 0): ?>

                                        <button
                                            type="button"
                                            class="shop-btn disabled"
                                            disabled>
                                            Out of Stock
                                        </button>

                                    <?php else: ?>

                                        <button
                                            type="button"
                                            class="shop-btn add-to-cart"
                                            data-id="<?= (int)$product['id']; ?>"
                                            data-title="<?= htmlspecialchars($product['title']); ?>"
                                            data-price="<?= htmlspecialchars($product['price']); ?>"
                                            data-image="<?= htmlspecialchars($product['image']); ?>">

                                            <i class="fa-solid fa-cart-plus"></i>

                                            Add to Cart

                                        </button>

                                    <?php endif; ?>

                                </div>

                            </article>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>

    </section>

    <section class="recommendation-section">

        <div class="container-fluid homepage-container">

            <div class="row g-4">


                <!-- BEST DEALS -->

                <div class="col-lg-4 col-md-6">

                    <div class="deal-card">

                        <div class="deal-card-header">

                            <h3>
                                Continue shopping deals
                            </h3>

                            <a href="related-products.php">
                                View all
                            </a>

                        </div>


                        <div class="deal-grid">

                            <?php if (empty($dealProducts)): ?>

                                <p class="text-muted">
                                    No deals available.
                                </p>

                            <?php else: ?>

                                <?php foreach ($dealProducts as $product): ?>

                                    <a
                                        href="product-details.php?id=<?= (int)$product['id']; ?>"
                                        class="mini-product">

                                        <div class="mini-product-image">

                                            <img
                                                src="<?= htmlspecialchars($product['image']); ?>"
                                                alt="<?= htmlspecialchars($product['title']); ?>"
                                                loading="lazy">

                                        </div>


                                        <div class="mini-product-info">

                                            <p>
                                                <?= htmlspecialchars(
                                                    $product['title']
                                                ); ?>
                                            </p>


                                            <strong>
                                                ₹<?= number_format(
                                                        (float)$product['price'],
                                                        2
                                                    ); ?>
                                            </strong>


                                            <?php if ((float)$product['discount'] > 0): ?>

                                                <span>
                                                    <?= number_format(
                                                        (float)$product['discount'],
                                                        0
                                                    ); ?>% off
                                                </span>

                                            <?php endif; ?>

                                        </div>

                                    </a>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

                <div class="col-lg-4 col-md-6">

                    <div class="deal-card">

                        <div class="deal-card-header">

                            <h3>
                                Recommended for you
                            </h3>

                            <a href="related-products.php">
                                View all
                            </a>

                        </div>


                        <div class="deal-grid">

                            <?php foreach ($recommendationProducts as $product): ?>

                                <a
                                    href="product-details.php?id=<?= (int)$product['id']; ?>"
                                    class="mini-product">

                                    <div class="mini-product-image">

                                        <img
                                            src="<?= htmlspecialchars($product['image']); ?>"
                                            alt="<?= htmlspecialchars($product['title']); ?>"
                                            loading="lazy">

                                    </div>


                                    <div class="mini-product-info">

                                        <p>
                                            <?= htmlspecialchars(
                                                $product['title']
                                            ); ?>
                                        </p>


                                        <strong>
                                            ₹<?= number_format(
                                                    (float)$product['price'],
                                                    2
                                                ); ?>
                                        </strong>

                                    </div>

                                </a>

                            <?php endforeach; ?>

                        </div>

                    </div>

                </div>
                <div class="col-lg-4 col-md-6">

                    <div class="deal-card">

                        <div class="deal-card-header">

                            <h3>
                                Smartphones curated for you
                            </h3>

                            <a href="related-products.php?category_id=4">
                                View all
                            </a>

                        </div>


                        <div class="deal-grid">

                            <?php if (empty($smartphoneProducts)): ?>

                                <p class="text-muted">
                                    No smartphones available.
                                </p>

                            <?php else: ?>

                                <?php foreach ($smartphoneProducts as $product): ?>

                                    <a
                                        href="product-details.php?id=<?= (int)$product['id']; ?>"
                                        class="mini-product">

                                        <div class="mini-product-image">

                                            <img
                                                src="<?= htmlspecialchars($product['image']); ?>"
                                                alt="<?= htmlspecialchars($product['title']); ?>"
                                                loading="lazy">

                                        </div>


                                        <div class="mini-product-info">

                                            <p>
                                                <?= htmlspecialchars(
                                                    $product['title']
                                                ); ?>
                                            </p>


                                            <strong>
                                                ₹<?= number_format(
                                                        (float)$product['price'],
                                                        2
                                                    ); ?>
                                            </strong>


                                            <?php if ((float)$product['discount'] > 0): ?>

                                                <span>
                                                    <?= number_format(
                                                        (float)$product['discount'],
                                                        0
                                                    ); ?>% off
                                                </span>

                                            <?php endif; ?>

                                        </div>

                                    </a>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>
    <section
        class="today-deals-section"
        id="todayDeals">

        <div class="container-fluid homepage-container">

            <div class="today-deals-box">


                <div class="today-deals-header">

                    <div>

                        <span class="section-label">
                            Limited time
                        </span>

                        <h2>
                            Today's Deals
                        </h2>

                    </div>

                    <a href="related-products.php">
                        See all deals
                        <i class="fa-solid fa-arrow-right"></i>
                    </a>

                </div>


                <?php if (empty($todayDeals)): ?>

                    <div class="empty-products">

                        <i class="fa-solid fa-tag"></i>

                        <h4>
                            No deals available right now
                        </h4>

                    </div>

                <?php else: ?>


                    <?php
                    $chunks = array_chunk($todayDeals, 4);
                    ?>


                    <div
                        id="productCarousel"
                        class="carousel slide"
                        data-bs-ride="carousel"
                        data-bs-interval="3500">

                        <div class="carousel-inner">


                            <?php foreach ($chunks as $slideIndex => $slideProducts): ?>

                                <div
                                    class="carousel-item
                                    <?= $slideIndex === 0 ? 'active' : ''; ?>">

                                    <div class="row g-3">


                                        <?php foreach ($slideProducts as $product): ?>

                                            <div
                                                class="col-xl-3 col-lg-3 col-md-4 col-sm-6">

                                                <article class="today-product">

                                                    <a
                                                        href="product-details.php?id=<?= (int)$product['id']; ?>"
                                                        class="today-product-image">

                                                        <img
                                                            src="<?= htmlspecialchars($product['image']); ?>"
                                                            alt="<?= htmlspecialchars($product['title']); ?>"
                                                            loading="lazy">

                                                    </a>


                                                    <a
                                                        href="product-details.php?id=<?= (int)$product['id']; ?>"
                                                        class="today-product-title">

                                                        <?= htmlspecialchars(
                                                            $product['title']
                                                        ); ?>

                                                    </a>


                                                    <div class="today-product-price">

                                                        ₹<?= number_format(
                                                                (float)$product['price'],
                                                                2
                                                            ); ?>


                                                        <?php if (
                                                            !empty($product['original_price']) &&
                                                            (float)$product['original_price'] >
                                                            (float)$product['price']
                                                        ): ?>

                                                            <span>

                                                                ₹<?= number_format(
                                                                        (float)$product['original_price'],
                                                                        2
                                                                    ); ?>

                                                            </span>

                                                        <?php endif; ?>

                                                    </div>


                                                    <?php if ((float)$product['discount'] > 0): ?>

                                                        <div class="today-discount">

                                                            <?= number_format(
                                                                (float)$product['discount'],
                                                                0
                                                            ); ?>% off

                                                        </div>

                                                    <?php endif; ?>


                                                    <button
                                                        type="button"
                                                        class="shop-btn add-to-cart mt-2"
                                                        data-id="<?= (int)$product['id']; ?>"
                                                        data-title="<?= htmlspecialchars($product['title']); ?>"
                                                        data-price="<?= htmlspecialchars($product['price']); ?>"
                                                        data-image="<?= htmlspecialchars($product['image']); ?>">

                                                        <i class="fa-solid fa-cart-plus"></i>

                                                        Add to Cart

                                                    </button>

                                                </article>

                                            </div>

                                        <?php endforeach; ?>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>


                        <?php if (count($chunks) > 1): ?>

                            <button
                                class="carousel-control-prev deals-control"
                                type="button"
                                data-bs-target="#productCarousel"
                                data-bs-slide="prev">

                                <span class="carousel-control-prev-icon"></span>

                                <span class="visually-hidden">
                                    Previous
                                </span>

                            </button>


                            <button
                                class="carousel-control-next deals-control"
                                type="button"
                                data-bs-target="#productCarousel"
                                data-bs-slide="next">

                                <span class="carousel-control-next-icon"></span>

                                <span class="visually-hidden">
                                    Next
                                </span>

                            </button>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </section>

    <div
        class="modal fade"
        id="quantityModal"
        tabindex="-1"
        aria-hidden="true">

        <div class="modal-dialog modal-dialog-centered">

            <div class="modal-content border-0 rounded-4 shadow">


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
                        class="modal-product-image mb-3">


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
                            class="d-flex justify-content-center align-items-center gap-3">

                            <button
                                type="button"
                                id="quantityMinus"
                                class="btn btn-outline-secondary rounded-circle quantity-button">
                                −
                            </button>


                            <input
                                type="number"
                                id="quantityInput"
                                value="1"
                                min="1"
                                class="form-control text-center fw-bold quantity-input">


                            <button
                                type="button"
                                id="quantityPlus"
                                class="btn btn-outline-primary rounded-circle quantity-button">
                                +
                            </button>

                        </div>

                    </div>

                </div>


                <div
                    class="modal-footer border-0 justify-content-center pb-4">

                    <button
                        type="button"
                        class="btn btn-secondary rounded-pill px-4"
                        data-bs-dismiss="modal">
                        Cancel
                    </button>


                    <button
                        type="button"
                        id="confirmAddToCart"
                        class="btn btn-primary rounded-pill px-4">

                        <i class="fa-solid fa-cart-plus me-2"></i>

                        Add to Cart

                    </button>

                </div>

            </div>

        </div>

    </div>

</main>


<?php
include "components/footer.php";
?>