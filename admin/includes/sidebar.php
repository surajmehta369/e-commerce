<?php
/** @var string $activeMenu */

$adminMenu = [
    'dashboard'  => ['Dashboard',       'index.php',      'bi-speedometer2'],
    'vendors'    => ['Vendors',         'vendors.php',    'bi-shop'],
    'products'   => ['Products',        'products.php',   'bi-box-seam'],
    'customers'  => ['Customers',       'customers.php',  'bi-people'],
    'categories' => ['Categories',      'categories.php', 'bi-tags'],
    'brands'     => ['Brands',          'brands.php',     'bi-award'],
    'orders'     => ['Orders',          'orders.php',     'bi-bag-check'],
    'reviews'    => ['Product Reviews', 'reviews.php',    'bi-star'],
    'profile'    => ['Admin Profile',   'profile.php',    'bi-person-circle'],
];
?>
<aside class="admin-sidebar" id="adminSidebar">

    <div class="admin-brand">
        <i class="bi bi-grid-1x2-fill me-2"></i>E-Commerce Admin
    </div>

    <nav class="admin-nav">
        <?php foreach ($adminMenu as $key => [$label, $file, $icon]): ?>
            <a href="<?= e($file) ?>"
               data-menu="<?= e($key) ?>"
               class="nav-item-link <?= $activeMenu === $key ? 'active' : '' ?>"
               <?= $activeMenu === $key ? 'aria-current="page"' : '' ?>>
                <i class="bi <?= e($icon) ?>"></i>
                <span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>

        <div class="admin-nav-divider"></div>
        <a href="../outh/logout.php" class="nav-item-link text-danger-emphasis logout" data-no-ajax>
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>
    </nav>

</aside>
