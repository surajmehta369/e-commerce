<?php
$sidebarCategories = [];
$fitnessCategoryId = 0;

try {
    require_once __DIR__ . '/../connection/dbconnect.php';

    $database = new Database();
    $sidebarDb = $database->connect();
    $categoryStmt = $sidebarDb->prepare("
    SELECT id, parent_id, name, slug
    FROM categories
    WHERE status = 1
    ORDER BY name ASC
");
    $categoryStmt->execute();
    $allSidebarCategories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

    $sidebarCategories = array_values(array_filter(
        $allSidebarCategories,
        fn($category) => $category['parent_id'] === null
            || (int) $category['parent_id'] === 0
    ));
    foreach ($sidebarCategories as $category) {
        $name = strtolower(trim($category['name']));
        $slug = strtolower(trim($category['slug']));

        if (
            strpos($name, 'fitness') !== false ||
            strpos($name, 'supplement') !== false ||
            strpos($slug, 'fitness') !== false ||
            strpos($slug, 'supplement') !== false
        ) {
            $fitnessCategoryId = (int) $category['id'];
            break;
        }
    }
} catch (Throwable $e) {
    error_log('Sidebar category loading failed: ' . $e->getMessage());
}
?>

<div id="sidebarOverlay" class="sidebar-overlay"></div>

<div id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <span>☰ &nbsp; All</span>
        <button type="button" id="closeSidebar"
            aria-label="Close sidebar">&times;</button>
    </div>

    <div class="sidebar-section">
        <h3>Trending</h3>

        <a href="best-sellers.php">Best Sellers</a>

        <a href="related-products.php?sort=newest&amp;limit=10">
            New Releases
        </a>

        <?php if ($fitnessCategoryId > 0): ?>
            <a href="related-products.php?category_id=<?= $fitnessCategoryId ?>">
                Movers &amp; Shakers
            </a>
        <?php else: ?>
            <a href="related-products.php?sort=newest&amp;limit=10">
                Movers &amp; Shakers
            </a>
        <?php endif; ?>
    </div>

    <div class="sidebar-section">
        <h3>Shop by Category</h3>

        <?php if (!empty($sidebarCategories)): ?>
            <?php foreach ($sidebarCategories as $category): ?>
                <a href="related-products.php?category_id=<?= (int) $category['id'] ?>">
                    <?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?>
                </a>

                <?php
                $children = array_filter(
                    $allSidebarCategories,
                    fn($child) => (int) $child['parent_id'] === (int) $category['id']
                );
                ?>

                <?php foreach ($children as $child): ?>
                    <a class="sidebar-subcategory"
                        href="related-products.php?category_id=<?= (int) $child['id'] ?>">
                        &nbsp;&nbsp;↳
                        <?= htmlspecialchars($child['name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <p>Categories are currently unavailable.</p>
        <?php endif; ?>
    </div>
</div>