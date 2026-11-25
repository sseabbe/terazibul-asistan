<?php

declare(strict_types=1);

namespace App\Services\ProductAssistant;

/**
 * Niyeti gerçek ürün kayıtlarına uygular. Eşleşmeyen veya alanı boş ürün elenir.
 */
final class ProductAssistantSearch
{
    /**
     * @param array<string, mixed> $intent
     * @param list<int> $previousIds
     * @return array{products:list<array<string,mixed>>,total:int,more_url:?string}
     */
    public static function find(array $intent, array $previousIds = [], int $offset = 0): array
    {
        $catalog = ProductAssistantCatalog::products();
        $mode = (string) ($intent['mode'] ?? 'recommend');

        if (($intent['reference'] ?? null) !== null) {
            return self::fromReference($catalog, $intent, $previousIds);
        }

        if ($mode === 'compare' && $previousIds !== [] && ($intent['brand_slugs'] ?? []) === [] && ($intent['category'] ?? null) === null) {
            $pool = self::onlyIds($catalog, $previousIds);

            return self::pack($pool, $intent, count($pool));
        }

        if ($mode === 'min_weight') {
            $product = self::resolveNamed($catalog, $intent, $previousIds);

            return self::pack($product !== null ? [$product] : [], $intent, $product !== null ? 1 : 0);
        }

        $matched = self::catalogMatches($intent);

        if ($mode === 'compare' && ($intent['brand_slugs'] ?? []) !== []) {
            $matched = self::perBrand($matched, $intent['brand_slugs'], 2);
        }

        $total = count($matched);
        $intent['precision_filter_slugs'] = self::precisionFilterSlugs($matched, $intent);
        $offset = max(0, $offset);
        $packed = self::pack(array_slice($matched, $offset, 4), $intent, $total, false, $offset);
        $packed['matched'] = $matched;

        return $packed;
    }

    /**
     * Yayınlanmış katalogda niyete uyan tüm ürünler. Kart dilimi değildir.
     *
     * @param array<string, mixed> $intent
     * @return list<array<string, mixed>>
     */
    public static function catalogMatches(array $intent): array
    {
        $catalog = ProductAssistantCatalog::products();
        $matched = [];
        foreach ($catalog as $product) {
            $score = self::score($product, $intent);
            if ($score === null) {
                continue;
            }
            $product['_score'] = $score;
            $matched[] = $product;
        }

        usort($matched, static function (array $a, array $b): int {
            $score = ($b['_score'] <=> $a['_score']);
            if ($score !== 0) {
                return $score;
            }
            $order = ($a['sort_order'] <=> $b['sort_order']);
            if ($order !== 0) {
                return $order;
            }

            return ((int) $a['id']) <=> ((int) $b['id']);
        });

        $clean = [];
        foreach ($matched as $product) {
            unset($product['_score'], $product['_pos']);
            $clean[] = $product;
        }

        return $clean;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private static function onlyIds(array $catalog, array $ids): array
    {
        $byId = [];
        foreach ($catalog as $product) {
            $byId[(int) $product['id']] = $product;
        }

        $found = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($byId[$id])) {
                $found[] = $byId[$id];
            }
        }

