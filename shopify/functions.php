<?php

require_once __DIR__ . '/config.php';
require_once "../zoho/zoho_functions.php";


$database = new Database();
$db = $database->connect();

function generateShopifyAccessToken()
{
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => SHOPIFY_ACCESS_TOKEN_URL,

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_ENCODING => '',

        CURLOPT_MAXREDIRS => 10,

        CURLOPT_TIMEOUT => 30,

        CURLOPT_FOLLOWLOCATION => true,

        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,

        CURLOPT_CUSTOMREQUEST => 'POST',

        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => SHOPIFY_CLIENT_ID,
            'client_secret' => SHOPIFY_CLIENT_SECRET
        ]),

        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($curl);

    if ($response === false) {

        $error = curl_error($curl);



        throw new Exception(
            'Shopify token request failed: ' . $error
        );
    }

    $httpCode = curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );



    $data = json_decode(
        $response,
        true
    );

    if (
        $httpCode < 200 ||
        $httpCode >= 300
    ) {

        throw new Exception(
            'Shopify token API returned HTTP ' .
                $httpCode .
                ': ' .
                $response
        );
    }

    if (
        empty($data['access_token'])
    ) {

        throw new Exception(
            'Shopify access token was not returned.'
        );
    }


    if (isset($data['expires_in'])) {

        $data['expires_at'] =
            time() + (int) $data['expires_in'];
    } else {

        $data['expires_at'] =
            time() + (23 * 60 * 60);
    }
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES
    );

    if (
        file_put_contents(
            SHOPIFY_ACCESS_TOKEN_FILE,
            $json,
            LOCK_EX
        ) === false
    ) {

        throw new Exception(
            'Unable to save Shopify access token.'
        );
    }


    return $data['access_token'];
}

function getShopifyAccessToken()
{


    if (
        file_exists(
            SHOPIFY_ACCESS_TOKEN_FILE
        )
    ) {

        $json = file_get_contents(
            SHOPIFY_ACCESS_TOKEN_FILE
        );

        $data = json_decode(
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

    return generateShopifyAccessToken();
}
function shopifyGraphQL(string $query, array $variables = [])
{
    $accessToken = getShopifyAccessToken();

    $curl = curl_init(SHOPIFY_GRAPHQL_URL);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Shopify-Access-Token: ' . $accessToken,
        ],

        CURLOPT_POSTFIELDS => json_encode([
            'query' => $query,
            'variables' => (object) $variables,
        ]),

        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($curl);

    if ($response === false) {
        $error = curl_error($curl);
        return [
            'http_code' => 0,
            'data' => [],
            'errors' => [
                [
                    'message' => 'Shopify cURL error: ' . $error
                ]
            ]
        ];
    }

    $httpCode =
        curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );

    $data =
        json_decode(
            $response,
            true
        );

    if (!is_array($data)) {
        return [
            'http_code' => $httpCode,
            'data' => [],
            'errors' => [
                [
                    'message' => 'Invalid Shopify JSON response',
                    'raw_response' => $response
                ]
            ]
        ];
    }

    return [
        'http_code' => $httpCode,
        'data' => $data,
        'errors' => $data['errors'] ?? []
    ];
}
function shopifyGid(string $type, $id): string
{
    $id = trim((string) $id);

    if ($id === '') {
        return '';
    }
    if (str_starts_with($id, 'gid://shopify/')) {
        return $id;
    }

    return "gid://shopify/{$type}/{$id}";
}

function createShopifyProduct(array $product)
{
    $title = trim($product['title'] ?? '');

    if ($title === '') {
        return [
            'success' => false,
            'message' => 'Product title is required.',
            'errors' => []
        ];
    }

    $query = <<<'GRAPHQL'
mutation ProductCreate($product: ProductCreateInput!) {
    productCreate(product: $product) {
        product {
            id
            title
            handle
            status
        }
        userErrors {
            field
            message
        }
    }
}
GRAPHQL;

    $variables = [
        'product' => [
            'title' => $title,
            'descriptionHtml' => $product['description'] ?? '',
            'handle' => !empty($product['slug'])
                ? trim($product['slug'])
                : null,
            'status' => !empty($product['status'])
                ? 'ACTIVE'
                : 'DRAFT'
        ]
    ];

    $response = shopifyGraphQL(
        $query,
        $variables
    );
    if (!empty($response['errors'])) {
        return [
            'success' => false,
            'message' => 'Shopify GraphQL request failed.',
            'errors' => $response['errors']
        ];
    }
    $shopifyData = $response['data']['data'] ?? [];

    $productCreate =
        $shopifyData['productCreate'] ?? null;
    if (!$productCreate) {
        return [
            'success' => false,
            'message' => 'Shopify productCreate response was not returned.',
            'response' => $response
        ];
    }

    $userErrors =
        $productCreate['userErrors'] ?? [];

    if (!empty($userErrors)) {
        return [
            'success' => false,
            'message' => 'Shopify product creation failed.',
            'errors' => $userErrors
        ];
    }
    $createdProduct =
        $productCreate['product'] ?? null;

    if (
        !$createdProduct ||
        empty($createdProduct['id'])
    ) {
        return [
            'success' => false,
            'message' => 'Shopify product was not returned after creation.',
            'response' => $response
        ];
    }
    return [
        'success' => true,
        'message' => 'Shopify product created successfully.',
        'data' => $createdProduct
    ];
}


function getShopifyProductInventoryInfo($shopifyProductId)
{
    $productGid = shopifyGid(
        'Product',
        $shopifyProductId
    );

    if ($productGid === '') {
        return [
            'success' => false,
            'message' => 'Shopify product ID is empty.',
            'data' => null
        ];
    }
    $locationInfo = getShopifyLocationId();

    if (empty($locationInfo['success'])) {
        return [
            'success' => false,
            'message' => $locationInfo['message'] ?? 'Unable to get Shopify location.',
            'errors' => $locationInfo['errors'] ?? []
        ];
    }

    $targetLocationId = $locationInfo['location_id'];

    $query = <<<'GRAPHQL'
query GetProductInventoryInfo($id: ID!) {
    product(id: $id) {
        id
        title
        status

        variants(first: 10) {
            nodes {
                id
                sku

                inventoryItem {
                    id

                    inventoryLevels(first: 50) {
                        nodes {
                            location {
                                id
                                name
                            }

                            quantities(names: ["available"]) {
                                name
                                quantity
                            }
                        }
                    }
                }
            }
        }
    }
}
GRAPHQL;


    $response = shopifyGraphQL($query, [
        'id' => $productGid
    ]);
    if (!empty($response['errors'])) {
        return [
            'success' => false,
            'message' => 'Shopify product inventory lookup failed.',
            'data' => null,
            'errors' => $response['errors'],
            'response' => $response
        ];
    }

    $shopifyData =
        $response['data']['data'] ?? [];
    if (
        empty($shopifyData['product']) ||
        empty($shopifyData['product']['variants']['nodes'])
    ) {
        return [
            'success' => false,
            'message' => 'Shopify product or variant not found.',
            'data' => null,
            'response' => $response
        ];
    }

    $product =
        $shopifyData['product'];

    $variant =
        $product['variants']['nodes'][0];
    if (empty($variant['inventoryItem']['id'])) {
        return [
            'success' => false,
            'message' => 'Shopify inventory item not found.',
            'data' => null,
            'response' => $response
        ];
    }
    $inventoryQuantity = 0;

    foreach (
        ($variant['inventoryItem']['inventoryLevels']['nodes'] ?? [])
        as $inventoryLevel
    ) {

        if (
            ($inventoryLevel['location']['id'] ?? '')
            === $targetLocationId
        ) {

            foreach (
                ($inventoryLevel['quantities'] ?? [])
                as $quantity
            ) {

                if (
                    ($quantity['name'] ?? '') === 'available'
                ) {
                    $inventoryQuantity =
                        (int) $quantity['quantity'];

                    break;
                }
            }

            break;
        }
    }

    return [
        'success' => true,
        'message' => 'Inventory information fetched successfully.',

        'data' => [
            'product_id' =>
            $product['id'],

            'title' =>
            $product['title'],

            'status' =>
            $product['status'],

            'variant_id' =>
            $variant['id'],

            'sku' =>
            $variant['sku'],

            'inventory_quantity' =>
            $inventoryQuantity,

            'inventory_item_id' =>
            $variant['inventoryItem']['id']
        ]
    ];
}

