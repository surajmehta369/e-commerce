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

function getZohoPrimaryLocation()
{
    $response = zohoInventoryApi(
        'GET',
        'locations',
        [
            'organization_id' =>
                ZOHO_ORGANIZATION_ID
        ]
    );

    if (empty($response['success'])) {

        $zohoMessage =
            $response['response']['message']
            ?? '';

        $zohoCode =
            $response['response']['code']
            ?? '';

        $message =
            'Unable to retrieve Zoho Inventory locations.';

        if ($zohoMessage !== '') {

            $message .=
                ' Zoho: ' .
                $zohoMessage;
        }

        if ($zohoCode !== '') {

            $message .=
                ' (Code: ' .
                $zohoCode .
                ')';
        }

        return [
            'success' => false,

            'location_id' => null,

            'message' => $message,

            'response' => $response
        ];
    }

    $locations =
        $response['response']['locations']
        ?? [];

    if (empty($locations)) {

        return [
            'success' => false,

            'location_id' => null,

            'message' =>
                'No Zoho Inventory locations were found.',

            'response' => $response
        ];
    }

    /*
     * First preference:
     * Primary active location.
     */
    foreach ($locations as $location) {

        $locationId =
            trim(
                (string) (
                    $location['location_id']
                    ?? ''
                )
            );

        $isPrimary =
            !empty($location['is_primary']);

        $status =
            strtolower(
                trim(
                    (string) (
                        $location['status']
                        ?? ''
                    )
                )
            );

        if (
            $locationId !== '' &&
            $isPrimary &&
            (
                $status === '' ||
                $status === 'active'
            )
        ) {

            return [
                'success' => true,

                'location_id' =>
                    $locationId,

                'location' =>
                    $location
            ];
        }
    }

    /*
     * Fallback:
     * First active location.
     */
    foreach ($locations as $location) {

        $locationId =
            trim(
                (string) (
                    $location['location_id']
                    ?? ''
                )
            );

        $status =
            strtolower(
                trim(
                    (string) (
                        $location['status']
                        ?? ''
                    )
                )
            );

        if (
            $locationId !== '' &&
            (
                $status === '' ||
                $status === 'active'
            )
        ) {

            return [
                'success' => true,

                'location_id' =>
                    $locationId,

                'location' =>
                    $location
            ];
        }
    }

    return [
        'success' => false,

        'location_id' => null,

        'message' =>
            'No active Zoho Inventory location was found.',

        'response' =>
            $response
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
    'sku' => $sku,
    'item_type' => 'inventory',
    'product_type' => 'goods',
    'can_be_sold' => true,
    'can_be_purchased' => true,
    'track_inventory' => true
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

if (isset($product['stock'])) {

    $stock =
        (float) $product['stock'];

    if ($stock < 0) {
        $stock = 0;
    }

    $locationResult =
        getZohoPrimaryLocation();

    if (empty($locationResult['success'])) {

        return [
            'success' => false,

            'message' =>
                $locationResult['message']
                ?? 'Unable to determine Zoho Inventory location.',

            'item' => null,

            'response' =>
                $locationResult
        ];
    }

    $locationId =
        $locationResult['location_id'];

    $initialStockRate =
        isset($product['purchase_rate']) &&
        (float) $product['purchase_rate'] > 0
            ? (float) $product['purchase_rate']
            : (float) ($product['rate'] ?? 0);

    $itemData['locations'] = [
        [
            'location_id' =>
                $locationId,

            'initial_stock' =>
                $stock,

            'initial_stock_rate' =>
                $initialStockRate
        ]
    ];
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

function adjustZohoItemStock(
    string $itemId,
    string $itemName,
    float $newStock
) {
    $itemId = trim($itemId);
    $itemName = trim($itemName);

    if ($itemId === '') {
        return [
            'success' => false,
            'message' => 'Zoho item ID is required.',
            'response' => null
        ];
    }

    if ($itemName === '') {
        $itemName = 'Product';
    }

    if ($newStock < 0) {
        $newStock = 0;
    }

    /*
     * Get current Zoho stock.
     */
    $itemResponse = zohoInventoryApi(
        'GET',
        'items/' . rawurlencode($itemId),
        [
            'organization_id' =>
                ZOHO_ORGANIZATION_ID
        ]
    );

    if (empty($itemResponse['success'])) {

        return [
            'success' => false,

            'message' =>
                'Unable to retrieve current Zoho item stock.',

            'response' =>
                $itemResponse
        ];
    }

    $zohoItem =
        $itemResponse['response']['item']
        ?? null;

    if (!$zohoItem) {

        return [
            'success' => false,

            'message' =>
                'Zoho item details were not returned.',

            'response' =>
                $itemResponse
        ];
    }

    $currentStock =
        isset($zohoItem['stock_on_hand'])
            ? (float) $zohoItem['stock_on_hand']
            : 0;

    /*
     * Calculate the required adjustment.
     *
     * Example:
     *
     * Zoho = 10
     * Website = 15
     *
     * Difference = +5
     *
     * Zoho = 15
     * Website = 7
     *
     * Difference = -8
     */
    $quantityAdjusted =
        $newStock - $currentStock;

    /*
     * Nothing to change.
     */
    if (abs($quantityAdjusted) < 0.000001) {

        return [
            'success' => true,

            'message' =>
                'Zoho stock is already up to date.',

            'current_stock' =>
                $currentStock,

            'new_stock' =>
                $newStock,

            'adjustment' =>
                0,

            'response' => null
        ];
    }

    /*
     * Get the primary Zoho location.
     */
    $locationResult =
        getZohoPrimaryLocation();

    if (empty($locationResult['success'])) {

        return [
            'success' => false,

            'message' =>
                $locationResult['message']
                ?? 'Unable to determine Zoho Inventory location.',

            'response' =>
                $locationResult
        ];
    }

    $locationId =
        $locationResult['location_id'];

    /*
     * Zoho Inventory Adjustment.
     *
     * Positive value:
     * increase stock.
     *
     * Negative value:
     * decrease stock.
     */
    $adjustmentData = [
        'date' =>
            date('Y-m-d'),

        'reason' =>
            'Stock synchronization',

        'description' =>
            'Stock synchronized from website product.',

        'reference_number' =>
            'WEB-' . $itemId . '-' . time(),

        'adjustment_type' =>
            'quantity',

        'location_id' =>
            $locationId,

        'line_items' => [
            [
                'item_id' =>
                    $itemId,

                'name' =>
                    $itemName,

                'quantity_adjusted' =>
                    $quantityAdjusted,

                'location_id' =>
                    $locationId
            ]
        ]
    ];

    $response =
        zohoInventoryApi(
            'POST',
            'inventoryadjustments',
            [
                'organization_id' =>
                    ZOHO_ORGANIZATION_ID
            ],
            $adjustmentData
        );

    if (empty($response['success'])) {

        return [
            'success' => false,

            'message' =>
                'Zoho inventory adjustment failed.',

            'current_stock' =>
                $currentStock,

            'new_stock' =>
                $newStock,

            'adjustment' =>
                $quantityAdjusted,

            'response' =>
                $response
        ];
    }

    return [
        'success' => true,

        'message' =>
            'Zoho stock adjusted successfully.',

        'current_stock' =>
            $currentStock,

        'new_stock' =>
            $newStock,

        'adjustment' =>
            $quantityAdjusted,

        'response' =>
            $response
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

if (empty($result['success'])) {

    return [
        'success' => false,

        'action' => 'update',

        'message' =>
            $result['message'] ??
            'Zoho item update failed.',

        'item' =>
            $result['item'] ?? null,

        'response' =>
            $result
    ];
}

/*
 * Sync Stock on Hand separately.
 */
if (isset($product['stock'])) {

    $stockResult =
        adjustZohoItemStock(
            $itemId,

            $product['name']
                ?? $existing['item']['name']
                ?? 'Product',

            (float) $product['stock']
        );

    if (empty($stockResult['success'])) {

        return [
            'success' => false,

            'action' => 'update',

            'message' =>
                'Zoho item details updated, but stock synchronization failed: ' .
                (
                    $stockResult['message']
                    ?? 'Unknown stock synchronization error.'
                ),

            'item' =>
                $result['item'] ?? null,

            'response' => [
                'item_update' =>
                    $result,

                'stock_update' =>
                    $stockResult
            ]
        ];
    }
}

return [
    'success' => true,

    'action' => 'update',

    'message' =>
        'Zoho item and stock updated successfully.',

    'item' =>
        $result['item'] ?? null,

    'response' => [
        'item_update' =>
            $result,

        'stock_update' =>
            $stockResult ?? null
    ]
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
