<?php

header('Content-Type: application/json');

// Get parameters
$token = isset($_GET['token']) ? $_GET['token'] : '';
$serviceId = isset($_GET['serviceId']) ? $_GET['serviceId'] : '';

if (empty($token) || empty($serviceId)) {
    echo json_encode(['error' => 'Missing token or serviceId']);
    exit;
}

$apiUrl = 'https://connect.pay.nl/v4/Transaction/getService/json?' . http_build_query([
        'token' => $token,
        'serviceId' => $serviceId
    ]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($result === false) {
    $error = curl_error($ch);
    curl_close($ch);
    echo json_encode(['error' => $error]);
    exit;
}

curl_close($ch);

echo $result;