function getShopifyLocationId()
{
    $query = <<<'GRAPHQL'
query GetLocations {
    locations(first: 10) {
        nodes {
            id
            name
            isActive
            hasActiveInventory
            fulfillsOnlineOrders
        }
    }
}
GRAPHQL;

    $response = shopifyGraphQL($query);
    if (!empty($response['errors'])) {
        return [
            'success' => false,
            'message' => 'Shopify locations query failed.',
            'location_id' => null,
            'errors' => $response['errors'],
            'response' => $response
        ];
    }

    $shopifyData =
        $response['data']['data'] ?? [];

    $locations =
        $shopifyData['locations']['nodes'] ?? [];

    if (empty($locations)) {
        return [
            'success' => false,
            'message' => 'No Shopify inventory location found.',
            'location_id' => null,
            'errors' => [],
            'response' => $response
        ];
    }
    foreach ($locations as $location) {

        if (
            $location['name'] === 'My Custom Location' &&
            !empty($location['isActive'])
        ) {
            return [
                'success' => true,
                'message' => 'Shopify target inventory location found.',
                'location_id' => $location['id'],
                'location_name' => $location['name'],
                'data' => $location
            ];
        }
    }

    // foreach ($locations as $location) {

    //     if (!empty($location['isActive'])) {
    //         return [
    //             'success' => true,
    //             'message' => 'Shopify active location found.',
    //             'location_id' => $location['id'],
    //             'location_name' => $location['name'],
    //             'data' => $location
    //         ];
    //     }
    // }

    return [
        'success' => false,
        'message' => 'Shopify locations were returned, but no active inventory location was found.',
        'location_id' => null,
        'locations' => $locations,
        'response' => $response
    ];
}
function updateShopifyInventory($shopifyProductId, $newQuantity)
{
    $newQuantity = (int) $newQuantity;

    $inventoryInfo = getShopifyProductInventoryInfo($shopifyProductId);

    if (empty($inventoryInfo['success'])) {
        return [
            'success' => false,
            'message' => $inventoryInfo['message'] ?? 'Unable to get Shopify inventory information.',
            'errors' => $inventoryInfo['errors'] ?? []
        ];
    }

    $inventoryItemId =
        $inventoryInfo['data']['inventory_item_id'];

    $currentQuantity =
        (int) $inventoryInfo['data']['inventory_quantity'];
    if ($currentQuantity === $newQuantity) {

        return [
            'success' => true,
            'message' => 'Shopify inventory is already up to date.',
            'data' => [
                'old_quantity' => $currentQuantity,
                'new_quantity' => $newQuantity,
                'changed' => false
            ]
        ];
    }

    $locationInfo = getShopifyLocationId();

    if (empty($locationInfo['success'])) {
        return [
            'success' => false,
            'message' => $locationInfo['message'] ?? 'Unable to get Shopify location.',
            'errors' => $locationInfo['errors'] ?? []
        ];
    }

    $locationId = $locationInfo['location_id'];
    $idempotencyKey =
        'inventory-' .
        preg_replace(
            '/[^a-zA-Z0-9_-]/',
            '-',
            $shopifyProductId
        ) .
        '-' .
        $currentQuantity .
        '-' .
        $newQuantity .
        '-' .
        uniqid();
    $query = <<<'GRAPHQL'
mutation InventorySetQuantities(
    $input: InventorySetQuantitiesInput!
    $idempotencyKey: String!
) {
    inventorySetQuantities(
        input: $input
    ) @idempotent(key: $idempotencyKey) {
        inventoryAdjustmentGroup {
            createdAt
            reason
            referenceDocumentUri
            changes {
                name
                delta
            }
        }
        userErrors {
            field
            message
        }
    }
}
GRAPHQL;

    $variables = [
        'input' => [
            'name' => 'available',
            'reason' => 'correction',
            'quantities' => [
                [
                    'inventoryItemId' => $inventoryItemId,
                    'locationId' => $locationId,
                    'quantity' => $newQuantity,
                    'changeFromQuantity' => $currentQuantity
                ]
            ]
        ],
        'idempotencyKey' => $idempotencyKey
    ];

    $response = shopifyGraphQL(
        $query,
        $variables
    );


    if (!empty($response['errors'])) {

        return [
            'success' => false,
            'message' => 'Shopify GraphQL error.',
            'errors' => $response['errors']
        ];
    }
    $shopifyData =
        $response['data']['data'] ?? [];

    $inventoryResult =
        $shopifyData['inventorySetQuantities'] ?? null;

    if (!$inventoryResult) {

        return [
            'success' => false,
            'message' => 'Shopify inventory response was not returned.',
            'response' => $response
        ];
    }
    $userErrors =
        $inventoryResult['userErrors'] ?? [];

    if (!empty($userErrors)) {

        return [
            'success' => false,
            'message' => 'Shopify inventory update failed.',
            'errors' => $userErrors
        ];
    }
    return [
        'success' => true,
        'message' => 'Shopify inventory updated successfully.',
        'data' => [
            'old_quantity' => $currentQuantity,
            'new_quantity' => $newQuantity,
            'inventory_item_id' => $inventoryItemId,
            'location_id' => $locationId,
            'changed' => true,
            'shopify_response' =>
            $inventoryResult['inventoryAdjustmentGroup'] ?? null
        ]
    ];
}


function updateShopifyProductVariant(
    $shopifyProductId,
    $price,
    $originalPrice,
    $sku,
    $shopifyVariantId = '',
    $variantTitle = ''
) {
    $sku = trim((string) $sku);

    if ($sku === '') {
        return [
            'success' => false,
            'message' => 'Variant SKU is required.'
        ];
    }

    if (!is_numeric($price) || (float) $price < 0) {
        return [
            'success' => false,
            'message' => 'A valid variant price is required.'
        ];
    }

    $productGid = shopifyGid('Product', $shopifyProductId);

    if ($productGid === '') {
        return [
            'success' => false,
            'message' => 'Invalid Shopify product ID.'
        ];
    }
    $shopifyVariantId = trim((string) $shopifyVariantId);

    if ($shopifyVariantId !== '') {
        $variantId = shopifyGid(
            'ProductVariant',
            $shopifyVariantId
        );
    } else {
        $lookup = findShopifyVariantBySKU(
            $productGid,
            $sku
        );

        if (empty($lookup['success']) || empty($lookup['variant']['id'])) {
            return [
                'success' => false,
                'message' => $lookup['message']
                    ?? 'Shopify variant lookup failed.',
                'errors' => $lookup['errors'] ?? []
            ];
        }

        $variantId = $lookup['variant']['id'];
    }
    $compareAtPrice = (
        is_numeric($originalPrice) &&
        (float) $originalPrice > 0
    )
        ? number_format((float) $originalPrice, 2, '.', '')
        : null;

    $mutation = <<<'GRAPHQL'
mutation UpdateProductVariant(
    $productId: ID!
    $variants: [ProductVariantsBulkInput!]!
) {
    productVariantsBulkUpdate(
        productId: $productId
        variants: $variants
    ) {
        productVariants {
            id
            sku
            price
            compareAtPrice
            inventoryItem {
                id
                tracked
            }
        }
        userErrors {
            field
            message
        }
    }
}
GRAPHQL;

    $variables = [
        'productId' => $productGid,
        'variants' => [[
            'id' => $variantId,
            'price' => number_format(
                (float) $price,
                2,
                '.',
                ''
            ),
            'compareAtPrice' => $compareAtPrice,
            'inventoryItem' => [
                'sku' => $sku
            ],
            'optionValues' => [[
                'optionName' => 'Title',
                'name' => trim((string) $variantTitle)
            ]]
        ]]
    ];

    $response = shopifyGraphQL(
        $mutation,
        $variables
    );

    if (!empty($response['errors'])) {
        return [
            'success' => false,
            'message' => 'Shopify variant update request failed.',
            'errors' => $response['errors']
        ];
    }

    $result =
        $response['data']['data']['productVariantsBulkUpdate']
        ?? null;

    if (!$result) {
        return [
            'success' => false,
            'message' => 'Shopify variant update response was not returned.',
            'response' => $response
        ];
    }

    if (!empty($result['userErrors'])) {
        return [
            'success' => false,
            'message' => 'Shopify variant update failed.',
            'errors' => $result['userErrors']
        ];
    }

    $updatedVariant =
        $result['productVariants'][0] ?? null;

    if (
        !$updatedVariant ||
        empty($updatedVariant['id']) ||
        empty($updatedVariant['inventoryItem']['id'])
    ) {
        return [
            'success' => false,
            'message' => 'Updated variant or inventory item ID was not returned.',
            'response' => $response
        ];
    }
    return [
        'success' => true,
        'message' => 'Shopify variant updated successfully.',
        'data' => $updatedVariant
    ];
}


