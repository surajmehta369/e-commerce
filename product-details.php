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

<main class="container-fluid" style="margin-top:100px;">
 
    <div class="container-fluid">

        <div class="card border-0 shadow-sm rounded-4 p-4">

            <div class="row g-5 align-items-start">


                <div class="col-lg-6 col-md-6">

                    <div
                        class="product-details-image
                               d-flex
                               justify-content-center
                               align-items-center
                               bg-light
                               rounded-4
                               p-4">

                        <img
                            src="<?= htmlspecialchars($image); ?>"
                            alt="<?= htmlspecialchars($title); ?>"
                            class="img-fluid"
                            style="
                                max-height:500px;
                                width:100%;
                                object-fit:contain;
                            ">

                    </div>

                </div>


                <div class="col-lg-6 col-md-6">

                    <div class="mb-2">

                        <span class="badge bg-secondary">

                            <?= htmlspecialchars($brandName); ?>

                        </span>

                    </div>

                    <h1 class="fw-bold mb-3">

                        <?= htmlspecialchars($title); ?>

                    </h1>

                    <p class="text-muted mb-3">

                        SKU:
                        <strong>
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


                    <hr>

                    <div class="mb-3">

                        <span
                            class="fs-2
                                   fw-bold
                                   text-primary">

                            ₹<?= number_format($price, 2); ?>

                        </span>


                        <?php if ($originalPrice > $price): ?>

                            <span
                                class="text-muted
                                       text-decoration-line-through
                                       ms-2">

                                ₹<?= number_format($originalPrice, 2); ?>

                            </span>

                        <?php endif; ?>


                        <?php if ($discount > 0): ?>

                            <span
                                class="badge
                                       bg-danger
                                       ms-2">

                                <?= number_format($discount, 0); ?>% OFF

                            </span>

                        <?php endif; ?>

                    </div>

                    <div class="mb-4">

                        <strong class="<?= $stockClass; ?>">

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

                        <button
    type="button"
    class="shop-btn add-to-cart"
    data-id="<?= (int)$product['id']; ?>"
    data-title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>"
    data-price="<?= htmlspecialchars($price, ENT_QUOTES, 'UTF-8'); ?>"
    data-image="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8'); ?>"
    data-stock="<?= (int)$stock; ?>"
>
    <i
        class="fa-solid fa-cart-plus me-2"
    ></i>

    Add to Cart
</button>

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
   






<?php

include "components/footer.php";

?>