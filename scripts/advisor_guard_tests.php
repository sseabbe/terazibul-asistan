<?php

declare(strict_types=1);

/**
 * Group A #5 readiness, #9 handoff, #8 error-code matching.
 *
 * Run: php scripts/advisor_guard_tests.php
 */

use App\Services\AssistantV2\AssistantV2AdvisorErrorMatch;
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

function turn(string $message, array $mock): array
{
    $turns = AssistantV2AdvisorTestSupport::runConversation([$message], [$mock]);

    return $turns[0] ?? [];
}

function ready(array $payload): bool
{
    return (($payload['state']['advisor']['ready_for_products'] ?? false) === true);
}

function handoff(array $payload): string
{
    return (string) ($payload['state']['advisor']['handoff_reason'] ?? '');
}

function hasType(array $payload, string $type): bool
{
    return AssistantV2AdvisorTestSupport::hasActionType($payload, $type);
}

function hasWhatsapp(array $payload): bool
{
    return hasType($payload, 'whatsapp_write');
}

echo "Readiness\n";

$r1 = turn('200 g 0.0001 g analitik terazi lazım', [
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
assertTrue(ready($r1), 'R1 ready');
assertTrue(hasType($r1, 'show_products'), 'R1 SHOW_PRODUCTS');
$url = '';
foreach ($r1['actions'] ?? [] as $action) {
    if (($action['type'] ?? '') === 'show_products') {
        $url = (string) ($action['url'] ?? '');
    }
}
assertTrue(str_contains($url, 'kap_min=200') && str_contains($url, 'has_max=0.0001'), 'R1 listing filters');

$r2 = turn('laboratuvar terazisi arıyorum', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'subcategory' => null,
    'ready_for_product_results' => true,
    'missing_information' => ['kapasite'],
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'laboratuvar'],
]);
assertTrue(!ready($r2), 'R2 not ready');
assertSame('no_confirmed_filter', (string) ($r2['state']['advisor']['readiness_reason'] ?? ''), 'R2 clarification reason');
assertTrue(!hasType($r2, 'show_products'), 'R2 no SHOW_PRODUCTS');

$r3 = turn('0,abc mg hassasiyet lazım', [
    'intent' => 'product_discovery',
    'category' => 'laboratuvar',
    'subcategory' => 'lab-analitik',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'laboratuvar',
        'subcategory' => 'lab-analitik',
        'precision' => ['value' => '0,abc', 'unit' => 'mg'],
    ],
]);
assertTrue(!ready($r3), 'R3 unresolved not ready');
assertSame('unresolved_measure', (string) ($r3['state']['advisor']['readiness_reason'] ?? ''), 'R3 unresolved reason');

$r4 = turn('Dolar kaç TL?', [
    'intent' => 'off_topic',
    'ready_for_product_results' => true,
    'needs_catalog_search' => false,
]);
assertTrue(!ready($r4), 'R4 off_topic not ready');
assertSame('intent_blocked', (string) ($r4['state']['advisor']['readiness_reason'] ?? ''), 'R4 reason');

$r5 = turn('Ohaus terazim bozuldu', [
    'intent' => 'service',
    'service_topic' => 'overview',
    'ready_for_product_results' => true,
    'needs_catalog_search' => false,
]);
assertTrue(!ready($r5), 'R5 service not product-ready');

echo "\nHandoff\n";

$h1 = $r5;
assertSame('service_request', handoff($h1), 'H1 service handoff');
assertTrue(hasWhatsapp($h1) || hasType($h1, 'phone') || hasType($h1, 'service_form'), 'H1 handoff action');

$h2 = $r2;
assertSame('', handoff($h2), 'H2 no immediate handoff');
assertTrue(!hasWhatsapp($h2), 'H2 no WhatsApp');

$h3 = turn('bir uzmanla görüşmek istiyorum', [
    'intent' => 'general',
    'ready_for_product_results' => false,
    'needs_catalog_search' => false,
]);
assertSame('user_requested', handoff($h3), 'H3 user requested');
assertTrue(hasWhatsapp($h3), 'H3 WhatsApp');

$h4 = turn('bu load cell bu gösterge ile uyumlu mu', [
    'intent' => 'product_discovery',
    'category' => 'endustriyel',
    'ready_for_product_results' => false,
    'needs_catalog_search' => true,
    'filter_updates' => ['category' => 'endustriyel', 'subcategory' => 'load-cell'],
]);
assertSame('compatibility_uncertain', handoff($h4), 'H4 compatibility handoff');
assertTrue(hasWhatsapp($h4), 'H4 WhatsApp');

