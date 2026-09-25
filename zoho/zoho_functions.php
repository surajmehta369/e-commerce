<?php

require_once __DIR__ . '/zoho_keys.php';

function generateZohoAccessToken()
{
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => ZOHO_TOKEN_URL,

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS => http_build_query([
            'refresh_token' => ZOHO_REFRESH_TOKEN,

            'client_id' =>
                ZOHO_CLIENT_ID,

            'client_secret' =>
                ZOHO_CLIENT_SECRET,

            'grant_type' =>
                'refresh_token'
        ]),

        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',

            'Accept' => 'application/json'
        ],

        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($curl);

    if ($response === false) {

        $error = curl_error($curl);

        curl_close($curl);

        throw new Exception(
            'Zoho token request failed: ' .
            $error
        );
    }

    $httpCode =
        curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );

    curl_close($curl);


    $data =
        json_decode(
            $response,
            true
        );


    if (!is_array($data)) {

        throw new Exception(
            'Invalid Zoho token response: ' .
            $response
        );
    }


    if (
        $httpCode < 200 ||
        $httpCode >= 300
    ) {

        throw new Exception(
            'Zoho token API returned HTTP ' .
            $httpCode .
            ': ' .
            $response
        );
    }


    if (
        empty($data['access_token'])
    ) {

        throw new Exception(
            'Zoho access token was not returned: ' .
            $response
        );
    }
    $expiresIn =
        isset($data['expires_in'])
            ? (int) $data['expires_in']
            : 3600;


    $data['expires_at'] =
        time() + $expiresIn;
    $json =
        json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES
        );


    if (
        file_put_contents(
            ZOHO_ACCESS_TOKEN_FILE,
            $json,
            LOCK_EX
        ) === false
    ) {

        throw new Exception(
            'Unable to save Zoho access token.'
        );
    }


    return $data['access_token'];
}

function getZohoAccessToken()
{
    if (
        file_exists(
            ZOHO_ACCESS_TOKEN_FILE
        )
    ) {

        $json =
            file_get_contents(
                ZOHO_ACCESS_TOKEN_FILE
            );


        $data =
            json_decode(
                $json,
                true
            );


        if (
            !empty($data['access_token']) &&
            !empty($data['expires_at'])
        ) {
            if (
                time() <
                ((int) $data['expires_at'] - 300)
            ) {

                return $data['access_token'];
            }
        }
    }


    return generateZohoAccessToken();
}
function zohoInventoryApi(
    string $method,
    string $endpoint,
    array $query = [],
    $body = null
) {

    $accessToken =
        getZohoAccessToken();


    /*
     * Build URL.
     */
    $url =
        rtrim(
            ZOHO_API_DOMAIN,
            '/'
        ) .
        '/inventory/v1/' .
        ltrim(
            $endpoint,
            '/'
        );


    if (!empty($query)) {

        $url .= '?' .
            http_build_query($query);
    }


    $curl =
        curl_init($url);


    $headers = [
        'Authorization: Zoho-oauthtoken ' .
            $accessToken,

        'Accept: application/json'
    ];


    $options = [
        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_CUSTOMREQUEST =>
            strtoupper($method),

        CURLOPT_HTTPHEADER =>
            $headers,

        CURLOPT_TIMEOUT => 30
    ];


    if ($body !== null) {

        $headers[] =
            'Content-Type: application/json';


        $options[CURLOPT_HTTPHEADER] =
            $headers;


        $options[CURLOPT_POSTFIELDS] =
            json_encode($body);
    }


    curl_setopt_array(
        $curl,
        $options
    );


    $response =
        curl_exec($curl);


    if ($response === false) {

        $error =
            curl_error($curl);

        curl_close($curl);

        return [
            'http_code' => 0,

            'success' => false,

            'response' => [],

            'errors' => [
                [
                    'message' =>
                        'Zoho cURL error: ' .
                        $error
                ]
            ]
        ];
    }


    $httpCode =
        curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );


    curl_close($curl);


    $data =
        json_decode(
            $response,
            true
        );


    if (!is_array($data)) {

        return [
            'http_code' =>
                $httpCode,

            'success' =>
                false,

            'response' => [],

            'errors' => [
                [
                    'message' =>
                        'Invalid Zoho JSON response',

                    'raw_response' =>
                        $response
                ]
            ]
        ];
    }


    return [
        'http_code' =>
            $httpCode,

        'success' =>
            (
                $httpCode >= 200 &&
                $httpCode < 300
            ),

        'response' =>
            $data,

        'errors' =>
            []
    ];
}
function findZohoItemBySKU(string $sku)
{
    $sku = trim($sku);

    if ($sku === '') {
        return [
            'success' => false,
            'found' => false,
            'message' => 'SKU is required.',
            'item' => null
        ];
    }

    $response = zohoInventoryApi(
        'GET',
        'items',
        [
            'organization_id' => ZOHO_ORGANIZATION_ID,
            'sku' => $sku
        ]
    );

    if (empty($response['success'])) {
        return [
            'success' => false,
            'found' => false,
            'message' => 'Zoho item lookup failed.',
            'item' => null,
            'response' => $response
        ];
    }

    $items =
        $response['response']['items'] ?? [];

    foreach ($items as $item) {

        $itemSku =
            trim($item['sku'] ?? '');

        if (
            $itemSku !== '' &&
            strcasecmp($itemSku, $sku) === 0
        ) {
            return [
                'success' => true,
                'found' => true,
                'message' => 'Zoho item found by SKU.',
                'item' => $item
            ];
        }
    }

    return [
        'success' => true,
        'found' => false,
        'message' => 'No Zoho item found with this SKU.',
        'item' => null
    ];
}
function createZohoItem(array $product)
{
    $name = trim($product['name'] ?? '');
    $sku  = trim($product['sku'] ?? '');

    if ($name === '') {
        return [
            'success' => false,
            'message' => 'Product name is required.',
            'item' => null
        ];
    }

    if ($sku === '') {
        return [
            'success' => false,
            'message' => 'Product SKU is required.',
            'item' => null
        ];
    }
    $existing = findZohoItemBySKU($sku);

    if (
        !empty($existing['success']) &&
        !empty($existing['found'])
    ) {
        return [
            'success' => false,
            'message' => 'A Zoho item with this SKU already exists.',
            'item' => $existing['item']
        ];
    }
    $itemData = [
        'name' => $name,
        'sku' => $sku
    ];

    if (isset($product['description'])) {
        $itemData['description'] =
            (string) $product['description'];
    }

    if (isset($product['rate'])) {
        $itemData['rate'] =
            (float) $product['rate'];
    }

    if (isset($product['purchase_rate'])) {
        $itemData['purchase_rate'] =
            (float) $product['purchase_rate'];
    }

    $response = zohoInventoryApi(
        'POST',
        'items',
        [
            'organization_id' =>
                ZOHO_ORGANIZATION_ID
        ],
        $itemData
    );

    if (empty($response['success'])) {
        return [
            'success' => false,
            'message' => 'Zoho item creation failed.',
            'item' => null,
            'response' => $response
        ];
    }

    $item =
        $response['response']['item'] ?? null;

    if (!$item) {
        return [
            'success' => false,
            'message' =>
                'Zoho did not return the created item.',
            'item' => null,
            'response' => $response
        ];
    }

    return [
        'success' => true,
        'message' => 'Zoho item created successfully.',
        'item' => $item
    ];
}

