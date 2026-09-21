<?php

require_once __DIR__ . '../shopify/functions.php';

$shopifyProductId = 'gid://shopify/Product/9525213167854';

$result = updateShopifyInventory(
    $shopifyProductId,
    15
);

echo '<pre>';
print_r($result);
echo '</pre>';