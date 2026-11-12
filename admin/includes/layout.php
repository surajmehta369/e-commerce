<?php

require_once __DIR__ . '/../auth.php';

ob_start();


function adminCsrfToken(): string
{
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_csrf'];
}

function adminVerifyCsrf(): bool
{
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    return is_string($sent)
        && $sent !== ''
        && hash_equals(adminCsrfToken(), $sent);
}



function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function adminFlashMessages(): void
{
    foreach (['success_message' => 'success', 'error_message' => 'warning'] as $key => $type) {
        if (!empty($_SESSION[$key])) {
            echo '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">'
                . e($_SESSION[$key])
                . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            unset($_SESSION[$key]);
        }
    }
}

function adminHeader(string $title, string $activeMenu = ''): void
{

    header('Vary: X-Requested-With');
    header('Cache-Control: no-store, max-age=0');

    if (isAjaxRequest()) {
        header('X-Admin-Fragment: 1');
        header('X-Page-Title: ' . rawurlencode($title . ' | Admin'));
        header('X-Active-Menu: ' . $activeMenu);
        adminFlashMessages();
        return;
    }

    $adminBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="<?= e(adminCsrfToken()) ?>">
        <title><?= e($title) ?> | Admin</title>

        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <link href="<?= e($adminBase) ?>assets/css/admin.css" rel="stylesheet">

        <script>
            window.Admin = window.Admin || {
                _q: [],
                onReady: function(fn) {
                    this._q.push(fn);
                }
            };
        </script>
    </head>

    <body>
        <div id="adminProgress" class="admin-progress"></div>

        <?php require __DIR__ . '/sidebar.php'; ?>
        <div class="admin-overlay" id="adminOverlay"></div>

        <div class="admin-main">

            <div class="admin-topbar">
                <div class="d-flex align-items-center gap-3">
                    <button class="btn btn-outline-secondary btn-sm d-lg-none"
                        id="sidebarToggle"
                        type="button"
                        aria-label="Toggle menu">
                        <i class="bi bi-list"></i>
                    </button>

                    <strong class="admin-topbar-title">
                        <?= e($title) ?>
                    </strong>
                </div>
                <div class="admin-topbar-actions">
                    <?php
                    $unreadNotifications = 0;

                    try {
                        require_once __DIR__ . '/../../connection/dbconnect.php';

                        $notificationDatabase = new Database();
                        $notificationDb = $notificationDatabase->connect();

                        $notificationStmt = $notificationDb->query("
        SELECT COUNT(*)
        FROM contact_messages
        WHERE status = 'new'
    ");

                        $unreadNotifications = (int) $notificationStmt->fetchColumn();
                    } catch (Throwable $e) {
                        $unreadNotifications = 0;
                    }
                    ?>

                    <a href="notifications.php"
                        class="admin-topbar-icon"
                        title="Notifications"
                        aria-label="Notifications">

                        <i class="bi bi-bell"></i>

                        <?php if ($unreadNotifications > 0): ?>
                            <span class="notification-count">
                                <?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?>
                            </span>
                        <?php endif; ?>

                    </a>
                    <a href="profile.php"
                        class="admin-topbar-icon"
                        title="Admin Profile"
                        aria-label="Admin Profile">

                        <i class="bi bi-person-circle"></i>

                    </a>

                </div>

            </div>

            <main id="adminContent" class="admin-content" aria-live="polite">
            <?php
            adminFlashMessages();
        }

        function adminAssetBase(): string
        {
            return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
        }

        function adminFooter(): void
        {
            if (isAjaxRequest()) {
                return;
            }
            ?>
            </main>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script src="<?= e(adminAssetBase()) ?>assets/js/admin.js"></script>
    </body>

    </html>
<?php
        }
