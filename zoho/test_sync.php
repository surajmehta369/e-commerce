
<?php

require_once "../connection/dbconnect.php";
require_once __DIR__ . '/zoho_functions.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    // Step 1: Synchronize Zoho data to the local database.
    $localResult = syncZohoWebhookToDatabase();

    // Step 2: Synchronize the same Zoho event to Shopify.
    $shopifyResult = syncZohoWebhookItemToShopify();

    $result = [
        'local_sync' => $localResult,
        'shopify_sync' => $shopifyResult,
    ];

    echo "ZOHO SYNCHRONIZATION RESULTS\n";
    echo "============================\n\n";

    echo "LOCAL DATABASE:\n";
    print_r($localResult);

    echo "\nSHOPIFY:\n";
    print_r($shopifyResult);

} catch (Throwable $e) {
    http_response_code(500);

    echo "Synchronization error:\n";
    echo $e->getMessage();
}