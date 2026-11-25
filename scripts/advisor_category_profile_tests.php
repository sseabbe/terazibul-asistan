<?php

declare(strict_types=1);

use App\Services\AssistantV2\AssistantV2AdvisorSearch;
use App\Services\AssistantV2\AssistantV2AdvisorState;
use App\Services\AssistantV2\AssistantV2AdvisorTestSupport;
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

function cat(array $payload): string
{
    return (string) ($payload['state']['advisor']['category'] ?? '');
}

function sub(array $payload): string
{
    return (string) ($payload['state']['filters']['subcategory'] ?? $payload['state']['advisor']['subcategory'] ?? '');
}

echo "Classification\n";

$n1 = turn('plastik granülün nemini ölçmek istiyorum', [
    'intent' => 'product_discovery',
    'category' => 'test-ve-olcum',
    'subcategory' => null,
    'ready_for_product_results' => false,
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'test-ve-olcum'],
]);
assertSame('laboratuvar', cat($n1), 'N1 category');
assertSame('nem-tayin', sub($n1), 'N1 subcategory');

$n2 = turn('gida numunesinin rutubetini olcmek istiyorum', [
    'intent' => 'product_discovery',
    'category' => 'perakende',
    'subcategory' => null,
    'ready_for_product_results' => false,
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'perakende'],
]);
assertSame('laboratuvar', cat($n2), 'N2 category');
assertSame('nem-tayin', sub($n2), 'N2 subcategory');

$n3 = turn('nem tayin cihazı kaç para', [
    'intent' => 'price',
    'category' => 'laboratuvar',
    'subcategory' => null,
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'laboratuvar'],
]);
assertSame('laboratuvar', cat($n3), 'N3 category');
assertSame('nem-tayin', sub($n3), 'N3 subcategory');
$n3Url = (string) ($n3['state']['products_listing_url'] ?? '');
assertTrue(str_contains($n3Url, 'nem-tayin'), 'N3 listing is nem-tayin');
$broad = AssistantV2AdvisorSearch::searchProducts(AssistantV2Filters::normalizeState(['category' => 'laboratuvar']), 0);
assertTrue((int) ($n3['state']['search_total'] ?? 0) < (int) $broad['total'], 'N3 narrower than all lab products');

$l1 = turn('tankın altına dört load cell bağlayacağız', [
    'intent' => 'product_discovery',
    'category' => 'endustriyel',
    'subcategory' => null,
    'ready_for_product_results' => false,
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'endustriyel'],
]);
assertSame('endustriyel', cat($l1), 'L1 category');
assertSame('load-cell', sub($l1), 'L1 subcategory');

$l2 = turn('500 kg yük hücresi lazım', [
    'intent' => 'product_discovery',
    'category' => 'aksesuar',
    'subcategory' => null,
    'ready_for_product_results' => false,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'aksesuar',
        'capacity' => ['operator' => 'min', 'value_g' => 500000],
    ],
]);
assertSame('endustriyel', cat($l2), 'L2 category');
assertSame('load-cell', sub($l2), 'L2 subcategory');
assertTrue(cat($l2) !== 'aksesuar' && sub($l2) !== 'aksesuar', 'L2 not aksesuar');

$p1 = turn('kasap için barkod basan terazi arıyorum', [
    'intent' => 'product_discovery',
    'category' => 'perakende',
    'subcategory' => null,
    'ready_for_product_results' => false,
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'perakende'],
]);
assertSame('perakende', cat($p1), 'P1 category');
assertSame('barkodlu-teraziler', sub($p1), 'P1 subcategory');

$b2 = turn('marka önemli değil 220g 0.1mg analitik', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'subcategory' => 'yari-analitik-teraziler',
    'ready_for_product_results' => false,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'laboratuvar',
        'subcategory' => 'yari-analitik-teraziler',
        'capacity' => ['operator' => 'min', 'value' => '220', 'unit' => 'g'],
        'precision' => ['value' => '0.1', 'unit' => 'mg'],
    ],
]);
assertSame('laboratuvar', cat($b2), 'B2 category');
assertSame('lab-analitik', sub($b2), 'B2 subcategory');
assertTrue(!str_contains(sub($b2), 'yari'), 'B2 not semi-analytical');

echo "\nMulti-turn\n";
$mt = AssistantV2AdvisorTestSupport::runConversation(
    ['200 g analitik terazi bakıyorum', 'vazgeçtim, plastik granül nemi ölçeceğim'],
    [
        [
            'intent' => 'product_discovery',
            'category' => 'laboratuvar',
            'subcategory' => 'lab-analitik',
            'ready_for_product_results' => false,
            'needs_catalog_search' => true,
            'filter_updates' => [
                'category' => 'laboratuvar',
                'subcategory' => 'lab-analitik',
                'capacity' => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
            ],
        ],
        [
            'intent' => 'product_discovery',
            'category' => 'laboratuvar',
            'subcategory' => 'lab-analitik',
            'relation' => 'continue_search',
            'ready_for_product_results' => false,
            'needs_catalog_search' => true,
            'filter_updates' => [
                'category' => 'laboratuvar',
                'subcategory' => 'lab-analitik',
            ],
        ],
    ]
);
$mt2 = $mt[1] ?? [];
assertSame('nem-tayin', sub($mt2), 'MT3 final subcategory');
assertSame('laboratuvar', cat($mt2), 'MT3 category');
assertTrue(!is_numeric($mt2['state']['filters']['capacity']['value_g'] ?? null), 'MT3 old capacity cleared');
assertTrue(!str_contains(sub($mt2), 'analitik'), 'MT3 analytical context gone');

echo "\nGeneralization\n";
$g1 = turn('laboratuvar terazisi lazım', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'subcategory' => null,
    'ready_for_product_results' => false,
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'laboratuvar'],
]);
assertSame('laboratuvar', cat($g1), 'G1 category');
assertSame('', sub($g1), 'G1 no invented subcategory');

$g2 = turn('endüstriyel terazi lazım', [
    'intent' => 'product_discovery',
    'category' => 'endustriyel',
    'subcategory' => null,
    'ready_for_product_results' => false,
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'endustriyel'],
]);
assertSame('endustriyel', cat($g2), 'G2 category');
assertSame('', sub($g2), 'G2 no load-cell');

$g3 = turn('market için normal tartım terazisi', [
    'intent' => 'product_discovery',
    'category' => 'perakende',
    'subcategory' => null,
    'ready_for_product_results' => false,
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'perakende'],
]);
assertSame('perakende', cat($g3), 'G3 category');
assertTrue(sub($g3) !== 'barkodlu-teraziler', 'G3 no barcode subcategory');

echo "\nProfile hints\n";
$hints = AssistantV2AdvisorState::profileHints('laboratuvar', 'nem-tayin');
assertTrue($hints !== [] && str_contains(implode(' ', $hints), 'numune'), 'nem profile hints');

echo "\n";
if ($failures > 0) {
    echo "{$failures} assertion(s) failed.\n";
    exit(1);
}
echo "All category profile tests passed.\n";
exit(0);
