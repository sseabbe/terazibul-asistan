<?php

declare(strict_types=1);

/**
 * Group A final regression: evidence guard, search/listing parity, prior gates.
 *
 * Run: php scripts/advisor_final_regression_tests.php
 */

use App\Controllers\Front\AssistantV2Controller;
use App\Services\AssistantV2\AssistantV2AdvisorEvidence;
use App\Services\AssistantV2\AssistantV2AdvisorErrorMatch;
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
    return ($payload['state']['advisor']['ready_for_products'] ?? false) === true;
}

function handoff(array $payload): string
{
    return (string) ($payload['state']['advisor']['handoff_reason'] ?? '');
}

function hasType(array $payload, string $type): bool
{
    return AssistantV2AdvisorTestSupport::hasActionType($payload, $type);
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

/**
 * @return array{search:int,listing:int}
 */
function analyticalParity(string $capacityValue, string $capacityUnit, string $precisionValue, string $precisionUnit): array
{
    $filters = AssistantV2Filters::normalizeState(AssistantV2Filters::criteria([
        'category'    => 'laboratuvar',
        'subcategory' => 'lab-analitik',
        'capacity'    => ['operator' => 'min', 'value' => $capacityValue, 'unit' => $capacityUnit],
        'precision'   => ['value' => $precisionValue, 'unit' => $precisionUnit],
    ]));
    $found = AssistantV2Filters::search($filters, 0, 30);
    $listing = 0;
    foreach (AssistantV2Catalog::products() as $product) {
        if (!AssistantV2Catalog::inGroup($product, 'lab-analitik', true) && !AssistantV2Catalog::inGroup($product, 'lab-analitik', false)) {
            continue;
        }
        $card = AssistantV2Filters::listingCardMeasures($product);
        $cap = is_string($card['capacity']) && is_numeric($card['capacity']) ? (float) $card['capacity'] : null;
        $prec = is_string($card['precision']) && is_numeric($card['precision']) ? (float) $card['precision'] : null;
        if (
            $cap !== null
            && AssistantV2Filters::capacityCoversLoad($cap, 200.0)
            && $prec !== null
            && AssistantV2Filters::readabilityMeetsRequest($prec, 0.0001)
        ) {
            ++$listing;
        }
    }

    return ['search' => (int) $found['total'], 'listing' => $listing];
}

echo "1-2 analytical parity\n";

$one = turn('200 g 0.0001 g analitik terazi lazım', [
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
assertTrue(ready($one), '1 ready');
assertTrue(hasType($one, 'show_products'), '1 SHOW_PRODUCTS');
$url = showUrl($one);
assertTrue(str_contains($url, 'kap_min=200') && str_contains($url, 'has_max=0.0001'), '1 listing filters');
assertSame('', handoff($one), '1 no handoff');
$pair = analyticalParity('200', 'g', '0.0001', 'g');
echo '  advisor_count=' . $pair['search'] . ' listing_count=' . $pair['listing'] . "\n";
assertTrue($pair['search'] > 0 && $pair['search'] === $pair['listing'], '1 advisor count == listing count');

$pairGr = analyticalParity('200', 'gr', '0,0001', 'gr');
assertSame($pair['search'], $pairGr['search'], '2 same search count');
assertSame($pair['listing'], $pairGr['listing'], '2 same listing count');

echo "\n3-10 state and readiness\n";

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
assertTrue(abs((float) ($turns[1]['state']['filters']['capacity']['value_g'] ?? 0) - 1000) < 0.001, '3 final 1000 g');

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
assertSame([], $turns[1]['state']['filters']['brands'] ?? null, '4 brand clear');

$nem = turn('plastik granülün nemini ölçmek istiyorum', [
    'intent' => 'product_discovery', 'category' => 'laboratuvar', 'subcategory' => 'nem-tayin',
    'ready_for_product_results' => false, 'needs_catalog_search' => true,
]);
assertSame('nem-tayin', (string) ($nem['state']['advisor']['subcategory'] ?? ''), '5 nem tayin');

$cell = turn('tankın altına dört load cell bağlayacağız', [
    'intent' => 'product_discovery', 'category' => 'endustriyel', 'subcategory' => 'load-cell',
    'ready_for_product_results' => false, 'needs_catalog_search' => true,
]);
assertSame('load-cell', (string) ($cell['state']['advisor']['subcategory'] ?? ''), '6 load cell');

$retail = turn('kasap için barkod basan terazi arıyorum', [
    'intent' => 'product_discovery', 'category' => 'perakende', 'subcategory' => 'barkodlu-teraziler',
    'ready_for_product_results' => false, 'needs_catalog_search' => true,
]);
assertSame('barkodlu-teraziler', (string) ($retail['state']['advisor']['subcategory'] ?? ''), '7 retail');

$lab = turn('laboratuvar terazisi arıyorum', [
    'intent' => 'product_discovery', 'category' => 'laboratuvar', 'ready_for_product_results' => true,
    'needs_catalog_search' => true, 'filter_updates' => ['category' => 'laboratuvar'],
]);
assertTrue(!ready($lab), '8 not ready');
assertSame('', handoff($lab), '8 no handoff');

$bad = turn('0,abc mg hassasiyet lazım', [
    'intent' => 'product_discovery', 'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
    'ready_for_product_results' => true, 'needs_catalog_search' => true,
    'filter_updates' => [
        'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
        'precision' => ['value' => '0,abc', 'unit' => 'mg'],
    ],
]);
assertTrue(!ready($bad), '9 unresolved not ready');

$fx = turn('Dolar kaç TL?', [
    'intent' => 'off_topic', 'ready_for_product_results' => true, 'needs_catalog_search' => false,
]);
assertSame('off_topic', (string) ($fx['state']['advisor']['intent'] ?? ''), '10 off_topic');
assertTrue(!ready($fx), '10 not ready');

echo "\n11-15 handoff, errors, debug\n";

$service = turn('Ohaus terazim bozuldu', [
    'intent' => 'service', 'service_topic' => 'overview', 'ready_for_product_results' => false, 'needs_catalog_search' => false,
]);
assertSame('service_request', handoff($service), '11 service handoff');

$normal = $one;
assertSame('', handoff($normal), '12 no WhatsApp reason');
assertTrue(!hasType($normal, 'whatsapp_write'), '12 no WhatsApp');

assertTrue(AssistantV2AdvisorErrorMatch::normalizeCode('Err 8') !== AssistantV2AdvisorErrorMatch::normalizeCode('Err 80'), '13 Err 8 != Err 80');
$synthetic = [
    ['code' => 'Err 8', 'brand' => 'Alpha', 'series' => 'S100', 'meaning' => 'eight'],
    ['code' => 'Err 80', 'brand' => 'Alpha', 'series' => 'S100', 'meaning' => 'eighty'],
];
$err8 = AssistantV2AdvisorErrorMatch::match('Err 8', $synthetic);
foreach ($err8['matches'] as $row) {
    assertSame('eight', (string) ($row['meaning'] ?? ''), '13 no Err 80 meaning');
}

$missing = AssistantV2AdvisorErrorMatch::match('Err 80');
assertTrue(!$missing['found'], '14 unknown code');
assertSame('', AssistantV2AdvisorErrorMatch::evidenceBlock($missing['matches'], false), '14 no invented explanation');

assertTrue(!AssistantV2Controller::debugAllowedFor('local', true, true, '', '', '203.0.113.8'), '15 public debug closed');
assertTrue(AssistantV2Controller::debugAllowedFor('local', false, true, '', '', '127.0.0.1'), '15 loopback debug kept');

echo "\n16-20 evidence and strict match\n";

$ats60 = [
    'id' => 60, 'brand' => 'Axis', 'model_no' => 'ATS60', 'name' => 'Axis ATS60',
    'drying_c' => 160, 'capacity_label' => '60 g', 'precision_label' => '0.001 g',
    'specs' => [['label' => 'Sıcaklık', 'value' => '160 °C']],
    'price_try' => null, 'in_stock' => true,
];
$ats120 = [
    'id' => 120, 'brand' => 'Axis', 'model_no' => 'ATS120', 'name' => 'Axis ATS120',
    'capacity_label' => '120 g', 'precision_label' => '0.001 g',
    'specs' => [['label' => 'Kapasite', 'value' => '120 g']],
    'price_try' => null, 'in_stock' => false,
];
$invented = AssistantV2AdvisorEvidence::guard(
    'ATS120 160°C kurutur.',
    'ATS120 kaç derece kurutur?',
    [$ats60, $ats120]
);
assertSame(AssistantV2AdvisorEvidence::NO_EVIDENCE, $invented, '16 unsupported temperature removed');
assertTrue(!str_contains($invented, '160'), '16 no invented 160');

$carried = AssistantV2AdvisorEvidence::guard(
    'ATS120 160°C kurutur.',
    'ATS120 sıcaklığı nedir?',
    [$ats60, $ats120]
);
assertTrue(!str_contains($carried, '160'), '17 ATS60 temperature not copied to ATS120');

$generic = AssistantV2AdvisorEvidence::guard(
    'ATS120 maksimum 160°C kurutur.',
    'ATS120 kaç derece kurutur?',
    [$ats120],
    'Axis nem tayin cihazları genel olarak 160°C kurutur.'
);
assertSame(AssistantV2AdvisorEvidence::NO_EVIDENCE, $generic, 'T2-4 generic Axis text does not verify ATS120');

$ats60Reply = AssistantV2AdvisorEvidence::namedDryingReply('ATS60 kaç derece kurutur', [$ats60, $ats120]);
assertTrue(str_contains($ats60Reply, 'ATS60') && str_contains($ats60Reply, '160'), 'T2-2 ATS60 uses its own temperature');
$ats120Reply = AssistantV2AdvisorEvidence::namedDryingReply('ATS120 kaç derece kurutur', [$ats60, $ats120]);
assertSame(AssistantV2AdvisorEvidence::NO_EVIDENCE, $ats120Reply, 'T2-3 ATS60 temperature is not used for ATS120');
$liveA = AssistantV2AdvisorEvidence::namedDryingReply('ATS120 kaç derece kurutur');
$liveB = AssistantV2AdvisorEvidence::namedDryingReply('ATS120 kaç derece kurutur');
assertSame($liveA, $liveB, 'T2-1 drying reply is stable');
assertTrue(str_contains($liveA, 'ATS120') && str_contains($liveA, '160'), 'T2-1 live ATS120 catalog temperature');

$commerce = AssistantV2AdvisorEvidence::guard(
    'ATS120 fiyatı 15.900 TL ve stokta 2 adet.',
    'ATS120 fiyatı ve stoğu nedir?',
    [$ats60, $ats120],
    "ATS120 fiyatı 15.900 TL yazar."
);
assertTrue(!str_contains($commerce, '15.900') && !str_contains($commerce, '15900'), '18 no price from RAG');
assertTrue(!str_contains($commerce, '2 adet'), '18 no stock count');

$priced = [
    'id' => 7, 'brand' => 'Axis', 'model_no' => 'APN220', 'name' => 'Axis APN220',
    'capacity_label' => '220 g', 'price_try' => 22000, 'in_stock' => true,
    'specs' => [],
];
$okPrice = AssistantV2AdvisorEvidence::guard(
    'APN220 fiyatı 22000 TL ve stokta var.',
    'APN220 fiyatı nedir?',
    [$priced]
);
assertTrue(str_contains($okPrice, '22000'), '18 structured price kept');
assertTrue(str_contains($okPrice, 'stokta var'), '18 structured stock kept');

assertTrue(!AssistantV2Filters::capacityCoversLoad(199.96, 200), '19 199.96 g does not cover 200 g');
assertTrue(!AssistantV2Filters::readabilityMeetsRequest(0.0002, 0.0001), '20 0.0002 g does not meet 0.0001 g');

$echo = AssistantV2AdvisorEvidence::guard(
    '200 g kapasite ve 0.0001 g hassasiyet için analitik gruba bakabiliriz.',
    '200 g 0.0001 g analitik terazi lazım',
    [$ats60, $ats120]
);
assertTrue(str_contains($echo, '200 g') && str_contains($echo, '0.0001'), 'echoed requirement kept');
assertTrue(!hasType($lab, 'whatsapp_write'), 'evidence gap does not add WhatsApp on clarification');

echo "\n";
if ($failures > 0) {
    echo "{$failures} assertion(s) failed.\n";
    exit(1);
}

echo "All final regression tests passed.\n";
exit(0);
