<?php

declare(strict_types=1);

/**
 * SHOW_PRODUCTS ölçüleri, exact seçenek ve liste sayfası aynı filtreyi kullanır.
 * Web'den çalışmaz.
 *
 * Run: php scripts/advisor_listing_filter_ui_tests.php
 */

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit;
}

use App\Services\AssistantV2\AssistantV2Advisor;
use App\Services\AssistantV2\AssistantV2AdvisorAnalyzer;
use App\Services\AssistantV2\AssistantV2AdvisorRag;
use App\Services\AssistantV2\AssistantV2AdvisorTestSupport;
use App\Services\AssistantV2\AssistantV2Filters;
use App\Services\AssistantV2\AssistantV2Llm;

require dirname(__DIR__) . '/vendor/autoload.php';

\Core\Env::load(dirname(__DIR__) . '/.env');
$app = new \Core\Application(dirname(__DIR__));
$app->boot();

$failed = 0;
$results = [];

function uiCheck(string $id, bool $ok, string $detail = ''): void
{
    global $failed, $results;
    $results[] = $id . ' ' . ($ok ? 'PASS' : 'FAIL') . ($detail !== '' ? ' ' . $detail : '');
    if (!$ok) {
        $failed++;
    }
}

function uiFetch(string $url): array
{
    if (str_starts_with($url, '/')) {
        $url = 'https://terazibul.com' . $url;
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: TeraziBul-filter-ui-test\r\n",
            'follow_location' => 1,
            'max_redirects' => 5,
            'timeout' => 30,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    $final = $url;
    foreach ($http_response_header ?? [] as $header) {
        if (stripos($header, 'Location:') === 0) {
            $final = trim(substr($header, 9));
        }
    }

    return ['body' => is_string($body) ? $body : '', 'final' => $final];
}

function uiCount(string $html): int
{
    if (preg_match('/id="products-count"[^>]*>[^0-9]*([0-9]+)/u', $html, $match) !== 1) {
        return -1;
    }

    return (int) $match[1];
}

$base = [
    'intent' => 'product_discovery',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'needsWebSearch' => false,
];
$jewelry = AssistantV2AdvisorTestSupport::runConversation(
    ['kuyumcu terazisi arıyorum', '600', '0.01'],
    [
        array_merge($base, [
            'ready_for_product_results' => false,
            'category' => 'kuyumcu',
            'filter_updates' => ['category' => 'kuyumcu'],
        ]),
        array_merge($base, ['ready_for_product_results' => false]),
        $base,
    ]
);
$last = $jewelry[2] ?? [];
$state = is_array($last['state'] ?? null) ? $last['state'] : [];
$filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
$url = '';
foreach (is_array($last['actions'] ?? null) ? $last['actions'] : [] as $action) {
    if (($action['type'] ?? '') === 'show_products') {
        $url = (string) ($action['url'] ?? '');
    }
}
$params = AssistantV2Filters::listingMeasureParams($filters);
uiCheck('FILTER-UI-1', $url !== ''
    && str_contains($url, 'kap_min=600')
    && str_contains($url, 'has_max=0.01')
    && ($params['kap_min'] ?? '') === '600'
    && ($params['has_max'] ?? '') === '0.01');

$plain = uiFetch('https://terazibul.com/urunler/kuyumcu');
$filtered = uiFetch($url !== '' ? $url : 'https://terazibul.com/urunler/kuyumcu?kap_min=600&has_max=0.01');
$plainCount = uiCount($plain['body']);
$filteredCount = uiCount($filtered['body']);
$filteredCards = preg_match_all('/class="product-card"/', $filtered['body']);
uiCheck('FILTER-UI-2', str_contains($filtered['final'], 'kap_min=600')
    && str_contains($filtered['final'], 'has_max=0.01')
    && $filteredCount > 0
    && $filteredCount < $plainCount);
uiCheck('FILTER-UI-3', $filteredCount === $filteredCards && $filteredCount !== $plainCount);

$view = (string) file_get_contents(dirname(__DIR__) . '/resources/views/front/pages/templates/products.php');
uiCheck('FILTER-UI-4', str_contains($view, 'function hydrateMeasureFilters')
    && str_contains($view, "return 'En az ' + value + ' g'")
    && str_contains($view, "return 'En fazla ' + value + ' g'")
    && str_contains($view, 'data-measure-key'));
uiCheck('FILTER-UI-5', str_contains($view, 'function removeMeasureKey')
    && str_contains($view, 'params.delete(key)')
    && str_contains($view, "e.target.closest('[data-measure-key]')")
    && str_contains($view, 'data-measure-chip-remove'));

$previousAnalyze = AssistantV2AdvisorAnalyzer::$testAnalyzeOverride;
$previousLlm = AssistantV2Llm::$testCompleteOverride;
$previousRag = AssistantV2AdvisorRag::$testRetrieveOverride;
$llmTurn = 0;
$analyzeTurn = 0;
$offerAnalysis = array_merge($base, [
    'ready_for_product_results' => false,
    'category' => 'laboratuvar',
    'subcategory' => 'lab-analitik',
    'filter_updates' => [
        'category' => 'laboratuvar',
        'subcategory' => 'lab-analitik',
        'precision' => ['value' => '0.0001', 'unit' => 'g'],
    ],
]);
$choiceAnalysis = array_merge($base, [
    'filter_updates' => [
        'capacity' => ['operator' => 'min', 'value' => '220', 'unit' => 'g'],
    ],
]);
$analyses = [$offerAnalysis, $choiceAnalysis];
AssistantV2AdvisorRag::$testRetrieveOverride = static fn (): array => ['block' => '', 'debug' => ['used' => false]];
AssistantV2Llm::$testCompleteOverride = static function () use (&$llmTurn): array {
    $index = $llmTurn;
    $llmTurn++;
    if ($index === 0) {
        return ['message' => '0,1 mg okunabilirlikte 220 g ve 320 g kapasiteli seçenekler görünüyor.'];
    }

    return ['message' => 'Seçiminizi not aldım.'];
};
AssistantV2AdvisorAnalyzer::$testAnalyzeOverride = static function () use (&$analyzeTurn, $analyses): array {
    $mock = $analyses[$analyzeTurn] ?? [
        'intent' => 'product_discovery',
        'ready_for_product_results' => false,
        'needs_catalog_search' => false,
        'needsWebSearch' => false,
        'filter_updates' => [],
    ];
    $analyzeTurn++;

    return $mock;
};
$first = AssistantV2Advisor::turn('0,1 mg analitik terazi', [], [], [], '');
$firstState = is_array($first['state'] ?? null) ? $first['state'] : [];
$offered = $firstState['advisor']['offered_options']['capacity'] ?? [];
$second = AssistantV2Advisor::turn('220 olsun', $firstState, [
    ['role' => 'user', 'text' => '0,1 mg analitik terazi'],
    ['role' => 'assistant', 'text' => (string) ($first['message'] ?? '')],
], [], '');
$secondState = is_array($second['state'] ?? null) ? $second['state'] : [];
$secondFilters = is_array($secondState['filters'] ?? null) ? $secondState['filters'] : [];
$choiceUrl = '';
foreach (is_array($second['actions'] ?? null) ? $second['actions'] : [] as $action) {
    if (($action['type'] ?? '') === 'show_products') {
        $choiceUrl = (string) ($action['url'] ?? '');
    }
}
$choiceParams = AssistantV2Filters::listingMeasureParams($secondFilters);
$product220 = ['capacity_max' => 220, 'precision_filters' => [['unit' => 'g', 'grams' => 0.0001]], 'specs' => []];
$product320 = ['capacity_max' => 320, 'precision_filters' => [['unit' => 'g', 'grams' => 0.0001]], 'specs' => []];
uiCheck('OPTION-1', $offered === [220.0, 320.0]
    && ($secondFilters['capacity']['operator'] ?? '') === 'exact'
    && (float) ($secondFilters['capacity']['value_g'] ?? 0) === 220.0
    && ($choiceParams['kap_eq'] ?? '') === '220');
uiCheck('OPTION-2', !AssistantV2Filters::matchesListingMeasures($product320, $choiceParams)
    && AssistantV2Filters::matchesListingMeasures($product220, $choiceParams)
    && str_contains($choiceUrl, 'kap_eq=220')
    && !str_contains($choiceUrl, 'kap_min=220'));
$loadFilters = AssistantV2Filters::applyCapacityChoice(
    $secondFilters,
    '220 g numune tartacağım',
    [220.0, 320.0]
);
$loadParams = AssistantV2Filters::listingMeasureParams($loadFilters);
uiCheck('OPTION-3', ($loadFilters['capacity']['operator'] ?? '') === 'min'
    && ($loadParams['kap_min'] ?? '') === '220'
    && AssistantV2Filters::matchesListingMeasures($product320, $loadParams));
uiCheck('OPTION-4', ($choiceParams['has_max'] ?? '') === '0.0001'
    && AssistantV2Filters::canonicalGramString((float) ($secondFilters['precision']['value'] ?? 0)) === '0.0001');
$unrelated = AssistantV2Advisor::turn('plastik granül nemi ölçeceğim', $firstState, [], [], '');
$unrelatedState = is_array($unrelated['state'] ?? null) ? $unrelated['state'] : [];
$later = AssistantV2Advisor::turn('220 olsun', $unrelatedState, [], [], '');
$laterFilters = is_array($later['state']['filters'] ?? null) ? $later['state']['filters'] : [];
uiCheck('OPTION-5', ($unrelatedState['advisor']['offered_options']['capacity'] ?? null) === []
    && ($laterFilters['capacity']['operator'] ?? '') !== 'exact');

AssistantV2AdvisorAnalyzer::$testAnalyzeOverride = $previousAnalyze;
AssistantV2Llm::$testCompleteOverride = $previousLlm;
AssistantV2AdvisorRag::$testRetrieveOverride = $previousRag;

$critical = AssistantV2AdvisorTestSupport::runConversation(
    ['plastik granülün nemini ölçmek istiyorum'],
    [array_merge($base, [
        'ready_for_product_results' => true,
        'category' => 'laboratuvar',
        'subcategory' => 'nem-tayin',
        'filter_updates' => ['category' => 'laboratuvar', 'subcategory' => 'nem-tayin'],
    ])]
);
$criticalActions = is_array($critical[0]['actions'] ?? null) ? $critical[0]['actions'] : [];
$criticalShow = false;
foreach ($criticalActions as $action) {
    if (($action['type'] ?? '') === 'show_products') {
        $criticalShow = true;
    }
}
uiCheck('ACTION-1', !$criticalShow
    && trim((string) ($critical[0]['state']['advisor']['pending_question_field'] ?? '')) !== '');
$jewelryParams = AssistantV2Filters::listingMeasureParams($filters);
$jewelryConsistent = $url !== '';
foreach ($jewelryParams as $key => $value) {
    if (!str_contains($url, $key . '=' . $value)) {
        $jewelryConsistent = false;
    }
}
uiCheck('ACTION-2', $jewelryConsistent && $jewelryParams !== []);

echo implode("\n", $results) . "\n";
echo 'Passed: ' . (12 - $failed) . "\n";
echo 'Failed: ' . $failed . "\n";
exit($failed === 0 ? 0 : 1);
