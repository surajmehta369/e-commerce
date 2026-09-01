<?php
include "components/header.php";
include "components/sidebar.php";

?>
<main>


    <?php

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

    ?>

    <div id="mainBannerCarousel"
        class="carousel slide"
        data-bs-ride="carousel"
        data-bs-interval="3000">

        <div class="carousel-inner">

            <?php foreach ($banners as $index => $banner): ?>

                <div class="carousel-item <?php echo ($index === 0) ? 'active' : ''; ?>">

                    <a href="<?php echo htmlspecialchars($banner['link']); ?>">

                        <img
                            src="<?php echo htmlspecialchars($banner['image']); ?>"
                            class="d-block w-100 main-banner"
                            alt="<?php echo htmlspecialchars($banner['alt']); ?>">

                    </a>

                </div>

            <?php endforeach; ?>

        </div>

        <!-- Previous -->
        <button
            class="carousel-control-prev"
            type="button"
            data-bs-target="#mainBannerCarousel"
            data-bs-slide="prev">
            <span class="carousel-control-prev-icon"></span>
            <span class="visually-hidden">Previous</span>
        </button>

        <!-- Next -->
        <button
            class="carousel-control-next"
            type="button"
            data-bs-target="#mainBannerCarousel"
            data-bs-slide="next">
            <span class="carousel-control-next-icon"></span>
            <span class="visually-hidden">Next</span>
        </button>

    </div>



    <!-- SHOP CARDS -->

    <?php

    require_once "connection/dbconnect.php";

    $database = new Database();
    $db = $database->connect();


    // Get active products

    $sql = "
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
    ORDER BY id ASC
";

    $stmt = $db->prepare($sql);
    $stmt->execute();

    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);



    // Products used for homepage recommendation sections
    $recommendationSql = "
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
    LIMIT 12
