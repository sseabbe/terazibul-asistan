<?php

declare(strict_types=1);

namespace App\Services\ProductAssistant;

use Core\Cache;
use Core\Database;

/**
 * Yayınlanmış Terazibul ürünlerini asistanın okuyacağı tek kaynaktan derler.
 * AI bu sınıfa SQL yazdırmaz; sorgular sabittir.
 */
final class ProductAssistantCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function products(): array
    {
        $cached = Cache::remember('product_assistant.catalog.v13', 300, static function (): array {
            return self::load();
        });

        return is_array($cached) ? array_values($cached) : [];
    }

    public static function forget(): void
    {
        Cache::forget('product_assistant.catalog.v12');
        Cache::forget('product_assistant.catalog.v13');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function byId(): array
    {
        $map = [];
        foreach (self::products() as $product) {
            $map[(int) $product['id']] = $product;
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function load(): array
    {
        $rows = Database::select(
            "SELECT p.id, p.name, p.slug, p.model_no, p.short_description, p.thumbnail, p.gallery,
                    p.price, p.sale_price, p.price_currency, p.show_price, p.price_includes_vat,
                    p.in_stock, p.`order` AS sort_order, b.name AS brand_name, b.slug AS brand_slug
             FROM products p
             LEFT JOIN brands b ON b.id = p.brand_id
             WHERE p.status = 'published'
               AND p.deleted_at IS NULL
               AND COALESCE(p.is_shop_demo, 0) = 0"
        );

        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $categories = [];
        foreach (Database::select(
            "SELECT ppc.product_id, ppc.`order` AS cat_order, pc.name, pc.slug, COALESCE(parent.slug, '') AS parent_slug, COALESCE(parent.name, '') AS parent_name, COALESCE(root.slug, '') AS root_slug
             FROM product_product_category ppc
             INNER JOIN product_categories pc ON pc.id = ppc.product_category_id AND pc.deleted_at IS NULL
             LEFT JOIN product_categories parent ON parent.id = pc.parent_id AND parent.deleted_at IS NULL
             LEFT JOIN product_categories root ON root.id = parent.parent_id AND root.deleted_at IS NULL
             WHERE ppc.product_id IN ($placeholders)",
            $ids
        ) as $row) {
            $categories[(int) $row['product_id']][] = [
                'name'        => (string) $row['name'],
                'slug'        => (string) $row['slug'],
                'parent_slug' => (string) $row['parent_slug'],
                'parent_name' => (string) $row['parent_name'],
                'root_slug'   => (string) ($row['root_slug'] ?? ''),
                'order'       => (int) ($row['cat_order'] ?? 0),
            ];
        }

        $filters = [];
        foreach (Database::select(
            "SELECT pf.product_id, f.name, f.slug AS filter_slug, f.`order` AS filter_order, fc.slug AS cat_slug
             FROM product_filter pf
             INNER JOIN filters f ON f.id = pf.filter_id
             INNER JOIN filter_categories fc ON fc.id = f.filter_category_id
             WHERE pf.product_id IN ($placeholders)",
            $ids
        ) as $row) {
            $filters[(int) $row['product_id']][] = [
                'name'  => (string) $row['name'],
                'slug'  => (string) ($row['filter_slug'] ?? ''),
                'cat'   => (string) $row['cat_slug'],
                'order' => (int) ($row['filter_order'] ?? 0),
            ];
        }

        $specs = [];
        foreach (Database::select(
            "SELECT product_id, label, value
             FROM product_specs
             WHERE product_id IN ($placeholders)
             ORDER BY product_id ASC, `order` ASC, id ASC",
            $ids
        ) as $row) {
            $label = trim((string) $row['label']);
            $value = trim(strip_tags((string) $row['value']));
            if ($label === '' || $value === '') {
                continue;
            }
            $specs[(int) $row['product_id']][] = ['label' => $label, 'value' => $value];
        }

        $audiences = [];
        foreach (Database::select(
            "SELECT product_id, title, content
             FROM product_accordions
             WHERE product_id IN ($placeholders)
             ORDER BY product_id ASC, `order` ASC, id ASC",
            $ids
        ) as $row) {
            if (!self::suitabilityTitle((string) ($row['title'] ?? ''))) {
                continue;
            }
            $lines = self::audienceLines((string) ($row['content'] ?? ''));
            if ($lines === []) {
                continue;
            }
            $id = (int) $row['product_id'];
            foreach ($lines as $line) {
                $audiences[$id][] = $line;
            }
        }

        $tags = [];
        foreach (Database::select(
            "SELECT ppt.product_id, pt.slug, pt.name
             FROM product_product_tag ppt
             INNER JOIN product_tags pt ON pt.id = ppt.product_tag_id
             WHERE ppt.product_id IN ($placeholders)",
            $ids
        ) as $row) {
            $tags[(int) $row['product_id']][] = [
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
            ];
        }

        $approved = product_commercial_approval_product_set();
        $specGroups = ['kapasite' => true, 'hassasiyet' => true];
        foreach (array_keys(\App\Support\SpecTemplate::filterItemsBySlug()) as $groupSlug) {
            $specGroups[(string) $groupSlug] = true;
        }
        $products = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $productFilters = $filters[$id] ?? [];
            $productSpecs = $specs[$id] ?? [];
            $productTags = $tags[$id] ?? [];
            $capacity = self::capacity($productSpecs, $productFilters);
            $precision = self::precision($productSpecs, $productFilters);
            $commercial = self::commercial($id, $productFilters, $approved);
            $facets = self::listingFacets($productSpecs, $productFilters, $productTags, $specGroups);

            $image = product_primary_image($row);
            if ($image !== '' && !str_starts_with($image, 'http://') && !str_starts_with($image, 'https://')) {
                $image = url(ltrim($image, '/'));
            }

            $priceLabel = null;
            $priceTry = null;
            if (product_has_visible_price($row)) {
                $formatted = product_card_price_html($row, 0);
                $priceLabel = trim(html_entity_decode(strip_tags($formatted), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($priceLabel === '') {
                    $priceLabel = null;
                }
                $list = product_display_price_in_try($row, 'price');
                $saleAmount = product_sale_amount_in_currency($row);
                $sale = $saleAmount > 0
                    ? product_display_price_in_try(array_merge($row, ['sale_price' => $saleAmount]), 'sale_price')
                    : 0.0;
                $amount = $sale > 0 ? $sale : $list;
                if ($amount > 0) {
                    $priceTry = round($amount, 2);
                }
            }

            $products[] = [
                'id'               => $id,
                'name'             => (string) $row['name'],
                'slug'             => (string) $row['slug'],
                'model_no'         => trim((string) ($row['model_no'] ?? '')),
                'brand'            => trim((string) ($row['brand_name'] ?? '')),
                'brand_slug'       => trim((string) ($row['brand_slug'] ?? '')),
                'url'              => url('/urun/' . $row['slug']),
                'image'            => $image,
                'in_stock'         => (int) ($row['in_stock'] ?? 0) === 1,
                'sort_order'       => (int) ($row['sort_order'] ?? 0),
                'short'            => self::plain((string) ($row['short_description'] ?? ''), 280),
                'price_label'      => $priceLabel,
                'price_try'        => $priceTry,
                'capacity_label'   => $capacity['label'],
                'capacity_min'     => $capacity['min'],
                'capacity_max'     => $capacity['max'],
                'precision_label'  => $precision['label'],
                'precision_grams'  => $precision['grams'],
                'precision_unit'   => $precision['unit'],
                'precision_filter_slug' => $precision['slug'],
                'precision_filters' => self::precisionFilters($productFilters),
                'capacity_filters' => self::capacityFilters($productFilters),
                'category_orders'  => self::categoryOrders($categories[$id] ?? []),
                'commercial'       => $commercial,
                'calibration'      => self::joined($productFilters, ['kalibrasyon-tipi', 'hassasiyet-kalibrasyon']),
                'communication'    => self::communication($productSpecs, $productFilters),
                'housing'          => self::names($productFilters, 'kasa-yapisi'),
                'accessories'      => self::names($productFilters, 'aksesuar'),
                'condition'        => self::condition($productFilters, $productTags),
                'listing_filters'  => $facets['filters'],
                'tag_slugs'        => $facets['tags'],
                'categories'       => $categories[$id] ?? [],
                'specs'            => array_slice($productSpecs, 0, 24),
                'audiences'        => $audiences[$id] ?? [],
                'drying_c'         => self::dryingCelsius($productSpecs),
            ];
        }

        return $products;
    }

    /**
     * @param list<array{label:string,value:string}> $specs
     * @param list<array{name:string,cat:string}> $filters
     * @return array{label:?string,min:?float,max:?float}
     */
    private static function capacity(array $specs, array $filters): array
    {
        foreach ($specs as $spec) {
            $label = self::fold($spec['label']);
            if (!str_contains($label, 'kapasite') || str_contains($label, 'yogunluk')) {
                continue;
            }
            $range = self::gramsRange($spec['value']);
            if ($range !== null) {
                return ['label' => $spec['value'], 'min' => $range[0], 'max' => $range[1]];
            }
        }

        foreach ($filters as $filter) {
            if ($filter['cat'] !== 'kapasite') {
                continue;
            }
            $range = self::gramsRange($filter['name']);
            // Aralık kovası (200g-500g) cihaz kapasitesi değildir. Tek değerli filtre kalır.
            if ($range !== null && abs($range[0] - $range[1]) < 0.6) {
                return ['label' => $filter['name'], 'min' => $range[0], 'max' => $range[1]];
            }
        }

        return ['label' => null, 'min' => null, 'max' => null];
    }

    /**
     * @param list<array{label:string,value:string}> $specs
     * @param list<array{name:string,slug?:string,cat:string}> $filters
     * @return array{label:?string,grams:?float,slug:?string,unit:?string}
     */
    private static function precision(array $specs, array $filters): array
    {
        $filterOptions = [];
        foreach ($filters as $filter) {
            if ($filter['cat'] !== 'hassasiyet') {
                continue;
            }
            $grams = self::precisionGrams($filter['name']);
            if ($grams === null || $grams <= 0 || $grams >= 100) {
                continue;
            }
            $filterOptions[] = [
                'label' => $filter['name'],
                'grams' => $grams,
                'slug'  => ($filter['slug'] ?? '') !== '' ? (string) $filter['slug'] : null,
                'unit'  => 'g',
            ];
        }
        $filterMatch = $filterOptions[0] ?? null;

        $ctLabel = null;
        foreach ($specs as $spec) {
            $label = self::fold($spec['label']);
            if (str_contains($label, 'yogunluk')) {
                continue;
            }
            if (!str_contains($label, 'hassasiyet') && !str_contains($label, 'okunabilirlik')) {
                continue;
            }
            $grams = self::precisionGrams($spec['value']);
            if ($grams === null || $grams <= 0 || $grams >= 100) {
                $foldedValue = self::fold($spec['value']);
                if ($ctLabel === null && preg_match('/(?<=\d)\s*ct\b|karat/u', $foldedValue) === 1) {
                    $ctLabel = $spec['value'];
                }
                continue;
            }
            $slug = null;
            foreach ($filterOptions as $option) {
                $optionGrams = (float) $option['grams'];
                if (abs($optionGrams - $grams) / $grams <= 0.02) {
                    $slug = $option['slug'];
                    break;
                }
            }

            return [
                'label' => $spec['value'],
                'grams' => $grams,
                'slug'  => $slug,
                'unit'  => 'g',
            ];
        }

        if ($filterMatch !== null) {
            return $filterMatch;
        }

        if ($ctLabel !== null) {
            return ['label' => $ctLabel, 'grams' => null, 'slug' => null, 'unit' => 'ct'];
        }

        return ['label' => null, 'grams' => null, 'slug' => null, 'unit' => null];
    }

    /**
     * Ürüne işlenmiş hassasiyet filtreleri. Çip bunlar dışından üretilmez.
     *
     * @param list<array{name:string,slug?:string,cat:string}> $filters
     * @return list<array{grams:float,slug:string,label:string}>
     */
    private static function precisionFilters(array $filters): array
    {
        $out = [];
        foreach ($filters as $filter) {
            if (($filter['cat'] ?? '') !== 'hassasiyet') {
                continue;
            }
            $name = (string) ($filter['name'] ?? '');
            $foldedName = str_replace(',', '.', self::fold($name));
            if (preg_match('/(?<=\d)\s*ct\b|karat/u', $foldedName) === 1) {
                if (preg_match('/(0\.\d+)/u', $foldedName, $ct) !== 1) {
                    continue;
                }
                $out[] = [
                    'grams' => (float) $ct[1],
                    'slug'  => (string) ($filter['slug'] ?? ''),
                    'label' => $name,
                    'unit'  => 'ct',
                ];
                continue;
            }
            $grams = self::precisionGrams($name);
            if ($grams === null || $grams <= 0) {
                continue;
            }
            $out[] = [
                'grams' => $grams,
                'slug'  => (string) ($filter['slug'] ?? ''),
                'label' => $name,
                'unit'  => 'g',
            ];
        }

        return $out;
    }

    /**
     * Vitrin kapasite facet'i. Ürün adındaki gramlar bu listeye girmez.
     *
     * @param list<array{name:string,slug?:string,cat:string,order?:int}> $filters
     * @return list<array{slug:string,name:string,order:int}>
     */
    private static function capacityFilters(array $filters): array
    {
        $out = [];
        foreach ($filters as $filter) {
            if (($filter['cat'] ?? '') !== 'kapasite') {
                continue;
            }
            $slug = trim((string) ($filter['slug'] ?? ''));
            $name = trim((string) ($filter['name'] ?? ''));
            if ($slug === '' || $name === '') {
                continue;
            }
            $out[] = [
                'slug'  => $slug,
                'name'  => $name,
                'order' => (int) ($filter['order'] ?? 0),
            ];
        }
        usort($out, static fn (array $a, array $b): int => ($a['order'] <=> $b['order']) ?: strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Kategori içi vitrin sırası. Üst kapsam en küçük çocuk sırasını tutar.
     *
     * @param list<array<string, mixed>> $categories
     * @return array<string, int>
     */
    private static function categoryOrders(array $categories): array
    {
        $orders = [];
        foreach ($categories as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $ord = (int) ($cat['order'] ?? 0);
            if ($ord < 1) {
                continue;
            }
            $keys = [
                trim((string) ($cat['slug'] ?? '')),
                trim((string) ($cat['parent_slug'] ?? '')),
                trim((string) ($cat['root_slug'] ?? '')),
            ];
            foreach ($keys as $key) {
                if ($key === '') {
                    continue;
                }
                if (!isset($orders[$key]) || $ord < $orders[$key]) {
                    $orders[$key] = $ord;
                }
            }
        }

        return $orders;
    }

    private static function precisionGrams(string $value): ?float
    {
        $folded = str_replace(',', '.', self::fold($value));
        if (preg_match('/\(([\d.]+)\s*g\)/u', $folded, $m)) {
            $grams = (float) $m[1];

            return ($grams > 0 && $grams < 100) ? $grams : null;
        }
        if (preg_match('/(?<=\d)\s*ct\b|karat/u', $folded)) {
            return null;
        }

        $grams = product_filter_parse_precision_value($value);

        return ($grams !== null && $grams > 0 && $grams < 100) ? $grams : null;
    }

    /**
     * @param list<array{name:string,cat:string}> $filters
     * @param array<int, true> $approved
     */
    private static function commercial(int $id, array $filters, array $approved): ?bool
    {
        foreach ($filters as $filter) {
            $name = self::fold($filter['name']);
            if (str_contains($name, 'onaysiz') || str_contains($name, 'onay siz')) {
                return false;
            }
        }

        if (isset($approved[$id])) {
            return true;
        }

        foreach ($filters as $filter) {
            if ($filter['cat'] !== 'ticari-onayli') {
                continue;
            }
            $name = self::fold($filter['name']);
            if (str_contains($name, 'onayli') || $name === 'onayli') {
                return true;
            }
        }

        return null;
    }

    /**
     * @param list<array{label:string,value:string}> $specs
     * @param list<array{name:string,cat:string}> $filters
     * @return list<string>
     */
    private static function communication(array $specs, array $filters): array
    {
        $values = self::names($filters, 'baglanti');
        foreach (self::names($filters, 'haberlesme') as $name) {
            $values[] = $name;
        }

        foreach ($specs as $spec) {
            $label = self::fold($spec['label']);
            if (!str_contains($label, 'iletisim') && !str_contains($label, 'haberlesme') && !str_contains($label, 'baglanti')) {
                continue;
            }
            foreach (preg_split('/[,;\/|]+/u', $spec['value']) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $values[] = $part;
                }
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param list<array{name:string,cat:string}> $filters
     * @return list<string>
     */
    private static function names(array $filters, string $cat): array
    {
        $names = [];
        foreach ($filters as $filter) {
            if ($filter['cat'] === $cat && $filter['name'] !== '') {
                $names[] = $filter['name'];
            }
        }

        return $names;
    }

    /**
     * 2. el ürünler sitede ürün etiketiyle durur. Ürün durumu filtresi boş olsa da etiket geçerlidir.
     *
     * @param list<array{name:string,cat:string}> $filters
     * @param list<array{slug:string,name:string}> $tags
     */
    private static function condition(array $filters, array $tags): ?string
    {
        foreach ($tags as $tag) {
            if (\App\Models\ProductTag::isRenewedSlug((string) ($tag['slug'] ?? ''))) {
                return '2. El';
            }
        }

        return self::joined($filters, ['urun-durumu']);
    }

    /**
     * @param list<array{name:string,cat:string}> $filters
     * @param list<string> $cats
     */
    private static function joined(array $filters, array $cats): ?string
    {
        $names = [];
        foreach ($cats as $cat) {
            foreach (self::names($filters, $cat) as $name) {
                $names[] = $name;
            }
        }
        $names = array_values(array_unique($names));

        return $names === [] ? null : implode(', ', $names);
    }

    /**
     * @return array{0:float,1:float}|null
     */
    private static function gramsRange(string $text): ?array
    {
        $t = str_replace([',', '–', '—'], ['.', '-', '-'], mb_strtolower(trim($text)));
        if ($t === '' || preg_match('/(?:nm|ct|cm)/u', $t)) {
            return null;
        }

        if (preg_match('/([\d.]+)\s*(mg|g|kg|t)?\s*-\s*([\d.]+)\s*(mg|g|kg|t)/u', $t, $m)) {
            $unitMin = ($m[2] ?? '') !== '' ? $m[2] : $m[4];
            $min = product_filter_to_grams((float) $m[1], $unitMin);
            $max = product_filter_to_grams((float) $m[3], $m[4]);

            return [min($min, $max), max($min, $max)];
        }

        if (preg_match('/([\d.]+)\s*(mg|g|kg|t)\s*\+/u', $t, $m)) {
            $min = product_filter_to_grams((float) $m[1], $m[2]);

            return [$min, $min * 50];
        }

        $grams = product_filter_parse_capacity_grams($text);
        if ($grams === null || $grams <= 0) {
            return null;
        }

        return [$grams, $grams];
    }

    private static function plain(string $html, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }

    private static function suitabilityTitle(string $title): bool
    {
        $fold = self::fold($title);

        return str_contains($fold, 'kimler icin uygun') || str_contains($fold, 'who is this product for');
    }

    /**
     * @return list<string>
     */
    private static function audienceLines(string $html): array
    {
        $out = [];
        $push = static function (string $chunk) use (&$out): void {
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($chunk), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            if ($text === '' || mb_strlen($text) > 120) {
                return;
            }
            $out[] = $text;
        };
        if (preg_match_all('/<li\b[^>]*>(.*?)<\/li>/isu', $html, $items) >= 1) {
            foreach ($items[1] as $item) {
                $push((string) $item);
            }
        }
        if ($out === [] && preg_match_all('/<strong\b[^>]*>(.*?)<\/strong>/isu', $html, $items) >= 1) {
            foreach ($items[1] as $item) {
                $push((string) $item);
            }
        }
        $seen = [];
        $unique = [];
        foreach ($out as $line) {
            $key = self::fold($line);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $line;
        }

        return $unique;
    }

    /**
     * @param list<array{label:string,value:string}> $specs
     */
    private static function dryingCelsius(array $specs): ?int
    {
        foreach ($specs as $spec) {
            $label = self::fold((string) ($spec['label'] ?? ''));
            if (!str_contains($label, 'sicaklik') || str_contains($label, 'sure')) {
                continue;
            }
            if (!str_contains($label, 'kurutma') && !str_contains($label, 'maksimum')) {
                continue;
            }
            if (preg_match('/(\d{2,3})/u', (string) ($spec['value'] ?? ''), $match) !== 1) {
                continue;
            }
            $celsius = (int) $match[1];
            if ($celsius >= 40 && $celsius <= 400) {
                return $celsius;
            }
        }

        return null;
    }

    /**
     * Liste sayfasının checkbox slug'ları. Spec grupları product_specs'ten,
     * diğerleri filters tablosundan. Kapasite/hassasiyet çakışma eki sayfa
     * kapsamında üretildiği için burada temel slug tutulur.
     *
     * @param list<array{label:string,value:string}> $specs
     * @param list<array{name:string,slug:string,cat:string}> $filters
     * @param list<array{slug:string,name:string}> $tags
     * @param array<string, true> $specGroups
     * @return array{filters:array<string, list<array{slug:string,name:string}>>,tags:list<string>}
     */
    private static function listingFacets(array $specs, array $filters, array $tags, array $specGroups): array
    {
        $groups = [];
        foreach ($filters as $filter) {
            $cat = (string) ($filter['cat'] ?? '');
            $slug = (string) ($filter['slug'] ?? '');
            $name = (string) ($filter['name'] ?? '');
            if ($cat === '' || $slug === '' || isset($specGroups[$cat])) {
                continue;
            }
            $groups[$cat][$slug] = $name;
        }

        $template = \App\Support\SpecTemplate::filterItemsBySlug();
        foreach ($specs as $spec) {
            $label = (string) ($spec['label'] ?? '');
            $value = trim((string) ($spec['value'] ?? ''));
            if ($value === '' || product_spec_value_is_empty($value)) {
                continue;
            }
            $item = \App\Support\SpecTemplate::itemForLabel($label);
            $group = null;
            if (is_array($item) && empty($item['is_system']) && isset($template[(string) ($item['slug'] ?? '')])) {
                $group = (string) $item['slug'];
            } else {
                $kind = product_spec_measure_kind($label);
                if (is_string($kind) && $kind !== '') {
                    $group = $kind;
                }
            }
            if ($group === null) {
                continue;
            }
            $value = product_spec_filter_canonical_value($group, $value);
            $legacy = $group === 'kapasite' || $group === 'hassasiyet';
            $slug = $legacy
                ? product_spec_measure_filter_slug($group, $value)
                : product_spec_filter_option_slug($group, $value);
            if ($slug !== '') {
                $groups[$group][$slug] = $value;
            }
        }

        $tagSlugs = [];
        foreach ($tags as $tag) {
            $slug = (string) ($tag['slug'] ?? '');
            if ($slug !== '') {
                $tagSlugs[] = $slug;
            }
        }
        $tagSlugs = array_values(array_unique($tagSlugs));
        if (!product_tag_slugs_include_renewed($tagSlugs)) {
            $groups['urun-durumu'][product_new_condition_filter_slug()] = 'Sıfır';
        }

        $out = [];
        foreach ($groups as $group => $options) {
            foreach ($options as $slug => $name) {
                $out[$group][] = ['slug' => (string) $slug, 'name' => (string) $name];
            }
        }

        return ['filters' => $out, 'tags' => $tagSlugs];
    }

    public static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');

        return strtr($text, [
            'ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c',
            'â' => 'a', 'î' => 'i', 'û' => 'u',
        ]);
    }
}
