<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: application/json; charset=utf-8');

require_once "../connection/dbconnect.php";

require_once "../search/SearchQuery.php";
require_once "../search/SearchMatcher.php";
require_once "../search/SearchRelevance.php";
require_once "../search/SearchEngine.php";


try {

    // Connect to database
    $database = new Database();
    $db = $database->connect();


    // Create search components
    $matcher = new SearchMatcher($db);

    $scorer = new RelevanceScorer();

    $engine = new SearchEngine(
        $matcher,
        $scorer
    );


    // Get search query
    $query = isset($_GET['q'])
        ? trim($_GET['q'])
        : '';


    // Empty search
    if ($query === '') {

        echo json_encode([
            "success" => true,
            "count" => 0,
            "products" => []
        ]);

        exit;
    }


    // Perform search
    $products = $engine->search(
        $query,
        50
    );

    $searchQuery = new SearchQuery($query);

$words = $searchQuery->getWords();



echo json_encode([
    "success" => true,
    "query" => $searchQuery->getQuery(),
    "words" => $words,
    "count" => count($products),
    "products" => $products
]);


} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Search failed."
    ]);

    exit;
}
