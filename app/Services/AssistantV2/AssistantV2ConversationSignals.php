<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Yalnız deterministik kalite sinyalleri. Tespiti güvenilir olmayanlar üretilmez.
 */
final class AssistantV2ConversationSignals
{
    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $prior
     * @return array<string, bool>
     */
    public static function build(array $current, array $prior): array
    {
        $pending = trim((string) ($current['pending_question_field'] ?? ''));
        $priorPending = trim((string) ($prior['pending_question_field'] ?? ''));
        $category = trim((string) ($current['category'] ?? ''));
        $priorCategory = trim((string) ($prior['category'] ?? ''));
        $handoffReason = trim((string) ($current['handoff_reason'] ?? ''));
        $message = AssistantV2Catalog::fold((string) ($current['user_message'] ?? ''));

        return [
            'repeated_question' => $priorPending !== '' && $pending === $priorPending,
            'user_correction' => self::userCorrection($message, $current, $prior),
            'wrong_category_suspected' => $priorCategory !== '' && $category !== '' && $priorCategory !== $category,
            'zero_result' => ($current['zero_result'] ?? false) === true,
            'handoff' => $handoffReason !== '',
            'evidence_missing' => ($current['evidence_guard_triggered'] ?? false) === true
                || trim((string) ($current['assistant_message'] ?? '')) === AssistantV2AdvisorEvidence::NO_EVIDENCE,
            'unresolved_constraint' => ($current['unresolved_constraint'] ?? false) === true,
            'show_products_clicked' => ($current['ui_action'] ?? '') === 'show_products',
            'successful_product_discovery' => ($current['show_products'] ?? false) === true
                && (int) ($current['search_total'] ?? 0) > 0
                && ($current['ready_for_products'] ?? false) === true,
        ];
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $prior
     */
    private static function userCorrection(string $fold, array $current, array $prior): bool
    {
        if ($fold === '' || preg_match('/\b(hayir|yanlis|vazgectim)\b|dedim ya|aslinda/u', $fold) !== 1) {
            return false;
        }

        $changed = trim((string) ($current['category'] ?? '')) !== trim((string) ($prior['category'] ?? ''))
            || trim((string) ($current['subcategory'] ?? '')) !== trim((string) ($prior['subcategory'] ?? ''))
            || (string) ($current['capacity_g'] ?? '') !== (string) ($prior['capacity_g'] ?? '')
            || (string) ($current['brands'] ?? '') !== (string) ($prior['brands'] ?? '');

        return $changed;
    }
}
