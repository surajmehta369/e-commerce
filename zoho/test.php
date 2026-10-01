<?php

$webhookJsonUrl =
    'https://baseavangers.topscripts.in/sumit_rana/offline/zoho_latest_webhook.json';

$jsonResponse = file_get_contents($webhookJsonUrl);

if ($jsonResponse === false) {
    die('Unable to fetch Zoho webhook JSON.');
}

echo '<h3>Raw JSON</h3>';

echo '<pre>';
echo htmlspecialchars($jsonResponse);
echo '</pre>';


$data = json_decode($jsonResponse, true);

if (!is_array($data)) {
    die('Invalid JSON received from server.');
}

echo '<h3>Decoded Data</h3>';

echo '<pre>';
print_r($data);
echo '</pre>';
