<?php

declare(strict_types=1);

/**
 * Ürün keşfi cevap metni. Karar mantığını değil, gösterilen cümleyi doğrular.
 * Web'den çalışmaz.
 *
 * Run: php scripts/advisor_response_copy_tests.php
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
use App\Services\AssistantV2\AssistantV2Llm;

require dirname(__DIR__) . '/vendor/autoload.php';

\Core\Env::load(dirname(__DIR__) . '/.env');
$app = new \Core\Application(dirname(__DIR__));
$app->boot();

$failed = 0;
$results = [];

function copyCheck(string $id, bool $ok, string $detail = ''): void
{
    global $failed, $results;
    $results[] = $id . ' ' . ($ok ? 'PASS' : 'FAIL') . ($ok || $detail === '' ? '' : ' ' . $detail);
    if (!$ok) {
        $failed++;
    }
}

function copyShow(array $payload): bool
{
    return AssistantV2AdvisorTestSupport::hasActionType($payload, 'show_products');
}

function copyFold(string $text): string
{
    return \App\Services\AssistantV2\AssistantV2Catalog::fold($text);
}

$base = [
    'intent' => 'product_discovery',
    'ready_for_product_results' => true,
    'needs_catalog_search' => true,
    'needsWebSearch' => false,
];

$previousAnalyze = AssistantV2AdvisorAnalyzer::$testAnalyzeOverride;
$previousLlm = AssistantV2Llm::$testCompleteOverride;
$previousRag = AssistantV2AdvisorRag::$testRetrieveOverride;
AssistantV2AdvisorRag::$testRetrieveOverride = static fn (): array => ['block' => '', 'debug' => ['used' => false]];
AssistantV2Llm::$testCompleteOverride = static fn (): array => [
    'message' => 'Anladım, 600 g kapasite ihtiyacınızı not ettim.',
];
AssistantV2AdvisorAnalyzer::$testAnalyzeOverride = static fn (): array => array_merge($base, [
    'category' => 'kuyumcu',
    'filter_updates' => [
        'category' => 'kuyumcu',
        'capacity' => ['operator' => 'min', 'value' => '600', 'unit' => 'g'],
    ],
]);
$onlyCapacity = AssistantV2Advisor::turn(
    'kuyumcu terazisi 600 g',
    ['advisor' => ['answered_fields' => ['readability']]],
    [],
    [],
    ''
);
$onlyText = (string) ($onlyCapacity['message'] ?? '');
copyCheck('COPY-1', copyShow($onlyCapacity)
    && !str_contains(copyFold($onlyText), 'not ettim')
    && str_contains($onlyText, '600 g kapasite')
    && str_contains($onlyText, 'Ürünleri Gör')
    && !str_contains($onlyText, '600 g hassasiyet'), $onlyText);

AssistantV2Llm::$testCompleteOverride = static fn (): array => [
    'message' => 'Anladım, 600 g kapasite ve 0.01 g ihtiyacınızı not ettim.',
];
AssistantV2AdvisorAnalyzer::$testAnalyzeOverride = static fn (): array => array_merge($base, [
    'category' => 'kuyumcu',
    'filter_updates' => [
        'category' => 'kuyumcu',
        'capacity' => ['operator' => 'min', 'value' => '600', 'unit' => 'g'],
        'precision' => ['value' => '0.01', 'unit' => 'g'],
    ],
]);
$both = AssistantV2Advisor::turn('kuyumcu terazisi 600 g ve 0.01 g', [], [], [], '');
$bothText = (string) ($both['message'] ?? '');
copyCheck('COPY-2', copyShow($both)
    && str_contains($bothText, '600 g kapasite')
    && str_contains($bothText, '0,01 g okunabilirliğ')
    && !str_contains($bothText, '600 g hassasiyet')
    && !str_contains($bothText, '0,01 g kapasite')
    && !str_contains(copyFold($bothText), 'not ettim'), $bothText);

AssistantV2Llm::$testCompleteOverride = static fn (): array => [
    'message' => 'Bu bilgiyi aldım, ilerliyorum.',
];
$gold = AssistantV2Advisor::turn('altın, yüzük, küpe tartacağım', [], [], [], '');
$goldText = (string) ($gold['message'] ?? '');
copyCheck('COPY-3', copyShow($gold)
    && str_contains($goldText, 'Altın ve takı tartımı için'), $goldText);

AssistantV2AdvisorAnalyzer::$testAnalyzeOverride = static fn (): array => array_merge($base, [
    'ready_for_product_results' => false,
    'category' => 'kuyumcu',
    'filter_updates' => ['category' => 'kuyumcu'],
]);
AssistantV2Llm::$testCompleteOverride = static fn (): array => [
    'message' => 'Anladım, kapasite ihtiyacınızı not ettim. Tek seferde tartacağınız en ağır parça yaklaşık kaç gram?',
];
$ask = AssistantV2Advisor::turn('kuyumcu terazisi arıyorum', [], [], [], '');
$askText = (string) ($ask['message'] ?? '');
copyCheck('COPY-4', !copyShow($ask)
    && !str_contains(copyFold($askText), 'not ettim')
    && !str_contains(copyFold($askText), 'listeledim')
    && str_contains($askText, '?'), $askText);

AssistantV2AdvisorAnalyzer::$testAnalyzeOverride = static fn (): array => array_merge($base, [
    'category' => 'kuyumcu',
    'filter_updates' => [
        'category' => 'kuyumcu',
        'capacity' => ['operator' => 'min', 'value' => '600', 'unit' => 'g'],
        'precision' => ['value' => '0.01', 'unit' => 'g'],
    ],
]);
AssistantV2Llm::$testCompleteOverride = static fn (): array => [
    'message' => 'Kriteri kaydettim.',
];
$plain = AssistantV2Advisor::turn('600 g ve 0.01 g istiyorum', [], [], [], '');
$plainText = (string) ($plain['message'] ?? '');
$plainFold = copyFold($plainText);
copyCheck('COPY-5', copyShow($plain)
    && !str_contains($plainText, 'Altın')
    && !str_contains($plainFold, 'taki')
    && !str_contains($plainText, 'Nem tayini')
    && !str_contains($plainText, 'Laboratuvar kullanımı')
    && !str_contains($plainText, 'Tank/silo')
    && !str_contains($plainText, 'Market/kasap'), $plainText);

AssistantV2AdvisorAnalyzer::$testAnalyzeOverride = $previousAnalyze;
AssistantV2Llm::$testCompleteOverride = $previousLlm;
AssistantV2AdvisorRag::$testRetrieveOverride = $previousRag;

echo implode("\n", $results) . "\n";
echo 'Passed: ' . (5 - $failed) . "\n";
echo 'Failed: ' . $failed . "\n";
exit($failed === 0 ? 0 : 1);
