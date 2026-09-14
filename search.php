<?php

session_start();

require_once __DIR__ . '/connection/dbconnect.php';

$db = new Database();
$conn = $db->connect();

$q = trim($_GET['q'] ?? '');

$products = [];

if ($q !== '') {

    $searchTerm = '%' . $q . '%';

    $sql = "
        SELECT
            id,
            sku,
            title,
            slug,
            description,
            image,
            price,
            stock,
            status
        FROM products
        WHERE status = 1
          AND (
                title LIKE :search
                OR description LIKE :search
                OR sku LIKE :search
          )
        ORDER BY id DESC
    ";

    $stmt = $conn->prepare($sql);

    $stmt->execute([
        ':search' => $searchTerm
    ]);

    $products = $stmt->fetchAll();
}

require_once __DIR__ . '/components/header.php';

?>

<div class="container" style="margin-top: 100px;">

    <h2>Search Results</h2>

    <?php if ($q === ''): ?>

        <p>Please enter something to search.</p>

    <?php else: ?>

        <p>
            Search results for:
            <strong><?= htmlspecialchars($q) ?></strong>
        </p>

        <?php if (empty($products)): ?>

            <div class="alert alert-warning">
                No products found.
            </div>

        <?php else: ?>

            <div class="row">

                <?php foreach ($products as $product): ?>

                    <div class="col-md-4 mb-4">

                        <div class="card h-100">

                            <?php if (!empty($product['image'])): ?>

                                <img
                                    src="<?= htmlspecialchars($product['image']) ?>"
                                    class="card-img-top"
                                    style="height: 220px; object-fit: cover;"
                                    alt="<?= htmlspecialchars($product['title']) ?>">

                            <?php endif; ?>

                            <div class="card-body">

                                <h5 class="card-title">
                                    <?= htmlspecialchars($product['title']) ?>
                                </h5>

                                <p class="card-text">
                                    <?= htmlspecialchars($product['description']) ?>
                                </p>

                                <h6>
                                    ₹<?= number_format($product['price'], 2) ?>
                                </h6>

                                <p>
                                    Stock:
                                    <?= (int) $product['stock'] ?>
                                </p>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/components/footer.php'; ?>