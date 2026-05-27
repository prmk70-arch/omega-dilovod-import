<?php

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);

$omegaKey   = getenv('OMEGA_API_KEY');
$dilovodKey = getenv('DILOVOD_API_KEY');

if (!$omegaKey) die('OMEGA_API_KEY not set');
if (!$dilovodKey) die('DILOVOD_API_KEY not set');

const FIRM_ID      = '1100400000001002';
const PERSON_ID    = '1100100000001002';
const STORAGE_ID   = '1100700000000001'; // Основний склад
const BUSINESS_ID  = '1115000000000001';
const CONTRACT_ID  = '1103000000001024';
const SUPPLIERS = [
    'bd6962e2-4870-11e6-80c3-005056a817fa' => [
        'firm' => '1100400000001002',
        'person' => '1100100000001002',
        'contract' => '1103000000001024',
        'apiKey' => 'DILOVOD_API_KEY'
    ],

    'bd6962e8-4870-11e6-80c3-005056a817fa' => [
        'firm' => '1100400000001001',
        'person' => '1100100000001252',
        'contract' => '1103000000001097',
        'apiKey' => 'DILOVOD_API_KEY_2'
    ],
];
const CURRENCY_ID  = '1101200000001001';
const DOCMODE_ID   = '1004000000000343';

function postJson($url, $data)
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        die(curl_error($ch));
    }

    curl_close($ch);

    return json_decode($response, true);
}

function dilovod($packet, $apiKey = null)
{
    if (!$apiKey) {
        $apiKey = getenv('DILOVOD_API_KEY');
    }

    $packet['version'] = '0.25';
    $packet['key'] = $apiKey;

    $ch = curl_init('https://api.dilovod.ua');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'packet' => json_encode($packet)
        ]
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        die(curl_error($ch));
    }

    curl_close($ch);

    return json_decode($response, true);
}

function omegaList()
{
    global $omegaKey;

    $start = date('d.m.Y', strtotime('-3 days'));
    $end   = date('d.m.Y');

    return postJson(
        'https://public.omega.page/public/api/v1.0/expense/getExpenseDocumentList',
        [
            'StartDate' => $start,
            'EndDate' => $end,
            'Index' => 0,
            'Key' => $omegaKey
        ]
    );
}

function omegaHeader($docId)
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

function omegaProducts($docId)
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

function findDocumentByNumber($number)
{
    $res = dilovod([
        'action' => 'request',
        'params' => [
            'from' => 'documents.purchase',
            'fields' => [
                'id' => 'id',
                'originalNumber' => 'originalNumber'
            ],
            'filters' => [
                [
                    'alias' => 'originalNumber',
                    'operator' => '=',
                    'value' => $number
                ]
            ]
        ]
    ]);

    if (!empty($res[0]['id'])) {
    echo "SKIP EXISTS: $number\n";
    return true;
}

return false;
}

function findProduct($code)
{
    $res = dilovod([
        'action' => 'request',
        'params' => [
            'from' => 'catalogs.goods',
            'fields' => [
                'id' => 'id',
                'productNum' => 'productNum'
            ],
            'filters' => [
                [
                    'alias' => 'productNum',
                    'operator' => '=',
                    'value' => trim($code)
                ]
            ]
        ]
    ], getenv('DILOVOD_API_KEY'));

    print_r($res);

    return $res[0]['id'] ?? false;
}

function findBrand($name)
{
    $res = dilovod([
        'action' => 'request',
        'params' => [
            'from' => 'catalogs.tradeMarks',
            'fields' => [
                'id' => 'id',
                'name' => 'name'
            ],
            'filters' => [
                [
                    'alias' => 'name',
                    'operator' => '=',
                    'value' => $name
                ]
            ]
        ]
    ], getenv('DILOVOD_API_KEY'));

    return $res[0]['id'] ?? false;
}

    foreach ($res as $row) {
        $brandName = '';

        if (is_array($row['name'] ?? null)) {
            $brandName = $row['name']['uk'] ?? $row['name']['ru'] ?? '';
        } else {
            $brandName = $row['name'] ?? '';
        }

        if (mb_strtoupper(trim($brandName)) === mb_strtoupper(trim($name))) {
            return $row['id'];
        }
    }

    return false;
}

function createBrand($name)
{
    $res = dilovod([
        'action' => 'saveObject',
        'params' => [
            'saveType' => 1,
            'header' => [
                'id' => 'catalogs.tradeMarks',
                'code' => '',
                'isGroup' => 0,
                'name' => [
                    'uk' => $name,
                    'ru' => $name
                ]
            ],
            'tableParts' => []
        ]
    ], getenv('DILOVOD_API_KEY'));

    if (!empty($res['id'])) {
        return $res['id'];
    }

    return '1101600000001477'; // Без бренду
}

