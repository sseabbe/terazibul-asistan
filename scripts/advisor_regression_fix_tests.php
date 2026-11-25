<?php

declare(strict_types=1);

use App\Services\AssistantV2\AssistantV2AdvisorEvidence;
use App\Services\AssistantV2\AssistantV2AdvisorHandoff;
use App\Services\AssistantV2\AssistantV2AdvisorTestSupport;
use App\Services\AssistantV2\AssistantV2Catalog;
use App\Services\AssistantV2\AssistantV2Filters;

require dirname(__DIR__) . '/vendor/autoload.php';
\Core\Env::load(dirname(__DIR__) . '/.env');
(new \Core\Application(dirname(__DIR__)))->boot();

$failures = 0;

function assertTrue(mixed $condition, string $label): void
{
    global $failures;
    if ($condition) {
        echo "  OK  {$label}\n";
        return;
    }
    echo " FAIL {$label}\n";
    ++$failures;
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    assertTrue($expected === $actual, $label . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')');
}

function turn(string $message, array $mock): array
{
    return AssistantV2AdvisorTestSupport::runConversation([$message], [$mock])[0] ?? [];
}

function showUrl(array $payload): string
{
    foreach ($payload['actions'] ?? [] as $action) {
        if (is_array($action) && ($action['type'] ?? '') === 'show_products') {
            return (string) ($action['url'] ?? '');
        }
    }

    return '';
}

echo "Capacity direction\n";
assertSame('max', AssistantV2Filters::statedCapacityOperator('okul laboratuvarı için hassas terazi max 500 gr'), 'A4 max');
assertSame('min', AssistantV2Filters::statedCapacityOperator('500 gramlık yük tartacağım'), 'required load min');
assertSame('min', AssistantV2Filters::statedCapacityOperator('fabrika için platform kantar 2 ton'), 'E2 2 ton min');
assertSame('min', AssistantV2Filters::statedCapacityOperator('2 ton kapasite lazım'), '2 ton kapasite min');
assertSame('max', AssistantV2Filters::statedCapacityOperator('2 tona kadar bir şey yeter'), '2 tona kadar max');
assertSame('min', AssistantV2Filters::statedCapacityOperator('200 g 0.0001 g analitik terazi lazım'), '200 g stays min');
assertSame('approximately', AssistantV2Filters::statedCapacityOperator('0,1 mg hassasiyetli lab terazisi lazim, 200 gram civarı'), 'civari stays approximately');

$a4 = turn('okul laboratuvarı için hassas terazi max 500 gr', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'laboratuvar',
        'capacity' => ['operator' => 'min', 'value' => '500', 'unit' => 'g'],
    ],
]);
assertSame('max', (string) ($a4['state']['filters']['capacity']['operator'] ?? ''), 'A4 operator corrected to max');
assertTrue(($a4['state']['advisor']['ready_for_products'] ?? true) !== true, 'A4 not ready');
assertTrue(!AssistantV2AdvisorTestSupport::hasActionType($a4, 'show_products'), 'A4 no SHOW_PRODUCTS');

$e2 = turn('fabrika için platform kantar 2 ton', [
    'intent' => 'product_discovery',
    'category' => 'endustriyel',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'endustriyel',
        'capacity' => ['operator' => 'max', 'value_g' => 2000000],
    ],
]);
assertSame('min', (string) ($e2['state']['filters']['capacity']['operator'] ?? ''), 'E2 operator min');
$e2Listing = (string) ($e2['state']['products_listing_url'] ?? '');
assertTrue($e2Listing !== '' && str_contains($e2Listing, 'kap_min=') && !str_contains($e2Listing, 'kap_max='), 'E2 listing is kap_min');
$e2Show = showUrl($e2);
assertTrue($e2Show === '' || !str_contains($e2Show, 'kap_max='), 'E2 SHOW is not kap_max');

echo "\nReadiness\n";
$a3 = turn('analitik terazi lazm 4 hane', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'laboratuvar',
        'precision' => ['value' => '0.0001', 'unit' => 'g'],
    ],
]);
assertTrue(($a3['state']['advisor']['ready_for_products'] ?? true) !== true, 'A3 precision alone not ready');
assertTrue(!AssistantV2AdvisorTestSupport::hasActionType($a3, 'show_products'), 'A3 no SHOW_PRODUCTS');

$mt3 = turn('200 g analitik terazi bakıyorum', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'laboratuvar',
        'capacity' => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
    ],
]);
assertTrue(($mt3['state']['advisor']['ready_for_products'] ?? true) !== true, 'MT3 capacity alone not ready');
assertTrue(!AssistantV2AdvisorTestSupport::hasActionType($mt3, 'show_products'), 'MT3 no SHOW_PRODUCTS');

$m2 = turn('bluetoothlu 0.001 g terazi var mı', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'laboratuvar',
        'precision' => ['value' => '0.001', 'unit' => 'g'],
    ],
]);
assertTrue(($m2['state']['advisor']['ready_for_products'] ?? true) !== true, 'M2 bluetooth not ready');
assertTrue(!AssistantV2AdvisorTestSupport::hasActionType($m2, 'show_products'), 'M2 no SHOW_PRODUCTS');

$both = turn('200 g 0.0001 g analitik terazi lazım', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'subcategory' => 'lab-analitik',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'laboratuvar',
        'subcategory' => 'lab-analitik',
        'capacity' => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
        'precision' => ['value' => '0.0001', 'unit' => 'g'],
    ],
]);
assertTrue(($both['state']['advisor']['ready_for_products'] ?? false) === true, 'regression both measures ready');
assertTrue(AssistantV2AdvisorTestSupport::hasActionType($both, 'show_products'), 'regression SHOW_PRODUCTS');
$bothUrl = showUrl($both);
assertTrue(str_contains($bothUrl, 'kap_min=200') && str_contains($bothUrl, 'has_max=0.0001'), 'regression listing filters');

