
<?php   
function getBusinessSetting(PDO $db, string $key): ?string
{
    $stmt = $db->prepare("
        SELECT setting_value
        FROM business_settings
        WHERE setting_key = :setting_key
        LIMIT 1
    ");

    $stmt->execute([
        ':setting_key' => $key
    ]);

    $value = $stmt->fetchColumn();

    return $value === false ? null : (string) $value;
}
function getBusinessPickupLocation(PDO $db): array
{
    return [
        'business_name' => getBusinessSetting($db, 'business_name'),
        'city'          => getBusinessSetting($db, 'pickup_city'),
        'state'         => getBusinessSetting($db, 'pickup_state'),
        'pincode'       => getBusinessSetting($db, 'pickup_pincode'),
        'country'       => getBusinessSetting($db, 'pickup_country')
    ];
}
