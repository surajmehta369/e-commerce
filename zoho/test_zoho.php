<?php

require_once __DIR__ . '/zoho_functions.php';

$product = [
    'name' =>
        'API Sync Testing',

    'sku' =>
        'API-SYNC-NEW-002',

    'description' =>
        'Testing product creation.',

    'rate' =>
        3000
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