function updateShopifyProduct(
    $shopifyProductId,
    array $product
) {
    $query = <<<'GRAPHQL'
mutation ProductUpdate(
    $product: ProductUpdateInput!
) {
    productUpdate(product: $product) {
        product {
            id
            title
            handle
            status
            descriptionHtml
        }
        userErrors {
            field
            message
        }
    }
}
GRAPHQL;

    $variables = [
        'product' => [
            'id' => shopifyGid('Product', $shopifyProductId),
            'title' => $product['title'] ?? '',
            'descriptionHtml' =>
            $product['description'] ?? '',
            'handle' => $product['slug'] ?? null,
            'status' => !empty($product['status'])
                ? 'ACTIVE'
                : 'DRAFT'
        ]
    ];

    $response = shopifyGraphQL(
        $query,
        $variables
    );
    if (!empty($response['errors'])) {
        return [
            'success' => false,
            'message' => 'Shopify product update request failed.',
            'errors' => $response['errors']
        ];
    }
    $shopifyData =
        $response['data']['data'] ?? [];

    $productUpdate =
        $shopifyData['productUpdate'] ?? null;

    if (!$productUpdate) {
        return [
            'success' => false,
            'message' =>
            'Shopify productUpdate response was not returned.',
            'response' => $response
        ];
    }

    $userErrors =
        $productUpdate['userErrors'] ?? [];

    if (!empty($userErrors)) {
        return [
            'success' => false,
            'message' =>
            'Shopify product update failed.',
            'errors' => $userErrors
        ];
    }
    $updatedProduct =
        $productUpdate['product'] ?? null;

    if (
        !$updatedProduct ||
        empty($updatedProduct['id'])
    ) {
        return [
            'success' => false,
            'message' =>
            'Shopify updated product was not returned.',
            'response' => $response
        ];
    }

    return [
        'success' => true,
        'message' =>
        'Shopify product updated successfully.',
        'data' => $updatedProduct
    ];
}

