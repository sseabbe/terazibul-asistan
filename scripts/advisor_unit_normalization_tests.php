<?php

declare(strict_types=1);

/**
 * Weight / readability normalization for advisor catalog filters.
 *
 * Run: php scripts/advisor_unit_normalization_tests.php
 */

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

function nanoOf(?float $grams): ?int
{
    if ($grams === null) {
        return null;
    }

    return (int) round($grams * 1000000000);
}

function assertGrams(mixed $value, mixed $unit, int $nano, string $label): void
{
    $grams = AssistantV2Filters::normalizeWeightGrams($value, $unit);
    assertSame($nano, nanoOf($grams), $label);
}

echo "Weight normalization\n";

assertGrams('1', 'kg', 1000000000000, '1 kg → 1000 g');
assertGrams('200', 'g', 200000000000, '200 g');
assertGrams('200', 'gr', 200000000000, '200 gr');
assertGrams('200', 'gram', 200000000000, '200 gram');
assertGrams('0.1', 'mg', 100000, '0.1 mg → 0.0001 g');
assertGrams('0,1', 'mg', 100000, '0,1 mg → 0.0001 g');
assertGrams('0.1', 'miligram', 100000, '0.1 miligram');
assertGrams('100', 'µg', 100000, '100 µg → 0.0001 g');
assertGrams('100', 'μg', 100000, '100 μg → 0.0001 g');
assertGrams('100', 'ug', 100000, '100 ug → 0.0001 g');
assertGrams('0.0001', 'g', 100000, '0.0001 g');
assertGrams('0,0001', 'g', 100000, '0,0001 g');
assertGrams('0,0001', 'gr', 100000, '0,0001 gr');
assertGrams(0.0001, 'g', 100000, 'float 0.0001 g');
assertGrams('500', 'mg', 500000000, '500 mg → 0.5 g');
assertGrams('0.001', 'g', 1000000, '0.001 g');
assertGrams('0,001', 'g', 1000000, '0,001 g');

echo "\nCapacity and readability stay separate\n";

$a = AssistantV2Filters::criteria([
    'capacity'  => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
    'precision' => ['value' => '0.0001', 'unit' => 'g'],
]);
assertSame(200000000000, nanoOf((float) $a['capacity']['value_g']), 'A capacity 200 g');
assertSame(100000, $a['precision']['nano'], 'A readability 0.0001 g');
assertSame('g', $a['precision']['unit'], 'A readability unit g');
assertTrue(($a['precision']['unresolved'] ?? false) !== true, 'A precision resolved');

$b = AssistantV2Filters::criteria([
    'capacity'  => ['operator' => 'min', 'value' => '200', 'unit' => 'gr'],
    'precision' => ['value' => '0,0001', 'unit' => 'gr'],
]);
assertSame($a['capacity']['value_g'], $b['capacity']['value_g'], 'B capacity matches A');
assertSame($a['precision']['nano'], $b['precision']['nano'], 'B readability matches A');
assertSame('0,0001 gr', $b['precision']['original'], 'B original readability kept');

$c = AssistantV2Filters::criteria([
    'capacity'  => ['operator' => 'min', 'value' => '200', 'unit' => 'gram'],
    'precision' => ['value' => '0.1', 'unit' => 'mg'],
]);
assertSame(200000000000, nanoOf((float) $c['capacity']['value_g']), 'C capacity 200 g');
assertSame(100000, $c['precision']['nano'], 'C readability 0.0001 g');

$d = AssistantV2Filters::criteria([
    'capacity'  => ['operator' => 'min', 'value' => '200', 'unit' => 'gram'],
    'precision' => ['value' => '100', 'unit' => 'µg'],
]);
assertSame(200000000000, nanoOf((float) $d['capacity']['value_g']), 'D capacity 200 g');
assertSame(100000, $d['precision']['nano'], 'D readability 0.0001 g');

$e = AssistantV2Filters::criteria([
    'capacity' => ['operator' => 'min', 'value' => '1', 'unit' => 'kg'],
]);
assertSame(1000000000000, nanoOf((float) $e['capacity']['value_g']), 'E capacity 1000 g');
assertTrue(!isset($e['precision']), 'E no readability invented');

$f = AssistantV2Filters::criteria([
    'precision' => ['value' => '0.001', 'unit' => 'g'],
]);
assertSame(1000000, $f['precision']['nano'], 'F readability 0.001 g');
assertTrue(!isset($f['capacity']), 'F no capacity invented');

$g = AssistantV2Filters::criteria([
    'precision' => ['value' => '0,001', 'unit' => 'g'],
]);
assertSame($f['precision']['nano'], $g['precision']['nano'], 'G matches F');

$h = AssistantV2Filters::criteria([
    'capacity' => ['operator' => 'min', 'value' => '500', 'unit' => 'mg'],
]);
assertSame(500000000, nanoOf((float) $h['capacity']['value_g']), 'H sample weight 0.5 g stays capacity');
assertTrue(!isset($h['precision']), 'H mg is not readability');

