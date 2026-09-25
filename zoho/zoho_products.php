<?php

require_once __DIR__ . '/zoho_functions.php';


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
