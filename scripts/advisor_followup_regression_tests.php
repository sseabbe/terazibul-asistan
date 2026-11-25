<?php

declare(strict_types=1);

/**
 * Follow-up binding, SHOW_PRODUCTS gating, hassas usage, live spec priority.
 *
 * Run: php scripts/advisor_followup_regression_tests.php
 */

use App\Services\AssistantV2\AssistantV2AdvisorEvidence;
use App\Services\AssistantV2\AssistantV2AdvisorTestSupport;
use App\Services\AssistantV2\AssistantV2QuestionPlan;

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

echo "NEW follow-up regression\n";

$sampleQuestion = 'Hangi malzemeden numune tartılacak?';
$readabilityQuestion = 'Hangi okunabilirlik/hassasiyet gerekiyor? Gram olarak biliyorsanız yazabilirsiniz. Bilmiyorsanız ne tartacağınızı ve ne kadar hassas ölçmeniz gerektiğini söyleyin.';

$nem = AssistantV2AdvisorTestSupport::runConversation(
    ['nem tayin cihazı arıyorum', 'süt tozu'],
    [
        discoveryMock([
            'category'    => 'laboratuvar',
            'subcategory' => 'nem-tayin',
        ]),
        discoveryMock(['relation' => 'continue_search']),
    ]
);
$nem0 = $nem[0] ?? [];
$nem1 = $nem[1] ?? [];
assertTrue(str_contains((string) ($nem0['message'] ?? ''), $sampleQuestion), 'NEW-1 asks sample material');
assertTrue((string) ($nem0['state']['advisor']['pending_question_field'] ?? '') === 'sample_material', 'NEW-1 pending field is sample_material');
assertTrue((string) ($nem1['state']['advisor']['facts']['sample_material'] ?? '') === 'süt tozu', 'NEW-1 sample_material = süt tozu');
assertTrue(!str_contains((string) ($nem1['message'] ?? ''), $sampleQuestion), 'NEW-1 same question is not repeated');
assertTrue(in_array('sample_material', $nem1['state']['advisor']['answered_fields'] ?? [], true), 'NEW-1 answered_fields contains sample_material');

$repeat = AssistantV2AdvisorTestSupport::runConversation(
    ['nem tayin cihazı arıyorum', 'süt tozu dedim ya'],
    [
        discoveryMock([
            'category'    => 'laboratuvar',
            'subcategory' => 'nem-tayin',
        ]),
        discoveryMock(['relation' => 'continue_search']),
    ]
);
$repeat1 = $repeat[1] ?? [];
assertTrue((string) ($repeat1['state']['advisor']['facts']['sample_material'] ?? '') === 'süt tozu', 'NEW-2 süt tozu dedim ya binds');
assertTrue(!str_contains((string) ($repeat1['message'] ?? ''), $sampleQuestion), 'NEW-2 material question is not repeated');

$hassas = AssistantV2AdvisorTestSupport::runConversation(
    ['hassas terazi arıyorum'],
    [discoveryMock(['ready_for_product_results' => true])]
);
$hassas0 = $hassas[0] ?? [];
assertTrue(($hassas0['state']['advisor']['category'] ?? null) !== 'laboratuvar', 'NEW-3 hassas terazi is not laboratuvar');
assertTrue(($hassas0['state']['filters']['category'] ?? null) !== 'laboratuvar', 'NEW-3 filter category is not laboratuvar');
assertTrue(str_contains((string) ($hassas0['message'] ?? ''), AssistantV2QuestionPlan::USAGE_QUESTION), 'NEW-3 asks how the scale will be used');
assertTrue(!AssistantV2AdvisorTestSupport::hasActionType($hassas0, 'show_products'), 'NEW-3 no SHOW_PRODUCTS');

$lab = AssistantV2AdvisorTestSupport::runConversation(
    ['hassas terazi arıyorum', 'laboratuvar', '600'],
    [
        discoveryMock(),
        discoveryMock(['relation' => 'continue_search']),
        discoveryMock([
            'relation'                  => 'continue_search',
            'ready_for_product_results' => true,
            'filter_updates'            => [
                'capacity' => ['operator' => 'min', 'value_g' => 600],
            ],
        ]),
    ]
);
$lab2 = $lab[2] ?? [];
$capacity = (float) ($lab2['state']['filters']['capacity']['value_g'] ?? 0);
assertTrue(abs($capacity - 600) < 0.001, 'NEW-4 capacity = 600 g');
assertTrue(($lab2['state']['advisor']['ready_for_products'] ?? true) !== true, 'NEW-4 not ready without readability');
assertTrue(!AssistantV2AdvisorTestSupport::hasActionType($lab2, 'show_products'), 'NEW-4 no SHOW_PRODUCTS');
$question = (string) ($lab2['message'] ?? '');
assertTrue(str_contains($question, 'okunabilirlik'), 'NEW-5 asks readability in open form');
assertTrue(!str_contains($question, '0,01 g mı') && !str_contains($question, '0,001 g mı'), 'NEW-5 does not force 0,01 / 0,001');
assertTrue(str_contains($question, $readabilityQuestion), 'NEW-5 uses the catalog question');