$h5 = $r1;
assertSame('', handoff($h5), 'H5 no handoff');
assertTrue(!hasWhatsapp($h5), 'H5 no WhatsApp');

echo "\nError codes\n";

assertSame('err8', AssistantV2AdvisorErrorMatch::normalizeCode('Err 8'), 'normalize Err 8');
assertSame('err8', AssistantV2AdvisorErrorMatch::normalizeCode('ERR-8'), 'normalize ERR-8');
assertSame('err80', AssistantV2AdvisorErrorMatch::normalizeCode('Err 80'), 'normalize Err 80');
assertSame('e8', AssistantV2AdvisorErrorMatch::normalizeCode('E8'), 'normalize E8');
assertSame('e80', AssistantV2AdvisorErrorMatch::normalizeCode('E80'), 'normalize E80');
assertTrue(AssistantV2AdvisorErrorMatch::normalizeCode('Err 8') !== AssistantV2AdvisorErrorMatch::normalizeCode('Err 80'), 'E2 tokens differ');
assertTrue(AssistantV2AdvisorErrorMatch::normalizeCode('E8') !== AssistantV2AdvisorErrorMatch::normalizeCode('E80'), 'E8 is not E80');
assertTrue(AssistantV2AdvisorErrorMatch::normalizeCode('Err 8') !== AssistantV2AdvisorErrorMatch::normalizeCode('E8'), 'Err 8 is not E8');

$synthetic = [
    ['code' => 'Err 8', 'brand' => 'Alpha', 'series' => 'S1', 'meaning' => 'alpha eight'],
    ['code' => 'Err 80', 'brand' => 'Alpha', 'series' => 'S1', 'meaning' => 'alpha eighty'],
    ['code' => 'Err 8', 'brand' => 'Beta', 'series' => 'S9', 'meaning' => 'beta eight'],
];
$exact = AssistantV2AdvisorErrorMatch::match('Err 8', $synthetic);
assertTrue($exact['found'], 'E1 synthetic found');
foreach ($exact['matches'] as $row) {
    assertSame('err8', AssistantV2AdvisorErrorMatch::normalizeCode((string) $row['code']), 'E2 no Err 80 in Err 8 matches');
}
$eighty = AssistantV2AdvisorErrorMatch::match('Err 80', $synthetic);
assertTrue($eighty['found'], 'Err 80 finds its own row');
assertSame('alpha eighty', (string) ($eighty['matches'][0]['meaning'] ?? ''), 'Err 80 meaning');

$live = AssistantV2AdvisorErrorMatch::match('Err 8');
assertTrue($live['found'], 'E1 catalog Err 8');
assertTrue($live['ambiguous'] || count($live['matches']) > 1, 'E1 more than one series');
foreach ($live['matches'] as $row) {
    assertSame('err8', AssistantV2AdvisorErrorMatch::normalizeCode((string) $row['code']), 'E1 exact code');
}

$precisa = AssistantV2AdvisorErrorMatch::match('Precisa 165 BJ Err 8');
assertSame('series', $precisa['tier'], 'E3 series tier');
assertTrue($precisa['found'], 'E3 found');
foreach ($precisa['matches'] as $row) {
    assertTrue(str_contains(mb_strtolower((string) ($row['brand'] ?? '')), 'precisa'), 'E3 stays on Precisa');
}

$kern = AssistantV2AdvisorErrorMatch::match('Kern Err 8');
assertTrue($kern['found'], 'E3 Kern found');
assertTrue($kern['tier'] === 'brand' || $kern['tier'] === 'series', 'E3 Kern stays in brand context');
foreach ($kern['matches'] as $row) {
    assertTrue(str_contains(mb_strtolower((string) ($row['brand'] ?? '')), 'kern'), 'E3 Kern does not use another brand');
}

$ohausCode = AssistantV2AdvisorErrorMatch::match('Ohaus Err 8');
assertTrue(!$ohausCode['found'], 'E3 named brand without a record is not another brand');

$missing = AssistantV2AdvisorErrorMatch::match('Err 80');
assertTrue(!$missing['found'], 'E4 no invented Err 80');
assertSame([], $missing['matches'], 'E4 empty matches');
assertSame('', AssistantV2AdvisorErrorMatch::evidenceBlock($missing['matches'], false), 'E4 no explanation text');

