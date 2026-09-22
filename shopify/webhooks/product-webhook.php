<?php

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');

$data = json_decode($rawBody, true);

echo json_encode([
    'success' => true,
    'message' => 'Webhook endpoint received the request.',
    'data' => $data
]);