function updateZohoItem(string $itemId, array $product)
{
    $itemId = trim($itemId);

    if ($itemId === '') {
        return [
            'success' => false,
            'message' => 'Zoho item ID is required.',
            'item' => null
        ];
    }

    $name = trim($product['name'] ?? '');
    $sku  = trim($product['sku'] ?? '');

    if ($name === '') {
        return [
            'success' => false,
            'message' => 'Product name is required.',
            'item' => null
        ];
    }

    if ($sku === '') {
        return [
            'success' => false,
            'message' => 'Product SKU is required.',
            'item' => null
        ];
    }

    $itemData = [
        'name' => $name,
        'sku'  => $sku
    ];

    if (isset($product['description'])) {
        $itemData['description'] =
            (string) $product['description'];
    }

    if (isset($product['rate'])) {
        $itemData['rate'] =
            (float) $product['rate'];
    }

    if (isset($product['purchase_rate'])) {
        $itemData['purchase_rate'] =
            (float) $product['purchase_rate'];
    }

    $response = zohoInventoryApi(
        'PUT',
        'items/' . rawurlencode($itemId),
        [
            'organization_id' =>
                ZOHO_ORGANIZATION_ID
        ],
        $itemData
    );

    if (empty($response['success'])) {
        return [
            'success' => false,
            'message' => 'Zoho item update failed.',
            'item' => null,
            'response' => $response
        ];
    }

    $item =
        $response['response']['item'] ?? null;

    if (!$item) {
        return [
            'success' => false,
            'message' =>
                'Zoho did not return the updated item.',
            'item' => null,
            'response' => $response
        ];
    }

    return [
        'success' => true,
        'message' => 'Zoho item updated successfully.',
        'item' => $item
    ];
}

function syncProductToZoho(array $product)
{
    $sku = trim($product['sku'] ?? '');

    if ($sku === '') {
        return [
            'success' => false,
            'action' => null,
            'message' => 'Product SKU is required for synchronization.',
            'item' => null
        ];
    }
    $existing = findZohoItemBySKU($sku);

    if (empty($existing['success'])) {
        return [
            'success' => false,
            'action' => null,
            'message' =>
                $existing['message'] ??
                'Unable to search Zoho item.',
            'item' => null,
            'response' => $existing
        ];
    }
    if (!empty($existing['found'])) {

        $itemId =
            $existing['item']['item_id'] ?? '';

        if ($itemId === '') {
            return [
                'success' => false,
                'action' => 'update',
                'message' =>
                    'Zoho item was found, but item_id is missing.',
                'item' => null
            ];
        }

        $result =
            updateZohoItem(
                $itemId,
                $product
            );

        return [
            'success' =>
                !empty($result['success']),

            'action' =>
                'update',

            'message' =>
                $result['message'] ??
                'Zoho item update completed.',

            'item' =>
                $result['item'] ?? null,

            'response' =>
                $result
        ];
    }
    $result =
        createZohoItem($product);

    return [
        'success' =>
            !empty($result['success']),

        'action' =>
            'create',

        'message' =>
            $result['message'] ??
            'Zoho item creation completed.',

        'item' =>
            $result['item'] ?? null,

        'response' =>
            $result
    ];
}
