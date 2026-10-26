
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/connection/dbconnect.php';


$database = new Database();
$db = $database->connect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
if (
    empty($_SESSION['logged_in']) ||
    empty($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'customer'
) {
    $_SESSION['wishlist_message'] = [
        'type' => 'warning',
        'text' => 'Please log in as a customer to manage your wishlist.'
    ];

    $_SESSION['wishlist_login_redirect'] = $_POST['return_to'] ?? 'wishlist.php';

    header('Location: outh/login.php');
    exit;
}

$sessionToken = $_SESSION['wishlist_csrf_token'] ?? '';
$submittedToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($submittedToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    http_response_code(403);
    exit('Invalid security token. Please refresh the page and try again.');
}

$userId = (int) $_SESSION['user_id'];
$productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';
$returnTo = $_POST['return_to'] ?? 'wishlist.php';
if (
    !is_string($returnTo) ||
    preg_match('/^[a-zA-Z0-9_\/?=&.-]+$/', $returnTo) !== 1 ||
    str_contains($returnTo, '..') ||
    str_starts_with($returnTo, '/')
) {
    $returnTo = 'wishlist.php';
}

if (!$productId || $productId < 1) {
    $_SESSION['wishlist_message'] = [
        'type' => 'danger',
        'text' => 'Invalid product.'
    ];

    header('Location: ' . $returnTo);
    exit;
}

try {
    if ($action === 'add') {
        $productStmt = $db->prepare("
            SELECT id
            FROM products
            WHERE id = :product_id
              AND status = 1
            LIMIT 1
        ");

        $productStmt->execute([':product_id' => $productId]);

        if (!$productStmt->fetchColumn()) {
            $_SESSION['wishlist_message'] = [
                'type' => 'danger',
                'text' => 'This product is unavailable.'
            ];

            header('Location: ' . $returnTo);
            exit;
        }
        $stmt = $db->prepare("
            INSERT INTO wishlists (user_id, product_id)
            VALUES (:user_id, :product_id)
            ON DUPLICATE KEY UPDATE product_id = VALUES(product_id)
        ");

        $stmt->execute([
            ':user_id' => $userId,
            ':product_id' => $productId
        ]);

        $_SESSION['wishlist_message'] = [
            'type' => 'success',
            'text' => 'Product added to your wishlist.'
        ];

    } elseif ($action === 'remove') {
        $stmt = $db->prepare("
            DELETE FROM wishlists
            WHERE user_id = :user_id
              AND product_id = :product_id
        ");

        $stmt->execute([
            ':user_id' => $userId,
            ':product_id' => $productId
        ]);

        $_SESSION['wishlist_message'] = [
            'type' => 'success',
            'text' => 'Product removed from your wishlist.'
        ];

    } else {
        $_SESSION['wishlist_message'] = [
            'type' => 'danger',
            'text' => 'Invalid wishlist action.'
        ];
    }

} catch (PDOException $e) {
    error_log('Wishlist action error: ' . $e->getMessage());

    $_SESSION['wishlist_message'] = [
        'type' => 'danger',
        'text' => 'Something went wrong. Please try again.'
    ];
}

header('Location: ' . $returnTo);
exit;
