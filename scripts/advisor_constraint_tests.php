<?php

declare(strict_types=1);

/**
 * Group A #4 state replace/clear, #1 listing filters, #2 strict numeric match.
 *
 * Run: php scripts/advisor_constraint_tests.php
 */

use App\Services\AssistantV2\AssistantV2AdvisorSearch;
use App\Services\AssistantV2\AssistantV2AdvisorTestSupport;
use App\Services\AssistantV2\AssistantV2Filters;

require dirname(__DIR__) . '/vendor/autoload.php';

\Core\Env::load(dirname(__DIR__) . '/.env');
$app = new \Core\Application(dirname(__DIR__));
$app->boot();

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

function nanoOf(float $grams): int
{
    return (int) round($grams * 1000000000);
}

/**
 * @param list<array{0:string,1:array<string,mixed>}> $turns
 */
function converse(array $turns): array
{
    $messages = [];
    $mocks = [];
    foreach ($turns as $turn) {
        $messages[] = $turn[0];
        $mocks[] = $turn[1];
    }

    return AssistantV2AdvisorTestSupport::runConversation($messages, $mocks);
}

function showUrl(array $payload): string
{
    foreach (is_array($payload['actions'] ?? null) ? $payload['actions'] : [] as $action) {
        if (is_array($action) && ($action['type'] ?? '') === 'show_products') {
            return (string) ($action['url'] ?? '');
        }
    }

    return '';
}

echo "State replace and clear\n";

$turns = converse([
    ['500 gram yeter', [
        'intent'                    => 'product_discovery',
        'category'                  => 'laboratuvar',
        'subcategory'               => 'lab-analitik',
        'relation'                  => 'continue_search',
        'ready_for_product_results' => false,
        'needs_catalog_search'      => true,
        'filter_updates'            => [
            'category'    => 'laboratuvar',
            'subcategory' => 'lab-analitik',
            'capacity'    => ['operator' => 'min', 'value' => '500', 'unit' => 'g'],
        ],
    ]],
    ['Aslında 1 kilo lazım', [
        'intent'                    => 'product_discovery',
        'relation'                  => 'modify_search',
        'ready_for_product_results' => true,
        'needs_catalog_search'      => true,
        'filter_updates'            => [
            'capacity' => ['operator' => 'min', 'value' => '1', 'unit' => 'kg'],
        ],
    ]],
]);
$filters = $turns[1]['state']['filters'] ?? [];
assertSame(1000000000000, nanoOf((float) ($filters['capacity']['value_g'] ?? 0)), 'S1 final 1000 g');
assertTrue(nanoOf((float) ($filters['capacity']['value_g'] ?? 0)) !== 500000000000, 'S1 500 g not active');
assertSame('laboratuvar', (string) ($filters['category'] ?? ''), 'S1 category kept');

$turns = converse([
    ['Axis olsun', [
        'intent'                    => 'product_discovery',
        'category'                  => 'laboratuvar',
        'relation'                  => 'continue_search',
        'ready_for_product_results' => false,
        'needs_catalog_search'      => true,
        'filter_updates'            => [
            'category' => 'laboratuvar',
            'brands'   => ['Axis'],
        ],
    ]],
    ['Marka fark etmez', [
        'intent'                    => 'product_discovery',
        'relation'                  => 'modify_search',
        'ready_for_product_results' => false,
        'needs_catalog_search'      => true,
        'remove_constraints'        => ['brand'],
        'filter_updates'            => [],
    ]],
]);
$filters = $turns[1]['state']['filters'] ?? [];
assertSame([], $filters['brands'] ?? null, 'S2 brand cleared');
assertSame('laboratuvar', (string) ($filters['category'] ?? ''), 'S2 category kept');

