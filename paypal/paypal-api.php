<?php

require_once __DIR__ . '/../config/paypal.php';

function getPayPalAccessToken()
{
    $url = PAYPAL_BASE_URL . '/v1/oauth2/token';

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);

    curl_setopt($ch, CURLOPT_USERPWD,
        PAYPAL_CLIENT_ID . ':' . PAYPAL_CLIENT_SECRET
    );

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Accept-Language: en_US',
        'Content-Type: application/x-www-form-urlencoded'
    ]);

curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'grant_type' => 'client_credentials'
]));

    $response = curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        $error = curl_error($ch);
        throw new Exception(
            'PayPal connection error: ' . $error
        );
    }

    $data = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception(
            'PayPal authentication failed: ' . $response
        );
    }

    if (!isset($data['access_token'])) {
        throw new Exception(
            'PayPal access token was not returned.'
        );
    }

    return $data['access_token'];
}

function paypalRequest(string $method, string $endpoint, $data = null)
{
    $accessToken = getPayPalAccessToken();

    $url = PAYPAL_BASE_URL . $endpoint;

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ];
    if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
        $headers[] = 'PayPal-Request-Id: ' . uniqid('ECOM_', true);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($data !== null) {
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode($data)
        );
    }

    $response = curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        $error = curl_error($ch);
        throw new Exception(
            'PayPal API connection error: ' . $error
        );
    }

    $decodedResponse = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception(
            'PayPal API error: ' . $response
        );
    }

    return $decodedResponse;
}