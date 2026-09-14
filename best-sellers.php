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

        b.name AS brand_name,

        COALESCE(
            SUM(oi.quantity),
            0
        ) AS total_sold

    FROM products p

    INNER JOIN order_items oi
        ON oi.product_id = p.id

    INNER JOIN orders o
        ON o.id = oi.order_id

    LEFT JOIN categories c
        ON c.id = p.category_id

    LEFT JOIN brands b
        ON b.id = p.brand_id

    WHERE p.status = 1

    AND (

        (
            o.payment_method = 'cash_on_delivery'

            AND o.order_status NOT IN (
                'cancelled',
                'canceled'
            )
        )

        OR

        (
            o.payment_method <> 'cash_on_delivery'

            AND o.payment_status = 'paid'

            AND o.order_status NOT IN (
                'cancelled',
                'canceled'
            )
        )

    )

    GROUP BY

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
        c.name,
        b.name

    ORDER BY

        total_sold DESC,

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

    FROM (

        SELECT p.id

        FROM products p

        INNER JOIN order_items oi
            ON oi.product_id = p.id

        INNER JOIN orders o
            ON o.id = oi.order_id

        WHERE p.status = 1

        AND (

            (
                o.payment_method = 'cash_on_delivery'

                AND o.order_status NOT IN (
                    'cancelled',
                    'canceled'
                )
            )

            OR

            (
                o.payment_method <> 'cash_on_delivery'

                AND o.payment_status = 'paid'

                AND o.order_status NOT IN (
                    'cancelled',
                    'canceled'
                )
            )

        )

        GROUP BY p.id

    ) AS best_sellers
";


$countStmt = $db->prepare($countSql);

$countStmt->execute();

$totalProducts = (int) $countStmt->fetchColumn();

$totalPages = $totalProducts > 0
    ? (int) ceil($totalProducts / $limit)
    : 1;


require_once "components/header.php";

require_once "components/sidebar.php";

?>

<main
    class="container-fluid"
    style="margin-top: 100px;">

    <div class="container-fluid">

        <div
            class="d-flex
                   justify-content-between
                   align-items-center
                   mb-4">

            <div>

                <h2 class="fw-bold mb-1">

                    Best Sellers

                </h2>

                <p class="text-muted mb-0">

                    Discover our most popular products,
                    loved and purchased by our customers.

                </p>

            </div>

        </div>
        <div
            class="d-flex
                   justify-content-between
                   align-items-center
                   mb-3">

            <h4 class="fw-bold mb-0">

                Top Selling Products

            </h4>

            <span class="text-muted">

                <?= $totalProducts; ?>
                product(s)

            </span>

        </div>


        <?php if (empty($products)): ?>

            <div
                class="text-center
                       py-5">

                <div
                    class="mb-3"
                    style="font-size:60px;">

                    <i
                        class="fa-solid
                               fa-chart-line
                               text-muted"></i>

                </div>

                <h4 class="fw-bold">

                    No Best Sellers Yet

                </h4>

                <p class="text-muted">

                    Best selling products will appear here
                    once customers start placing orders.

                </p>

                <a
                    href="index.php"
                    class="btn
                           btn-primary
                           rounded-pill
                           px-4">

                    <i
                        class="fa-solid
                               fa-arrow-left
                               me-2"></i>

                    Continue Shopping

                </a>

            </div>


        <?php else: ?>

            <div class="row g-4">

                <?php foreach (
                    $products as $index => $product
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

                    $totalSold =
                        (int) $product['total_sold'];

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
                                style="z-index: 5;">

                                <span
                                    class="
                                        badge
                                        bg-warning
                                        text-dark
                                    ">

                                    #<?= $offset + $index + 1; ?>

                                </span>

                            </div>
                            <div
                                class="
                                    shop-card-image
                                    position-relative
                                ">

                                <a
                                    href="product-details.php?id=<?= (int) $product['id']; ?>"
                                    class="text-decoration-none">

                                    <img
                                        src="<?= htmlspecialchars(
                                                    $product['image']
                                                ); ?>"
                                        alt="<?= htmlspecialchars(
                                                    $product['title']
                                                ); ?>">

                                </a>
                                <span
                                    class="
                                        position-absolute
                                        top-0
                                        end-0
                                        m-2
                                        badge
                                        bg-danger
                                    ">

                                    <i
                                        class="
                                            fa-solid
                                            fa-fire
                                            me-1
                                        "></i>

                                    Best Seller

                                </span>

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
                                    class="fw-bold">

                                    <a
                                        href="product-details.php?id=<?= (int) $product['id']; ?>"
                                        class="
                                            text-decoration-none
                                            text-dark
                                        ">

                                        <?= htmlspecialchars(
                                            $product['title']
                                        ); ?>

                                    </a>

                                </h5>

                                <p
                                    class="text-muted">

                                    <?= htmlspecialchars(
                                        $product['description'] ?? ''
                                    ); ?>

                                </p>
                                <div class="mb-2">

                                    <span
                                        class="
                                            fw-bold
                                            fs-5
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
                                <?php if (
                                    $discount > 0
                                ): ?>

                                    <div class="mb-2">

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

                                <?php endif; ?>
                                <div
                                    class="
                                        text-muted
                                        small
                                        mb-3
                                    ">

                                    <i
                                        class="
                                            fa-solid
                                            fa-chart-line
                                            me-1
                                        "></i>

                                    <?= number_format(
                                        $totalSold
                                    ); ?>

                                    sold

                                </div>
                                <?php if (
                                    (int) $product['stock'] > 0
                                ): ?>

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
                                        data-stock="<?= (int) $product['stock']; ?>">

                                        <i
                                            class="
                                                fa-solid
                                                fa-cart-plus
                                                me-2
                                            "></i>

                                        Add to Cart

                                    </button>

                                <?php else: ?>

                                    <button
                                        type="button"
                                        class="
                                            btn
                                            btn-secondary
                                            rounded-pill
                                            w-100
                                        "
                                        disabled>

                                        Out of Stock

                                    </button>

                                <?php endif; ?>


                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>
            <?php if (
                $totalPages > 1
            ): ?>

                <nav
                    aria-label="Best Seller Pagination"
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
                                class="page-item">

                                <a
                                    class="page-link"
                                    href="?page=<?= $page - 1; ?>">

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
                                    class="page-link"
                                    href="?page=<?= $i; ?>">

                                    <?= $i; ?>

                                </a>

                            </li>

                        <?php endfor; ?>


                        <?php if (
                            $page < $totalPages
                        ): ?>

                            <li
                                class="page-item">

                                <a
                                    class="page-link"
                                    href="?page=<?= $page + 1; ?>">

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