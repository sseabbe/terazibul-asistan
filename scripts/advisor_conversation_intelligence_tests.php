<?php

declare(strict_types=1);

/**
 * Conversation Intelligence aşama 1.
 * Chatbot kararlarını değiştirmez. Web'den çalışmaz.
 *
 * Run: php scripts/advisor_conversation_intelligence_tests.php
 */

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit;
}

use App\Services\AssistantV2\AssistantV2Advisor;
use App\Services\AssistantV2\AssistantV2AdvisorAnalyzer;
use App\Services\AssistantV2\AssistantV2AdvisorEvidence;
use App\Services\AssistantV2\AssistantV2AdvisorRag;
use App\Services\AssistantV2\AssistantV2AdvisorTestSupport;
use App\Services\AssistantV2\AssistantV2ConversationLog;
use App\Services\AssistantV2\AssistantV2ConversationRedactor;
use App\Services\AssistantV2\AssistantV2Llm;

require dirname(__DIR__) . '/vendor/autoload.php';

\Core\Env::load(dirname(__DIR__) . '/.env');
$app = new \Core\Application(dirname(__DIR__));
$app->boot();

$failed = 0;
$results = [];

/**
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function ciSignals(array $event): array
{
    $decoded = json_decode((string) ($event['signals'] ?? ''), true);

    return is_array($decoded) ? $decoded : [];
}

function ciReset(): void
{
    AssistantV2ConversationLog::$testCapture = [];
    AssistantV2ConversationLog::$testFailWrite = false;
}

/**
 * @param list<string> $messages
 * @param list<array<string, mixed>> $analyses
 * @return list<array<string, mixed>>
 */
function ciRun(array $messages, array $analyses): array
{
    ciReset();

    return AssistantV2AdvisorTestSupport::runConversation($messages, $analyses);
}

function ciCheck(string $id, bool $ok, string $detail = ''): void
{
    global $failed, $results;
    $results[] = $id . ' ' . ($ok ? 'PASS' : 'FAIL') . ($detail !== '' ? ' ' . $detail : '');
    if (!$ok) {
        $failed++;
    }
}

$discovery = [
    'intent' => 'product_discovery',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'needsWebSearch' => false,
    'category' => 'laboratuvar',
    'subcategory' => 'lab-analitik',
    'filter_updates' => [
        'category' => 'laboratuvar',
        'subcategory' => 'lab-analitik',
        'capacity' => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
        'precision' => ['value' => '0.0001', 'unit' => 'g'],
    ],
];

ciRun(['200 g 0.0001 g analitik terazi lazım'], [$discovery]);
$event = AssistantV2ConversationLog::$testCapture[0] ?? null;
ciCheck('CI-1', is_array($event)
    && ($event['conversation_id'] ?? '') !== ''
    && ($event['intent'] ?? '') === 'product_discovery'
    && ($event['category'] ?? '') === 'laboratuvar');

$phone = AssistantV2ConversationRedactor::text('numaram 0555 123 45 67');
ciRun(['numaram 0555 123 45 67'], [[
    'intent' => 'general',
    'needs_catalog_search' => false,
    'ready_for_product_results' => false,
]]);
$phoneEvent = AssistantV2ConversationLog::$testCapture[0] ?? [];
ciCheck('CI-2', str_contains($phone, '[PHONE]')
    && !str_contains($phone, '0555')
    && str_contains((string) ($phoneEvent['user_message_redacted'] ?? ''), '[PHONE]')
    && !str_contains((string) ($phoneEvent['user_message_redacted'] ?? ''), '0555'));

$email = AssistantV2ConversationRedactor::text('yazın test@example.com');
ciRun(['yazın test@example.com'], [[
    'intent' => 'general',
    'needs_catalog_search' => false,
    'ready_for_product_results' => false,
]]);
$emailEvent = AssistantV2ConversationLog::$testCapture[0] ?? [];
ciCheck('CI-3', str_contains($email, '[EMAIL]')
    && !str_contains($email, 'test@example.com')
    && str_contains((string) ($emailEvent['user_message_redacted'] ?? ''), '[EMAIL]')
    && !str_contains((string) ($emailEvent['user_message_redacted'] ?? ''), 'test@example.com'));

ciRun(['200 g 0.0001 g analitik terazi lazım'], [$discovery]);
$show = AssistantV2ConversationLog::$testCapture[0] ?? [];
ciCheck('CI-4', (int) ($show['show_products'] ?? 0) === 1);