";

    $recommendationStmt = $db->prepare($recommendationSql);
    $recommendationStmt->execute();

    $recommendationProducts = $recommendationStmt->fetchAll(PDO::FETCH_ASSOC);



    // Electronics products
    $electronicsStmt = $db->prepare("
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
      AND category_id = 1
    ORDER BY id DESC
    LIMIT 4
");

    $electronicsStmt->execute();
    $electronicsProducts = $electronicsStmt->fetchAll(PDO::FETCH_ASSOC);


    // Smartphone products
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
    $smartphoneProducts = $smartphoneStmt->fetchAll(PDO::FETCH_ASSOC);


    // General shopping deals
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
    $dealProducts = $dealsStmt->fetchAll(PDO::FETCH_ASSOC);

    ?>



    <div
        id="searchResults"
        class="container-fluid"
        style="display: none;">
    </div>
    <div class="shop-cards-section" id="defaultProducts">

        <div class="container-fluid">

            <div class="row g-4 px-2">

                <?php foreach ($cards as $card): ?>

                    <div class="col-lg-4 col-md-6 col-sm-12">

                        <div class="shop-card-image">

                            <a
                                href="product-details.php?id=<?= (int)$card['id']; ?>"
                                class="text-decoration-none">

                                <img
                                    src="<?= htmlspecialchars($card['image']); ?>"
                                    alt="<?= htmlspecialchars($card['title']); ?>">

                            </a>

                        </div>


                        <div class="shop-card-body">

                            <h4>

                                <a
                                    href="product-details.php?id=<?= (int)$card['id']; ?>"
                                    class="text-decoration-none text-dark">
                                    <?= htmlspecialchars($card['title']); ?>
                                </a>

                            </h4>


                            <p>
                                <?= htmlspecialchars($card['description']); ?>
                            </p>


                            <div class="mb-3">

                                <div class="d-flex align-items-center gap-2 flex-wrap">

                                    <h5 class="mb-0 fw-bold">
                                        ₹<?= number_format((float)$card['price'], 2); ?>
                                    </h5>


                                    <?php if (
                                        !empty($card['original_price']) &&
                                        (float)$card['original_price'] > (float)$card['price']
                                    ): ?>

                                        <span class="text-muted text-decoration-line-through">
                                            ₹<?= number_format((float)$card['original_price'], 2); ?>
                                        </span>

                                    <?php endif; ?>

                                </div>


                                <?php if ((float)$card['discount'] > 0): ?>

                                    <span class="badge bg-success mt-2">
                                        <?= number_format((float)$card['discount'], 0); ?>% OFF
                                    </span>

                                <?php endif; ?>

                            </div>


                            <button
                                type="button"
                                class="shop-btn add-to-cart"
                                <?= ((int)$card['stock'] <= 0) ? 'disabled' : ''; ?>
                                data-id="<?= (int)$card['id']; ?>"
                                data-title="<?= htmlspecialchars($card['title']); ?>"
                                data-price="<?= htmlspecialchars($card['price']); ?>"
                                data-image="<?= htmlspecialchars($card['image']); ?>">
                                <i class="fa-solid fa-cart-plus me-2"></i>
                                Add to Cart
                            </button>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div> <!-- ✅ ROW CLOSES AFTER FOREACH -->

        </div>

    </div>

    </div>
    <hr>

    <?php

    $dealSections = [

        [
            'title' => 'Continue shopping deals',
            'link_text' => 'See more deals',
            'link' => '#',

            'products' => [
                [
                    'image' => 'assets/uploads/image1.jpg',
                    'alt' => 'Product 1',
                    'text' => 'Freedom Sale Mega Deal',
                    'link' => '#'
                ],
                [
                    'image' => 'assets/uploads/image2.jpg',
                    'alt' => 'Product 2',
                    'text' => 'Freedom Sale Mega Deal',
                    'link' => '#'
                ],
                [
                    'image' => 'assets/uploads/image5.jpg',
                    'alt' => 'Product 3',
                    'text' => 'Freedom Sale Mega Deal',
                    'link' => '#'
                ],
                [
                    'image' => 'assets/uploads/image5.jpg',
                    'alt' => 'Product 4',
                    'text' => 'Freedom Sale Mega Deal',
                    'link' => '#'
                ]
            ]
        ],

        [
            'title' => 'Electronics & Photo recommendations for you',
            'link_text' => 'See more deals',
            'link' => '#',

            'products' => [
                [
                    'image' => 'assets/uploads/image7.jpg',
                    'alt' => 'Product',
                    'text' => ''
                ],
                [
                    'image' => 'assets/uploads/image8.jpg',
                    'alt' => 'Product',
                    'text' => ''
                ],
                [
                    'image' => 'assets/uploads/image.jpg',
                    'alt' => 'Product',
                    'text' => ''
                ],
                [
                    'image' => 'assets/uploads/image9.jpg',
                    'alt' => 'Product',
                    'text' => ''
                ]
            ]
        ],

        [
            'title' => 'Smartphones curated for you',
            'link_text' => 'See all offers',
            'link' => '#',

            'products' => [
                [
                    'image' => 'assets/uploads/product1.jpg',
                    'alt' => 'Budget smartphones',
                    'text' => 'Budget | Under ₹15,000'
                ],
                [
                    'image' => 'assets/uploads/product2.jpg',
                    'alt' => 'Mid-range smartphones',
                    'text' => 'Mid-range | ₹15,000 - ₹25,000'
                ],
                [
                    'image' => 'assets/uploads/product3.jpg',
                    'alt' => 'Premium smartphones',
                    'text' => 'Premium | ₹25,000 - ₹45,000'
                ],
                [
                    'image' => 'assets/uploads/product4.jpg',
                    'alt' => 'Ultra premium smartphones',
                    'text' => 'Ultra Premium | Above ₹45,000'
                ]
            ]
        ]

    ];

    ?>



    <div class="container-fluid">
        <div class="row g-3">

            <div class="col-lg-4 col-md-6 col-sm-12">

                <div class="deal-card p-3">

                    <h4>Continue Shopping Deals</h4>

                    <div class="row">

                        <?php if (empty($dealProducts)): ?>

                            <div class="col-12">
                                <p class="text-muted">
                                    No deals available.
                                </p>
                            </div>

                        <?php else: ?>

                            <?php foreach ($dealProducts as $product): ?>

                                <div class="col-6 mb-3">

                                    <a
                                        href="product-details.php?id=<?= (int)$product['id']; ?>"
                                        class="text-decoration-none text-dark">

                                        <img
                                            src="<?= htmlspecialchars($product['image']); ?>"
                                            class="img-fluid"
                                            alt="<?= htmlspecialchars($product['title']); ?>">

                                        <p class="mt-2 mb-1">
                                            <?= htmlspecialchars($product['title']); ?>
                                        </p>

                                        <?php if ((float)$product['discount'] > 0): ?>

                                            <small class="text-success fw-semibold">
                                                <?= number_format(
                                                    (float)$product['discount'],
                                                    0
                                                ); ?>% off
                                            </small>

                                        <?php endif; ?>

                                    </a>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                    <a href="related-products.php">
                        See more deals
                    </a>

                </div>

            </div>


            <div class="col-lg-4 col-md-6 col-sm-12">

                <div class="deal-card p-3">

                    <h4>Electronics & Photo Recommendations</h4>

                    <div class="row">

                        <?php foreach (array_slice($recommendationProducts, 0, 4) as $product): ?>

                            <div class="col-6 mb-3">

                                <a
                                    href="product-details.php?id=<?= (int)$product['id']; ?>"
                                    class="text-decoration-none">

                                    <img
                                        src="<?= htmlspecialchars($product['image']); ?>"
                                        class="img-fluid"
                                        alt="<?= htmlspecialchars($product['title']); ?>">

                                </a>

                            </div>

                        <?php endforeach; ?>

                    </div>

                    <a href="related-products.php">
                        See more deals
                    </a>

                </div>

            </div>

            <div class="col-lg-4 col-md-6 col-sm-12">

                <div class="deal-card p-3">

                    <h4>Smartphones Curated For You</h4>

                    <div class="row">

                        <?php if (empty($smartphoneProducts)): ?>

                            <div class="col-12">

                                <p class="text-muted">
                                    No smartphones available.
                                </p>

                            </div>

                        <?php else: ?>

                            <?php foreach ($smartphoneProducts as $product): ?>

                                <div class="col-6 mb-3">

                                    <a
                                        href="product-details.php?id=<?= (int)$product['id']; ?>"
                                        class="text-decoration-none text-dark">

                                        <img
                                            src="<?= htmlspecialchars($product['image']); ?>"
                                            class="img-fluid"
                                            alt="<?= htmlspecialchars($product['title']); ?>">

                                        <p class="mt-2 mb-1 text-dark">

                                            <?= htmlspecialchars($product['title']); ?>

                                        </p>

                                        <strong class="text-dark">

                                            ₹<?= number_format(
                                                    (float)$product['price'],
                                                    2
                                                ); ?>

                                        </strong>

                                        <?php if ((float)$product['discount'] > 0): ?>

                                            <div class="text-success fw-semibold">

                                                <?= number_format(
                                                    (float)$product['discount'],
                                                    0
                                                ); ?>% off

                                            </div>

                                        <?php endif; ?>

                                    </a>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                    <a href="related-products.php?category_id=4">
                        See all offers
                    </a>

                </div>

            </div>

        </div>
    </div>
    <hr>
    <div class="container-fluid">
        <div class="row g-3">

            <div class="col-lg-4 col-md-6 col-sm-12">
                <div class="deal-card p-3">

                    <h4>Continue shopping deals</h4>

                    <div class="row">

                        <div class="col-6">
                            <a href="#">
                                <img src="assets/uploads/image1.jpg" class="img-fluid" alt="Product 1">
                            </a>
                            <p>Freedom Sale Mega Deal</p>
                        </div>

                        <div class="col-6">
                            <a href="#">
                                <img src="assets/uploads/image2.jpg" class="img-fluid" alt="Product 2">
                            </a>
                            <p>Freedom Sale Mega Deal</p>
                        </div>

                        <div class="col-6">
                            <a href="#">
                                <img src="assets/uploads/image5.jpg" class="img-fluid" alt="Product 3">
                            </a>
                            <p>Freedom Sale Mega Deal</p>
                        </div>

                        <div class="col-6">
                            <a href="#">
                                <img src="assets/uploads/image5.jpg" class="img-fluid" alt="Product 4">
                            </a>
                            <p>Freedom Sale Mega Deal</p>
                        </div>

                    </div>

                    <a href="#">See more deals</a>

                </div>
            </div>


            <div class="col-lg-4 col-md-6 col-sm-12">

                <div class="deal-card p-3">

                    <h4>Electronics & Photo Recommendations</h4>

                    <div class="row">

                        <?php if (empty($recommendationProducts)): ?>

                            <div class="col-12">
                                <p class="text-muted">
                                    No recommendations available.
                                </p>
                            </div>

                        <?php else: ?>

                            <?php foreach (array_slice($recommendationProducts, 0, 4) as $product): ?>

                                <div class="col-6 mb-3">

                                    <a
                                        href="product-details.php?id=<?= (int)$product['id']; ?>"
                                        class="text-decoration-none text-dark">

                                        <img
                                            src="<?= htmlspecialchars($product['image']); ?>"
                                            class="img-fluid"
                                            alt="<?= htmlspecialchars($product['title']); ?>">

                                        <p class="mt-2 mb-1">
                                            <?= htmlspecialchars($product['title']); ?>
                                        </p>

                                        <strong>
                                            ₹<?= number_format((float)$product['price'], 2); ?>
                                        </strong>

                                    </a>

                                    <button
                                        type="button"
                                        class="shop-btn add-to-cart mt-2"
                                        <?= ((int)$product['stock'] <= 0) ? 'disabled' : ''; ?>
                                        data-id="<?= (int)$product['id']; ?>"
                                        data-title="<?= htmlspecialchars($product['title']); ?>"
                                        data-price="<?= htmlspecialchars($product['price']); ?>"
                                        data-image="<?= htmlspecialchars($product['image']); ?>">
                                        <i class="fa-solid fa-cart-plus me-2"></i>
                                        Add to Cart
                                    </button>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                    <a href="related-products.php">
                        See more deals
                    </a>

                </div>

            </div>
            <div class="col-lg-4 col-md-6 col-sm-12">
                <div class="deal-card p-3">

                    <h4>Smartphones curated for you</h4>

                    <div class="row">

                        <div class="col-6">
                            <img src="assets/uploads/product1.jpg" class="img-fluid" alt="Budget smartphones">
                            <p>Budget | Under ₹15,000</p>
                        </div>

                        <div class="col-6">
                            <img src="assets/uploads/product2.jpg" class="img-fluid" alt="Mid-range smartphones">
                            <p>Mid-range | ₹15,000 - ₹25,000</p>
                        </div>

                        <div class="col-6">
                            <img src="assets/uploads/product3.jpg" class="img-fluid" alt="Premium smartphones">
                            <p>Premium | ₹25,000 - ₹45,000</p>
                        </div>

                        <div class="col-6">
                            <img src="assets/uploads/product4.jpg" class="img-fluid" alt="Ultra premium smartphones">
                            <p>Ultra Premium | Above ₹45,000</p>
                        </div>

                    </div>

                    <a href="#">See all offers</a>

                </div>
            </div>

        </div>
    </div>


    <?php

    require_once "connection/dbconnect.php";

    $database = new Database();
    $db = $database->connect();

    $todayDealsSql = "
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
";

    $todayDealsStmt = $db->prepare($todayDealsSql);
    $todayDealsStmt->execute();

    $todayDeals = $todayDealsStmt->fetchAll(PDO::FETCH_ASSOC);

    ?>

    <div class="container-fluid my-4" id="todayDeals">

        <div class="card p-3">

            <h3 class="mb-3">
                Today's Deals
            </h3>

            <?php if (empty($todayDeals)): ?>

                <div class="text-center py-5">

                    <p class="text-muted mb-0">
                        No deals available right now.
                    </p>

                </div>

            <?php else: ?>

                <div
                    id="productCarousel"
                    class="carousel slide"
                    data-bs-ride="carousel">

                    <div class="carousel-inner">

                        <?php
                        $chunks = array_chunk($todayDeals, 4);
                        ?>

                        <?php foreach ($chunks as $slideIndex => $products): ?>

                            <div
                                class="carousel-item
                            <?= $slideIndex === 0 ? 'active' : ''; ?>">

                                <div class="row g-3">

                                    <?php foreach ($products as $product): ?>

                                        <div
                                            class="col-lg-3 col-md-4 col-sm-6">

                                            <div class="product-card h-100">

                                                <a
                                                    href="product-details.php?id=<?= (int)$product['id']; ?>"
                                                    class="text-decoration-none text-dark">

                                                    <img
                                                        src="<?= htmlspecialchars($product['image']); ?>"
                                                        class="img-fluid"
                                                        alt="<?= htmlspecialchars($product['title']); ?>">

                                                    <h5 class="mt-2">
                                                        <?= htmlspecialchars($product['title']); ?>
                                                    </h5>

                                                </a>

                                                <div class="mt-2">

                                                    <strong>
                                                        ₹<?= number_format(
                                                                (float)$product['price'],
                                                                2
                                                            ); ?>
                                                    </strong>

                                                    <?php if (
                                                        !empty($product['original_price']) &&
                                                        (float)$product['original_price'] > (float)$product['price']
                                                    ): ?>

                                                        <span
                                                            class="text-muted text-decoration-line-through ms-2">
                                                            ₹<?= number_format(
                                                                    (float)$product['original_price'],
                                                                    2
                                                                ); ?>
                                                        </span>

                                                    <?php endif; ?>

                                                    <?php if ((float)$product['discount'] > 0): ?>

                                                        <div class="text-success fw-semibold">
                                                            <?= number_format(
                                                                (float)$product['discount'],
                                                                0
                                                            ); ?>% off
                                                        </div>

                                                    <?php endif; ?>

                                                </div>

                                                <button
                                                    type="button"
                                                    class="shop-btn add-to-cart mt-2"
                                                    data-id="<?= (int)$product['id']; ?>"
                                                    data-title="<?= htmlspecialchars($product['title']); ?>"
                                                    data-price="<?= htmlspecialchars($product['price']); ?>"
                                                    data-image="<?= htmlspecialchars($product['image']); ?>">
                                                    <i class="fa-solid fa-cart-plus me-2"></i>
                                                    Add to Cart
                                                </button>

                                            </div>

                                        </div>

                                    <?php endforeach; ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                    <?php if (count($chunks) > 1): ?>

                        <button
                            class="carousel-control-prev"
                            type="button"
                            data-bs-target="#productCarousel"
                            data-bs-slide="prev">

                            <span class="carousel-control-prev-icon"></span>

                            <span class="visually-hidden">
                                Previous
                            </span>

                        </button>

                        <button
                            class="carousel-control-next"
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


    <!-- =====================================================
     ADD TO CART QUANTITY MODAL
===================================================== -->

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

                    <!-- PRODUCT IMAGE -->

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


                    <!-- PRODUCT NAME -->

                    <h5
                        id="modalProductTitle"
                        class="fw-bold mb-2"></h5>


                    <!-- PRODUCT PRICE -->

                    <p
                        id="modalProductPrice"
                        class="text-primary fw-bold fs-5"></p>


                    <!-- QUANTITY -->

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
                                class="btn btn-outline-secondary
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
                                class="form-control text-center fw-bold"
                                style="width:70px;">


                            <button
                                type="button"
                                id="quantityPlus"
                                class="btn btn-outline-primary
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


                <div class="modal-footer border-0 justify-content-center pb-4">

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