echo "\nBrand and callback\n";
$kern = AssistantV2Catalog::resolveBrand('Kern');
assertTrue(is_string($kern) && $kern !== '', 'Kern resolves');
$b1 = turn('sadece Kern analitik terazi, 200 g 0.0001 g', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'subcategory' => 'lab-analitik',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'laboratuvar',
        'subcategory' => 'lab-analitik',
        'capacity' => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
        'precision' => ['value' => '0.0001', 'unit' => 'g'],
    ],
]);
$brands = $b1['state']['filters']['brands'] ?? [];
assertTrue(is_array($brands) && $brands !== [] && in_array($kern, $brands, true), 'B1 brands contain Kern');
$b1Url = showUrl($b1);
assertTrue($b1Url !== '' && (str_contains($b1Url, 'kern') || str_contains($b1Url, 'marka=')), 'B1 SHOW_PRODUCTS carries Kern');

$u2 = turn('beni arayın teraziyi konuşalım', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'laboratuvar'],
]);
assertTrue(AssistantV2AdvisorHandoff::isUserRequested('beni arayın teraziyi konuşalım'), 'U2 phrase detected');
assertSame('user_requested', (string) ($u2['state']['advisor']['handoff_reason'] ?? ''), 'U2 handoff');
assertTrue(($u2['state']['advisor']['ready_for_products'] ?? true) !== true, 'U2 not product ready');
assertTrue(AssistantV2AdvisorTestSupport::hasActionType($u2, 'phone') || AssistantV2AdvisorTestSupport::hasActionType($u2, 'whatsapp_write'), 'U2 phone or WhatsApp');
assertTrue(!AssistantV2AdvisorTestSupport::hasActionType($u2, 'show_products'), 'U2 no SHOW_PRODUCTS');

echo "\nNamed model and analytical subcategory\n";
$t1 = turn('Axis APN220 kapasitesi ve hassasiyeti ne', [
    'intent' => 'product_discovery',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => ['brands' => ['Axis']],
]);
$t1Reply = (string) ($t1['message'] ?? '');
assertTrue(str_contains($t1Reply, 'APN220') && str_contains($t1Reply, '220') && (str_contains($t1Reply, '0.0001') || str_contains($t1Reply, '0,0001')), 'T1 catalog identity');
assertTrue(!str_contains($t1Reply, 'APN82') && !str_contains($t1Reply, 'kayıtlara bakılamıyor'), 'T1 not the other model or generic error');

$yari = turn('sadece Kern analitik terazi, 200 g 0.0001 g', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'subcategory' => 'yari-analitik-teraziler',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'laboratuvar',
        'subcategory' => 'yari-analitik-teraziler',
        'capacity' => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
        'precision' => ['value' => '0.0001', 'unit' => 'g'],
    ],
]);
assertSame('lab-analitik', (string) ($yari['state']['filters']['subcategory'] ?? ''), 'analitik is not yari-analitik');

echo "\nEvidence\n";
$kept = AssistantV2AdvisorEvidence::guard(
    '200 g civarı ve 0,1 mg hassasiyet kriterlerine göre uygun ürünleri filtreledim.',
    '0,1 mg hassasiyetli lab terazisi lazim, 200 gram civarı',
    [[
        'id' => 1,
        'brand' => 'Axis',
        'model_no' => 'APN220',
        'name' => 'Axis APN220',
        'housing' => ['metal', 'cam'],
        'capacity_label' => '220 g',
        'precision_label' => '0.0001 g',
    ]]
);
assertTrue(str_contains($kept, '0,1 mg') || str_contains($kept, '200 g'), 'A2 discovery sentence kept');
assertTrue(!str_contains($kept, 'doğrulayamıyorum'), 'A2 not replaced');

$crash = AssistantV2AdvisorEvidence::guard(
    'APN220 kapasitesi 220 g, hassasiyeti 0.0001 g.',
    'Axis APN220 kapasitesi ve hassasiyeti ne',
    [[
        'id' => 7,
        'brand' => 'Axis',
        'model_no' => 'APN220',
        'name' => 'Axis APN220',
        'housing' => ['paslanmaz'],
        'communication' => ['RS232'],
        'capacity_label' => '220 g',
        'precision_label' => '0.0001 g',
        'specs' => [['label' => 'Kapasite', 'value' => '220 g'], ['label' => 'Hassasiyet', 'value' => '0.0001 g']],
    ]]
);
assertTrue(str_contains($crash, '220 g') && str_contains($crash, '0.0001'), 'T1 array housing does not drop supported specs');

$invented = AssistantV2AdvisorEvidence::guard(
    'APN220 160°C kurutur.',
    'Axis APN220 kapasitesi ne',
    [[
        'id' => 7,
        'brand' => 'Axis',
        'model_no' => 'APN220',
        'name' => 'Axis APN220',
        'housing' => ['paslanmaz'],
        'capacity_label' => '220 g',
    ]]
);
assertSame(AssistantV2AdvisorEvidence::NO_EVIDENCE, $invented, 'T1 unsupported model fact removed');

echo "\n";
if ($failures > 0) {
    echo "{$failures} assertion(s) failed.\n";
    exit(1);
}
echo "All regression-fix tests passed.\n";
exit(0);
