<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Profildeki kritik alanlardan, state'te olmayan bir sonraki soruyu seçer.
 */
final class AssistantV2QuestionPlan
{
    public const USAGE_QUESTION = 'Ne tartacaksınız veya teraziyi hangi amaçla kullanacaksınız?';

    /**
     * @param array<string, mixed> $state
     * @param list<array{role?:string,text?:string}> $history
     */
    public static function next(string $message, array $state, array $history = []): string
    {
        $field = self::nextField($message, $state, $history);
        if ($field === '') {
            return '';
        }
        if ($field === 'usage') {
            return self::USAGE_QUESTION;
        }
        $profile = self::profileFor($message, $state);
        $questions = is_array($profile['questions'] ?? null) ? $profile['questions'] : [];
        $key = $field === 'sample_material' ? 'sample' : $field;
        $question = $questions[$key] ?? '';

        return is_string($question) ? trim($question) : '';
    }

    /**
     * Planner'ın sorduğu sorunun state alanı. Sonraki kısa cevap buna bağlanır.
     *
     * @param array<string, mixed> $state
     * @param list<array{role?:string,text?:string}> $history
     */
    public static function nextField(string $message, array $state, array $history = []): string
    {
        $intent = AssistantV2Catalog::fold((string) ($state['advisor']['intent'] ?? ''));
        if ($intent !== '' && !in_array($intent, ['product_discovery', 'product', 'urun', 'compare'], true)) {
            return '';
        }
        $answered = self::answered($message, $state, $history);
        if (self::needsUsage($message, $state, $history, $answered)) {
            return 'usage';
        }
        $profile = self::profileFor($message, $state);
        if ($profile === null) {
            return '';
        }
        $fields = is_array($profile['critical_fields'] ?? null) ? $profile['critical_fields'] : [];
        foreach ($fields as $field) {
            if (!is_string($field) || self::fieldAnswered($field, $answered)) {
                continue;
            }

            return $field === 'sample' ? 'sample_material' : $field;
        }

        return '';
    }

    /**
     * Asistan bir alan sorduktan sonra gelen kısa cevap o alana yazılır.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function bindPendingAnswer(array $state, string $message): array
    {
        $field = trim((string) ($state['advisor']['pending_question_field'] ?? ''));
        $message = trim($message);
        if ($field === '' || $message === '') {
            return $state;
        }
        if (self::looksLikeNewTopic($message) || self::answersDifferentMeasure($field, $message)) {
            $state['advisor']['pending_question_field'] = '';

            return $state;
        }
        $value = self::answerValue($message);
        if ($value === '') {
            return $state;
        }

        $state['advisor']['facts'][$field] = $value;
        if ($field === 'sample_material') {
            $state['advisor']['facts']['sample'] = $value;
        }
        if ($field === 'usage') {
            $usage = AssistantV2Catalog::statedUsage($value) ?? self::usageFromPendingAnswer($value);
            if ($usage !== null) {
                $state['advisor']['category'] = $usage;
                $state['filters']['category'] = $usage;
                $state['advisor']['facts']['usage'] = $usage;
            }
        }
        if ($field === 'capacity') {
            $stated = preg_match('/\d/u', $message) === 1 && preg_match('/\d\s*(?:g|gr|kg|mg)\b/iu', $message) !== 1
                ? $message . ' g'
                : $message;
            $state['filters'] = AssistantV2Filters::applyChipCapacity($state['filters'], $stated);
            $operator = AssistantV2Filters::statedCapacityOperator($stated);
            if ($operator !== null && is_numeric($state['filters']['capacity']['value_g'] ?? null)) {
                $state['filters']['capacity']['operator'] = $operator;
            }
        }
        if ($field === 'readability') {
            $state['filters'] = AssistantV2Filters::applyStatedReadability($state['filters'], $message);
        }

        $answered = is_array($state['advisor']['answered_fields'] ?? null) ? $state['advisor']['answered_fields'] : [];
        $answered[] = $field;
        if ($field === 'sample_material') {
            $answered[] = 'sample';
        }
        $state['advisor']['answered_fields'] = array_values(array_unique(array_filter($answered, 'is_string')));
        $state['advisor']['pending_question_field'] = '';

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @param list<array{role?:string,text?:string}> $history
     * @return list<string>
     */
    public static function answeredKeys(string $message, array $state, array $history = []): array
    {
        return array_keys(self::answered($message, $state, $history));
    }

