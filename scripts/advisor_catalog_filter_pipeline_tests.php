<?php

declare(strict_types=1);

/**
 * Konuşma filtreleri SHOW_PRODUCTS URL'si ve katalog listesiyle aynı slug'ı kullanır.
 * Web'den çalışmaz.
 *
 * Run: php scripts/advisor_catalog_filter_pipeline_tests.php
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
use App\Services\AssistantV2\AssistantV2AdvisorSearch;
use App\Services\AssistantV2\AssistantV2AdvisorTestSupport;
use App\Services\AssistantV2\AssistantV2AdvisorWebSearch;
use App\Services\AssistantV2\AssistantV2Catalog;
use App\Services\AssistantV2\AssistantV2Filters;
use App\Services\AssistantV2\AssistantV2Llm;
use App\Services\ProductAssistant\ProductAssistantCatalog;

require dirname(__DIR__) . '/vendor/autoload.php';

\Core\Env::load(dirname(__DIR__) . '/.env');
$app = new \Core\Application(dirname(__DIR__));
$app->boot();

ProductAssistantCatalog::forget();

$failures = 0;

function pipeOk(mixed $condition, string $label): void
{
    global $failures;
    if ($condition) {
        echo "  OK  {$label}\n";

        return;
    }
    echo " FAIL {$label}\n";
    ++$failures;
}

function pipeMock(array $extra = []): array
{
    return array_merge([
        'intent'                    => 'product_discovery',
        'ready_for_product_results' => false,
        'needs_catalog_search'      => true,
        'needsWebSearch'            => false,
    ], $extra);
}

function pipeUrl(array $payload): string
{
    foreach (is_array($payload['actions'] ?? null) ? $payload['actions'] : [] as $action) {
        if (is_array($action) && ($action['type'] ?? '') === 'show_products') {
            return (string) ($action['url'] ?? '');
        }
    }

    return '';
}

function pipeFilters(array $payload): array
{
    $state = is_array($payload['state'] ?? null) ? $payload['state'] : [];

    return is_array($state['filters'] ?? null) ? $state['filters'] : [];
}

/**
 * @param list<string> $messages
 * @param list<array<string, mixed>> $mocks
 */
function pipeTalk(array $messages, array $mocks): array
{
    $turns = AssistantV2AdvisorTestSupport::runConversation($messages, $mocks);

    return $turns[count($turns) - 1] ?? [];
}

function pipeFetch(string $url, bool $follow = true): array
{
    if (str_starts_with($url, '/')) {
        $url = 'https://terazibul.com' . $url;
    }
    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => "User-Agent: TeraziBul-filter-pipeline\r\n",
            'follow_location' => $follow ? 1 : 0,
            'max_redirects' => 5,
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $status = 0;
    $location = '';
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match) === 1) {
            $status = (int) $match[1];
        }
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
    }

    return [
        'body'     => is_string($body) ? $body : '',
        'status'   => $status,
        'location' => $location,
        'headers'  => $headers,
    ];
}

function pipeCount(string $html): int
{
    if (preg_match('/id="products-count"[^>]*>[^0-9]*([0-9]+)/u', $html, $match) !== 1) {
        return -1;
    }

    return (int) $match[1];
}

function pipeChecked(string $html, string $slug): bool
{
    return str_contains($html, 'data-filter-attr="' . $slug . '"')
        || str_contains($html, "data-filter-attr='" . $slug . "'");
}

$base = [
    pipeMock([
        'category'       => 'kuyumcu',
        'filter_updates' => ['category' => 'kuyumcu'],
    ]),
    pipeMock(['relation' => 'continue_search']),
    pipeMock(['relation' => 'continue_search']),
    pipeMock(['relation' => 'continue_search', 'ready_for_product_results' => true]),
];

