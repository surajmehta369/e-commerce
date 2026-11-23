
<?php

require_once __DIR__ . "/connection/dbconnect.php";
require_once __DIR__ . "/admin/courier/packing_functions.php";

$database = new Database();
$db = $database->connect();

try {

    $result = assignCourierAndCreateShipment($db, 62);

    echo "<h3>Order #62 - Courier Assignment Test</h3>";

    echo "<pre>";
    print_r($result);
    echo "</pre>";

} catch (Throwable $e) {

    echo "Error: " . htmlspecialchars($e->getMessage());
}
