<?php

declare(strict_types=1);

use App\Services\AssistantV2\AssistantV2AdvisorSearch;
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
    assertTrue($expected === $actual, $label . ' (expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ')');
}

function turn(string $message, array $mock): array
{
    return AssistantV2AdvisorTestSupport::runConversation([$message], [$mock])[0] ?? [];
}

function grams(array $payload): float
{
    $value = $payload['state']['filters']['capacity']['value_g'] ?? null;

    return is_numeric($value) ? (float) $value : -1.0;
}

function diag(array $payload): array
{
    $raw = $payload['state']['advisor']['filter_diagnostics'] ?? [];

    return is_array($raw) ? $raw : [];
}

function hasType(array $payload, string $type): bool
{
    return AssistantV2AdvisorTestSupport::hasActionType($payload, $type);
}

echo "Units\n";
$ton = AssistantV2Filters::criteria(['capacity' => ['operator' => 'min', 'value' => '1', 'unit' => 'ton']]);
$kg = AssistantV2Filters::criteria(['capacity' => ['operator' => 'min', 'value' => '1000', 'unit' => 'kg']]);
$g = AssistantV2Filters::criteria(['capacity' => ['operator' => 'min', 'value' => '1000000', 'unit' => 'g']]);
$two = AssistantV2Filters::criteria(['capacity' => ['operator' => 'min', 'value' => '2', 'unit' => 'ton']]);
assertSame(1000000.0, (float) ($ton['capacity']['value_g'] ?? 0), '1 ton = 1000000 g');
assertSame(1000000.0, (float) ($kg['capacity']['value_g'] ?? 0), '1000 kg = 1000000 g');
assertSame(1000000.0, (float) ($g['capacity']['value_g'] ?? 0), '1000000 g stays grams');
assertSame(2000000.0, (float) ($two['capacity']['value_g'] ?? 0), '2 ton = 2000000 g');

$loadNode = AssistantV2Catalog::node('load-cell');
$barcodeNode = AssistantV2Catalog::node('barkodlu-teraziler');
assertTrue(is_array($loadNode) && ($loadNode['parent'] ?? '') === 'endustriyel', 'load-cell node parent endustriyel');
assertTrue(is_array($barcodeNode) && ($barcodeNode['parent'] ?? '') === 'perakende', 'barkodlu node parent perakende');

echo "\nProduct data\n";
$industrial = AssistantV2AdvisorSearch::searchProducts(AssistantV2Filters::normalizeState(['category' => 'endustriyel']), 0);
$retail = AssistantV2AdvisorSearch::searchProducts(AssistantV2Filters::normalizeState(['category' => 'perakende']), 0);

$d1 = turn('tankın altına dört load cell bağlayacağız', [
    'intent' => 'product_discovery',
    'category' => 'endustriyel',
    'subcategory' => null,
    'ready_for_product_results' => false,
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'endustriyel'],
]);
assertSame('endustriyel', (string) ($d1['state']['filters']['category'] ?? ''), 'D1 category');
assertSame('load-cell', (string) ($d1['state']['filters']['subcategory'] ?? ''), 'D1 subcategory');
assertTrue((diag($d1)['taxonomy_valid'] ?? false) === true, 'D1 taxonomy mapping valid');
assertTrue((int) ($d1['state']['search_total'] ?? -1) < (int) $industrial['total'], 'D1 not the whole industrial catalog');
assertTrue(!str_contains((string) ($d1['state']['filters']['category'] ?? ''), 'aksesuar'), 'D1 not aksesuar');
$d1Url = (string) ($d1['state']['products_listing_url'] ?? '');
assertTrue(str_contains($d1Url, 'load-cell'), 'D1 listing uses load-cell');
assertTrue(!preg_match('/kaç adet|kac adet/iu', (string) ($d1['message'] ?? '')), 'D1 does not re-ask cell count');
assertTrue(str_contains(mb_strtolower((string) ($d1['message'] ?? '')), 'toplam yük') || str_contains(mb_strtolower((string) ($d1['message'] ?? '')), 'toplam yuk'), 'D1 asks total load');

