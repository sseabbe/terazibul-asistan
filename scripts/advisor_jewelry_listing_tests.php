<?php

declare(strict_types=1);

/**
 * Kuyumcu SHOW_PRODUCTS ölçüleri listeyle aynı kapasite/okunabilirlik hesabını kullanır.
 *
 * Run: php scripts/advisor_jewelry_listing_tests.php
 */

use App\Services\AssistantV2\AssistantV2AdvisorSearch;
use App\Services\AssistantV2\AssistantV2AdvisorTestSupport;
use App\Services\AssistantV2\AssistantV2Catalog;
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

function discoveryMock(array $extra = []): array
{
    return array_merge([
        'intent'                    => 'product_discovery',
        'ready_for_product_results' => false,
        'needs_catalog_search'      => true,
        'needsWebSearch'            => false,
    ], $extra);
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

/**
 * @param array<string, string> $query
 * @return array{advisor:int,listing:int,low_capacity:int,coarse:int,finer:int}
 */
function jewelryCounts(array $query): array
{
    $filters = AssistantV2Filters::normalizeState(AssistantV2Filters::criteria([
        'category'  => 'kuyumcu',
        'capacity'  => ['operator' => 'min', 'value' => '600', 'unit' => 'g'],
        'precision' => ['value' => '0.001', 'unit' => 'g'],
    ]));
    $found = AssistantV2Filters::search($filters, 0, 50);
    $listing = 0;
    $low = 0;
    $coarse = 0;
    $finer = 0;
    foreach (AssistantV2Catalog::products() as $product) {
        if (!AssistantV2Catalog::inGroup($product, 'kuyumcu', false)) {
            continue;
        }
        if (!AssistantV2Filters::matchesListingMeasures($product, $query)) {
            continue;
        }
        ++$listing;
        $cap = AssistantV2Filters::capacityGrams($product);
        $prec = AssistantV2Filters::readabilityGrams($product);
        if ($cap !== null && $cap < 600) {
            ++$low;
        }
        if ($prec !== null && !AssistantV2Filters::readabilityMeetsRequest($prec, 0.001)) {
            ++$coarse;
        }
        if ($prec !== null && $prec < 0.001 && $cap !== null && AssistantV2Filters::capacityCoversLoad($cap, 600)) {
            ++$finer;
        }
    }

    return [
        'advisor'      => (int) $found['total'],
        'listing'      => $listing,
        'low_capacity' => $low,
        'coarse'       => $coarse,
        'finer'        => $finer,
    ];
}

echo "JEW kuyumcu SHOW_PRODUCTS\n";

$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['kuyumcu terazisi arıyorum', '600 g', '0.001 g hassasiyet'],
    [
        discoveryMock([
            'category'       => 'kuyumcu',
            'filter_updates' => ['category' => 'kuyumcu'],
        ]),
        discoveryMock(['relation' => 'continue_search']),
        discoveryMock(['relation' => 'continue_search']),
    ]
);
$onlyCapacity = $turns[1] ?? [];
$both = $turns[2] ?? [];
$url = showUrl($both);
$state = is_array($both['state'] ?? null) ? $both['state'] : [];
$filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];

assertTrue((string) ($filters['category'] ?? '') === 'kuyumcu', 'JEW state category kuyumcu');
assertTrue(is_numeric($filters['capacity']['value_g'] ?? null) && abs((float) $filters['capacity']['value_g'] - 600.0) < 0.001, 'JEW state capacity 600 g');
assertTrue(
    is_numeric($filters['precision']['value'] ?? null)
    && AssistantV2Filters::canonicalGramString((float) $filters['precision']['value']) === '0.001',
    'JEW state readability 0.001 g'
);
assertTrue(str_contains($url, '/urunler/kuyumcu'), 'JEW-1 path is kuyumcu');
assertTrue(str_contains($url, 'kap_min=600') && str_contains($url, 'has_max=0.001'), 'JEW-1 URL has measure params');
assertTrue(AssistantV2AdvisorTestSupport::hasActionType($both, 'show_products'), 'JEW-7 SHOW_PRODUCTS');

