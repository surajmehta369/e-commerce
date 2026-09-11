<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once "connection/dbconnect.php";

$database = new Database();
$db = $database->connect();
$limit = 12;

$page = isset($_GET['page'])
    ? (int) $_GET['page']
    : 1;

if ($page < 1) {
    $page = 1;
}

$offset = ($page - 1) * $limit;

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
        p.created_at,

        c.name AS category_name,

        b.name AS brand_name

    FROM products p

    LEFT JOIN categories c
        ON p.category_id = c.id

    LEFT JOIN brands b
        ON p.brand_id = b.id

    WHERE p.status = 1

      AND p.stock > 0

      AND p.discount >= 30

    ORDER BY

        p.discount DESC,

        p.created_at DESC

    LIMIT :limit
    OFFSET :offset
";


$stmt = $db->prepare($sql);

$stmt->bindValue(
    ':limit',
    $limit,
    PDO::PARAM_INT
);

$stmt->bindValue(
    ':offset',
    $offset,
    PDO::PARAM_INT
);

$stmt->execute();

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$countSql = "
    SELECT COUNT(*)

    FROM products p

    WHERE p.status = 1

      AND p.stock > 0

      AND p.discount >= 30
";

$countStmt = $db->prepare($countSql);

$countStmt->execute();

$totalProducts =
    (int) $countStmt->fetchColumn();
$totalPages = $totalProducts > 0
    ? (int) ceil(
        $totalProducts / $limit
    )
    : 1;


require_once "components/header.php";

require_once "components/sidebar.php";

?>


