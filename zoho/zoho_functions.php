<?php

require_once __DIR__ . '/zoho_keys.php';
require_once "../shopify/functions.php";


$database = new Database();
$db = $database->connect();

function generateZohoAccessToken()
{
    $curl = curl_init();

    if ($curl === false) {
        throw new Exception('Unable to initialize cURL for Zoho token request.');
    }

    curl_setopt_array($curl, [
        CURLOPT_URL => ZOHO_TOKEN_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'refresh_token' => ZOHO_REFRESH_TOKEN,
            'client_id' => ZOHO_CLIENT_ID,
            'client_secret' => ZOHO_CLIENT_SECRET,
            'grant_type' => 'refresh_token'
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($curl);

    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);

        throw new Exception('Zoho token request failed: ' . $error);
    }

    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new Exception('Invalid Zoho token response: ' . $response);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception(
            'Zoho token API returned HTTP ' . $httpCode . ': ' . $response
        );
    }

    if (empty($data['access_token'])) {
        throw new Exception(
            'Zoho access token was not returned: ' . $response
        );
    }

    $expiresIn = isset($data['expires_in'])
        ? (int) $data['expires_in']
        : 3600;

    $data['expires_at'] = time() + $expiresIn;

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new Exception('Unable to encode Zoho token response.');
    }

    $tokenDirectory = dirname(ZOHO_ACCESS_TOKEN_FILE);

    if (!is_dir($tokenDirectory) || !is_writable($tokenDirectory)) {
        throw new Exception('Zoho token directory does not exist or is not writable.');
    }

    if (file_put_contents(ZOHO_ACCESS_TOKEN_FILE, $json, LOCK_EX) === false) {
        throw new Exception('Unable to save Zoho access token.');
    }

    return $data['access_token'];
}

