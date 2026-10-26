<?php
session_start();

require_once __DIR__ . '/connection/dbconnect.php';

$db = (new Database())->connect();

function reviewRedirect(int $productId, string $message): void
{
    header(
        'Location: product-details.php?id=' . $productId .
        '&review_message=' . urlencode($message) . '#reviews'
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
$productId = $productId ? (int) $productId : 0;

if ($productId < 1) {
    http_response_code(400);
    exit('Invalid product.');
}

if (
    empty($_SESSION['logged_in']) ||
    empty($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'customer'
) {
    reviewRedirect($productId, 'login_required');
}

$userId = (int) $_SESSION['user_id'];
$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['review_csrf_token'] ?? '';

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    reviewRedirect($productId, 'invalid_request');
}

$rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);

if ($rating === false || $rating === null || $rating < 1 || $rating > 5) {
    reviewRedirect($productId, 'invalid_rating');
}

$title = trim((string) ($_POST['review_title'] ?? ''));
$reviewText = trim((string) ($_POST['review_text'] ?? ''));

if (mb_strlen($title) > 150) {
    reviewRedirect($productId, 'title_too_long');
}

if ($reviewText === '' || mb_strlen($reviewText) > 5000) {
    reviewRedirect($productId, 'invalid_review_text');
}

try {
    $userStmt = $db->prepare("
        SELECT id
        FROM users
        WHERE id = :user_id
          AND role = 'customer'
          AND status = 1
        LIMIT 1
    ");

    $userStmt->execute([':user_id' => $userId]);

    if (!$userStmt->fetchColumn()) {
        reviewRedirect($productId, 'account_not_eligible');
    }
    $productStmt = $db->prepare("
        SELECT id
        FROM products
        WHERE id = :product_id
          AND status = 1
        LIMIT 1
    ");

    $productStmt->execute([':product_id' => $productId]);

    if (!$productStmt->fetchColumn()) {
        reviewRedirect($productId, 'product_unavailable');
    }
    $purchaseStmt = $db->prepare("
        SELECT 1
        FROM orders o
        INNER JOIN order_items oi
            ON oi.order_id = o.id
        WHERE o.user_id = :user_id
          AND oi.product_id = :product_id
          AND (
              (
                  o.payment_method = 'cash_on_delivery'
                  AND o.order_status = 'confirmed'
                  AND o.payment_status IN ('pending', 'paid')
              )
              OR
              (
                  o.payment_status = 'paid'
                  AND o.order_status <> 'cancelled'
              )
          )
        LIMIT 1
    ");

    $purchaseStmt->execute([
        ':user_id' => $userId,
        ':product_id' => $productId
    ]);

    if (!$purchaseStmt->fetchColumn()) {
        reviewRedirect($productId, 'purchase_required');
    }
    $reviewStmt = $db->prepare("
        INSERT INTO product_reviews (
            product_id,
            user_id,
            rating,
            review_title,
            review_text,
            is_verified_purchase,
            status
        ) VALUES (
            :product_id,
            :user_id,
            :rating,
            :review_title,
            :review_text,
            1,
            'published'
        )
        ON DUPLICATE KEY UPDATE
            rating = VALUES(rating),
            review_title = VALUES(review_title),
            review_text = VALUES(review_text),
            is_verified_purchase = 1,
            status = 'published',
            updated_at = CURRENT_TIMESTAMP
    ");

    $reviewStmt->execute([
        ':product_id' => $productId,
        ':user_id' => $userId,
        ':rating' => $rating,
        ':review_title' => $title !== '' ? $title : null,
        ':review_text' => $reviewText
    ]);

    reviewRedirect($productId, 'review_saved');

} catch (PDOException $e) {
    error_log('Product review database error: ' . $e->getMessage());
    reviewRedirect($productId, 'save_failed');
}
