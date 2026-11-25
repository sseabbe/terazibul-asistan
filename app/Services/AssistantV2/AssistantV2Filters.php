<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Filtre, faset, sıralama ve karşılaştırma. Kararlar sayısaldır, cümle üretmez.
 */
final class AssistantV2Filters
{
    public const PAGE = 5;

    /**
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return [
            'category'            => null,
            'subcategory'         => null,
            'brands'              => [],
            'precision'           => ['value' => null, 'unit' => null],
            'capacity'            => ['operator' => null, 'value_g' => null, 'min_g' => null, 'max_g' => null],
            'capacity_slug'       => null,
            'commercial_approval' => null,
            'calibration'         => null,
            'communication'       => null,
            'condition'           => null,
            'facets'              => [],
            'unsupported'         => [],
            'series'              => null,
            'type_token'          => null,
            'moisture_form'       => null,
            'moisture_use'        => null,
            'moisture_temp'       => null,
        ];
    }

    /**
     * @return array{field:?string,direction:?string}
     */
    public static function blankRanking(): array
    {
        return ['field' => null, 'direction' => null];
    }

    public static function relation(string $relation): string
    {
        return match ($relation) {
            'continue', 'continue_search' => 'continue_search',
            'modify_search' => 'modify_search',
            'widen', 'broaden_search' => 'broaden_search',
            'replace', 'new', 'new_search' => 'new_search',
            'product_followup' => 'product_followup',
            'non_product_question' => 'non_product_question',
            default => 'continue_search',
        };
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    public static function names(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $field) {
            if (!is_string($field)) {
                continue;
            }
            $field = trim($field);
            if ($field === '' || in_array($field, $out, true)) {
                continue;
            }
            $out[] = $field;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $plan
     * @return array{filters:array<string,mixed>,ranking:array{field:?string,direction:?string},replaced:bool}
     */
    public static function merge(array $previous, array $plan): array
    {
        $relation = self::relation((string) ($plan['relation'] ?? ''));
        $prevFilters = is_array($previous['filters'] ?? null) ? $previous['filters'] : self::blank();
        $prevRanking = is_array($previous['ranking'] ?? null) ? $previous['ranking'] : self::blankRanking();

        $rawAdd = is_array($plan['add_constraints'] ?? null) ? $plan['add_constraints'] : [];
        if ($rawAdd === [] && is_array($plan['hard'] ?? null)) {
            $rawAdd = $plan['hard'];
        }
        $add = self::criteria($rawAdd);
        $remove = array_values(array_unique(array_merge(
            self::names($plan['remove_constraints'] ?? $plan['drop'] ?? []),
            self::explicitClears($rawAdd)
        )));
        $replaced = $relation === 'new_search';
        $filters = self::normalizeState($prevFilters);
        if ($relation === 'new_search') {
            $filters = self::overlay(self::blank(), $add);
        } elseif ($relation === 'product_followup' || $relation === 'non_product_question') {
            $filters = self::normalizeState($prevFilters);
        } else {
            foreach ($remove as $field) {
                $filters = self::clear($filters, $field);
            }
            $filters = self::overlay($filters, $add);
        }

        $ranking = $replaced ? self::blankRanking() : [
            'field'     => self::rankField((string) ($prevRanking['field'] ?? '')),
            'direction' => self::rankDirection((string) ($prevRanking['direction'] ?? '')),
        ];
        $requested = self::rankingFromPlan($plan);
        if ($requested['field'] !== null) {
            $ranking = $requested;
        }
        if ($relation === 'new_search') {
            if ($requested['field'] === null) {
                $ranking = self::blankRanking();
            }
        }

        return [
            'filters'  => $filters,
            'ranking'  => $ranking,
            'replaced' => $replaced,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function search(array $filters, int $offset, int $limit): array
    {
        $filters = self::normalizeState($filters);
        $exact = [];
        $nearPool = [];
        foreach (AssistantV2Catalog::products() as $product) {
            if (!self::matchesCore($product, $filters)) {
                continue;
            }
            $verdict = self::capacityVerdict($product, $filters['capacity']);
            if ($verdict === 'reject') {
                continue;
            }
            if ($verdict === 'near') {
                $nearPool[] = $product;
                continue;
            }
            $exact[] = $product;
        }

        $quality = 'exact';
        $pool = $exact;
        $nearest = null;
        $operator = $filters['capacity']['operator'] ?? null;
        if ($pool === [] && $operator === 'approximately' && $nearPool !== []) {
            $target = (float) ($filters['capacity']['value_g'] ?? 0);
            $nearest = self::nearestCapacity($nearPool, $target);
            if ($nearest !== null) {
                foreach ($nearPool as $product) {
                    $grams = self::capacityGrams($product);
                    if ($grams !== null && abs($grams - $nearest) < 0.6) {
                        $pool[] = $product;
                    }
                }
                $quality = 'near';
            }
        }

        $pool = self::enforceHardCapacity($pool, $filters['capacity']);
        $operator = $filters['capacity']['operator'] ?? null;
        $load = $filters['capacity']['value_g'] ?? null;
        if (self::isMoistureGroup($filters)) {
            $pool = self::applyMoisture($pool, $filters);
            $pool = self::sortMoisture($pool, $filters);
        } elseif ($operator === 'min' && is_numeric($load)) {
            $pool = self::orderByLoadGroups($pool, $filters, (float) $load);
        } else {
            $pool = self::storefrontSort($pool, $filters);
        }
        $total = count($pool);
        $offset = max(0, $offset);
        $page = array_slice($pool, $offset, max(1, $limit));

        return [
            'all_ids'    => array_map(static fn (array $product): int => (int) $product['id'], $pool),
            'page'       => $page,
            'total'      => $total,
            'quality'    => $quality,
            'nearest_g'  => $nearest,
            'facets'     => self::facets($pool, self::subcategoryParent($filters)),
            'filters'    => $filters,
        ];
    }

    /**
     * Hangi kısıtın havuzu sıfırladığını sayar. Sonuç gevşetilmez.
     *
     * @param array<string, mixed> $filters
     * @return array{
     *   category_before:int,
     *   subcategory_after:int,
     *   brand_after:int,
     *   capacity_after:int,
     *   readability_after:int,
     *   blocking_filter:?string,
     *   reason:string,
     *   taxonomy_valid:bool
     * }
     */
    public static function diagnose(array $filters): array
    {
        $filters = self::normalizeState($filters);
        $category = is_string($filters['category'] ?? null) ? $filters['category'] : '';
        $subcategory = is_string($filters['subcategory'] ?? null) ? $filters['subcategory'] : '';
        $mappingFailed = ($subcategory !== '' && AssistantV2Catalog::node($subcategory) === null)
            || ($category !== '' && $subcategory === '' && AssistantV2Catalog::node($category) === null);

        $categoryOnly = $filters;
        $categoryOnly['subcategory'] = null;
        $categoryOnly['brands'] = [];
        $categoryOnly['precision'] = ['value' => null, 'unit' => null];
        $categoryOnly['capacity'] = self::blank()['capacity'];
        $categoryBefore = ($category !== '' || $subcategory !== '')
            ? self::countSurvivors($categoryOnly)
            : self::countSurvivors(self::blank());

        $throughSub = $categoryOnly;
        $throughSub['subcategory'] = $subcategory !== '' ? $subcategory : null;
        $throughSub['category'] = $category !== '' ? $category : $filters['category'];
        $subAfter = self::countSurvivors($throughSub);

        $throughBrand = $throughSub;
        $throughBrand['brands'] = $filters['brands'];
        $brandAfter = self::countSurvivors($throughBrand);

        $throughCapacity = $throughBrand;
        $throughCapacity['capacity'] = $filters['capacity'];
        $capacityAfter = self::countSurvivors($throughCapacity);

        $readabilityAfter = self::countSurvivors($filters);

        $brands = is_array($filters['brands'] ?? null) ? $filters['brands'] : [];
        $capacity = is_array($filters['capacity'] ?? null) ? $filters['capacity'] : [];
        $precision = is_array($filters['precision'] ?? null) ? $filters['precision'] : [];
        $capacityActive = is_string($capacity['operator'] ?? null) && $capacity['operator'] !== ''
            && (is_numeric($capacity['value_g'] ?? null) || is_numeric($capacity['min_g'] ?? null) || is_numeric($capacity['max_g'] ?? null));
        $readabilityActive = is_numeric($precision['value'] ?? null);

        $blocking = null;
        $reason = '';
        if ($mappingFailed) {
            $blocking = 'taxonomy';
            $reason = 'taxonomy_mapping';
        } else {
            $cursor = $categoryBefore;
            if ($subcategory !== '') {
                if ($subAfter === 0 && $cursor > 0) {
                    $blocking = 'subcategory';
                }
                $cursor = $subAfter;
            } elseif ($category !== '' && $categoryBefore === 0) {
                $blocking = 'category';
                $cursor = 0;
            }
            if ($blocking === null && $brands !== []) {
                if ($brandAfter === 0 && $cursor > 0) {
                    $blocking = 'brand';
                }
                $cursor = $brandAfter;
            }
            if ($blocking === null && $capacityActive) {
                if ($capacityAfter === 0 && $cursor > 0) {
                    $blocking = 'capacity';
                }
                $cursor = $capacityAfter;
            }
            if ($blocking === null && $readabilityActive && $readabilityAfter === 0 && $cursor > 0) {
                $blocking = 'readability';
            }
            $reason = $blocking ?? '';
            if ($blocking === 'subcategory' || $blocking === 'category') {
                $slug = $blocking === 'subcategory' ? $subcategory : $category;
                $node = AssistantV2Catalog::node($slug);
                if ($node !== null && (int) ($node['products'] ?? 0) === 0) {
                    $reason = 'catalog_empty';
                }
            }
        }

        return [
            'category_before'   => $categoryBefore,
            'subcategory_after' => $subcategory !== '' ? $subAfter : $categoryBefore,
            'brand_after'       => $brands !== [] ? $brandAfter : ($subcategory !== '' ? $subAfter : $categoryBefore),
            'capacity_after'    => $capacityActive ? $capacityAfter : ($brands !== [] ? $brandAfter : ($subcategory !== '' ? $subAfter : $categoryBefore)),
            'readability_after' => $readabilityActive ? $readabilityAfter : $capacityAfter,
            'blocking_filter'   => $blocking,
            'reason'            => $reason,
            'taxonomy_valid'    => !$mappingFailed,
        ];
    }

    /**
     * @param array<string, mixed> $diagnostic
     */
    public static function explainZero(array $diagnostic): string
    {
        return match ((string) ($diagnostic['reason'] ?? '')) {
            'taxonomy_mapping' => 'Bu ürün grubu katalog kaydıyla eşleşmiyor.',
            'catalog_empty'    => 'Bu grupta kayıtlı bir ürün bulamadım.',
            'capacity'         => 'Bu kapasiteyi karşılayan kayıtlı bir ürün bulamadım.',
            'readability'      => 'Bu okunabilirliği karşılayan kayıtlı bir ürün bulamadım.',
            'brand'            => 'Bu markada, verdiğiniz kriterlere uyan kayıtlı bir ürün bulamadım.',
            'category'         => 'Bu kategoride kayıtlı bir ürün bulamadım.',
            'subcategory'      => 'Bu grupta kayıtlı bir ürün bulamadım.',
            default            => 'Bu kriterlere uyan kayıtlı bir ürün bulamadım.',
        };
    }

    /**
     * @param array<string, mixed> $filters
     */
    private static function countSurvivors(array $filters): int
    {
        $filters = self::normalizeState($filters);
        $pool = [];
        foreach (AssistantV2Catalog::products() as $product) {
            if (!self::matchesCore($product, $filters)) {
                continue;
            }
            if (self::capacityVerdict($product, $filters['capacity']) === 'reject') {
                continue;
            }
            $pool[] = $product;
        }
        $pool = self::enforceHardCapacity($pool, $filters['capacity']);
        if (self::isMoistureGroup($filters)) {
            $pool = self::applyMoisture($pool, $filters);
        }

        return count($pool);
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return array<string, mixed>
     */
    public static function facets(array $products, string $parent = ''): array
    {
        $brands = [];
        $capacities = [];
        $precisions = [];
        $calibrations = [];
        $commercial = ['yes' => 0, 'no' => 0, 'unknown' => 0];
        foreach ($products as $product) {
            $brand = trim((string) ($product['brand'] ?? ''));
            if ($brand !== '') {
                $brands[$brand] = ($brands[$brand] ?? 0) + 1;
            }
            self::collectSpecMeasures($product, $capacities, $precisions);
            $calibration = trim((string) ($product['calibration'] ?? ''));
            if ($calibration !== '') {
                $calibrations[$calibration] = ($calibrations[$calibration] ?? 0) + 1;
            }
            if ($product['commercial'] === true) {
                $commercial['yes']++;
            } elseif ($product['commercial'] === false) {
                $commercial['no']++;
            } else {
                $commercial['unknown']++;
            }
        }
        uasort($capacities, static function (array $a, array $b): int {
            $order = ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0));

            return $order !== 0 ? $order : strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });

        return [
            'available_brands'               => $brands,
            'available_capacities'           => array_values($capacities),
            'available_precisions'           => array_values($precisions),
            'available_calibrations'         => $calibrations,
            'available_commercial_approval'  => $commercial,
            'available_usages'               => AssistantV2Catalog::usagesIn($products),
            'available_subcategories'        => self::subcategoriesIn($products, $parent),
        ];
    }

    /**
     * Alt kategori sorusu, seçili kökün bu kümedeki gerçek çocuklarıdır.
     *
     * @param list<array<string, mixed>> $products
     * @return list<array{slug:string,name:string,count:int}>
     */
    private static function subcategoriesIn(array $products, string $parent): array
    {
        if ($parent === '') {
            return [];
        }
        $out = [];
        foreach ($products as $product) {
            $seen = [];
            foreach (is_array($product['categories'] ?? null) ? $product['categories'] : [] as $cat) {
                if (!is_array($cat)) {
                    continue;
                }
                $child = self::directChild((string) ($cat['slug'] ?? ''), $parent);
                if ($child === null || isset($seen[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $node = AssistantV2Catalog::node($child);
                if ($node === null || $node['products'] < 1) {
                    continue;
                }
                $name = trim((string) $node['name']);
                if ($name === '' || AssistantV2Catalog::fold($name) === AssistantV2Catalog::fold($parent)) {
                    continue;
                }
                if (!isset($out[$child])) {
                    $out[$child] = ['slug' => $child, 'name' => $name, 'count' => 0];
                }
                $out[$child]['count']++;
            }
        }
        $rows = array_values($out);
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    private static function subcategoryParent(array $filters): string
    {
        $sub = is_string($filters['subcategory'] ?? null) ? $filters['subcategory'] : '';
        if ($sub !== '') {
            return '';
        }

        return is_string($filters['category'] ?? null) ? $filters['category'] : '';
    }

    private static function directChild(string $slug, string $parent): ?string
    {
        $guard = 0;
        $current = $slug;
        while ($current !== '' && $guard < 6) {
            $node = AssistantV2Catalog::node($current);
            if ($node === null) {
                return null;
            }
            if ($node['parent'] === $parent) {
                return $node['slug'];
            }
            if ($node['parent'] === '' || $node['parent'] === $current) {
                return null;
            }
            $current = $node['parent'];
            $guard++;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{products:list<array<string,mixed>>,winner:?array<string,mixed>,pool:int,priced:int,reason:?string,facets:array<string,mixed>,all_ids:list<int>}
     */
    public static function rank(array $filters, ?string $field, ?string $direction): array
    {
        $found = self::search($filters, 0, 500);
        $pool = [];
        $byId = AssistantV2Catalog::byId();
        foreach ($found['all_ids'] as $id) {
            if (isset($byId[$id])) {
                $pool[] = $byId[$id];
            }
        }
        $field = self::rankField((string) $field);
        $direction = self::rankDirection((string) $direction) ?? 'asc';
        if ($field === null) {
            return [
                'products' => [],
                'winner'   => null,
                'pool'     => count($pool),
                'priced'   => 0,
                'reason'   => 'no_ranking',
                'facets'   => $found['facets'],
                'all_ids'  => $found['all_ids'],
            ];
        }

        if ($field === 'price') {
            $priced = array_values(array_filter($pool, static fn (array $product): bool => is_numeric($product['price_try'] ?? null) && (float) $product['price_try'] > 0));
            if ($priced === []) {
                return [
                    'products' => $pool,
                    'winner'   => null,
                    'pool'     => count($pool),
                    'priced'   => 0,
                    'reason'   => count($pool) > 0 ? 'no_published_price' : 'empty',
                    'facets'   => $found['facets'],
                    'all_ids'  => $found['all_ids'],
                ];
            }
            usort($priced, static function (array $a, array $b) use ($direction): int {
                $cmp = ((float) $a['price_try']) <=> ((float) $b['price_try']);

                return $direction === 'desc' ? -$cmp : $cmp;
            });

            return [
                'products' => $priced,
                'winner'   => $priced[0],
                'pool'     => count($pool),
                'priced'   => count($priced),
                'reason'   => null,
                'facets'   => $found['facets'],
                'all_ids'  => $found['all_ids'],
            ];
        }

        $ranked = array_values(array_filter($pool, static function (array $product) use ($field): bool {
            if ($field === 'precision') {
                return is_numeric($product['precision_grams'] ?? null) && ($product['precision_unit'] ?? null) === 'g';
            }

            return self::capacityGrams($product) !== null;
        }));
        if ($ranked === []) {
            return [
                'products' => $pool,
                'winner'   => null,
                'pool'     => count($pool),
                'priced'   => 0,
                'reason'   => count($pool) > 0 ? 'missing_measure' : 'empty',
                'facets'   => $found['facets'],
                'all_ids'  => $found['all_ids'],
            ];
        }
        usort($ranked, static function (array $a, array $b) use ($field, $direction): int {
            $av = $field === 'precision' ? (float) $a['precision_grams'] : (float) self::capacityGrams($a);
            $bv = $field === 'precision' ? (float) $b['precision_grams'] : (float) self::capacityGrams($b);
            $cmp = $av <=> $bv;

            return $direction === 'desc' ? -$cmp : $cmp;
        });

        return [
            'products' => $ranked,
            'winner'   => $ranked[0],
            'pool'     => count($pool),
            'priced'   => 0,
            'reason'   => null,
            'facets'   => $found['facets'],
            'all_ids'  => $found['all_ids'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return array{shared:list<array{label:string,value:string}>,different:list<array{label:string,values:array<string,string>}>}
     */
    public static function compare(array $products): array
    {
        $rows = [];
        foreach ($products as $product) {
            $id = (string) (int) $product['id'];
            $pairs = [
                'Marka'        => (string) ($product['brand'] ?? ''),
                'Kapasite'     => (string) ($product['capacity_label'] ?? ''),
                'Hassasiyet'   => (string) ($product['precision_label'] ?? ''),
                'Fiyat'        => (string) ($product['price_label'] ?? ''),
                'Kalibrasyon'  => (string) ($product['calibration'] ?? ''),
                'Ticari onay'  => $product['commercial'] === true ? 'Var' : ($product['commercial'] === false ? 'Yok' : ''),
                'Stok'         => array_key_exists('in_stock', $product) ? (((bool) $product['in_stock']) ? 'Stokta' : 'Stok bilgisi yok') : '',
                'Durum'        => (string) ($product['condition'] ?? ''),
            ];
            foreach (self::answerSpecs($product, 12) as $spec) {
                $label = trim((string) ($spec['label'] ?? ''));
                $value = trim((string) ($spec['value'] ?? ''));
                if ($label !== '' && $value !== '') {
                    $pairs[$label] = $value;
                }
            }
            foreach ($pairs as $label => $value) {
                if ($value === '') {
                    continue;
                }
                $rows[$label][$id] = $value;
            }
        }

        $shared = [];
        $different = [];
        $ids = array_map(static fn (array $product): string => (string) (int) $product['id'], $products);
        foreach ($rows as $label => $values) {
            if (count($values) < count($ids)) {
                $different[] = ['label' => $label, 'values' => $values];
                continue;
            }
            $folded = array_map(static fn (string $value): string => AssistantV2Catalog::fold($value), $values);
            if (count(array_unique($folded)) === 1) {
                $shared[] = ['label' => $label, 'value' => (string) reset($values)];
            } else {
                $different[] = ['label' => $label, 'values' => $values];
            }
        }

        return [
            'shared'    => array_slice($shared, 0, 12),
            'different' => array_slice($different, 0, 12),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function hasConstraint(array $filters): bool
    {
        $filters = self::normalizeState($filters);

        return self::activeGroup($filters) !== ''
            || self::hasMeasure($filters)
            || $filters['brands'] !== []
            || (is_string($filters['series'] ?? null) && $filters['series'] !== '')
            || (is_string($filters['type_token'] ?? null) && $filters['type_token'] !== '')
            || is_bool($filters['commercial_approval'])
            || (is_string($filters['calibration']) && $filters['calibration'] !== '')
            || (is_string($filters['communication']) && $filters['communication'] !== '')
            || in_array($filters['condition'] ?? null, ['new', 'renewed'], true)
            || $filters['facets'] !== []
            || $filters['unsupported'] !== [];
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string> $asked
     */
    public static function specificEnough(array $filters, array $asked = []): bool
    {
        return self::nextQuestion($filters, $asked) === null;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string> $asked
     */
    public static function nextQuestion(array $filters, array $asked): ?string
    {
        $filters = self::normalizeState($filters);
        if (!self::hasConstraint($filters)) {
            return null;
        }
        [$pool, $found] = self::poolForFilters($filters, 500);
        if ($pool === []) {
            return null;
        }
        $facets = $found['facets'];
        $group = self::activeGroup($filters);
        $skipMeasures = AssistantV2Catalog::skipsMeasures($pool);
        $singleProduct = count($pool) < 2;
        $capacityChosen = self::criterionSet($filters, 'capacity');
        foreach (['subcategory', 'capacity', 'precision'] as $key) {
            if ($skipMeasures && ($key === 'precision' || $key === 'capacity')) {
                continue;
            }
            if ($singleProduct && $key === 'capacity') {
                continue;
            }
            if ($singleProduct && $key === 'precision' && !$capacityChosen) {
                continue;
            }
            if (in_array($key, $asked, true) || self::criterionSet($filters, $key)) {
                continue;
            }
            if ($key === 'precision') {
                if (!$capacityChosen) {
                    continue;
                }
                if (count($facets['available_precisions'] ?? []) < 1) {
                    continue;
                }

                return 'precision';
            }
            if (
                $key === 'subcategory'
                && !self::criterionSet($filters, 'subcategory')
                && is_string($filters['type_token'] ?? null)
                && ($filters['type_token'] ?? '') === 'mikro'
            ) {
                $subs = is_array($facets['available_subcategories'] ?? null) ? $facets['available_subcategories'] : [];
                if ($subs !== []) {
                    return 'subcategory';
                }
            }
            if (self::facetSplits($facets, $key, $group)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Kapasite seçildi, hassasiyet henüz yok: kart/liste öncesi zorunlu adım.
     *
     * @param array<string, mixed> $filters
     * @param list<string> $asked
     */
    public static function pendingPrecisionAfterCapacity(array $filters, array $asked): bool
    {
        $filters = self::normalizeState($filters);
        if (in_array('precision', $asked, true) || self::criterionSet($filters, 'precision')) {
            return false;
        }
        if (!self::criterionSet($filters, 'capacity') || self::isMoistureGroup($filters)) {
            return false;
        }
        [$pool, $found] = self::poolForFilters($filters, 500);
        if ($pool === [] || AssistantV2Catalog::skipsMeasures($pool)) {
            return false;
        }

        return count($found['facets']['available_precisions'] ?? []) >= 1;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}
     */
    private static function poolForFilters(array $filters, int $limit): array
    {
        $found = self::search($filters, 0, max(1, $limit));
        if ((int) $found['total'] === 0) {
            return [[], $found];
        }
        $byId = AssistantV2Catalog::byId();
        $pool = [];
        foreach ($found['all_ids'] as $id) {
            if (isset($byId[$id])) {
                $pool[] = $byId[$id];
            }
        }

        return [$pool, $found];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function criteria(array $raw): array
    {
        $out = [];
        $group = null;
        $rawSub = is_string($raw['subcategory'] ?? null) ? trim($raw['subcategory']) : '';
        $rawCat = is_string($raw['category'] ?? null) ? trim($raw['category']) : '';
        if ($rawSub !== '' && AssistantV2Catalog::resolveGroup($rawSub) === null) {
            $out['subcategory'] = $rawSub;
            if ($rawCat !== '') {
                $catNode = AssistantV2Catalog::resolveGroup($rawCat);
                $out['category'] = is_array($catNode) ? (string) $catNode['slug'] : $rawCat;
            }
        }
        if (!isset($out['subcategory'])) {
            foreach (['subcategory', 'category'] as $key) {
                if (!is_string($raw[$key] ?? null) || trim($raw[$key]) === '') {
                    continue;
                }
                $node = AssistantV2Catalog::resolveGroup($raw[$key]);
                if ($node !== null) {
                    $group = $node;
                    break;
                }
            }
            if ($group !== null) {
                if ($group['parent'] !== '' && AssistantV2Catalog::node($group['parent']) !== null) {
                    $out['category'] = $group['parent'];
                    $out['subcategory'] = $group['slug'];
                } else {
                    $out['category'] = $group['slug'];
                    $out['subcategory'] = null;
                }
                if ($group['children'] === 0) {
                    $out['subcategory'] = $group['slug'];
                    $out['category'] = $group['parent'] !== '' ? $group['parent'] : $group['slug'];
                }
            }
        }

        $brands = [];
        foreach (is_array($raw['brands'] ?? null) ? $raw['brands'] : [] as $brand) {
            if (!is_string($brand)) {
                continue;
            }
            $resolved = AssistantV2Catalog::resolveBrand($brand);
            $kept = $resolved ?? trim($brand);
            if ($kept === '') {
                continue;
            }
            $brands[] = $kept;
        }
        if ($brands !== []) {
            $out['brands'] = array_values(array_unique($brands));
        }

        if (is_array($raw['precision'] ?? null)) {
            $precision = self::precisionValue($raw['precision']);
            if ($precision !== null) {
                $out['precision'] = $precision;
            }
        }
        if (is_array($raw['capacity'] ?? null)) {
            $capacity = self::capacityValue($raw['capacity']);
            if ($capacity !== null) {
                $out['capacity'] = $capacity;
            }
        }
        if (array_key_exists('commercial_approval', $raw) && is_bool($raw['commercial_approval'])) {
            $out['commercial_approval'] = $raw['commercial_approval'];
        }
        if (is_string($raw['calibration'] ?? null) && trim($raw['calibration']) !== '') {
            $out['calibration'] = trim($raw['calibration']);
        }
        if (is_string($raw['communication'] ?? null) && trim($raw['communication']) !== '') {
            $out['communication'] = trim($raw['communication']);
        }
        if (in_array($raw['condition'] ?? null, ['new', 'renewed'], true)) {
            $out['condition'] = $raw['condition'];
        }
        if (is_string($raw['series'] ?? null) && trim($raw['series']) !== '') {
            $series = AssistantV2Catalog::resolveSeries($raw['series']);
            if ($series !== null) {
                $out['series'] = $series;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public static function card(array $product, bool $near = false): array
    {
        $badge = '';
        $badgeKey = '';
        $condition = AssistantV2Catalog::fold((string) ($product['condition'] ?? ''));
        if (str_contains($condition, 'yenilen') || preg_match('/2\s*\.?\s*el|ikinci el/u', $condition) === 1) {
            $badge = (string) __('common.product_badge_renewed');
            $badgeKey = 'perfect';
        } elseif ($product['commercial'] === true) {
            $badge = 'Ticari Onaylı';
            $badgeKey = 'commercial';
        }

        return [
            'id'         => (int) $product['id'],
            'name'       => (string) $product['name'],
            'order'      => (int) ($product['sort_order'] ?? 0),
            'image'      => (string) ($product['image'] ?? ''),
            'capacity'  => (string) ($product['capacity_label'] ?? ''),
            'precision' => (string) ($product['precision_label'] ?? ''),
            'capacity_g'=> self::capacityGrams($product),
            'badge'     => $badge,
            'badge_key' => $badgeKey,
            'url'       => (string) ($product['url'] ?? ''),
            'near'      => $near,
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public static function publicFacts(array $product, bool $withName = false): array
    {
        $usages = AssistantV2Catalog::usagesOf($product);
        $facts = [
            'id'            => (int) $product['id'],
            'brand'         => (string) ($product['brand'] ?? ''),
            'usages'        => $usages,
            'capacity'      => $product['capacity_label'],
            'capacity_g'    => self::capacityGrams($product),
            'precision'     => $product['precision_label'],
            'precision_unit'=> $product['precision_unit'],
            'price'         => $product['price_label'],
            'in_stock'      => $product['in_stock'],
            'calibration'   => $product['calibration'],
            'commercial'    => $product['commercial'],
            'condition'     => $product['condition'],
            'specs'         => self::writerSpecs($product, $usages),
        ];
        if ($withName) {
            $facts['name'] = (string) $product['name'];
        }

        return $facts;
    }

    /**
     * Kategori kayıtları varken "Ürün Tipi" satırı tek alanı ürünün tamamı gibi göstermesin.
     *
     * @param array<string, mixed> $product
     * @param list<string> $usages
     * @return list<array<string, mixed>>
     */
    private static function writerSpecs(array $product, array $usages): array
    {
        unset($usages);

        return self::answerSpecs($product, 12);
    }

    /**
     * Yazıcı ve planlayıcıya giden teknik özellik satırları. Kategori satırı kullanım yoludur.
     *
     * @param array<string, mixed> $product
     * @return list<array{label:string,value:string}>
     */
    public static function answerSpecs(array $product, int $limit = 12): array
    {
        $out = [];
        $seen = [];
        foreach (is_array($product['specs'] ?? null) ? $product['specs'] : [] as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $label = trim((string) ($spec['label'] ?? ''));
            $value = trim((string) ($spec['value'] ?? ''));
            if ($label === '' || $value === '' || AssistantV2Catalog::fold($label) === 'kategori') {
                continue;
            }
            $key = AssistantV2Catalog::fold($label);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['label' => $label, 'value' => $value];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public static function specPresent(array $product, string $query): bool
    {
        $folded = AssistantV2Catalog::fold($query);
        if (str_contains($folded, 'kapasite') || str_contains($folded, 'hassasiyet')) {
            foreach (self::answerSpecs($product, 16) as $spec) {
                $label = AssistantV2Catalog::fold((string) ($spec['label'] ?? ''));
                if (str_contains($folded, 'kapasite') && str_contains($label, 'kapasite') && !str_contains($label, 'yogunluk')) {
                    return true;
                }
                if (str_contains($folded, 'hassasiyet') && ($label === 'hassasiyet' || str_starts_with($label, 'hassasiyet '))) {
                    return true;
                }
            }
            $capacityLabel = trim((string) ($product['capacity_label'] ?? ''));
            if (str_contains($folded, 'kapasite') && $capacityLabel !== '' && !self::measureRangeLabel($capacityLabel)) {
                return true;
            }
            $precisionLabel = trim((string) ($product['precision_label'] ?? ''));
            if (str_contains($folded, 'hassasiyet') && $precisionLabel !== '' && !self::measureRangeLabel($precisionLabel)) {
                return true;
            }
        }
        $tokens = array_values(array_filter(
            preg_split('/[^a-z0-9]+/u', $folded) ?: [],
            static fn (string $token): bool => strlen($token) >= 5
        ));
        if ($tokens === []) {
            return false;
        }
        foreach ($product['specs'] ?? [] as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $label = AssistantV2Catalog::fold((string) ($spec['label'] ?? ''));
            $ok = true;
            foreach ($tokens as $token) {
                if (!str_contains($label, $token)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $capacity
     */
    private static function capacityVerdict(array $product, array $capacity): string
    {
        $operator = $capacity['operator'] ?? null;
        if (!is_string($operator) || $operator === '') {
            return 'pass';
        }
        $grams = self::capacityGrams($product);
        if ($grams === null) {
            return 'reject';
        }
        $value = is_numeric($capacity['value_g'] ?? null) ? (float) $capacity['value_g'] : null;
        $min = is_numeric($capacity['min_g'] ?? null) ? (float) $capacity['min_g'] : null;
        $max = is_numeric($capacity['max_g'] ?? null) ? (float) $capacity['max_g'] : null;

        return match ($operator) {
            'max'           => ($value !== null && self::nanoAtMost($grams, $value)) ? 'pass' : 'reject',
            'lt'            => ($value !== null && self::nanoLessThan($grams, $value)) ? 'pass' : 'reject',
            'min'           => ($value !== null && self::capacityCoversLoad($grams, $value)) ? 'pass' : 'reject',
            'range'         => ($min !== null && $max !== null && self::capacityCoversLoad($grams, $min) && self::nanoAtMost($grams, $max)) ? 'pass' : 'reject',
            'exact'         => ($value !== null && self::nanoEqual($grams, $value)) ? 'pass' : 'reject',
            'approximately' => ($value !== null && self::nearEnough($grams, $value, 0.05, 25.0)) ? 'pass' : 'reject',
            default         => 'reject',
        };
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $filters
     */
    private static function matchesCore(array $product, array $filters): bool
    {
        $sub = $filters['subcategory'] ?? null;
        $cat = $filters['category'] ?? null;
        if (is_string($sub) && $sub !== '') {
            if (!AssistantV2Catalog::inGroup($product, $sub, true) && !AssistantV2Catalog::inGroup($product, $sub, false)) {
                return false;
            }
        } elseif (is_string($cat) && $cat !== '') {
            if (!AssistantV2Catalog::inGroup($product, $cat, false)) {
                return false;
            }
        }
        $type = is_string($filters['type_token'] ?? null) ? $filters['type_token'] : '';
        if ($type !== '' && !self::matchesType($product, $type)) {
            return false;
        }

        $brands = is_array($filters['brands'] ?? null) ? $filters['brands'] : [];
        if ($brands !== []) {
            $have = AssistantV2Catalog::fold((string) ($product['brand'] ?? ''));
            $ok = false;
            foreach ($brands as $brand) {
                if (AssistantV2Catalog::fold((string) $brand) === $have) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }

        $precision = is_array($filters['precision'] ?? null) ? $filters['precision'] : [];
        if (($precision['unresolved'] ?? false) === true) {
            return false;
        }
        if (is_numeric($precision['value'] ?? null) && is_string($precision['unit'] ?? null)) {
            if (!self::precisionMatches($product, (float) $precision['value'], $precision['unit'], $precision)) {
                return false;
            }
        }
        $capacity = is_array($filters['capacity'] ?? null) ? $filters['capacity'] : [];
        if (($capacity['unresolved'] ?? false) === true) {
            return false;
        }
        $capacitySlug = is_string($filters['capacity_slug'] ?? null) ? $filters['capacity_slug'] : '';
        if ($capacitySlug !== '' && !self::hasCapacitySlug($product, $capacitySlug)) {
            return false;
        }

        if (is_array($product['listing_filters'] ?? null)) {
            if (!self::matchesCatalogFacets($product, $filters)) {
                return false;
            }
        } else {
            if (is_bool($filters['commercial_approval'] ?? null) && $product['commercial'] !== $filters['commercial_approval']) {
                return false;
            }
            if (is_string($filters['calibration'] ?? null) && $filters['calibration'] !== '') {
                $have = AssistantV2Catalog::fold((string) ($product['calibration'] ?? ''));
                if ($have === '' || !str_contains($have, AssistantV2Catalog::fold($filters['calibration']))) {
                    return false;
                }
            }
        }
        if (is_string($filters['series'] ?? null) && $filters['series'] !== '') {
            if (AssistantV2Catalog::seriesOf($product) !== $filters['series']) {
                return false;
            }
        }

        return true;
    }

    /**
     * En ince gram okunabilirlik: yapılandırılmış hassasiyet filtresi veya güvenilir spec satırı.
     * Liste kartı ile katalog araması bu değeri kullanır.
     *
     * @param array<string, mixed> $product
     */
    public static function readabilityGrams(array $product): ?float
    {
        $best = null;
        $consider = static function (?float $grams) use (&$best): void {
            if ($grams === null || $grams <= 0) {
                return;
            }
            if ($best === null || $grams < $best) {
                $best = $grams;
            }
        };
        foreach ($product['precision_filters'] ?? [] as $filter) {
            if (!is_array($filter) || (string) ($filter['unit'] ?? 'g') !== 'g') {
                continue;
            }
            $consider(is_numeric($filter['grams'] ?? null) ? (float) $filter['grams'] : null);
        }
        $consider(self::specPrecisionGrams($product, 'g'));

        return $best;
    }

    /**
     * SHOW_PRODUCTS kart attribute'ları. Boş yapılandırılmış alan, spec'ten çıkan değeri gizlemez.
     *
     * @param array<string, mixed> $product
     * @return array{capacity:?string,precision:?string}
     */
    public static function listingCardMeasures(array $product): array
    {
        $capacity = self::capacityGrams($product);
        $precision = self::readabilityGrams($product);

        return [
            'capacity'  => $capacity !== null ? self::canonicalGramString($capacity) : null,
            'precision' => $precision !== null ? self::canonicalGramString($precision) : null,
        ];
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function precisionMatches(array $product, float $value, string $unit, array $requested = []): bool
    {
        if ($unit !== 'g' && $unit !== 'ct') {
            return false;
        }
        if ($unit === 'g') {
            $have = self::readabilityGrams($product);
            $requestedNano = is_int($requested['nano'] ?? null)
                ? $requested['nano']
                : self::gramsToNano($value);
            $haveNano = $have === null ? null : self::gramsToNano($have);

            return $haveNano !== null && $requestedNano !== null && $haveNano > 0 && $requestedNano > 0 && $haveNano <= $requestedNano;
        }

        $candidates = [];
        foreach ($product['precision_filters'] ?? [] as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $filterUnit = (string) ($filter['unit'] ?? 'g');
            if ($filterUnit === $unit && is_numeric($filter['grams'] ?? null)) {
                $candidates[] = (float) $filter['grams'];
            }
        }
        $specGrams = self::specPrecisionGrams($product, $unit);
        if ($specGrams !== null) {
            $candidates[] = $specGrams;
        }
        if ($unit === 'ct') {
            foreach ($candidates as $candidate) {
                if (self::readabilityMeetsRequest($candidate, $value)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    public static function readabilityMeetsRequest(float $productGrams, float $requestedGrams): bool
    {
        $have = self::gramsToNano($productGrams);
        $want = self::gramsToNano($requestedGrams);

        return $have !== null && $want !== null && $have > 0 && $want > 0 && $have <= $want;
    }

    public static function capacityCoversLoad(float $productGrams, float $requestedGrams): bool
    {
        $have = self::gramsToNano($productGrams);
        $want = self::gramsToNano($requestedGrams);

        return $have !== null && $want !== null && $have >= $want;
    }

    /**
     * Liste sayfası ile SHOW_PRODUCTS aynı sınır: capacityGrams / readabilityGrams.
     * kap_min: kapasite >= istenen. has_max: okunabilirlik <= istenen (daha ince kalır).
     *
     * @param array<string, mixed> $product
     * @param array<string, string> $query
     */
    public static function matchesListingMeasures(array $product, array $query): bool
    {
        $cap = self::capacityGrams($product);
        $prec = self::readabilityGrams($product);
        $read = static function (string $key) use ($query): ?float {
            if (!array_key_exists($key, $query)) {
                return null;
            }
            $raw = str_replace(',', '.', trim((string) $query[$key]));
            if ($raw === '' || !is_numeric($raw)) {
                return null;
            }
            $number = (float) $raw;

            return ($number > 0 && is_finite($number)) ? $number : null;
        };

        $min = $read('kap_min');
        if ($min !== null && ($cap === null || !self::capacityCoversLoad($cap, $min))) {
            return false;
        }
        $max = $read('kap_max');
        if ($max !== null && ($cap === null || !self::nanoAtMost($cap, $max))) {
            return false;
        }
        $lt = $read('kap_lt');
        if ($lt !== null && ($cap === null || !self::nanoLessThan($cap, $lt))) {
            return false;
        }
        $eq = $read('kap_eq');
        if ($eq !== null && ($cap === null || !self::nanoEqual($cap, $eq))) {
            return false;
        }
        $approx = $read('kap_approx');
        if ($approx !== null && ($cap === null || !self::nearEnough($cap, $approx, 0.05, 25.0))) {
            return false;
        }
        $hasMax = $read('has_max');
        if ($hasMax !== null && ($prec === null || !self::readabilityMeetsRequest($prec, $hasMax))) {
            return false;
        }

        return true;
    }

    /**
     * Analizör sayısal kısıtı yazmadıysa kullanıcının söylediği gramı mevcut filtre şekline yazar.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function keepStatedMeasures(array $filters, string $message): array
    {
        $filters = self::normalizeState($filters);
        $capacity = is_array($filters['capacity'] ?? null) ? $filters['capacity'] : [];
        $resolvedCapacity = is_numeric($capacity['value_g'] ?? null)
            && ($capacity['unresolved'] ?? false) !== true
            && is_string($capacity['operator'] ?? null)
            && $capacity['operator'] !== '';
        if (!$resolvedCapacity) {
            $filters = self::applyChipCapacity($filters, $message);
            $operator = self::statedCapacityOperator($message);
            if ($operator !== null && is_numeric($filters['capacity']['value_g'] ?? null)) {
                $filters['capacity']['operator'] = $operator;
            }
        }

        $precision = is_array($filters['precision'] ?? null) ? $filters['precision'] : [];
        $resolvedPrecision = is_numeric($precision['value'] ?? null)
            && ($precision['unresolved'] ?? false) !== true
            && ($precision['unit'] ?? null) === 'g';
        $fold = AssistantV2Catalog::fold($message);
        if (!$resolvedPrecision && preg_match('/hassasiyet|okunabilir/u', $fold) === 1) {
            $filters = self::applyStatedReadability($filters, $message);
        }

        return self::normalizeState($filters);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function applyStatedReadability(array $filters, string $message): array
    {
        $filters = self::normalizeState($filters);
        if (preg_match('/^\d+(?:[.,]\d+)?$/u', trim($message)) === 1) {
            $message .= ' g';
        }
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(ug|µg|μg|mg|g|gr|gram|ct)\b/iu', $message, $matches, PREG_SET_ORDER) < 1) {
            return $filters;
        }
        $picked = null;
        foreach ($matches as $match) {
            $unit = strtolower((string) $match[2]);
            $massUnit = in_array($unit, ['gr', 'gram'], true) ? 'g' : $unit;
            $grams = self::toGrams(str_replace(',', '.', (string) $match[1]), $massUnit);
            if ($grams !== null && $grams > 0 && $grams < 1) {
                $picked = ['value' => str_replace(',', '.', (string) $match[1]), 'unit' => $massUnit];
            }
        }
        if ($picked === null) {
            return $filters;
        }
        $precision = self::precisionValue($picked);
        if ($precision === null || ($precision['unresolved'] ?? false) === true) {
            return $filters;
        }
        $filters['precision'] = $precision;

        return self::normalizeState($filters);
    }

    /**
     * Katalog liste URL'sinin kapasite/hassasiyet query'si. Arama ile aynı sınır.
     *
     * @param array<string, mixed> $filters
     * @return array<string, string>
     */
    public static function listingMeasureParams(array $filters): array
    {
        $filters = self::normalizeState($filters);
        $query = [];
        $capacity = is_array($filters['capacity'] ?? null) ? $filters['capacity'] : [];
        if (($capacity['unresolved'] ?? false) !== true) {
            $operator = is_string($capacity['operator'] ?? null) ? $capacity['operator'] : '';
            $value = is_numeric($capacity['value_g'] ?? null) ? self::canonicalGramString((float) $capacity['value_g']) : null;
            $min = is_numeric($capacity['min_g'] ?? null) ? self::canonicalGramString((float) $capacity['min_g']) : null;
            $max = is_numeric($capacity['max_g'] ?? null) ? self::canonicalGramString((float) $capacity['max_g']) : null;
            if ($operator === 'min' && $value !== null) {
                $query['kap_min'] = $value;
            } elseif ($operator === 'max' && $value !== null) {
                $query['kap_max'] = $value;
            } elseif ($operator === 'lt' && $value !== null) {
                $query['kap_lt'] = $value;
            } elseif ($operator === 'range' && $min !== null && $max !== null) {
                $query['kap_min'] = $min;
                $query['kap_max'] = $max;
            } elseif ($operator === 'exact' && $value !== null) {
                $query['kap_eq'] = $value;
            } elseif ($operator === 'approximately' && $value !== null) {
                $query['kap_approx'] = $value;
            }
        }
        $precision = is_array($filters['precision'] ?? null) ? $filters['precision'] : [];
        if (
            ($precision['unresolved'] ?? false) !== true
            && ($precision['unit'] ?? null) === 'g'
            && is_numeric($precision['value'] ?? null)
        ) {
            $text = self::canonicalGramString((float) $precision['value']);
            if ($text !== null) {
                $query['has_max'] = $text;
            }
        }

        return $query;
    }

    /**
     * Konuşmada doğrulanan kriteri listelemenin gerçek ?f= / ?tag= slug'larına çevirir.
     *
     * @param array<string, mixed> $filters
     * @return array{f:list<string>,tag:list<string>,missing:list<string>}
     */
    public static function listingFacetParams(array $filters): array
    {
        $filters = self::normalizeState($filters);
        $products = self::scopeProducts($filters);
        $f = [];
        $tag = [];
        $missing = [];

        if (is_bool($filters['commercial_approval'])) {
            $slugs = self::slugsForCommercial($products, $filters['commercial_approval'] === true);
            if ($slugs === []) {
                $missing[] = 'commercial_approval';
            } else {
                $f = array_merge($f, $slugs);
            }
        }
        $calibration = is_string($filters['calibration'] ?? null) ? $filters['calibration'] : '';
        if ($calibration !== '') {
            $slugs = self::slugsForToken(
                $products,
                ['kalibrasyon-tipi', 'hassasiyet-kalibrasyon'],
                $calibration,
                self::calibrationExcludes($calibration)
            );
            if ($slugs === []) {
                $missing[] = 'calibration';
            } else {
                $f = array_merge($f, $slugs);
            }
        }
        $communication = is_string($filters['communication'] ?? null) ? $filters['communication'] : '';
        if ($communication !== '') {
            $slugs = self::slugsForToken($products, ['haberlesme', 'baglanti'], $communication, []);
            if ($slugs === []) {
                $missing[] = 'communication';
            } else {
                $f = array_merge($f, $slugs);
            }
        }
        if (($filters['condition'] ?? null) === 'new') {
            $slug = product_new_condition_filter_slug();
            $slugs = self::slugsForToken($products, ['urun-durumu'], 'sifir', []);
            if (!in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
            $present = false;
            foreach ($products as $product) {
                if (self::productHasSlug($product, 'urun-durumu', $slug)) {
                    $present = true;
                    break;
                }
            }
            if (!$present) {
                $missing[] = 'condition';
            } else {
                $f[] = $slug;
            }
        }
        if (($filters['condition'] ?? null) === 'renewed') {
            $renewed = self::renewedTagInScope($products);
            if ($renewed === null) {
                $missing[] = 'condition';
            } else {
                $tag[] = $renewed;
            }
        }
        foreach ($filters['facets'] as $facet) {
            if (!is_array($facet)) {
                continue;
            }
            $group = (string) ($facet['group'] ?? '');
            $token = (string) ($facet['token'] ?? '');
            $slugs = self::slugsForToken($products, [$group], $token, []);
            if ($slugs === []) {
                $missing[] = $group;
            } else {
                $f = array_merge($f, $slugs);
            }
        }

        return [
            'f'       => array_values(array_unique($f)),
            'tag'     => array_values(array_unique($tag)),
            'missing' => array_values(array_unique($missing)),
        ];
    }

    /**
     * Ölçü query'si ile liste facet slug'ları. SHOW_PRODUCTS ve arama aynı diziyi kullanır.
     *
     * @param array<string, mixed> $filters
     * @return array<string, string>
     */
    public static function listingQueryParams(array $filters): array
    {
        $query = self::listingMeasureParams($filters);
        $facets = self::listingFacetParams($filters);
        if ($facets['f'] !== []) {
            $query['f'] = implode(',', $facets['f']);
        }
        if ($facets['tag'] !== []) {
            $query['tag'] = implode(',', $facets['tag']);
        }

        return $query;
    }

    /**
     * Doğrulanmış bir kriter liste slug'ına bağlanamıyorsa ürün listesi açılmaz.
     *
     * @param array<string, mixed> $filters
     */
    public static function listingFiltersBlocked(array $filters): bool
    {
        $filters = self::normalizeState($filters);

        return $filters['unsupported'] !== [] || self::listingFacetParams($filters)['missing'] !== [];
    }

    /**
     * Kullanıcının bu turda söylediği kriter. Söylenmeyen alan uydurulmaz; kardeş filtreler durur.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function applyStatedFacets(array $filters, string $message): array
    {
        $filters = self::normalizeState($filters);
        $filters['unsupported'] = [];
        $fold = AssistantV2Catalog::fold($message);
        if ($fold === '') {
            return $filters;
        }
        $commit = preg_match('/\b(olsun|istiyorum|istiyoruz|olmali|lazim|gerekli|istiyor)\b/u', $fold) === 1;
        $dismiss = preg_match('/onemli degil|fark etmez|gerekmiyor|gerekmez|istemiyorum|olmasin/u', $fold) === 1;

        if (preg_match('/\bmarka\b/u', $fold) === 1 && $dismiss) {
            $filters['brands'] = [];
        }
        if (preg_match('/onay/u', $fold) === 1 && $dismiss) {
            $filters['commercial_approval'] = null;
        } elseif ($commit && preg_match('/\bonaysiz\b/u', $fold) === 1) {
            $filters['commercial_approval'] = false;
        } elseif ($commit && preg_match('/\b(ticari onayli|onayli)\b/u', $fold) === 1) {
            $filters['commercial_approval'] = true;
        }

        if (preg_match('/kalibrasyon/u', $fold) === 1 && $dismiss) {
            $filters['calibration'] = null;
        } elseif ($commit && preg_match('/kalibrasyon/u', $fold) === 1) {
            if (preg_match('/yari\s*otomatik/u', $fold) === 1) {
                $filters['calibration'] = 'yari otomatik';
            } elseif (preg_match('/\bharici\b/u', $fold) === 1) {
                $filters['calibration'] = 'harici';
            } elseif (preg_match('/\bdahili\b/u', $fold) === 1) {
                $filters['calibration'] = 'dahili';
            } elseif (preg_match('/\botomatik\b/u', $fold) === 1) {
                $filters['calibration'] = 'otomatik';
            } else {
                $filters['unsupported'][] = 'kalibrasyon';
            }
        }

        $protocol = null;
        if (preg_match('/rs\s*-?\s*232/u', $fold) === 1) {
            $protocol = 'rs232';
        } elseif (preg_match('/\bbluetooth\b/u', $fold) === 1) {
            $protocol = 'bluetooth';
        } elseif (preg_match('/\b(wifi|wi-fi)\b/u', $fold) === 1) {
            $protocol = 'wifi';
        } elseif (preg_match('/\bethernet\b/u', $fold) === 1) {
            $protocol = 'ethernet';
        } elseif (preg_match('/\busb\b/u', $fold) === 1) {
            $protocol = 'usb';
        }
        if ($protocol !== null && $dismiss) {
            $filters['communication'] = null;
        } elseif ($protocol !== null && $commit) {
            $filters['communication'] = $protocol;
        }

        if ((preg_match('/(?:^|\s)(2\s*\.?\s*el|ikinci el|yenilenmis)(?:\s|$)/u', $fold) === 1) && $dismiss) {
            $filters['condition'] = null;
        } elseif ($commit && preg_match('/(?:^|\s)(2\s*\.?\s*el|ikinci el|yenilenmis)(?:\s|$)/u', $fold) === 1) {
            $filters['condition'] = 'renewed';
        } elseif ($commit && preg_match('/\b(sifir|yeni urun)\b/u', $fold) === 1) {
            $filters['condition'] = 'new';
        }

        if (preg_match('/\bmensei\b/u', $fold) === 1) {
            $filters['unsupported'][] = 'mensei';
        }

        return self::normalizeState($filters);
    }

    /**
     * Asistanın aynı cümlede sunduğu iki veya daha fazla kapasite seçeneği.
     *
     * @return list<float>
     */
    public static function offeredCapacityValues(string $text): array
    {
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(kg|kilogram|kilo|gram|gr|g)\b/iu', $text, $matches, PREG_SET_ORDER) < 1) {
            return [];
        }
        $values = [];
        foreach ($matches as $match) {
            $unit = strtolower((string) $match[2]);
            if (in_array($unit, ['gram', 'gr'], true)) {
                $unit = 'g';
            }
            if (in_array($unit, ['kilogram', 'kilo'], true)) {
                $unit = 'kg';
            }
            $grams = self::toGrams(str_replace(',', '.', (string) $match[1]), $unit);
            if ($grams === null || $grams < 1) {
                continue;
            }
            $key = self::canonicalGramString($grams);
            if ($key !== null) {
                $values[$key] = (float) $grams;
            }
        }
        if (count($values) < 2) {
            return [];
        }
        $out = array_values($values);
        sort($out);

        return array_slice($out, 0, 8);
    }

    /**
     * Sunulan kapasite seçeneği exact kalır. Tartılacak yük cümlesi minimum kapasite olarak kalır.
     *
     * @param array<string, mixed> $filters
     * @param list<float|int|string> $offeredGrams
     * @return array<string, mixed>
     */
    public static function applyCapacityChoice(array $filters, string $message, array $offeredGrams): array
    {
        $filters = self::normalizeState($filters);
        $offered = [];
        foreach ($offeredGrams as $grams) {
            if (is_numeric($grams) && (float) $grams >= 1) {
                $offered[] = (float) $grams;
            }
        }
        if ($offered === []) {
            return $filters;
        }
        if (self::capacitySense($message) === 'required_load') {
            $grams = self::utteranceGrams($message);
            if ($grams !== null && $grams >= 1) {
                $filters['capacity'] = [
                    'operator' => 'min',
                    'value_g' => $grams,
                    'min_g' => null,
                    'max_g' => null,
                    'sense' => 'required_load',
                ];
            }

            return self::normalizeState($filters);
        }
        $fold = AssistantV2Catalog::fold($message);
        if (preg_match('/\b(olsun|secerim|secelim)\b/u', $fold) !== 1) {
            return $filters;
        }
        if (preg_match_all('/\d+(?:[.,]\d+)?/u', $message, $numbers) < 1) {
            return $filters;
        }
        foreach ($numbers[0] as $raw) {
            $value = (float) str_replace(',', '.', (string) $raw);
            foreach ($offered as $grams) {
                if (self::nanoEqual($value, $grams)) {
                    $filters['capacity'] = [
                        'operator' => 'exact',
                        'value_g' => $grams,
                        'min_g' => null,
                        'max_g' => null,
                        'sense' => 'offered_choice',
                    ];

                    return self::normalizeState($filters);
                }
            }
        }

        return $filters;
    }

    public static function canonicalGramString(float $grams): ?string
    {
        $nano = self::gramsToNano($grams);
        if ($nano === null || $nano <= 0) {
            return null;
        }
        $whole = intdiv($nano, 1000000000);
        $frac = $nano % 1000000000;
        if ($frac === 0) {
            return (string) $whole;
        }
        $fracText = rtrim(str_pad((string) $frac, 9, '0', STR_PAD_LEFT), '0');

        return $whole . '.' . $fracText;
    }

    private static function nanoAtMost(float $grams, float $target): bool
    {
        $have = self::gramsToNano($grams);
        $want = self::gramsToNano($target);

        return $have !== null && $want !== null && $have <= $want;
    }

    private static function nanoLessThan(float $grams, float $target): bool
    {
        $have = self::gramsToNano($grams);
        $want = self::gramsToNano($target);

        return $have !== null && $want !== null && $have < $want;
    }

    private static function nanoEqual(float $grams, float $target): bool
    {
        $have = self::gramsToNano($grams);
        $want = self::gramsToNano($target);

        return $have !== null && $want !== null && $have === $want;
    }

    public static function normalizeWeightGrams(mixed $value, mixed $unit = 'g'): ?float
    {
        $mass = self::massUnit($unit, true);
        $nano = self::measureNano($value, $mass);

        return $nano === null ? null : $nano / 1000000000;
    }

    private static function specPrecisionGrams(array $product, string $unit): ?float
    {
        foreach (is_array($product['specs'] ?? null) ? $product['specs'] : [] as $spec) {
            if (!is_array($spec) || self::specMeasureKind((string) ($spec['label'] ?? '')) !== 'hassasiyet') {
                continue;
            }
            $value = trim((string) ($spec['value'] ?? ''));
            if ($value === '' || self::measureRangeLabel($value)) {
                continue;
            }
            if ($unit === 'ct' && preg_match('/(0[.,]\d+)\s*ct\b/iu', $value, $match) === 1) {
                return (float) str_replace(',', '.', $match[1]);
            }
            if ($unit === 'g') {
                $grams = self::leadingGrams($value);
                if ($grams !== null && $grams > 0 && $grams < 100) {
                    return $grams;
                }
            }
        }

        return null;
    }

    public static function specFilterSlug(string $kind, string $value): string
    {
        $prefix = $kind === 'kapasite' ? 'spec-kap-' : 'spec-has-';
        $token = str_replace([',', ' '], ['.', ''], AssistantV2Catalog::fold($value));
        $token = preg_replace('/[^a-z0-9.\-]+/', '', $token) ?? '';
        if ($token === '') {
            $token = substr(md5($value), 0, 10);
        }

        return $prefix . $token;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed> $capacity
     * @return list<array<string, mixed>>
     */
    private static function enforceHardCapacity(array $products, array $capacity): array
    {
        $operator = $capacity['operator'] ?? null;
        if (!in_array($operator, ['max', 'lt', 'min', 'range', 'exact'], true)) {
            return $products;
        }

        return array_values(array_filter($products, static function (array $product) use ($capacity): bool {
            return self::capacityVerdict($product, $capacity) === 'pass';
        }));
    }

    /**
     * Seçilen yük önde, daha yüksek cihaz kapasiteleri arkada.
     *
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed> $capacity
     * @return list<array<string, mixed>>
     */
    private static function orderByRequiredLoad(array $products, array $capacity): array
    {
        if (($capacity['operator'] ?? null) !== 'min' || !is_numeric($capacity['value_g'] ?? null)) {
            return $products;
        }
        $target = (float) $capacity['value_g'];
        usort($products, static function (array $a, array $b) use ($target): int {
            $ga = self::capacityGrams($a);
            $gb = self::capacityGrams($b);
            $da = $ga === null ? PHP_FLOAT_MAX : $ga - $target;
            $db = $gb === null ? PHP_FLOAT_MAX : $gb - $target;
            if (abs($da - $db) >= 0.5) {
                return $da <=> $db;
            }
            $order = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));

            return $order !== 0 ? $order : ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        return $products;
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function nearestCapacity(array $products, float $target): ?float
    {
        $best = null;
        $bestDist = null;
        foreach ($products as $product) {
            $grams = self::capacityGrams($product);
            if ($grams === null) {
                continue;
            }
            $dist = abs($grams - $target);
            if ($bestDist === null || $dist < $bestDist) {
                $bestDist = $dist;
                $best = $grams;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $product
     */
    public static function capacityGrams(array $product): ?float
    {
        $max = $product['capacity_max'] ?? null;
        if (!is_numeric($max) || (float) $max <= 0) {
            return null;
        }

        return (float) $max;
    }

    /**
     * Yalnız marka kısıtı var: kategori, ölçü, seri ve tür yok.
     *
     * @param array<string, mixed> $filters
     */
    public static function brandOnly(array $filters): bool
    {
        $filters = self::normalizeState($filters);

        return $filters['brands'] !== []
            && self::activeGroup($filters) === ''
            && !self::hasMeasure($filters)
            && !(is_string($filters['series'] ?? null) && $filters['series'] !== '')
            && !(is_string($filters['type_token'] ?? null) && $filters['type_token'] !== '');
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function hasMeasure(array $filters): bool
    {
        $precision = $filters['precision']['value'] ?? null;
        $operator = $filters['capacity']['operator'] ?? null;

        return is_numeric($precision) || (is_string($operator) && $operator !== '');
    }

    /**
     * @param array<string, mixed> $filters
     */
    private static function activeGroup(array $filters): string
    {
        if (is_string($filters['subcategory']) && $filters['subcategory'] !== '') {
            return $filters['subcategory'];
        }

        return is_string($filters['category']) ? $filters['category'] : '';
    }

    /**
     * @param array<string, mixed> $filters
     */
    private static function criterionSet(array $filters, string $key): bool
    {
        return match ($key) {
            'precision'           => is_numeric($filters['precision']['value'] ?? null),
            'capacity'            => (is_string($filters['capacity']['operator'] ?? null) && $filters['capacity']['operator'] !== '')
                || (is_string($filters['capacity_slug'] ?? null) && $filters['capacity_slug'] !== ''),
            'commercial_approval' => is_bool($filters['commercial_approval'] ?? null),
            'brand'               => ($filters['brands'] ?? []) !== [],
            'subcategory'         => is_string($filters['subcategory'] ?? null) && $filters['subcategory'] !== '' && !AssistantV2Catalog::isBroad((string) $filters['subcategory']),
            'calibration'         => is_string($filters['calibration'] ?? null) && $filters['calibration'] !== '',
            default               => false,
        };
    }

    /**
     * @param array<string, mixed> $facets
     */
    private static function facetSplits(array $facets, string $key, string $group): bool
    {
        return match ($key) {
            'precision'           => count($facets['available_precisions'] ?? []) >= 2,
            'capacity'            => count($facets['available_capacities'] ?? []) >= 2,
            'brand'               => count($facets['available_brands'] ?? []) >= 2,
            'commercial_approval' => (($facets['available_commercial_approval']['yes'] ?? 0) > 0)
                && ((($facets['available_commercial_approval']['no'] ?? 0) + ($facets['available_commercial_approval']['unknown'] ?? 0)) > 0),
            'calibration'         => count($facets['available_calibrations'] ?? []) >= 2,
            'subcategory'         => count($facets['available_subcategories'] ?? []) >= 2,
            default               => false,
        };
    }

    private static function childCount(string $slug): int
    {
        $count = 0;
        foreach (AssistantV2Catalog::taxonomy() as $node) {
            if ($node['parent'] === $slug && $node['products'] > 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{value:float,unit:string}|null
     */
    private static function precisionValue(array $raw): ?array
    {
        if (!array_key_exists('value', $raw) && !array_key_exists('value_g', $raw)) {
            return null;
        }
        $source = array_key_exists('value', $raw) ? $raw['value'] : $raw['value_g'];
        $original = self::originalMeasure($source, $raw['unit'] ?? null);
        $unit = self::massUnit($raw['unit'] ?? null, true);
        if ($unit === '') {
            return self::unresolvedMeasure($original);
        }
        $nano = self::measureNano($source, $unit === 'ct' ? 'g' : $unit);
        if ($nano === null || $nano <= 0 || $nano >= 100000000000) {
            return self::unresolvedMeasure($original);
        }
        if ($unit === 'ct') {
            return [
                'value'    => $nano / 1000000000,
                'unit'     => 'ct',
                'original' => $original,
            ];
        }

        return [
            'value'    => $nano / 1000000000,
            'unit'     => 'g',
            'nano'     => $nano,
            'original' => $original,
        ];
    }

    /**
     * "1 kg'a kadar tartacağım" cihaz üst sınırı değildir. En ağır yük, kapasitesi en az o kadar olan cihaz ister.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function applyUtteranceMeasures(array $filters, string $message): array
    {
        $filters = self::normalizeState($filters);
        $sense = self::capacitySense($message);
        if ($sense === null || $sense === 'ambiguous') {
            return $filters;
        }
        $current = is_array($filters['capacity']) ? $filters['capacity'] : [];
        if (($current['operator'] ?? null) === 'range') {
            return $filters;
        }
        $grams = self::utteranceGrams($message);
        if ($grams === null && !is_numeric($current['value_g'] ?? null)) {
            return $filters;
        }
        $filters['capacity'] = [
            'operator' => $sense === 'required_load' ? 'min' : 'max',
            'value_g' => $grams ?? (float) $current['value_g'],
            'min_g' => null,
            'max_g' => null,
            'sense' => $sense,
        ];

        return self::normalizeState($filters);
    }

    /**
     * Çipteki kapasite, tartılacak yüktür. "1 kg'a kadar tartacağım" ile aynı: cihaz kapasitesi bu yükten küçükse elenir.
     * Yük veya cihaz üst sınırı cümlesi zaten kurulduysa çip onu ezmez.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function applyChipCapacity(array $filters, string $message): array
    {
        $filters = self::normalizeState($filters);
        if (self::capacitySense($message) !== null) {
            return $filters;
        }
        $grams = self::utteranceGrams($message);
        if ($grams === null || $grams < 1) {
            return $filters;
        }
        $filters['capacity'] = [
            'operator' => 'min',
            'value_g'  => $grams,
            'min_g'    => null,
            'max_g'    => null,
            'sense'    => 'required_load',
        ];

        return self::normalizeState($filters);
    }

    private static function capacitySense(string $message): ?string
    {
        $fold = AssistantV2Catalog::fold($message);
        $device = preg_match('/kapasite/u', $fold) === 1
            && preg_match('/en fazla|az olsun|gecmesin|ust sinir|maksimum/u', $fold) === 1;
        $load = preg_match('/tartacag|tartmam|tartmak|en agir|kadar tart/u', $fold) === 1;
        if ($device && $load) {
            return 'ambiguous';
        }
        if ($device) {
            return 'device_limit';
        }
        if ($load) {
            return 'required_load';
        }

        return null;
    }

    private static function utteranceGrams(string $message): ?float
    {
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(kg|mg|g)\b/iu', $message, $matches, PREG_SET_ORDER) < 1) {
            return null;
        }
        $picked = null;
        foreach ($matches as $match) {
            $value = (float) str_replace(',', '.', (string) $match[1]);
            $unit = strtolower((string) $match[2]);
            if ($unit === 'g' && $value < 1) {
                continue;
            }
            $grams = self::toGrams($value, $unit);
            if ($grams !== null && $grams > 0) {
                $picked = $grams;
            }
        }

        return $picked;
    }

    /**
     * Kullanıcı cümlesindeki kapasite yönü. max/kadar üst sınır, civarı yaklaşık, aksi halde gereken yük.
     */
    public static function statedCapacityOperator(string $message): ?string
    {
        $fold = AssistantV2Catalog::fold($message);
        $weight = '/\d+(?:[.,]\d+)?\s*(?:ton|kilogram|kilo|gram|kg|gr|mg|g)[a-z]*\b/u';
        if ($fold === '' || preg_match($weight, $fold) !== 1) {
            return null;
        }
        if (preg_match('/\bmax\b|en fazla|ust sinir|gecmesin|asmasin/u', $fold) === 1
            || preg_match('/\d+(?:[.,]\d+)?\s*(?:ton|kilogram|kilo|gram|kg|gr|mg|g)[a-z]*\s+kadar\b/u', $fold) === 1) {
            return 'max';
        }
        if (preg_match('/\b(civari|civar|yaklasik)\b/u', $fold) === 1) {
            return 'approximately';
        }

        return 'min';
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{operator:string,value_g:?float,min_g:?float,max_g:?float}|null
     */
    private static function capacityValue(array $raw): ?array
    {
        $operator = is_string($raw['operator'] ?? null) ? AssistantV2Catalog::fold($raw['operator']) : '';
        $map = [
            'exact' => 'exact', 'approximately' => 'approximately', 'approx' => 'approximately',
            'max' => 'max', 'min' => 'min', 'range' => 'range', 'lt' => 'lt',
        ];
        if (!isset($map[$operator])) {
            $supplied = $raw['value'] ?? $raw['value_g'] ?? null;
            if ($supplied === null || $supplied === '') {
                return null;
            }

            return [
                'operator'   => '',
                'value_g'    => null,
                'min_g'      => null,
                'max_g'      => null,
                'unresolved' => true,
                'original'   => self::originalMeasure($supplied, $raw['unit'] ?? null),
            ];
        }
        $operator = $map[$operator];
        $hasExplicit = array_key_exists('value', $raw) && $raw['value'] !== null && $raw['value'] !== '';
        $unit = $hasExplicit ? self::massUnit($raw['unit'] ?? null) : 'g';
        if ($operator === 'range') {
            $minUnit = self::massUnit($raw['min_unit'] ?? $raw['unit'] ?? null);
            $maxUnit = self::massUnit($raw['max_unit'] ?? $raw['unit'] ?? null);
            $min = self::toGrams($raw['min'] ?? $raw['min_g'] ?? null, $minUnit);
            $max = self::toGrams($raw['max'] ?? $raw['max_g'] ?? null, $maxUnit);
            if ($min === null || $max === null) {
                $supplied = $raw['min'] ?? $raw['min_g'] ?? $raw['max'] ?? $raw['max_g'] ?? null;
                if ($supplied === null || $supplied === '') {
                    return null;
                }

                return [
                    'operator'   => 'range',
                    'value_g'    => null,
                    'min_g'      => null,
                    'max_g'      => null,
                    'unresolved' => true,
                    'original'   => self::originalMeasure($supplied, $raw['unit'] ?? null),
                ];
            }
            if ($min > $max) {
                [$min, $max] = [$max, $min];
            }

            return ['operator' => 'range', 'value_g' => null, 'min_g' => $min, 'max_g' => $max];
        }

        $value = self::toGrams($hasExplicit ? $raw['value'] : ($raw['value_g'] ?? null), $unit);
        if ($value === null || $value <= 0) {
            $supplied = $hasExplicit ? $raw['value'] : ($raw['value_g'] ?? null);
            if ($supplied === null || $supplied === '') {
                return null;
            }

            return [
                'operator'   => $operator,
                'value_g'    => null,
                'min_g'      => null,
                'max_g'      => null,
                'unresolved' => true,
                'original'   => self::originalMeasure($supplied, $raw['unit'] ?? null),
            ];
        }

        return [
            'operator' => $operator,
            'value_g'  => $value,
            'min_g'    => null,
            'max_g'    => null,
            'original' => self::originalMeasure($hasExplicit ? $raw['value'] : ($raw['value_g'] ?? null), $hasExplicit ? ($raw['unit'] ?? null) : 'g'),
        ];
    }

    private static function massUnit(mixed $unit, bool $missingDefaultsToGram = true): string
    {
        if ($unit === null || (is_string($unit) && trim($unit) === '')) {
            return $missingDefaultsToGram ? 'g' : '';
        }
        if (!is_string($unit)) {
            return '';
        }
        $raw = str_replace(['μ', 'µ'], 'u', mb_strtolower(trim($unit)));
        $fold = AssistantV2Catalog::fold($raw);

        return match (true) {
            in_array($raw, ['ug', 'µg', 'μg'], true) || in_array($fold, ['ug', 'mikrogram', 'microgram'], true) => 'ug',
            in_array($fold, ['mg', 'miligram', 'milligram'], true) => 'mg',
            in_array($fold, ['t', 'ton'], true) => 't',
            in_array($fold, ['kg', 'kilogram'], true) => 'kg',
            in_array($fold, ['g', 'gr', 'gram', 'grams'], true) => 'g',
            in_array($fold, ['ct', 'karat', 'carat'], true) => 'ct',
            default => '',
        };
    }

    private static function toGrams(mixed $value, string $unit): ?float
    {
        $nano = self::measureNano($value, $unit);

        return $nano === null ? null : $nano / 1000000000;
    }

    private static function measureNano(mixed $value, string $unit): ?int
    {
        if ($unit === '' || $unit === 'ct') {
            return null;
        }
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return self::numericToNano((float) $value, $unit);
        }
        $decimal = self::decimalString($value);
        if ($decimal === null) {
            return null;
        }
        $nano = self::decimalToNano($decimal, $unit);

        return $nano !== null && $nano > 0 ? $nano : null;
    }

    private static function numericToNano(float $number, string $unit): ?int
    {
        if (!is_finite($number) || $number <= 0) {
            return null;
        }
        $scale = match ($unit) {
            't'  => 1000000000000000,
            'kg' => 1000000000000,
            'g'  => 1000000000,
            'mg' => 1000000,
            'ug' => 1000,
            default => null,
        };
        if ($scale === null) {
            return null;
        }
        $nano = (int) round($number * $scale);

        return $nano > 0 ? $nano : null;
    }

    private static function decimalString(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }

            return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
        }
        if (!is_string($value)) {
            return null;
        }
        $text = str_replace([' ', ' '], '', trim($value));
        if ($text === '') {
            return null;
        }
        if (str_contains($text, ',') && str_contains($text, '.')) {
            if (strrpos($text, ',') > strrpos($text, '.')) {
                $text = str_replace('.', '', $text);
                $text = str_replace(',', '.', $text);
            } else {
                $text = str_replace(',', '', $text);
            }
        } else {
            $text = str_replace(',', '.', $text);
        }
        if (preg_match('/^\d+(?:\.\d+)?$/', $text) !== 1) {
            return null;
        }

        return $text;
    }

    private static function decimalToNano(string $decimal, string $unit): ?int
    {
        $shift = match ($unit) {
            't'  => 6,
            'kg' => 3,
            'g'  => 0,
            'mg' => -3,
            'ug' => -6,
            default => null,
        };
        if ($shift === null || preg_match('/^(\d+)(?:\.(\d+))?$/', $decimal, $match) !== 1) {
            return null;
        }
        $scale = 9;
        $frac = substr(str_pad($match[2] ?? '', $scale, '0'), 0, $scale);
        $digits = ltrim($match[1] . $frac, '0');
        if ($digits === '') {
            return 0;
        }
        if ($shift >= 0) {
            $digits .= str_repeat('0', $shift);
        } else {
            $cut = -$shift;
            if (strlen($digits) <= $cut) {
                $digits = '0';
            } else {
                $digits = substr($digits, 0, -$cut);
            }
        }
        if ($digits === '' || strlen($digits) > 18) {
            return $digits === '' ? 0 : null;
        }

        return (int) $digits;
    }

    private static function gramsToNano(float $grams): ?int
    {
        if (!is_finite($grams) || $grams <= 0) {
            return null;
        }

        return (int) round($grams * 1000000000);
    }

    /**
     * @return array{value:null,unit:null,unresolved:true,original:?string}
     */
    private static function unresolvedMeasure(?string $original): array
    {
        return [
            'value'      => null,
            'unit'       => null,
            'unresolved' => true,
            'original'   => $original,
        ];
    }

    private static function originalMeasure(mixed $value, mixed $unit): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $unitText = is_string($unit) ? trim($unit) : '';

        return $unitText !== '' ? $text . ' ' . $unitText : $text;
    }

    /**
     * Boş marka listesi markayı kaldırır. Diğer alanlar yalnız remove_constraints ile kalkar.
     *
     * @param array<string, mixed> $raw
     * @return list<string>
     */
    private static function explicitClears(array $raw): array
    {
        $out = [];
        if (array_key_exists('brands', $raw) && ($raw['brands'] === null || $raw['brands'] === [])) {
            $out[] = 'brands';
        }
        if (array_key_exists('brand', $raw) && ($raw['brand'] === null || $raw['brand'] === '')) {
            $out[] = 'brand';
        }

        return $out;
    }

    private static function nearEnough(float $grams, float $target, float $ratio, float $abs): bool
    {
        $diff = abs($grams - $target);

        return $diff <= $abs || ($target > 0 && $diff / $target <= $ratio);
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $filters
     */
    private static function matchesCatalogFacets(array $product, array $filters): bool
    {
        if (is_bool($filters['commercial_approval'] ?? null)) {
            $approved = $filters['commercial_approval'] === true;
            if (!self::productCommercialFacet($product, $approved)) {
                return false;
            }
        }
        $calibration = is_string($filters['calibration'] ?? null) ? $filters['calibration'] : '';
        if ($calibration !== '' && !self::productTokenFacet($product, ['kalibrasyon-tipi', 'hassasiyet-kalibrasyon'], $calibration, self::calibrationExcludes($calibration))) {
            return false;
        }
        $communication = is_string($filters['communication'] ?? null) ? $filters['communication'] : '';
        if ($communication !== '' && !self::productTokenFacet($product, ['haberlesme', 'baglanti'], $communication, [])) {
            return false;
        }
        if (($filters['condition'] ?? null) === 'new' && !self::productHasSlug($product, 'urun-durumu', product_new_condition_filter_slug())) {
            return false;
        }
        if (($filters['condition'] ?? null) === 'renewed' && !product_tag_slugs_include_renewed(is_array($product['tag_slugs'] ?? null) ? $product['tag_slugs'] : [])) {
            return false;
        }
        foreach ($filters['facets'] ?? [] as $facet) {
            if (!is_array($facet)) {
                continue;
            }
            if (!self::productTokenFacet($product, [(string) ($facet['group'] ?? '')], (string) ($facet['token'] ?? ''), [])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private static function scopeProducts(array $filters): array
    {
        $out = [];
        foreach (AssistantV2Catalog::products() as $product) {
            if (self::inListingScope($product, $filters)) {
                $out[] = $product;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $filters
     */
    private static function inListingScope(array $product, array $filters): bool
    {
        $sub = $filters['subcategory'] ?? null;
        $cat = $filters['category'] ?? null;
        if (is_string($sub) && $sub !== '') {
            if (!AssistantV2Catalog::inGroup($product, $sub, true) && !AssistantV2Catalog::inGroup($product, $sub, false)) {
                return false;
            }
        } elseif (is_string($cat) && $cat !== '') {
            if (!AssistantV2Catalog::inGroup($product, $cat, false)) {
                return false;
            }
        }
        $brands = is_array($filters['brands'] ?? null) ? $filters['brands'] : [];
        if ($brands !== []) {
            $have = AssistantV2Catalog::fold((string) ($product['brand'] ?? ''));
            $ok = false;
            foreach ($brands as $brand) {
                if (AssistantV2Catalog::fold((string) $brand) === $have) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<string>
     */
    private static function slugsForCommercial(array $products, bool $approved): array
    {
        $slugs = [];
        foreach ($products as $product) {
            foreach (self::groupOptions($product, 'ticari-onayli') as $option) {
                $hay = self::compactFold((string) ($option['name'] ?? '') . ' ' . (string) ($option['slug'] ?? ''));
                $hit = $approved
                    ? (str_contains($hay, 'onayli') && !str_contains($hay, 'onaysiz'))
                    : str_contains($hay, 'onaysiz');
                $slug = (string) ($option['slug'] ?? '');
                if ($hit && $slug !== '') {
                    $slugs[$slug] = $slug;
                }
            }
        }

        return array_values($slugs);
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param list<string> $groups
     * @param list<string> $excludes
     * @return list<string>
     */
    private static function slugsForToken(array $products, array $groups, string $token, array $excludes): array
    {
        $slugs = [];
        foreach ($products as $product) {
            foreach ($groups as $group) {
                foreach (self::groupOptions($product, $group) as $option) {
                    if (!self::optionHits($option, $token, $excludes)) {
                        continue;
                    }
                    $slug = (string) ($option['slug'] ?? '');
                    if ($slug !== '') {
                        $slugs[$slug] = $slug;
                    }
                }
            }
        }

        return array_values($slugs);
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function renewedTagInScope(array $products): ?string
    {
        $found = [];
        foreach ($products as $product) {
            foreach (is_array($product['tag_slugs'] ?? null) ? $product['tag_slugs'] : [] as $slug) {
                if (is_string($slug) && \App\Models\ProductTag::isRenewedSlug($slug)) {
                    $found[$slug] = true;
                }
            }
        }
        foreach (\App\Models\ProductTag::RENEWED_SLUGS as $slug) {
            if (isset($found[$slug])) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function productCommercialFacet(array $product, bool $approved): bool
    {
        foreach (self::groupOptions($product, 'ticari-onayli') as $option) {
            $hay = self::compactFold((string) ($option['name'] ?? '') . ' ' . (string) ($option['slug'] ?? ''));
            if ($approved && str_contains($hay, 'onayli') && !str_contains($hay, 'onaysiz')) {
                return true;
            }
            if (!$approved && str_contains($hay, 'onaysiz')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $product
     * @param list<string> $groups
     * @param list<string> $excludes
     */
    private static function productTokenFacet(array $product, array $groups, string $token, array $excludes): bool
    {
        foreach ($groups as $group) {
            foreach (self::groupOptions($product, $group) as $option) {
                if (self::optionHits($option, $token, $excludes)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function productHasSlug(array $product, string $group, string $slug): bool
    {
        foreach (self::groupOptions($product, $group) as $option) {
            if ((string) ($option['slug'] ?? '') === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $product
     * @return list<array<string, mixed>>
     */
    private static function groupOptions(array $product, string $group): array
    {
        $listing = is_array($product['listing_filters'] ?? null) ? $product['listing_filters'] : [];
        $options = $listing[$group] ?? [];

        return is_array($options) ? array_values(array_filter($options, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $option
     * @param list<string> $excludes
     */
    private static function optionHits(array $option, string $token, array $excludes): bool
    {
        $hay = self::compactFold((string) ($option['name'] ?? '') . ' ' . (string) ($option['slug'] ?? ''));
        $token = self::compactFold($token);
        if ($token === '' || !str_contains($hay, $token)) {
            return false;
        }
        foreach ($excludes as $exclude) {
            $exclude = self::compactFold($exclude);
            if ($exclude !== '' && str_contains($hay, $exclude)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function calibrationExcludes(string $token): array
    {
        $token = self::compactFold($token);
        if ($token === 'otomatik') {
            return ['yari', 'dahili'];
        }

        return [];
    }

    private static function compactFold(string $value): string
    {
        return str_replace(['-', ' ', '_', '.'], '', AssistantV2Catalog::fold($value));
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private static function overlay(array $filters, array $patch): array
    {
        foreach (['category', 'subcategory', 'commercial_approval', 'calibration', 'communication', 'condition', 'series'] as $key) {
            if (array_key_exists($key, $patch) && $patch[$key] !== null && $patch[$key] !== '') {
                $filters[$key] = $patch[$key];
            }
        }
        if (isset($patch['precision']) && is_array($patch['precision'])) {
            $filters['precision'] = $patch['precision'];
        }
        if (isset($patch['capacity']) && is_array($patch['capacity'])) {
            $filters['capacity'] = $patch['capacity'];
        }
        if (isset($patch['brands']) && is_array($patch['brands']) && $patch['brands'] !== []) {
            $filters['brands'] = array_values($patch['brands']);
        }

        return self::normalizeState($filters);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function clear(array $filters, string $field): array
    {
        $map = [
            'category' => 'category', 'subcategory' => 'subcategory', 'brands' => 'brands', 'brand' => 'brands',
            'precision' => 'precision', 'capacity' => 'capacity', 'commercial_approval' => 'commercial_approval',
            'calibration' => 'calibration', 'communication' => 'communication', 'condition' => 'condition',
            'series' => 'series',
        ];
        if (!isset($map[$field])) {
            return $filters;
        }
        $blank = self::blank();
        $filters[$map[$field]] = $blank[$map[$field]];

        return $filters;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function normalizeState(array $filters): array
    {
        $blank = self::blank();
        $filters = array_merge($blank, $filters);
        $filters['brands'] = array_values(array_filter(is_array($filters['brands']) ? $filters['brands'] : [], 'is_string'));
        if (!is_array($filters['precision'])) {
            $filters['precision'] = $blank['precision'];
        }
        if (!is_array($filters['capacity'])) {
            $filters['capacity'] = $blank['capacity'];
        }
        if (!is_bool($filters['commercial_approval']) && $filters['commercial_approval'] !== null) {
            $filters['commercial_approval'] = null;
        }
        $calibration = is_string($filters['calibration'] ?? null) ? trim($filters['calibration']) : '';
        $filters['calibration'] = ($calibration !== '' && mb_strlen($calibration) <= 80) ? $calibration : null;
        $communication = is_string($filters['communication'] ?? null) ? self::compactFold($filters['communication']) : '';
        $filters['communication'] = ($communication !== '' && strlen($communication) <= 40) ? $communication : null;
        $filters['condition'] = in_array($filters['condition'] ?? null, ['new', 'renewed'], true) ? $filters['condition'] : null;
        $facets = [];
        foreach (is_array($filters['facets'] ?? null) ? $filters['facets'] : [] as $facet) {
            if (!is_array($facet)) {
                continue;
            }
            $group = is_string($facet['group'] ?? null) ? strtolower(trim($facet['group'])) : '';
            $token = is_string($facet['token'] ?? null) ? self::compactFold($facet['token']) : '';
            if (preg_match('/^[a-z0-9-]{2,40}$/', $group) !== 1 || $token === '' || strlen($token) > 40) {
                continue;
            }
            $facets[$group . "\0" . $token] = ['group' => $group, 'token' => $token];
        }
        $filters['facets'] = array_values($facets);
        $unsupported = [];
        foreach (is_array($filters['unsupported'] ?? null) ? $filters['unsupported'] : [] as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '' || in_array($item, $unsupported, true)) {
                continue;
            }
            $unsupported[] = $item;
        }
        $filters['unsupported'] = array_slice($unsupported, 0, 8);
        $series = is_string($filters['series'] ?? null) ? AssistantV2Catalog::resolveSeries($filters['series']) : null;
        $filters['series'] = $series;
        $capacitySlug = is_string($filters['capacity_slug'] ?? null) ? strtolower(trim($filters['capacity_slug'])) : '';
        $filters['capacity_slug'] = preg_match('/^[a-z0-9-]{2,80}$/', $capacitySlug) === 1 ? $capacitySlug : null;
        $token = is_string($filters['type_token'] ?? null) ? AssistantV2Catalog::fold($filters['type_token']) : '';
        $filters['type_token'] = $token !== '' ? $token : null;
        $form = $filters['moisture_form'] ?? null;
        $filters['moisture_form'] = in_array($form, ['liquid', 'solid'], true) ? $form : null;
        $use = is_string($filters['moisture_use'] ?? null) ? trim($filters['moisture_use']) : '';
        $filters['moisture_use'] = ($use !== '' && mb_strlen($use) <= 120) ? $use : null;
        $temp = $filters['moisture_temp'] ?? null;
        $filters['moisture_temp'] = (is_numeric($temp) && (int) $temp >= 40 && (int) $temp <= 400) ? (int) $temp : null;

        return $filters;
    }

    /**
     * Kullanıcı kullanım alanı söylemediyse tahmin edilen kategori durmaz.
     * Alan sorusunun cevabı önceki markayı ve türü korur.
     *
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $previous
     * @param list<string> $asked
     * @return array<string, mixed>
     */
    public static function alignUsage(array $filters, array $previous, string $message, bool $replaced, array $asked): array
    {
        $filters = self::normalizeState($filters);
        $previous = self::normalizeState($previous);
        $token = AssistantV2Catalog::ambiguousTypeToken($message);
        $scaleType = AssistantV2Catalog::statedLabScaleType($message);
        $stated = AssistantV2Catalog::statedUsage($message);
        $answering = $stated !== null && in_array('usage', $asked, true);
        $previousToken = is_string($previous['type_token'] ?? null) ? $previous['type_token'] : null;
        if ($token !== null) {
            $filters['type_token'] = $token;
        } elseif ($scaleType !== null && AssistantV2Catalog::labScaleDiscovery($message)) {
            $boot = AssistantV2Catalog::labScaleFilters($message);
            if (is_string($boot['type_token'] ?? null) && ($boot['type_token'] ?? '') !== '') {
                $filters['type_token'] = $boot['type_token'];
            }
        } elseif ($answering || !$replaced) {
            $filters['type_token'] = $previousToken;
        } else {
            $filters['type_token'] = null;
        }

        if ($stated !== null) {
            $filters['category'] = $stated;
            $sub = AssistantV2Catalog::matchingChild($stated, $message);
            if ($sub === null && $scaleType !== null && $stated === 'laboratuvar') {
                $boot = AssistantV2Catalog::labScaleFilters($message);
                if (is_string($boot['subcategory'] ?? null) && ($boot['subcategory'] ?? '') !== '') {
                    $sub = (string) $boot['subcategory'];
                }
            }
            if ($sub === null) {
                $type = is_string($filters['type_token'] ?? null) ? $filters['type_token'] : '';
                $sub = $type !== '' ? AssistantV2Catalog::childForType($stated, $type) : null;
            }
            if ($sub === null) {
                $node = AssistantV2Catalog::resolveGroup($message);
                if (
                    $node !== null
                    && $node['children'] === 0
                    && $node['slug'] !== $stated
                    && AssistantV2Catalog::usageSlug($node) === $stated
                ) {
                    $sub = $node['slug'];
                }
            }
            $filters['subcategory'] = $sub;

            return self::normalizeState($filters);
        }

        $parent = is_string($previous['category'] ?? null) ? $previous['category'] : '';
        if ($parent !== '' && !$replaced) {
            $named = AssistantV2Catalog::matchingChild($parent, $message);
            if ($named !== null) {
                $filters['category'] = $parent;
                $filters['subcategory'] = $named;

                return self::normalizeState($filters);
            }
        }

        $child = AssistantV2Catalog::statedChild($message);
        if ($child !== null) {
            $filters['category'] = $child['category'];
            $filters['subcategory'] = $child['subcategory'];

            return self::normalizeState($filters);
        }

        $filters['category'] = $replaced ? null : $previous['category'];
        $filters['subcategory'] = $replaced ? null : $previous['subcategory'];

        return self::normalizeState($filters);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private static function usageChosen(array $filters): bool
    {
        $category = is_string($filters['category'] ?? null) ? $filters['category'] : '';
        if ($category !== '') {
            $node = AssistantV2Catalog::node($category);
            if ($node !== null && ($node['parent'] === '' || AssistantV2Catalog::usageSlug($node) !== '')) {
                return true;
            }
        }
        $subcategory = is_string($filters['subcategory'] ?? null) ? $filters['subcategory'] : '';
        if ($subcategory !== '') {
            $node = AssistantV2Catalog::node($subcategory);

            return $node !== null;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function matchesType(array $product, string $token): bool
    {
        foreach (is_array($product['categories'] ?? null) ? $product['categories'] : [] as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $hay = AssistantV2Catalog::fold((string) ($cat['name'] ?? '') . ' ' . str_replace('-', ' ', (string) ($cat['slug'] ?? '')));
            if ($hay !== '' && AssistantV2Catalog::typeTokenMatchesFamily($hay, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{field:?string,direction:?string}
     */
    public static function rankingFromPlan(array $plan): array
    {
        $args = is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [];
        $name = AssistantV2Catalog::fold((string) ($args['ranking'] ?? $args['ranking_field'] ?? $plan['ranking'] ?? ''));
        $map = [
            'cheapest' => ['price', 'asc'],
            'most_expensive' => ['price', 'desc'],
            'expensive' => ['price', 'desc'],
            'most_precise' => ['precision', 'asc'],
            'highest_capacity' => ['capacity', 'desc'],
            'lowest_capacity' => ['capacity', 'asc'],
            'price_asc' => ['price', 'asc'],
            'price_desc' => ['price', 'desc'],
        ];
        if (isset($map[$name])) {
            return ['field' => $map[$name][0], 'direction' => $map[$name][1]];
        }
        $field = self::rankField((string) ($args['field'] ?? ''));
        $direction = self::rankDirection((string) ($args['direction'] ?? ''));
        if ($field === null || $direction === null) {
            return self::blankRanking();
        }

        return ['field' => $field, 'direction' => $direction];
    }

    private static function rankField(string $field): ?string
    {
        return in_array($field, ['price', 'precision', 'capacity'], true) ? $field : null;
    }

    private static function rankDirection(string $direction): ?string
    {
        return in_array($direction, ['asc', 'desc'], true) ? $direction : null;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    private static function sortCatalog(array $products): array
    {
        return self::storefrontSort($products, []);
    }

    /**
     * Vitrin sırası: kategori sırası, products.order, sonra marka dağılımı.
     *
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private static function storefrontSort(array $products, array $filters): array
    {
        $slug = '';
        if (is_string($filters['subcategory'] ?? null) && $filters['subcategory'] !== '') {
            $slug = $filters['subcategory'];
        } elseif (is_string($filters['category'] ?? null)) {
            $slug = $filters['category'];
        }
        usort($products, static function (array $a, array $b) use ($slug): int {
            $ao = self::catOrder($a, $slug);
            $bo = self::catOrder($b, $slug);
            if ($ao !== $bo) {
                return $ao <=> $bo;
            }
            $order = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));

            return $order !== 0 ? $order : ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });
        if (function_exists('catalog_products_brand_interleave')) {
            $products = catalog_products_brand_interleave($products);
        }

        return $products;
    }

    /**
     * Seçilen yükün grubu önde, daha yüksek gerçek kapasiteler arkada. Grup içi vitrin sırasıdır.
     *
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private static function orderByLoadGroups(array $products, array $filters, float $target): array
    {
        $groups = [];
        foreach ($products as $product) {
            $grams = self::capacityGrams($product);
            if ($grams === null || !self::capacityCoversLoad($grams, $target)) {
                continue;
            }
            $key = (int) round($grams);
            $groups[$key][] = $product;
        }
        if ($groups === []) {
            return [];
        }
        $keys = array_keys($groups);
        usort($keys, static function (int $a, int $b) use ($target): int {
            $ae = abs($a - $target) < 0.6;
            $be = abs($b - $target) < 0.6;
            if ($ae !== $be) {
                return $ae ? -1 : 1;
            }

            return $a <=> $b;
        });
        $out = [];
        foreach ($keys as $key) {
            foreach (self::storefrontSort($groups[$key], $filters) as $product) {
                $out[] = $product;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function catOrder(array $product, string $slug): int
    {
        $orders = is_array($product['category_orders'] ?? null) ? $product['category_orders'] : [];
        if ($slug !== '' && isset($orders[$slug]) && (int) $orders[$slug] > 0) {
            return (int) $orders[$slug];
        }

        return 1000000 + (int) ($product['sort_order'] ?? 0);
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function hasCapacitySlug(array $product, string $slug): bool
    {
        foreach (is_array($product['capacity_filters'] ?? null) ? $product['capacity_filters'] : [] as $filter) {
            if (is_array($filter) && (string) ($filter['slug'] ?? '') === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mesaj, bu kümenin vitrin kapasite filtresiyse slug döner.
     *
     * @param array<string, mixed> $filters
     */
    public static function capacityFacetSlug(array $filters, string $message): ?string
    {
        $filters = self::normalizeState($filters);
        $filters['capacity'] = self::blank()['capacity'];
        $filters['capacity_slug'] = null;
        $fold = AssistantV2Catalog::fold($message);
        if ($fold === '') {
            return null;
        }
        $found = null;
        foreach (AssistantV2Catalog::products() as $product) {
            if (!self::matchesCore($product, $filters)) {
                continue;
            }
            foreach (is_array($product['capacity_filters'] ?? null) ? $product['capacity_filters'] : [] as $filter) {
                if (!is_array($filter)) {
                    continue;
                }
                $name = AssistantV2Catalog::fold((string) ($filter['name'] ?? ''));
                $slug = strtolower(trim((string) ($filter['slug'] ?? '')));
                if ($name !== '' && $name === $fold && preg_match('/^[a-z0-9-]{2,80}$/', $slug) === 1) {
                    $found = $slug;
                    break 2;
                }
            }
        }

        return $found;
    }

    /**
     * Çip, vitrin kovasından değil Kapasite / Kapasite (Maks.) ve Hassasiyet satırından gelir.
     *
     * @param array<string, mixed> $product
     * @param array<string, array{slug:string,grams:float,label:string,order:int,count:int}> $capacities
     * @param array<string, array{grams:float,unit:string,label:string,count:int}> $precisions
     */
    private static function collectSpecMeasures(array $product, array &$capacities, array &$precisions): void
    {
        $seenCap = [];
        $seenPrec = [];
        foreach (is_array($product['specs'] ?? null) ? $product['specs'] : [] as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $label = trim((string) ($spec['label'] ?? ''));
            $value = trim((string) ($spec['value'] ?? ''));
            $kind = self::specMeasureKind($label);
            if ($kind === null || $value === '' || self::measureRangeLabel($value)) {
                continue;
            }
            if (AssistantV2Prompt::conversational() && preg_match('/^\d+,\d+\s*(?:kg|mg|g|ct)?$/iu', $value) === 1) {
                // 0,001g ve 0.001g aynı değerdir, çip tekrarlanmasın.
                $value = str_replace(',', '.', $value);
            }
            if ($kind === 'kapasite') {
                if (isset($seenCap[$value])) {
                    continue;
                }
                $seenCap[$value] = true;
                $grams = self::leadingGrams($value);
                if (!isset($capacities[$value])) {
                    $capacities[$value] = [
                        'slug' => '',
                        'grams' => $grams ?? 0.0,
                        'label' => $value,
                        'order' => $grams === null ? 100000000 : (int) round($grams * 1000),
                        'count' => 0,
                    ];
                }
                $capacities[$value]['count']++;
                continue;
            }
            if (isset($seenPrec[$value])) {
                continue;
            }
            $seenPrec[$value] = true;
            $grams = self::leadingGrams($value);
            if (!isset($precisions[$value])) {
                $precisions[$value] = [
                    'grams' => $grams ?? 1000.0,
                    'unit' => 'g',
                    'label' => $value,
                    'count' => 0,
                ];
            }
            $precisions[$value]['count']++;
        }
    }

    private static function specMeasureKind(string $label): ?string
    {
        $fold = AssistantV2Catalog::fold($label);
        if ($fold === '') {
            return null;
        }
        if (str_contains($fold, 'kapasite') && !str_contains($fold, 'yogunluk')) {
            return 'kapasite';
        }
        if (
            ($fold === 'hassasiyet' || str_starts_with($fold, 'hassasiyet '))
            && !str_contains($fold, 'kalibrasyon')
        ) {
            return 'hassasiyet';
        }

        return null;
    }

    public static function measureRangeLabel(string $value): bool
    {
        return preg_match_all('/\d+(?:[.,]\d+)?\s*(?:kg|mg|g)\b/iu', $value) >= 2;
    }

    private static function leadingGrams(string $value): ?float
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(kilogram|miligram|gram|µg|μg|ug|kg|mg|gr|g)\b/iu', $value, $match) !== 1) {
            return null;
        }

        return self::toGrams(str_replace(',', '.', (string) $match[1]), self::massUnit((string) $match[2], false));
    }

    /**
     * @param array<string, array{grams:float,unit:string,label:string,count:int}> $bucket
     */
    private static function collectPrecision(array $product, array &$bucket): void
    {
        $seen = [];
        $add = static function (?float $grams, ?string $unit, string $label) use (&$bucket, &$seen): void {
            if ($grams === null || $grams <= 0 || ($unit !== 'g' && $unit !== 'ct')) {
                return;
            }
            $key = $unit . ':' . rtrim(rtrim(number_format($grams, 6, '.', ''), '0'), '.');
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            if (!isset($bucket[$key])) {
                $bucket[$key] = ['grams' => $grams, 'unit' => $unit, 'label' => $label !== '' ? $label : $key, 'count' => 0];
            }
            $bucket[$key]['count']++;
        };
        foreach ($product['precision_filters'] ?? [] as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $add(
                is_numeric($filter['grams'] ?? null) ? (float) $filter['grams'] : null,
                is_string($filter['unit'] ?? null) ? $filter['unit'] : 'g',
                (string) ($filter['label'] ?? '')
            );
        }
    }

    public static function formatGrams(float $grams): string
    {
        if ($grams >= 1000 && abs($grams / 1000 - round($grams / 1000)) < 0.001) {
            return (string) (int) round($grams / 1000) . ' kg';
        }
        if (abs($grams - round($grams)) < 0.05) {
            return (string) (int) round($grams) . ' g';
        }

        return rtrim(rtrim(number_format($grams, 2, '.', ''), '0'), '.') . ' g';
    }

    public static function capacityLabelIsRange(string $message): bool
    {
        return preg_match_all('/\d+(?:[.,]\d+)?\s*(?:kg|mg|g)\b/iu', $message) >= 2;
    }

    public static function rangeFloorGrams(string $message): ?float
    {
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(kg|mg|g)\b/iu', $message, $matches, PREG_SET_ORDER) < 2) {
            return null;
        }
        $vals = [];
        foreach ($matches as $match) {
            $value = (float) str_replace(',', '.', (string) $match[1]);
            $grams = self::toGrams($value, strtolower((string) $match[2]));
            if ($grams !== null && $grams >= 1) {
                $vals[] = $grams;
            }
        }
        if (count($vals) < 2) {
            return null;
        }

        return min($vals);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function isMoistureGroup(array $filters): bool
    {
        foreach (['subcategory', 'category'] as $key) {
            $slug = $filters[$key] ?? null;
            if (!is_string($slug) || $slug === '') {
                continue;
            }
            $node = AssistantV2Catalog::node($slug);
            if ($node !== null && AssistantV2Catalog::kindOfNode($node) === 'nem') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    public static function moistureAudiences(array $filters): array
    {
        $filters = self::normalizeState($filters);
        $filters['moisture_use'] = null;
        $filters['moisture_temp'] = null;
        $seen = [];
        $out = [];
        foreach (self::moistureProducts($filters) as $product) {
            foreach (is_array($product['audiences'] ?? null) ? $product['audiences'] : [] as $line) {
                if (!is_string($line)) {
                    continue;
                }
                $line = trim($line);
                $key = AssistantV2Catalog::fold($line);
                if ($line === '' || $key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<int>
     */
    public static function moistureTemps(array $filters): array
    {
        $filters = self::normalizeState($filters);
        $filters['moisture_temp'] = null;
        $seen = [];
        foreach (self::applyMoisture(self::moistureProducts($filters), $filters) as $product) {
            $temp = $product['drying_c'] ?? null;
            if (!is_numeric($temp)) {
                continue;
            }
            $celsius = (int) $temp;
            if ($celsius >= 40 && $celsius <= 400) {
                $seen[$celsius] = $celsius;
            }
        }
        $temps = array_values($seen);
        sort($temps, SORT_NUMERIC);

        return $temps;
    }

    /**
     * @param array<string, mixed> $product
     * @return list<array{label:string,value:string}>
     */
    public static function relevantSpecs(array $product, string $question): array
    {
        $focus = self::specFocus($question);
        $out = [];
        $seen = [];
        foreach (is_array($product['specs'] ?? null) ? $product['specs'] : [] as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $label = trim((string) ($spec['label'] ?? ''));
            $value = trim((string) ($spec['value'] ?? ''));
            $kind = self::specKind($label);
            if ($label === '' || $value === '' || $kind === null || !in_array($kind, $focus, true)) {
                continue;
            }
            $key = AssistantV2Catalog::fold($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['label' => $label, 'value' => $value];
        }
        $communication = $product['communication'] ?? '';
        if (is_array($communication)) {
            $communication = implode(', ', array_values(array_filter($communication, 'is_string')));
        }
        $extra = [
            'prec' => ['Hassasiyet', (string) ($product['precision_label'] ?? '')],
            'cap'  => ['Kapasite', (string) ($product['capacity_label'] ?? '')],
            'cal'  => ['Kalibrasyon', (string) ($product['calibration'] ?? '')],
            'comm' => ['Haberleşme', trim((string) $communication)],
            'temp' => ['Maksimum Kurutma Sıcaklığı', is_numeric($product['drying_c'] ?? null) ? ((int) $product['drying_c']) . '°C' : ''],
        ];
        foreach ($extra as $kind => $pair) {
            if (!in_array($kind, $focus, true) || $pair[1] === '' || isset($seen[AssistantV2Catalog::fold($pair[0])])) {
                continue;
            }
            $covered = false;
            foreach ($out as $row) {
                if (self::specKind((string) $row['label']) === $kind) {
                    $covered = true;
                    break;
                }
            }
            if ($covered) {
                continue;
            }
            $out[] = ['label' => $pair[0], 'value' => $pair[1]];
        }

        return array_slice($out, 0, 8);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private static function moistureProducts(array $filters): array
    {
        $out = [];
        foreach (AssistantV2Catalog::products() as $product) {
            if (!self::matchesCore($product, $filters)) {
                continue;
            }
            if (AssistantV2Catalog::kindOf($product) !== 'nem') {
                continue;
            }
            $out[] = $product;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private static function applyMoisture(array $products, array $filters): array
    {
        $use = is_string($filters['moisture_use'] ?? null) ? AssistantV2Catalog::fold($filters['moisture_use']) : '';
        $temp = is_numeric($filters['moisture_temp'] ?? null) ? (int) $filters['moisture_temp'] : null;
        $out = [];
        foreach ($products as $product) {
            if (AssistantV2Catalog::kindOf($product) !== 'nem') {
                continue;
            }
            if ($use !== '') {
                $hit = false;
                foreach (is_array($product['audiences'] ?? null) ? $product['audiences'] : [] as $line) {
                    if (is_string($line) && AssistantV2Catalog::fold($line) === $use) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
            }
            if ($temp !== null) {
                $have = $product['drying_c'] ?? null;
                if (!is_numeric($have) || (int) $have < $temp) {
                    continue;
                }
            }
            $out[] = $product;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private static function sortMoisture(array $products, array $filters): array
    {
        $temp = is_numeric($filters['moisture_temp'] ?? null) ? (int) $filters['moisture_temp'] : null;
        $slug = self::activeGroup($filters);
        if (($filters['moisture_form'] ?? '') === 'liquid') {
            usort($products, static function (array $a, array $b) use ($temp, $slug): int {
                $rank = self::precisionRank($a) <=> self::precisionRank($b);
                if ($rank !== 0) {
                    return $rank;
                }
                if ($temp !== null) {
                    $distance = self::tempDistance($a, $temp) <=> self::tempDistance($b, $temp);
                    if ($distance !== 0) {
                        return $distance;
                    }
                }

                return self::catOrder($a, $slug) <=> self::catOrder($b, $slug);
            });

            return $products;
        }
        $groups = [];
        foreach ($products as $product) {
            $have = $product['drying_c'] ?? null;
            $key = is_numeric($have) ? (int) $have : 0;
            $groups[$key][] = $product;
        }
        $keys = array_keys($groups);
        usort($keys, static function (int $a, int $b) use ($temp): int {
            if ($temp === null) {
                return $a <=> $b;
            }
            $ad = $a === 0 ? 10000 : abs($a - $temp);
            $bd = $b === 0 ? 10000 : abs($b - $temp);
            if ($ad !== $bd) {
                return $ad <=> $bd;
            }

            return $a <=> $b;
        });
        $out = [];
        foreach ($keys as $key) {
            foreach (self::storefrontSort($groups[$key], $filters) as $product) {
                $out[] = $product;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function precisionRank(array $product): float
    {
        $grams = $product['precision_grams'] ?? null;
        if (!is_numeric($grams) || (string) ($product['precision_unit'] ?? '') !== 'g' || (float) $grams <= 0) {
            return 1000000000.0;
        }

        return (float) $grams;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function tempDistance(array $product, int $target): int
    {
        $have = $product['drying_c'] ?? null;
        if (!is_numeric($have)) {
            return 10000;
        }

        return abs((int) $have - $target);
    }

    /**
     * @return list<string>
     */
    private static function specFocus(string $question): array
    {
        $fold = AssistantV2Catalog::fold($question);
        $map = [
            'temp' => str_contains($fold, 'sicaklik') || str_contains($fold, 'kurutma'),
            'prec' => str_contains($fold, 'hassasiyet') || str_contains($fold, 'okunabilir'),
            'cap'  => str_contains($fold, 'kapasite'),
            'cal'  => str_contains($fold, 'kalibr'),
            'rep'  => str_contains($fold, 'tekrar'),
            'comm' => str_contains($fold, 'haberles') || str_contains($fold, 'baglanti') || str_contains($fold, 'rs232'),
            'mode' => str_contains($fold, 'tartim'),
            'type' => str_contains($fold, 'urun tipi') || str_contains($fold, 'tipi'),
        ];
        $picked = [];
        foreach ($map as $kind => $hit) {
            if ($hit) {
                $picked[] = $kind;
            }
        }
        if ($picked !== []) {
            return $picked;
        }

        return ['prec', 'cap', 'cal', 'temp', 'rep', 'comm', 'mode', 'type'];
    }

    private static function specKind(string $label): ?string
    {
        $fold = AssistantV2Catalog::fold($label);
        if ($fold === '') {
            return null;
        }
        if (str_contains($fold, 'sicaklik') && !str_contains($fold, 'sure')) {
            return 'temp';
        }
        if (str_contains($fold, 'tekrarlan')) {
            return 'rep';
        }
        if (str_contains($fold, 'haberles') || str_contains($fold, 'iletisim') || str_contains($fold, 'rs232') || str_contains($fold, 'bluetooth') || $fold === 'usb') {
            return 'comm';
        }
        if (str_contains($fold, 'tartim mod') || $fold === 'tartim modu' || str_contains($fold, 'tartim tipi')) {
            return 'mode';
        }
        if (str_contains($fold, 'kalibr')) {
            return 'cal';
        }
        if ((str_contains($fold, 'hassasiyet') || str_contains($fold, 'okunabilir')) && !str_contains($fold, 'yogunluk')) {
            return 'prec';
        }
        if (str_contains($fold, 'kapasite') && !str_contains($fold, 'yogunluk')) {
            return 'cap';
        }
        if ($fold === 'urun tipi' || $fold === 'urun turu' || str_starts_with($fold, 'urun tipi')) {
            return 'type';
        }

        return null;
    }
}