    /**
     * @param array<string, mixed> $state
     * @param list<array{role?:string,text?:string}> $history
     * @return array<string, true>
     */
    private static function answered(string $message, array $state, array $history): array
    {
        $out = [];
        $stored = $state['advisor']['answered_fields'] ?? [];
        if (is_array($stored)) {
            foreach ($stored as $field) {
                if (is_string($field) && $field !== '') {
                    $out[$field] = true;
                }
            }
        }

        $blob = $message;
        foreach ($history as $turn) {
            if (!is_array($turn) || ($turn['role'] ?? '') !== 'user') {
                continue;
            }
            $blob .= ' ' . (string) ($turn['text'] ?? '');
        }
        $fold = AssistantV2Catalog::fold($blob);
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        $capacity = is_array($filters['capacity'] ?? null) ? $filters['capacity'] : [];
        $precision = is_array($filters['precision'] ?? null) ? $filters['precision'] : [];
        if (is_numeric($capacity['value_g'] ?? null) || is_numeric($capacity['min_g'] ?? null)) {
            $out['capacity'] = true;
            $out['total_load'] = true;
        }
        if (is_numeric($precision['value'] ?? null) && ($precision['unresolved'] ?? false) !== true) {
            $out['readability'] = true;
        }
        $facts = is_array($state['advisor']['facts'] ?? null) ? $state['advisor']['facts'] : [];
        foreach (['sample', 'sample_material', 'temperature', 'capacity', 'readability', 'total_load', 'cell_count', 'barcode', 'label', 'usage'] as $key) {
            $value = $facts[$key] ?? null;
            if ((is_string($value) && trim($value) !== '') || is_int($value) || is_float($value)) {
                $out[$key] = true;
            }
        }
        if (isset($out['sample']) || isset($out['sample_material'])) {
            $out['sample'] = true;
            $out['sample_material'] = true;
        }
        if (preg_match('/granul|numune|plastik|gida|rutubet|\bun\b|toz\b/u', $fold) === 1) {
            $out['sample'] = true;
            $out['sample_material'] = true;
        }
        if (preg_match('/sicaklik|derece|\d+\s*°?\s*c\b/u', $fold) === 1) {
            $out['temperature'] = true;
        }
        if (preg_match('/load cell|yuk hucre/u', $fold) === 1
            && preg_match('/\b(bir|iki|uc|dort|bes|alti|yedi|sekiz)\b|\b\d+\s*(adet|hucre|load cell)/u', $fold) === 1) {
            $out['cell_count'] = true;
        }
        if (preg_match('/barkod|etiket bas/u', $fold) === 1) {
            $out['barcode'] = true;
        }
        if (preg_match('/\bplu\b|etiket|entegrasyon/u', $fold) === 1) {
            $out['label'] = true;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private static function profileFor(string $message, array $state): ?array
    {
        $json = self::profiles();
        $sub = '';
        foreach ([$state['filters']['subcategory'] ?? null, $state['advisor']['subcategory'] ?? null] as $slug) {
            if (is_string($slug) && $slug !== '') {
                $sub = $slug;
                break;
            }
        }
        if ($sub !== '') {
            foreach ($json as $key => $profile) {
                if (!is_string($key) || !is_array($profile) || !is_array($profile['critical_fields'] ?? null)) {
                    continue;
                }
                $profileSub = is_string($profile['subcategory'] ?? null) ? $profile['subcategory'] : '';
                if ($sub === $key || ($profileSub !== '' && ($sub === $profileSub || str_starts_with($sub, $profileSub . '-') || str_starts_with($sub, $profileSub)))) {
                    return $profile;
                }
            }
        }

        $category = (string) ($state['advisor']['category'] ?? $state['filters']['category'] ?? '');
        if ($category !== '' && isset($json[$category]) && is_array($json[$category]) && is_array($json[$category]['critical_fields'] ?? null)) {
            return $json[$category];
        }

        $stated = AssistantV2Catalog::statedLabScaleType($message);
        $category = (string) ($state['advisor']['category'] ?? $state['filters']['category'] ?? '');
        $stored = AssistantV2Catalog::fold((string) (
            $state['filters']['type_token']
            ?? $state['advisor']['facts']['type_token']
            ?? ''
        ));
        $type = $stated;
        if ($type === null && $category === 'laboratuvar' && $stored !== '') {
            $type = $stored;
        }
        if (in_array($type, ['analitik', 'hassas', 'yari analitik', 'mikro', 'yari mikro'], true)
            && isset($json['lab-analitik']) && is_array($json['lab-analitik'])) {
            return $json['lab-analitik'];
        }

        return null;
    }

    /**
     * @param array<string, true> $answered
     */
    private static function fieldAnswered(string $field, array $answered): bool
    {
        if (isset($answered[$field])) {
            return true;
        }

        return $field === 'sample' && isset($answered['sample_material']);
    }

    /**
     * @param array<string, mixed> $state
     * @param list<array{role?:string,text?:string}> $history
     * @param array<string, true> $answered
     */
    private static function needsUsage(string $message, array $state, array $history, array $answered): bool
    {
        if (isset($answered['usage'])) {
            return false;
        }
        $category = (string) ($state['advisor']['category'] ?? $state['filters']['category'] ?? '');
        $subcategory = (string) ($state['advisor']['subcategory'] ?? $state['filters']['subcategory'] ?? '');
        if ($category !== '' || $subcategory !== '') {
            return false;
        }
        $blob = $message;
        foreach ($history as $turn) {
            if (is_array($turn) && ($turn['role'] ?? '') === 'user') {
                $blob .= ' ' . (string) ($turn['text'] ?? '');
            }
        }

        return AssistantV2Catalog::barePrecisionScale($blob);
    }

    private static function looksLikeNewTopic(string $message): bool
    {
        $fold = AssistantV2Catalog::fold($message);

        return preg_match('/\b(ariyorum|lazim|istiyorum|oner)\b/u', $fold) === 1
            && preg_match('/\b(terazi|cihaz|kantar|load cell)\b/u', $fold) === 1;
    }

    private static function answersDifferentMeasure(string $field, string $message): bool
    {
        if (in_array($field, ['capacity', 'total_load', 'readability', 'temperature'], true)) {
            return false;
        }
        $fold = AssistantV2Catalog::fold($message);

        return preg_match('/^\s*\d+(?:[.,]\d+)?\s*(g|gr|gram|kg|mg|c)?\s*$/u', $fold) === 1;
    }

    private static function answerValue(string $message): string
    {
        $text = trim($message);
        $text = preg_replace('/\s+(dedim(\s+ya)?|demi[sş]tim(\s+ya)?|soylemistim|söylemiştim)\s*$/iu', '', $text) ?? $text;

        return trim($text, " \t.!?\"'");
    }

    private static function usageFromPendingAnswer(string $value): ?string
    {
        $fold = AssistantV2Catalog::fold($value);
        if (preg_match('/kimya|numune|laboratuvar|\blab\b/u', $fold) === 1) {
            return 'laboratuvar';
        }
        if (preg_match('/altin|taki|kuyum|mucevher/u', $fold) === 1) {
            return 'kuyumcu';
        }
        if (preg_match('/uretim|fabrika|sanayi|endustri/u', $fold) === 1) {
            return 'endustriyel';
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function profiles(): array
    {
        $path = __DIR__ . '/advisor_category_profiles.json';
        if (!is_file($path)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : [];
    }
}
