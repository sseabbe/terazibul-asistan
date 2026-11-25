<?php

declare(strict_types=1);

/**
 * Advisor V2 conversation integration tests (mocked analyzer + LLM, no network).
 *
 * Run: php scripts/advisor_conversation_tests.php
 * Or:  php tera advisor:test
 */

use App\Services\AssistantV2\AssistantV2AdvisorTestSupport;
use App\Services\AssistantV2\AssistantV2AdvisorWebSearch;

require dirname(__DIR__) . '/vendor/autoload.php';

\Core\Env::load(dirname(__DIR__) . '/.env');
$app = new \Core\Application(dirname(__DIR__));
$app->boot();

putenv('ASSISTANT_WEB_SEARCH_ENABLED=1');
$_ENV['ASSISTANT_WEB_SEARCH_ENABLED'] = '1';

$failures = 0;

/**
 * @param mixed $condition
 */
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

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function assertSame(mixed $expected, mixed $actual, string $label): void
{
    assertTrue($expected === $actual, $label . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')');
}

echo "Advisor conversation tests\n\n";

// 1) Lab scale turn1 — no show_products
$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['Laboratuvar terazisi arıyorum'],
    [[
        'intent'                    => 'product_discovery',
        'category'                  => 'laboratuvar',
        'ready_for_product_results' => false,
        'needs_catalog_search'      => true,
        'missing_information'       => ['kapasite', 'okuma değeri'],
        'needsWebSearch'            => false,
    ]]
);
$t0 = $turns[0] ?? [];
assertTrue(($t0['cards'] ?? []) === [], 'lab terazi turn1: no cards');
assertTrue(!AssistantV2AdvisorTestSupport::hasActionType($t0, 'show_products'), 'lab terazi turn1: no show_products');

// 2) 200g + 0.0001g — show_products when ready
$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['200g 0.0001g analitik terazi'],
    [[
        'intent'                    => 'product_discovery',
        'category'                  => 'laboratuvar',
        'subcategory'               => 'analitik-terazi',
        'ready_for_product_results' => true,
        'needs_catalog_search'      => true,
        'filter_updates'            => [
            'category'    => 'laboratuvar',
            'subcategory' => 'analitik-terazi',
            'capacity'    => ['operator' => 'approximately', 'value_g' => 200],
            'precision'   => ['value' => 0.0001, 'unit' => 'g'],
        ],
        'needsWebSearch'            => false,
    ]]
);
$t0 = $turns[0] ?? [];
$ready = ($t0['state']['advisor']['ready_for_products'] ?? false) === true;
assertTrue(
    $ready || AssistantV2AdvisorTestSupport::hasActionType($t0, 'show_products'),
    '200g 0.0001g: ready_for_products or show_products'
);

// 3) Nem tayin — not generic lab scale discovery path (category hint)
$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['Nem tayin cihazı önerir misiniz?'],
    [[
        'intent'                    => 'product_discovery',
        'category'                  => 'laboratuvar',
        'subcategory'               => 'nem-tayin-cihazlari',
        'ready_for_product_results' => false,
        'needs_catalog_search'      => true,
        'needsWebSearch'            => false,
    ]]
);
$cat = (string) (($turns[0]['state']['advisor']['subcategory'] ?? '') ?: ($turns[0]['state']['advisor']['category'] ?? ''));
assertTrue(str_contains($cat, 'nem') || ($turns[0]['state']['filters']['subcategory'] ?? '') === 'nem-tayin-cihazlari', 'nem tayin: subcategory preserved');

// 4) Out of scope — no web
AssistantV2AdvisorWebSearch::$testSearchOverride = static fn (): array => [
    ['title' => 'Should not run', 'url' => 'https://axis.pl/x', 'snippet' => 'blocked'],
];
$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['Bugün dolar kuru kaç?'],
    [[
        'intent'         => 'off_topic',
        'needsWebSearch' => true,
        'webSearchTarget' => ['brand' => 'Axis', 'model' => '', 'requestedFact' => 'kur'],
    ]]
);
$web = is_array($turns[0]['debug']['webSearch'] ?? null) ? $turns[0]['debug']['webSearch'] : [];
assertSame(false, ($web['used'] ?? true), 'out_of_scope: webSearch not used');
AssistantV2AdvisorWebSearch::$testSearchOverride = null;

