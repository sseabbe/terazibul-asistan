<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Kalıcı danışman konuşma durumu. Bellek her tur birleştirilir; sıfırlanmaz.
 */
final class AssistantV2AdvisorState
{
    /**
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    public static function normalize(array $incoming): array
    {
        $advisor = is_array($incoming['advisor'] ?? null) ? $incoming['advisor'] : [];
        $filters = is_array($incoming['filters'] ?? null)
            ? AssistantV2Filters::normalizeState($incoming['filters'])
            : AssistantV2Filters::blank();
        $ranking = is_array($incoming['ranking'] ?? null)
            ? $incoming['ranking']
            : AssistantV2Filters::blankRanking();

        $facts = is_array($advisor['facts'] ?? null) ? $advisor['facts'] : [];
        $cleanFacts = [];
        foreach ($facts as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '') {
                    $cleanFacts[$key] = $value;
                }
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $cleanFacts[$key] = $value;
            }
        }

        return [
            'conversation_id'       => self::conversationId($incoming['conversation_id'] ?? ''),
            'filters'               => $filters,
            'ranking'               => [
                'field'     => is_string($ranking['field'] ?? null) ? $ranking['field'] : null,
                'direction' => is_string($ranking['direction'] ?? null) ? $ranking['direction'] : null,
            ],
            'offset'                => max(0, (int) ($incoming['offset'] ?? 0)),
            'focus_ids'             => self::intList($incoming['focus_ids'] ?? []),
            'products_listing_url'  => trim((string) ($incoming['products_listing_url'] ?? '')),
            'search_total'          => max(0, (int) ($incoming['search_total'] ?? 0)),
            'last_search_ids'       => self::intList($incoming['last_search_ids'] ?? []),
            'advisor'               => [
                'intent'                 => trim((string) ($advisor['intent'] ?? '')),
                'category'               => self::slugOrNull($advisor['category'] ?? null),
                'subcategory'            => self::slugOrNull($advisor['subcategory'] ?? null),
                'facts'                  => $cleanFacts,
                'missing_information'    => self::stringList($advisor['missing_information'] ?? []),
                'ready_for_products'     => ($advisor['ready_for_products'] ?? false) === true,
                'readiness_reason'       => trim((string) ($advisor['readiness_reason'] ?? '')),
                'handoff_reason'         => trim((string) ($advisor['handoff_reason'] ?? '')),
                'turns'                  => max(0, (int) ($advisor['turns'] ?? 0)),
                'answered_fields'        => self::stringList($advisor['answered_fields'] ?? []),
                'pending_question_field' => self::pendingField($advisor['pending_question_field'] ?? ''),
                'next_question'          => trim((string) ($advisor['next_question'] ?? '')),
                'offered_options'        => self::offeredOptions($advisor['offered_options'] ?? null),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    public static function mergeAnalysis(array $state, array $analysis, string $message): array
    {
        $state = self::normalize($state);
        $state['advisor']['turns'] = (int) $state['advisor']['turns'] + 1;

        $intent = trim((string) ($analysis['intent'] ?? ''));
        if ($intent !== '') {
            $state['advisor']['intent'] = $intent;
        }
        $cat = self::slugOrNull($analysis['category'] ?? null);
        if ($cat !== null) {
            $state['advisor']['category'] = $cat;
        }
        $sub = self::slugOrNull($analysis['subcategory'] ?? null);
        if ($sub !== null) {
            $state['advisor']['subcategory'] = $sub;
        }

        $newFacts = is_array($analysis['new_facts'] ?? null) ? $analysis['new_facts'] : [];
        foreach ($newFacts as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
                $state['advisor']['facts'][$key] = $value;
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $state['advisor']['facts'][$key] = $value;
            }
        }

        $missing = is_array($analysis['missing_information'] ?? null) ? $analysis['missing_information'] : [];
        if ($missing !== []) {
            $state['advisor']['missing_information'] = self::stringList($missing);
        }

        $relation = AssistantV2Filters::relation((string) ($analysis['relation'] ?? 'continue_search'));
        $filterPlan = [
            'relation'            => $relation,
            'add_constraints'     => is_array($analysis['filter_updates'] ?? null) ? $analysis['filter_updates'] : [],
            'remove_constraints'  => is_array($analysis['remove_constraints'] ?? null) ? $analysis['remove_constraints'] : [],
        ];
        if ($filterPlan['add_constraints'] === [] && is_array($analysis['filters'] ?? null)) {
            $filterPlan['add_constraints'] = $analysis['filters'];
        }
        $merged = AssistantV2Filters::merge(
            ['filters' => $state['filters'], 'ranking' => $state['ranking']],
            $filterPlan
        );
        $state['filters'] = $merged['filters'];
        $state['ranking'] = $merged['ranking'];
        if ($merged['replaced']) {
            $state['offset'] = 0;
            $state['last_search_ids'] = [];
            $state['search_total'] = 0;
            $state['products_listing_url'] = '';
        }

        $state = self::applyCatalogHints($state, $message);
        $state['filters'] = AssistantV2Filters::keepStatedMeasures($state['filters'], $message);
        $operator = AssistantV2Filters::statedCapacityOperator($message);
        if ($operator !== null && is_numeric($state['filters']['capacity']['value_g'] ?? null) && ($state['filters']['capacity']['unresolved'] ?? false) !== true) {
            $state['filters']['capacity']['operator'] = $operator;
        }
        $offered = is_array($state['advisor']['offered_options']['capacity'] ?? null)
            ? $state['advisor']['offered_options']['capacity']
            : [];
        $state['filters'] = AssistantV2Filters::applyCapacityChoice($state['filters'], $message, $offered);
        $state['filters'] = AssistantV2Filters::applyStatedFacets($state['filters'], $message);
        $state['advisor']['offered_options'] = ['capacity' => []];

        $decision = self::readinessDecision($state, $analysis, $message);
        $state['advisor']['ready_for_products'] = $decision['ready'];
        $state['advisor']['readiness_reason'] = $decision['reason'];

        return $state;
    }

    /**
     * LLM ready sinyali tek başına yetmez. Yeterli ve doğrulanmış state, LLM false dese de durur.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $analysis
     * @return array{ready:bool,reason:string}
     */
    public static function readinessDecision(array $state, array $analysis, string $message = ''): array
    {
        $llm = ($analysis['ready_for_product_results'] ?? false) === true
            || ($analysis['ready_for_products'] ?? false) === true;
        $block = self::readinessBlock($state, $message);
        if ($block !== null) {
            return ['ready' => false, 'reason' => $block];
        }
        if ($llm || self::hasConfirmedMeasure($state)) {
            return ['ready' => true, 'reason' => $llm ? 'confirmed' : 'measures_confirmed'];
        }

        return ['ready' => false, 'reason' => 'needs_clarification'];
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function readinessBlock(array $state, string $message = ''): ?string
    {
        $intent = (string) ($state['advisor']['intent'] ?? '');
        if (!self::intentAllowsProducts($intent)) {
            return 'intent_blocked';
        }
        if (!self::hasResolvedGroup($state)) {
            return 'no_category';
        }
        if (self::hasUnresolvedMeasure($state)) {
            return 'unresolved_measure';
        }
        if (self::unverifiedFeature($message)) {
            return 'unverified_requirement';
        }
        if (self::labMeasureIncomplete($state, $message)) {
            return 'needs_clarification';
        }
        $intent = AssistantV2Catalog::fold((string) ($state['advisor']['intent'] ?? ''));
        if (!in_array($intent, ['price', 'fiyat'], true) && AssistantV2QuestionPlan::next($message, $state) !== '') {
            return 'needs_clarification';
        }
        if (!self::hasMeaningfulFilter($state)) {
            return 'no_confirmed_filter';
        }
        if (!AssistantV2Filters::hasConstraint($state['filters'] ?? [])) {
            return 'search_not_runnable';
        }

        return null;
    }

    private static function unverifiedFeature(string $message): bool
    {
        $fold = AssistantV2Catalog::fold($message);

        return $fold !== '' && preg_match('/bluetooth|wifi|kablosuz/u', $fold) === 1;
    }

    /**
     * Analitik / hassas kullanımda kapasite ve okunabilirlik birlikte gerekir.
     *
     * @param array<string, mixed> $state
     */
    private static function labMeasureIncomplete(array $state, string $message = ''): bool
    {
        $stated = AssistantV2Catalog::fold((string) (AssistantV2Catalog::statedLabScaleType($message) ?? ''));
        $type = AssistantV2Catalog::fold((string) ($state['filters']['type_token'] ?? $state['advisor']['facts']['type_token'] ?? ''));
        $sub = AssistantV2Catalog::fold((string) ($state['filters']['subcategory'] ?? $state['advisor']['subcategory'] ?? ''));
        $blob = trim($stated . ' ' . $type . ' ' . $sub);
        if ($blob === '' || preg_match('/analitik|hassas|mikro/u', $blob) !== 1) {
            return false;
        }
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        $precision = is_array($filters['precision'] ?? null) ? $filters['precision'] : [];
        $capacity = is_array($filters['capacity'] ?? null) ? $filters['capacity'] : [];
        $hasPrecision = is_numeric($precision['value'] ?? null) && ($precision['unresolved'] ?? false) !== true;
        $hasCapacity = is_numeric($capacity['value_g'] ?? null) && ($capacity['unresolved'] ?? false) !== true;

        return !($hasPrecision && $hasCapacity);
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function hasResolvedGroup(array $state): bool
    {
        foreach (['subcategory', 'category'] as $key) {
            foreach ([$state['filters'][$key] ?? null, $state['advisor'][$key] ?? null] as $slug) {
                if (is_string($slug) && $slug !== '' && AssistantV2Catalog::node($slug) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function hasUnresolvedMeasure(array $state): bool
    {
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        $precision = is_array($filters['precision'] ?? null) ? $filters['precision'] : [];
        $capacity = is_array($filters['capacity'] ?? null) ? $filters['capacity'] : [];

        return ($precision['unresolved'] ?? false) === true || ($capacity['unresolved'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function hasConfirmedMeasure(array $state): bool
    {
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        $precision = is_array($filters['precision'] ?? null) ? $filters['precision'] : [];
        $capacity = is_array($filters['capacity'] ?? null) ? $filters['capacity'] : [];

        return (is_numeric($precision['value'] ?? null) && ($precision['unresolved'] ?? false) !== true)
            || (is_numeric($capacity['value_g'] ?? null) && ($capacity['unresolved'] ?? false) !== true);
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function hasMeaningfulFilter(array $state): bool
    {
        if (self::hasConfirmedMeasure($state)) {
            return true;
        }
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        if (is_array($filters['brands'] ?? null) && $filters['brands'] !== []) {
            return true;
        }
        $sub = $filters['subcategory'] ?? $state['advisor']['subcategory'] ?? null;
        if (!is_string($sub) || $sub === '' || AssistantV2Catalog::node($sub) === null) {
            return false;
        }

        return !AssistantV2Catalog::isBroad($sub);
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function summaryForLlm(array $state): string
    {
        $state = self::normalize($state);
        $advisor = $state['advisor'];
        $lines = [
            'intent: ' . ($advisor['intent'] !== '' ? $advisor['intent'] : '(belirsiz)'),
            'category: ' . ($advisor['category'] ?? '(yok)'),
            'subcategory: ' . ($advisor['subcategory'] ?? '(yok)'),
            'facts: ' . json_encode($advisor['facts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'missing: ' . implode(', ', $advisor['missing_information']),
            'ready_for_products: ' . ($advisor['ready_for_products'] ? 'yes' : 'no'),
            'search_total: ' . (string) $state['search_total'],
        ];
        if (AssistantV2Filters::hasConstraint($state['filters'])) {
            $lines[] = 'active_filters: ' . json_encode($state['filters'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    public static function profileHints(string $categorySlug, string $subcategorySlug = ''): array
    {
        return AssistantV2CategoryProfiles::hintLines($categorySlug, $subcategorySlug);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function applyCatalogHints(array $state, string $message): array
    {
        $classified = ($state['advisor']['category'] ?? null) !== null
            || self::filledSlug($state['filters']['category'] ?? null);

        if (!$classified) {
            $usage = AssistantV2Catalog::statedUsage($message);
            $labType = AssistantV2Catalog::statedLabScaleType($message);
            if ($labType !== null && ($usage === null || $usage === 'laboratuvar')) {
                $lab = AssistantV2Catalog::labScaleFilters($message);
                $state['advisor']['category'] = 'laboratuvar';
                $state['filters']['category'] = 'laboratuvar';
                if (is_string($lab['subcategory'] ?? null) && $lab['subcategory'] !== '') {
                    $state['filters']['subcategory'] = $lab['subcategory'];
                    $state['advisor']['subcategory'] = $lab['subcategory'];
                }
                if (is_string($lab['type_token'] ?? null) && $lab['type_token'] !== '') {
                    $state['filters']['type_token'] = $lab['type_token'];
                    $state['advisor']['facts']['type_token'] = $lab['type_token'];
                }
            } elseif ($usage !== null) {
                $state['advisor']['category'] = $usage;
                $state['filters']['category'] = $usage;
            }
        }

        $category = is_string($state['advisor']['category'] ?? null) ? $state['advisor']['category'] : '';
        if ($category === '' && self::filledSlug($state['filters']['category'] ?? null)) {
            $category = (string) $state['filters']['category'];
        }
        $subcategory = is_string($state['advisor']['subcategory'] ?? null) ? $state['advisor']['subcategory'] : '';
        $labType = AssistantV2Catalog::statedLabScaleType($message);
        if (
            $labType !== null
            && ($category === '' || $category === 'laboratuvar')
            && $subcategory === ''
            && !self::filledSlug($state['filters']['type_token'] ?? null)
        ) {
            $lab = AssistantV2Catalog::labScaleFilters($message);
            $token = is_string($lab['type_token'] ?? null) ? $lab['type_token'] : '';
            if ($token !== '') {
                $state['filters']['type_token'] = $token;
                $state['advisor']['facts']['type_token'] = $token;
            }
        }

        $stated = AssistantV2Catalog::statedLabScaleType($message);
        $subSlug = is_string($state['filters']['subcategory'] ?? null) ? $state['filters']['subcategory'] : '';
        if ($stated === 'analitik' && str_contains(AssistantV2Catalog::fold($subSlug), 'yari')) {
            $children = AssistantV2Catalog::childrenForType('laboratuvar', 'analitik');
            if (count($children) === 1) {
                $state['filters']['subcategory'] = $children[0];
                $state['advisor']['subcategory'] = $children[0];
            }
        }

        if (AssistantV2Catalog::barePrecisionScale($message)) {
            $state['advisor']['facts']['requested_scale'] = 'hassas';
            $state['advisor']['category'] = null;
            $state['advisor']['subcategory'] = null;
            $state['filters']['category'] = null;
            $state['filters']['subcategory'] = null;
            if (($state['filters']['type_token'] ?? '') === 'hassas') {
                $state['filters']['type_token'] = null;
            }
        }

        $scale = (string) ($state['advisor']['facts']['requested_scale'] ?? '');
        $categoryNow = is_string($state['advisor']['category'] ?? null) ? $state['advisor']['category'] : '';
        if ($categoryNow === '' && self::filledSlug($state['filters']['category'] ?? null)) {
            $categoryNow = (string) $state['filters']['category'];
        }
        if (
            $scale === 'hassas'
            && $categoryNow === 'laboratuvar'
            && !self::filledSlug($state['filters']['type_token'] ?? null)
        ) {
            $state['filters']['type_token'] = 'hassas';
            $state['advisor']['facts']['type_token'] = 'hassas';
        }

        $state = AssistantV2CategoryProfiles::apply($state, $message);
        $state = self::dropUnnamedEmptySubcategory($state, $message);

        return self::rememberBrand($state, $message);
    }

    /**
     * Ürünü olmayan bir alt grup, kullanıcı o grubun adını söylemediyse ve profil de seçmediyse üst kategoride kalır.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function dropUnnamedEmptySubcategory(array $state, string $message): array
    {
        $sub = is_string($state['filters']['subcategory'] ?? null) ? $state['filters']['subcategory'] : '';
        if ($sub === '') {
            return $state;
        }
        $node = AssistantV2Catalog::node($sub);
        if ($node === null || (int) ($node['products'] ?? 0) > 0) {
            return $state;
        }
        $decision = AssistantV2CategoryProfiles::resolve($message, $state);
        if (is_array($decision) && ($decision['subcategory'] ?? '') === $sub) {
            return $state;
        }
        if (AssistantV2Catalog::messageNamesGroup($message, $node)) {
            return $state;
        }
        $state['filters']['subcategory'] = null;
        $state['advisor']['subcategory'] = null;

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function rememberBrand(array $state, string $message): array
    {
        $brand = AssistantV2Catalog::mentionedBrand($message);
        if ($brand !== null) {
            $brands = is_array($state['filters']['brands'] ?? null) ? $state['filters']['brands'] : [];
            if (!in_array($brand, $brands, true)) {
                $brands[] = $brand;
                $state['filters']['brands'] = $brands;
            }
        }

        return $state;
    }

    private static function filledSlug(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function intentAllowsProducts(string $intent): bool
    {
        $intent = AssistantV2Catalog::fold($intent);

        return in_array($intent, [
            'product_discovery',
            'product',
            'urun',
            'compare',
            'price',
            'fiyat',
        ], true);
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private static function intList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $id = (int) $id;
                if ($id > 0) {
                    $out[] = $id;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '' && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return array_slice($out, 0, 12);
    }

    /**
     * @return array{capacity:list<float>}
     */
    private static function offeredOptions(mixed $raw): array
    {
        $capacity = [];
        $values = is_array($raw) ? ($raw['capacity'] ?? []) : [];
        if (is_array($values)) {
            foreach ($values as $value) {
                if (!is_numeric($value) || (float) $value < 1) {
                    continue;
                }
                $capacity[] = (float) $value;
            }
        }
        $capacity = array_values(array_unique($capacity, SORT_REGULAR));
        sort($capacity);

        return ['capacity' => array_slice($capacity, 0, 8)];
    }

    private static function conversationId(mixed $raw): string
    {
        $value = is_string($raw) ? strtolower(trim($raw)) : '';

        return preg_match('/^[a-f0-9]{32}$/', $value) === 1 ? $value : '';
    }

    private static function pendingField(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $field = trim($raw);
        if ($field === '' || preg_match('/^[a-z_]{2,40}$/', $field) !== 1) {
            return '';
        }

        return $field;
    }

    private static function slugOrNull(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $slug = trim($raw);
        if ($slug === '' || preg_match('/^[a-z0-9-]{2,80}$/', $slug) !== 1) {
            return null;
        }

        return $slug;
    }
}