function createShopifyVendorCollection($vendorId)
{
    global $db;

    $vendorId = (int) $vendorId;

    if ($vendorId <= 0) {
        return [
            'success' => false,
            'message' => 'Invalid vendor ID.'
        ];
    }
    $stmt = $db->prepare("
        SELECT
            user_id,
            store_name,
            business_name,
            shopify_collection_id
        FROM vendor_profiles
        WHERE user_id = :user_id
        LIMIT 1
    ");

    $stmt->execute([
        ':user_id' => $vendorId
    ]);

    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vendor) {
        return [
            'success' => false,
            'message' => 'Vendor profile not found.'
        ];
    }

    if (!empty($vendor['shopify_collection_id'])) {

        return [
            'success' => true,
            'message' =>
            'Vendor Shopify collection already exists.',
            'collection_id' =>
            $vendor['shopify_collection_id'],
            'created' => false
        ];
    }

    $vendorName = trim(
        $vendor['business_name'] ?? ''
    );

    if ($vendorName === '') {
        $vendorName = trim(
            $vendor['store_name'] ?? ''
        );
    }

    if ($vendorName === '') {
        return [
            'success' => false,
            'message' =>
            'Vendor business/store name is empty.'
        ];
    }
    $collectionTitle = $vendorName;

    $query = <<<'GRAPHQL'
mutation CreateVendorCollection(
    $collection: CollectionCreateInput!
) {
    collectionCreate(
        collection: $collection
    ) {
        collection {
            id
            title
            handle
        }
        userErrors {
            field
            message
        }
    }
}
GRAPHQL;

    $variables = [
        'collection' => [
            'title' => $collectionTitle
        ]
    ];

    $response = shopifyGraphQL(
        $query,
        $variables
    );
    if (!empty($response['errors'])) {

        return [
            'success' => false,
            'message' =>
            'Shopify collection creation failed.',
            'errors' => $response['errors'],
            'response' => $response
        ];
    }

    $shopifyData =
        $response['data']['data'] ?? [];

    $result =
        $shopifyData['collectionCreate'] ?? null;

    if (!$result) {

        return [
            'success' => false,
            'message' =>
            'Shopify collectionCreate response was not returned.',
            'response' => $response
        ];
    }
    $userErrors =
        $result['userErrors'] ?? [];

    if (!empty($userErrors)) {

        return [
            'success' => false,
            'message' =>
            'Shopify vendor collection creation failed.',
            'errors' => $userErrors
        ];
    }

    $collection =
        $result['collection'] ?? null;

    if (
        !$collection ||
        empty($collection['id'])
    ) {

        return [
            'success' => false,
            'message' =>
            'Shopify collection ID was not returned.',
            'response' => $response
        ];
    }
    $updateStmt = $db->prepare("
        UPDATE vendor_profiles
        SET shopify_collection_id = :collection_id
        WHERE user_id = :user_id
    ");

    $updateStmt->execute([
        ':collection_id' =>
        $collection['id'],
        ':user_id' =>
        $vendorId
    ]);

    return [
        'success' => true,
        'message' =>
        'Shopify vendor collection created successfully.',
        'collection_id' =>
        $collection['id'],
        'collection_title' =>
        $collection['title'],
        'created' => true
    ];
}

function addShopifyProductToVendorCollection(
    $shopifyProductId,
    $vendorId
) {
    $shopifyProductId =
        shopifyGid(
            'Product',
            $shopifyProductId
        );

    if ($shopifyProductId === '') {
        return [
            'success' => false,
            'message' => 'Shopify product ID is empty.'
        ];
    }
    $collectionResult =
        createShopifyVendorCollection(
            $vendorId
        );

    if (empty($collectionResult['success'])) {

        return [
            'success' => false,
            'message' =>
            $collectionResult['message']
                ?? 'Unable to create/get vendor collection.',
            'errors' =>
            $collectionResult['errors']
                ?? []
        ];
    }

    $collectionId =
        $collectionResult['collection_id'];

    $query = <<<'GRAPHQL'
mutation AddProductToCollection(
    $id: ID!
    $productIds: [ID!]!
) {
    collectionAddProducts(
        id: $id
        productIds: $productIds
    ) {
        collection {
            id
            title
        }
        userErrors {
            field
            message
        }
    }
}
GRAPHQL;

    $variables = [
        'id' => $collectionId,
        'productIds' => [
            $shopifyProductId
        ]
    ];

    $response =
        shopifyGraphQL(
            $query,
            $variables
        );
    if (!empty($response['errors'])) {

        return [
            'success' => false,
            'message' =>
            'Shopify collection product request failed: ' .
                (
                    $response['errors'][0]['message']
                    ?? 'Unknown GraphQL error.'
                ),
            'errors' => $response['errors']
        ];
    }

    $shopifyData =
        $response['data']['data'] ?? [];

    $result =
        $shopifyData['collectionAddProducts'] ?? null;

    if (!$result) {

        return [
            'success' => false,
            'message' =>
            'Shopify collectionAddProducts response was not returned.',
            'response' => $response
        ];
    }
    $userErrors =
        $result['userErrors'] ?? [];

    if (!empty($userErrors)) {

        $errorMessages = [];

        foreach ($userErrors as $error) {

            $errorMessages[] =
                ($error['message'] ?? 'Unknown Shopify error.');
        }

        return [
            'success' => false,
            'message' =>
            'Unable to add product to vendor collection: ' .
                implode(' | ', $errorMessages),
            'errors' => $userErrors
        ];
    }

    return [
        'success' => true,
        'message' =>
        'Product added to vendor Shopify collection.',
        'collection_id' =>
        $collectionId,
        'collection_title' =>
        $result['collection']['title'] ?? null
    ];
}

function deleteShopifyProduct($shopifyProductId)
{
    $shopifyProductId = shopifyGid(
        'Product',
        $shopifyProductId
    );

    if ($shopifyProductId === '') {
        return [
            'success' => false,
            'message' => 'Shopify product ID is empty.'
        ];
    }

    $query = <<<'GRAPHQL'
mutation ProductDelete(
    $input: ProductDeleteInput!
) {
    productDelete(
        input: $input
    ) {
        deletedProductId
        userErrors {
            field
            message
        }
    }
}
GRAPHQL;

    $variables = [
        'input' => [
            'id' => $shopifyProductId
        ]
    ];

    $response = shopifyGraphQL(
        $query,
        $variables
    );

    if (!empty($response['errors'])) {
        return [
            'success' => false,
            'message' => 'Shopify product deletion request failed.',
            'errors' => $response['errors'],
            'response' => $response
        ];
    }

    $shopifyData =
        $response['data']['data'] ?? [];

    $result =
        $shopifyData['productDelete'] ?? null;

    if (!$result) {
        return [
            'success' => false,
            'message' =>
            'Shopify productDelete response was not returned.',
            'response' => $response
        ];
    }

    $userErrors =
        $result['userErrors'] ?? [];

    if (!empty($userErrors)) {
        return [
            'success' => false,
            'message' =>
            'Shopify product deletion failed.',
            'errors' => $userErrors
        ];
    }

    if (empty($result['deletedProductId'])) {
        return [
            'success' => false,
            'message' =>
            'Shopify did not return a deleted product ID.',
            'response' => $response
        ];
    }

    return [
        'success' => true,
        'message' =>
        'Shopify product deleted successfully.',
        'deleted_product_id' =>
        $result['deletedProductId']
    ];
}

function createUniqueSlug($db, $title, $excludeId = 0)
{
    $slug = strtolower(trim($title));

    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');

    if ($slug === '') {
        $slug = 'product';
    }

    $baseSlug = $slug;
    $counter = 1;

    $sql = "
        SELECT COUNT(*)
        FROM products
        WHERE slug = :slug
    ";

    if ($excludeId > 0) {
        $sql .= " AND id != :exclude_id";
    }

    $stmt = $db->prepare($sql);

    while (true) {

        $params = [
            ':slug' => $slug
        ];

        if ($excludeId > 0) {
            $params[':exclude_id'] = $excludeId;
        }

        $stmt->execute($params);

        if ((int) $stmt->fetchColumn() === 0) {
            break;
        }

        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }

    return $slug;
}

function syncShopifyProductsToDatabase()
{
    global $db;

    $shopifyWebhookUrl = "https://baseavangers.topscripts.in/sumit_rana/offline/shopify_latest_webhook.json";

    $result = [
        'success' => false,
        'message' => '',
        'product_action' => null,
        'product_id' => null,
        'created_variants' => 0,
        'updated_variants' => 0,
        'failed_variants' => []
    ];

    try {

        // 1. Fetch the latest webhook from hosting.
        $ch = curl_init($shopifyWebhookUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new Exception(
                "Could not fetch Shopify webhook. HTTP: {$httpCode}. " . $curlError
            );
        }

        // 2. Decode webhook JSON.
        $decodedWebhook = json_decode($response, true);

        if (!is_array($decodedWebhook)) {
            throw new Exception("Invalid webhook JSON.");
        }

        $topic = $decodedWebhook['shopify']['topic'] ?? '';

        if ($topic !== 'products/update') {
            throw new Exception("Unsupported Shopify webhook topic: " . $topic);
        }

        $product = $decodedWebhook['product'] ?? null;

        if (!is_array($product)) {
            throw new Exception("Shopify product data is missing.");
        }

        // 3. Get Shopify parent product ID.
        $rawProductId = $product['id'] ?? '';

        if ($rawProductId === '') {
            throw new Exception("Shopify product ID is missing.");
        }

        $shopifyProductGid = shopifyGid('Product', $rawProductId);

        $title = trim($product['title'] ?? '');

        if ($title === '') {
            throw new Exception("Product title is missing.");
        }

        $description = $product['body_html'] ?? '';
        $handle = trim($product['handle'] ?? '');

        $shopifyStatus = strtolower($product['status'] ?? 'active');
        $localStatus = ($shopifyStatus === 'active') ? 1 : 0;

        // 4. Get product image.
        $image = null;

        if (!empty($product['image']['src'])) {
            $image = $product['image']['src'];
        } elseif (!empty($product['images'][0]['src'])) {
            $image = $product['images'][0]['src'];
        }

        $variants = $product['variants'] ?? [];

        if (!is_array($variants)) {
            $variants = [];
        }

        // 5. Calculate parent product price using its cheapest variant.
        $parentPrice = 0;
        $parentOriginalPrice = null;
        $parentDiscount = 0;

        $cheapestPrice = null;

        foreach ($variants as $variant) {

            $variantPrice = (float) ($variant['price'] ?? 0);

            if ($cheapestPrice === null || $variantPrice < $cheapestPrice) {

                $cheapestPrice = $variantPrice;
                $parentPrice = $variantPrice;

                $comparePrice = $variant['compare_at_price'] ?? null;

                $parentOriginalPrice = (
                    $comparePrice !== null && $comparePrice !== ''
                ) ? (float) $comparePrice : null;
            }
        }

        if (
            $parentOriginalPrice !== null &&
            $parentOriginalPrice > $parentPrice &&
            $parentOriginalPrice > 0
        ) {
            $parentDiscount = round(
                (($parentOriginalPrice - $parentPrice) / $parentOriginalPrice) * 100,
                2
            );
        }

        // 6. Start database transaction.
        $db->beginTransaction();

        // 7. Find existing parent product using Shopify product ID.
        $stmt = $db->prepare("
            SELECT id
            FROM products
            WHERE shopify_product_id = :gid
               OR shopify_product_id = :raw_id
            LIMIT 1
        ");

        $stmt->execute([
            ':gid' => $shopifyProductGid,
            ':raw_id' => (string) $rawProductId
        ]);

        $existingProduct = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingProduct) {

            $localProductId = (int) $existingProduct['id'];
            $productAction = 'updated';

            $slug = createUniqueSlug(
                $db,
                $handle !== '' ? $handle : $title,
                $localProductId
            );

            // Update existing parent product.
            $stmt = $db->prepare("
                UPDATE products
                SET
                    shopify_product_id = :shopify_product_id,
                    shopify_status = :shopify_status,
                    shopify_synced_at = NOW(),
                    title = :title,
                    slug = :slug,
                    description = :description,
                    image = COALESCE(:image, image),
                    price = :price,
                    original_price = :original_price,
                    discount = :discount,
                    status = :status
                WHERE id = :id
            ");

            $stmt->execute([
                ':shopify_product_id' => $shopifyProductGid,
                ':shopify_status' => $shopifyStatus,
                ':title' => $title,
                ':slug' => $slug,
                ':description' => $description,
                ':image' => $image,
                ':price' => $parentPrice,
                ':original_price' => $parentOriginalPrice,
                ':discount' => $parentDiscount,
                ':status' => $localStatus,
                ':id' => $localProductId
            ]);
        } else {

            $productAction = 'created';

            $slug = createUniqueSlug(
                $db,
                $handle !== '' ? $handle : $title
            );

            // Create new parent product.
            $stmt = $db->prepare("
                INSERT INTO products (
                    shopify_product_id,
                    shopify_status,
                    shopify_synced_at,
                    sku,
                    title,
                    slug,
                    description,
                    image,
                    price,
                    original_price,
                    discount,
                    stock,
                    status
                ) VALUES (
                    :shopify_product_id,
                    :shopify_status,
                    NOW(),
                    NULL,
                    :title,
                    :slug,
                    :description,
                    :image,
                    :price,
                    :original_price,
                    :discount,
                    0,
                    :status
                )
            ");

            $stmt->execute([
                ':shopify_product_id' => $shopifyProductGid,
                ':shopify_status' => $shopifyStatus,
                ':title' => $title,
                ':slug' => $slug,
                ':description' => $description,
                ':image' => $image,
                ':price' => $parentPrice,
                ':original_price' => $parentOriginalPrice,
                ':discount' => $parentDiscount,
                ':status' => $localStatus
            ]);

            $localProductId = (int) $db->lastInsertId();
        }

        // 8. Synchronize Shopify variants into product_variants.
        $totalLocalVariantStock = 0;

        foreach ($variants as $variant) {

            try {

                $rawVariantId = $variant['id'] ?? '';

                if ($rawVariantId === '') {
                    throw new Exception("Shopify variant ID is missing.");
                }

                $shopifyVariantGid = shopifyGid(
                    'ProductVariant',
                    $rawVariantId
                );

                $rawInventoryItemId = $variant['inventory_item_id'] ?? '';

                $shopifyInventoryItemGid = $rawInventoryItemId !== ''
                    ? shopifyGid('InventoryItem', $rawInventoryItemId)
                    : null;

                $variantTitle = trim($variant['title'] ?? '');

                if ($variantTitle === '') {
                    $variantTitle = 'Default Title';
                }

                $sku = trim($variant['sku'] ?? '');
                $sku = ($sku !== '') ? $sku : null;

                $variantPrice = (float) ($variant['price'] ?? 0);

                $comparePrice = $variant['compare_at_price'] ?? null;

                $originalPrice = (
                    $comparePrice !== null && $comparePrice !== ''
                ) ? (float) $comparePrice : null;

                $variantStock = (int) ($variant['inventory_quantity'] ?? 0);

                // Build variant attributes from Shopify options.
                $attributes = [];

                $options = $product['options'] ?? [];

                foreach ($options as $index => $option) {

                    $optionName = trim($option['name'] ?? '');

                    $optionValue = trim(
                        $variant['option' . ($index + 1)] ?? ''
                    );

                    if (
                        $optionName !== '' &&
                        strtolower($optionName) !== 'title' &&
                        $optionValue !== ''
                    ) {
                        $attributes[$optionName] = $optionValue;
                    }
                }

                $attributesJson = !empty($attributes)
                    ? json_encode($attributes)
                    : null;

                // 9. First match using Shopify variant ID.
                $stmt = $db->prepare("
                    SELECT id
                    FROM product_variants
                    WHERE product_id = :product_id
                      AND (
                          shopify_variant_id = :variant_gid
                          OR shopify_variant_id = :raw_variant_id
                      )
                    LIMIT 1
                ");

                $stmt->execute([
                    ':product_id' => $localProductId,
                    ':variant_gid' => $shopifyVariantGid,
                    ':raw_variant_id' => (string) $rawVariantId
                ]);

                $existingVariant = $stmt->fetch(PDO::FETCH_ASSOC);

                // 10. If Shopify ID did not match, try SKU.
                if (!$existingVariant && $sku !== null) {

                    $stmt = $db->prepare("
                        SELECT id
                        FROM product_variants
                        WHERE product_id = :product_id
                          AND sku = :sku
                        LIMIT 1
                    ");

                    $stmt->execute([
                        ':product_id' => $localProductId,
                        ':sku' => $sku
                    ]);

                    $existingVariant = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                $localVariantId = $existingVariant
                    ? (int) $existingVariant['id']
                    : 0;

                // 11. Protect the unique SKU constraint.
                if ($sku !== null) {

                    $stmt = $db->prepare("
                        SELECT id, product_id
                        FROM product_variants
                        WHERE sku = :sku
                          AND id != :variant_id
                        LIMIT 1
                    ");

                    $stmt->execute([
                        ':sku' => $sku,
                        ':variant_id' => $localVariantId
                    ]);

                    $skuConflict = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($skuConflict) {
                        throw new Exception(
                            "SKU {$sku} already belongs to another local variant."
                        );
                    }
                }

                // 12. Update existing local variant.
                if ($existingVariant) {

                    $stmt = $db->prepare("
                        UPDATE product_variants
                        SET
                            variant_title = :variant_title,
                            sku = :sku,
                            price = :price,
                            original_price = :original_price,
                            stock = :stock,
                            attributes = :attributes,
                            status = :status,
                            shopify_variant_id = :shopify_variant_id,
                            shopify_inventory_item_id = :inventory_item_id,
                            shopify_sync_status = 'synced'
                        WHERE id = :id
                    ");

                    $stmt->execute([
                        ':variant_title' => $variantTitle,
                        ':sku' => $sku,
                        ':price' => $variantPrice,
                        ':original_price' => $originalPrice,
                        ':stock' => $variantStock,
                        ':attributes' => $attributesJson,
                        ':status' => $localStatus,
                        ':shopify_variant_id' => $shopifyVariantGid,
                        ':inventory_item_id' => $shopifyInventoryItemGid,
                        ':id' => $localVariantId
                    ]);

                    $result['updated_variants']++;
                } else {

                    // 13. Create a new local variant.
                    $stmt = $db->prepare("
                        INSERT INTO product_variants (
                            product_id,
                            variant_title,
                            sku,
                            price,
                            original_price,
                            stock,
                            attributes,
                            status,
                            shopify_variant_id,
                            shopify_inventory_item_id,
                            shopify_sync_status
                        ) VALUES (
                            :product_id,
                            :variant_title,
                            :sku,
                            :price,
                            :original_price,
                            :stock,
                            :attributes,
                            :status,
                            :shopify_variant_id,
                            :inventory_item_id,
                            'synced'
                        )
                    ");

                    $stmt->execute([
                        ':product_id' => $localProductId,
                        ':variant_title' => $variantTitle,
                        ':sku' => $sku,
                        ':price' => $variantPrice,
                        ':original_price' => $originalPrice,
                        ':stock' => $variantStock,
                        ':attributes' => $attributesJson,
                        ':status' => $localStatus,
                        ':shopify_variant_id' => $shopifyVariantGid,
                        ':inventory_item_id' => $shopifyInventoryItemGid
                    ]);

                    $result['created_variants']++;
                }

                $totalLocalVariantStock += $variantStock;
            } catch (Exception $variantError) {

                $result['failed_variants'][] = [
                    'sku' => $variant['sku'] ?? null,
                    'variant_id' => $variant['id'] ?? null,
                    'message' => $variantError->getMessage()
                ];
            }
        }

        // 14. Update parent stock with successfully synchronized variants.
        $stmt = $db->prepare("
            UPDATE products
            SET
                stock = :stock,
                shopify_synced_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            ':stock' => $totalLocalVariantStock,
            ':id' => $localProductId
        ]);

        // 15. Commit transaction.

        // 15. Commit local database transaction.
        $db->commit();

        // 16. Synchronize parent product with Zoho.
        $result['zoho_sync'] = [
            'parent' => null,
            'variants' => []
        ];

        try {

            $stmt = $db->prepare("
        SELECT
            id,
            title,
            sku,
            description,
            price,
            stock,
            zoho_item_id
        FROM products
        WHERE id = :id
        LIMIT 1
    ");

            $stmt->execute([':id' => $localProductId]);
            $localProduct = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($localProduct && !empty($localProduct['sku'])) {

                $parentZohoData = [
                    'name' => $localProduct['title'],
                    'sku' => $localProduct['sku'],
                    'description' => $localProduct['description'] ?? '',
                    'rate' => (float) $localProduct['price'],
                    'stock' => (float) $localProduct['stock']
                ];

                $savedZohoItemId = trim(
                    (string) ($localProduct['zoho_item_id'] ?? '')
                );

                $parentZohoResult = syncProductToZoho(
                    $parentZohoData,
                    $savedZohoItemId !== '' ? $savedZohoItemId : null
                );

                if (!empty($parentZohoResult['success'])) {

                    $zohoItemId = trim(
                        (string) ($parentZohoResult['item']['item_id'] ?? '')
                    );

                    if ($zohoItemId !== '') {

                        $updateStmt = $db->prepare("
                    UPDATE products
                    SET zoho_item_id = :zoho_item_id
                    WHERE id = :id
                ");

                        $updateStmt->execute([
                            ':zoho_item_id' => $zohoItemId,
                            ':id' => $localProductId
                        ]);
                    }
                }

                $result['zoho_sync']['parent'] = $parentZohoResult;
            } else {

                $result['zoho_sync']['parent'] = [
                    'success' => false,
                    'message' => 'Parent product SKU is missing. Parent Zoho item synchronization skipped.'
                ];
            }
        } catch (Throwable $zohoError) {

            $result['zoho_sync']['parent'] = [
                'success' => false,
                'message' => $zohoError->getMessage()
            ];
        }

        try {

            $stmt = $db->prepare("
        SELECT id
        FROM product_variants
        WHERE product_id = :product_id
        ORDER BY id ASC
    ");

            $stmt->execute([
                ':product_id' => $localProductId
            ]);

            $localVariants = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($localVariants as $localVariant) {

                $variantId = (int) $localVariant['id'];

                $variantZohoResult = syncVariantToZoho($variantId);

                $result['zoho_sync']['variants'][] = [
                    'variant_id' => $variantId,
                    'result' => $variantZohoResult
                ];
            }
        } catch (Throwable $zohoError) {

            $result['zoho_sync']['variants_error'] =
                $zohoError->getMessage();
        }

        $result['success'] = true;
        $result['message'] = 'Shopify to Local synchronization completed.';
        $result['product_action'] = $productAction;
        $result['product_id'] = $localProductId;
        $result['total_variant_stock'] = $totalLocalVariantStock;

        return $result;
    } catch (Exception $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $result['message'] = $e->getMessage();

        return $result;
    }
}

function findShopifyProductBySKU(string $sku)
{
    $sku = trim($sku);

    if ($sku === '') {
        return [
            'success' => false,
            'found' => false,
            'message' => 'SKU is required.',
            'product' => null
        ];
    }

    $query = <<<'GRAPHQL'
query FindProductBySKU($query: String!) {
    products(first: 10, query: $query) {
        nodes {
            id
            title
            handle
            status
            variants(first: 100) {
                nodes {
                    id
                    sku
                    price
                    compareAtPrice
                    inventoryQuantity
                    inventoryItem {
                        id
                        sku
                    }
                }
            }
        }
    }
}
GRAPHQL;

    $response = shopifyGraphQL($query, [
        'query' => 'sku:' . $sku
    ]);

    if (!empty($response['errors'])) {
        return [
            'success' => false,
            'found' => false,
            'message' => 'Shopify SKU lookup failed.',
            'product' => null,
            'errors' => $response['errors']
        ];
    }

    $shopifyData =
        $response['data']['data'] ?? [];

    $products =
        $shopifyData['products']['nodes'] ?? [];

    foreach ($products as $product) {

        $variants =
            $product['variants']['nodes'] ?? [];

        foreach ($variants as $variant) {

            if (
                trim((string) ($variant['sku'] ?? ''))
                === $sku
            ) {
                return [
                    'success' => true,
                    'found' => true,
                    'message' =>
                    'Shopify product found by SKU.',
                    'product' => $product,
                    'variant' => $variant
                ];
            }
        }
    }

    return [
        'success' => true,
        'found' => false,
        'message' =>
        'No Shopify product found with this SKU.',
        'product' => null,
        'variant' => null
    ];
}


function findShopifyVariantBySKU($shopifyProductId, string $sku)
{
    $sku = trim($sku);

    if ($sku === '') {
        return [
            'success' => false,
            'message' => 'Variant SKU is required.',
            'variant' => null
        ];
    }

    $productGid = shopifyGid('Product', $shopifyProductId);

    if ($productGid === '') {
        return [
            'success' => false,
            'message' => 'Invalid Shopify product ID.',
            'variant' => null
        ];
    }

    $query = <<<'GRAPHQL'
query GetVariantsForProduct($id: ID!) {
    product(id: $id) {
        id
        title
        variants(first: 100) {
            nodes {
                id
                sku
                price
                compareAtPrice
                inventoryItem {
                    id
                    tracked
                }
            }
        }
    }
}
GRAPHQL;

    $response = shopifyGraphQL($query, [
        'id' => $productGid
    ]);

    if (!empty($response['errors'])) {
        return [
            'success' => false,
            'message' => 'Unable to retrieve Shopify variants.',
            'errors' => $response['errors'],
            'variant' => null
        ];
    }

    $product = $response['data']['data']['product'] ?? null;

    if (!$product) {
        return [
            'success' => false,
            'message' => 'Shopify product not found.',
            'variant' => null
        ];
    }

    $matches = [];

    foreach (($product['variants']['nodes'] ?? []) as $variant) {
        if (trim((string) ($variant['sku'] ?? '')) === $sku) {
            $matches[] = $variant;
        }
    }

    if (count($matches) === 0) {
        return [
            'success' => false,
            'message' => 'Variant SKU was not found in this Shopify product.',
            'product' => $product,
            'variant' => null
        ];
    }

    if (count($matches) > 1) {
        return [
            'success' => false,
            'message' => 'Multiple Shopify variants have the same SKU.',
            'product' => $product,
            'variant' => null,
            'matches' => count($matches)
        ];
    }

    return [
        'success' => true,
        'message' => 'Shopify variant found successfully.',
        'product' => $product,
        'variant' => $matches[0]
    ];
}

function enableShopifyInventoryTracking($inventoryItemId)
{
    $query = <<<'GRAPHQL'
mutation InventoryItemUpdate(
    $id: ID!
    $input: InventoryItemInput!
) {
    inventoryItemUpdate(
        id: $id
        input: $input
    ) {
        inventoryItem {
            id
            tracked
            sku
        }

        userErrors {
            field
            message
        }
    }
}
GRAPHQL;

    $variables = [
        'id' => $inventoryItemId,

        'input' => [
            'tracked' => true
        ]
    ];

    $response = shopifyGraphQL(
        $query,
        $variables
    );

    if (!empty($response['errors'])) {
        return [
            'success' => false,
            'message' =>
            'Shopify inventory tracking update failed.',
            'errors' =>
            $response['errors']
        ];
    }

    $result =
        $response['data']['data']['inventoryItemUpdate']
        ?? null;

    if (!$result) {
        return [
            'success' => false,
            'message' =>
            'Shopify inventoryItemUpdate response was not returned.',
            'response' => $response
        ];
    }

    if (!empty($result['userErrors'])) {
        return [
            'success' => false,
            'message' =>
            'Unable to enable Shopify inventory tracking.',
            'errors' =>
            $result['userErrors']
        ];
    }

    return [
        'success' => true,
        'message' =>
        'Shopify inventory tracking enabled.',
        'data' =>
        $result['inventoryItem'] ?? null
    ];
}




function updateShopifyVariantInventory($inventoryItemId, $newQuantity)
{
    $inventoryItemId = trim((string) $inventoryItemId);
    $newQuantity = (int) $newQuantity;

    if ($inventoryItemId === '') {
        return [
            'success' => false,
            'message' => 'Shopify inventory item ID is required.'
        ];
    }

    if ($newQuantity < 0) {
        return [
            'success' => false,
            'message' => 'Inventory quantity cannot be negative.'
        ];
    }
    $locationInfo = getShopifyLocationId();

    if (empty($locationInfo['success'])) {
        return [
            'success' => false,
            'message' => $locationInfo['message']
                ?? 'Unable to retrieve Shopify location.',
            'errors' => $locationInfo['errors'] ?? []
        ];
    }

    $locationId = shopifyGid(
        'Location',
        $locationInfo['location_id']
    );

    $inventoryItemGid = shopifyGid(
        'InventoryItem',
        $inventoryItemId
    );
    $query = <<<'GRAPHQL'
query GetCurrentInventory(
    $inventoryItemId: ID!,
    $locationId: ID!
) {
    inventoryItem(id: $inventoryItemId) {
        id
        inventoryLevel(locationId: $locationId) {
            quantities(names: ["available"]) {
                name
                quantity
            }
        }
    }
}
GRAPHQL;

    $response = shopifyGraphQL($query, [
        'inventoryItemId' => $inventoryItemGid,
        'locationId' => $locationId
    ]);

    if (!empty($response['errors'])) {
        return [
            'success' => false,
            'message' => 'Unable to retrieve current Shopify inventory.',
            'errors' => $response['errors']
        ];
    }

    $inventoryItem = $response['data']['data']['inventoryItem'] ?? null;

    if (!$inventoryItem) {
        return [
            'success' => false,
            'message' => 'Shopify inventory item was not found.'
        ];
    }

    $inventoryLevel = $inventoryItem['inventoryLevel'] ?? null;

    if (!$inventoryLevel) {
        return [
            'success' => false,
            'message' => 'Inventory is not activated at the selected Shopify location.',
            'inventory_item_id' => $inventoryItemGid,
            'location_id' => $locationId
        ];
    }

    $currentQuantity = null;

    foreach ($inventoryLevel['quantities'] ?? [] as $quantityData) {
        if ($quantityData['name'] === 'available') {
            $currentQuantity = (int) $quantityData['quantity'];
            break;
        }
    }

    if ($currentQuantity === null) {
        return [
            'success' => false,
            'message' => 'Current available inventory quantity was not returned.'
        ];
    }
    $mutation = <<<'GRAPHQL'
mutation SetVariantInventory(
    $input: InventorySetQuantitiesInput!,
    $idempotencyKey: String!
) {
    inventorySetQuantities(input: $input)
        @idempotent(key: $idempotencyKey) {
        userErrors {
            field
            message
        }
        inventoryAdjustmentGroup {
            createdAt
            changes {
                name
                delta
            }
        }
    }
}
GRAPHQL;

    $variables = [
        'input' => [
            'name' => 'available',
            'reason' => 'correction',
            'quantities' => [[
                'inventoryItemId' => $inventoryItemGid,
                'locationId' => $locationId,
                'quantity' => $newQuantity,
                'changeFromQuantity' => $currentQuantity
            ]]
        ],
        'idempotencyKey' => bin2hex(random_bytes(16))
    ];

    $updateResponse = shopifyGraphQL($mutation, $variables);

    if (!empty($updateResponse['errors'])) {
        return [
            'success' => false,
            'message' => 'Shopify inventory request failed.',
            'errors' => $updateResponse['errors']
        ];
    }

    $result = $updateResponse['data']['data']['inventorySetQuantities']
        ?? null;

    if (!$result) {
        return [
            'success' => false,
            'message' => 'Shopify inventory response was not returned.',
            'response' => $updateResponse
        ];
    }

    if (!empty($result['userErrors'])) {
        return [
            'success' => false,
            'message' => 'Shopify inventory update failed.',
            'errors' => $result['userErrors']
        ];
    }

    return [
        'success' => true,
        'message' => 'Variant inventory updated successfully.',
        'inventory_item_id' => $inventoryItemGid,
        'location_id' => $locationId,
        'previous_quantity' => $currentQuantity,
        'new_quantity' => $newQuantity
    ];
}

function syncProductToShopify(array $product)
{
    $sku = trim((string) ($product['sku'] ?? ''));

    if ($sku === '') {
        return [
            'success' => false,
            'message' => 'SKU is required.',
            'action' => null,
            'data' => null
        ];
    }

    $title = trim((string) ($product['title'] ?? ''));

    if ($title === '') {
        return [
            'success' => false,
            'message' => 'Product title is required.',
            'action' => null,
            'data' => null
        ];
    }

    $price =
        isset($product['price']) &&
        is_numeric($product['price'])
        ? (float) $product['price']
        : 0;

    $originalPrice =
        isset($product['original_price']) &&
        $product['original_price'] !== '' &&
        is_numeric($product['original_price'])
        ? (float) $product['original_price']
        : $price;

    $stock =
        isset($product['stock']) &&
        is_numeric($product['stock'])
        ? (int) $product['stock']
        : 0;

    $status =
        !empty($product['status'])
        ? 1
        : 0;
    $savedProductId = trim(
        (string) ($product['shopify_product_id'] ?? '')
    );

    $existing = null;

    if ($savedProductId !== '') {

        $savedProductGid = shopifyGid(
            'Product',
            $savedProductId
        );

        $lookupQuery = <<<'GRAPHQL'
query GetSavedShopifyProduct($id: ID!) {
    product(id: $id) {
        id
        title
        variants(first: 1) {
            nodes {
                id
                sku
                price
                compareAtPrice
                inventoryItem {
                    id
                    tracked
                }
            }
        }
    }
}
GRAPHQL;

        $lookupResponse = shopifyGraphQL(
            $lookupQuery,
            ['id' => $savedProductGid]
        );

        if (!empty($lookupResponse['errors'])) {
            return [
                'success' => false,
                'message' => 'Unable to retrieve the saved Shopify product.',
                'errors' => $lookupResponse['errors']
            ];
        }

        $savedProduct =
            $lookupResponse['data']['data']['product'] ?? null;

        if ($savedProduct) {

            $savedVariant =
                $savedProduct['variants']['nodes'][0] ?? null;

            if (!$savedVariant) {
                return [
                    'success' => false,
                    'message' => 'Saved Shopify product has no default variant.'
                ];
            }

            $existing = [
                'success' => true,
                'found' => true,
                'product' => $savedProduct,
                'variant' => $savedVariant
            ];
        }
    }

    if ($existing === null) {
        $existing = findShopifyProductBySKU($sku);
    }

    if (!$existing['success']) {
        return [
            'success' => false,
            'message' =>
            $existing['message']
                ?? 'Shopify SKU lookup failed.',
            'action' => null,
            'errors' =>
            $existing['errors'] ?? []
        ];
    }
    if (!empty($existing['found'])) {

        $shopifyProduct =
            $existing['product'];

        $shopifyVariant =
            $existing['variant'];

        $shopifyProductId =
            $shopifyProduct['id'] ?? null;

        if (!$shopifyProductId) {
            return [
                'success' => false,
                'message' =>
                'Shopify product was found but Product ID is missing.',
                'action' => 'update'
            ];
        }

        $productUpdate =
            updateShopifyProduct(
                $shopifyProductId,
                [
                    'title' =>
                    $title,

                    'description' =>
                    $product['description'] ?? '',

                    'slug' =>
                    $product['slug'] ?? null,

                    'status' =>
                    $status
                ]
            );

        if (empty($productUpdate['success'])) {
            return [
                'success' => false,
                'message' =>
                'Shopify product update failed.',
                'action' => 'update',
                'shopify_product_id' =>
                $shopifyProductId,
                'errors' =>
                $productUpdate['errors'] ?? []
            ];
        }

        $variantUpdate =
            updateShopifyProductVariant(
                $shopifyProductId,
                $price,
                $originalPrice,
                $sku,
                $shopifyVariant['id'] ?? ''
            );

        $inventoryUpdate =
            updateShopifyInventory(
                $shopifyProductId,
                $stock
            );

        if (
            empty($variantUpdate['success']) ||
            empty($inventoryUpdate['success'])
        ) {
            return [
                'success' => false,
                'message' =>
                'Shopify product updated, but one or more additional sync operations failed.',
                'action' => 'update',
                'shopify_product_id' =>
                $shopifyProductId,
                'variant' =>
                $variantUpdate,
                'inventory' =>
                $inventoryUpdate
            ];
        }

        return [
            'success' => true,
            'message' =>
            'Shopify product found by SKU and updated successfully.',
            'action' => 'update',
            'data' => [
                'shopify_product_id' =>
                $shopifyProductId,

                'shopify_variant_id' =>
                $shopifyVariant['id'] ?? null,

                'sku' =>
                $sku,

                'price' =>
                $price,

                'original_price' =>
                $originalPrice,

                'stock' =>
                $stock
            ]
        ];
    }

    $createResult =
        createShopifyProduct([
            'title' =>
            $title,

            'description' =>
            $product['description'] ?? '',

            'slug' =>
            $product['slug'] ?? null,

            'status' =>
            $status
        ]);

    if (empty($createResult['success'])) {
        return [
            'success' => false,
            'message' =>
            'Unable to create Shopify product.',
            'action' => 'create',
            'errors' =>
            $createResult['errors'] ?? []
        ];
    }

    $shopifyProductId =
        $createResult['data']['id'] ?? null;

    if (!$shopifyProductId) {
        return [
            'success' => false,
            'message' =>
            'Shopify product was created but Product ID was not returned.',
            'action' => 'create'
        ];
    }

    $variantUpdate =
        updateShopifyProductVariant(
            $shopifyProductId,
            $price,
            $originalPrice,
            $sku
        );

    $inventoryUpdate =
        updateShopifyInventory(
            $shopifyProductId,
            $stock
        );

    if (
        empty($variantUpdate['success']) ||
        empty($inventoryUpdate['success'])
    ) {
        return [
            'success' => false,
            'message' =>
            'Shopify product was created, but variant or inventory sync failed.',
            'action' => 'create',
            'shopify_product_id' =>
            $shopifyProductId,
            'variant' =>
            $variantUpdate,
            'inventory' =>
            $inventoryUpdate
        ];
    }

    return [
        'success' => true,
        'message' =>
        'Shopify product created and fully synchronized successfully.',
        'action' => 'create',
        'data' => [
            'shopify_product_id' =>
            $shopifyProductId,

            'sku' =>
            $sku,

            'price' =>
            $price,

            'original_price' =>
            $originalPrice,

            'stock' =>
            $stock
        ]
    ];
}



function createShopifyVariantForParent($shopifyProductId, array $localVariant)
{
    $productGid = shopifyGid('Product', $shopifyProductId);

    $sku = trim((string) ($localVariant['sku'] ?? ''));
    $title = trim((string) ($localVariant['variant_title'] ?? ''));

    if ($sku === '' || $title === '') {
        return [
            'success' => false,
            'message' => 'Variant SKU and title are required.'
        ];
    }

    $price = number_format((float) ($localVariant['price'] ?? 0), 2, '.', '');
    $originalPrice = $localVariant['original_price'] ?? null;

    $compareAtPrice = (
        is_numeric($originalPrice) &&
        (float) $originalPrice > (float) $price
    )
        ? number_format((float) $originalPrice, 2, '.', '')
        : null;

    $mutation = <<<'GRAPHQL'
mutation CreateParentVariant(
    $productId: ID!,
    $variants: [ProductVariantsBulkInput!]!
) {
    productVariantsBulkCreate(
        productId: $productId,
        variants: $variants
    ) {
        productVariants {
            id
            title
            sku
            price
            compareAtPrice
            inventoryItem {
                id
                tracked
            }
        }
        userErrors {
            field
            message
        }
    }
}
GRAPHQL;

    $variantInput = [
        'price' => $price,
        'inventoryItem' => [
            'sku' => $sku,
            'tracked' => true
        ],
        'optionValues' => [
            [
                'optionName' => 'Title',
                'name' => $title
            ]
        ]
    ];

    if ($compareAtPrice !== null) {
        $variantInput['compareAtPrice'] = $compareAtPrice;
    }

    $response = shopifyGraphQL($mutation, [
        'productId' => $productGid,
        'variants' => [$variantInput]
    ]);

    if (!empty($response['errors'])) {
        return [
            'success' => false,
            'message' => 'Shopify GraphQL request failed.',
            'errors' => $response['errors']
        ];
    }

    $result = $response['data']['data']['productVariantsBulkCreate'] ?? null;

    if (!$result) {
        return [
            'success' => false,
            'message' => 'Shopify returned no creation result.',
            'response' => $response
        ];
    }

    if (!empty($result['userErrors'])) {
        return [
            'success' => false,
            'message' => 'Shopify could not create the variant.',
            'errors' => $result['userErrors']
        ];
    }

    $createdVariant = $result['productVariants'][0] ?? null;

    if (!$createdVariant || empty($createdVariant['id'])) {
        return [
            'success' => false,
            'message' => 'Shopify did not return the new variant ID.'
        ];
    }

    return [
        'success' => true,
        'variant' => $createdVariant
    ];
}



function syncParentProductVariantsToShopify($productId)
{
    global $db;

    $productId = (int) $productId;

    if ($productId <= 0) {
        return [
            'success' => false,
            'message' => 'Invalid local product ID.'
        ];
    }

    $stmt = $db->prepare("
        SELECT *
        FROM products
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        return [
            'success' => false,
            'message' => 'Local parent product was not found.'
        ];
    }
    $variantStmt = $db->prepare("
        SELECT *
        FROM product_variants
        WHERE product_id = :product_id
        ORDER BY id ASC
    ");

    $variantStmt->execute([':product_id' => $productId]);
    $variants = $variantStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$variants) {
        return [
            'success' => false,
            'message' => 'This product has no local variants. Use regular product sync.'
        ];
    }

    $shopifyProductId = trim(
        (string) ($product['shopify_product_id'] ?? '')
    );
    if ($shopifyProductId === '') {

        foreach ($variants as $localVariant) {

            $sku = trim((string) ($localVariant['sku'] ?? ''));

            if ($sku === '') {
                continue;
            }

            $lookup = findShopifyProductBySKU($sku);

            if (empty($lookup['success'])) {
                return [
                    'success' => false,
                    'message' => 'Shopify product lookup failed.',
                    'errors' => $lookup['errors'] ?? []
                ];
            }

            if (!empty($lookup['found'])) {
                $shopifyProductId = $lookup['product']['id'];
                break;
            }
        }
    }

    if ($shopifyProductId === '') {
        return [
            'success' => false,
            'message' => 'Shopify parent product was not found.',
            'product_id' => $productId
        ];
    }

    $shopifyProductGid = shopifyGid(
        'Product',
        $shopifyProductId
    );
    $query = <<<'GRAPHQL'
query GetParentVariants($id: ID!) {
    product(id: $id) {
        id
        title
        variants(first: 100) {
            nodes {
                id
                title
                sku
                price
                compareAtPrice
                inventoryItem {
                    id
                    sku
                    tracked
                }
            }
        }
    }
}
GRAPHQL;

    $response = shopifyGraphQL($query, [
        'id' => $shopifyProductGid
    ]);

    if (!empty($response['errors'])) {
        return [
            'success' => false,
            'message' => 'Unable to retrieve Shopify variants.',
            'errors' => $response['errors']
        ];
    }

    $shopifyProduct = $response['data']['data']['product'] ?? null;

    if (!$shopifyProduct) {
        return [
            'success' => false,
            'message' => 'Shopify parent product was not found.'
        ];
    }
    $parentUpdate = updateShopifyProduct(
        $shopifyProductGid,
        [
            'title' => $product['title'],
            'description' => $product['description'] ?? '',
            'slug' => $product['slug'] ?? '',
            'status' => $product['status']
        ]
    );

    if (empty($parentUpdate['success'])) {
        return [
            'success' => false,
            'message' => 'Shopify parent product details could not be updated.',
            'errors' => $parentUpdate['errors'] ?? [],
            'details' => $parentUpdate['message'] ?? ''
        ];
    }
    $shopifyBySku = [];

    foreach ($shopifyProduct['variants']['nodes'] ?? [] as $shopifyVariant) {

        $sku = trim((string) ($shopifyVariant['sku'] ?? ''));

        if ($sku !== '') {
            $shopifyBySku[$sku] = $shopifyVariant;
        }
    }

    $synced = [];
    $created = [];
    $failed = [];
    foreach ($variants as $localVariant) {

        $variantId = (int) $localVariant['id'];
        $sku = trim((string) ($localVariant['sku'] ?? ''));
        $variantTitle = trim(
            (string) ($localVariant['variant_title'] ?? '')
        );

        if ($sku === '') {
            $failed[] = [
                'variant_id' => $variantId,
                'message' => 'Local variant SKU is empty.'
            ];
            continue;
        }

        if ($variantTitle === '') {
            $failed[] = [
                'variant_id' => $variantId,
                'sku' => $sku,
                'message' => 'Local variant title is empty.'
            ];
            continue;
        }
        $shopifyVariant = null;
        $savedShopifyVariantId = trim(
            (string) ($localVariant['shopify_variant_id'] ?? '')
        );

        if ($savedShopifyVariantId !== '') {

            $savedShopifyVariantGid = shopifyGid(
                'ProductVariant',
                $savedShopifyVariantId
            );

            foreach ($shopifyProduct['variants']['nodes'] ?? [] as $existingVariant) {

                if (($existingVariant['id'] ?? '') === $savedShopifyVariantGid) {
                    $shopifyVariant = $existingVariant;
                    break;
                }
            }
        }
        if (!$shopifyVariant) {
            $shopifyVariant = $shopifyBySku[$sku] ?? null;
        }

        if (!$shopifyVariant) {

            $creationResult = createShopifyVariantForParent(
                $shopifyProductGid,
                $localVariant
            );

            if (empty($creationResult['success'])) {
                $failed[] = [
                    'variant_id' => $variantId,
                    'sku' => $sku,
                    'message' => $creationResult['message']
                        ?? 'Shopify variant creation failed.',
                    'details' => $creationResult['errors'] ?? []
                ];
                continue;
            }

            $shopifyVariant = $creationResult['variant'];

            $shopifyBySku[$sku] = $shopifyVariant;

            $created[] = [
                'variant_id' => $variantId,
                'sku' => $sku,
                'shopify_variant_id' => $shopifyVariant['id']
            ];
        }

        $shopifyVariantId = $shopifyVariant['id'] ?? '';
        $inventoryItemId =
            $shopifyVariant['inventoryItem']['id'] ?? '';

        if ($shopifyVariantId === '' || $inventoryItemId === '') {
            $failed[] = [
                'variant_id' => $variantId,
                'sku' => $sku,
                'message' => 'Shopify variant or inventory item ID is missing.'
            ];
            continue;
        }
        try {

            $mappingStmt = $db->prepare("
                UPDATE product_variants
                SET
                    shopify_variant_id = :variant_id,
                    shopify_inventory_item_id = :inventory_item_id,
                    shopify_sync_status = 'pending'
                WHERE id = :id
                  AND product_id = :product_id
            ");

            $mappingStmt->execute([
                ':variant_id' => $shopifyVariantId,
                ':inventory_item_id' => $inventoryItemId,
                ':id' => $variantId,
                ':product_id' => $productId
            ]);
        } catch (PDOException $e) {

            $failed[] = [
                'variant_id' => $variantId,
                'sku' => $sku,
                'message' => 'Unable to save Shopify variant mapping.',
                'details' => $e->getMessage()
            ];

            continue;
        }
        $variantUpdate = updateShopifyProductVariant(
            $shopifyProductGid,
            $localVariant['price'],
            $localVariant['original_price'] ?? null,
            $sku,
            $shopifyVariantId,
            $localVariant['variant_title'] ?? ''
        );

        if (empty($variantUpdate['success'])) {
            $failed[] = [
                'variant_id' => $variantId,
                'sku' => $sku,
                'message' => $variantUpdate['message']
                    ?? 'Shopify variant update failed.',
                'details' => $variantUpdate['errors'] ?? []
            ];
            continue;
        }

        $updatedVariant = $variantUpdate['data'] ?? [];
        $shopifyVariantId =
            $updatedVariant['id'] ?? $shopifyVariantId;

        $inventoryItemId =
            $updatedVariant['inventoryItem']['id']
            ?? $inventoryItemId;

        if ($inventoryItemId === '') {
            $failed[] = [
                'variant_id' => $variantId,
                'sku' => $sku,
                'message' => 'Shopify inventory item ID is missing.'
            ];
            continue;
        }
        $trackingResult = enableShopifyInventoryTracking(
            $inventoryItemId
        );

        if (empty($trackingResult['success'])) {
            $failed[] = [
                'variant_id' => $variantId,
                'sku' => $sku,
                'message' => 'Inventory tracking could not be enabled.',
                'details' => $trackingResult['errors'] ?? []
            ];
            continue;
        }
        $inventoryResult = updateShopifyVariantInventory(
            $inventoryItemId,
            (int) $localVariant['stock']
        );

        if (empty($inventoryResult['success'])) {
            $failed[] = [
                'variant_id' => $variantId,
                'sku' => $sku,
                'message' => $inventoryResult['message']
                    ?? 'Inventory update failed.',
                'details' => $inventoryResult['errors'] ?? []
            ];
            continue;
        }
        $saveStmt = $db->prepare("
            UPDATE product_variants
            SET
                shopify_variant_id = :variant_id,
                shopify_inventory_item_id = :inventory_item_id,
                shopify_sync_status = 'synced'
            WHERE id = :id
              AND product_id = :product_id
        ");

        $saveStmt->execute([
            ':variant_id' => $shopifyVariantId,
            ':inventory_item_id' => $inventoryItemId,
            ':id' => $variantId,
            ':product_id' => $productId
        ]);

        $synced[] = [
            'variant_id' => $variantId,
            'sku' => $sku,
            'shopify_variant_id' => $shopifyVariantId,
            'shopify_inventory_item_id' => $inventoryItemId,
            'stock' => (int) $localVariant['stock']
        ];
    }
    $stockStmt = $db->prepare("
        SELECT COALESCE(SUM(stock), 0)
        FROM product_variants
        WHERE product_id = :product_id
    ");

    $stockStmt->execute([':product_id' => $productId]);

    $totalStock = (int) $stockStmt->fetchColumn();

    $parentStockStmt = $db->prepare("
    UPDATE products
    SET
        stock = :stock,
        shopify_product_id = :shopify_product_id,
        shopify_status = :shopify_status,
        shopify_synced_at = NOW()
    WHERE id = :id
");

    $parentStockStmt->execute([
        ':stock' => $totalStock,
        ':shopify_product_id' => $shopifyProductGid,
        ':shopify_status' => !empty($product['status']) ? 'ACTIVE' : 'DRAFT',
        ':id' => $productId
    ]);

    $success = empty($failed)
        && count($synced) === count($variants);

    return [
        'success' => $success,
        'message' => $success
            ? 'All Shopify variants synchronized successfully.'
            : 'Sync partially completed. Review failures.',
        'product_id' => $productId,
        'shopify_product_id' => $shopifyProductGid,
        'parent_stock' => $totalStock,
        'created_variants' => $created,
        'synced_variants' => $synced,
        'failed_variants' => $failed
    ];
}
