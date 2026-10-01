<?php
require_once "../connection/dbconnect.php";

require_once __DIR__ . '/zoho_functions.php';

$result = syncZohoWebhookItemToShopify();

echo '<pre>';
print_r($result);
echo '</pre>';
