<?php

header('Content-Type: text/plain; charset=utf-8');

$omegaKey   = getenv('OMEGA_API_KEY');
$dilovodKey = getenv('DILOVOD_API_KEY');

if (!$omegaKey) {
    die('OMEGA_API_KEY not set');
}

if (!$dilovodKey) {
    die('DILOVOD_API_KEY not set');
}

$docId = $_GET['docId'] ?? '';

if (!$docId) {
    die('Use URL like: ?docId=YOUR_OMEGA_DOC_ID');
}

function omegaRequest($docId, $omegaKey)
{
    $ch = curl_init('https://public.omega.page/public/api/v1.0/expense/getExpenseDocument');

    $payload = [
        'Key'   => $omegaKey,
        'DocId' => $docId
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        die(curl_error($ch));
    }

    curl_close($ch);

    return json_decode($response, true);
}

function dilovodRequest($packet)
{
    $ch = curl_init('https://api.dilovod.ua');

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);

    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'packet' => json_encode($packet)
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        die(curl_error($ch));
    }

    curl_close($ch);

    return json_decode($response, true);
}

$omegaDoc = omegaRequest($docId, $omegaKey);

echo "OMEGA RESPONSE:\n\n";
print_r($omegaDoc);

echo "\n\nDILOVOD TEST:\n\n";

$test = dilovodRequest([
    "version" => "0.25",
    "key" => $dilovodKey,
    "action" => "request",
    "params" => [
        "from" => "catalogs.storages",
        "fields" => [
            "id" => "id",
            "name" => "name"
        ]
    ]
]);

print_r($test);