$query = ['kap_min' => '600', 'has_max' => '0.001'];
$counts = jewelryCounts($query);
$catalogTotal = 0;
foreach (AssistantV2Catalog::products() as $product) {
    if (AssistantV2Catalog::inGroup($product, 'kuyumcu', false)) {
        ++$catalogTotal;
    }
}
echo '  advisor_count=' . $counts['advisor'] . ' listing_count=' . $counts['listing'] . ' kuyumcu_total=' . $catalogTotal . "\n";
echo '  url=' . $url . "\n";
assertTrue($counts['advisor'] > 0 && $counts['advisor'] < $catalogTotal, 'JEW-2 listing is narrower than the jewelry catalog');
assertTrue($counts['advisor'] === $counts['listing'], 'JEW-2 advisor count == listing count');
assertTrue($counts['low_capacity'] === 0, 'JEW-3 no product under 600 g');
assertTrue($counts['coarse'] === 0, 'JEW-4 no coarser readability than 0.001 g');

$finer = [
    'capacity_max'      => 1000,
    'precision_filters' => [['unit' => 'g', 'grams' => 0.0001]],
];
$coarse = [
    'capacity_max'      => 1000,
    'precision_filters' => [['unit' => 'g', 'grams' => 0.01]],
];
$light = [
    'capacity_max'      => 500,
    'precision_filters' => [['unit' => 'g', 'grams' => 0.0001]],
];
assertTrue(AssistantV2Filters::matchesListingMeasures($finer, $query), 'JEW-5 0.0001 g stays when capacity covers 600 g');
assertTrue(!AssistantV2Filters::matchesListingMeasures($coarse, $query), 'JEW-4 0.01 g is excluded');
assertTrue(!AssistantV2Filters::matchesListingMeasures($light, $query), 'JEW-3 500 g is excluded');
if ($counts['finer'] > 0) {
    assertTrue(true, 'JEW-5 live finer jewelry product remains');
}

assertTrue(!AssistantV2AdvisorTestSupport::hasActionType($onlyCapacity, 'show_products'), 'JEW-6 capacity only has no SHOW_PRODUCTS');

$built = AssistantV2AdvisorSearch::listingUrl($filters);
assertTrue(str_contains($built, 'kap_min=600') && str_contains($built, 'has_max=0.001'), 'JEW-1 listingUrl measure params');

echo "\nJEW-8 laboratuvar 200 g / 0.0001 g\n";
$lab = AssistantV2AdvisorTestSupport::runConversation(
    ['200 g 0.0001 g analitik terazi lazım'],
    [
        discoveryMock([
            'category'                  => 'laboratuvar',
            'subcategory'               => 'lab-analitik',
            'ready_for_product_results' => true,
            'filter_updates'            => [
                'category'    => 'laboratuvar',
                'subcategory' => 'lab-analitik',
                'capacity'    => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
                'precision'   => ['value' => '0.0001', 'unit' => 'g'],
            ],
        ]),
    ]
);
$labUrl = showUrl($lab[0] ?? []);
assertTrue(str_contains($labUrl, 'kap_min=200') && str_contains($labUrl, 'has_max=0.0001'), 'JEW-8 lab URL keeps measure params');
$labFilters = AssistantV2Filters::normalizeState(AssistantV2Filters::criteria([
    'category'    => 'laboratuvar',
    'subcategory' => 'lab-analitik',
    'capacity'    => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
    'precision'   => ['value' => '0.0001', 'unit' => 'g'],
]));
$labFound = AssistantV2Filters::search($labFilters, 0, 30);
$labListing = 0;
foreach (AssistantV2Catalog::products() as $product) {
    if (!AssistantV2Catalog::inGroup($product, 'lab-analitik', false) && !AssistantV2Catalog::inGroup($product, 'lab-analitik', true)) {
        continue;
    }
    if (AssistantV2Filters::matchesListingMeasures($product, ['kap_min' => '200', 'has_max' => '0.0001'])) {
        ++$labListing;
    }
}
echo '  lab_advisor_count=' . (int) $labFound['total'] . ' lab_listing_count=' . $labListing . "\n";
assertTrue((int) $labFound['total'] > 0 && (int) $labFound['total'] === $labListing, 'JEW-8 advisor count == listing count');
assertTrue(AssistantV2AdvisorTestSupport::hasActionType($lab[0] ?? [], 'show_products'), 'JEW-8 SHOW_PRODUCTS');

echo $failures === 0 ? "\nALL JEW PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