echo "F-4 / CONV ticari onay\n";
$conv = AssistantV2AdvisorTestSupport::runConversation(
    ['kuyumcu terazisi arıyorum', '600', '0.01', 'onaylı olsun'],
    $base
);
$final = $conv[3] ?? [];
$filters = pipeFilters($final);
$url = pipeUrl($final);
pipeOk(($filters['category'] ?? '') === 'kuyumcu', 'CONV category kuyumcu');
pipeOk(is_numeric($filters['capacity']['value_g'] ?? null) && abs((float) $filters['capacity']['value_g'] - 600.0) < 0.001, 'CONV capacity 600');
pipeOk(($filters['capacity']['operator'] ?? '') === 'min', 'CONV capacity operator min');
pipeOk(
    is_numeric($filters['precision']['value'] ?? null)
    && AssistantV2Filters::canonicalGramString((float) $filters['precision']['value']) === '0.01',
    'CONV readability 0.01'
);
pipeOk(($filters['commercial_approval'] ?? null) === true, 'CONV commercial_approval true');
pipeOk(str_contains($url, '/urunler/kuyumcu'), 'CONV path kuyumcu');
pipeOk(str_contains($url, 'kap_min=600') && str_contains($url, 'has_max=0.01'), 'CONV measure query');
$params = AssistantV2Filters::listingFacetParams($filters);
$approvalSlug = $params['f'][0] ?? '';
pipeOk($approvalSlug !== '' && str_contains($url, rawurlencode($approvalSlug)), 'CONV URL carries approval slug');
pipeOk(AssistantV2AdvisorTestSupport::hasActionType($final, 'show_products'), 'CONV SHOW_PRODUCTS');
$search = AssistantV2Filters::search($filters, 0, 20);
echo '  advisor_count=' . (int) $search['total'] . ' url=' . $url . "\n";

$page = $url !== '' ? pipeFetch($url) : ['body' => '', 'status' => 0];
$listed = pipeCount($page['body']);
echo '  listing_count=' . $listed . ' http=' . $page['status'] . "\n";
pipeOk($page['status'] === 200, 'CONV listing HTTP 200');
pipeOk($listed === (int) $search['total'], 'CONV advisor count == listing count');
pipeOk($approvalSlug !== '' && pipeChecked($page['body'], $approvalSlug), 'CONV approval checkbox exists');
pipeOk(str_contains($page['body'], 'data-measure-key') || str_contains($page['body'], 'hydrateMeasureFilters'), 'CONV measure UI hook present');

echo "\nF-12 reload\n";
$again = $url !== '' ? pipeFetch($url) : ['body' => '', 'status' => 0];
pipeOk(pipeCount($again['body']) === $listed && $listed >= 0, 'F-12 reload count matches');

echo "\nF-13 redirect keeps query\n";
$path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
$query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
$child = $path !== '' ? $path . '/olmayan-alt-grup' . ($query !== '' ? '?' . $query : '') : '';
$redirect = $child !== '' ? pipeFetch($child, false) : ['status' => 0, 'location' => ''];
echo '  redirect_status=' . $redirect['status'] . ' location=' . $redirect['location'] . "\n";
pipeOk(in_array($redirect['status'], [301, 302], true), 'F-13 redirect status');
pipeOk(str_contains($redirect['location'], 'kap_min=600') && str_contains($redirect['location'], 'f='), 'F-13 query survives redirect');

echo "\nF-1 brand\n";
$brandTurn = pipeTalk(
    ['laboratuvar terazisi arıyorum', 'Kern olsun'],
    [
        pipeMock([
            'category'                  => 'laboratuvar',
            'ready_for_product_results' => true,
            'filter_updates'            => ['category' => 'laboratuvar'],
        ]),
        pipeMock(['relation' => 'continue_search', 'ready_for_product_results' => true]),
    ]
);
$brandFilters = pipeFilters($brandTurn);
$brandUrl = pipeUrl($brandTurn);
pipeOk($brandFilters['brands'] !== [] && str_contains(implode(' ', $brandFilters['brands']), 'Kern'), 'F-1 Kern in state');
pipeOk(str_contains($brandUrl, '/urunler/kern-sohn/'), 'F-1 brand is in the path');
$brandPage = $brandUrl !== '' ? pipeFetch($brandUrl) : ['body' => '', 'status' => 0];
$brandListed = pipeCount($brandPage['body']);
$brandSearch = AssistantV2Filters::search($brandFilters, 0, 20);
echo '  brand_advisor=' . (int) $brandSearch['total'] . ' brand_listing=' . $brandListed . ' url=' . $brandUrl . "\n";
pipeOk($brandPage['status'] === 200 && $brandListed === (int) $brandSearch['total'] && $brandListed > 0, 'F-1 advisor count == listing count');
pipeOk(str_contains($brandPage['body'], 'Kern'), 'F-1 listing shows Kern');

