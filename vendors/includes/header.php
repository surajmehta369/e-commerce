
<?php

$pageTitle = $title ?? 'Vendor Dashboard';

?>
<div id="vendorProgress" class="vendor-progress"></div>
<header class="vendor-topbar">

    <div class="vendor-topbar-left">

        <button
            type="button"
            id="vendorSidebarToggle"
            class="vendor-sidebar-toggle"
            aria-label="Toggle sidebar"
        >
            <i class="bi bi-list"></i>
        </button>

        <div>

            <h5 class="vendor-topbar-title mb-0">
                <?= vendorEscape($pageTitle); ?>
            </h5>

            <small class="vendor-topbar-subtitle">
                Manage your store from one place
            </small>

        </div>

    </div>

    <div class="vendor-topbar-right">

        <div class="vendor-topbar-store">

            <i class="bi bi-shop"></i>

            <span>
                <?= vendorEscape($vendor['store_name'] ?? 'My Store'); ?>
            </span>

        </div>

        <div class="vendor-topbar-user">

            <div class="vendor-user-avatar">
                <i class="bi bi-person"></i>
            </div>

            <div class="vendor-user-details">

                <strong>
                    <?= vendorEscape($_SESSION['user_name'] ?? 'Vendor'); ?>
                </strong>

                <small>Vendor</small>

            </div>

        </div>

    </div>

</header>
