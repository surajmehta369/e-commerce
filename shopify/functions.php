<?php

require_once __DIR__ . '/config.php';

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

function shopifyGraphQL(
    string $query,
    array $variables = []
) {

    $accessToken =
        getShopifyAccessToken();


    $payload = [
        'query' => $query
    ];


    if (!empty($variables)) {

        $payload['variables'] =
            $variables;
    }


    $curl = curl_init();

    curl_setopt_array($curl, [

        CURLOPT_URL =>
        SHOPIFY_GRAPHQL_URL,

        CURLOPT_RETURNTRANSFER =>
        true,

        CURLOPT_ENCODING => '',

        CURLOPT_MAXREDIRS => 10,

        CURLOPT_TIMEOUT => 30,

        CURLOPT_FOLLOWLOCATION =>
        true,

        CURLOPT_HTTP_VERSION =>
        CURL_HTTP_VERSION_1_1,

        CURLOPT_CUSTOMREQUEST =>
        'POST',

        CURLOPT_POSTFIELDS =>
        json_encode($payload),

        CURLOPT_HTTPHEADER => [

            'Content-Type: application/json',

            'Accept: application/json',

            'X-Shopify-Access-Token: ' .
                $accessToken
        ]
    ]);


    $response =
        curl_exec($curl);


    if ($response === false) {

        $error =
            curl_error($curl);

        

        throw new Exception(
            'Shopify API request failed: ' .
                $error
        );
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


    return [

        'http_code' =>
        $httpCode,

        'data' =>
        $data
    ];
}

function createShopifyProduct(array $product)
{
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
            'title' => $product['title'],
            'descriptionHtml' => $product['description'] ?? '',
            'handle' => $product['slug'] ?? null,
            'status' => !empty($product['status'])
                ? 'ACTIVE'
                : 'DRAFT'
        ]
    ];

    return shopifyGraphQL($query, $variables);
}


function getShopifyProductInventoryInfo($shopifyProductId)
{
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
        'id' => $shopifyProductId
    ]);
    $shopifyData = $response['data']['data'] ?? [];

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

    $product = $shopifyData['product'];
    $variant = $product['variants']['nodes'][0];

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
            'product_id' => $product['id'],
            'title' => $product['title'],
            'status' => $product['status'],
            'variant_id' => $variant['id'],
            'sku' => $variant['sku'],
            'inventory_quantity' => (int) $variant['inventoryQuantity'],
            'inventory_item_id' => $variant['inventoryItem']['id']
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
        }
    }
}
GRAPHQL;

    $response = shopifyGraphQL($query);

    $shopifyData = $response['data']['data'] ?? [];

    if (
        empty($shopifyData['locations']['nodes'])
    ) {
        return [
            'success' => false,
            'message' => 'No Shopify inventory location found.',
            'location_id' => null,
            'response' => $response
        ];
    }

    $location = $shopifyData['locations']['nodes'][0];

    return [
        'success' => true,
        'message' => 'Shopify location found.',
        'location_id' => $location['id'],
        'location_name' => $location['name']
    ];
}

function updateShopifyInventory($shopifyProductId, $newQuantity)
{
    $newQuantity = (int) $newQuantity;
    $inventoryInfo = getShopifyProductInventoryInfo($shopifyProductId);

    if (!$inventoryInfo['success']) {
        return $inventoryInfo;
    }

    $inventoryItemId = $inventoryInfo['data']['inventory_item_id'];
    $currentQuantity = $inventoryInfo['data']['inventory_quantity'];

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

    if (!$locationInfo['success']) {
        return $locationInfo;
    }

    $locationId = $locationInfo['location_id'];
    $idempotencyKey = 'inventory-' .
        preg_replace('/[^a-zA-Z0-9_-]/', '-', $shopifyProductId) .
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

    $response = shopifyGraphQL($query, $variables);

    if (!empty($response['errors'])) {
        return [
            'success' => false,
            'message' => 'Shopify GraphQL error.',
            'errors' => $response['errors']
        ];
    }
    $userErrors =
        $response['data']['inventorySetQuantities']['userErrors'] ?? [];

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
            $response['data']['inventorySetQuantities']['inventoryAdjustmentGroup']
                ?? null
        ]
    ];
}

function updateShopifyProductVariant(
    $shopifyProductId,
    $price,
    $originalPrice,
    $sku
) {

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
        'id' => $shopifyProductId
    ]);

    $shopifyData = $response['data']['data'] ?? [];

    if (
        empty($shopifyData['product']) ||
        empty($shopifyData['product']['variants']['nodes'][0])
    ) {
        return [
            'success' => false,
            'message' => 'Shopify product variant not found.'
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
        'productId' => $shopifyProductId,
        'variants' => [
            [
                'id' => $variantId,
                'price' => number_format((float) $price, 2, '.', ''),
                'compareAtPrice' =>
                !empty($originalPrice)
                    ? number_format((float) $originalPrice, 2, '.', '')
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


    $userErrors =
        $response['data']['data']['productVariantsBulkUpdate']['userErrors']
        ?? [];


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
        $response['data']['data']['productVariantsBulkUpdate']['productVariants']
            ?? []
    ];
}
