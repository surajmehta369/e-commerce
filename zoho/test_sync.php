
<?php
require_once "../connection/dbconnect.php";
require_once __DIR__ . '/zoho_functions.php';

header('Content-Type: application/json');

$result = getZohoItemDetailsById('4096516000000192002');

echo '<pre>';
print_r($result);
echo '</pre>';