$legacy = AssistantV2Filters::criteria([
    'capacity' => ['operator' => 'min', 'value_g' => 200],
]);
assertSame(200000000000, nanoOf((float) $legacy['capacity']['value_g']), 'value_g stays grams');

echo "\nNo silent drop\n";

$comma = AssistantV2Filters::criteria([
    'precision' => ['value' => '0,0001', 'unit' => 'gr'],
]);
assertTrue(isset($comma['precision']), 'comma readability kept');
assertSame(100000, $comma['precision']['nano'] ?? null, 'comma readability normalized');

$bad = AssistantV2Filters::criteria([
    'category'  => 'laboratuvar',
    'precision' => ['value' => 'abc', 'unit' => 'g'],
    'capacity'  => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
]);
assertTrue(($bad['precision']['unresolved'] ?? false) === true, 'unparseable readability marked');
assertTrue(array_key_exists('original', $bad['precision']), 'unparseable original kept');
assertSame(200000000000, nanoOf((float) $bad['capacity']['value_g']), 'valid capacity kept beside invalid readability');

try {
    $blocked = AssistantV2Filters::search(AssistantV2Filters::normalizeState($bad), 0, 5);
    assertSame(0, $blocked['total'], 'invalid readability does not search products');
} catch (Throwable $e) {
    assertTrue(false, 'invalid readability search threw ' . $e->getMessage());
}

echo "\nComparison direction\n";

assertTrue(AssistantV2Filters::capacityCoversLoad(220, 200), 'capacity 220 covers 200');
assertTrue(AssistantV2Filters::capacityCoversLoad(200, 200), 'capacity 200 covers 200');
assertTrue(!AssistantV2Filters::capacityCoversLoad(100, 200), 'capacity 100 does not cover 200');
assertTrue(AssistantV2Filters::readabilityMeetsRequest(0.0001, 0.0001), 'readability equal');
assertTrue(AssistantV2Filters::readabilityMeetsRequest(0.00001, 0.0001), 'finer readability passes');
assertTrue(!AssistantV2Filters::readabilityMeetsRequest(0.001, 0.0001), 'coarser readability fails');

echo "\nCatalog search 200 g / 0.0001 g\n";

$searchFilters = AssistantV2Filters::normalizeState(AssistantV2Filters::criteria([
    'category'    => 'laboratuvar',
    'subcategory' => 'lab-analitik',
    'capacity'    => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
    'precision'   => ['value' => '0.0001', 'unit' => 'g'],
]));
assertSame(100000, $searchFilters['precision']['nano'], 'search filter readability nano');
assertSame(200.0, (float) $searchFilters['capacity']['value_g'], 'search filter capacity grams');

try {
    $found = AssistantV2Filters::search($searchFilters, 0, 8);
    assertTrue($found['total'] > 0, 'search_total > 0');
    assertTrue($found['total'] < 400, 'search_total is constrained');
    $checked = 0;
    foreach ($found['page'] as $product) {
        $grams = AssistantV2Filters::capacityGrams($product);
        assertTrue($grams !== null && AssistantV2Filters::capacityCoversLoad($grams, 200.0), 'result capacity covers 200 g');
        $readability = AssistantV2Filters::readabilityGrams($product);
        assertTrue(
            $readability !== null && AssistantV2Filters::readabilityMeetsRequest($readability, 0.0001),
            'result readability <= 0.0001 g'
        );
        ++$checked;
    }
    assertTrue($checked > 0, 'search page has products');
} catch (Throwable $e) {
    assertTrue(false, 'catalog search threw ' . $e->getMessage());
}

echo "\nConversation chain\n";

$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['200 g 0.0001 g analitik terazi lazım'],
    [[
        'intent'                    => 'product_discovery',
        'category'                  => 'laboratuvar',
        'subcategory'               => 'lab-analitik',
        'ready_for_product_results' => true,
        'needs_catalog_search'      => true,
        'filter_updates'            => [
            'category'    => 'laboratuvar',
            'subcategory' => 'lab-analitik',
            'capacity'    => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
            'precision'   => ['value' => '0,0001', 'unit' => 'gr'],
        ],
        'needsWebSearch'            => false,
    ]]
);
$turn = $turns[0] ?? [];
$filters = is_array($turn['state']['filters'] ?? null) ? $turn['state']['filters'] : [];
assertSame(100000, $filters['precision']['nano'] ?? null, 'state readability 0.0001 g');
assertSame(200.0, (float) ($filters['capacity']['value_g'] ?? 0), 'state capacity 200 g');
assertTrue(($turn['state']['advisor']['ready_for_products'] ?? false) === true, 'ready_for_products kept');
assertTrue((int) ($turn['state']['search_total'] ?? 0) > 0, 'conversation search_total');
assertTrue(AssistantV2AdvisorTestSupport::hasActionType($turn, 'show_products'), 'SHOW_PRODUCTS');
assertTrue(!str_contains((string) ($turn['message'] ?? ''), 'kayıtlara bakılamıyor'), 'no generic error');