function createProduct($code, $name, $brandId)
{
    $res = dilovod([
        'action' => 'saveObject',
        'params' => [
            'saveType' => 1,
            'header' => [
                'id' => 'catalogs.goods',
                'code' => '',
                'productNum' => $code,
                'isGroup' => 0,
                'name' => [
                    'uk' => $name,
                    'ru' => $name
                ],
                'mainUnit' => '1103600000000001',
                'tradeMark' => $brandId,
                'accPolicy' => '1201200000001002',
                'specQty' => 1
            ],
            'tableParts' => [
                'tpGoods' => [],
                'tpReplacements' => [],
                'tpOperations' => []
            ]
        ]
    ], getenv('DILOVOD_API_KEY'));

    if (!empty($res['id'])) {
        return $res['id'];
    }

    die("PRODUCT CREATE ERROR:\n" . print_r($res, true));
}

function importDocument($docId)
{
    $headerRes = omegaHeader($docId);
    $productsRes = omegaProducts($docId);

    if (empty($headerRes['Success']) || empty($productsRes['Success'])) {
        echo "OMEGA ERROR: $docId\n";
        return;
    }

    $omega = $headerRes['Data'];
    $supplierKey = $omega['Customer']['Key'] ?? '';

if (!isset(SUPPLIERS[$supplierKey])) {
    die("UNKNOWN SUPPLIER: " . $supplierKey);
}

$firmId = SUPPLIERS[$supplierKey]['firm'];
$personId = SUPPLIERS[$supplierKey]['person'];
$contractId = SUPPLIERS[$supplierKey]['contract'];
$docApiKey = getenv(SUPPLIERS[$supplierKey]['apiKey']);    
$isSecondFirm = ($firmId === '1100400000001001');    
    
    $products = $productsRes['Data'];

    if (findDocumentByNumber($omega['Number'])) {
        echo "SKIP EXISTS: {$omega['Number']}\n";
        return;
    }

    $tpGoods = [];
    $row = 1;

    foreach ($products as $p) {
        $code = trim($p['Code']);
        $name = trim($p['ProductDescrition']);
        $qty = (float)$p['Count'];
        $price = (float)$p['PiceWithVAT'];

        $brandName = trim($p['Brand'] ?? '');

    if (!$brandName) {
        if (preg_match('/\(пр-во\s+([^)]+)\)/ui', $p['ProductDescrition'], $m)) {
            $brandName = trim($m[1]);
        } else {
            $brandName = 'Без бренду';
        }
    }

     if ($isSecondFirm) {
         $goodId = findProductGlobal($code);
     } else {
         $brandId = findBrand($brandName);

         if (!$brandId) {
             $brandId = createBrand($brandName);
         }

         $goodId = findProduct($code);
    }

    if (!$goodId) {
       echo "PRODUCT NOT FOUND: {$code} {$name}\n";
       continue;
    }
        function findProductGlobal($code)
{
    $res = dilovod([
        'action' => 'request',
        'params' => [
            'from' => 'catalogs.goods',
            'fields' => [
                'id' => 'id',
                'productNum' => 'productNum'
            ]
        ]
    ]);

    foreach ($res as $row) {
        if (trim((string)($row['productNum'] ?? '')) === trim($code)) {
            return $row['id'];
        }
    }

    return false;
}
        
        $tpGoods[] = [
            'rowNum' => (string)$row,
            'good' => $goodId,
            'price' => number_format($price, 5, '.', ''),
            'qty' => number_format($qty, 3, '.', ''),
            'baseQty' => number_format($qty, 3, '.', ''),
            'priceAmount' => round($price * $qty, 2),
            'unit' => '1103600000000001',
            'ratio' => '1.0000',
            'discount' => '0.00',
            'discountPercent' => '0.0',
            'amountCur' => round($price * $qty, 2),
            'goodPart' => 0,
            'gCharForDelete' => 0,
            'analytics1' => 0,
            'analytics2' => 0,
            'analytics3' => 0,
            'analytics4' => 0,
            'analytics5' => 0,
            'analytics6' => 0,
            'analytics7' => 0,
            'analytics8' => 0,
            'vatTax' => '1105800000000023',
            'vatAmount' => '0.00'
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
                'originalNumber' => $omega['Number'],
                'originalDate' => date('Y-m-d H:i:s', strtotime($omega['Date'])),   
                'firm' => $firmId,
                'business' => BUSINESS_ID,
                'storage' => STORAGE_ID,
                'person' => $personId,
                'contract' => $contractId,
                'currency' => CURRENCY_ID,
                'amountCur' => $omega['Summ'],
                'rate' => 1,
                'taxAccount' => 1,
                'paymentForm' => '1110300000000001',
                'department' => '1101900000000001',
                'state' => '1111500000000005',
                'docMode' => DOCMODE_ID,
                'posted' => 0
            ],
            'tableParts' => [
                'tpGoods' => $tpGoods
            ]
        ]
    ], $docApiKey);

    print_r($doc);
    echo "\n";
}

$list = omegaList();

if (empty($list['Success']) || empty($list['Data']['Result'])) {
    die("NO DOCUMENTS\n");
}

$processed = [];

foreach ($list['Data']['Result'] as $doc) {
    $header = omegaHeader($doc['Id']);

    if (empty($header['Success'])) {
        continue;
    }

    $number = (string)$header['Data']['Number'];
    $key = md5($number);

    if (isset($processed[$key])) {
        continue;
    }

    $processed[$key] = true;

    if (findDocumentByNumber($number)) {
        continue;
    }

    importDocument($doc['Id']);
}

echo "DONE\n";