$ambiguous = AssistantV2AdvisorTestSupport::runConversation(
    ['evet'],
    [discoveryMock([
        'ready_for_product_results' => true,
        'filter_updates'            => [
            'capacity'  => ['operator' => 'min', 'value_g' => 600],
            'precision' => ['value' => 0.01, 'unit' => 'g'],
        ],
    ])],
    [
        'search_total'          => 4,
        'products_listing_url'  => 'https://terazibul.com/urunler/laboratuvar',
    ]
);
$ambiguous0 = $ambiguous[0] ?? [];
assertTrue(($ambiguous0['state']['advisor']['category'] ?? null) === null, 'NEW-6 category still unresolved');
assertTrue(!AssistantV2AdvisorTestSupport::hasActionType($ambiguous0, 'show_products'), 'NEW-6 no SHOW_PRODUCTS without category');

$ready = AssistantV2AdvisorTestSupport::runConversation(
    ['200 g 0.0001 g analitik terazi lazım'],
    [discoveryMock([
        'category'                  => 'laboratuvar',
        'subcategory'               => 'lab-analitik',
        'ready_for_product_results' => true,
        'filter_updates'            => [
            'category'    => 'laboratuvar',
            'subcategory' => 'lab-analitik',
            'capacity'    => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
            'precision'   => ['value' => '0.0001', 'unit' => 'g'],
        ],
    ])]
);
$ready0 = $ready[0] ?? [];
assertTrue(($ready0['state']['advisor']['ready_for_products'] ?? false) === true, 'NEW-7 ready when category and measures are set');
assertTrue(AssistantV2AdvisorTestSupport::hasActionType($ready0, 'show_products'), 'NEW-7 SHOW_PRODUCTS');

$stale = AssistantV2AdvisorTestSupport::runConversation(
    ['200 g 0.0001 g analitik terazi lazım', 'bluetooth da olsun'],
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
        discoveryMock([
            'relation'                  => 'continue_search',
            'category'                  => 'laboratuvar',
            'ready_for_product_results' => true,
        ]),
    ]
);
assertTrue(AssistantV2AdvisorTestSupport::hasActionType($stale[0] ?? [], 'show_products'), 'NEW-10 prior turn can show products');
assertTrue(!AssistantV2AdvisorTestSupport::hasActionType($stale[1] ?? [], 'show_products'), 'NEW-10 stale SHOW_PRODUCTS is dropped');

$changed = [
    'id'         => 120,
    'brand'      => 'Axis',
    'model_no'   => 'ATS120',
    'name'       => 'Axis ATS120',
    'specs'      => [['label' => 'Maksimum Kurutma Sıcaklığı', 'value' => '180°C']],
];
$drying = AssistantV2AdvisorEvidence::namedDryingReply('ATS120 kaç derece kurutur', [$changed]);
assertTrue(str_contains($drying, '180'), 'NEW-8 resolver sees updated Teknik Özellikler');
assertTrue(!str_contains($drying, '160'), 'NEW-8 old temperature is not kept');

$staleRag = AssistantV2AdvisorEvidence::guard(
    'ATS120 160°C kurutur.',
    'ATS120 sıcaklığı nedir?',
    [$changed],
    'ATS120 maksimum kurutma sıcaklığı 160°C.'
);
assertTrue(!str_contains($staleRag, '160'), 'NEW-9 stale RAG temperature loses');
$liveSpec = AssistantV2AdvisorEvidence::guard(
    'ATS120 180°C kurutur.',
    'ATS120 sıcaklığı nedir?',
    [$changed],
    'ATS120 maksimum kurutma sıcaklığı 160°C.'
);
assertTrue(str_contains($liveSpec, '180'), 'NEW-9 live Teknik Özellikler value wins');

echo "\n";
if ($failures > 0) {
    echo "{$failures} assertion(s) failed.\n";
    exit(1);
}

echo "All NEW follow-up regression tests passed.\n";
exit(0);
