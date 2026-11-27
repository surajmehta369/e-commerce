
<?php

$currentMenu = $activeMenu ?? 'dashboard';

$vendorStoreName = $vendor['store_name'] ?? 'My Store';
$vendorOwnerName = $_SESSION['user_name'] ?? 'Vendor';

$sidebarItems = [
    [
        'menu' => 'dashboard',
        'label' => 'Dashboard',
        'icon' => 'bi-grid-1x2',
        'url' => 'index.php'
    ],
    [
        'menu' => 'products',
        'label' => 'Products',
        'icon' => 'bi-box-seam',
        'url' => 'products.php'
    ],
    [
        'menu' => 'product-add',
        'label' => 'Add Product',
        'icon' => 'bi-plus-circle',
        'url' => 'product-add.php'
    ],
    [
        'menu' => 'orders',
        'label' => 'Orders',
        'icon' => 'bi-bag-check',
        'url' => 'orders.php'
    ],
    [
        'menu' => 'reviews',
        'label' => 'Product Reviews',
        'icon' => 'bi-star',
        'url' => 'reviews.php'
    ],
    [
        'menu' => 'profile',
        'label' => 'Store Profile',
        'icon' => 'bi-shop',
        'url' => 'profile.php'
    ]
];

?>

<aside id="vendorSidebar" class="vendor-sidebar">

    <div class="vendor-sidebar-brand">

        <div class="vendor-brand-icon">
            <i class="bi bi-shop"></i>
        </div>

        <div>
            <h5 class="mb-0">Vendor Panel</h5>
            <small>Seller Dashboard</small>
        </div>

    </div>

    <div class="vendor-sidebar-profile">

        <div class="vendor-profile-avatar">
            <i class="bi bi-person"></i>
        </div>

        <div class="vendor-profile-info">

            <strong>
                <?= vendorEscape($vendorOwnerName); ?>
            </strong>

            <small>
                <?= vendorEscape($vendorStoreName); ?>
            </small>

        </div>

    </div>

    <nav class="vendor-navigation">

        <div class="vendor-nav-heading">
            MAIN MENU
        </div>

        <?php foreach ($sidebarItems as $item): ?>

            <?php
            $isActive = $currentMenu === $item['menu'];

            // Product listing, adding and editing share the Products section.
            if (
                $currentMenu === 'products'
                && $item['menu'] === 'products'
            ) {
                $isActive = true;
            }
            ?>

            <a
                href="<?= vendorEscape($item['url']); ?>"
                class="vendor-nav-link <?= $isActive ? 'active' : ''; ?>"
                data-menu="<?= vendorEscape($item['menu']); ?>"
                <?= $isActive ? 'aria-current="page"' : ''; ?>
            >

                <i class="bi <?= vendorEscape($item['icon']); ?>"></i>

                <span>
                    <?= vendorEscape($item['label']); ?>
                </span>

            </a>

        <?php endforeach; ?>

        <div class="vendor-nav-heading vendor-nav-heading-bottom">
            ACCOUNT
        </div>

        <a
            href="../outh/logout.php"
            class="vendor-nav-link vendor-logout-link"
        >

            <i class="bi bi-box-arrow-right"></i>

            <span>Logout</span>

        </a>

    </nav>

    <div class="vendor-sidebar-footer">

        <small>
            Vendor Management System
        </small>

    </div>

</aside>
