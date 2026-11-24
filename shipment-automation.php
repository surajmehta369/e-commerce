<?php

if (!defined('SHIPMENT_AUTOMATION_HTTP')) {
    http_response_code(403);
    exit('Access denied. Use the authorized automation endpoint.');
}

set_time_limit(0);

$logDirectory = __DIR__ . '/logs';

if (
    !is_dir($logDirectory) &&
    !mkdir($logDirectory, 0755, true) &&
    !is_dir($logDirectory)
) {
    exit('Unable to create automation log directory.');
}

$logFile = $logDirectory . '/shipment-automation.log';
$lockFile = $logDirectory . '/shipment-automation.lock';

ini_set('log_errors', '1');
ini_set('error_log', $logFile);

function shipmentLog(string $message): void
{
    global $logFile;

    $logMessage =
        '[' . date('Y-m-d H:i:s') . '] ' .
        $message .
        PHP_EOL;

    file_put_contents(
        $logFile,
        $logMessage,
        FILE_APPEND | LOCK_EX
    );

    echo $logMessage;
}


$lockHandle = fopen($lockFile, 'c');

if ($lockHandle === false) {
    shipmentLog('Unable to open automation lock file.');
    exit;
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fclose($lockHandle);

    shipmentLog(
        'Another shipment automation process is running. Skipping.'
    );

    exit;
}

register_shutdown_function(function () use ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});


shipmentLog('Shipment automation started.');

shipmentLog(
    'Automatic shipment creation and status progression are disabled.'
);

shipmentLog(
    'Courier assignment is handled by the admin order-status workflow.'
);

shipmentLog(
    'Pickup, Out for Delivery and Delivered require admin confirmation.'
);

shipmentLog('No automatic shipment status changes were performed.');

shipmentLog('Shipment automation completed.');