$zeroAnalysis = [
    'intent' => 'product_discovery',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'needsWebSearch' => false,
    'category' => 'laboratuvar',
    'subcategory' => 'lab-analitik',
    'filter_updates' => [
        'category' => 'laboratuvar',
        'subcategory' => 'lab-analitik',
        'capacity' => ['operator' => 'min', 'value' => '500000', 'unit' => 'g'],
    ],
];
ciRun(['500000 g analitik terazi'], [$zeroAnalysis]);
$zero = AssistantV2ConversationLog::$testCapture[0] ?? [];
ciCheck('CI-5', (int) ($zero['zero_result'] ?? 0) === 1 && ($zero['blocking_filter'] ?? '') === 'capacity');

ciRun(['Ohaus terazim bozuldu'], [[
    'intent' => 'service',
    'needs_catalog_search' => false,
    'ready_for_product_results' => false,
]]);
$handoff = AssistantV2ConversationLog::$testCapture[0] ?? [];
$handoffSignals = ciSignals($handoff);
ciCheck('CI-6', ($handoffSignals['handoff'] ?? false) === true && ($handoff['handoff_reason'] ?? '') === 'service_request');

ciReset();
$previousAnalyze = AssistantV2AdvisorAnalyzer::$testAnalyzeOverride;
$previousLlm = AssistantV2Llm::$testCompleteOverride;
$previousRag = AssistantV2AdvisorRag::$testRetrieveOverride;
AssistantV2AdvisorAnalyzer::$testAnalyzeOverride = static fn (): array => [
    'intent' => 'technical_question',
    'needs_catalog_search' => false,
    'ready_for_product_results' => false,
    'needsWebSearch' => false,
];
AssistantV2Llm::$testCompleteOverride = static fn (): array => [
    'message' => 'ATS120 9999 g kapasitelidir.',
];
AssistantV2AdvisorRag::$testRetrieveOverride = static fn (): array => [
    'block' => '',
    'debug' => ['used' => false],
];
$evidencePayload = AssistantV2Advisor::turn('ATS120 hakkında kısa bilgi', [], [], [], '');
$evidence = AssistantV2ConversationLog::$testCapture[0] ?? [];
$evidenceSignals = ciSignals($evidence);
AssistantV2AdvisorAnalyzer::$testAnalyzeOverride = $previousAnalyze;
AssistantV2Llm::$testCompleteOverride = $previousLlm;
AssistantV2AdvisorRag::$testRetrieveOverride = $previousRag;
ciCheck(
    'CI-7',
    ($evidenceSignals['evidence_missing'] ?? false) === true
        && (int) ($evidence['evidence_guard_triggered'] ?? 0) === 1
        && str_contains((string) ($evidencePayload['message'] ?? ''), AssistantV2AdvisorEvidence::NO_EVIDENCE)
);

$moisture = [
    'intent' => 'product_discovery',
    'ready_for_product_results' => false,
    'needs_catalog_search' => true,
    'needsWebSearch' => false,
    'category' => 'laboratuvar',
    'subcategory' => 'nem-tayin',
    'filter_updates' => [
        'category' => 'laboratuvar',
        'subcategory' => 'nem-tayin',
    ],
];
ciRun(
    ['plastik granülün nemini ölçmek istiyorum', 'başka bir terazi arıyorum'],
    [$moisture, [
        'intent' => 'product_discovery',
        'ready_for_product_results' => false,
        'needs_catalog_search' => true,
        'needsWebSearch' => false,
    ]]
);
$repeat = ciSignals(AssistantV2ConversationLog::$testCapture[1] ?? []);
ciCheck('CI-8', ($repeat['repeated_question'] ?? false) === true);

ciReset();
AssistantV2ConversationLog::$testFailWrite = true;
$isolated = AssistantV2AdvisorTestSupport::runConversation(
    ['200 g 0.0001 g analitik terazi lazım'],
    [$discovery]
);
AssistantV2ConversationLog::$testFailWrite = false;
ciCheck(
    'CI-9',
    trim((string) ($isolated[0]['message'] ?? '')) !== ''
        && (AssistantV2ConversationLog::$testCapture ?? []) === []
);

ciRun(['200 g 0.0001 g analitik terazi lazım'], [$discovery]);
$versionEvent = AssistantV2ConversationLog::$testCapture[0] ?? [];
ciCheck('CI-10', ($versionEvent['assistant_version'] ?? '') === AssistantV2ConversationLog::version()
    && ($versionEvent['assistant_version'] ?? '') !== '');

ciReset();

echo implode("\n", $results) . "\n";
echo 'Passed: ' . (10 - $failed) . "\n";
echo 'Failed: ' . $failed . "\n";
exit($failed === 0 ? 0 : 1);