$e5 = turn('ekranda Err 80 yazıyor', [
    'intent' => 'service',
    'ready_for_product_results' => false,
    'needs_catalog_search' => false,
]);
assertTrue(handoff($e5) === 'service_request' || handoff($e5) === 'error_code_unknown', 'E5 handoff');
$e5b = turn('ekranda Err 80 yazıyor', [
    'intent' => 'general',
    'ready_for_product_results' => false,
    'needs_catalog_search' => false,
]);
assertSame('error_code_unknown', handoff($e5b), 'E5 unknown code handoff');
assertTrue(hasWhatsapp($e5b), 'E5 WhatsApp');

echo "\nRegression\n";

$nem = turn('plastik granülün nemini ölçmek istiyorum', [
    'intent' => 'product_discovery', 'category' => 'laboratuvar', 'subcategory' => 'nem-tayin',
    'ready_for_product_results' => false, 'needs_catalog_search' => true,
]);
assertSame('nem-tayin', (string) ($nem['state']['advisor']['subcategory'] ?? ''), 'nem tayin');
assertTrue(!ready($nem), 'nem not forced ready');

$cell = turn('tankın altına dört load cell bağlayacağız', [
    'intent' => 'product_discovery', 'category' => 'endustriyel', 'subcategory' => 'load-cell',
    'ready_for_product_results' => false, 'needs_catalog_search' => true,
]);
assertSame('load-cell', (string) ($cell['state']['advisor']['subcategory'] ?? ''), 'load cell');

$retail = turn('kasap için barkod basan terazi arıyorum', [
    'intent' => 'product_discovery', 'category' => 'perakende', 'subcategory' => 'barkodlu-teraziler',
    'ready_for_product_results' => false, 'needs_catalog_search' => true,
]);
assertSame('barkodlu-teraziler', (string) ($retail['state']['advisor']['subcategory'] ?? ''), 'retail');

$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['500 gram yeter', 'Aslında 1 kilo lazım'],
    [
        [
            'intent' => 'product_discovery', 'category' => 'laboratuvar', 'ready_for_product_results' => false,
            'filter_updates' => ['category' => 'laboratuvar', 'capacity' => ['operator' => 'min', 'value' => '500', 'unit' => 'g']],
        ],
        [
            'intent' => 'product_discovery', 'relation' => 'modify_search', 'ready_for_product_results' => false,
            'filter_updates' => ['capacity' => ['operator' => 'min', 'value' => '1', 'unit' => 'kg']],
        ],
    ]
);
$cap = (float) ($turns[1]['state']['filters']['capacity']['value_g'] ?? 0);
assertTrue(abs($cap - 1000) < 0.001, 'state overwrite 1000 g');
assertSame('laboratuvar', (string) ($turns[1]['state']['filters']['category'] ?? ''), 'category kept');

$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['Axis olsun', 'Marka fark etmez'],
    [
        [
            'intent' => 'product_discovery', 'category' => 'laboratuvar', 'ready_for_product_results' => false,
            'filter_updates' => ['category' => 'laboratuvar', 'brands' => ['Axis']],
        ],
        [
            'intent' => 'product_discovery', 'relation' => 'modify_search', 'ready_for_product_results' => false,
            'remove_constraints' => ['brand'], 'filter_updates' => [],
        ],
    ]
);
assertSame([], $turns[1]['state']['filters']['brands'] ?? null, 'brand clear');

assertTrue(!AssistantV2Filters::capacityCoversLoad(199.96, 200), 'strict 199.96 rejected');
assertTrue(AssistantV2Filters::readabilityMeetsRequest(0.0001, 0.0001), 'strict readability equal');
assertTrue(!AssistantV2Filters::readabilityMeetsRequest(0.0002, 0.0001), 'strict readability coarser rejected');

$filters = AssistantV2Filters::normalizeState(AssistantV2Filters::criteria([
    'category' => 'laboratuvar',
    'capacity' => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
    'precision' => ['value' => '0.0001', 'unit' => 'g'],
]));
$found = AssistantV2Filters::search($filters, 0, 8);
echo '  search_total_laboratuvar=' . $found['total'] . "\n";
assertTrue($found['total'] > 0, 'catalog search still returns');

echo "\n";
if ($failures > 0) {
    echo "{$failures} assertion(s) failed.\n";
    exit(1);
}

echo "All guard tests passed.\n";
exit(0);
