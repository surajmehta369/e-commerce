<?php

require_once __DIR__ . '/zoho_functions.php';


$result = findZohoItemBySKU('TEST-001');

echo '<pre>';
print_r($result);
echo '</pre>';