$d2 = turn('500 kg yük hücresi lazım', [
    'intent' => 'product_discovery',
    'category' => 'aksesuar',
    'subcategory' => null,
    'ready_for_product_results' => false,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'aksesuar',
        'capacity' => ['operator' => 'min', 'value' => '500', 'unit' => 'kg'],
    ],
]);
assertSame('load-cell', (string) ($d2['state']['filters']['subcategory'] ?? ''), 'D2 subcategory');
assertTrue((diag($d2)['taxonomy_valid'] ?? false) === true, 'D2 mapping valid');
assertTrue((int) ($d2['state']['search_total'] ?? -1) < (int) $industrial['total'], 'D2 not all industrial');
if ((int) ($loadNode['products'] ?? 0) === 0) {
    assertSame(0, (int) ($d2['state']['search_total'] ?? -1), 'D2 empty load-cell catalog');
    assertSame('catalog_empty', (string) (diag($d2)['reason'] ?? ''), 'D2 reason is empty catalog');
} else {
    assertTrue((int) ($d2['state']['search_total'] ?? 0) > 0, 'D2 finds assigned load-cell products');
}

$d3 = turn('kasap için barkod basan terazi arıyorum', [
    'intent' => 'product_discovery',
    'category' => 'perakende',
    'subcategory' => null,
    'ready_for_product_results' => false,
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'perakende'],
]);
assertSame('barkodlu-teraziler', (string) ($d3['state']['filters']['subcategory'] ?? ''), 'D3 subcategory');
assertTrue((diag($d3)['taxonomy_valid'] ?? false) === true, 'D3 mapping valid');
assertTrue((int) ($d3['state']['search_total'] ?? -1) < (int) $retail['total'] || (int) $retail['total'] === 0, 'D3 not the whole retail catalog');
assertTrue(str_contains((string) ($d3['state']['products_listing_url'] ?? ''), 'barkodlu-teraziler'), 'D3 listing uses barkodlu-teraziler');
if ((int) ($barcodeNode['products'] ?? 0) === 0) {
    assertSame(0, (int) ($d3['state']['search_total'] ?? -1), 'D3 empty barcode catalog');
    assertSame('catalog_empty', (string) (diag($d3)['reason'] ?? ''), 'D3 reason is empty catalog');
}

$d4 = turn('depoda 1 tonluk yer kantarı lazım', [
    'intent' => 'product_discovery',
    'category' => 'endustriyel',
    'subcategory' => null,
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'endustriyel',
        'capacity' => ['operator' => 'max', 'value' => '1', 'unit' => 'ton'],
    ],
]);
assertSame(1000000.0, grams($d4), 'D4 1 ton stored as 1000000 g');
assertSame('min', (string) ($d4['state']['filters']['capacity']['operator'] ?? ''), 'D4 operator min');
$misplaced = turn('depoda 1 tonluk yer kantarı lazım', [
    'intent' => 'product_discovery',
    'category' => 'endustriyel',
    'subcategory' => 'aks-kantari',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'endustriyel',
        'subcategory' => 'aks-kantari',
        'capacity' => ['operator' => 'max', 'value' => '1', 'unit' => 'ton'],
    ],
]);
assertTrue((string) ($misplaced['state']['filters']['subcategory'] ?? '') !== 'aks-kantari', 'D4 unnamed empty axle group dropped');
if ((int) ($d4['state']['search_total'] ?? 0) === 0) {
    assertSame('capacity', (string) (diag($d4)['blocking_filter'] ?? ''), 'D4 blocking filter capacity');
    assertTrue(str_contains((string) ($d4['message'] ?? ''), 'kapasite'), 'D4 explains capacity');
    assertSame('capacity', (string) (diag($misplaced)['blocking_filter'] ?? ''), 'D4 misplaced group uses capacity');
} else {
    assertTrue((int) ($d4['state']['search_total'] ?? 0) > 0, 'D4 keeps products at or above 1 ton');
}

