<?php

require_once __DIR__ . '/config.php';


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
        curl_close($curl);

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

    curl_close($curl);

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
                inventoryQuantity
                inventoryItem {
                    id
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
            (int) $variant['inventoryQuantity'],

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
            !empty($location['isActive']) &&
            !empty($location['hasActiveInventory'])
        ) {
            return [
                'success' => true,
                'message' => 'Shopify active inventory location found.',
                'location_id' => $location['id'],
                'location_name' => $location['name'],
                'data' => $location
            ];
        }
    }
    foreach ($locations as $location) {

        if (!empty($location['isActive'])) {
            return [
                'success' => true,
                'message' => 'Shopify active location found.',
                'location_id' => $location['id'],
                'location_name' => $location['name'],
                'data' => $location
            ];
        }
    }

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
    $sku
) {
    $productGid = shopifyGid(
        'Product',
        $shopifyProductId
    );

    if ($productGid === '') {
        return [
            'success' => false,
            'message' => 'Shopify product ID is empty.'
        ];
    }
    $query = <<<'GRAPHQL'
query GetProductVariant($id: ID!) {
    product(id: $id) {
        id
        variants(first: 1) {
            nodes {
                id
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
            'message' => 'Shopify product lookup failed.',
            'errors' => $response['errors']
        ];
    }

    $shopifyData =
        $response['data']['data'] ?? [];
    if (
        empty($shopifyData['product']) ||
        empty($shopifyData['product']['variants']['nodes'][0])
    ) {
        return [
            'success' => false,
            'message' => 'Shopify product variant not found.',
            'product_id' => $productGid,
            'response' => $response
        ];
    }
    $variantId =
        $shopifyData['product']['variants']['nodes'][0]['id'];

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
            price
            compareAtPrice
            sku
            inventoryItem {
                id
                sku
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

        'variants' => [
            [
                'id' => $variantId,

                'price' =>
                number_format(
                    (float) $price,
                    2,
                    '.',
                    ''
                ),

                'compareAtPrice' =>
                !empty($originalPrice)
                    ? number_format(
                        (float) $originalPrice,
                        2,
                        '.',
                        ''
                    )
                    : null,

                'inventoryItem' => [
                    'sku' => $sku
                ]
            ]
        ]
    ];

    $response = shopifyGraphQL(
        $mutation,
        $variables
    );

    if (!empty($response['errors'])) {
        return [
            'success' => false,
            'message' => 'Shopify variant update failed.',
            'errors' => $response['errors']
        ];
    }

    $shopifyData =
        $response['data']['data'] ?? [];

    $result =
        $shopifyData['productVariantsBulkUpdate']
        ?? null;

    if (!$result) {
        return [
            'success' => false,
            'message' => 'Shopify variant update response was not returned.',
            'response' => $response
        ];
    }
    $userErrors =
        $result['userErrors'] ?? [];

    if (!empty($userErrors)) {
        return [
            'success' => false,
            'message' => 'Shopify variant update failed.',
            'errors' => $userErrors
        ];
    }

    return [
        'success' => true,
        'message' => 'Shopify price, original price and SKU updated.',
        'data' =>
        $result['productVariants'] ?? []
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

    $shopifyWebhookUrl =
        "https://baseavangers.topscripts.in/sumit_rana/offline/shopify_latest_webhook.json";

    $ch = curl_init($shopifyWebhookUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $jsonResponse = curl_exec($ch);

    if ($jsonResponse === false) {
        curl_close($ch);
        return [
            'success' => false,
            'message' => 'Unable to fetch Shopify webhook.'
        ];
    }

    curl_close($ch);

    $decodedWebhook = json_decode($jsonResponse, true);

    if (
        !is_array($decodedWebhook) ||
        empty($decodedWebhook['product']) ||
        !is_array($decodedWebhook['product'])
    ) {
        return [
            'success' => false,
            'message' => 'No Shopify product webhook data found.'
        ];
    }

    $shopifyProduct = $decodedWebhook['product'];

    $webhookTopic =
        $decodedWebhook['shopify']['topic'] ?? '';

    if (
        $webhookTopic !== '' &&
        $webhookTopic !== 'products/update'
    ) {
        return [
            'success' => false,
            'message' => 'Webhook topic is not products/update.'
        ];
    }

    $shopifyProductId =
        isset($shopifyProduct['id'])
        ? (string) $shopifyProduct['id']
        : null;

    $shopifyStatus =
        strtoupper(
            trim(
                $shopifyProduct['status'] ?? ''
            )
        );

    $variants =
        $shopifyProduct['variants'] ?? [];

    if (
        !is_array($variants) ||
        empty($variants)
    ) {
        return [
            'success' => false,
            'message' => 'No Shopify variants found.'
        ];
    }

    try {

        $db->beginTransaction();

        $updatedCount = 0;
        $insertedCount = 0;
        $skippedCount = 0;

        foreach ($variants as $variant) {

            $shopifySku =
                trim(
                    (string) (
                        $variant['sku'] ?? ''
                    )
                );

            if ($shopifySku === '') {
                $skippedCount++;
                continue;
            }

            $title =
                trim(
                    (string) (
                        $shopifyProduct['title'] ?? ''
                    )
                );

            if ($title === '') {
                $title = 'Shopify Product';
            }

            $description =
                $shopifyProduct['body_html'] ?? null;

            $shopifyHandle =
                trim(
                    (string) (
                        $shopifyProduct['handle'] ?? ''
                    )
                );

            $image = null;

            if (
                !empty($shopifyProduct['image']) &&
                is_array($shopifyProduct['image'])
            ) {
                $image =
                    $shopifyProduct['image']['src'] ?? null;
            }

            if (
                empty($image) &&
                !empty($shopifyProduct['images']) &&
                is_array($shopifyProduct['images'])
            ) {
                $image =
                    $shopifyProduct['images'][0]['src'] ?? null;
            }

            $priceValue =
                isset($variant['price']) &&
                is_numeric($variant['price'])
                ? (float) $variant['price']
                : 0.00;

            $compareAtPrice =
                $variant['compare_at_price'] ?? null;

            $originalPriceValue =
                (
                    $compareAtPrice !== null &&
                    $compareAtPrice !== '' &&
                    is_numeric($compareAtPrice)
                )
                ? (float) $compareAtPrice
                : $priceValue;

            $discount = 0;

            if (
                $originalPriceValue > 0 &&
                $originalPriceValue > $priceValue
            ) {
                $discount =
                    round(
                        (
                            (
                                $originalPriceValue -
                                $priceValue
                            )
                            /
                            $originalPriceValue
                        ) * 100,
                        2
                    );
            }

            $stock =
                isset($variant['inventory_quantity']) &&
                is_numeric($variant['inventory_quantity'])
                ? (int) $variant['inventory_quantity']
                : 0;

            $localStatus =
                strtolower(
                    $shopifyProduct['status'] ?? ''
                ) === 'active'
                ? 1
                : 0;
            $skuStmt = $db->prepare("
                SELECT
                    id,
                    slug,
                    image
                FROM products
                WHERE sku = :sku
                LIMIT 1
            ");

            $skuStmt->execute([
                ':sku' => $shopifySku
            ]);

            $existingProduct =
                $skuStmt->fetch(PDO::FETCH_ASSOC);
            if ($existingProduct) {

                $localProductId =
                    (int) $existingProduct['id'];

                if ($shopifyHandle !== '') {

                    $newSlug =
                        createUniqueSlug(
                            $db,
                            $shopifyHandle,
                            $localProductId
                        );
                } elseif (
                    !empty($existingProduct['slug'])
                ) {

                    $newSlug =
                        $existingProduct['slug'];
                } else {

                    $newSlug =
                        createUniqueSlug(
                            $db,
                            $title,
                            $localProductId
                        );
                }

                $imageValue =
                    !empty($image)
                    ? $image
                    : $existingProduct['image'];

                $updateStmt = $db->prepare("
                    UPDATE products
                    SET
                        shopify_product_id = :shopify_product_id,
                        shopify_status = :shopify_status,
                        shopify_synced_at = NOW(),

                        sku = :sku,
                        title = :title,
                        slug = :slug,
                        description = :description,
                        image = :image,

                        price = :price,
                        original_price = :original_price,
                        discount = :discount,
                        stock = :stock,
                        status = :status

                    WHERE id = :id
                ");

                $updateStmt->execute([

                    ':shopify_product_id' =>
                    $shopifyProductId,

                    ':shopify_status' =>
                    $shopifyStatus !== ''
                        ? $shopifyStatus
                        : null,

                    ':sku' =>
                    $shopifySku,

                    ':title' =>
                    $title,

                    ':slug' =>
                    $newSlug,

                    ':description' =>
                    $description,

                    ':image' =>
                    $imageValue,

                    ':price' =>
                    $priceValue,

                    ':original_price' =>
                    $originalPriceValue,

                    ':discount' =>
                    $discount,

                    ':stock' =>
                    $stock,

                    ':status' =>
                    $localStatus,

                    ':id' =>
                    $localProductId
                ]);

                $updatedCount++;
            } else {

                if ($shopifyHandle !== '') {

                    $newSlug =
                        createUniqueSlug(
                            $db,
                            $shopifyHandle
                        );
                } else {

                    $newSlug =
                        createUniqueSlug(
                            $db,
                            $title
                        );
                }

                $insertStmt = $db->prepare("
                    INSERT INTO products
                    (
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

                        category_id,
                        brand_id,
                        vendor_id,

                        status
                    )
                    VALUES
                    (
                        :shopify_product_id,
                        :shopify_status,
                        NOW(),

                        :sku,
                        :title,
                        :slug,
                        :description,
                        :image,

                        :price,
                        :original_price,
                        :discount,
                        :stock,

                        NULL,
                        NULL,
                        NULL,

                        :status
                    )
                ");

                $insertStmt->execute([

                    ':shopify_product_id' =>
                    $shopifyProductId,

                    ':shopify_status' =>
                    $shopifyStatus !== ''
                        ? $shopifyStatus
                        : null,

                    ':sku' =>
                    $shopifySku,

                    ':title' =>
                    $title,

                    ':slug' =>
                    $newSlug,

                    ':description' =>
                    $description,

                    ':image' =>
                    $image,

                    ':price' =>
                    $priceValue,

                    ':original_price' =>
                    $originalPriceValue,

                    ':discount' =>
                    $discount,

                    ':stock' =>
                    $stock,

                    ':status' =>
                    $localStatus
                ]);

                $insertedCount++;
            }
        }

        $db->commit();

        return [
            'success' => true,
            'message' =>
            "Shopify sync completed. " .
                $updatedCount .
                " product(s) updated, " .
                $insertedCount .
                " product(s) inserted.",
            'updated' => $updatedCount,
            'inserted' => $insertedCount,
            'skipped' => $skippedCount
        ];
    } catch (PDOException $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        return [
            'success' => false,
            'message' => 'Shopify synchronization failed.',
            'error' => $e->getMessage()
        ];
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

    /*
     * -----------------------------------------
     * 1. CHECK SKU IN SHOPIFY
     * -----------------------------------------
     */

    $existing =
        findShopifyProductBySKU($sku);

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

    /*
     * -----------------------------------------
     * 2. SKU EXISTS
     * -----------------------------------------
     */

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

        /*
         * Update product information
         */

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

        /*
         * Update price + original price + SKU
         */

        $variantUpdate =
            updateShopifyProductVariant(
                $shopifyProductId,
                $price,
                $originalPrice,
                $sku
            );

        /*
         * Update inventory
         */

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

    /*
     * -----------------------------------------
     * 3. SKU DOES NOT EXIST
     * -----------------------------------------
     */

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

    /*
     * Update variant:
     * SKU + price + original price
     */

    $variantUpdate =
        updateShopifyProductVariant(
            $shopifyProductId,
            $price,
            $originalPrice,
            $sku
        );

    /*
     * Update inventory
     */

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

