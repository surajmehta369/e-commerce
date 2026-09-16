<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: application/json; charset=utf-8');

require_once "../connection/dbconnect.php";

try {

    $database = new Database();
    $db = $database->connect();

    $categoryId = isset($_GET['category_id'])
        ? trim($_GET['category_id'])
        : '';

    $brandId = isset($_GET['brand_id'])
        ? trim($_GET['brand_id'])
        : '';

    $minPrice = isset($_GET['min_price'])
        ? trim($_GET['min_price'])
        : '';

    $maxPrice = isset($_GET['max_price'])
        ? trim($_GET['max_price'])
        : '';

    $minDiscount = isset($_GET['min_discount'])
        ? trim($_GET['min_discount'])
        : '';

    $sort = isset($_GET['sort'])
        ? trim($_GET['sort'])
        : 'relevance';

    $excludeId = isset($_GET['exclude_id'])
        ? trim($_GET['exclude_id'])
        : '';
    $page = isset($_GET['page'])
        ? (int) $_GET['page']
        : 1;

    $limit = isset($_GET['limit'])
        ? (int) $_GET['limit']
        : 12;

    if ($page < 1) {
        $page = 1;
    }

    if ($limit < 1) {
        $limit = 12;
    }

    if ($limit > 100) {
        $limit = 100;
    }

    $offset = ($page - 1) * $limit;
    $where = [
        "status = 1"
    ];

    $params = [];

    if ($categoryId !== '' && ctype_digit($categoryId)) {

        $where[] = "category_id = :category_id";

        $params[':category_id'] = (int) $categoryId;
    }


    if ($brandId !== '' && ctype_digit($brandId)) {

        $where[] = "brand_id = :brand_id";

        $params[':brand_id'] = (int) $brandId;
    }

    if ($excludeId !== '' && ctype_digit($excludeId)) {

        $where[] = "id != :exclude_id";

        $params[':exclude_id'] = (int) $excludeId;
    }



    if ($minPrice !== '' && is_numeric($minPrice)) {

        $where[] = "price >= :min_price";

        $params[':min_price'] = (float) $minPrice;
    }

    if ($maxPrice !== '' && is_numeric($maxPrice)) {

        $where[] = "price <= :max_price";

        $params[':max_price'] = (float) $maxPrice;
    }

    if ($minDiscount !== '' && is_numeric($minDiscount)) {

        $where[] = "discount >= :min_discount";

        $params[':min_discount'] = (float) $minDiscount;
    }

    $whereSql = implode(" AND ", $where);
    switch ($sort) {

        case 'price_low':
            $orderBy = "price ASC";
            break;

        case 'price_high':
            $orderBy = "price DESC";
            break;

        case 'discount':
            $orderBy = "discount DESC";
            break;

        case 'newest':
            $orderBy = "created_at DESC";
            break;

        case 'oldest':
            $orderBy = "created_at ASC";
            break;

        case 'relevance':
        default:
            $orderBy = "id DESC";
            break;
    }
    $countSql = "
        SELECT COUNT(*) 
        FROM products
        WHERE $whereSql
    ";

    $countStmt = $db->prepare($countSql);

    foreach ($params as $key => $value) {

        if (is_int($value)) {

            $countStmt->bindValue(
                $key,
                $value,
                PDO::PARAM_INT
            );
        } else {

            $countStmt->bindValue(
                $key,
                $value
            );
        }
    }

    $countStmt->execute();

    $totalProducts = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT
            id,
            sku,
            title,
            slug,
            description,
            image,
            price,
            original_price,
            discount,
            stock,
            category_id,
            brand_id,
            attributes,
            created_at,
            updated_at
        FROM products
        WHERE $whereSql
        ORDER BY $orderBy
        LIMIT :limit
        OFFSET :offset
    ";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {

        if (is_int($value)) {

            $stmt->bindValue(
                $key,
                $value,
                PDO::PARAM_INT
            );
        } else {

            $stmt->bindValue(
                $key,
                $value
            );
        }
    }

    $stmt->bindValue(
        ':limit',
        $limit,
        PDO::PARAM_INT
    );

    $stmt->bindValue(
        ':offset',
        $offset,
        PDO::PARAM_INT
    );


    $stmt->execute();

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalPages = $totalProducts > 0
        ? (int) ceil($totalProducts / $limit)
        : 0;

    echo json_encode([

        "success" => true,

        "filters" => [
            "category_id" => $categoryId,
            "brand_id" => $brandId,
            "exclude_id" => $excludeId,
            "min_price" => $minPrice,
            "max_price" => $maxPrice,
            "min_discount" => $minDiscount,
            "sort" => $sort
        ],

        "pagination" => [

            "page" => $page,

            "limit" => $limit,

            "total_products" => $totalProducts,

            "total_pages" => $totalPages,

            "has_next_page" =>
            $page < $totalPages,

            "has_previous_page" =>
            $page > 1

        ],

        "count" => count($products),

        "products" => $products

    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "message" => "Product filter failed.",

        "error" => $e->getMessage()

    ]);
}