<main
    class="container-fluid"
    style="margin-top:100px;">

    <div class="container-fluid">
        <div
            class="
                d-flex
                justify-content-between
                align-items-center
                mb-4
            ">

            <div>

                <h2 class="fw-bold mb-1">

                    <i
                        class="
                            fa-solid
                            fa-fire
                            text-danger
                            me-2
                        "></i>

                    Today's Deals

                </h2>

                <p class="text-muted mb-0">

                    Great deals with
                    <strong>30% or more</strong>
                    discount.

                </p>

            </div>


            <a
                href="index.php"
                class="
                    btn
                    btn-outline-dark
                    rounded-pill
                ">

                <i
                    class="
                        fa-solid
                        fa-arrow-left
                        me-2
                    "></i>

                Continue Shopping

            </a>

        </div>
        <div
            class="
                alert
                alert-warning
                border-0
                rounded-4
                mb-4
            ">

            <i
                class="
                    fa-solid
                    fa-bolt
                    me-2
                "></i>

            <strong>Today's Deals</strong>

            — Save more on products
            with discounts of 30% or more.

        </div>
        <div
            class="
                d-flex
                justify-content-between
                align-items-center
                mb-3
            ">

            <h4 class="fw-bold mb-0">

                Deals For You

            </h4>


            <span class="text-muted">

                <?= number_format(
                    $totalProducts
                ); ?>

                deal(s)

            </span>

        </div>


        <?php if (empty($products)): ?>
            <div
                class="
                    text-center
                    py-5
                ">
                <div
                    class="mb-3"
                    style="font-size:60px;">

                    <i
                        class="
                            fa-solid
                            fa-tags
                            text-muted
                        "></i>

                </div>
                <h4 class="fw-bold">

                    No Deals Available

                </h4>
                <p class="text-muted">

                    There are currently no products
                    with a discount of 30% or more.

                </p>
                <a
                    href="index.php"
                    class="
                        btn
                        btn-primary
                        rounded-pill
                        px-4
                    ">

                    <i
                        class="
                            fa-solid
                            fa-cart-shopping
                            me-2
                        "></i>

                    Shop Now

                </a>

            </div>


        <?php else: ?>

            <div class="row g-4">


                <?php foreach (
                    $products as $product
                ): ?>

                    <?php

                    $price =
                        (float) $product['price'];


                    $originalPrice =
                        $product['original_price'] !== null
                        ? (float) $product['original_price']
                        : null;


                    $discount =
                        (float) $product['discount'];


                    $stock =
                        (int) $product['stock'];

                    ?>
                    <div
                        class="
                            col-lg-3
                            col-md-4
                            col-sm-6
                            col-12
                        ">


                        <div
                            class="
                                shop-card
                                h-100
                                position-relative
                            ">
                            <div
                                class="
                                    position-absolute
                                    top-0
                                    start-0
                                    m-2
                                "
                                style="z-index:5;">

                                <span
                                    class="
                                        badge
                                        bg-danger
                                    ">

                                    <i
                                        class="
                                            fa-solid
                                            fa-bolt
                                            me-1
                                        "></i>

                                    TODAY'S DEAL

                                </span>

                            </div>
                            <div
                                class="
                                    position-absolute
                                    top-0
                                    end-0
                                    m-2
                                "
                                style="z-index:5;">

                                <span
                                    class="
                                        badge
                                        bg-success
                                    ">

                                    <?= number_format(
                                        $discount,
                                        0
                                    ); ?>% OFF

                                </span>

                            </div>
                            <div
                                class="
                                    shop-card-image
                                    position-relative
                                ">

                                <a
                                    href="
                                        product-details.php?id=<?= (int) $product['id']; ?>
                                    "
                                    class="
                                        text-decoration-none
                                    ">

                                    <img
                                        src="<?= htmlspecialchars(
                                                    $product['image']
                                                ); ?>"
                                        alt="<?= htmlspecialchars(
                                                    $product['title']
                                                ); ?>">

                                </a>

                            </div>

                            <div
                                class="
                                    shop-card-body
                                ">

                                <?php if (
                                    !empty($product['brand_name'])
                                ): ?>

                                    <div
                                        class="mb-2">

                                        <span
                                            class="
                                                badge
                                                bg-secondary
                                            ">

                                            <?= htmlspecialchars(
                                                $product['brand_name']
                                            ); ?>

                                        </span>

                                    </div>

                                <?php endif; ?>

                                <h5
                                    class="
                                        fw-bold
                                        mb-2
                                    ">

                                    <a
                                        href="
                                            product-details.php?id=<?= (int) $product['id']; ?>
                                        "
                                        class="
                                            text-decoration-none
                                            text-dark
                                        ">

                                        <?= htmlspecialchars(
                                            $product['title']
                                        ); ?>

                                    </a>

                                </h5>
                                <?php if (
                                    !empty($product['description'])
                                ): ?>

                                    <p
                                        class="
                                            text-muted
                                            mb-2
                                        ">

                                        <?= htmlspecialchars(
                                            $product['description']
                                        ); ?>

                                    </p>

                                <?php endif; ?>
                                <div
                                    class="
                                        mb-2
                                    ">

                                    <span
                                        class="
                                            fw-bold
                                            fs-5
                                            text-primary
                                        ">

                                        ₹<?= number_format(
                                                $price,
                                                2
                                            ); ?>

                                    </span>


                                    <?php if (
                                        $originalPrice !== null
                                        &&
                                        $originalPrice > $price
                                    ): ?>

                                        <span
                                            class="
                                                text-muted
                                                text-decoration-line-through
                                                ms-2
                                            ">

                                            ₹<?= number_format(
                                                    $originalPrice,
                                                    2
                                                ); ?>

                                        </span>

                                    <?php endif; ?>

                                </div>
                                <div
                                    class="
                                        mb-3
                                    ">

                                    <span
                                        class="
                                            badge
                                            bg-success
                                        ">

                                        Save
                                        <?= number_format(
                                            $discount,
                                            0
                                        ); ?>%

                                    </span>

                                </div>
                                <?php if (
                                    $stock <= 5
                                ): ?>

                                    <div
                                        class="
                                            text-warning
                                            small
                                            mb-3
                                        ">

                                        <i
                                            class="
                                                fa-solid
                                                fa-triangle-exclamation
                                                me-1
                                            "></i>

                                        Only
                                        <?= $stock; ?>
                                        left

                                    </div>

                                <?php endif; ?>
                                <button
                                    type="button"
                                    class="
                                        shop-btn
                                        add-to-cart
                                    "
                                    data-id="<?= (int) $product['id']; ?>"
                                    data-title="<?= htmlspecialchars(
                                                    $product['title'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>"
                                    data-price="<?= htmlspecialchars(
                                                    $price,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>"
                                    data-image="<?= htmlspecialchars(
                                                    $product['image'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>"
                                    data-stock="<?= $stock; ?>">

                                    <i
                                        class="
                                            fa-solid
                                            fa-cart-plus
                                            me-2
                                        "></i>

                                    Add to Cart

                                </button>


                            </div>

                        </div>

                    </div>


                <?php endforeach; ?>


            </div>
            <?php if (
                $totalPages > 1
            ): ?>


                <nav
                    aria-label="Today's Deals Pagination"
                    class="mt-5 mb-5">

                    <ul
                        class="
                            pagination
                            justify-content-center
                        ">


                        <?php if (
                            $page > 1
                        ): ?>

                            <li
                                class="
                                    page-item
                                ">

                                <a
                                    class="
                                        page-link
                                    "
                                    href="
                                        ?page=<?= $page - 1; ?>
                                    ">

                                    Previous

                                </a>

                            </li>

                        <?php endif; ?>


                        <?php for (
                            $i = 1;
                            $i <= $totalPages;
                            $i++
                        ): ?>

                            <li
                                class="
                                    page-item
                                    <?= $i === $page
                                        ? 'active'
                                        : ''; ?>
                                ">

                                <a
                                    class="
                                        page-link
                                    "
                                    href="
                                        ?page=<?= $i; ?>
                                    ">

                                    <?= $i; ?>

                                </a>

                            </li>

                        <?php endfor; ?>


                        <?php if (
                            $page < $totalPages
                        ): ?>

                            <li
                                class="
                                    page-item
                                ">

                                <a
                                    class="
                                        page-link
                                    "
                                    href="
                                        ?page=<?= $page + 1; ?>
                                    ">

                                    Next

                                </a>

                            </li>

                        <?php endif; ?>


                    </ul>

                </nav>


            <?php endif; ?>


        <?php endif; ?>


    </div>

</main>


<?php

require_once "components/footer.php";

?>