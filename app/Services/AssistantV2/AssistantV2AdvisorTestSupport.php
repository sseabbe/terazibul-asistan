<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Integration helpers for advisor conversation tests (no HTTP).
 */
final class AssistantV2AdvisorTestSupport
{
    /**
     * @param list<string> $messages User messages in order (one per turn).
     * @param list<array<string,mixed>> $analysisMocks Per-turn analyzer JSON (same length as messages).
     * @param array<string, mixed> $initialState
     * @param array{brand?:string,category?:string} $page
     * @return list<array<string,mixed>>
     */
    public static function runConversation(
        array $messages,
        array $analysisMocks = [],
        array $initialState = [],
        array $page = []
    ): array {
        $previousAnalyze = AssistantV2AdvisorAnalyzer::$testAnalyzeOverride;
        $previousLlm = AssistantV2Llm::$testCompleteOverride;
        $previousWeb = AssistantV2AdvisorWebSearch::$testSearchOverride;
        $previousRag = AssistantV2AdvisorRag::$testRetrieveOverride;

        AssistantV2AdvisorRag::$testRetrieveOverride = static fn (): array => [
            'block' => '',
            'debug' => ['used' => false, 'confidence' => 'none', 'intent' => 'technical_question'],
        ];

        AssistantV2Llm::$testCompleteOverride = static fn (): array => [
            'message' => 'Test yanıtı — yapılandırılmış kanıtlara dayanır.',
        ];

        $turnIndex = 0;
        AssistantV2AdvisorAnalyzer::$testAnalyzeOverride = static function (
            string $message,
            array $history,
            array $state,
            array $pageContext
        ) use (&$turnIndex, $analysisMocks, $messages): array {
            unset($message, $history, $state, $pageContext);
            $mock = $analysisMocks[$turnIndex] ?? $analysisMocks[count($analysisMocks) - 1] ?? [
                'intent'                    => 'product_discovery',
                'ready_for_product_results' => false,
                'needs_catalog_search'      => true,
                'needsWebSearch'            => false,
            ];
            ++$turnIndex;

            return $mock;
        };

        $state = $initialState;
        $history = [];
        $turns = [];

        foreach ($messages as $message) {
            $payload = AssistantV2Advisor::turn($message, $state, $history, $page, '');
            $turns[] = $payload;
            $state = is_array($payload['state'] ?? null) ? $payload['state'] : $state;
            $history[] = ['role' => 'user', 'text' => $message];
            $history[] = ['role' => 'assistant', 'text' => (string) ($payload['message'] ?? '')];
        }

        AssistantV2AdvisorAnalyzer::$testAnalyzeOverride = $previousAnalyze;
        AssistantV2Llm::$testCompleteOverride = $previousLlm;
        AssistantV2AdvisorWebSearch::$testSearchOverride = $previousWeb;
        AssistantV2AdvisorRag::$testRetrieveOverride = $previousRag;

        return $turns;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function hasActionType(array $payload, string $type): bool
    {
        foreach (is_array($payload['actions'] ?? null) ? $payload['actions'] : [] as $action) {
            if (is_array($action) && ($action['type'] ?? '') === $type) {
                return true;
            }
        }

        return false;
    }
}
