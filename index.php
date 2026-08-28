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
                        alt="<?php echo htmlspecialchars($banner['alt']); ?>"
                    >

                </a>

            </div>

        <?php endforeach; ?>

    </div>

    <!-- Previous -->
    <button
        class="carousel-control-prev"
        type="button"
        data-bs-target="#mainBannerCarousel"
        data-bs-slide="prev"
    >
        <span class="carousel-control-prev-icon"></span>
        <span class="visually-hidden">Previous</span>
    </button>

    <!-- Next -->
    <button
        class="carousel-control-next"
        type="button"
        data-bs-target="#mainBannerCarousel"
        data-bs-slide="next"
    >
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
        title,
        description,
        image,
        price,
        stock
    FROM products
    WHERE status = 1
    ORDER BY id ASC
";

$stmt = $db->prepare($sql);
$stmt->execute();

$cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div
    id="searchResults"
    class="container-fluid"
    style="display: none;"
>
</div>

<div class="shop-cards-section"  id="defaultProducts">

    <div class="container-fluid">

        <div class="row g-4 px-2">
<?php foreach ($cards as $card): ?>

    <div class="col-lg-4 col-md-6 col-sm-12">

        <div class="shop-card">

            <div class="shop-card-image">

                <img
                    src="<?= htmlspecialchars($card['image']); ?>"
                    alt="<?= htmlspecialchars($card['title']); ?>"
                >

            </div>


            <div class="shop-card-body">

                <h4>
                    <?= htmlspecialchars($card['title']); ?>
                </h4>


                <p>
                    <?= htmlspecialchars($card['description']); ?>
                </p>


                <h5 class="mb-3">
                    ₹<?= number_format($card['price'], 2); ?>
                </h5>


                <button
                    type="button"
                    class="shop-btn add-to-cart"

                    data-id="<?= $card['id']; ?>"

                    data-title="<?= htmlspecialchars($card['title']); ?>"

                    data-price="<?= $card['price']; ?>"

                    data-image="<?= htmlspecialchars($card['image']); ?>"
                >
                    <i class="fa-solid fa-cart-plus me-2"></i>
                    Add to Cart
                </button>

            </div>

        </div>

    </div>