function getZohoAccessToken()
{
    if (file_exists(ZOHO_ACCESS_TOKEN_FILE)) {

        $json = @file_get_contents(ZOHO_ACCESS_TOKEN_FILE);

        if ($json !== false && $json !== '') {

            $data = json_decode($json, true);

            if (
                is_array($data) &&
                !empty($data['access_token']) &&
                !empty($data['expires_at'])
            ) {
                if (time() < ((int) $data['expires_at'] - 300)) {
                    return $data['access_token'];
                }
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
): array {

    // 1. Get Zoho access token.

    $method = strtoupper(trim($method));

    if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
        return [
            'http_code' => 0,
            'success' => false,
            'response' => [],
            'errors' => [[
                'message' => 'Invalid HTTP method: ' . $method
            ]]
        ];
    }
    try {
        $accessToken = getZohoAccessToken();
    } catch (Throwable $e) {
        return [
            'http_code' => 0,
            'success' => false,
            'response' => [],
            'errors' => [[
                'message' => 'Unable to obtain Zoho access token: ' . $e->getMessage()
            ]]
        ];
    }

    if (!is_string($accessToken) || trim($accessToken) === '') {
        return [
            'http_code' => 0,
            'success' => false,
            'response' => [],
            'errors' => [[
                'message' => 'Zoho access token is missing.'
            ]]
        ];
    }

    // 2. Build API URL.
    $url = rtrim(ZOHO_API_DOMAIN, '/') .
        '/inventory/v1/' .
        ltrim($endpoint, '/');

    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    // 3. Prepare request headers.
    $headers = [
        'Authorization: Zoho-oauthtoken ' . $accessToken,
        'Accept: application/json'
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ];

    // 4. Encode request body when supplied.
    if ($body !== null) {
        $jsonBody = json_encode($body);

        if ($jsonBody === false) {
            return [
                'http_code' => 0,
                'success' => false,
                'response' => [],
                'errors' => [[
                    'message' => 'Could not encode request JSON: ' .
                        json_last_error_msg()
                ]]
            ];
        }

        $headers[] = 'Content-Type: application/json';

        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = $jsonBody;
    }

    // 5. Execute request.
    $curl = curl_init($url);

    if ($curl === false) {
        return [
            'http_code' => 0,
            'success' => false,
            'response' => [],
            'errors' => [[
                'message' => 'Unable to initialize Zoho cURL request.'
            ]]
        ];
    }

    curl_setopt_array($curl, $options);

    $rawResponse = curl_exec($curl);
    $curlError = curl_error($curl);

    $httpCode = (int) curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );

    curl_close($curl);

    // 6. Handle cURL errors.
    if ($rawResponse === false) {
        return [
            'http_code' => $httpCode,
            'success' => false,
            'response' => [],
            'errors' => [[
                'message' => 'Zoho cURL error: ' . $curlError
            ]]
        ];
    }

    // 7. Decode Zoho response.
    $data = json_decode($rawResponse, true);

    if (!is_array($data)) {
        return [
            'http_code' => $httpCode,
            'success' => false,
            'response' => [],
            'errors' => [[
                'message' => 'Invalid Zoho JSON response: ' .
                    json_last_error_msg(),
                'raw_response' => $rawResponse
            ]]
        ];
    }

    // 8. Validate HTTP response.
    $httpSuccess = (
        $httpCode >= 200 &&
        $httpCode < 300
    );

    // 9. Validate Zoho API response.
    $zohoSuccess = (
        isset($data['code']) &&
        (int) $data['code'] === 0
    );

    $success = $httpSuccess && $zohoSuccess;

    return [
        'http_code' => $httpCode,
        'success' => $success,
        'response' => $data,
        'errors' => $success ? [] : [[
            'message' => $data['message']
                ?? 'Zoho API request failed.',
            'zoho_code' => $data['code'] ?? null
        ]]
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

function getZohoItemDetailsById(string $itemId): array
{
    $itemId = trim($itemId);

    if ($itemId === '') {
        return [
            'success' => false,
            'message' => 'Zoho item ID is required.',
            'item' => null
        ];
    }

    // First, try retrieving it as an individual variant.
    $variantResponse = zohoInventoryApi(
        'GET',
        'itemvariants/' . rawurlencode($itemId),
        [
            'organization_id' => ZOHO_ORGANIZATION_ID
        ]
    );

    if (!empty($variantResponse['success'])) {
        $variant = $variantResponse['response']['item_variant'] ?? null;

        if (is_array($variant)) {
            return [
                'success' => true,
                'type' => 'variant',
                'item' => $variant,
                'response' => $variantResponse
            ];
        }
    }

    // If it is not a variant, try retrieving it as a normal item.
    $itemResponse = zohoInventoryApi(
        'GET',
        'items/' . rawurlencode($itemId),
        [
            'organization_id' => ZOHO_ORGANIZATION_ID
        ]
    );

    if (!empty($itemResponse['success'])) {
        $item = $itemResponse['response']['item'] ?? null;

        if (is_array($item)) {
            return [
                'success' => true,
                'type' => !empty($item['group_id'])
                    ? 'group_variant'
                    : 'standalone',
                'item' => $item,
                'response' => $itemResponse
            ];
        }
    }

    return [
        'success' => false,
        'message' => 'Unable to retrieve this Zoho item.',
        'variant_response' => $variantResponse,
        'item_response' => $itemResponse
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

    if (empty($existing['success'])) {
        return [
            'success' => false,
            'message' => 'Unable to verify whether this SKU already exists in Zoho.',
            'item' => null,
            'response' => $existing
        ];
    }

    if (!empty($existing['found'])) {
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

        if (!is_numeric($product['stock']) || (float) $product['stock'] < 0) {
            return [
                'success' => false,
                'message' => 'Stock must be a valid non-negative number.',
                'item' => null
            ];
        }

        $stock = (float) $product['stock'];

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

    if (!is_finite($newStock) || $newStock < 0) {
        return [
            'success' => false,
            'message' => 'Stock must be a valid non-negative number.',
            'response' => null
        ];
    }
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
    $quantityAdjusted =
        $newStock - $currentStock;

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


function syncVariantToZoho(int $variantId)
{
    global $db;

    if ($variantId <= 0) {
        return [
            'success' => false,
            'action' => null,
            'message' => 'Valid variant ID is required.',
            'item' => null
        ];
    }

    try {
        // 1. Fetch the local variant and its parent product.
        $stmt = $db->prepare("
            SELECT
                v.id AS variant_id,
                v.product_id,
                v.variant_title,
                v.sku,
                v.price,
                v.original_price,
                v.stock,
                v.zoho_item_id,
                p.title AS parent_title,
                p.description
            FROM product_variants v
            INNER JOIN products p
                ON p.id = v.product_id
            WHERE v.id = :variant_id
            LIMIT 1
        ");

        $stmt->execute([
            ':variant_id' => $variantId
        ]);

        $variant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$variant) {
            return [
                'success' => false,
                'action' => null,
                'message' => 'Local variant not found.',
                'item' => null
            ];
        }

        $sku = trim((string) $variant['sku']);

        if ($sku === '') {
            return [
                'success' => false,
                'action' => null,
                'message' => 'Variant SKU is missing.',
                'item' => null
            ];
        }

        // 2. Prepare the Zoho item data.
        $variantName = trim((string) $variant['variant_title']);

        if ($variantName === '') {
            $variantName = trim((string) $variant['parent_title']);
        }

        if ($variantName === '') {
            return [
                'success' => false,
                'action' => null,
                'message' => 'Variant name is missing.',
                'item' => null
            ];
        }

        if (
            !is_numeric($variant['stock']) ||
            (float) $variant['stock'] < 0
        ) {
            return [
                'success' => false,
                'action' => null,
                'message' => 'Variant stock must be a valid non-negative number.',
                'item' => null
            ];
        }

        $productData = [
            'name' => $variantName,
            'sku' => $sku,
            'description' => $variant['description'] ?? '',
            'rate' => (float) $variant['price'],
            'stock' => (float) $variant['stock']
        ];

        if ($productData['name'] === '') {
            $productData['name'] = trim(
                (string) $variant['parent_title']
            );
        }

        // 3. Create or update the Zoho item.
        $result = syncProductToZoho($productData);

        if (empty($result['success'])) {
            return [
                'success' => false,
                'action' => $result['action'] ?? null,
                'message' => $result['message']
                    ?? 'Zoho variant synchronization failed.',
                'variant_id' => $variantId,
                'sku' => $sku,
                'item' => $result['item'] ?? null,
                'response' => $result
            ];
        }

        // 4. Get the Zoho item ID.
        $zohoItemId = trim(
            (string) ($result['item']['item_id'] ?? '')
        );

        if ($zohoItemId === '') {
            return [
                'success' => false,
                'action' => $result['action'] ?? null,
                'message' => 'Zoho item was synchronized, but its item ID was not returned.',
                'variant_id' => $variantId,
                'sku' => $sku,
                'item' => $result['item'] ?? null
            ];
        }

        // 5. Save the Zoho item ID against this exact local variant.
        $updateStmt = $db->prepare("
            UPDATE product_variants
            SET
                zoho_item_id = :zoho_item_id,
                zoho_sync_status = 'synced'
            WHERE id = :variant_id
        ");

        $updateStmt->execute([
            ':zoho_item_id' => $zohoItemId,
            ':variant_id' => $variantId
        ]);
        if ($updateStmt->rowCount() === 0) {
            $checkStmt = $db->prepare("
        SELECT zoho_item_id
        FROM product_variants
        WHERE id = :variant_id
        LIMIT 1
    ");

            $checkStmt->execute([
                ':variant_id' => $variantId
            ]);

            $savedItemId = $checkStmt->fetchColumn();

            if ((string) $savedItemId !== $zohoItemId) {
                throw new Exception('Zoho item ID could not be saved to the local variant.');
            }
        }

        return [
            'success' => true,
            'action' => $result['action'] ?? null,
            'message' => 'Variant synchronized with Zoho successfully.',
            'product_id' => (int) $variant['product_id'],
            'variant_id' => $variantId,
            'sku' => $sku,
            'zoho_item_id' => $zohoItemId,
            'item' => $result['item']
        ];
    } catch (Throwable $e) {

        error_log(
            'Zoho variant sync error: ' . $e->getMessage()
        );

        return [
            'success' => false,
            'action' => null,
            'message' => 'Unexpected error while synchronizing variant with Zoho.',
            'variant_id' => $variantId,
            'error' => $e->getMessage()
        ];
    }
}


function setZohoItemStatus(string $itemId, string $status): array
{
    $itemId = trim($itemId);

    if ($itemId === '') {
        return [
            'success' => false,
            'message' => 'Zoho item ID is required.'
        ];
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        return [
            'success' => false,
            'message' => 'Invalid Zoho item status.'
        ];
    }

    $response = zohoInventoryApi(
        'POST',
        'items/' . rawurlencode($itemId) . '/' . $status,
        ['organization_id' => ZOHO_ORGANIZATION_ID],
        null
    );

    if (empty($response['success'])) {
        return [
            'success' => false,
            'message' => 'Could not set Zoho item to ' . $status . '.',
            'response' => $response
        ];
    }

    return [
        'success' => true,
        'message' => 'Zoho item is now ' . $status . '.'
    ];
}

function syncApprovedProductToZoho(array $product): array
{
    $itemId = trim($product['zoho_item_id'] ?? '');

    if ($itemId !== '') {

        $updated = updateZohoItem($itemId, $product);

        if (empty($updated['success'])) {
            return [
                'success' => false,
                'message' => $updated['message']
                    ?? 'Zoho item update failed.',
                'item' => $updated['item'] ?? null,
                'item_id' => $itemId
            ];
        }

        if (isset($product['stock'])) {
            $stockResult = adjustZohoItemStock(
                $itemId,
                $product['name'] ?? 'Product',
                (float) $product['stock']
            );

            if (empty($stockResult['success'])) {
                return [
                    'success' => false,
                    'message' =>
                    'Zoho item details updated, but stock update failed: ' .
                        ($stockResult['message'] ?? 'Unknown error.'),
                    'item' => $updated['item'] ?? null,
                    'item_id' => $itemId
                ];
            }
        }

        $item = $updated['item'] ?? null;
    } else {

        $synced = syncProductToZoho($product);

        if (empty($synced['success'])) {
            return [
                'success' => false,
                'message' => $synced['message']
                    ?? 'Zoho synchronization failed.',
                'item' => $synced['item'] ?? null,
                'item_id' => $synced['item']['item_id'] ?? ''
            ];
        }

        $item = $synced['item'] ?? null;
        $itemId = $item['item_id'] ?? '';

        if ($itemId === '') {
            return [
                'success' => false,
                'message' => 'Zoho item ID was not returned.',
                'item' => $item
            ];
        }
    }

    $activated = setZohoItemStatus($itemId, 'active');

    if (empty($activated['success'])) {
        return [
            'success' => false,
            'message' => $activated['message']
                ?? 'Zoho item could not be activated.',
            'item' => $item,
            'item_id' => $itemId
        ];
    }

    return [
        'success' => true,
        'message' => 'Zoho item synchronized and activated.',
        'item' => $item,
        'item_id' => $itemId
    ];
}




function syncZohoWebhookToDatabase(array $decodedPayload)
{
    global $db;

    $webhookItem = $decodedPayload['item'] ?? null;

    if (!is_array($webhookItem) || empty($webhookItem)) {
        return [
            'success' => false,
            'message' => 'No valid Zoho item data found.'
        ];
    }

    $sku = trim((string)($webhookItem['sku'] ?? ''));
    $zohoItemId = trim((string)($webhookItem['item_id'] ?? ''));

    if ($sku === '' || $zohoItemId === '') {
        return [
            'success' => false,
            'message' => 'Zoho item ID and SKU are required.'
        ];
    }

    /*
     * Retrieve the authoritative Zoho record.
     * This provides group_id for variants, even when
     * the webhook payload does not include it.
     */
    $detailsResult = getZohoItemDetailsById($zohoItemId);

    if (empty($detailsResult['success'])) {
        return [
            'success' => false,
            'message' => 'Unable to retrieve complete Zoho item details.',
            'zoho_details' => $detailsResult
        ];
    }

    $item = $detailsResult['item'] ?? [];

    if (!is_array($item) || empty($item)) {
        return [
            'success' => false,
            'message' => 'Zoho item details are empty.'
        ];
    }

    // Use the retrieved Zoho details as the source of truth.
    $sku = trim((string)($item['sku'] ?? $sku));
    $zohoItemId = trim((string)($item['item_id'] ?? $zohoItemId));

    if (
        !isset($item['stock_on_hand']) ||
        !is_numeric($item['stock_on_hand'])
    ) {
        return [
            'success' => false,
            'message' => 'Zoho stock_on_hand is missing or invalid.',
            'sku' => $sku
        ];
    }

    $name = trim((string)($item['name'] ?? ''));
    $description = trim((string)($item['description'] ?? ''));

    $price = isset($item['rate']) && is_numeric($item['rate'])
        ? (float)$item['rate']
        : 0.00;

    $stock = max(0, (int)$item['stock_on_hand']);

    $localStatus = strtolower(
        trim((string)($item['status'] ?? ''))
    ) === 'active' ? 1 : 0;

    $itemType = $detailsResult['type'] ?? '';

    // A variant must have a valid parent group ID.
    $groupId = trim((string)(
        $item['group_id']
        ?? $item['item_master_id']
        ?? ''
    ));

    if ($itemType === 'variant' || $itemType === 'group_variant') {
        if ($groupId === '') {
            return [
                'success' => false,
                'message' => 'Zoho variant does not contain a parent group ID.',
                'sku' => $sku
            ];
        }
    } elseif ($itemType !== 'standalone') {
        return [
            'success' => false,
            'message' => 'Unsupported Zoho item type.',
            'item_type' => $itemType
        ];
    }

    try {
        $db->beginTransaction();

        /*
         * STEP 1: Check variants by Zoho item ID first.
         */
        $variant = false;

        $variantStmt = $db->prepare("
            SELECT id, product_id, sku, zoho_item_id
            FROM product_variants
            WHERE zoho_item_id = :zoho_item_id
            LIMIT 1
            FOR UPDATE
        ");

        $variantStmt->execute([
            ':zoho_item_id' => $zohoItemId
        ]);

        $variant = $variantStmt->fetch(PDO::FETCH_ASSOC);

        /*
         * STEP 2: If not found by Zoho ID, check SKU.
         */
        if (!$variant) {
            $variantStmt = $db->prepare("
                SELECT id, product_id, sku, zoho_item_id
                FROM product_variants
                WHERE sku = :sku
                LIMIT 1
                FOR UPDATE
            ");

            $variantStmt->execute([
                ':sku' => $sku
            ]);

            $variant = $variantStmt->fetch(PDO::FETCH_ASSOC);
        }

        /*
         * STEP 3: Existing variant update.
         */
        if ($variant) {

            if (
                !empty($variant['zoho_item_id']) &&
                $variant['zoho_item_id'] !== $zohoItemId
            ) {
                throw new RuntimeException(
                    'SKU is already mapped to a different Zoho item ID.'
                );
            }

            $productId = (int)$variant['product_id'];

            $parentStmt = $db->prepare("
                SELECT zoho_group_id
                FROM products
                WHERE id = :product_id
                LIMIT 1
                FOR UPDATE
            ");

            $parentStmt->execute([
                ':product_id' => $productId
            ]);

            $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$parent) {
                throw new RuntimeException(
                    'Local parent product was not found.'
                );
            }

            if (
                $groupId !== '' &&
                !empty($parent['zoho_group_id']) &&
                $parent['zoho_group_id'] !== $groupId
            ) {
                throw new RuntimeException(
                    'Zoho group ID conflicts with the existing local parent mapping.'
                );
            }

            $updateVariant = $db->prepare("
                UPDATE product_variants
                SET
                    variant_title = :title,
                    price = :price,
                    stock = :stock,
                    status = :status,
                    zoho_item_id = :zoho_item_id,
                    zoho_sync_status = 'synced'
                WHERE id = :variant_id
            ");

            $updateVariant->execute([
                ':title' => $name,
                ':price' => $price,
                ':stock' => $stock,
                ':status' => $localStatus,
                ':zoho_item_id' => $zohoItemId,
                ':variant_id' => (int)$variant['id']
            ]);

            // Save group mapping if the local parent does not have one.
            if ($groupId !== '' && empty($parent['zoho_group_id'])) {
                $groupUpdate = $db->prepare("
                    UPDATE products
                    SET zoho_group_id = :group_id
                    WHERE id = :product_id
                      AND (zoho_group_id IS NULL OR zoho_group_id = '')
                ");

                $groupUpdate->execute([
                    ':group_id' => $groupId,
                    ':product_id' => $productId
                ]);
            }

            $action = 'variant_updated';
            $variantId = (int)$variant['id'];
        }

        /*
         * STEP 4: New variant in an existing local group.
         */
        elseif ($itemType === 'variant' || $itemType === 'group_variant') {

            $parentStmt = $db->prepare("
                SELECT id, zoho_group_id
                FROM products
                WHERE zoho_group_id = :group_id
                LIMIT 1
                FOR UPDATE
            ");

            $parentStmt->execute([
                ':group_id' => $groupId
            ]);

            $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$parent) {
                throw new RuntimeException(
                    'No local parent product is mapped to this Zoho group. No variant was created.'
                );
            }

            $productId = (int)$parent['id'];

            // Check SKU again before inserting.
            $skuCheck = $db->prepare("
                SELECT id
                FROM product_variants
                WHERE sku = :sku
                LIMIT 1
                FOR UPDATE
            ");

            $skuCheck->execute([
                ':sku' => $sku
            ]);

            if ($skuCheck->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException(
                    'This SKU already exists under another local variant.'
                );
            }

            $insertVariant = $db->prepare("
                INSERT INTO product_variants (
                    product_id,
                    variant_title,
                    sku,
                    price,
                    stock,
                    status,
                    zoho_item_id,
                    zoho_sync_status,
                    shopify_sync_status
                ) VALUES (
                    :product_id,
                    :title,
                    :sku,
                    :price,
                    :stock,
                    :status,
                    :zoho_item_id,
                    'synced',
                    'pending'
                )
            ");

            $insertVariant->execute([
                ':product_id' => $productId,
                ':title' => $name,
                ':sku' => $sku,
                ':price' => $price,
                ':stock' => $stock,
                ':status' => $localStatus,
                ':zoho_item_id' => $zohoItemId
            ]);

            $variantId = (int)$db->lastInsertId();
            $action = 'variant_created';
        }

        /*
         * STEP 5: Standalone item.
         */
        else {

            // First look for the standalone item by Zoho ID.
            $productStmt = $db->prepare("
                SELECT id, sku, slug, zoho_item_id
                FROM products
                WHERE zoho_item_id = :zoho_item_id
                LIMIT 1
                FOR UPDATE
            ");

            $productStmt->execute([
                ':zoho_item_id' => $zohoItemId
            ]);

            $product = $productStmt->fetch(PDO::FETCH_ASSOC);

            // Then look for its SKU.
            if (!$product) {
                $productStmt = $db->prepare("
                    SELECT id, sku, slug, zoho_item_id
                    FROM products
                    WHERE sku = :sku
                    LIMIT 1
                    FOR UPDATE
                ");

                $productStmt->execute([
                    ':sku' => $sku
                ]);

                $product = $productStmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($product) {

                if (
                    !empty($product['zoho_item_id']) &&
                    $product['zoho_item_id'] !== $zohoItemId
                ) {
                    throw new RuntimeException(
                        'Standalone SKU is already mapped to another Zoho item.'
                    );
                }

                $productId = (int)$product['id'];

                $hasVariantsStmt = $db->prepare("
                    SELECT COUNT(*)
                    FROM product_variants
                    WHERE product_id = :product_id
                ");

                $hasVariantsStmt->execute([
                    ':product_id' => $productId
                ]);

                if ((int)$hasVariantsStmt->fetchColumn() > 0) {
                    throw new RuntimeException(
                        'Standalone Zoho item matched a local product that has variants.'
                    );
                }

                $slug = trim((string)($product['slug'] ?? ''));

                if ($slug === '') {
                    $slug = createUniqueSlug($db, $name, $productId);
                }

                $updateProduct = $db->prepare("
                    UPDATE products
                    SET
                        title = :title,
                        slug = :slug,
                        description = :description,
                        price = :price,
                        stock = :stock,
                        status = :status,
                        zoho_item_id = :zoho_item_id
                    WHERE id = :product_id
                ");

                $updateProduct->execute([
                    ':title' => $name,
                    ':slug' => $slug,
                    ':description' => $description !== '' ? $description : null,
                    ':price' => $price,
                    ':stock' => $stock,
                    ':status' => $localStatus,
                    ':zoho_item_id' => $zohoItemId,
                    ':product_id' => $productId
                ]);

                $action = 'product_updated';
            } else {

                // Create a new standalone local product.
                $slug = createUniqueSlug($db, $name);

                $insertProduct = $db->prepare("
                    INSERT INTO products (
                        sku,
                        title,
                        slug,
                        description,
                        price,
                        stock,
                        status,
                        zoho_item_id
                    ) VALUES (
                        :sku,
                        :title,
                        :slug,
                        :description,
                        :price,
                        :stock,
                        :status,
                        :zoho_item_id
                    )
                ");

                $insertProduct->execute([
                    ':sku' => $sku,
                    ':title' => $name,
                    ':slug' => $slug,
                    ':description' => $description !== '' ? $description : null,
                    ':price' => $price,
                    ':stock' => $stock,
                    ':status' => $localStatus,
                    ':zoho_item_id' => $zohoItemId
                ]);

                $productId = (int)$db->lastInsertId();
                $action = 'product_created';
            }
        }

        /*
         * STEP 6: Recalculate parent stock for variants.
         */
        if (
            $action === 'variant_updated' ||
            $action === 'variant_created'
        ) {
            $parentUpdate = $db->prepare("
                UPDATE products
                SET stock = (
                    SELECT COALESCE(SUM(v.stock), 0)
                    FROM product_variants v
                    WHERE v.product_id = products.id
                )
                WHERE id = :product_id
            ");

            $parentUpdate->execute([
                ':product_id' => $productId
            ]);
        }

        $db->commit();

        return [
            'success' => true,
            'action' => $action,
            'message' => 'Zoho item synchronized with the local database.',
            'product_id' => $productId,
            'variant_id' => $variantId ?? null,
            'sku' => $sku,
            'zoho_item_id' => $zohoItemId,
            'zoho_group_id' => $groupId ?: null,
            'stock' => $stock
        ];

    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log(
            'Zoho webhook database sync error: ' . $e->getMessage()
        );

        return [
            'success' => false,
            'message' => 'Zoho database synchronization failed.',
            'error' => $e->getMessage(),
            'sku' => $sku,
            'zoho_item_id' => $zohoItemId
        ];
    }
}



function fetchLatestZohoWebhookPayload(): array
{
    $url = 'https://baseavangers.topscripts.in/sumit_rana/offline/zoho_latest_webhook.json';

    $curl = curl_init();

    if ($curl === false) {
        return [
            'success' => false,
            'message' => 'Unable to initialize cURL.'
        ];
    }

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($response === false) {
        return [
            'success' => false,
            'message' => 'Webhook file request failed: ' . $curlError
        ];
    }

    if ($httpCode !== 200) {
        return [
            'success' => false,
            'message' => 'Webhook file returned HTTP ' . $httpCode
        ];
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        return [
            'success' => false,
            'message' => 'Invalid webhook JSON: ' . json_last_error_msg()
        ];
    }

    $decodedPayload = $data['decoded_payload'] ?? null;

 if (
    !is_array($decodedPayload) ||
    empty($decodedPayload['item']) ||
    empty($decodedPayload['item']['item_id']) ||
    empty($decodedPayload['item']['sku'])
) {
        return [
            'success' => false,
            'message' => 'No valid decoded Zoho item found in webhook file.'
        ];
    }

    return [
        'success' => true,
        'decoded_payload' => $decodedPayload,
        'received_at' => $data['received_at'] ?? null
    ];
}

function syncZohoWebhookItemToShopify(): array
{
    global $db;

    // STEP 1: Fetch the latest webhook from hosting.
    $webhookResult = fetchLatestZohoWebhookPayload();

    if (empty($webhookResult['success'])) {
        return [
            'success' => false,
            'message' => $webhookResult['message']
                ?? 'Unable to fetch Zoho webhook.',
            'stage' => 'webhook_fetch'
        ];
    }

    $decodedPayload = $webhookResult['decoded_payload'];

    // STEP 2: Synchronize Zoho data with the local database.
    $databaseResult = syncZohoWebhookToDatabase($decodedPayload);

    if (empty($databaseResult['success'])) {
        return [
            'success' => false,
            'message' => $databaseResult['message']
                ?? 'Zoho database synchronization failed.',
            'stage' => 'database_sync',
            'database_result' => $databaseResult
        ];
    }

    $action = $databaseResult['action'] ?? '';
    $productId = (int)($databaseResult['product_id'] ?? 0);

    if ($productId <= 0) {
        return [
            'success' => false,
            'message' => 'Invalid local product ID.',
            'database_result' => $databaseResult
        ];
    }

    // STEP 3: Synchronize variants with Shopify.
    if (in_array($action, ['variant_updated', 'variant_created'], true)) {

        $shopifyResult = syncParentProductVariantsToShopify($productId);

        return [
            'success' => !empty($shopifyResult['success']),
            'action' => $action,
            'product_id' => $productId,
            'variant_id' => $databaseResult['variant_id'] ?? null,
            'sku' => $databaseResult['sku'] ?? null,
            'message' => !empty($shopifyResult['success'])
                ? 'Zoho variant and Shopify variants synchronized successfully.'
                : 'Local variant saved, but Shopify synchronization failed.',
            'database_result' => $databaseResult,
            'shopify_result' => $shopifyResult
        ];
    }

    // STEP 4: Synchronize standalone products with Shopify.
    if (in_array($action, ['product_updated', 'product_created'], true)) {

        $stmt = $db->prepare("
            SELECT
                id,
                sku,
                title,
                slug,
                description,
                price,
                original_price,
                stock,
                status,
                shopify_product_id
            FROM products
            WHERE id = :product_id
            LIMIT 1
        ");

        $stmt->execute([
            ':product_id' => $productId
        ]);

        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            return [
                'success' => false,
                'message' => 'Local standalone product could not be found.',
                'product_id' => $productId
            ];
        }

        $shopifyResult = syncProductToShopify($product);

        if (empty($shopifyResult['success'])) {
            return [
                'success' => false,
                'action' => $action,
                'message' => 'Local product saved, but Shopify synchronization failed.',
                'product_id' => $productId,
                'database_result' => $databaseResult,
                'shopify_result' => $shopifyResult
            ];
        }

        $shopifyProductId =
            $shopifyResult['data']['shopify_product_id'] ?? null;

        if (!empty($shopifyProductId)) {
            $saveShopifyId = $db->prepare("
                UPDATE products
                SET
                    shopify_product_id = :shopify_product_id,
                    shopify_status = 'active',
                    shopify_synced_at = NOW()
                WHERE id = :product_id
            ");

            $saveShopifyId->execute([
                ':shopify_product_id' => $shopifyProductId,
                ':product_id' => $productId
            ]);
        }

        return [
            'success' => true,
            'action' => $action,
            'product_id' => $productId,
            'sku' => $product['sku'],
            'message' => 'Zoho standalone product synchronized with Shopify.',
            'database_result' => $databaseResult,
            'shopify_result' => $shopifyResult
        ];
    }

    return [
        'success' => false,
        'message' => 'Unsupported synchronization action.',
        'action' => $action,
        'database_result' => $databaseResult
    ];
}