// 5) ATS120 temperature — web mock when RAG empty
AssistantV2AdvisorWebSearch::$testSearchOverride = static fn (): array => [
    [
        'title'   => 'ATS120 drying',
        'url'     => 'https://www.axis.pl/en/ats/78-ats210.html',
        'snippet' => 'ATS120 standard drying temperature up to 160 C',
    ],
];
$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['Axis ATS120 kurutma sıcaklığı aralığı nedir?'],
    [[
        'intent'          => 'general',
        'relation'        => 'non_product_question',
        'needsWebSearch'  => true,
        'webSearchReason' => 'site spec missing',
        'webSearchTarget' => [
            'brand'         => 'Axis',
            'model'         => 'ATS120',
            'requestedFact' => 'kurutma sıcaklığı aralığı',
        ],
        'needs_catalog_search' => false,
        'ready_for_product_results' => false,
    ]]
);
$web = is_array($turns[0]['debug']['webSearch'] ?? null) ? $turns[0]['debug']['webSearch'] : [];
assertSame(true, ($web['used'] ?? false), 'ATS120: webSearch used with mock provider');
AssistantV2AdvisorWebSearch::$testSearchOverride = null;

// 6) Err 8 service — RAG first; web only if RAG insufficient (simulate RAG miss + web off target)
$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['Ohaus terazim sürekli Err 8 veriyor'],
    [[
        'intent'                    => 'service',
        'service_topic'             => 'overview',
        'ready_for_product_results' => false,
        'needs_catalog_search'      => false,
        'needsWebSearch'            => false,
    ]]
);
assertSame('service', (string) ($turns[0]['state']['advisor']['intent'] ?? ''), 'Err8 flow: service intent');

// 7) State update 500g → 1kg across turns
$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['500g hassas terazi', 'Aslında 1 kg lazım'],
    [
        [
            'intent'                    => 'product_discovery',
            'category'                  => 'laboratuvar',
            'ready_for_product_results' => false,
            'filter_updates'            => ['capacity' => ['operator' => 'approximately', 'value_g' => 500]],
            'needsWebSearch'            => false,
        ],
        [
            'intent'                    => 'product_discovery',
            'relation'                  => 'continue_search',
            'ready_for_product_results' => true,
            'filter_updates'            => ['capacity' => ['operator' => 'approximately', 'value_g' => 1000]],
            'needsWebSearch'            => false,
        ],
    ]
);
$updatedCapacity = (float) ($turns[1]['state']['filters']['capacity']['value_g'] ?? 0);
assertTrue(abs($updatedCapacity - 1000) < 0.001, 'state update: capacity becomes 1000 g');
assertTrue(($turns[1]['state']['advisor']['category'] ?? null) !== 'laboratuvar', 'state update: bare hassas does not lock laboratuvar');

echo "\nKeyword precedence\n";

$classify = static function (array $turn): array {
    $debug = is_array($turn['debug'] ?? null) ? $turn['debug'] : [];
    $advisor = is_array($turn['state']['advisor'] ?? null) ? $turn['state']['advisor'] : [];
    $filters = is_array($turn['state']['filters'] ?? null) ? $turn['state']['filters'] : [];

    return [
        'category'    => (string) ($advisor['category'] ?? ''),
        'subcategory' => (string) ($advisor['subcategory'] ?? ''),
        'filter_cat'  => (string) ($filters['category'] ?? ''),
        'filter_sub'  => (string) ($filters['subcategory'] ?? ''),
        'source'      => (string) ($debug['classification_source'] ?? ''),
        'keyword'     => (string) ($debug['keyword_fallback'] ?? ''),
        'ready'       => ($advisor['ready_for_products'] ?? false) === true,
        'intent'      => (string) ($advisor['intent'] ?? ''),
        'total'       => (int) ($turn['state']['search_total'] ?? 0),
        'show'        => AssistantV2AdvisorTestSupport::hasActionType($turn, 'show_products'),
        'message'     => (string) ($turn['message'] ?? ''),
    ];
};

$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['plastik granülün nemini ölçmek istiyorum'],
    [[
        'intent'                    => 'product_discovery',
        'category'                  => 'laboratuvar',
        'subcategory'               => 'nem-tayin',
        'ready_for_product_results' => false,
        'needs_catalog_search'      => true,
        'needsWebSearch'            => false,
    ]]
);
$a = $classify($turns[0] ?? []);
assertSame('analyzer', $a['source'], 'A source analyzer');
assertSame('laboratuvar', $a['category'], 'A category laboratuvar');
assertSame('nem-tayin', $a['subcategory'], 'A subcategory nem-tayin');
assertSame('nem-tayin', $a['filter_sub'], 'A filter subcategory kept');
assertTrue($a['filter_cat'] !== '' && $a['filter_sub'] !== $a['filter_cat'], 'A not collapsed to root only');