        return array_slice($found, 0, 4);
    }

    /**
     * Son gösterilen ürünlerin dışına çıkmadan referansı çözer.
     *
     * @param list<array<string, mixed>> $catalog
     * @param array<string, mixed> $intent
     * @param list<int> $previousIds
     * @return array{products:list<array<string,mixed>>,total:int,more_url:?string,reference_miss:bool}
     */
    private static function fromReference(array $catalog, array $intent, array $previousIds): array
    {
        $pool = self::onlyIds($catalog, $previousIds);
        $picked = self::sliceReference($pool, (string) $intent['reference']);
        if ($picked === []) {
            return self::pack([], $intent, 0, true);
        }

        if ((string) $intent['reference'] !== 'set' || !self::hasConstraint($intent)) {
            return self::pack($picked, $intent, count($picked));
        }

        $matched = [];
        foreach ($picked as $position => $product) {
            $score = self::score($product, $intent);
            if ($score === null) {
                continue;
            }
            $product['_score'] = $score;
            $product['_pos'] = $position;
            $matched[] = $product;
        }

        usort($matched, static function (array $a, array $b): int {
            $score = ($b['_score'] <=> $a['_score']);
            if ($score !== 0) {
                return $score;
            }

            return ((int) $a['_pos']) <=> ((int) $b['_pos']);
        });

        return self::pack($matched, $intent, count($matched));
    }

    /**
     * @param list<array<string, mixed>> $pool
     * @return list<array<string, mixed>>
     */
    private static function sliceReference(array $pool, string $reference): array
    {
        if ($reference === 'set') {
            return $pool;
        }
        if ($reference === 'first') {
            return isset($pool[0]) ? [$pool[0]] : [];
        }
        if ($reference === 'other') {
            return isset($pool[1]) ? [$pool[1]] : [];
        }
        if (preg_match('/^index:(\d+)$/', $reference, $match) === 1) {
            $index = (int) $match[1];

            return isset($pool[$index]) ? [$pool[$index]] : [];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $intent
     */
    private static function hasConstraint(array $intent): bool
    {
        return ($intent['category'] ?? null) !== null
            || ($intent['brand_slugs'] ?? []) !== []
            || ($intent['precision'] ?? null) !== null
            || ($intent['capacity_target'] ?? null) !== null
            || ($intent['commercial'] ?? null) !== null
            || ($intent['calibration'] ?? null) !== null
            || ($intent['communication'] ?? []) !== []
            || ($intent['condition'] ?? null) !== null;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param array<string, mixed> $intent
     * @param list<int> $previousIds
     */
    private static function resolveNamed(array $catalog, array $intent, array $previousIds): ?array
    {
        if (!empty($intent['use_previous']) && $previousIds !== []) {
            foreach ($catalog as $product) {
                if ((int) $product['id'] === (int) $previousIds[0]) {
                    return $product;
                }
            }
        }

        $best = null;
        $bestScore = 0;
        foreach ($catalog as $product) {
            $score = self::nameScore($product, $intent);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $product;
            }
        }

        return $bestScore >= 8 ? $best : null;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $intent
     */
    private static function score(array $product, array $intent): ?int
    {
        $score = 0;

        $scopeIds = [];
        foreach ($intent['scope_ids'] ?? [] as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $scopeIds[] = (int) $id;
            }
        }
        if ($scopeIds !== [] && !in_array((int) ($product['id'] ?? 0), $scopeIds, true)) {
            return null;
        }

        $categorySlugs = [];
        foreach ($intent['category_slugs'] ?? [] as $slug) {
            $slug = trim((string) $slug);
            if ($slug !== '') {
                $categorySlugs[] = $slug;
            }
        }
        $categorySlugs = array_values(array_unique($categorySlugs));
        if ($categorySlugs !== []) {
            $hit = false;
            foreach ($product['categories'] as $row) {
                if (in_array((string) ($row['slug'] ?? ''), $categorySlugs, true) || in_array((string) ($row['parent_slug'] ?? ''), $categorySlugs, true) || in_array((string) ($row['root_slug'] ?? ''), $categorySlugs, true)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                return null;
            }
            $score += 80;
        }

        $category = $intent['category'] ?? null;
        if ($categorySlugs === [] && is_string($category) && $category !== '') {
            $exact = false;
            $parent = false;
            foreach ($product['categories'] as $row) {
                if (($row['slug'] ?? '') === $category) {
                    $exact = true;
                }
                if (($row['parent_slug'] ?? '') === $category || ($row['root_slug'] ?? '') === $category) {
                    $parent = true;
                }
            }
            if (!$exact && !$parent) {
                return null;
            }
            $score += $exact ? 80 : 40;
        }

        $brands = $intent['brand_slugs'] ?? [];
        if (is_array($brands) && $brands !== []) {
            if (!in_array((string) $product['brand_slug'], $brands, true)) {
                return null;
            }
            $score += 30;
        }

        $ctTarget = $intent['precision_ct'] ?? null;
        if (is_float($ctTarget) || is_int($ctTarget) || (is_string($ctTarget) && is_numeric($ctTarget))) {
            if (!self::matchesCtPrecision($product, (float) $ctTarget)) {
                return null;
            }
            $score += 50;
        }

        $targets = $intent['precisions'] ?? [];
        if ($targets === [] && ($intent['precision'] ?? null) !== null) {
            $targets = [(float) $intent['precision']];
        }
        if (is_array($targets) && $targets !== []) {
            $grams = $product['precision_grams'];
            if (!is_float($grams) && !is_int($grams)) {
                return null;
            }
            $bestRel = null;
            foreach ($targets as $target) {
                $target = (float) $target;
                if ($target <= 0) {
                    continue;
                }
                $rel = abs((float) $grams - $target) / $target;
                if ($rel <= 0.2 && ($bestRel === null || $rel < $bestRel)) {
                    $bestRel = $rel;
                }
            }
            if ($bestRel === null) {
                return null;
            }
            $score += $bestRel < 0.02 ? 100 : (int) round(40 + ((0.2 - $bestRel) / 0.2) * 30);
        }

        if ($intent['capacity_target'] !== null) {
            if ($product['capacity_min'] === null || $product['capacity_max'] === null) {
                return null;
            }
            $target = (float) $intent['capacity_target'];
            $mode = (string) ($intent['capacity_mode'] ?? 'around');
            $min = $mode === 'upto' ? $target * 0.45 : $target * 0.65;
            $max = $mode === 'upto' ? $target * 1.25 : $target * 1.55;
            $pmin = (float) $product['capacity_min'];
            $pmax = (float) $product['capacity_max'];
            if ($pmax < $min || $pmin > $max) {
                return null;
            }
            $center = ($pmin + $pmax) / 2;
            $distance = abs($center - $target) / max($target, 1);
            $score += (int) round((1 - min(1.0, $distance)) * 50);
            if (abs($pmax - $pmin) < 1) {
                $score += 20;
            }
        }

        if ($intent['commercial'] === true) {
            if ($product['commercial'] !== true) {
                return null;
            }
            $score += 25;
        } elseif ($intent['commercial'] === false) {
            if ($product['commercial'] === true) {
                return null;
            }
            $score += 25;
        }

        if ($intent['calibration'] !== null) {
            $cal = ProductAssistantCatalog::fold((string) ($product['calibration'] ?? ''));
            if ($cal === '') {
                return null;
            }
            $ok = $intent['calibration'] === 'auto'
                ? (str_contains($cal, 'otomatik') || str_contains($cal, 'ic'))
                : (str_contains($cal, 'manuel') || str_contains($cal, 'harici') || str_contains($cal, 'dis'));
            if (!$ok) {
                return null;
            }
            $score += 20;
        }

        foreach ($intent['communication'] ?? [] as $needle) {
            $hay = str_replace(['wi-fi', 'wi fi', '-'], ['wifi', 'wifi', ''], ProductAssistantCatalog::fold(implode(' ', $product['communication'] ?? [])));
            $needle = str_replace(['wi-fi', '-'], ['wifi', ''], (string) $needle);
            if ($hay === '' || !str_contains($hay, $needle)) {
                return null;
            }
            $score += 15;
        }

        if ($intent['condition'] !== null) {
            $cond = ProductAssistantCatalog::fold((string) ($product['condition'] ?? ''));
            if ($cond === '') {
                return null;
            }
            $ok = $intent['condition'] === 'renewed'
                ? (str_contains($cond, 'yenilen') || preg_match('/2\s*\.?\s*el|ikinci el/u', $cond) === 1)
                : (str_contains($cond, 'sifir') || str_contains($cond, 'yeni'));
            if (!$ok) {
                return null;
            }
            $score += 15;
        }

        $keywords = $intent['keywords'] ?? [];
        if (is_array($keywords) && $keywords !== []) {
            $score += self::nameScore($product, $intent);
        }

        $structured = $category || $brands || ($intent['precisions'] ?? []) !== [] || $intent['precision'] || $intent['capacity_target']
            || $intent['commercial'] !== null || $intent['calibration'] || ($intent['communication'] ?? []) || $intent['condition'];
        $rankingMode = ($intent['mode'] ?? '') === 'ranking';
        if (!$structured && !$rankingMode && self::nameScore($product, $intent) < 4) {
            return null;
        }

        if (!empty($product['in_stock'])) {
            $score += 3;
        }

        return $score;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $intent
     */
    private static function nameScore(array $product, array $intent): int
    {
        $hay = ProductAssistantCatalog::fold(
            (string) $product['name'] . ' ' . (string) $product['model_no'] . ' ' . (string) $product['brand']
        );
        $score = 0;
        foreach ($intent['brand_slugs'] ?? [] as $slug) {
            if ($slug !== '' && str_contains($hay, ProductAssistantCatalog::fold((string) $slug))) {
                $score += 6;
            }
        }
        foreach ($intent['keywords'] ?? [] as $word) {
            if (is_string($word) && $word !== '' && str_contains($hay, $word)) {
                $score += strlen($word) >= 5 ? 8 : 4;
            }
        }

        return $score;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function inCategory(array $product, string $slug): bool
    {
        foreach ($product['categories'] as $category) {
            if (($category['slug'] ?? '') === $slug || ($category['parent_slug'] ?? '') === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param list<string> $brands
     * @return list<array<string, mixed>>
     */
    private static function perBrand(array $products, array $brands, int $limit): array
    {
        $picked = [];
        foreach ($brands as $slug) {
            $count = 0;
            foreach ($products as $product) {
                if ((string) $product['brand_slug'] !== $slug) {
                    continue;
                }
                $picked[] = $product;
                $count++;
                if ($count >= $limit) {
                    break;
                }
            }
        }

        return $picked;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed> $intent
     * @return array{products:list<array<string,mixed>>,total:int,more_url:?string,reference_miss:bool}
     */
    private static function pack(array $products, array $intent, int $total, bool $referenceMiss = false, int $offset = 0): array
    {
        $clean = [];
        foreach ($products as $product) {
            unset($product['_score'], $product['_pos']);
            $clean[] = $product;
        }

        return [
            'products'        => $clean,
            'total'           => $total,
            'more_url'        => ($offset + count($clean)) < $total ? self::moreUrl($intent) : null,
            'reference_miss'  => $referenceMiss,
        ];
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function matchesCtPrecision(array $product, float $target): bool
    {
        if ($target <= 0) {
            return false;
        }
        foreach (is_array($product['precision_filters'] ?? null) ? $product['precision_filters'] : [] as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $label = str_replace(',', '.', ProductAssistantCatalog::fold((string) ($filter['label'] ?? '')));
            $isCt = ($filter['unit'] ?? '') === 'ct' || preg_match('/(?<=\d)\s*ct\b|karat/u', $label) === 1;
            if (!$isCt) {
                continue;
            }
            $value = $filter['grams'] ?? null;
            if (!is_float($value) && !is_int($value)) {
                continue;
            }
            $value = (float) $value;
            if ($value > 0 && abs($value - $target) / $target <= 0.02) {
                return true;
            }
        }
        if (($product['precision_unit'] ?? '') !== 'ct') {
            return false;
        }
        $label = str_replace(',', '.', ProductAssistantCatalog::fold((string) ($product['precision_label'] ?? '')));
        if (preg_match('/(0\.\d+)\s*ct\b/u', $label, $hit) !== 1) {
            return false;
        }

        $value = (float) $hit[1];

        return $value > 0 && abs($value - $target) / $target <= 0.02;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed> $intent
     * @return list<string>
     */
    private static function precisionFilterSlugs(array $products, array $intent): array
    {
        $targets = $intent['precisions'] ?? [];
        if ($targets === [] && ($intent['precision'] ?? null) !== null) {
            $targets = [(float) $intent['precision']];
        }
        if (!is_array($targets) || $targets === []) {
            return [];
        }

        $slugs = [];
        foreach ($products as $product) {
            $slug = trim((string) ($product['precision_filter_slug'] ?? ''));
            $grams = $product['precision_grams'] ?? null;
            if ($slug === '' || (!is_float($grams) && !is_int($grams))) {
                continue;
            }
            $matches = false;
            foreach ($targets as $target) {
                $target = (float) $target;
                if ($target > 0 && abs((float) $grams - $target) / $target <= 0.2) {
                    $matches = true;
                    break;
                }
            }
            if ($matches && !in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * @param array<string, mixed> $intent
     */
    private static function moreUrl(array $intent): ?string
    {
        $category = is_string($intent['category'] ?? null) ? $intent['category'] : '';
        $brands = [];
        foreach ($intent['brand_slugs'] ?? [] as $slug) {
            $slug = trim((string) $slug);
            if ($slug !== '' && preg_match('/^[a-z0-9\-]+$/', $slug) === 1) {
                $brands[] = $slug;
            }
        }
        $brands = array_values(array_unique($brands));
        $filters = [];
        foreach ($intent['precision_filter_slugs'] ?? [] as $slug) {
            $slug = trim((string) $slug);
            if ($slug !== '' && preg_match('/^[a-z0-9\-]+$/', $slug) === 1) {
                $filters[] = $slug;
            }
        }
        $filters = array_values(array_unique($filters));
        $targets = $intent['precisions'] ?? [];
        if ($targets === [] && ($intent['precision'] ?? null) !== null) {
            $targets = [(float) $intent['precision']];
        }
        if (is_array($targets) && $targets !== [] && $filters === []) {
            return null;
        }

        $query = [];
        if (count($brands) >= 2) {
            $path = '/urunler' . ($category !== '' ? '/' . $category : '');
            $query['marka'] = implode(',', $brands);
        } elseif (count($brands) === 1 && $category !== '') {
            $path = '/urunler/' . $brands[0] . '/' . $category;
        } elseif (count($brands) === 1) {
            $path = '/urunler/' . $brands[0];
        } elseif ($category !== '') {
            $path = '/urunler/' . $category;
        } else {
            $path = '/urunler';
        }
        if ($filters !== []) {
            $query['f'] = implode(',', $filters);
        }
        if ($query === [] && $path === '/urunler' && $brands === [] && $category === '') {
            return null;
        }

        $url = url($path);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * Süperlative seçimini sayısal alana göre yapar. Kazananı model seçmez.
     *
     * @param array<string, mixed> $intent
     * @return array{reason:?string,products:list<array<string,mixed>>,tie_count:int,brand_name:?string,ct_skipped:int}
     */
    public static function rank(array $intent): array
    {
        $field = (string) ($intent['ranking_field'] ?? '');
        $direction = (string) ($intent['ranking_direction'] ?? 'asc');
        $catalog = ProductAssistantCatalog::products();
        $pool = [];
        foreach ($catalog as $product) {
            if (self::score($product, $intent) === null) {
                continue;
            }
            $pool[] = $product;
        }

        $brandName = self::brandName($intent, $pool !== [] ? $pool : $catalog);
        $empty = [
            'reason'     => 'no_products',
            'products'   => [],
            'tie_count'  => 0,
            'brand_name' => $brandName,
            'ct_skipped' => 0,
        ];
        if ($pool === []) {
            return $empty;
        }

        $eligible = [];
        $ctSkipped = 0;
        foreach ($pool as $product) {
            if ($field === 'precision' && ($product['precision_unit'] ?? null) === 'ct') {
                $ctSkipped++;
            }
            $value = self::rankValue($product, $field);
            if ($value === null) {
                continue;
            }
            $product['_rank'] = $value;
            $eligible[] = $product;
        }
        if ($eligible === []) {
            $reason = match ($field) {
                'price' => 'no_price',
                'precision' => 'no_precision',
                'capacity' => 'no_capacity',
                default => 'no_products',
            };

            return [
                'reason'     => $reason,
                'products'   => [],
                'tie_count'  => 0,
                'brand_name' => $brandName,
                'ct_skipped' => $ctSkipped,
            ];
        }

        usort($eligible, static function (array $a, array $b) use ($direction): int {
            $cmp = $a['_rank'] <=> $b['_rank'];
            if ($direction === 'desc') {
                $cmp = -$cmp;
            }
            if ($cmp !== 0) {
                return $cmp;
            }
            $order = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
            if ($order !== 0) {
                return $order;
            }

            return ((int) $a['id']) <=> ((int) $b['id']);
        });

        $best = (float) $eligible[0]['_rank'];
        $winners = [];
        foreach ($eligible as $product) {
            if (!self::sameRank((float) $product['_rank'], $best)) {
                break;
            }
            unset($product['_rank']);
            $winners[] = $product;
        }

        return [
            'reason'     => null,
            'products'   => array_slice($winners, 0, 12),
            'tie_count'  => count($winners),
            'brand_name' => $brandName,
            'ct_skipped' => $ctSkipped,
        ];
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function rankValue(array $product, string $field): ?float
    {
        if ($field === 'precision') {
            if (($product['precision_unit'] ?? null) === 'ct') {
                return null;
            }
            $grams = $product['precision_grams'] ?? null;
            if (!is_float($grams) && !is_int($grams)) {
                return null;
            }
            $grams = (float) $grams;

            return $grams > 0 ? $grams : null;
        }
        if ($field === 'price') {
            $price = $product['price_try'] ?? null;
            if (!is_float($price) && !is_int($price)) {
                return null;
            }
            $price = (float) $price;

            return $price > 0 ? $price : null;
        }
        if ($field === 'capacity') {
            $max = $product['capacity_max'] ?? null;
            if (!is_float($max) && !is_int($max)) {
                return null;
            }
            $max = (float) $max;

            return $max > 0 ? $max : null;
        }

        return null;
    }

    private static function sameRank(float $a, float $b): bool
    {
        $scale = max(abs($a), abs($b), 1e-12);

        return abs($a - $b) <= $scale * 1e-6;
    }

    /**
     * @param array<string, mixed> $intent
     * @param list<array<string, mixed>> $products
     */
    private static function brandName(array $intent, array $products): ?string
    {
        $slugs = $intent['brand_slugs'] ?? [];
        if (!is_array($slugs) || count($slugs) !== 1) {
            return null;
        }
        $slug = (string) $slugs[0];
        foreach ($products as $product) {
            if ((string) ($product['brand_slug'] ?? '') === $slug) {
                $name = trim((string) ($product['brand'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return null;
    }
}
