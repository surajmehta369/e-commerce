<?php

require_once __DIR__ . '/../auth.php';

/**
 * Vendor CSRF token.
 */
function vendorCsrfToken(): string
{
    if (empty($_SESSION['vendor_csrf_token'])) {
        $_SESSION['vendor_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['vendor_csrf_token'];
}

/**
 * Verify vendor CSRF token.
 */
function vendorVerifyCsrf(?string $token = null): bool
{
    $token = $token ?? ($_POST['csrf_token'] ?? '');

    return is_string($token)
        && !empty($_SESSION['vendor_csrf_token'])
        && hash_equals($_SESSION['vendor_csrf_token'], $token);
}

/**
 * Escape HTML output.
 */
function vendorEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * Detect vendor AJAX requests.
 */
function vendorIsAjaxRequest(): bool
{
    return (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) || (
        ($_SERVER['HTTP_X_VENDOR_FRAGMENT'] ?? '') === '1'
    );
}

/**
 * Vendor asset directory.
 */
function vendorAssetBase(): string
{
    return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/assets';
}

/**
 * Display and clear vendor flash messages.
 */
function vendorFlashMessages(): void
{
    if (empty($_SESSION['vendor_flash'])) {
        return;
    }

    $messages = $_SESSION['vendor_flash'];
    unset($_SESSION['vendor_flash']);

    foreach ($messages as $message) {
        $type = in_array(
            $message['type'] ?? '',
            ['success', 'danger', 'warning', 'info'],
            true
        ) ? $message['type'] : 'info';

        echo '<div class="alert alert-' . $type .
            ' alert-dismissible fade show" role="alert">';

        echo vendorEscape($message['message'] ?? '');

        echo '<button type="button" class="btn-close" ' .
            'data-bs-dismiss="alert" aria-label="Close"></button>';

        echo '</div>';
    }
}

/**
 * Render vendor page header and shared layout.
 */
function vendorHeader(
    string $title = 'Vendor Dashboard',
    string $activeMenu = 'dashboard'
): void {

    $isAjax = vendorIsAjaxRequest();

    if ($isAjax) {
        header('X-Vendor-Fragment: 1');
        header('X-Page-Title: ' . rawurlencode($title));
        header('X-Active-Menu: ' . $activeMenu);

        echo '<main id="vendorContent" class="vendor-content">';
        vendorFlashMessages();

        return;
    }

    $assetBase = vendorAssetBase();

?>
    <!DOCTYPE html>
    <html lang="en">

    <head>

        <meta charset="UTF-8">

        <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0">
        <meta
            name="vendor-csrf-token"
            content="<?= vendorEscape(vendorCsrfToken()); ?>">

        <title><?= vendorEscape($title); ?> | Vendor Panel</title>

        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
            rel="stylesheet">

        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
            rel="stylesheet">

        <link
            href="<?= vendorEscape($assetBase); ?>/css/vendor.css"
            rel="stylesheet">

    </head>

    <body>

        <div class="vendor-app">

            <?php
            require __DIR__ . '/sidebar.php';
            ?>

            <div class="vendor-main">

                <?php
                require __DIR__ . '/header.php';
                ?>

                <main id="vendorContent" class="vendor-content">

                    <?php vendorFlashMessages(); ?>

                <?php
            }

            /**
             * Close vendor page layout.
             */
            function vendorFooter(): void
            {
                if (vendorIsAjaxRequest()) {
                    echo '</main>';
                    return;
                }

                $assetBase = vendorAssetBase();

                ?>

                </main>

            </div>

        </div>

        <div id="vendorOverlay" class="vendor-overlay"></div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

        <script src="<?= vendorEscape($assetBase); ?>/js/vendor.js"></script>

    </body>

    </html>

<?php
            }
