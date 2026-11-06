<?php

require_once __DIR__ . "/includes/layout.php";
require_once "../connection/dbconnect.php";
require_once "../shopify/functions.php";
require_once "../zoho/zoho_functions.php";

$database = new Database();
$db = $database->connect();

// Runs on a full page load or when ?sync=1 is used ("Sync Shopify" button).
// It no longer runs on every AJAX page change, which keeps navigation fast.
if (!isAjaxRequest() || isset($_GET['sync'])) {
    syncShopifyProductsToDatabase();
}
// syncZohoWebhookItemToShopify();
$message = "";
$messageType = "success";

// CSRF protection for every POST on this page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !adminVerifyCsrf()) {
    http_response_code(419);
    $_POST = [];
    $message = 'Security token expired. Please try again.';
    $messageType = 'danger';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_zoho_webhook'])) {

    $syncResult = syncZohoWebhookItemToShopify();

    if (!empty($syncResult['success'])) {
        $message = $syncResult['message'] ?? 'Zoho webhook sync completed successfully.';
        $messageType = 'success';
    } else {
        $message = $syncResult['message'] ?? 'Zoho webhook sync failed.';
        $messageType = 'error';
    }
}


if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !empty($_POST) &&
    !isset($_POST['test_zoho_webhook'])
) {
    $action = $_POST['action'] ?? '';
    $productId = (int) ($_POST['product_id'] ?? 0);

    if ($productId <= 0) {

        $message = "Invalid product.";
        $messageType = "danger";
    } else {

        try {
            $productStmt = $db->prepare("
    
SELECT
    id,
    title,
    sku,
    description,
    price,
    original_price,
    stock,
    image,
    status,
    shopify_product_id,
    zoho_item_id
FROM products
WHERE id = :id
LIMIT 1
            ");

            $productStmt->execute([
                ':id' => $productId
            ]);

            $product = $productStmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {

                $message = "Product not found.";
                $messageType = "danger";
            } else {

                $shopifyProductId =
                    trim($product['shopify_product_id'] ?? '');



                if ($action === 'approve') {

                    $zohoProduct = [
                        'name' => $product['title'],
                        'sku' => $product['sku'],
                        'description' => $product['description'] ?? '',
                        'rate' => (float) $product['price'],
                        'purchase_rate' => (
                            $product['original_price'] !== null
                            && $product['original_price'] !== ''
                        ) ? (float) $product['original_price']
                            : (float) $product['price'],
                        'stock' => (int) $product['stock'],
                        'zoho_item_id' => $product['zoho_item_id'] ?? ''
                    ];
                    $shopifyResult = [
                        'success' => false,
                        'message' => 'No Shopify product is linked.'
                    ];

                    if ($shopifyProductId !== '') {
                        $shopifyResult = updateShopifyProduct(
                            $shopifyProductId,
                            [
                                'title' => $product['title'],
                                'status' => 1
                            ]
                        );
                    }
                    $zohoResult = syncApprovedProductToZoho($zohoProduct);
                    $returnedZohoId = $zohoResult['item_id']
                        ?? ($zohoResult['item']['item_id'] ?? '');

                    if ($returnedZohoId !== '') {
                        $saveId = $db->prepare("
            UPDATE products
            SET zoho_item_id = :zoho_item_id
            WHERE id = :id
        ");

                        $saveId->execute([
                            ':zoho_item_id' => $returnedZohoId,
                            ':id' => $productId
                        ]);
                    }

                    $shopifySuccess = !empty($shopifyResult['success']);
                    $zohoSuccess = !empty($zohoResult['success']);

                    if ($shopifySuccess && $zohoSuccess) {

                        $stmt = $db->prepare("
            UPDATE products
            SET status = 1,
                shopify_status = 'ACTIVE',
                shopify_synced_at = NOW()
            WHERE id = :id
        ");

                        $stmt->execute([':id' => $productId]);

                        $message = 'Product approved and activated in Shopify and Zoho.';
                        $messageType = 'success';
                    } else {
                        if ($shopifySuccess && $shopifyProductId !== '') {
                            updateShopifyProduct(
                                $shopifyProductId,
                                [
                                    'title' => $product['title'],
                                    'status' => 0
                                ]
                            );
                        }

                        if ($zohoSuccess && $returnedZohoId !== '') {
                            setZohoItemStatus($returnedZohoId, 'inactive');
                        }

                        $details = [];

                        if (!$shopifySuccess) {
                            $details[] = 'Shopify: ' .
                                ($shopifyResult['message'] ?? 'Activation failed.');
                        }

                        if (!$zohoSuccess) {
                            $details[] = 'Zoho: ' .
                                ($zohoResult['message'] ?? 'Synchronization failed.');
                        }

                        $message = 'Approval not completed. ' . implode(' ', $details);
                        $messageType = 'warning';
                    }
                } elseif ($action === 'activate') {

                    $zohoItemId = trim($product['zoho_item_id'] ?? '');
                    $shopifyResult = [
                        'success' => false,
                        'message' => 'No Shopify product is linked.'
                    ];

                    if ($shopifyProductId !== '') {
                        $shopifyResult = updateShopifyProduct(
                            $shopifyProductId,
                            [
                                'title' => $product['title'],
                                'status' => 1
                            ]
                        );
                    }
                    $zohoResult = [
                        'success' => false,
                        'message' => 'No Zoho item is linked.'
                    ];

                    if ($zohoItemId !== '') {
                        $zohoResult = setZohoItemStatus(
                            $zohoItemId,
                            'active'
                        );
                    }

                    $shopifySuccess = !empty($shopifyResult['success']);
                    $zohoSuccess = !empty($zohoResult['success']);
                    if ($shopifySuccess && $zohoSuccess) {

                        $stmt = $db->prepare("
            UPDATE products
            SET
                status = 1,
                shopify_status = 'ACTIVE',
                shopify_synced_at = NOW()
            WHERE id = :id
        ");

                        $stmt->execute([
                            ':id' => $productId
                        ]);

                        $message = 'Product activated successfully in Shopify and Zoho.';
                        $messageType = 'success';
                    } else {
                        if ($shopifySuccess && $shopifyProductId !== '') {
                            updateShopifyProduct(
                                $shopifyProductId,
                                [
                                    'title' => $product['title'],
                                    'status' => 0
                                ]
                            );
                        }

                        $details = [];

                        if (!$shopifySuccess) {
                            $details[] = 'Shopify: ' .
                                ($shopifyResult['message'] ?? 'Activation failed.');
                        }

                        if (!$zohoSuccess) {
                            $details[] = 'Zoho: ' .
                                ($zohoResult['message'] ?? 'Activation failed.');
                        }

                        $message = 'Product activation was not completed. ' .
                            implode(' ', $details);

                        $messageType = 'warning';
                    }
                } elseif ($action === 'deactivate') {

                    $sql = "
                        UPDATE products
                        SET status = 0
                        WHERE id = :id
                    ";

                    $stmt = $db->prepare($sql);

                    $stmt->execute([
                        ':id' => $productId
                    ]);

                    if ($shopifyProductId !== '') {

                        $shopifyResult =
                            updateShopifyProduct(
                                $shopifyProductId,
                                [
                                    'title' => $product['title'],
                                    'status' => 0
                                ]
                            );

                        if (!empty($shopifyResult['success'])) {

                            $syncStmt = $db->prepare("
                                UPDATE products
                                SET
                                    shopify_status = 'ARCHIVED',
                                    shopify_synced_at = NOW()
                                WHERE id = :id
                            ");

                            $syncStmt->execute([
                                ':id' => $productId
                            ]);

                            $message =
                                "Product deactivated and archived on Shopify.";

                            $messageType = "success";
                        } else {

                            $message =
                                "Product deactivated locally, but Shopify archive failed.";

                            $messageType = "warning";
                        }
                    } else {

                        $message =
                            "Product deactivated successfully. No Shopify product linked.";

                        $messageType = "warning";
                    }

                    $zohoItemId = trim($product['zoho_item_id'] ?? '');

                    if ($zohoItemId !== '') {
                        $zohoResult = setZohoItemStatus(
                            $zohoItemId,
                            'inactive'
                        );

                        if (empty($zohoResult['success'])) {
                            $message = 'Shopify was deactivated, but Zoho deactivation failed: '
                                . ($zohoResult['message'] ?? 'Unknown error.');

                            $messageType = 'warning';
                        } else {
                            $message = 'Product deactivated in Shopify and Zoho.';
                            $messageType = 'success';
                        }
                    } else {
                        $message = 'Product deactivated in Shopify. No Zoho item is linked.';
                        $messageType = 'warning';
                    }
                } elseif ($action === 'delete') {
                    $shopifySuccess = true;

                    $shopifyResult = [
                        'success' => true,
                        'message' => 'No Shopify product linked.'
                    ];

                    if ($shopifyProductId !== '') {

                        $shopifyResult = deleteShopifyProduct(
                            $shopifyProductId
                        );

                        $shopifySuccess = !empty($shopifyResult['success']);
                    }

                    if (empty($shopifySuccess)) {

                        $message = 'Product was not deleted because Shopify deletion failed: '
                            . ($shopifyResult['message'] ?? 'Unknown Shopify error.');

                        $messageType = 'danger';
                    } else {
                        $zohoErrors = [];

                        $zohoItemIds = [];

                        $parentZohoItemId = trim(
                            $product['zoho_item_id'] ?? ''
                        );

                        if ($parentZohoItemId !== '') {
                            $zohoItemIds[] = $parentZohoItemId;
                        }
                        $variantStmt = $db->prepare("
            SELECT id, zoho_item_id
            FROM product_variants
            WHERE product_id = :product_id
        ");

                        $variantStmt->execute([
                            ':product_id' => $productId
                        ]);

                        $productVariants = $variantStmt->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($productVariants as $variant) {

                            $variantZohoId = trim(
                                $variant['zoho_item_id'] ?? ''
                            );

                            if ($variantZohoId !== '') {
                                $zohoItemIds[] = $variantZohoId;
                            }
                        }
                        $zohoItemIds = array_unique($zohoItemIds);

                        foreach ($zohoItemIds as $zohoItemId) {

                            $zohoResult = setZohoItemStatus(
                                $zohoItemId,
                                'inactive'
                            );

                            if (empty($zohoResult['success'])) {

                                $zohoErrors[] = $zohoItemId . ': '
                                    . ($zohoResult['message'] ?? 'Deactivation failed.');
                            }
                        }

                        if (!empty($zohoErrors)) {

                            $message = 'Product was not deleted because Zoho deactivation failed: '
                                . implode(' | ', $zohoErrors);

                            $messageType = 'danger';
                        } else {
                            try {

                                $db->beginTransaction();
                                $stmt = $db->prepare("
                    DELETE FROM wishlists
                    WHERE product_id = :product_id
                    OR variant_id IN (
                        SELECT id
                        FROM product_variants
                        WHERE product_id = :variant_product_id
                    )
                ");

                                $stmt->execute([
                                    ':product_id' => $productId,
                                    ':variant_product_id' => $productId
                                ]);
                                $stmt = $db->prepare("
                    DELETE FROM product_variant_images
                    WHERE variant_id IN (
                        SELECT id
                        FROM product_variants
                        WHERE product_id = :product_id
                    )
                ");

                                $stmt->execute([
                                    ':product_id' => $productId
                                ]);
                                $stmt = $db->prepare("
                    DELETE FROM product_images
                    WHERE product_id = :product_id
                ");

                                $stmt->execute([
                                    ':product_id' => $productId
                                ]);
                                $stmt = $db->prepare("
                    DELETE FROM product_reviews
                    WHERE product_id = :product_id
                ");

                                $stmt->execute([
                                    ':product_id' => $productId
                                ]);
                                $stmt = $db->prepare("
                    DELETE FROM product_variants
                    WHERE product_id = :product_id
                ");

                                $stmt->execute([
                                    ':product_id' => $productId
                                ]);
                                $stmt = $db->prepare("
                    DELETE FROM products
                    WHERE id = :product_id
                ");

                                $stmt->execute([
                                    ':product_id' => $productId
                                ]);

                                if ($stmt->rowCount() !== 1) {
                                    throw new Exception('Product was not found during local deletion.');
                                }

                                $db->commit();
                                if (!empty($product['image'])) {

                                    $imagePath = "../" . ltrim(
                                        $product['image'],
                                        "/"
                                    );

                                    if (
                                        file_exists($imagePath) &&
                                        is_file($imagePath)
                                    ) {
                                        @unlink($imagePath);
                                    }
                                }

                                $message = 'Product and its variants deleted successfully from local database, Shopify and Zoho.';

                                $messageType = 'success';
                            } catch (Throwable $e) {

                                if ($db->inTransaction()) {
                                    $db->rollBack();
                                }

                                $message = 'Local product deletion failed: '
                                    . $e->getMessage();

                                $messageType = 'danger';
                            }
                        }
                    }
                } else {

                    $message = "Invalid product action.";
                    $messageType = "danger";
                }
            }
        } catch (PDOException $e) {

            $message =
                "Something went wrong. Please try again.";

            $messageType = "danger";
        }
    }
}

$allowedPerPage = [5, 10, 15, 20, 30, 40];

$perPage = (int) ($_GET['per_page'] ?? 10);

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 10;
}

$page = max(1, (int) ($_GET['page'] ?? 1));

$search = trim($_GET['search'] ?? '');
$categoryId = (int) ($_GET['category_id'] ?? 0);
$brandId = (int) ($_GET['brand_id'] ?? 0);
$status = $_GET['status'] ?? '';

$where = [];
$params = [];

if ($search !== '') {

    $where[] = "(
        p.title LIKE :search
        OR p.sku LIKE :search
        OR p.slug LIKE :search
        OR u.name LIKE :search
        OR vp.store_name LIKE :search
    )";

    $params[':search'] = '%' . $search . '%';
}
if ($categoryId > 0) {

    $where[] = "p.category_id = :category_id";

    $params[':category_id'] = $categoryId;
}

if ($brandId > 0) {

    $where[] = "p.brand_id = :brand_id";

    $params[':brand_id'] = $brandId;
}

if ($status === 'pending') {

    $where[] = "p.status = 0";
} elseif ($status === 'active') {

    $where[] = "p.status = 1";
}


$whereSql = "";

if (!empty($where)) {
    $whereSql = "WHERE " . implode(" AND ", $where);
}

$sql = "SELECT id, name
        FROM categories
        WHERE status = 1
        ORDER BY name ASC";

$stmt = $db->prepare($sql);
$stmt->execute();

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sql = "SELECT id, name
        FROM brands
        WHERE status = 1
        ORDER BY name ASC";

$stmt = $db->prepare($sql);
$stmt->execute();

$brands = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countSql = "
    SELECT COUNT(*)
    FROM products p

    LEFT JOIN users u
        ON u.id = p.vendor_id
        AND u.role = 'vendor'

    LEFT JOIN vendor_profiles vp
        ON vp.user_id = p.vendor_id

    $whereSql
";

$countStmt = $db->prepare($countSql);
$countStmt->execute($params);

$totalProducts = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalProducts / $perPage));

$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;


$sql = "
    SELECT
        p.id,
        p.sku,
        p.title,
        p.slug,
        p.description,
        p.image,
        p.price,
        p.original_price,
        p.discount,
        p.stock,
        p.category_id,
        p.brand_id,
        p.status,
        p.vendor_id,
        p.created_at,

        c.name AS category_name,
        b.name AS brand_name,

        u.name AS vendor_name,
        u.email AS vendor_email,

        vp.store_name AS vendor_store

    FROM products p

    LEFT JOIN categories c
        ON c.id = p.category_id

    LEFT JOIN brands b
        ON b.id = p.brand_id

    LEFT JOIN users u
        ON u.id = p.vendor_id
        AND u.role = 'vendor'

    LEFT JOIN vendor_profiles vp
        ON vp.user_id = p.vendor_id

    $whereSql

    ORDER BY p.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$variantStocksByProduct = [];

if (!empty($products)) {

    $productIds = array_column($products, 'id');

    $placeholders = implode(
        ',',
        array_fill(0, count($productIds), '?')
    );

    $variantStockStmt = $db->prepare("
        SELECT
            product_id,
            variant_title,
            sku,
            stock
        FROM product_variants
        WHERE product_id IN ($placeholders)
        ORDER BY id ASC
    ");

    $variantStockStmt->execute($productIds);

    $allVariantStocks = $variantStockStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allVariantStocks as $variant) {

        $variantStocksByProduct[$variant['product_id']][] = $variant;
    }
}


function productStatusBadge($status)
{
    if ((int) $status === 1) {

        return '<span class="badge bg-success">
                    Active
                </span>';
    }

    return '<span class="badge bg-warning text-dark">
                Pending / Inactive
            </span>';
}


function stockBadge($stock)
{
    $stock = (int) $stock;

    if ($stock <= 0) {

        return '<span class="badge bg-danger">
                    Out of Stock
                </span>';
    } elseif ($stock <= 5) {

        return '<span class="badge bg-warning text-dark">
                    Low Stock
                </span>';
    }

    return '<span class="badge bg-success">
                ' . $stock . '
            </span>';
}


adminHeader('Products', 'products');
?>
<style>
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .page-header h2 {
            margin: 0;
            font-weight: 700;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
        }

        .filter-card {
            margin-bottom: 20px;
        }

        .table th {
            white-space: nowrap;
            font-size: 13px;
            color: #6b7280;
        }

        .table td {
            vertical-align: middle;
        }

        .product-image {
            width: 55px;
            height: 55px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
        }

        .product-title {
            font-weight: 600;
            max-width: 220px;
        }

        .small-text {
            font-size: 12px;
            color: #6b7280;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .action-buttons form {
            display: inline;
        }

        .modal-product-image {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
        }

        .stock-details-dropdown {
            position: relative;
            display: inline-block;
        }

        .stock-details-btn {
            border: 1px solid #dbe2ea;
            background: #fff;
            color: #475569;
            border-radius: 6px;
            padding: 4px 7px;
            margin-left: 4px;
            font-size: 12px;
            vertical-align: middle;
        }

        .stock-details-btn:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
        }

        .stock-details-menu {
            min-width: 230px;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 9px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }

        .stock-details-title {
            font-size: 13px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 10px;
        }

        .stock-variant-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 7px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 12px;
        }

        .stock-variant-name {
            color: #475569;
            max-width: 155px;
            overflow-wrap: anywhere;
        }

        .stock-variant-quantity {
            font-weight: 600;
            color: #111827;
        }

        .stock-total-row {
            display: flex;
            justify-content: space-between;
            padding-top: 10px;
            margin-top: 5px;
            font-size: 13px;
            font-weight: 700;
            color: #111827;
        }
    </style>


        <div class="page-header">

            <div>

                <h2>Product Management</h2>

                <p class="text-muted mb-0">
                    Manage products, approvals, stock and availability.
                </p>

            </div>
            <div class="d-flex gap-2">
            <a href="products.php?sync=1" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-repeat me-1"></i>
                Sync Shopify
            </a>
            <a href="product-add.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>
                Add Product
            </a>
            </div>

        </div>
        <?php if ($message !== ""): ?>

            <div
                class="alert alert-<?php echo htmlspecialchars($messageType); ?>
                   alert-dismissible fade show">

                <?php echo htmlspecialchars($message); ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert">
                </button>

            </div>

        <?php endif; ?>
        <div class="card filter-card">

            <div class="card-body">

                <form method="GET">

                    <div class="row g-3 align-items-end">

                        <div class="col-lg-4 col-md-6">

                            <label class="form-label">
                                Search Product
                            </label>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                placeholder="Title, SKU or vendor"
                                value="<?php echo htmlspecialchars($search); ?>">

                        </div>


                        <div class="col-lg-2 col-md-6">

                            <label class="form-label">
                                Category
                            </label>

                            <select
                                name="category_id"
                                class="form-select">

                                <option value="">
                                    All Categories
                                </option>

                                <?php foreach ($categories as $category): ?>

                                    <option
                                        value="<?php echo (int) $category['id']; ?>"
                                        <?php
                                        echo $categoryId == $category['id']
                                            ? 'selected'
                                            : '';
                                        ?>>

                                        <?php
                                        echo htmlspecialchars(
                                            $category['name']
                                        );
                                        ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="col-lg-2 col-md-6">

                            <label class="form-label">
                                Brand
                            </label>

                            <select
                                name="brand_id"
                                class="form-select">

                                <option value="">
                                    All Brands
                                </option>

                                <?php foreach ($brands as $brand): ?>

                                    <option
                                        value="<?php echo (int) $brand['id']; ?>"
                                        <?php
                                        echo $brandId == $brand['id']
                                            ? 'selected'
                                            : '';
                                        ?>>

                                        <?php
                                        echo htmlspecialchars(
                                            $brand['name']
                                        );
                                        ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="col-lg-2 col-md-6">

                            <label class="form-label">
                                Status
                            </label>

                            <select
                                name="status"
                                class="form-select">

                                <option value="">
                                    All Status
                                </option>

                                <option
                                    value="pending"
                                    <?php
                                    echo $status === 'pending'
                                        ? 'selected'
                                        : '';
                                    ?>>
                                    Pending / Inactive
                                </option>

                                <option
                                    value="active"
                                    <?php
                                    echo $status === 'active'
                                        ? 'selected'
                                        : '';
                                    ?>>
                                    Active
                                </option>

                            </select>

                        </div>


                        <div class="col-lg-2 col-md-6">
                            <label class="form-label">Products per page</label>

                            <select name="per_page" class="form-select">
                                <?php foreach ([5, 10, 15, 20, 30, 40] as $option): ?>
                                    <option
                                        value="<?php echo $option; ?>"
                                        <?php echo $perPage === $option ? 'selected' : ''; ?>>
                                        <?php echo $option; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-2 col-md-6">

                            <div class="d-flex gap-2">

                                <button
                                    type="submit"
                                    class="btn btn-primary">
                                    Filter
                                </button>

                                <a
                                    href="products.php"
                                    class="btn btn-outline-secondary">
                                    Reset
                                </a>

                            </div>

                        </div>
                    </div>

                </form>

            </div>

        </div>
        <div class="card">

            <div class="card-body">

                <div class="d-flex justify-content-between align-items-center mb-3">

                    <h5 class="mb-0">
                        Products
                    </h5>
                    <form method="POST" action="products.php" style="margin-bottom: 15px;">
                        <button type="submit" name="test_zoho_webhook" value="1">
                            Test Zoho Webhook Sync
                        </button>
                    </form>

                    <span class="text-muted">
                        Showing <?php echo count($products); ?>
                        of <?php echo $totalProducts; ?> products
                    </span>

                </div>


                <div class="table-responsive">

                    <table class="table table-hover align-middle">

                        <thead>

                            <tr>

                                <th>#</th>

                                <th>Product</th>

                                <th>Vendor</th>

                                <th>Category</th>

                                <th>Brand</th>

                                <th>Price</th>

                                <th>Discount</th>

                                <th>Stock</th>

                                <th>Status</th>

                                <th>Created</th>

                                <th>Actions</th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php if (empty($products)): ?>

                                <tr>

                                    <td
                                        colspan="11"
                                        class="text-center py-5 text-muted">

                                        No products found.

                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($products as $index => $product): ?>

                                    <tr>

                                        <td>
                                            <?php echo $offset + $index + 1; ?>
                                        </td>

                                        <td>

                                            <div class="d-flex align-items-center gap-3">

                                                <?php if (!empty($product['image'])): ?>

                                                    <img
                                                        src="../<?php echo htmlspecialchars(
                                                                    ltrim($product['image'], '/')
                                                                ); ?>"
                                                        class="product-image"
                                                        alt="Product">

                                                <?php else: ?>

                                                    <div
                                                        class="product-image d-flex
                                                       align-items-center
                                                       justify-content-center">
                                                        <span class="small-text">
                                                            No Image
                                                        </span>
                                                    </div>

                                                <?php endif; ?>


                                                <div>

                                                    <div class="product-title">

                                                        <?php
                                                        echo htmlspecialchars(
                                                            $product['title']
                                                        );
                                                        ?>

                                                    </div>

                                                    <div class="small-text">

                                                        SKU:
                                                        <?php
                                                        echo htmlspecialchars(
                                                            $product['sku'] ?: '-'
                                                        );
                                                        ?>

                                                    </div>

                                                </div>

                                            </div>

                                        </td>
                                        <td>

                                            <?php if (!empty($product['vendor_id'])): ?>

                                                <div class="fw-semibold">

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $product['vendor_store']
                                                            ?: $product['vendor_name']
                                                            ?: 'Vendor'
                                                    );
                                                    ?>

                                                </div>

                                                <div class="small-text">

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $product['vendor_email'] ?: '-'
                                                    );
                                                    ?>

                                                </div>

                                            <?php else: ?>

                                                <span class="badge bg-info text-dark">
                                                    Admin / General
                                                </span>

                                            <?php endif; ?>

                                        </td>
                                        <td>

                                            <?php
                                            echo htmlspecialchars(
                                                $product['category_name'] ?: '-'
                                            );
                                            ?>

                                        </td>
                                        <td>

                                            <?php
                                            echo htmlspecialchars(
                                                $product['brand_name'] ?: '-'
                                            );
                                            ?>

                                        </td>
                                        <td>

                                            <strong>
                                                ₹<?php
                                                    echo number_format(
                                                        (float) $product['price'],
                                                        2
                                                    );
                                                    ?>
                                            </strong>

                                            <?php
                                            if (
                                                !empty($product['original_price'])
                                                &&
                                                $product['original_price']
                                                > $product['price']
                                            ):
                                            ?>

                                                <div class="small-text">

                                                    <del>
                                                        ₹<?php
                                                            echo number_format(
                                                                (float) $product['original_price'],
                                                                2
                                                            );
                                                            ?>
                                                    </del>

                                                </div>

                                            <?php endif; ?>

                                        </td>
                                        <td>

                                            <?php
                                            echo number_format(
                                                (float) $product['discount'],
                                                2
                                            );
                                            ?>%

                                        </td>
                                        <td>

                                            <?php

                                            $productId = (int) $product['id'];

                                            $variantStocks = $variantStocksByProduct[$productId] ?? [];

                                            echo stockBadge($product['stock']);

                                            ?>

                                            <?php if (!empty($variantStocks)): ?>

                                                <div class="dropdown stock-details-dropdown">

                                                    <button
                                                        type="button"
                                                        class="stock-details-btn dropdown-toggle"
                                                        data-bs-toggle="dropdown"
                                                        data-bs-auto-close="true"
                                                        aria-expanded="false"
                                                        title="View variant-wise stock">

                                                        <i class="bi bi-list-ul"></i>

                                                    </button>

                                                    <div class="dropdown-menu stock-details-menu">

                                                        <div class="stock-details-title">
                                                            <i class="bi bi-box-seam me-1"></i>
                                                            Variant Stock Details
                                                        </div>

                                                        <?php

                                                        $variantTotal = 0;

                                                        foreach ($variantStocks as $variant):

                                                            $variantStock = (int) $variant['stock'];

                                                            $variantTotal += $variantStock;

                                                            $variantName = trim($variant['variant_title'] ?? '');

                                                            if ($variantName === '') {
                                                                $variantName = !empty($variant['sku'])
                                                                    ? $variant['sku']
                                                                    : 'Variant';
                                                            }

                                                        ?>

                                                            <div class="stock-variant-row">

                                                                <span class="stock-variant-name">

                                                                    <?php echo htmlspecialchars($variantName); ?>

                                                                </span>

                                                                <span class="stock-variant-quantity">

                                                                    <?php echo $variantStock; ?>

                                                                </span>

                                                            </div>

                                                        <?php endforeach; ?>

                                                        <div class="stock-total-row">

                                                            <span>Total Stock</span>

                                                            <span>
                                                                <?php echo $variantTotal; ?>
                                                            </span>

                                                        </div>

                                                    </div>

                                                </div>

                                            <?php endif; ?>

                                        </td>
                                        <td>

                                            <?php
                                            echo productStatusBadge(
                                                $product['status']
                                            );
                                            ?>

                                        </td>
                                        <td>

                                            <?php
                                            echo date(
                                                'd M Y',
                                                strtotime($product['created_at'])
                                            );
                                            ?>

                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a
                                                    href="product-edit.php?id=<?php echo (int) $product['id']; ?>"
                                                    class="btn btn-sm btn-outline-warning"
                                                    title="Edit Product">
                                                    Edit
                                                </a>


                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-primary"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#productModal<?php echo $product['id']; ?>">
                                                    View
                                                </button>
                                                <?php if ((int) $product['status'] === 0): ?>

                                                    <form method="POST">

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="approve">

                                                        <input
                                                            type="hidden"
                                                            name="product_id"
                                                            value="<?php echo (int) $product['id']; ?>">

                                                        <button
                                                            type="submit"
                                                            class="btn btn-sm btn-success"
                                                            onclick="return confirm('Approve this product?');">
                                                            Approve
                                                        </button>

                                                    </form>

                                                <?php endif; ?>
                                                <?php if ((int) $product['status'] === 0): ?>

                                                    <form method="POST">

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="activate">

                                                        <input
                                                            type="hidden"
                                                            name="product_id"
                                                            value="<?php echo (int) $product['id']; ?>">

                                                        <button
                                                            type="submit"
                                                            class="btn btn-sm btn-outline-success"
                                                            onclick="return confirm('Activate this product?');">
                                                            Activate
                                                        </button>

                                                    </form>

                                                <?php endif; ?>
                                                <?php if ((int) $product['status'] === 1): ?>

                                                    <form method="POST">

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="deactivate">

                                                        <input
                                                            type="hidden"
                                                            name="product_id"
                                                            value="<?php echo (int) $product['id']; ?>">

                                                        <button
                                                            type="submit"
                                                            class="btn btn-sm btn-outline-secondary"
                                                            onclick="return confirm('Deactivate this product?');">
                                                            Deactivate
                                                        </button>

                                                    </form>

                                                <?php endif; ?>
                                                <form method="POST">

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="delete">

                                                    <input
                                                        type="hidden"
                                                        name="product_id"
                                                        value="<?php echo (int) $product['id']; ?>">

                                                    <button
                                                        type="submit"
                                                        class="btn btn-sm btn-outline-danger"
                                                        onclick="return confirm('Delete this product permanently? This action cannot be undone.');">
                                                        Delete
                                                    </button>

                                                </form>

                                            </div>

                                        </td>

                                    </tr>
                                    <div
                                        class="modal fade"
                                        id="productModal<?php echo $product['id']; ?>"
                                        tabindex="-1">

                                        <div
                                            class="modal-dialog modal-lg
                                           modal-dialog-centered">

                                            <div class="modal-content">

                                                <div class="modal-header">

                                                    <h5 class="modal-title">
                                                        Product Details
                                                    </h5>

                                                    <button
                                                        type="button"
                                                        class="btn-close"
                                                        data-bs-dismiss="modal">
                                                    </button>

                                                </div>


                                                <div class="modal-body">

                                                    <div class="row g-4">

                                                        <!-- Image -->

                                                        <div class="col-md-4 text-center">

                                                            <?php if (!empty($product['image'])): ?>

                                                                <img
                                                                    src="../<?php
                                                                            echo htmlspecialchars(
                                                                                ltrim(
                                                                                    $product['image'],
                                                                                    '/'
                                                                                )
                                                                            );
                                                                            ?>"
                                                                    class="modal-product-image"
                                                                    alt="Product">

                                                            <?php else: ?>

                                                                <div
                                                                    class="modal-product-image
                                                                   d-flex
                                                                   align-items-center
                                                                   justify-content-center
                                                                   mx-auto">

                                                                    <span class="text-muted">
                                                                        No Image
                                                                    </span>

                                                                </div>

                                                            <?php endif; ?>

                                                        </div>


                                                        <!-- Details -->

                                                        <div class="col-md-8">

                                                            <h4>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $product['title']
                                                                );
                                                                ?>
                                                            </h4>

                                                            <p class="text-muted">

                                                                <?php
                                                                echo nl2br(
                                                                    htmlspecialchars(
                                                                        $product['description']
                                                                            ?: 'No description available.'
                                                                    )
                                                                );
                                                                ?>

                                                            </p>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>SKU</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $product['sku'] ?: '-'
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Category</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $product['category_name']
                                                                        ?: '-'
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Brand</strong>

                                                            <div>
                                                                <?php
                                                                echo htmlspecialchars(
                                                                    $product['brand_name']
                                                                        ?: '-'
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Price</strong>

                                                            <div>
                                                                ₹<?php
                                                                    echo number_format(
                                                                        (float) $product['price'],
                                                                        2
                                                                    );
                                                                    ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Original Price</strong>

                                                            <div>
                                                                ₹<?php
                                                                    echo number_format(
                                                                        (float) $product['original_price'],
                                                                        2
                                                                    );
                                                                    ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Discount</strong>

                                                            <div>
                                                                <?php
                                                                echo number_format(
                                                                    (float) $product['discount'],
                                                                    2
                                                                );
                                                                ?>%
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Stock</strong>

                                                            <div>
                                                                <?php
                                                                echo (int) $product['stock'];
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Status</strong>

                                                            <div>
                                                                <?php
                                                                echo productStatusBadge(
                                                                    $product['status']
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>


                                                        <div class="col-md-4">

                                                            <strong>Created</strong>

                                                            <div>
                                                                <?php
                                                                echo date(
                                                                    'd M Y H:i',
                                                                    strtotime(
                                                                        $product['created_at']
                                                                    )
                                                                );
                                                                ?>
                                                            </div>

                                                        </div>
                                                        <div class="col-md-12">

                                                            <strong>Vendor</strong>

                                                            <div>

                                                                <?php if (!empty($product['vendor_id'])): ?>

                                                                    <?php
                                                                    echo htmlspecialchars(
                                                                        $product['vendor_store']
                                                                            ?: $product['vendor_name']
                                                                            ?: '-'
                                                                    );
                                                                    ?>

                                                                    <?php if (!empty($product['vendor_email'])): ?>

                                                                        <span class="text-muted">

                                                                            -
                                                                            <?php
                                                                            echo htmlspecialchars(
                                                                                $product['vendor_email']
                                                                            );
                                                                            ?>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                <?php else: ?>

                                                                    Admin / General Product

                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button
                                                        type="button"
                                                        class="btn btn-secondary"
                                                        data-bs-dismiss="modal">
                                                        Close
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>


                <?php
                $queryParams = $_GET;
                $queryParams['per_page'] = $perPage;
                ?>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-3">

                    <span class="text-muted">
                        Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                    </span>

                    <nav aria-label="Product pagination">
                        <ul class="pagination mb-0">

                            <?php $queryParams['page'] = max(1, $page - 1); ?>

                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link"
                                    href="?<?php echo htmlspecialchars(http_build_query($queryParams)); ?>">
                                    Previous
                                </a>
                            </li>

                            <?php $queryParams['page'] = min($totalPages, $page + 1); ?>

                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link"
                                    href="?<?php echo htmlspecialchars(http_build_query($queryParams)); ?>">
                                    Next
                                </a>
                            </li>

                        </ul>
                    </nav>

                </div>

            </div>
        </div>

<?php adminFooter();