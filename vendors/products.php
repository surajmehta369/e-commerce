<?php

require_once __DIR__ . '/includes/layout.php';

$vendorId = (int) $_SESSION['user_id'];

$database = new Database();
$db = $database->connect();

$sql = "
    SELECT
        p.id,
        p.sku,
        p.title,
        p.price,
        p.original_price,
        p.discount,
        p.stock,
        p.status,
        p.created_at,
        c.name AS category_name,
        b.name AS brand_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN brands b ON b.id = p.brand_id
    WHERE p.vendor_id = :vendor_id
    ORDER BY p.id DESC
";

$stmt = $db->prepare($sql);

$stmt->execute([
    'vendor_id' => $vendorId
]);

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$productCount = count($products);

vendorHeader('Products', 'products');

?>

<div class="vendor-products-page">

    <div class="vendor-products-heading">

        <div>
            <h2>Products</h2>
            <p>Manage your store products.</p>
        </div>

        <div class="vendor-products-heading-actions">

            <span class="vendor-products-count">
                Total Products: <?= $productCount; ?>
            </span>

            <a href="product-add.php" class="vendor-add-product-btn">
                <i class="fas fa-plus"></i>
                Add Product
            </a>

        </div>

    </div>

    <?php if (isset($_GET['deleted'])): ?>

        <div class="vendor-products-alert vendor-products-success">
            Product deleted successfully.
        </div>

    <?php endif; ?>

    <?php if (isset($_GET['delete_error'])): ?>

        <div class="vendor-products-alert vendor-products-error">
            Unable to delete the product. Please try again.
        </div>

    <?php endif; ?>


    <div class="vendor-products-card">

        <div class="vendor-products-table-wrapper">

            <table class="vendor-products-table">

                <thead>
                    <tr>
                        <th>S.No.</th>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Brand</th>
                        <th>Price</th>
                        <th>Discount</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($products)): ?>

                        <tr>
                            <td colspan="11" class="vendor-products-empty">
                                No products found.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($products as $index => $product): ?>

                            <tr>

                                <td>
                                    <?= $index + 1; ?>
                                </td>

                                <td>
                                    <?= vendorEscape($product['sku'] ?? '-'); ?>
                                </td>

                                <td class="vendor-product-title">
                                    <?= vendorEscape($product['title']); ?>
                                </td>

                                <td>
                                    <?= vendorEscape($product['category_name'] ?? '-'); ?>
                                </td>

                                <td>
                                    <?= vendorEscape($product['brand_name'] ?? '-'); ?>
                                </td>

                                <td>
                                    ₹<?= number_format(
                                            (float) $product['price'],
                                            2
                                        ); ?>
                                </td>

                                <td>
                                    <?= number_format(
                                        (float) $product['discount'],
                                        2
                                    ); ?>%
                                </td>

                                <td>
                                    <?= (int) $product['stock']; ?>
                                </td>

                                <td>

                                    <?php if ((int) $product['stock'] <= 0): ?>

                                        <span class="vendor-product-status out-of-stock">
                                            Out of Stock
                                        </span>

                                    <?php elseif ((int) $product['status'] === 1): ?>

                                        <span class="vendor-product-status active">
                                            Active
                                        </span>

                                    <?php else: ?>

                                        <span class="vendor-product-status pending">
                                            Pending Approval
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?= vendorEscape($product['created_at']); ?>
                                </td>

                                <td>

                                    <div class="vendor-product-actions">

                                        <a
                                            href="product-edit.php?id=<?= (int) $product['id']; ?>"
                                            class="vendor-edit-btn">
                                            Edit
                                        </a>

                                        <a
                                            href="product-delete.php?id=<?= (int) $product['id']; ?>"
                                            class="vendor-delete-btn"
                                            onclick="return confirm('Are you sure you want to delete this product?');">
                                            Delete
                                        </a>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php

vendorFooter();