$d5 = turn('fabrika için platform kantar 2 ton', [
    'intent' => 'product_discovery',
    'category' => 'endustriyel',
    'subcategory' => null,
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'endustriyel',
        'capacity' => ['operator' => 'max', 'value' => '2', 'unit' => 'ton'],
    ],
]);
assertSame(2000000.0, grams($d5), 'D5 2 ton stored as 2000000 g');
assertSame('min', (string) ($d5['state']['filters']['capacity']['operator'] ?? ''), 'D5 operator min');
$d5Url = (string) ($d5['state']['products_listing_url'] ?? '');
assertTrue(str_contains($d5Url, 'kap_min='), 'D5 listing uses kap_min');
assertTrue(!str_contains($d5Url, 'kap_max='), 'D5 listing has no kap_max');

echo "\nFilter diagnostics\n";
$f1 = $d4;
assertSame('capacity', (string) (diag($f1)['blocking_filter'] ?? ''), 'F1 blocking filter capacity');
assertSame(1000000.0, grams($f1), 'F4 capacity not relaxed');
assertSame('min', (string) ($f1['state']['filters']['capacity']['operator'] ?? ''), 'F4 operator stays min');
assertTrue(!hasType($f1, 'whatsapp_write'), 'F5 zero result has no WhatsApp');
assertSame('', (string) ($f1['state']['advisor']['handoff_reason'] ?? ''), 'F5 no automatic handoff');

$f2 = turn('laboratuvar terazisi Zzznomatchbrand 200 g', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'subcategory' => null,
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'laboratuvar',
        'brands' => ['Zzznomatchbrand'],
        'capacity' => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
    ],
]);
$f2Brands = $f2['state']['filters']['brands'] ?? [];
assertTrue(is_array($f2Brands) && in_array('Zzznomatchbrand', $f2Brands, true), 'F2 unknown brand kept');
assertSame('brand', (string) (diag($f2)['blocking_filter'] ?? ''), 'F2 blocking filter brand');
assertTrue(str_contains(mb_strtolower((string) ($f2['message'] ?? '')), 'marka'), 'F2 explains brand');
assertSame(200.0, grams($f2), 'F2 capacity not dropped');

$f3 = turn('özel grup ürünü', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'subcategory' => 'zzznomatchslug',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'laboratuvar',
        'subcategory' => 'zzznomatchslug',
    ],
]);
assertSame('zzznomatchslug', (string) ($f3['state']['filters']['subcategory'] ?? ''), 'F3 unresolved slug kept');
assertSame('taxonomy_mapping', (string) (diag($f3)['reason'] ?? ''), 'F3 mapping issue');
assertTrue((diag($f3)['taxonomy_valid'] ?? true) === false, 'F3 taxonomy invalid');
assertTrue(!str_contains((string) ($f3['message'] ?? ''), 'WhatsApp'), 'F3 no support handoff copy');

$asked = turn('beni arayın', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'laboratuvar'],
]);
assertSame('user_requested', (string) ($asked['state']['advisor']['handoff_reason'] ?? ''), 'F5 requested handoff still works');
assertTrue(hasType($asked, 'whatsapp_write') || hasType($asked, 'phone'), 'F5 requested handoff has a contact action');

echo "\nQuestion planner\n";
$q1 = turn('analitik terazi lazım 4 hane', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'subcategory' => null,
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'laboratuvar',
        'precision' => ['value' => '0.0001', 'unit' => 'g'],
    ],
]);
assertTrue(($q1['state']['advisor']['ready_for_products'] ?? true) !== true, 'Q1 not ready');
assertTrue(str_contains((string) ($q1['message'] ?? ''), 'kaç gram'), 'Q1 asks capacity');
assertTrue(!str_contains(mb_strtolower((string) ($q1['message'] ?? '')), 'okunabilirlik'), 'Q1 does not re-ask readability');

$q2 = turn('200 g analitik terazi', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'subcategory' => null,
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'laboratuvar',
        'capacity' => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
    ],
]);
assertTrue(($q2['state']['advisor']['ready_for_products'] ?? true) !== true, 'Q2 not ready');
assertTrue(str_contains(mb_strtolower((string) ($q2['message'] ?? '')), 'okunabilirlik'), 'Q2 asks readability');
assertTrue(!str_contains((string) ($q2['message'] ?? ''), 'kaç gram'), 'Q2 does not re-ask capacity');

