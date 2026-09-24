<?php

$url = 'https://baseavangers.topscripts.in/sumit_rana/offline/shopify_test_payload.json';


$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 15,
        'ignore_errors' => true
    ]
]);


$response = file_get_contents(
    $url,
    false,
    $context
);


echo '<pre>';

echo "URL:\n";
echo htmlspecialchars($url);

echo "\n\nRESPONSE HEADERS:\n";
print_r($http_response_header ?? []);

echo "\n\nSHOPIFY PAYLOAD:\n";


if ($response === false) {

    echo "FAILED TO FETCH";

} else {

    $data = json_decode($response, true);

    if (is_array($data)) {

        echo "Product ID: ";
        echo htmlspecialchars($data['id'] ?? 'N/A');

        echo "\n";

        echo "TITLE: ";
        echo htmlspecialchars($data['title'] ?? 'N/A');

        echo "\n";

        echo "HANDLE: ";
        echo htmlspecialchars($data['handle'] ?? 'N/A');

        echo "\n";

        echo "STATUS: ";
        echo htmlspecialchars($data['status'] ?? 'N/A');

        echo "\n\n";

        echo "FULL PAYLOAD:\n";

        echo htmlspecialchars(
            json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );

    } else {

        echo htmlspecialchars($response);

    }
}


echo '</pre>';