
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
$rawVariantId = $_POST['variant_id'] ?? '';
$imagePath = trim((string) ($_POST['image_path'] ?? ''));

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

$variantId = null;

if ($rawVariantId !== '' && $rawVariantId !== null) {
    $validatedVariantId = filter_var($rawVariantId, FILTER_VALIDATE_INT);

    if (!$validatedVariantId || $validatedVariantId < 1) {
        $_SESSION['wishlist_message'] = [
            'type' => 'danger',
            'text' => 'Invalid product variant.'
        ];
        header('Location: ' . $returnTo);
        exit;
    }

    $variantId = (int) $validatedVariantId;
}

try {
    $productStmt = $db->prepare("
        SELECT id, image
        FROM products
        WHERE id = :product_id
          AND status = 1
        LIMIT 1
    ");
    $productStmt->execute([':product_id' => $productId]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        throw new RuntimeException('This product is unavailable.');
    }

    // Verify that the selected variant belongs to this product.
    if ($variantId !== null) {
        $variantStmt = $db->prepare("
            SELECT id
            FROM product_variants
            WHERE id = :variant_id
              AND product_id = :product_id
              AND status = 1
            LIMIT 1
        ");
        $variantStmt->execute([
            ':variant_id' => $variantId,
            ':product_id' => $productId
        ]);

        if (!$variantStmt->fetchColumn()) {
            throw new RuntimeException('The selected variant is unavailable.');
        }

        // Verify that the submitted image belongs to this variant.
        if ($imagePath !== '') {
            $imageStmt = $db->prepare("
                SELECT 1
                FROM product_variant_images
                WHERE variant_id = :variant_id
                  AND image_path = :image_path
                LIMIT 1
            ");
            $imageStmt->execute([
                ':variant_id' => $variantId,
                ':image_path' => $imagePath
            ]);

            if (!$imageStmt->fetchColumn()) {
                // The main product image can also be displayed for a variant.
                $imagePath = $product['image'] ?? '';
            }
        } else {
            $imagePath = $product['image'] ?? '';
        }
    } else {
        // A product without a selected variant uses its main product image.
        $imagePath = $product['image'] ?? '';
    }

    // Find the exact row. Use separate placeholders for native PDO prepares.
    $findStmt = $db->prepare("
        SELECT id
        FROM wishlists
        WHERE user_id = :user_id
          AND product_id = :product_id
          AND (
              variant_id = :variant_id
              OR (variant_id IS NULL AND :variant_id_null IS NULL)
          )
        LIMIT 1
    ");

    $findStmt->execute([
        ':user_id' => $userId,
        ':product_id' => $productId,
        ':variant_id' => $variantId,
        ':variant_id_null' => $variantId
    ]);

    $wishlistRowId = $findStmt->fetchColumn();

    if ($action === 'add') {
        if ($wishlistRowId) {
            $updateStmt = $db->prepare("
                UPDATE wishlists
                SET image_path = :image_path
                WHERE id = :id
                  AND user_id = :user_id
            ");
            $updateStmt->execute([
                ':image_path' => $imagePath,
                ':id' => $wishlistRowId,
                ':user_id' => $userId
            ]);
        } else {
            $insertStmt = $db->prepare("
                INSERT INTO wishlists
                    (user_id, product_id, variant_id, image_path)
                VALUES
                    (:user_id, :product_id, :variant_id, :image_path)
            ");
            $insertStmt->execute([
                ':user_id' => $userId,
                ':product_id' => $productId,
                ':variant_id' => $variantId,
                ':image_path' => $imagePath
            ]);
        }

        $_SESSION['wishlist_message'] = [
            'type' => 'success',
            'text' => 'Item added to your wishlist.'
        ];

    } elseif ($action === 'remove') {
        $deleteStmt = $db->prepare("
            DELETE FROM wishlists
            WHERE user_id = :user_id
              AND product_id = :product_id
              AND (
                  variant_id = :variant_id
                  OR (variant_id IS NULL AND :variant_id_null IS NULL)
              )
        ");
        $deleteStmt->execute([
            ':user_id' => $userId,
            ':product_id' => $productId,
            ':variant_id' => $variantId,
            ':variant_id_null' => $variantId
        ]);

        $_SESSION['wishlist_message'] = [
            'type' => 'success',
            'text' => 'Item removed from your wishlist.'
        ];

    } else {
        throw new RuntimeException('Invalid wishlist action.');
    }

} catch (Throwable $e) {
    error_log('Wishlist action error: ' . $e->getMessage());

    $_SESSION['wishlist_message'] = [
        'type' => 'danger',
        'text' => $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Something went wrong. Please try again.'
    ];
}

header('Location: ' . $returnTo);
exit;
