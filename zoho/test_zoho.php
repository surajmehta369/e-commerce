<?php

require_once __DIR__ . '/zoho_functions.php';


$result = getZohoItemDetailsById('4096516000000196001');

echo '<pre>';
print_r($result['item'] ?? []);
echo '</pre>';