<?php endforeach; ?>


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

                    <h4>Electronics & Photo recommendations for you</h4>

                    <div class="row">

                        <div class="col-6">
                            <img src="assets/uploads/image7.jpg" class="img-fluid" alt="Product">
                        </div>

                        <div class="col-6">
                            <img src="assets/uploads/image8.jpg" class="img-fluid" alt="Product">
                        </div>

                        <div class="col-6">
                            <img src="assets/uploads/image.jpg" class="img-fluid" alt="Product">
                        </div>

                        <div class="col-6">
                            <img src="assets/uploads/image9.jpg" class="img-fluid" alt="Product">
                        </div>

                    </div>

                    <a href="#">See more deals</a>

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

                    <h4>Electronics & Photo recommendations for you</h4>

                    <div class="row">

                        <div class="col-6">
                            <img src="assets/uploads/image7.jpg" class="img-fluid" alt="Product">
                        </div>

                        <div class="col-6">
                            <img src="assets/uploads/image8.jpg" class="img-fluid" alt="Product">
                        </div>

                        <div class="col-6">
                            <img src="assets/uploads/image.jpg" class="img-fluid" alt="Product">
                        </div>

                        <div class="col-6">
                            <img src="assets/uploads/image9.jpg" class="img-fluid" alt="Product">
                        </div>

                    </div>

                    <a href="#">See more deals</a>

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


    <div class="container-fluid my-4" id="todayDeals" >

        <div class="card p-3">

            <h3 class="mb-3">Today's Deals</h3>

            <div id="productCarousel" class="carousel slide" data-ride="carousel">

                <div class="carousel-inner">

                    <!-- Slide 1 -->
                    <div class="carousel-item active">

                        <div class="row">

                            <div class="col-lg-3 col-md-4 col-sm-6">
                                <div class="product-card">
                                    <img src="assets/uploads/product1.jpg" class="img-fluid" alt="Product 1">

                                    <h5>Product 1</h5>
                                    <p>₹999</p>
                                </div>
                            </div>

                            <div class="col-lg-3 col-md-4 col-sm-6">
                                <div class="product-card">
                                    <img src="assets/uploads/product2.jpg" class="img-fluid" alt="Product 2">

                                    <h5>Product 2</h5>
                                    <p>₹1,299</p>
                                </div>
                            </div>

                            <div class="col-lg-3 col-md-4 col-sm-6">
                                <div class="product-card">
                                    <img src="assets/uploads/product3.jpg" class="img-fluid" alt="Product 3">

                                    <h5>Product 3</h5>
                                    <p>₹799</p>
                                </div>
                            </div>

                            <div class="col-lg-3 col-md-4 col-sm-6">
                                <div class="product-card">
                                    <img src="assets/uploads/product4.jpg" class="img-fluid" alt="Product 4">

                                    <h5>Product 4</h5>
                                    <p>₹1,499</p>
                                </div>
                            </div>

                        </div>

                    </div>


                    <!-- Slide 2 -->
                    <div class="carousel-item">

                        <div class="row">

                            <div class="col-lg-3 col-md-4 col-sm-6">
                                <div class="product-card">
                                    <img src="assets/uploads/product4.jpg" class="img-fluid" alt="Product 5">

                                    <h5>Product 5</h5>
                                    <p>₹2,199</p>
                                </div>
                            </div>

                            <div class="col-lg-3 col-md-4 col-sm-6">
                                <div class="product-card">
                                    <img src="assets/uploads/product3.jpg" class="img-fluid" alt="Product 6">

                                    <h5>Product 6</h5>
                                    <p>₹1,899</p>
                                </div>
                            </div>

                            <div class="col-lg-3 col-md-4 col-sm-6">
                                <div class="product-card">
                                    <img src="assets/uploads/product2.jpg" class="img-fluid" alt="Product 7">

                                    <h5>Product 7</h5>
                                    <p>₹599</p>
                                </div>
                            </div>

                            <div class="col-lg-3 col-md-4 col-sm-6">
                                <div class="product-card">
                                    <img src="assets/uploads/product1.jpg" class="img-fluid" alt="Product 8">

                                    <h5>Product 8</h5>
                                    <p>₹3,499</p>
                                </div>
                            </div>

                        </div>

                    </div>

                </div>


                <button class="carousel-control-prev" type="button" data-bs-target="#productCarousel"
                    data-bs-slide="prev">

                    <span class="carousel-control-prev-icon"></span>

                </button>

                <button class="carousel-control-next" type="button" data-bs-target="#productCarousel"
                    data-bs-slide="next">

                    <span class="carousel-control-next-icon"></span>

                </button>

            </div>

        </div>

    </div>


<!-- =====================================================
     ADD TO CART QUANTITY MODAL
===================================================== -->

<div
    class="modal fade"
    id="quantityModal"
    tabindex="-1"
    aria-hidden="true"
>

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
                    aria-label="Close"
                ></button>

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
                    class="mb-3"
                >


                <!-- PRODUCT NAME -->

                <h5
                    id="modalProductTitle"
                    class="fw-bold mb-2"
                ></h5>


                <!-- PRODUCT PRICE -->

                <p
                    id="modalProductPrice"
                    class="text-primary fw-bold fs-5"
                ></p>


                <!-- QUANTITY -->

                <div class="mt-4">

                    <label
                        class="fw-semibold d-block mb-2"
                    >
                        Quantity
                    </label>


                    <div
                        class="d-flex
                               justify-content-center
                               align-items-center
                               gap-3"
                    >

                        <button
                            type="button"
                            id="quantityMinus"
                            class="btn btn-outline-secondary
                                   rounded-circle"
                            style="
                                width:40px;
                                height:40px;
                            "
                        >
                            −
                        </button>


                        <input
                            type="number"
                            id="quantityInput"
                            value="1"
                            min="1"
                            class="form-control text-center fw-bold"
                            style="width:70px;"
                        >


                        <button
                            type="button"
                            id="quantityPlus"
                            class="btn btn-outline-primary
                                   rounded-circle"
                            style="
                                width:40px;
                                height:40px;
                            "
                        >
                            +
                        </button>

                    </div>

                </div>

            </div>


            <div class="modal-footer border-0 justify-content-center pb-4">

                <button
                    type="button"
                    class="btn btn-secondary rounded-pill px-4"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>


                <button
                    type="button"
                    id="confirmAddToCart"
                    class="btn btn-primary rounded-pill px-4"
                >

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


