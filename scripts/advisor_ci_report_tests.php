<?php

declare(strict_types=1);

/**
 * Conversation Intelligence rapor testleri.
 * Chatbot kararlarını çağırmaz. Web'den çalışmaz.
 *
 * Run: php scripts/advisor_ci_report_tests.php
 */

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit;
}

use App\Services\AssistantV2\AssistantV2AdvisorAnalyzer;
use App\Services\AssistantV2\AssistantV2ConversationReport;
use App\Services\AssistantV2\AssistantV2Llm;

require dirname(__DIR__) . '/vendor/autoload.php';

\Core\Env::load(dirname(__DIR__) . '/.env');
$app = new \Core\Application(dirname(__DIR__));
$app->boot();

$failed = 0;
$results = [];
$now = strtotime('2026-10-03 15:00:00');

function ciReportCheck(string $id, bool $ok): void
{
    global $failed, $results;
    $results[] = $id . ' ' . ($ok ? 'PASS' : 'FAIL');
    if (!$ok) {
        $failed++;
    }
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function ciEvent(array $extra = []): array
{
    $signals = [
        'repeated_question' => false,
        'user_correction' => false,
        'wrong_category_suspected' => false,
        'zero_result' => false,
        'handoff' => false,
        'evidence_missing' => false,
        'unresolved_constraint' => false,
        'show_products_clicked' => false,
        'successful_product_discovery' => false,
    ];
    if (isset($extra['signals']) && is_array($extra['signals'])) {
        $signals = array_merge($signals, $extra['signals']);
        unset($extra['signals']);
    }

    return array_merge([
        'conversation_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'turn_id' => 1,
        'user_message_redacted' => 'analitik terazi',
        'assistant_message_redacted' => 'yanit',
        'intent' => 'product_discovery',
        'category' => 'laboratuvar',
        'subcategory' => 'lab-analitik',
        'ready_for_products' => 0,
        'search_total' => 0,
        'show_products' => 0,
        'handoff_reason' => '',
        'zero_result' => 0,
        'blocking_filter' => '',
        'pending_question_field' => '',
        'assistant_version' => 'v1.2.0',
        'signals' => json_encode($signals, JSON_UNESCAPED_UNICODE),
        'created_at' => '2026-10-03 12:00:00',
    ], $extra);
}

/**
 * @param list<array<string, mixed>> $events
 * @return array{0:array<string,mixed>,1:string}
 */
function ciReport(array $events, int $days = 7, string $version = ''): array
{
    global $now;
    $filtered = AssistantV2ConversationReport::filter($events, $days, $version, $now);
    $report = AssistantV2ConversationReport::build($filtered, ['days' => $days, 'version' => $version]);

    return [$report, AssistantV2ConversationReport::renderText($report)];
}

$old = ciEvent(['created_at' => '2026-09-01 12:00:00']);
[, $emptyText] = ciReport([$old], 7);
ciReportCheck('CI-R1', str_contains($emptyText, 'No conversation events in this window.')
    && str_contains($emptyText, 'Turns: 0')
    && str_contains($emptyText, 'Conversations: 0')
    && !str_contains($emptyText, 'POSSIBLE ISSUE'));

[, $discoveryText] = ciReport([
    ciEvent([
        'conversation_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'ready_for_products' => 1,
        'show_products' => 1,
        'signals' => ['successful_product_discovery' => true],
    ]),
    ciEvent([
        'conversation_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'ready_for_products' => 0,
        'show_products' => 0,
    ]),
]);
ciReportCheck('CI-R2', str_contains($discoveryText, 'Conversations: 2')
    && str_contains($discoveryText, 'Turns: 2')
    && str_contains($discoveryText, 'Product discovery turns: 2')
    && str_contains($discoveryText, 'ready_for_products: 1/2 (50.0%)')
    && str_contains($discoveryText, 'SHOW_PRODUCTS: 1/2 (50.0%)')
    && str_contains($discoveryText, 'successful_product_discovery: 1/2 (50.0%)'));

[, $zeroText] = ciReport([
    ciEvent(['zero_result' => 1, 'blocking_filter' => 'capacity', 'signals' => ['zero_result' => true]]),
    ciEvent([
        'conversation_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'zero_result' => 1,
        'blocking_filter' => 'capacity',
        'signals' => ['zero_result' => true],
    ]),
    ciEvent([
        'conversation_id' => 'cccccccccccccccccccccccccccccccc',
        'zero_result' => 1,
        'blocking_filter' => 'brand',
        'signals' => ['zero_result' => true],
    ]),
]);
ciReportCheck('CI-R3', str_contains($zeroText, 'blocking_filter capacity: 2')
    && str_contains($zeroText, 'blocking_filter brand: 1')
    && str_contains($zeroText, 'capacity blocked: 2')
    && str_contains($zeroText, 'brand blocked: 1'));

$repeatId = 'dddddddddddddddddddddddddddddddd';
[, $repeatText] = ciReport([
    ciEvent([
        'conversation_id' => $repeatId,
        'pending_question_field' => 'sample_material',
        'signals' => ['repeated_question' => true],
    ]),
]);
ciReportCheck('CI-R4', str_contains($repeatText, 'repeated_question: 1')
    && str_contains($repeatText, 'examples repeated_question: ' . $repeatId));

[, $correctionText] = ciReport([
    ciEvent(['signals' => ['user_correction' => true]]),
]);
ciReportCheck('CI-R5', str_contains($correctionText, 'user_correction: 1')
    && str_contains($correctionText, 'examples user_correction: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));

[, $handoffText] = ciReport([
    ciEvent(['intent' => 'service', 'handoff_reason' => 'service_request', 'signals' => ['handoff' => true]]),
    ciEvent([
        'conversation_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'intent' => 'service',
        'handoff_reason' => 'service_request',
        'signals' => ['handoff' => true],
    ]),
    ciEvent([
        'conversation_id' => 'cccccccccccccccccccccccccccccccc',
        'intent' => 'general',
        'handoff_reason' => 'user_requested',
        'signals' => ['handoff' => true],
    ]),
]);
ciReportCheck('CI-R6', str_contains($handoffText, 'handoff_reason service_request: 2')
    && str_contains($handoffText, 'handoff_reason user_requested: 1')
    && str_contains($handoffText, 'Handoffs: 3/3 (100.0%)'));

[, $evidenceText] = ciReport([
    ciEvent(['signals' => ['evidence_missing' => true]]),
]);
ciReportCheck('CI-R7', str_contains($evidenceText, 'evidence_missing: 1')
    && str_contains($evidenceText, 'examples evidence_missing: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));

[, $versionText] = ciReport([
    ciEvent(['assistant_version' => 'v1.1.0', 'intent' => 'product_discovery']),
    ciEvent([
        'conversation_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'assistant_version' => 'v1.2.0',
        'intent' => 'service',
    ]),
], 7, 'v1.2.0');
ciReportCheck('CI-R8', str_contains($versionText, 'Version filter: v1.2.0')
    && str_contains($versionText, 'Turns: 1')
    && str_contains($versionText, 'Service turns: 1')
    && str_contains($versionText, 'Product discovery turns: 0'));

[, $privacyText] = ciReport([
    ciEvent([
        'user_message_redacted' => 'numaram 0555 123 45 67 ve test@example.com rutubet cihazı',
    ]),
    ciEvent([
        'conversation_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'user_message_redacted' => 'numaram 0555 123 45 67 ve test@example.com rutubet cihazı',
    ]),
]);
ciReportCheck('CI-R9', !str_contains($privacyText, '0555')
    && !str_contains($privacyText, 'test@example.com')
    && !str_contains($privacyText, '[PHONE]')
    && !str_contains($privacyText, '[EMAIL]'));

[, $phraseText] = ciReport([
    ciEvent(['user_message_redacted' => 'altın baskülü arıyorum [PHONE]']),
    ciEvent([
        'conversation_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'user_message_redacted' => 'altın baskülü arıyorum test@example.com',
    ]),
]);
ciReportCheck('CI-R10', str_contains($phraseText, 'altin baskulu: 2')
    && !str_contains($phraseText, '[PHONE]')
    && !str_contains($phraseText, '[EMAIL]')
    && !str_contains($phraseText, 'test@example.com'));

$paths = [
    dirname(__DIR__) . '/app/Services/AssistantV2/AssistantV2Advisor.php',
    dirname(__DIR__) . '/app/Services/AssistantV2/AssistantV2AdvisorAnalyzer.php',
    dirname(__DIR__) . '/app/Services/AssistantV2/AssistantV2AdvisorSearch.php',
    dirname(__DIR__) . '/app/Services/AssistantV2/AssistantV2AdvisorRag.php',
    dirname(__DIR__) . '/app/Services/AssistantV2/advisor_category_profiles.json',
    dirname(__DIR__) . '/storage/assistant_v2_system_prompt.txt',
];
$before = [];
foreach ($paths as $path) {
    $before[$path] = is_file($path) ? hash_file('sha256', $path) : '';
}
$analyzeBefore = AssistantV2AdvisorAnalyzer::$testAnalyzeOverride;
$llmBefore = AssistantV2Llm::$testCompleteOverride;
ciReport([ciEvent(), ciEvent(['conversation_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'])]);
$unchanged = $analyzeBefore === AssistantV2AdvisorAnalyzer::$testAnalyzeOverride
    && $llmBefore === AssistantV2Llm::$testCompleteOverride;
foreach ($paths as $path) {
    $hash = is_file($path) ? hash_file('sha256', $path) : '';
    if ($hash !== $before[$path]) {
        $unchanged = false;
    }
}
ciReportCheck('CI-R11', $unchanged);

echo implode("\n", $results) . "\n";
echo 'Passed: ' . (11 - $failed) . "\n";
echo 'Failed: ' . $failed . "\n";
exit($failed === 0 ? 0 : 1);