$turns = converse([
    ['0.001 g yeter', [
        'intent'                    => 'product_discovery',
        'category'                  => 'laboratuvar',
        'relation'                  => 'continue_search',
        'ready_for_product_results' => false,
        'filter_updates'            => [
            'category'  => 'laboratuvar',
            'precision' => ['value' => '0.001', 'unit' => 'g'],
        ],
    ]],
    ['Aslında 0.0001 g lazım', [
        'intent'                    => 'product_discovery',
        'relation'                  => 'modify_search',
        'ready_for_product_results' => false,
        'filter_updates'            => [
            'precision' => ['value' => '0.0001', 'unit' => 'g'],
        ],
    ]],
]);
$filters = $turns[1]['state']['filters'] ?? [];
assertSame(100000, $filters['precision']['nano'] ?? null, 'S3 readability 0.0001 g');
assertTrue(($filters['precision']['nano'] ?? null) !== 1000000, 'S3 old 0.001 g not active');

$turns = converse([
    ['200 gram', [
        'intent'                    => 'product_discovery',
        'category'                  => 'laboratuvar',
        'relation'                  => 'continue_search',
        'ready_for_product_results' => false,
        'filter_updates'            => [
            'category' => 'laboratuvar',
            'capacity' => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
        ],
    ]],
    ['Yanlış söyledim, 500 gram olacak', [
        'intent'                    => 'product_discovery',
        'relation'                  => 'modify_search',
        'ready_for_product_results' => false,
        'filter_updates'            => [
            'capacity' => ['operator' => 'min', 'value' => '500', 'unit' => 'gram'],
        ],
    ]],
]);
$filters = $turns[1]['state']['filters'] ?? [];
assertSame(500000000000, nanoOf((float) ($filters['capacity']['value_g'] ?? 0)), 'S4 final 500 g');

echo "\nSHOW_PRODUCTS filters\n";

$turns = converse([
    ['200 g 0.0001 g analitik terazi lazım', [
        'intent'                    => 'product_discovery',
        'category'                  => 'laboratuvar',
        'subcategory'               => 'lab-analitik',
        'relation'                  => 'continue_search',
        'ready_for_product_results' => true,
        'needs_catalog_search'      => true,
        'filter_updates'            => [
            'category'    => 'laboratuvar',
            'subcategory' => 'lab-analitik',
            'capacity'    => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
            'precision'   => ['value' => '0.0001', 'unit' => 'g'],
        ],
    ]],
]);
$turn = $turns[0] ?? [];
$filters = is_array($turn['state']['filters'] ?? null) ? $turn['state']['filters'] : [];
$url = showUrl($turn);
$params = AssistantV2Filters::listingMeasureParams($filters);
assertSame(200000000000, nanoOf((float) ($filters['capacity']['value_g'] ?? 0)), 'F1 capacity 200 g');
assertSame(100000, $filters['precision']['nano'] ?? null, 'F1 readability 0.0001 g');
assertSame('200', $params['kap_min'] ?? '', 'F1 kap_min');
assertSame('0.0001', $params['has_max'] ?? '', 'F1 has_max');
echo "  URL {$url}\n";
assertTrue($url !== '' && str_contains($url, 'kap_min=200') && str_contains($url, 'has_max=0.0001'), 'F1 SHOW_PRODUCTS keeps measures');
assertTrue(str_contains($url, 'lab-analitik'), 'F1 subcategory in URL');
assertTrue(!str_contains($url, 'marka='), 'F2 no brand query');
assertTrue(!preg_match('#/urunler/axis/#i', $url), 'F2 no brand path');
assertTrue((int) ($turn['state']['search_total'] ?? 0) > 0, 'F1 search_total');
assertTrue(AssistantV2AdvisorTestSupport::hasActionType($turn, 'show_products'), 'F1 SHOW_PRODUCTS');

$branded = AssistantV2Filters::normalizeState(AssistantV2Filters::criteria([
    'category'    => 'laboratuvar',
    'subcategory' => 'lab-analitik',
    'brands'      => ['Axis'],
    'capacity'    => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
    'precision'   => ['value' => '0.0001', 'unit' => 'g'],
]));
$brandUrl = AssistantV2AdvisorSearch::listingUrl($branded);
assertTrue($branded['brands'] !== [] && (str_contains($brandUrl, '/axis/') || str_contains($brandUrl, 'marka=')), 'F3 brand carried');
assertTrue(str_contains($brandUrl, 'kap_min=200') && str_contains($brandUrl, 'has_max=0.0001'), 'F3 measures stay with brand');

