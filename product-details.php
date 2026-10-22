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

                    <h1 class="fw-bold mb-3">

                        <?= htmlspecialchars($title); ?>

                    </h1>

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