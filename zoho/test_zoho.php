<?php

require_once __DIR__ . '/zoho_functions.php';

$product = [
    'name' =>
        'API Sync New Product',

    'sku' =>
        'API-SYNC-NEW-001',

    'description' =>
        'Testing automatic creation.',

    'rate' =>
        300
];


try {

    $result =
        syncProductToZoho($product);

    echo '<pre>';

    print_r($result);

    echo '</pre>';

} catch (Exception $e) {

    echo '<pre>';

    echo 'FAILED: ' .
        $e->getMessage();

    echo '</pre>';
}
