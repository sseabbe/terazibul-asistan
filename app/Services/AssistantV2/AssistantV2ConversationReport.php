<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Konuşma olaylarından salt okunur özet. Prompt, profil, katalog ve RAG değiştirmez.
 */
final class AssistantV2ConversationReport
{
    public const SAMPLE_MIN = 20;

    /** @var list<string> */
    private const HANDOFF_REASONS = [
        'service_request',
        'user_requested',
        'project_support',
        'compatibility_uncertain',
        'error_code_unknown',
        'no_catalog_match',
    ];

    /** @var list<string> */
    private const STOPWORDS = [
        'lazim', 'istiyorum', 'istiyoruz', 'var', 'yok', 'icin', 'bir', 'bu', 'su', 'ne',
        'nasil', 'kadar', 'gibi', 'ama', 'ile', 'veya', 'daha', 'cok', 'lutfen', 'beni',
        'bana', 'benim', 'numara', 'mail', 'tamam', 'evet', 'hayir', 'olan', 'olarak',
        'gerek', 'please', 'the', 'and', 'for', 'ariyorum',
    ];

    /** @var list<string> */
    private const GENERIC_TOKENS = [
        'terazi', 'terazisi', 'terazim', 'kantar', 'kantari', 'cihaz', 'cihazi',
    ];

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public static function filter(array $events, int $days, string $version, int $now): array
    {
        $days = max(1, $days);
        $cutoff = $now - ($days * 86400);
        $version = trim($version);
        $out = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $created = strtotime((string) ($event['created_at'] ?? ''));
            if ($created === false || $created < $cutoff) {
                continue;
            }
            if ($version !== '' && (string) ($event['assistant_version'] ?? '') !== $version) {
                continue;
            }
            $out[] = $event;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param array{days?:int,version?:string,generated_at?:string} $options
     * @return array<string, mixed>
     */
    public static function build(array $events, array $options = []): array
    {
        $days = max(1, (int) ($options['days'] ?? 7));
        $version = trim((string) ($options['version'] ?? ''));
        $turns = count($events);
        $conversations = [];
        $unknownConversationTurns = 0;
        $versions = [];
        $intents = ['product_discovery' => 0, 'service' => 0, 'off_topic' => 0, 'other' => 0];
        $ready = 0;
        $show = 0;
        $successful = 0;
        $zero = 0;
        $handoff = 0;
        $signals = [
            'evidence_missing' => 0,
            'unresolved_constraint' => 0,
            'repeated_question' => 0,
            'user_correction' => 0,
            'wrong_category_suspected' => 0,
        ];
        $categories = [];
        $subcategories = [];
        $emptyCategory = 0;
        $emptySubcategory = 0;
        $zeroByCategory = [];
        $zeroBySubcategory = [];
        $zeroByBlocking = [];
        $zeroBuckets = [
            'capacity_blocked' => 0,
            'brand_blocked' => 0,
            'readability_blocked' => 0,
            'taxonomy_or_data' => 0,
            'unclassified' => 0,
        ];
        $subRequests = [];
        $subZeros = [];
        $subBlocking = [];
        $handoffReasons = [];
        foreach (self::HANDOFF_REASONS as $reason) {
            $handoffReasons[$reason] = 0;
        }
        $handoffReasons['diğer'] = 0;
        $handoffByCategory = [];
        $handoffBySubcategory = [];
        $examples = [
            'repeated_question' => [],
            'user_correction' => [],
            'wrong_category_suspected' => [],
            'evidence_missing' => [],
            'unresolved_constraint' => [],
        ];
        $evidenceByCategory = [];
        $repeatedFields = [];
        $versionRows = [];

        foreach ($events as $event) {
            $id = trim((string) ($event['conversation_id'] ?? ''));
            if ($id === '') {
                $unknownConversationTurns++;
            } else {
                $conversations[$id] = true;
            }
            $ver = trim((string) ($event['assistant_version'] ?? ''));
            if ($ver === '') {
                $ver = '(empty)';
            }
            $versions[$ver] = ($versions[$ver] ?? 0) + 1;
            if (!isset($versionRows[$ver])) {
                $versionRows[$ver] = ['turns' => 0, 'zero_result' => 0, 'repeated_question' => 0, 'handoff' => 0];
            }
            $versionRows[$ver]['turns']++;

            $intent = strtolower(trim((string) ($event['intent'] ?? '')));
            if (isset($intents[$intent])) {
                $intents[$intent]++;
            } else {
                $intents['other']++;
            }

            if ((int) ($event['ready_for_products'] ?? 0) === 1) {
                $ready++;
            }
            if ((int) ($event['show_products'] ?? 0) === 1) {
                $show++;
            }
            $decoded = self::signals($event);
            if (self::flag($decoded, 'successful_product_discovery')) {
                $successful++;
            }
            $isZero = (int) ($event['zero_result'] ?? 0) === 1;
            if ($isZero) {
                $zero++;
                $versionRows[$ver]['zero_result']++;
            }
            $reason = trim((string) ($event['handoff_reason'] ?? ''));
            if ($reason !== '') {
                $handoff++;
                $versionRows[$ver]['handoff']++;
                if (isset($handoffReasons[$reason])) {
                    $handoffReasons[$reason]++;
                } else {
                    $handoffReasons['diğer']++;
                }
            }

            foreach ($signals as $key => $count) {
                unset($count);
                if (self::flag($decoded, $key)) {
                    $signals[$key]++;
                    self::pushExample($examples, $key, $id);
                }
            }
            if (self::flag($decoded, 'repeated_question')) {
                $versionRows[$ver]['repeated_question']++;
                $field = trim((string) ($event['pending_question_field'] ?? ''));
                if ($field !== '') {
                    $repeatedFields[$field] = ($repeatedFields[$field] ?? 0) + 1;
                }
            }

            $category = trim((string) ($event['category'] ?? ''));
            $subcategory = trim((string) ($event['subcategory'] ?? ''));
            if ($category === '') {
                $emptyCategory++;
                $categoryLabel = '(empty)';
            } else {
                $categoryLabel = $category;
            }
            if ($subcategory === '') {
                $emptySubcategory++;
                $subLabel = '(empty)';
            } else {
                $subLabel = $subcategory;
            }
            $categories[$categoryLabel] = ($categories[$categoryLabel] ?? 0) + 1;
            $subcategories[$subLabel] = ($subcategories[$subLabel] ?? 0) + 1;
            $subRequests[$subLabel] = ($subRequests[$subLabel] ?? 0) + 1;

            if ($isZero) {
                $zeroByCategory[$categoryLabel] = ($zeroByCategory[$categoryLabel] ?? 0) + 1;
                $zeroBySubcategory[$subLabel] = ($zeroBySubcategory[$subLabel] ?? 0) + 1;
                $subZeros[$subLabel] = ($subZeros[$subLabel] ?? 0) + 1;
                $blocking = trim((string) ($event['blocking_filter'] ?? ''));
                $blockingLabel = $blocking === '' ? '(empty)' : $blocking;
                $zeroByBlocking[$blockingLabel] = ($zeroByBlocking[$blockingLabel] ?? 0) + 1;
                $bucket = self::zeroBucket($blocking);
                $zeroBuckets[$bucket]++;
                if (!isset($subBlocking[$subLabel])) {
                    $subBlocking[$subLabel] = [];
                }
                $subBlocking[$subLabel][$blockingLabel] = ($subBlocking[$subLabel][$blockingLabel] ?? 0) + 1;
            }
            if ($reason !== '') {
                $handoffByCategory[$categoryLabel] = ($handoffByCategory[$categoryLabel] ?? 0) + 1;
                $handoffBySubcategory[$subLabel] = ($handoffBySubcategory[$subLabel] ?? 0) + 1;
            }
            if (self::flag($decoded, 'evidence_missing')) {
                $evidenceByCategory[$categoryLabel] = ($evidenceByCategory[$categoryLabel] ?? 0) + 1;
            }
        }

        $gaps = [];
        foreach ($subZeros as $label => $zeroCount) {
            if ($label === '(empty)' || $zeroCount < 2) {
                continue;
            }
            $gaps[] = [
                'subcategory' => $label,
                'requests' => (int) ($subRequests[$label] ?? 0),
                'zero_results' => $zeroCount,
                'blocking_filter' => self::ranked($subBlocking[$label] ?? []),
            ];
        }
        usort($gaps, static fn (array $a, array $b): int => $b['zero_results'] <=> $a['zero_results']);

        $comparison = [];
        ksort($versionRows);
        foreach ($versionRows as $name => $row) {
            $comparison[] = [
                'version' => $name,
                'turns' => $row['turns'],
                'zero_result' => $row['zero_result'],
                'repeated_question' => $row['repeated_question'],
                'handoff' => $row['handoff'],
                'sample' => $row['turns'] < self::SAMPLE_MIN ? 'insufficient sample' : '',
            ];
        }

        return [
            'generated_at' => (string) ($options['generated_at'] ?? date('Y-m-d H:i:s')),
            'days' => $days,
            'version_filter' => $version,
            'turns' => $turns,
            'conversations' => count($conversations),
            'unknown_conversation_turns' => $unknownConversationTurns,
            'versions' => self::ranked($versions),
            'intents' => $intents,
            'ready_for_products' => self::share($ready, $turns),
            'show_products' => self::share($show, $turns),
            'successful_product_discovery' => self::share($successful, $turns),
            'zero_result' => self::share($zero, $turns),
            'handoff' => self::share($handoff, $turns),
            'evidence_missing' => $signals['evidence_missing'],
            'unresolved_constraint' => $signals['unresolved_constraint'],
            'repeated_question' => $signals['repeated_question'],
            'user_correction' => $signals['user_correction'],
            'wrong_category_suspected' => $signals['wrong_category_suspected'],
            'categories' => self::ranked($categories),
            'subcategories' => self::ranked($subcategories),
            'empty_category' => $emptyCategory,
            'empty_subcategory' => $emptySubcategory,
            'zero_by_category' => self::ranked($zeroByCategory),
            'zero_by_subcategory' => self::ranked($zeroBySubcategory),
            'zero_by_blocking' => self::ranked($zeroByBlocking),
            'zero_buckets' => $zeroBuckets,
            'handoff_reasons' => $handoffReasons,
            'handoff_by_category' => self::ranked($handoffByCategory),
            'handoff_by_subcategory' => self::ranked($handoffBySubcategory),
            'quality_examples' => $examples,
            'phrase_candidates' => self::phrases($events),
            'catalog_gaps' => $gaps,
            'evidence_by_category' => self::ranked(array_filter($evidenceByCategory, static fn (int $n): bool => $n >= 2)),
            'repeated_fields' => self::ranked(array_filter($repeatedFields, static fn (int $n): bool => $n >= 2)),
            'version_comparison' => $comparison,
            'notes' => self::notes($turns, $zero, $signals['repeated_question'], $handoff),
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    public static function renderText(array $report): string
    {
        $lines = [];
        $lines[] = 'Conversation intelligence report';
        $lines[] = 'Window days: ' . (int) ($report['days'] ?? 0);
        $lines[] = 'Version filter: ' . (((string) ($report['version_filter'] ?? '')) !== '' ? (string) $report['version_filter'] : '(none)');
        $lines[] = 'Generated: ' . (string) ($report['generated_at'] ?? '');
        if ((int) ($report['turns'] ?? 0) === 0) {
            $lines[] = 'No conversation events in this window.';
        }
        $lines[] = 'Conversations: ' . (int) ($report['conversations'] ?? 0);
        $lines[] = 'Turns: ' . (int) ($report['turns'] ?? 0);
        $lines[] = 'Unknown conversation turns: ' . (int) ($report['unknown_conversation_turns'] ?? 0);
        $lines[] = 'Versions: ' . self::pairs($report['versions'] ?? []);
        $lines[] = 'Product discovery turns: ' . (int) ($report['intents']['product_discovery'] ?? 0);
        $lines[] = 'Service turns: ' . (int) ($report['intents']['service'] ?? 0);
        $lines[] = 'Off-topic turns: ' . (int) ($report['intents']['off_topic'] ?? 0);
        $lines[] = 'Other intent turns: ' . (int) ($report['intents']['other'] ?? 0);
        $lines[] = 'ready_for_products: ' . self::shareText($report['ready_for_products'] ?? []);
        $lines[] = 'SHOW_PRODUCTS: ' . self::shareText($report['show_products'] ?? []);
        $lines[] = 'successful_product_discovery: ' . self::shareText($report['successful_product_discovery'] ?? []);
        $lines[] = 'Zero results: ' . self::shareText($report['zero_result'] ?? []);
        $lines[] = 'Handoffs: ' . self::shareText($report['handoff'] ?? []);
        $lines[] = 'evidence_missing: ' . (int) ($report['evidence_missing'] ?? 0);
        $lines[] = 'unresolved_constraint: ' . (int) ($report['unresolved_constraint'] ?? 0);
        $lines[] = 'repeated_question: ' . (int) ($report['repeated_question'] ?? 0);
        $lines[] = 'user_correction: ' . (int) ($report['user_correction'] ?? 0);
        $lines[] = 'wrong_category_suspected: ' . (int) ($report['wrong_category_suspected'] ?? 0);
        $lines[] = 'Categories: ' . self::pairs($report['categories'] ?? []);
        $lines[] = 'Empty category turns: ' . (int) ($report['empty_category'] ?? 0);
        $lines[] = 'Subcategories: ' . self::pairs($report['subcategories'] ?? []);
        $lines[] = 'Empty subcategory turns: ' . (int) ($report['empty_subcategory'] ?? 0);
        $lines[] = 'Zero-result categories: ' . self::pairs($report['zero_by_category'] ?? []);
        $lines[] = 'Zero-result subcategories: ' . self::pairs($report['zero_by_subcategory'] ?? []);
        foreach (is_array($report['zero_by_blocking'] ?? null) ? $report['zero_by_blocking'] : [] as $row) {
            $lines[] = 'blocking_filter ' . $row['label'] . ': ' . $row['count'];
        }
        $buckets = is_array($report['zero_buckets'] ?? null) ? $report['zero_buckets'] : [];
        $lines[] = 'capacity blocked: ' . (int) ($buckets['capacity_blocked'] ?? 0);
        $lines[] = 'brand blocked: ' . (int) ($buckets['brand_blocked'] ?? 0);
        $lines[] = 'readability blocked: ' . (int) ($buckets['readability_blocked'] ?? 0);
        $lines[] = 'taxonomy or data blocking_filter: ' . (int) ($buckets['taxonomy_or_data'] ?? 0);
        $lines[] = 'unclassified zero results: ' . (int) ($buckets['unclassified'] ?? 0);
        $lines[] = 'Brand names and numeric capacity/readability values are not stored on events.';
        foreach (is_array($report['handoff_reasons'] ?? null) ? $report['handoff_reasons'] : [] as $reason => $count) {
            $lines[] = 'handoff_reason ' . $reason . ': ' . (int) $count;
        }
        $lines[] = 'Handoff categories: ' . self::pairs($report['handoff_by_category'] ?? []);
        $lines[] = 'Handoff subcategories: ' . self::pairs($report['handoff_by_subcategory'] ?? []);
        foreach (['repeated_question', 'user_correction', 'wrong_category_suspected', 'evidence_missing', 'unresolved_constraint'] as $key) {
            $ids = is_array($report['quality_examples'][$key] ?? null) ? $report['quality_examples'][$key] : [];
            $lines[] = 'examples ' . $key . ': ' . ($ids === [] ? '(none)' : implode(', ', $ids));
        }
        $lines[] = 'New phrase candidates:';
        $phrases = is_array($report['phrase_candidates'] ?? null) ? $report['phrase_candidates'] : [];
        if ($phrases === []) {
            $lines[] = '(none)';
        }
        foreach ($phrases as $phrase) {
            $lines[] = '- ' . $phrase['phrase'] . ': ' . $phrase['count'];
        }
        $lines[] = 'Potential catalog gaps:';
        $gaps = is_array($report['catalog_gaps'] ?? null) ? $report['catalog_gaps'] : [];
        if ($gaps === []) {
            $lines[] = '(none supported by the current logs)';
        }
        foreach ($gaps as $gap) {
            $lines[] = '- ' . $gap['subcategory'] . ': ' . $gap['requests'] . ' requests, ' . $gap['zero_results'] . ' zero results';
        }
        $lines[] = 'Potential content/spec gaps:';
        $evidence = is_array($report['evidence_by_category'] ?? null) ? $report['evidence_by_category'] : [];
        $fields = is_array($report['repeated_fields'] ?? null) ? $report['repeated_fields'] : [];
        if ($evidence === [] && $fields === []) {
            $lines[] = '(none supported by the current logs)';
        }
        foreach ($evidence as $row) {
            $lines[] = '- evidence_missing category ' . $row['label'] . ': ' . $row['count'];
        }
        foreach ($fields as $row) {
            $lines[] = '- repeated pending field ' . $row['label'] . ': ' . $row['count'];
        }
        $lines[] = 'Version comparison:';
        $comparison = is_array($report['version_comparison'] ?? null) ? $report['version_comparison'] : [];
        if ($comparison === []) {
            $lines[] = '(none)';
        }
        foreach ($comparison as $row) {
            $lines[] = $row['version'];
            $lines[] = 'turns: ' . $row['turns'];
            $lines[] = 'zero_result: ' . $row['zero_result'];
            $lines[] = 'repeated_question: ' . $row['repeated_question'];
            $lines[] = 'handoff: ' . $row['handoff'];
            if ((string) ($row['sample'] ?? '') !== '') {
                $lines[] = (string) $row['sample'];
            }
        }
        foreach (is_array($report['notes'] ?? null) ? $report['notes'] : [] as $note) {
            $lines[] = $note['kind'] . ': ' . $note['text'];
        }
        $lines[] = 'This report does not change prompts, profiles, catalog, RAG, or tests.';

        return self::scrub(implode("\n", $lines) . "\n");
    }

    /**
     * @param array<string, mixed> $report
     */
    public static function renderMarkdown(array $report): string
    {
        $text = self::renderText($report);
        $body = "# Conversation intelligence report\n\n```\n" . $text . "```\n";

        return self::scrub($body);
    }

    /**
     * @param array<string, int> $counts
     * @return list<array{label:string,count:int}>
     */
    private static function ranked(array $counts): array
    {
        arsort($counts);
        $out = [];
        foreach ($counts as $label => $count) {
            $out[] = ['label' => (string) $label, 'count' => (int) $count];
        }

        return array_slice($out, 0, 15);
    }

    /**
     * @param list<array{label:string,count:int}>|array<string, mixed> $rows
     */
    private static function pairs(array $rows): string
    {
        if ($rows === []) {
            return '(none)';
        }
        $parts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parts[] = $row['label'] . ': ' . $row['count'];
        }

        return $parts === [] ? '(none)' : implode(', ', $parts);
    }

    /**
     * @return array{count:int,total:int,text:string}
     */
    private static function share(int $count, int $total): array
    {
        if ($total === 0) {
            $text = 'n/a';
        } else {
            $text = $count . '/' . $total . ' (' . number_format(100 * $count / $total, 1, '.', '') . '%)';
        }

        return ['count' => $count, 'total' => $total, 'text' => $text];
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function shareText(array $share): string
    {
        return (string) ($share['text'] ?? 'n/a');
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private static function signals(array $event): array
    {
        $raw = $event['signals'] ?? '';
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $signals
     */
    private static function flag(array $signals, string $key): bool
    {
        $value = $signals[$key] ?? false;

        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * @param array<string, list<string>> $examples
     */
    private static function pushExample(array &$examples, string $key, string $id): void
    {
        if ($id === '' || !isset($examples[$key]) || count($examples[$key]) >= 5 || in_array($id, $examples[$key], true)) {
            return;
        }
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            return;
        }
        $examples[$key][] = $id;
    }

    private static function zeroBucket(string $blocking): string
    {
        return match ($blocking) {
            'capacity' => 'capacity_blocked',
            'brand' => 'brand_blocked',
            'readability' => 'readability_blocked',
            'taxonomy', 'category', 'subcategory' => 'taxonomy_or_data',
            default => 'unclassified',
        };
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array{phrase:string,count:int}>
     */
    private static function phrases(array $events): array
    {
        $counts = [];
        $surface = [];
        $generic = [];
        foreach (self::GENERIC_TOKENS as $token) {
            $generic[AssistantV2Catalog::fold($token)] = true;
        }
        $stop = [];
        foreach (self::STOPWORDS as $token) {
            $stop[AssistantV2Catalog::fold($token)] = true;
        }
        foreach ($events as $event) {
            $text = AssistantV2ConversationRedactor::text((string) ($event['user_message_redacted'] ?? ''));
            $text = preg_replace('/\[(PHONE|EMAIL|ID|ORDER|ADDRESS|TOKEN)\]/u', ' ', $text) ?? $text;
            $fold = AssistantV2Catalog::fold($text);
            $rawTokens = preg_split('/[^\p{L}\p{N}]+/u', $fold) ?: [];
            $tokens = [];
            foreach ($rawTokens as $token) {
                if ($token === '' || isset($stop[$token]) || strlen($token) < 3 || ctype_digit($token)) {
                    continue;
                }
                if (preg_match('/phone|email|token|address/u', $token) === 1) {
                    continue;
                }
                $tokens[] = $token;
            }
            $seen = [];
            $max = count($tokens);
            for ($size = 2; $size <= 3; $size++) {
                for ($i = 0; $i <= $max - $size; $i++) {
                    $slice = array_slice($tokens, $i, $size);
                    $specific = false;
                    foreach ($slice as $token) {
                        if (!isset($generic[$token])) {
                            $specific = true;
                            break;
                        }
                    }
                    if (!$specific) {
                        continue;
                    }
                    $phrase = implode(' ', $slice);
                    if (isset($seen[$phrase])) {
                        continue;
                    }
                    $seen[$phrase] = true;
                    $counts[$phrase] = ($counts[$phrase] ?? 0) + 1;
                    if (!isset($surface[$phrase])) {
                        $surface[$phrase] = $phrase;
                    }
                }
            }
        }
        $out = [];
        foreach ($counts as $phrase => $count) {
            if ($count < 2) {
                continue;
            }
            $out[] = ['phrase' => $surface[$phrase], 'count' => $count];
        }
        usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['phrase'], $b['phrase']));

        return array_slice($out, 0, 20);
    }

    /**
     * @param array<string, int> $signals
     * @return list<array{kind:string,text:string}>
     */
    private static function notes(int $turns, int $zero, int $repeated, int $handoff): array
    {
        if ($turns === 0) {
            return [];
        }
        if ($turns < self::SAMPLE_MIN) {
            return [[
                'kind' => 'OBSERVATION',
                'text' => 'Sample is below ' . self::SAMPLE_MIN . ' turns. No issue statements.',
            ]];
        }
        $notes = [];
        if ($zero / $turns >= 0.25) {
            $notes[] = [
                'kind' => 'OBSERVATION',
                'text' => 'Zero-result turns are ' . $zero . ' of ' . $turns . '.',
            ];
            $notes[] = [
                'kind' => 'POSSIBLE ISSUE',
                'text' => 'Searches in this window often return no products. The blocking_filter breakdown is the evidence.',
            ];
            $notes[] = [
                'kind' => 'SUGGESTED REVIEW',
                'text' => 'A person can review the blocking_filter counts before any catalog change.',
            ];
        }
        if ($repeated / $turns >= 0.15) {
            $notes[] = [
                'kind' => 'OBSERVATION',
                'text' => 'repeated_question turns are ' . $repeated . ' of ' . $turns . '.',
            ];
            $notes[] = [
                'kind' => 'POSSIBLE ISSUE',
                'text' => 'The same pending field is being asked again on later turns.',
            ];
            $notes[] = [
                'kind' => 'SUGGESTED REVIEW',
                'text' => 'Review the listed conversation ids. This report does not change the question plan.',
            ];
        }
        if ($handoff / $turns >= 0.25) {
            $notes[] = [
                'kind' => 'OBSERVATION',
                'text' => 'Handoff turns are ' . $handoff . ' of ' . $turns . '.',
            ];
            $notes[] = [
                'kind' => 'POSSIBLE ISSUE',
                'text' => 'Many turns leave the catalog flow with a handoff reason.',
            ];
            $notes[] = [
                'kind' => 'SUGGESTED REVIEW',
                'text' => 'Review handoff_reason counts. This report does not change handoff rules.',
            ];
        }

        return $notes;
    }

    private static function scrub(string $text): string
    {
        $text = preg_replace(
            '/(?<![\w.+-])[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}(?![\w.-])/iu',
            '',
            $text
        ) ?? $text;
        $text = preg_replace(
            '/(?<!\d)(?:\+90|0090|0)[\s.\-]?5\d{2}[\s.\-]?\d{3}[\s.\-]?\d{2}[\s.\-]?\d{2}(?!\d)/u',
            '',
            $text
        ) ?? $text;
        $text = preg_replace('/\[(PHONE|EMAIL|ID|ORDER|ADDRESS|TOKEN)\]/u', '', $text) ?? $text;

        return $text;
    }
}
