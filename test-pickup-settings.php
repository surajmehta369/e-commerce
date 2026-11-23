
<?php

require_once __DIR__ . "/connection/dbconnect.php";
require_once __DIR__ . "/admin/courier/packing_functions.php";

$database = new Database();
$db = $database->connect();

try {
    $packingPlan = packOrderIntoMultipleBoxes($db, 62);
    $result = saveOrderPackages(
        $db,
        $packingPlan,
        false
    );

    echo "<pre>";
    print_r($result);
    echo "</pre>";

} catch (Throwable $e) {

    echo "Error: " .
        htmlspecialchars($e->getMessage());
}