$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['tankın altına dört load cell bağlayacağız'],
    [[
        'intent'                    => 'product_discovery',
        'category'                  => 'endustriyel',
        'subcategory'               => 'load-cell',
        'ready_for_product_results' => false,
        'needs_catalog_search'      => true,
        'needsWebSearch'            => false,
    ]]
);
$b = $classify($turns[0] ?? []);
assertSame('analyzer', $b['source'], 'B source analyzer');
assertSame('endustriyel', $b['category'], 'B category endustriyel');
assertSame('load-cell', $b['subcategory'], 'B subcategory load-cell');
assertTrue($b['filter_cat'] !== 'laboratuvar', 'B not pulled to laboratuvar');

$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['kasap için barkod basan terazi arıyorum'],
    [[
        'intent'                    => 'product_discovery',
        'category'                  => 'perakende',
        'subcategory'               => 'barkodlu-teraziler',
        'ready_for_product_results' => false,
        'needs_catalog_search'      => true,
        'needsWebSearch'            => false,
    ]]
);
$c = $classify($turns[0] ?? []);
assertSame('analyzer', $c['source'], 'C source analyzer');
assertSame('perakende', $c['category'], 'C category perakende');
assertSame('barkodlu-teraziler', $c['subcategory'], 'C subcategory barkodlu');
assertTrue(!in_array($c['filter_cat'], ['laboratuvar', 'endustriyel'], true), 'C not lab or industrial');

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
            'capacity'    => ['operator' => 'approximately', 'value_g' => 200],
            'precision'   => ['value' => 0.0001, 'unit' => 'g'],
        ],
        'needsWebSearch'            => false,
    ]]
);
$d = $classify($turns[0] ?? []);
assertTrue($d['ready'], 'D ready_for_products');
assertTrue($d['show'] || $d['total'] > 0, 'D catalog search or SHOW_PRODUCTS');
assertTrue($d['show'], 'D SHOW_PRODUCTS');
assertTrue(!str_contains($d['message'], 'kayıtlara bakılamıyor'), 'D no generic error');
assertSame('laboratuvar', $d['category'], 'D stays laboratory analytical');
assertTrue(str_contains($d['subcategory'], 'analitik') || str_contains($d['filter_sub'], 'analitik'), 'D analytical subcategory');

$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['laboratuvar terazisi arıyorum'],
    [[
        'intent'                    => 'product_discovery',
        'category'                  => 'laboratuvar',
        'subcategory'               => null,
        'ready_for_product_results' => false,
        'missing_information'       => ['kapasite', 'okuma değeri'],
        'needs_catalog_search'      => true,
        'needsWebSearch'            => false,
    ]]
);
$e = $classify($turns[0] ?? []);
assertSame('analyzer', $e['source'], 'E source analyzer');
assertSame('laboratuvar', $e['category'], 'E general laboratory');
assertSame('', $e['subcategory'], 'E no forced subcategory');
assertTrue(!$e['ready'], 'E clarification continues');

$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['laboratuvar terazisi arıyorum'],
    [[
        'intent'                    => 'product_discovery',
        'category'                  => null,
        'subcategory'               => null,
        'ready_for_product_results' => false,
        'needs_catalog_search'      => true,
        'needsWebSearch'            => false,
    ]]
);
$f = $classify($turns[0] ?? []);
assertSame('keyword_fallback', $f['source'], 'F keyword fallback');
assertSame('laboratuvar', $f['category'], 'F keyword category');

$turns = AssistantV2AdvisorTestSupport::runConversation(
    ['Dolar kaç TL?'],
    [[
        'intent'                    => 'off_topic',
        'category'                  => null,
        'subcategory'               => null,
        'ready_for_product_results' => false,
        'needs_catalog_search'      => false,
        'needsWebSearch'            => false,
    ]]
);
$g = $classify($turns[0] ?? []);
assertSame('off_topic', $g['intent'], 'G stays off_topic');
assertSame('', $g['category'], 'G no product category');
assertSame('', $g['filter_cat'], 'G filters not categorized');
assertTrue($g['source'] !== 'keyword_fallback', 'G keyword did not classify');

echo "\n";
if ($failures > 0) {
    echo "{$failures} assertion(s) failed.\n";
    exit(1);
}

echo "All advisor conversation tests passed.\n";
exit(0);
