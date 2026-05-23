<?php

header('Content-Type: text/plain; charset=utf-8');

$omegaKey   = getenv('OMEGA_API_KEY');
$dilovodKey = getenv('DILOVOD_API_KEY');

if (!$omegaKey) die('OMEGA_API_KEY not set');
if (!$dilovodKey) die('DILOVOD_API_KEY not set');

$docId = $_GET['docId'] ?? '';
if (!$docId) die('Use URL like: ?docId=OMEGA_DOC_ID');

const FIRM_ID      = '1100400000001002';
const PERSON_ID    = '1100100000001002';
const STORAGE_ID   = '1100700000001003';
const BUSINESS_ID  = '1115000000000001';
const CONTRACT_ID  = '1103000000001024';
const CURRENCY_ID  = '1101200000001001';
const DOCMODE_ID   = '1004000000000343';

function postJson($url, $data)
{
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

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

function dilovod($packet)
{
    global $dilovodKey;

    $packet['version'] = '0.25';
    $packet['key'] = $dilovodKey;

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

function getOmegaHeader($docId)
{
    global $omegaKey;

    return postJson(
        'https://public.omega.page/public/api/v1.0/expense/getExpenseDocument',
        [
            'Key' => $omegaKey,
            'DocId' => $docId
        ]
    );
}

function getOmegaProducts($docId)
{
    global $omegaKey;

    return postJson(
        'https://public.omega.page/public/api/v1.0/expense/getExpenseDocumentDetails',
        [
            'Key' => $omegaKey,
            'DocId' => $docId
        ]
    );
}

function findProduct($code)
{
    $res = dilovod([
        'action' => 'request',
        'params' => [
            'from' => 'catalogs.goods',
            'fields' => [
                'id' => 'id',
                'code' => 'code'
            ],
            'filters' => [
                [
                    'alias' => 'code',
                    'operator' => '=',
                    'value' => $code
                ]
            ]
        ]
    ]);

    if (!empty($res[0]['id'])) {
        return $res[0]['id'];
    }

    return false;
}

function createProduct($code, $name)
{
    $res = dilovod([
        'action' => 'saveObject',
        'params' => [
            'saveType' => 1,
            'header' => [
                'id' => 'catalogs.goods',
                'firm' => FIRM_ID,
                'code' => $code,
                'name' => $name
            ]
        ]
    ]);

    if (!empty($res['id'])) {
        return $res['id'];
    }

    die("PRODUCT CREATE ERROR:\n" . print_r($res, true));
}

$headerRes = getOmegaHeader($docId);
$productsRes = getOmegaProducts($docId);

if (empty($headerRes['Success'])) {
    die("OMEGA HEADER ERROR:\n" . print_r($headerRes, true));
}

if (empty($productsRes['Success'])) {
    die("OMEGA PRODUCTS ERROR:\n" . print_r($productsRes, true));
}

$omega = $headerRes['Data'];
$products = $productsRes['Data'];

$tpGoods = [];
$row = 1;

foreach ($products as $p) {
    $code = trim($p['Code']);
    $name = trim($p['ProductDescrition']);
    $qty = (float)$p['Count'];
    $price = (float)$p['PiceWithVAT'];

    $goodId = findProduct($code);

    if (!$goodId) {
        $goodId = createProduct($code, $name);
    }

    $tpGoods[] = [
        'rowNum' => (string)$row,
        'good' => $goodId,
        'price' => number_format($price, 5, '.', ''),
        'qty' => number_format($qty, 3, '.', ''),
        'baseQty' => number_format($qty, 3, '.', ''),
        'priceAmount' => round($price * $qty, 2),
        'amountCur' => round($price * $qty, 2)
    ];

    $row++;
}

$doc = dilovod([
    'action' => 'saveObject',
    'params' => [
        'saveType' => 1,
        'header' => [
            'id' => 'documents.purchase',
            'date' => date('Y-m-d H:i:s', strtotime($omega['Date'])),
            'number' => $omega['Number'],
            'posted' => 1,
            'firm' => ['id' => FIRM_ID],
            'business' => ['id' => BUSINESS_ID],
            'storage' => ['id' => STORAGE_ID],
            'person' => ['id' => PERSON_ID],
            'contract' => ['id' => CONTRACT_ID],
            'currency' => ['id' => CURRENCY_ID],
            'docMode' => ['id' => DOCMODE_ID],
            'amountCur' => $omega['Summ'],
            'rate' => 1
        ],
        'tableParts' => [
            'tpGoods' => $tpGoods
        ]
    ]
]);

echo "RESULT:\n\n";
print_r($doc);
