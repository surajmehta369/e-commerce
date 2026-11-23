<?php
function getOrderItemsForPacking(PDO $db, int $orderId): array
{
    $stmt = $db->prepare("
        SELECT
            id,
            order_id,
            product_id,
            variant_id,
            product_name,
            quantity,
            weight,
            length,
            width,
            height
        FROM order_items
        WHERE order_id = :order_id
        ORDER BY id ASC
    ");

    $stmt->execute([
        ':order_id' => $orderId
    ]);

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        throw new Exception(
            "No order items found for order #{$orderId}."
        );
    }

    return $items;
}

function validateOrderItemsForPacking(array $items): void
{
    foreach ($items as $item) {

        $name = $item['product_name'] ?? 'Unknown product';

        $quantity = (int) ($item['quantity'] ?? 0);

        $weight = (float) ($item['weight'] ?? 0);

        $length = (float) ($item['length'] ?? 0);

        $width = (float) ($item['width'] ?? 0);

        $height = (float) ($item['height'] ?? 0);

        if ($quantity <= 0) {
            throw new Exception(
                "Invalid quantity for product: {$name}."
            );
        }

        if (
            $weight <= 0 ||
            $length <= 0 ||
            $width <= 0 ||
            $height <= 0
        ) {
            throw new Exception(
                "Product '{$name}' has missing or invalid weight/dimensions. "
                    . "Please update its product or variant details before shipping."
            );
        }
    }
}
function getProductOrientations(array $item): array
{
    $length = (float) $item['length'];

    $width = (float) $item['width'];

    $height = (float) $item['height'];

    $orientations = [
        [$length, $width, $height],
        [$length, $height, $width],
        [$width, $length, $height],
        [$width, $height, $length],
        [$height, $length, $width],
        [$height, $width, $length]
    ];
    $unique = [];

    foreach ($orientations as $orientation) {

        $key = implode(
            '|',
            array_map(
                fn($value) => number_format($value, 2, '.', ''),
                $orientation
            )
        );

        $unique[$key] = $orientation;
    }

    return array_values($unique);
}
function expandOrderItemsIntoUnits(array $items): array
{
    $units = [];

    foreach ($items as $item) {

        $quantity = (int) $item['quantity'];

        for ($i = 1; $i <= $quantity; $i++) {

            $units[] = [
                'order_item_id' => (int) $item['id'],
                'product_id'    => (int) $item['product_id'],
                'variant_id'    => $item['variant_id'] !== null
                    ? (int) $item['variant_id']
                    : null,

                'product_name' => $item['product_name'],

                'unit_number' => $i,

                'weight' => (float) $item['weight'],

                'length' => (float) $item['length'],
                'width'  => (float) $item['width'],
                'height' => (float) $item['height'],

                'orientations' => getProductOrientations($item)
            ];
        }
    }
    usort($units, function ($a, $b) {

        $volumeA =
            $a['length'] * $a['width'] * $a['height'];

        $volumeB =
            $b['length'] * $b['width'] * $b['height'];

        return $volumeB <=> $volumeA;
    });

    return $units;
}
function packingItemFits(
    array $orientation,
    array $space
): bool {
    return (
        $orientation[0] <= $space['length'] &&
        $orientation[1] <= $space['width'] &&
        $orientation[2] <= $space['height']
    );
}
function findBestOrientationForSpace(
    array $unit,
    array $space
): ?array {

    $bestOrientation = null;
    $bestScore = null;

    foreach ($unit['orientations'] as $orientation) {

        if (!packingItemFits($orientation, $space)) {
            continue;
        }

        $remainingLength =
            $space['length'] - $orientation[0];

        $remainingWidth =
            $space['width'] - $orientation[1];

        $remainingHeight =
            $space['height'] - $orientation[2];

        $score =
            ($remainingLength * $space['width'] * $space['height']) +
            ($orientation[0] * $remainingWidth * $space['height']) +
            ($orientation[0] * $orientation[1] * $remainingHeight);

        if (
            $bestScore === null ||
            $score < $bestScore
        ) {
            $bestScore = $score;
            $bestOrientation = $orientation;
        }
    }

    return $bestOrientation;
}
function splitPackingFreeSpace(
    array $space,
    array $placed
): array {

    $remainingSpaces = [];

    $remainingLength =
        $space['length'] - $placed['length'];

    $remainingWidth =
        $space['width'] - $placed['width'];

    $remainingHeight =
        $space['height'] - $placed['height'];
    if ($remainingLength > 0.001) {

        $remainingSpaces[] = [
            'x' => $space['x'] + $placed['length'],
            'y' => $space['y'],
            'z' => $space['z'],

            'length' => $remainingLength,
            'width' => $space['width'],
            'height' => $space['height']
        ];
    }
    if ($remainingWidth > 0.001) {

        $remainingSpaces[] = [
            'x' => $space['x'],
            'y' => $space['y'] + $placed['width'],
            'z' => $space['z'],

            'length' => $placed['length'],
            'width' => $remainingWidth,
            'height' => $space['height']
        ];
    }
    if ($remainingHeight > 0.001) {

        $remainingSpaces[] = [
            'x' => $space['x'],
            'y' => $space['y'],
            'z' => $space['z'] + $placed['height'],

            'length' => $placed['length'],
            'width' => $placed['width'],
            'height' => $remainingHeight
        ];
    }

    return $remainingSpaces;
}
function removeContainedPackingSpaces(array $spaces): array
{
    $filtered = [];

    foreach ($spaces as $i => $space) {

        $contained = false;

        foreach ($spaces as $j => $other) {

            if ($i === $j) {
                continue;
            }

            $inside =
                $space['x'] >= $other['x'] - 0.001 &&
                $space['y'] >= $other['y'] - 0.001 &&
                $space['z'] >= $other['z'] - 0.001 &&

                $space['x'] + $space['length']
                <= $other['x'] + $other['length'] + 0.001 &&

                $space['y'] + $space['width']
                <= $other['y'] + $other['width'] + 0.001 &&

                $space['z'] + $space['height']
                <= $other['z'] + $other['height'] + 0.001;

            if ($inside) {
                $contained = true;
                break;
            }
        }

        if (!$contained) {
            $filtered[] = $space;
        }
    }

    return $filtered;
}
function tryPackUnitsIntoBox(
    array $units,
    array $box
): ?array {

    $boxLength = (float) $box['internal_length'];
    $boxWidth  = (float) $box['internal_width'];
    $boxHeight = (float) $box['internal_height'];

    $maxWeight = (float) $box['max_weight'];

    $emptyWeight = (float) $box['empty_weight'];

    $productWeight = 0;

    foreach ($units as $unit) {
        $productWeight += $unit['weight'];
    }

    $totalWeight = $productWeight + $emptyWeight;

    if ($totalWeight > $maxWeight) {
        return null;
    }

    $freeSpaces = [[
        'x' => 0,
        'y' => 0,
        'z' => 0,

        'length' => $boxLength,
        'width' => $boxWidth,
        'height' => $boxHeight
    ]];

    $placements = [];

    foreach ($units as $unitIndex => $unit) {

        $bestChoice = null;

        $bestScore = null;

        foreach ($freeSpaces as $spaceIndex => $space) {

            $orientation = findBestOrientationForSpace(
                $unit,
                $space
            );

            if ($orientation === null) {
                continue;
            }

            $remainingLength =
                $space['length'] - $orientation[0];

            $remainingWidth =
                $space['width'] - $orientation[1];

            $remainingHeight =
                $space['height'] - $orientation[2];

            $score =
                ($remainingLength * $space['width'] * $space['height']) +
                ($orientation[0] * $remainingWidth * $space['height']) +
                ($orientation[0] * $orientation[1] * $remainingHeight);

            if (
                $bestScore === null ||
                $score < $bestScore
            ) {
                $bestScore = $score;

                $bestChoice = [
                    'space_index' => $spaceIndex,
                    'space' => $space,
                    'orientation' => $orientation
                ];
            }
        }

        if ($bestChoice === null) {
            return null;
        }

        $space = $bestChoice['space'];

        $orientation = $bestChoice['orientation'];

        $placed = [
            'order_item_id' => $unit['order_item_id'],
            'product_id' => $unit['product_id'],
            'variant_id' => $unit['variant_id'],
            'product_name' => $unit['product_name'],

            'unit_number' => $unit['unit_number'],

            'weight' => $unit['weight'],

            'x' => $space['x'],
            'y' => $space['y'],
            'z' => $space['z'],

            'length' => $orientation[0],
            'width' => $orientation[1],
            'height' => $orientation[2]
        ];

        $placements[] = $placed;
        unset($freeSpaces[$bestChoice['space_index']]);

        $freeSpaces = array_values($freeSpaces);
        $newSpaces = splitPackingFreeSpace(
            $space,
            $placed
        );

        $freeSpaces = array_merge(
            $freeSpaces,
            $newSpaces
        );
        $freeSpaces = array_filter(
            $freeSpaces,
            function ($space) {
                return (
                    $space['length'] > 0.001 &&
                    $space['width'] > 0.001 &&
                    $space['height'] > 0.001
                );
            }
        );

        $freeSpaces = removeContainedPackingSpaces(
            array_values($freeSpaces)
        );
    }

    return [
        'box' => $box,

        'placements' => $placements,

        'product_weight' => $productWeight,

        'packaging_weight' => $emptyWeight,

        'total_weight' => $totalWeight
    ];
}
function getActivePackagingBoxes(PDO $db): array
{
    $stmt = $db->query("
        SELECT
            id,
            box_name,

            internal_length,
            internal_width,
            internal_height,

            external_length,
            external_width,
            external_height,

            max_weight,
            empty_weight,
            packaging_cost

        FROM packaging_boxes
        WHERE status = 1
    ");

    $boxes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($boxes as &$box) {

        $box['id'] = (int) $box['id'];

        $box['internal_length'] =
            (float) $box['internal_length'];

        $box['internal_width'] =
            (float) $box['internal_width'];

        $box['internal_height'] =
            (float) $box['internal_height'];

        $box['max_weight'] =
            (float) $box['max_weight'];

        $box['empty_weight'] =
            (float) $box['empty_weight'];

        $box['packaging_cost'] =
            (float) $box['packaging_cost'];

        $box['external_length'] =
            (float) $box['external_length'];

        $box['external_width'] =
            (float) $box['external_width'];

        $box['external_height'] =
            (float) $box['external_height'];

        $box['internal_volume'] =
            $box['internal_length'] *
            $box['internal_width'] *
            $box['internal_height'];
    }

    unset($box);

    usort($boxes, function ($a, $b) {

        if ($a['internal_volume'] != $b['internal_volume']) {
            return $a['internal_volume']
                <=> $b['internal_volume'];
        }

        return $a['packaging_cost']
            <=> $b['packaging_cost'];
    });

    if (empty($boxes)) {
        throw new Exception(
            "No active packaging boxes are available."
        );
    }

    return $boxes;
}
function findBestPackageForBox(
    array $units,
    array $box
): ?array {

    $selectedUnits = [];

    $selectedIndexes = [];

    $bestPacking = null;

    foreach ($units as $unitIndex => $unit) {

        $trialUnits = $selectedUnits;

        $trialUnits[] = $unit;

        $packing = tryPackUnitsIntoBox(
            $trialUnits,
            $box
        );

        if ($packing === null) {
            continue;
        }

        $selectedUnits = $trialUnits;

        $selectedIndexes[] = $unitIndex;

        $bestPacking = $packing;
    }

    if ($bestPacking === null) {
        return null;
    }

    return [
        'packing' => $bestPacking,
        'selected_indexes' => $selectedIndexes,
        'unit_count' => count($selectedUnits),
        'box_cost' => (float) $box['packaging_cost'],
        'box_volume' => (float) $box['internal_volume']
    ];
}
function selectBestPackagingBox(
    array $units,
    array $boxes
): ?array {

    $bestChoice = null;

    foreach ($boxes as $box) {

        $candidate = findBestPackageForBox(
            $units,
            $box
        );

        if ($candidate === null) {
            continue;
        }

        if ($bestChoice === null) {

            $bestChoice = $candidate;

            continue;
        }

        if (
            $candidate['unit_count'] >
            $bestChoice['unit_count']
        ) {

            $bestChoice = $candidate;

            continue;
        }

        if (
            $candidate['unit_count'] ===
            $bestChoice['unit_count']
        ) {

            if (
                $candidate['box_cost'] <
                $bestChoice['box_cost']
            ) {

                $bestChoice = $candidate;

                continue;
            }

            if (
                $candidate['box_cost'] ===
                $bestChoice['box_cost'] &&
                $candidate['box_volume'] <
                $bestChoice['box_volume']
            ) {

                $bestChoice = $candidate;
            }
        }
    }

    return $bestChoice;
}
function groupPackageItemsByOrderItem(
    array $placements
): array {

    $grouped = [];

    foreach ($placements as $placement) {

        $orderItemId = (int) $placement['order_item_id'];

        if (!isset($grouped[$orderItemId])) {

            $grouped[$orderItemId] = [
                'order_item_id' => $orderItemId,
                'product_id' => (int) $placement['product_id'],
                'variant_id' => $placement['variant_id'],
                'quantity' => 0
            ];
        }

        $grouped[$orderItemId]['quantity']++;
    }

    return array_values($grouped);
}
function packOrderIntoMultipleBoxes(
    PDO $db,
    int $orderId
): array {

    if ($orderId <= 0) {
        throw new InvalidArgumentException(
            "Invalid order ID."
        );
    }

    // Load and validate order items.
    $items = getOrderItemsForPacking(
        $db,
        $orderId
    );

    validateOrderItemsForPacking($items);
    $units = expandOrderItemsIntoUnits($items);
    $boxes = getActivePackagingBoxes($db);

    $remainingUnits = $units;

    $packages = [];

    $packageNumber = 1;

    while (!empty($remainingUnits)) {

        $bestChoice = selectBestPackagingBox(
            $remainingUnits,
            $boxes
        );

        if ($bestChoice === null) {

            $unit = reset($remainingUnits);

            throw new Exception(
                "Unable to find a suitable packaging box for product: "
                    . $unit['product_name']
                    . ". Please check product dimensions and packaging box capacity."
            );
        }

        $packing = $bestChoice['packing'];

        $box = $packing['box'];

        $placements = $packing['placements'];

        $packages[] = [

            'package_number' => $packageNumber,

            'box_id' => (int) $box['id'],

            'box_name' => $box['box_name'],
            'length' => (float) $box['external_length'],
            'width' => (float) $box['external_width'],
            'height' => (float) $box['external_height'],

            'product_weight' =>
            (float) $packing['product_weight'],

            'packaging_weight' =>
            (float) $packing['packaging_weight'],

            'total_weight' =>
            (float) $packing['total_weight'],

            'packaging_cost' =>
            (float) $box['packaging_cost'],

            'items' =>
            groupPackageItemsByOrderItem($placements),
            'placements' => $placements
        ];
        foreach ($bestChoice['selected_indexes'] as $index) {
            unset($remainingUnits[$index]);
        }

        $packageNumber++;
    }

    $totalWeight = 0;

    $totalPackagingCost = 0;

    foreach ($packages as $package) {

        $totalWeight += $package['total_weight'];

        $totalPackagingCost += $package['packaging_cost'];
    }

    return [

        'order_id' => $orderId,

        'total_packages' => count($packages),

        'total_weight' => $totalWeight,

        'total_packaging_cost' => $totalPackagingCost,

        'packages' => $packages
    ];
}


function saveOrderPackages(
    PDO $db,
    array $packingPlan,
    bool $commit = false
): array {
    $orderId = (int) ($packingPlan['order_id'] ?? 0);
    $packages = $packingPlan['packages'] ?? [];

    if ($orderId <= 0 || empty($packages)) {
        throw new Exception("Invalid packing plan.");
    }

    if ($db->inTransaction()) {
        throw new Exception(
            "Cannot save packages: another transaction is already active."
        );
    }
    $checkStmt = $db->prepare("
        SELECT COUNT(*)
        FROM order_packages
        WHERE order_id = :order_id
    ");
    $checkStmt->execute([':order_id' => $orderId]);

    if ((int) $checkStmt->fetchColumn() > 0) {
        throw new Exception(
            "Packages already exist for order #{$orderId}."
        );
    }

    $savedPackageIds = [];

    try {
        $db->beginTransaction();

        $packageStmt = $db->prepare("
            INSERT INTO order_packages (
                order_id,
                shipment_id,
                box_id,
                package_number,
                length,
                width,
                height,
                product_weight,
                packaging_weight,
                total_weight,
                package_status
            ) VALUES (
                :order_id,
                NULL,
                :box_id,
                :package_number,
                :length,
                :width,
                :height,
                :product_weight,
                :packaging_weight,
                :total_weight,
                'packed'
            )
        ");

        $itemStmt = $db->prepare("
            INSERT INTO order_package_items (
                package_id,
                order_item_id,
                product_id,
                variant_id,
                quantity
            ) VALUES (
                :package_id,
                :order_item_id,
                :product_id,
                :variant_id,
                :quantity
            )
        ");

        foreach ($packages as $package) {

            $packageStmt->execute([
                ':order_id' => $orderId,
                ':box_id' => $package['box_id'],
                ':package_number' => $package['package_number'],
                ':length' => $package['length'],
                ':width' => $package['width'],
                ':height' => $package['height'],
                ':product_weight' => $package['product_weight'],
                ':packaging_weight' => $package['packaging_weight'],
                ':total_weight' => $package['total_weight']
            ]);

            $packageId = (int) $db->lastInsertId();

            $savedPackageIds[] = $packageId;

            foreach ($package['items'] as $item) {

                $itemStmt->execute([
                    ':package_id' => $packageId,
                    ':order_item_id' => $item['order_item_id'],
                    ':product_id' => $item['product_id'],
                    ':variant_id' => $item['variant_id'],
                    ':quantity' => $item['quantity']
                ]);
            }
        }

        if ($commit) {
            $db->commit();
        } else {
            $db->rollBack();
        }

        return [
            'success' => true,
            'test_mode' => !$commit,
            'order_id' => $orderId,
            'total_packages' => count($savedPackageIds),
            'temporary_package_ids' => $savedPackageIds,
            'message' => $commit
                ? 'Package records saved successfully.'
                : 'Package insert test passed. All test records were rolled back.'
        ];
    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $e;
    }
}
function getEligibleCourierServices(
    PDO $db,
    int $orderId,
    array $packingPlan
): array {
    require_once __DIR__ . "/settings.php";

    $pickup = getBusinessPickupLocation($db);

    $pickupPincode = trim($pickup['pincode'] ?? '');

    if ($pickupPincode === '') {
        throw new Exception("Business pickup pincode is missing.");
    }
    $stmt = $db->prepare("
        SELECT shipping_pincode
        FROM orders
        WHERE id = :order_id
        LIMIT 1
    ");

    $stmt->execute([':order_id' => $orderId]);

    $deliveryPincode = trim((string) $stmt->fetchColumn());

    if ($deliveryPincode === '') {
        throw new Exception("Customer delivery pincode is missing.");
    }

    $packages = $packingPlan['packages'] ?? [];

    if (empty($packages)) {
        throw new Exception("No packages available for courier assignment.");
    }

    $totalWeight = (float) $packingPlan['total_weight'];
    $totalPackages = count($packages);
    $stmt = $db->query("
        SELECT
            cs.*,
            cc.company_name,
            cc.reliability_score
        FROM courier_services cs
        INNER JOIN courier_companies cc
            ON cc.id = cs.courier_company_id
        WHERE cs.status = 1
          AND cc.status = 1
    ");

    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $eligibleServices = [];

    foreach ($services as $service) {
        if (
            $totalWeight > (float) $service['max_total_weight'] ||
            $totalPackages > (int) $service['max_packages']
        ) {
            continue;
        }
        $allPackagesFit = true;

        foreach ($packages as $package) {

            if (
                (float) $package['total_weight'] >
                (float) $service['max_weight_per_package'] ||

                (float) $package['length'] >
                (float) $service['max_length'] ||

                (float) $package['width'] >
                (float) $service['max_width'] ||

                (float) $package['height'] >
                (float) $service['max_height']
            ) {
                $allPackagesFit = false;
                break;
            }
        }

        if (!$allPackagesFit) {
            continue;
        }
        $pincodeStmt = $db->prepare("
            SELECT
                pincode,
                can_pickup,
                can_deliver
            FROM courier_service_pincodes
            WHERE courier_service_id = :service_id
              AND status = 1
              AND pincode IN (:pickup, :delivery)
        ");

        $pincodeStmt->execute([
            ':service_id' => $service['id'],
            ':pickup' => $pickupPincode,
            ':delivery' => $deliveryPincode
        ]);

        $pincodeRows = $pincodeStmt->fetchAll(PDO::FETCH_ASSOC);

        $pincodeMap = [];

        foreach ($pincodeRows as $row) {
            $pincodeMap[$row['pincode']] = $row;
        }
        if (
            !isset($pincodeMap[$pickupPincode]) ||
            (int) $pincodeMap[$pickupPincode]['can_pickup'] !== 1 ||
            !isset($pincodeMap[$deliveryPincode]) ||
            (int) $pincodeMap[$deliveryPincode]['can_deliver'] !== 1
        ) {
            continue;
        }
        $weightInKg = $totalWeight / 1000;

        $estimatedCost =
            (float) $service['base_rate'] +
            ($weightInKg * (float) $service['per_kg_rate']);

        $eligibleServices[] = [
            'courier_service_id' => (int) $service['id'],
            'courier_company_id' => (int) $service['courier_company_id'],
            'company_name' => $service['company_name'],
            'service_name' => $service['service_name'],
            'vehicle_type' => $service['vehicle_type'],
            'estimated_cost' => round($estimatedCost, 2),
            'estimated_delivery_days' =>
            (int) $service['estimated_delivery_days'],
            'reliability_score' =>
            (float) $service['reliability_score'],
            'total_packages' => $totalPackages,
            'total_weight' => $totalWeight
        ];
    }

    return $eligibleServices;
}
function rankCourierServices(array $eligibleServices): array
{
    if (empty($eligibleServices)) {
        return [];
    }

    $costs = array_column($eligibleServices, 'estimated_cost');
    $deliveryDays = array_column(
        $eligibleServices,
        'estimated_delivery_days'
    );
    $reliability = array_column(
        $eligibleServices,
        'reliability_score'
    );

    $minMaxScore = function (
        float $value,
        float $min,
        float $max,
        bool $lowerIsBetter
    ): float {
        if ($max == $min) {
            return 100;
        }

        if ($lowerIsBetter) {
            return (($max - $value) / ($max - $min)) * 100;
        }

        return (($value - $min) / ($max - $min)) * 100;
    };

    $minCost = min($costs);
    $maxCost = max($costs);

    $minDays = min($deliveryDays);
    $maxDays = max($deliveryDays);

    $minReliability = min($reliability);
    $maxReliability = max($reliability);

    foreach ($eligibleServices as &$service) {

        $costScore = $minMaxScore(
            (float) $service['estimated_cost'],
            (float) $minCost,
            (float) $maxCost,
            true
        );

        $speedScore = $minMaxScore(
            (float) $service['estimated_delivery_days'],
            (float) $minDays,
            (float) $maxDays,
            true
        );

        $reliabilityScore = $minMaxScore(
            (float) $service['reliability_score'],
            (float) $minReliability,
            (float) $maxReliability,
            false
        );

        $finalScore =
            ($costScore * 0.40) +
            ($speedScore * 0.30) +
            ($reliabilityScore * 0.30);

        $service['cost_score'] = round($costScore, 2);
        $service['speed_score'] = round($speedScore, 2);
        $service['reliability_component_score'] =
            round($reliabilityScore, 2);

        $service['assignment_score'] = round($finalScore, 2);

        $service['assignment_reason'] =
            "Balanced selection: 40% cost, 30% delivery speed, " .
            "30% reliability.";
    }

    unset($service);
    usort($eligibleServices, function ($a, $b) {
        return $b['assignment_score'] <=>
            $a['assignment_score'];
    });

    return $eligibleServices;
}
function assignCourierAndCreateShipment(
    PDO $db,
    int $orderId,
    ?string $newOrderStatus = null
): array {
    $ownsTransaction = !$db->inTransaction();

    try {

        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        $stmt = $db->prepare("
            SELECT id
            FROM orders
            WHERE id = :order_id
            FOR UPDATE
        ");

        $stmt->execute([
            ':order_id' => $orderId
        ]);

        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException("Order not found.");
        }
        $stmt = $db->prepare("
            SELECT id
            FROM order_shipments
            WHERE order_id = :order_id
            LIMIT 1
        ");

        $stmt->execute([
            ':order_id' => $orderId
        ]);

        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException(
                "A shipment already exists for this order."
            );
        }
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM order_packages
            WHERE order_id = :order_id
        ");

        $stmt->execute([
            ':order_id' => $orderId
        ]);

        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException(
                "Packages already exist for this order."
            );
        }
        $packingPlan = packOrderIntoMultipleBoxes(
            $db,
            $orderId
        );
        $eligibleServices = getEligibleCourierServices(
            $db,
            $orderId,
            $packingPlan
        );

        $rankedServices = rankCourierServices(
            $eligibleServices
        );

        if (empty($rankedServices)) {
            throw new RuntimeException(
                "No eligible courier service is available."
            );
        }
        $selectedCourier = $rankedServices[0];

        $now = date('Y-m-d H:i:s');

        $estimatedDeliveryAt = date(
            'Y-m-d H:i:s',
            strtotime(
                '+' .
                    (int) $selectedCourier['estimated_delivery_days'] .
                    ' days'
            )
        );
        $trackingNumber =
            'MYSHOP-' .
            date('Ymd') .
            '-' .
            $orderId .
            '-' .
            strtoupper(bin2hex(random_bytes(4)));
        $stmt = $db->prepare("
            INSERT INTO order_shipments (
                order_id,
                courier_name,
                courier_service_id,
                tracking_number,
                shipped_at,
                estimated_delivery_at,
                shipment_status,
                total_packages,
                total_weight,
                courier_cost,
                assignment_score,
                assignment_reason,
                assigned_at
            ) VALUES (
                :order_id,
                :courier_name,
                :courier_service_id,
                :tracking_number,
                NULL,
                :estimated_delivery_at,
                'pending',
                :total_packages,
                :total_weight,
                :courier_cost,
                :assignment_score,
                :assignment_reason,
                :assigned_at
            )
        ");

        $stmt->execute([
            ':order_id' => $orderId,
            ':courier_name' => $selectedCourier['company_name'],
            ':courier_service_id' => $selectedCourier['courier_service_id'],
            ':tracking_number' => $trackingNumber,
            ':estimated_delivery_at' => $estimatedDeliveryAt,
            ':total_packages' => $packingPlan['total_packages'],
            ':total_weight' => $packingPlan['total_weight'],
            ':courier_cost' => $selectedCourier['estimated_cost'],
            ':assignment_score' => $selectedCourier['assignment_score'],
            ':assignment_reason' => $selectedCourier['assignment_reason'],
            ':assigned_at' => $now
        ]);

        $shipmentId = (int) $db->lastInsertId();
        $packageStmt = $db->prepare("
            INSERT INTO order_packages (
                order_id,
                shipment_id,
                box_id,
                package_number,
                length,
                width,
                height,
                product_weight,
                packaging_weight,
                total_weight,
                package_status
            ) VALUES (
                :order_id,
                :shipment_id,
                :box_id,
                :package_number,
                :length,
                :width,
                :height,
                :product_weight,
                :packaging_weight,
                :total_weight,
                'packed'
            )
        ");

        $itemStmt = $db->prepare("
            INSERT INTO order_package_items (
                package_id,
                order_item_id,
                product_id,
                variant_id,
                quantity
            ) VALUES (
                :package_id,
                :order_item_id,
                :product_id,
                :variant_id,
                :quantity
            )
        ");

        $savedPackageIds = [];

        foreach ($packingPlan['packages'] as $package) {

            $packageStmt->execute([
                ':order_id' => $orderId,
                ':shipment_id' => $shipmentId,
                ':box_id' => $package['box_id'],
                ':package_number' => $package['package_number'],
                ':length' => $package['length'],
                ':width' => $package['width'],
                ':height' => $package['height'],
                ':product_weight' => $package['product_weight'],
                ':packaging_weight' => $package['packaging_weight'],
                ':total_weight' => $package['total_weight']
            ]);

            $packageId = (int) $db->lastInsertId();

            $savedPackageIds[] = $packageId;

            foreach ($package['items'] as $item) {

                $itemStmt->execute([
                    ':package_id' => $packageId,
                    ':order_item_id' => $item['order_item_id'],
                    ':product_id' => $item['product_id'],
                    ':variant_id' => $item['variant_id'],
                    ':quantity' => $item['quantity']
                ]);
            }
        }
        $description =
            'Courier assigned: ' .
            $selectedCourier['company_name'] .
            ' - ' .
            $selectedCourier['service_name'] .
            '. ' .
            $packingPlan['total_packages'] .
            ' package(s) created. Awaiting pickup confirmation.';

        $stmt = $db->prepare("
            INSERT INTO order_tracking_updates (
                order_id,
                shipment_id,
                event_type,
                previous_status,
                new_status,
                title,
                description,
                event_date,
                visible_to_customer
            ) VALUES (
                :order_id,
                :shipment_id,
                'shipment_created',
                NULL,
                'pending',
                'Shipment created',
                :description,
                :event_date,
                1
            )
        ");

        $stmt->execute([
            ':order_id' => $orderId,
            ':shipment_id' => $shipmentId,
            ':description' => $description,
            ':event_date' => $now
        ]);
        if ($newOrderStatus !== null) {

            $stmt = $db->prepare("
        UPDATE orders
        SET order_status = :status
        WHERE id = :order_id
    ");

            $stmt->execute([
                ':status' => $newOrderStatus,
                ':order_id' => $orderId
            ]);
        }
        if ($ownsTransaction) {
            $db->commit();
        }

        return [
            'success' => true,
            'order_id' => $orderId,
            'shipment_id' => $shipmentId,
            'tracking_number' => $trackingNumber,
            'courier' => $selectedCourier['company_name'],
            'service' => $selectedCourier['service_name'],
            'assignment_score' => $selectedCourier['assignment_score'],
            'total_packages' => count($savedPackageIds),
            'package_ids' => $savedPackageIds,
            'total_weight' => $packingPlan['total_weight'],
            'courier_cost' => $selectedCourier['estimated_cost'],
            'message' => 'Courier assigned and shipment created successfully.'
        ];
    } catch (Throwable $e) {

        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }

        return [
            'success' => false,
            'order_id' => $orderId,
            'message' => $e->getMessage()
        ];
    }
}