$kernTight = pipeTalk(
    ['kuyumcu terazisi arıyorum', '600', '0.01', 'Kern olsun'],
    $base
);
$kernTightFilters = pipeFilters($kernTight);
$kernTightUrl = AssistantV2AdvisorSearch::listingUrl($kernTightFilters);
pipeOk($kernTightFilters['brands'] !== [], 'F-1 tight set keeps Kern in state');
pipeOk(str_contains($kernTightUrl, '/urunler/kern-sohn/') && str_contains($kernTightUrl, 'kap_min=600'), 'F-1 tight listingUrl keeps brand and capacity');
pipeOk(!AssistantV2AdvisorTestSupport::hasActionType($kernTight, 'show_products'), 'F-1 empty intersection does not open the jewelry list');

echo "\nF-5 calibration\n";
$calTurn = pipeTalk(
    ['kuyumcu terazisi arıyorum', '600', '0.01', 'dahili kalibrasyon olsun'],
    $base
);
$calFilters = pipeFilters($calTurn);
$calUrl = pipeUrl($calTurn);
$calParams = AssistantV2Filters::listingFacetParams($calFilters);
pipeOk(($calFilters['calibration'] ?? '') === 'dahili', 'F-5 calibration token');
pipeOk(($calFilters['commercial_approval'] ?? null) === null, 'F-5 does not invent approval');
if ($calParams['missing'] === []) {
    pipeOk($calUrl !== '' && str_contains($calUrl, 'f='), 'F-5 URL has calibration slug');
    $calPage = pipeFetch($calUrl);
    $calSlug = $calParams['f'][0] ?? '';
    pipeOk($calSlug !== '' && pipeChecked($calPage['body'], $calSlug), 'F-5 calibration checkbox exists');
    pipeOk(pipeCount($calPage['body']) === (int) AssistantV2Filters::search($calFilters, 0, 10)['total'], 'F-5 counts match');
} else {
    pipeOk(!AssistantV2AdvisorTestSupport::hasActionType($calTurn, 'show_products'), 'F-5 unresolved calibration does not open the list');
}

echo "\nF-6 communication\n";
$commTurn = pipeTalk(
    ['kuyumcu terazisi arıyorum', '600', '0.01', 'RS232 olsun'],
    $base
);
$commFilters = pipeFilters($commTurn);
$commUrl = pipeUrl($commTurn);
$commParams = AssistantV2Filters::listingFacetParams($commFilters);
pipeOk(($commFilters['communication'] ?? '') === 'rs232', 'F-6 communication token');
pipeOk(str_contains($commUrl, 'kap_min=600'), 'F-6 measures stay');
if ($commParams['missing'] === []) {
    $commSlug = '';
    foreach ($commParams['f'] as $slug) {
        if (str_contains($slug, 'rs-232') || str_contains($slug, 'rs232')) {
            $commSlug = $slug;
            break;
        }
    }
    pipeOk($commSlug !== '' && str_contains($commUrl, rawurlencode($commSlug)), 'F-6 URL has RS232 slug');
    $commPage = pipeFetch($commUrl);
    pipeOk(pipeChecked($commPage['body'], $commSlug), 'F-6 RS232 checkbox exists');
    pipeOk(pipeCount($commPage['body']) === (int) AssistantV2Filters::search($commFilters, 0, 10)['total'], 'F-6 counts match');
} else {
    pipeOk(!AssistantV2AdvisorTestSupport::hasActionType($commTurn, 'show_products'), 'F-6 unresolved RS232 does not open the list');
}

