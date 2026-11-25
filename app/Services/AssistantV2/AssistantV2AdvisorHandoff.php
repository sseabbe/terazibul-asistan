<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * WhatsApp / telefon yalnız belirli nedenlerle. Düşük güven ve ilk netleştirme yetmez.
 */
final class AssistantV2AdvisorHandoff
{
    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $state
     * @param array{found?:bool}|null $errorMatch
     */
    public static function reason(string $message, array $analysis, array $state, ?array $errorMatch = null): string
    {
        $fold = AssistantV2Catalog::fold($message);
        $intent = AssistantV2Catalog::fold((string) ($analysis['intent'] ?? $state['advisor']['intent'] ?? ''));

        if (self::userRequested($fold)) {
            return 'user_requested';
        }
        if ($intent === 'service') {
            return 'service_request';
        }
        if (AssistantV2AdvisorRag::looksLikeErrorCodeQueryPublic($message)) {
            if ($errorMatch === null || ($errorMatch['found'] ?? false) !== true) {
                return 'error_code_unknown';
            }
        }
        if (preg_match('/\b(exproof|ex-proof|atex|sertifika|endustriyel proje|ozel proje)\b/u', $fold) === 1) {
            return 'project_support';
        }
        if (preg_match('/uyumluluk|uyumlu mu|aksesuar uyum/u', $fold) === 1
            || (preg_match('/\buyumlu\b/u', $fold) === 1 && preg_match('/\b(aksesuar|gosterge|load cell|haberles)\b/u', $fold) === 1)) {
            return 'compatibility_uncertain';
        }
        return '';
    }

    private static function userRequested(string $fold): bool
    {
        return preg_match('/\b(uzman|insanla|canli destek|gorusmek istiyorum|temsilci)\b/u', $fold) === 1
            || preg_match('/\bbeni ara(yin|yiniz|sin|siniz)?\b/u', $fold) === 1
            || preg_match('/\b(numarami birak|bana ulas)\w*\b/u', $fold) === 1;
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $state
     */
    public static function isUserRequested(string $message): bool
    {
        return self::userRequested(AssistantV2Catalog::fold($message));
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $state
     */
    private static function noCatalogMatch(array $analysis, array $state, string $message): bool
    {
        $intent = AssistantV2Catalog::fold((string) ($state['advisor']['intent'] ?? ''));
        if (!in_array($intent, ['product_discovery', 'compare', 'price', 'product'], true)) {
            return false;
        }
        if (($analysis['needs_catalog_search'] ?? true) === false) {
            return false;
        }
        $decision = AssistantV2AdvisorState::readinessDecision($state, $analysis, $message);
        if ($decision['ready'] !== true) {
            return false;
        }

        return (int) ($state['search_total'] ?? 0) === 0;
    }
}
