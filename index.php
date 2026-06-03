<?php

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);

$omegaKey   = getenv('OMEGA_API_KEY');
$dilovodKey = getenv('DILOVOD_API_KEY_2');

if (!$omegaKey) die('OMEGA_API_KEY not set');
if (!$dilovodKey) die('DILOVOD_API_KEY not set');

echo "OMEGA KEY: " . substr($omegaKey, 0, 10) . "...\n";
echo "DILOVOD KEY: " . substr($dilovodKey, 0, 10) . "...\n";

const FIRM_ID      = '1100400000001002';
const PERSON_ID    = '1100100000001002';
const STORAGE_ID   = '1100700000000001'; // Основний склад
const BUSINESS_ID  = '1115000000000001';
const CONTRACT_ID  = '1103000000001024';
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

function dilovod($packet)
{
    global $dilovodKey;
   // echo "DILOVOD KEY: " . substr($dilovodKey, 0, 10) . "...\n";

    $packet['version'] = '0.25';
    $packet['key'] = $dilovodKey;

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

    $start = date('d.m.Y', strtotime('-7 days'));
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
    ]);

    if (!empty($res[0]['id'])) {
    return $res[0]['id'];
}

return false;
}

function findProductGlobal($code)
{
    return '1100300000020076';
}

function findBrand($name)
{
    return '1101600000001003';
}

function createBrand($name)
{
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
                'isGroup' => 0,

                'name' => [
                    'uk' => $name,
                    'ru' => $name
                ],

                'parent' => '1100300000003465', // пока оставляем
                'tradeMark' => $brandId,

                'productNum' => $code,
                'mainUnit' => '1103600000000001',
                'accPolicy' => '1201200000001002',
                'specQty' => 1
            ],
            'tableParts' => []
        ]
    ]);

    if (!empty($res['id'])) {
        return $res['id'];
    }

    echo "CREATE PRODUCT ERROR:\n";
    print_r($res);

    return false;
}

function importDocument($docId)
{
    echo "START IMPORT: {$docId}\n";
    $headerRes = omegaHeader($docId);
    $productsRes = omegaProducts($docId);

    if (empty($headerRes['Success']) || empty($productsRes['Success'])) {
        echo "OMEGA ERROR: $docId\n";
        return;
    }

    $omega = $headerRes['Data'];
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

        $brandId = findBrand($brandName);
        echo "BRAND NAME: {$brandName}\n";
        echo "BRAND ID: {$brandId}\n";

        if (!$brandId) {
        $brandId = createBrand($brandName);
        }

        $goodId = findProduct($code);

echo "GOOD ID AFTER FIND: ";
var_dump($goodId);

if (!$goodId) {
    $goodId = createProduct($code, $name, $brandId);

    echo "GOOD ID AFTER CREATE: ";
    var_dump($goodId);
}

echo "AFTER PRODUCT\n";
        
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
            // 'vatTax' => '1105800000000023',
            'vatAmount' => '0.00'
        ];

        echo "AFTER TPGOODS\n";

        $row++;
    }

    print_r([
    'id' => 'documents.purchase',
    'date' => date('Y-m-d H:i:s', strtotime($omega['Date'])),
    'originalNumber' => $omega['Number'],
    'originalDate' => date('Y-m-d H:i:s', strtotime($omega['Date'])),
    'firm' => FIRM_ID,
    // 'business' => BUSINESS_ID,
    'storage' => STORAGE_ID,
    // 'person' => PERSON_ID,
    // 'contract' => CONTRACT_ID,
    'currency' => CURRENCY_ID,
    'amountCur' => $omega['Summ'],
    'rate' => 1,
    'taxAccount' => 1,
    'paymentForm' => '1110300000000001',
    'department' => '1101900000000001',
    'state' => '1111500000000005',
    'docMode' => DOCMODE_ID,
    'posted' => 0
]);
   
    echo "TP GOODS:\n";
print_r($tpGoods);
    
    $doc = dilovod([
        'action' => 'saveObject',
        'params' => [
            'saveType' => 1,
            'header' => [
    'id' => 'documents.purchase',
    'date' => date('Y-m-d H:i:s', strtotime($omega['Date'])),
    'originalNumber' => $omega['Number'],
    'originalDate' => date('Y-m-d H:i:s', strtotime($omega['Date'])),

    'firm' => FIRM_ID,
    // 'business' => BUSINESS_ID,
    'storage' => STORAGE_ID,
    'person' => PERSON_ID,
    // 'contract' => CONTRACT_ID,
    'currency' => CURRENCY_ID,

    'amountCur' => $omega['Summ'],
    'rate' => 1,
    'taxAccount' => 1,
    
    'docMode' => DOCMODE_ID,
     
    'paymentForm' => '1110300000000001',
    'department' => '1101900000000001',
    'state' => '1111500000000005',              
    'posted' => 0
],
    'tableParts' => [
    'tpGoods' => $tpGoods
]                  
         ]  
                   ]);

    print_r($doc);
    echo "\n";
}

$list = omegaList();

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

    echo "IMPORT DOCUMENT: {$number}\n";  
    importDocument($doc['Id']);
}

echo "DONE\n";