echo "\nF-7 product status\n";
$statusTurn = pipeTalk(
    ['kuyumcu terazisi arıyorum', '600', '0.01', 'sıfır olsun'],
    $base
);
$statusFilters = pipeFilters($statusTurn);
$statusParams = AssistantV2Filters::listingFacetParams($statusFilters);
pipeOk(($statusFilters['condition'] ?? '') === 'new', 'F-7 condition new');
if ($statusParams['missing'] === []) {
    $statusUrl = pipeUrl($statusTurn);
    pipeOk(str_contains($statusUrl, 'urun-durumu-sifir'), 'F-7 URL has new-condition slug');
    $statusPage = pipeFetch($statusUrl);
    pipeOk(pipeChecked($statusPage['body'], 'urun-durumu-sifir'), 'F-7 checkbox exists');
    pipeOk(pipeCount($statusPage['body']) === (int) AssistantV2Filters::search($statusFilters, 0, 10)['total'], 'F-7 counts match');
} else {
    pipeOk(!AssistantV2AdvisorTestSupport::hasActionType($statusTurn, 'show_products'), 'F-7 unsupported status does not open the list');
}

echo "\nF-8 / F-9 combination\n";
$combo = pipeTalk(
    ['kuyumcu terazisi arıyorum', '600', '0.01', 'onaylı olsun', 'Ohaus olsun'],
    array_merge($base, [pipeMock(['relation' => 'continue_search', 'ready_for_product_results' => true])])
);
$comboFilters = pipeFilters($combo);
$comboUrl = pipeUrl($combo);
pipeOk(($comboFilters['commercial_approval'] ?? null) === true, 'F-9 approval remains');
pipeOk(is_numeric($comboFilters['capacity']['value_g'] ?? null) && abs((float) $comboFilters['capacity']['value_g'] - 600.0) < 0.001, 'F-9 capacity remains');
pipeOk(
    is_numeric($comboFilters['precision']['value'] ?? null)
    && AssistantV2Filters::canonicalGramString((float) $comboFilters['precision']['value']) === '0.01',
    'F-9 readability remains'
);
pipeOk($comboFilters['brands'] !== [] && str_contains(implode(' ', $comboFilters['brands']), 'Ohaus'), 'F-9 brand remains');
$comboParams = AssistantV2Filters::listingFacetParams($comboFilters);
$comboSlug = '';
foreach ($comboParams['f'] as $slug) {
    if (str_contains($slug, 'onayli') && !str_contains($slug, 'onaysiz')) {
        $comboSlug = $slug;
        break;
    }
}
pipeOk($comboSlug !== '' && str_contains($comboUrl, rawurlencode($comboSlug)) && str_contains($comboUrl, 'kap_min=600') && str_contains($comboUrl, 'has_max=0.01'), 'F-9 URL keeps approval and measures');
$comboPage = $comboUrl !== '' ? pipeFetch($comboUrl) : ['body' => '', 'status' => 0];
$comboListed = pipeCount($comboPage['body']);
$comboSearch = AssistantV2Filters::search($comboFilters, 0, 20);
echo '  combo_advisor=' . (int) $comboSearch['total'] . ' combo_listing=' . $comboListed . "\n";
pipeOk($comboPage['status'] === 200 && $comboListed === (int) $comboSearch['total'], 'F-9 advisor count == listing count');

echo "\nF-10 / F-11 clear\n";
$cleared = pipeTalk(
    ['kuyumcu terazisi arıyorum', '600', '0.01', 'onaylı olsun', 'Ohaus olsun', 'onay önemli değil'],
    array_merge($base, [
        pipeMock(['relation' => 'continue_search', 'ready_for_product_results' => true]),
        pipeMock(['relation' => 'continue_search', 'ready_for_product_results' => true]),
    ])
);
$clearFilters = pipeFilters($cleared);
pipeOk(($clearFilters['commercial_approval'] ?? null) === null, 'F-10 approval cleared');
pipeOk($clearFilters['brands'] !== [], 'F-10 brand remains');
pipeOk(is_numeric($clearFilters['capacity']['value_g'] ?? null), 'F-10 capacity remains');
$brandCleared = pipeTalk(
    ['kuyumcu terazisi arıyorum', '600', '0.01', 'onaylı olsun', 'Ohaus olsun', 'marka fark etmez'],
    array_merge($base, [
        pipeMock(['relation' => 'continue_search', 'ready_for_product_results' => true]),
        pipeMock(['relation' => 'continue_search', 'ready_for_product_results' => true]),
    ])
);
$brandClearFilters = pipeFilters($brandCleared);
$brandClearUrl = pipeUrl($brandCleared);
pipeOk($brandClearFilters['brands'] === [], 'F-11 brand cleared');
pipeOk(($brandClearFilters['commercial_approval'] ?? null) === true, 'F-11 approval remains');
pipeOk(str_contains($brandClearUrl, 'kap_min=600') && str_contains($brandClearUrl, 'has_max=0.01'), 'F-11 measures remain');
pipeOk(!str_contains(strtolower($brandClearUrl), 'ohaus'), 'F-11 brand leaves the URL');