echo "\nStrict matching\n";

assertTrue(!AssistantV2Filters::capacityCoversLoad(199.96, 200), 'N1 199.96 g does not cover 200 g');
assertTrue(AssistantV2Filters::capacityCoversLoad(200, 200), 'N2 200 g covers 200 g');
assertTrue(AssistantV2Filters::readabilityMeetsRequest(0.0001, 0.0001), 'N3 equal readability');
assertTrue(!AssistantV2Filters::readabilityMeetsRequest(0.0002, 0.0001), 'N4 coarser readability fails');
assertTrue(AssistantV2Filters::readabilityMeetsRequest(0.00001, 0.0001), 'N5 finer readability passes');

echo "\nRegressions\n";

$regress = static function (string $message, array $mock): array {
    $turns = converse([[$message, $mock]]);
    $turn = $turns[0] ?? [];
    $advisor = is_array($turn['state']['advisor'] ?? null) ? $turn['state']['advisor'] : [];

    return [
        'category'    => (string) ($advisor['category'] ?? ''),
        'subcategory' => (string) ($advisor['subcategory'] ?? ''),
        'intent'      => (string) ($advisor['intent'] ?? ''),
        'ready'       => ($advisor['ready_for_products'] ?? false) === true,
        'total'       => (int) ($turn['state']['search_total'] ?? 0),
        'show'        => AssistantV2AdvisorTestSupport::hasActionType($turn, 'show_products'),
        'message'     => (string) ($turn['message'] ?? ''),
    ];
};

$r1 = $regress('plastik granülün nemini ölçmek istiyorum', [
    'intent' => 'product_discovery', 'category' => 'laboratuvar', 'subcategory' => 'nem-tayin',
    'ready_for_product_results' => false, 'needs_catalog_search' => true,
]);
assertSame('nem-tayin', $r1['subcategory'], 'R1 moisture');

$r2 = $regress('tankın altına dört load cell bağlayacağız', [
    'intent' => 'product_discovery', 'category' => 'endustriyel', 'subcategory' => 'load-cell',
    'ready_for_product_results' => false, 'needs_catalog_search' => true,
]);
assertSame('load-cell', $r2['subcategory'], 'R2 load cell');

$r3 = $regress('kasap için barkod basan terazi arıyorum', [
    'intent' => 'product_discovery', 'category' => 'perakende', 'subcategory' => 'barkodlu-teraziler',
    'ready_for_product_results' => false, 'needs_catalog_search' => true,
]);
assertSame('barkodlu-teraziler', $r3['subcategory'], 'R3 retail');

$r4 = $regress('Dolar kaç TL?', [
    'intent' => 'off_topic', 'category' => null, 'subcategory' => null,
    'ready_for_product_results' => false, 'needs_catalog_search' => false,
]);
assertSame('off_topic', $r4['intent'], 'R4 off_topic');
assertSame('', $r4['category'], 'R4 no category');

$r5 = $regress('200 g 0.0001 g analitik terazi lazım', [
    'intent' => 'product_discovery', 'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
    'ready_for_product_results' => true, 'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
        'capacity' => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
        'precision' => ['value' => '0.0001', 'unit' => 'g'],
    ],
]);
assertTrue($r5['ready'], 'R5 ready');
assertTrue($r5['total'] > 0, 'R5 catalog result');
assertTrue($r5['show'], 'R5 SHOW_PRODUCTS');
assertTrue(!str_contains($r5['message'], 'kayıtlara bakılamıyor'), 'R5 no generic error');

echo "\n";
if ($failures > 0) {
    echo "{$failures} assertion(s) failed.\n";
    exit(1);
}

echo "All constraint tests passed.\n";
exit(0);
