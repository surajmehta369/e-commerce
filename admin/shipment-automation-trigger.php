
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/layout.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

ignore_user_abort(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

if (!adminVerifyCsrf()) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

session_write_close();

try {

    define('SHIPMENT_AUTOMATION_HTTP', true);

    require_once __DIR__ . '/../shipment-automation.php';

} catch (Throwable $e) {

    error_log(
        '[' . date('Y-m-d H:i:s') . '] Automation endpoint error: '
        . $e->getMessage()
    );

    http_response_code(500);
    echo PHP_EOL . 'Automation failed. Check the PHP error log.';
}