echo "\nF-2 / F-3 operators already on the conversation URL\n";
pipeOk(str_contains($url, 'kap_min=600'), 'F-2 capacity uses kap_min');
pipeOk(str_contains($url, 'has_max=0.01'), 'F-3 readability uses has_max');

echo "\nF-15 unsupported\n";
$previousAnalyze = AssistantV2AdvisorAnalyzer::$testAnalyzeOverride;
$previousLlm = AssistantV2Llm::$testCompleteOverride;
$previousWeb = AssistantV2AdvisorWebSearch::$testSearchOverride;
$previousRag = AssistantV2AdvisorRag::$testRetrieveOverride;
AssistantV2AdvisorRag::$testRetrieveOverride = static function (...$args): array {
    unset($args);

    return ['block' => '', 'debug' => ['used' => false]];
};
AssistantV2AdvisorWebSearch::$testSearchOverride = static function (...$args): array {
    unset($args);

    return [];
};
AssistantV2Llm::$testCompleteOverride = static function (...$args): array {
    unset($args);

    return ['message' => 'Bu kriterle filtreledim.'];
};
AssistantV2AdvisorAnalyzer::$testAnalyzeOverride = static function (...$args): array {
    unset($args);

    return pipeMock([
        'ready_for_product_results' => true,
        'relation'                  => 'continue_search',
    ]);
};
$blocked = AssistantV2Advisor::turn('menşei Almanya olsun', [
    'filters' => AssistantV2Filters::normalizeState(AssistantV2Filters::criteria([
        'category'  => 'kuyumcu',
        'capacity'  => ['operator' => 'min', 'value' => '600', 'unit' => 'g'],
        'precision' => ['value' => '0.01', 'unit' => 'g'],
    ])),
    'advisor' => [
        'intent'             => 'product_discovery',
        'ready_for_products' => true,
        'answered_fields'    => ['usage', 'capacity', 'precision'],
    ],
], [], [], '');
AssistantV2AdvisorAnalyzer::$testAnalyzeOverride = $previousAnalyze;
AssistantV2Llm::$testCompleteOverride = $previousLlm;
AssistantV2AdvisorWebSearch::$testSearchOverride = $previousWeb;
AssistantV2AdvisorRag::$testRetrieveOverride = $previousRag;
$blockedFilters = pipeFilters($blocked);
$blockedText = AssistantV2Catalog::fold((string) ($blocked['message'] ?? ''));
pipeOk(in_array('mensei', $blockedFilters['unsupported'] ?? [], true), 'F-15 mensei is unsupported');
pipeOk(!AssistantV2AdvisorTestSupport::hasActionType($blocked, 'show_products'), 'F-15 no SHOW_PRODUCTS');
pipeOk(!str_contains($blockedText, 'filtreledim') && !str_contains($blockedText, 'listeledim'), 'F-15 does not claim a filtered list');
echo '  f15_message=' . (string) ($blocked['message'] ?? '') . "\n";

echo "\nF-14 UI remove hook\n";
$ui = file_get_contents(dirname(__DIR__) . '/resources/views/front/pages/templates/products.php');
pipeOk(is_string($ui) && str_contains($ui, 'reloadListingFromDom'), 'F-14 facet removal reloads the listing');

$built = AssistantV2AdvisorSearch::listingUrl($filters);
pipeOk($built === $url || ($url !== '' && str_contains($built, 'kap_min=600')), 'listingUrl matches the action query');

echo $failures === 0 ? "\nALL FILTER PIPELINE PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
