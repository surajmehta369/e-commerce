
<?php
require_once "../connection/dbconnect.php";
require_once __DIR__ . '/zoho_functions.php';

header('Content-Type: application/json');

$groupId = '4096516000000196004';

$result = zohoInventoryApi(
    'GET',
    'itemgroups/' . rawurlencode($groupId),
    [
        'organization_id' => ZOHO_ORGANIZATION_ID
    ]
);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
);