$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['200 gr 0,0001 gr analitik terazi lazım'],
    [[
        'intent'                    => 'product_discovery',
        'category'                  => 'laboratuvar',
        'subcategory'               => 'lab-analitik',
        'ready_for_product_results' => true,
        'needs_catalog_search'      => true,
        'filter_updates'            => [
            'category'    => 'laboratuvar',
            'subcategory' => 'lab-analitik',
            'capacity'    => ['operator' => 'min', 'value' => '200', 'unit' => 'gr'],
            'precision'   => ['value' => '0,0001', 'unit' => 'gr'],
        ],
        'needsWebSearch'            => false,
    ]]
);
$filters = is_array($turns[0]['state']['filters'] ?? null) ? $turns[0]['state']['filters'] : [];
assertSame(100000, $filters['precision']['nano'] ?? null, 'B state readability');
assertSame(200.0, (float) ($filters['capacity']['value_g'] ?? 0), 'B state capacity');

echo "\nRegressions\n";

$regress = static function (string $message, array $mock): array {
    $turns = AssistantV2AdvisorTestSupport::runConversation([$message], [$mock]);
    $turn = $turns[0] ?? [];
    $advisor = is_array($turn['state']['advisor'] ?? null) ? $turn['state']['advisor'] : [];
    $filters = is_array($turn['state']['filters'] ?? null) ? $turn['state']['filters'] : [];

    return [
        'category'    => (string) ($advisor['category'] ?? ''),
        'subcategory' => (string) ($advisor['subcategory'] ?? ''),
        'filter_sub'  => (string) ($filters['subcategory'] ?? ''),
        'intent'      => (string) ($advisor['intent'] ?? ''),
        'ready'       => ($advisor['ready_for_products'] ?? false) === true,
        'source'      => (string) (($turn['debug']['classification_source'] ?? '')),
    ];
};

$r1 = $regress('plastik granülün nemini ölçmek istiyorum', [
    'intent'                    => 'product_discovery',
    'category'                  => 'laboratuvar',
    'subcategory'               => 'nem-tayin',
    'ready_for_product_results' => false,
    'needs_catalog_search'      => true,
    'needsWebSearch'            => false,
]);
assertSame('laboratuvar', $r1['category'], 'R1 category');
assertSame('nem-tayin', $r1['subcategory'], 'R1 moisture subcategory');
assertSame('nem-tayin', $r1['filter_sub'], 'R1 filter subcategory');

$r2 = $regress('tankın altına dört load cell bağlayacağız', [
    'intent'                    => 'product_discovery',
    'category'                  => 'endustriyel',
    'subcategory'               => 'load-cell',
    'ready_for_product_results' => false,
    'needs_catalog_search'      => true,
    'needsWebSearch'            => false,
]);
assertSame('endustriyel', $r2['category'], 'R2 category');
assertSame('load-cell', $r2['subcategory'], 'R2 subcategory');

$r3 = $regress('kasap için barkod basan terazi arıyorum', [
    'intent'                    => 'product_discovery',
    'category'                  => 'perakende',
    'subcategory'               => 'barkodlu-teraziler',
    'ready_for_product_results' => false,
    'needs_catalog_search'      => true,
    'needsWebSearch'            => false,
]);
assertSame('perakende', $r3['category'], 'R3 category');
assertSame('barkodlu-teraziler', $r3['subcategory'], 'R3 subcategory');

$r4 = $regress('Dolar kaç TL?', [
    'intent'                    => 'off_topic',
    'category'                  => null,
    'subcategory'               => null,
    'ready_for_product_results' => false,
    'needs_catalog_search'      => false,
    'needsWebSearch'            => false,
]);
assertSame('off_topic', $r4['intent'], 'R4 off_topic');
assertSame('', $r4['category'], 'R4 no category');

$r5 = $regress('laboratuvar terazisi arıyorum', [
    'intent'                    => 'product_discovery',
    'category'                  => 'laboratuvar',
    'subcategory'               => null,
    'ready_for_product_results' => false,
    'missing_information'       => ['kapasite', 'okuma değeri'],
    'needs_catalog_search'      => true,
    'needsWebSearch'            => false,
]);
assertSame('laboratuvar', $r5['category'], 'R5 category');
assertSame('', $r5['subcategory'], 'R5 no subcategory');
assertTrue(!$r5['ready'], 'R5 clarification');

echo "\n";
if ($failures > 0) {
    echo "{$failures} assertion(s) failed.\n";
    exit(1);
}

echo "All unit normalization tests passed.\n";
exit(0);