$q3 = turn('200 g 0.0001 g analitik', [
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
assertTrue(($q3['state']['advisor']['ready_for_products'] ?? false) === true, 'Q3 ready');
assertSame('', (string) ($q3['state']['advisor']['next_question'] ?? 'x'), 'Q3 no extra question');
assertTrue(!str_contains((string) ($q3['message'] ?? ''), 'kaç gram'), 'Q3 no capacity question');
assertTrue(!str_contains(mb_strtolower((string) ($q3['message'] ?? '')), 'okunabilirlik'), 'Q3 no readability question');

$q4 = turn('plastik granülün nemini ölçmek istiyorum', [
    'intent' => 'product_discovery',
    'category' => 'test-ve-olcum',
    'subcategory' => null,
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'test-ve-olcum'],
]);
assertSame('nem-tayin', (string) ($q4['state']['filters']['subcategory'] ?? ''), 'Q4 nem-tayin');
$q4Text = mb_strtolower((string) ($q4['message'] ?? ''));
assertTrue(!str_contains($q4Text, 'ne tartacaksınız') && !str_contains($q4Text, 'ne tartacaksiniz'), 'Q4 not a generic lab question');
assertTrue(str_contains($q4Text, 'kurutma') || str_contains($q4Text, 'sıcaklığ'), 'Q4 asks a moisture-profile question');

$q5 = $d1;
assertTrue(!preg_match('/kaç adet|kac adet/iu', (string) ($q5['message'] ?? '')), 'Q5 does not re-ask four cells');
assertTrue(str_contains(mb_strtolower((string) ($q5['message'] ?? '')), 'toplam yük') || str_contains(mb_strtolower((string) ($q5['message'] ?? '')), 'toplam yuk'), 'Q5 asks total load');

$q6 = $d3;
assertTrue(!preg_match('/barkod gerekli/iu', (string) ($q6['message'] ?? '')), 'Q6 does not re-ask barcode');
assertTrue(str_contains(mb_strtolower((string) ($q6['message'] ?? '')), 'kilogram'), 'Q6 asks capacity');

$q7 = AssistantV2AdvisorTestSupport::runConversation(
    ['200 g analitik terazi', 'tamam bu kapasite kalsın'],
    [
        [
            'intent' => 'product_discovery',
            'category' => 'laboratuvar',
            'ready_for_product_results' => false,
            'needs_catalog_search' => true,
            'filter_updates' => [
                'category' => 'laboratuvar',
                'capacity' => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
            ],
        ],
        [
            'intent' => 'product_discovery',
            'category' => 'laboratuvar',
            'relation' => 'continue_search',
            'ready_for_product_results' => true,
            'needs_catalog_search' => true,
            'filter_updates' => ['category' => 'laboratuvar'],
        ],
    ]
);
$q7b = $q7[1] ?? [];
assertSame(200.0, grams($q7b), 'Q7 capacity remains');
assertTrue(!str_contains((string) ($q7b['message'] ?? ''), 'kaç gram'), 'Q7 does not ask capacity again');

echo "\nPrice\n";
$lab = AssistantV2AdvisorSearch::searchProducts(AssistantV2Filters::normalizeState(['category' => 'laboratuvar']), 0);
$p1 = turn('nem tayin cihazı kaç para', [
    'intent' => 'price',
    'category' => 'laboratuvar',
    'subcategory' => null,
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'laboratuvar'],
]);
assertSame('nem-tayin', (string) ($p1['state']['filters']['subcategory'] ?? ''), 'P1 subcategory');
$p1Total = (int) ($p1['state']['search_total'] ?? 0);
assertTrue($p1Total < (int) $lab['total'], 'P1 narrower than all lab products');
assertTrue($p1Total === (int) (AssistantV2Catalog::node('nem-tayin')['products'] ?? -1) || $p1Total > 0, 'P1 uses the moisture set');
$p1Text = mb_strtolower((string) ($p1['message'] ?? ''));
assertTrue(!preg_match('/\d+\s*(tl|₺|lira)/u', $p1Text), 'P1 does not invent a price');

echo "\n";
if ($failures > 0) {
    echo "{$failures} assertion(s) failed.\n";
    exit(1);
}
echo "All discovery batch tests passed.\n";
exit(0);
