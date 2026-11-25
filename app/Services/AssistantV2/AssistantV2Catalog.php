<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

use App\Services\ProductAssistant\ProductAssistantCatalog;
use Core\Cache;
use Core\Database;

/**
 * Canlı katalog indeksleri. Model SQL yazmaz.
 */
final class AssistantV2Catalog
{
    /** Kullanım alanı değil: ürün durumu / ticari etiket kökleri. */
    private const CONDITION_ROOTS = [
        'yenilenmis' => true,
        'takasa-uygun' => true,
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function products(): array
    {
        return ProductAssistantCatalog::products();
    }

    public static function fold(string $text): string
    {
        return ProductAssistantCatalog::fold($text);
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
     * @return array<string, array<string, mixed>>
     */
    public static function bySlug(): array
    {
        $map = [];
        foreach (self::products() as $product) {
            $slug = (string) ($product['slug'] ?? '');
            if ($slug !== '') {
                $map[$slug] = $product;
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public static function brands(): array
    {
        $names = [];
        foreach (self::products() as $product) {
            $name = trim((string) ($product['brand'] ?? ''));
            if ($name !== '') {
                $names[self::fold($name)] = $name;
            }
        }

        $list = array_values($names);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    /**
     * Katalogdaki model kodlarından türeyen seri adları. Örnek: PJX6201 → PJX.
     *
     * @return list<string>
     */
    public static function seriesList(): array
    {
        $names = [];
        foreach (self::products() as $product) {
            $series = self::seriesOf($product);
            if ($series !== null) {
                $names[$series] = $series;
            }
        }
        $list = array_values($names);
        sort($list, SORT_NATURAL);

        return $list;
    }

    public static function resolveSeries(string $raw): ?string
    {
        $token = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
        if ($token === '' || strlen($token) < 2) {
            return null;
        }
        $best = null;
        foreach (self::seriesList() as $series) {
            if ($token === $series) {
                return $series;
            }
            if (preg_match('/(?:^|[^A-Z])' . preg_quote($series, '/') . '(?:[^A-Z]|$)/', $token) === 1) {
                if ($best === null || strlen($series) > strlen($best)) {
                    $best = $series;
                }
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $product
     */
    public static function seriesOf(array $product): ?string
    {
        foreach (['model_no', 'name', 'slug'] as $key) {
            $token = self::seriesToken((string) ($product[$key] ?? ''));
            if ($token !== null && self::seriesAllowed($token, (string) ($product['brand'] ?? ''))) {
                return $token;
            }
        }

        return null;
    }

    private static function seriesToken(string $text): ?string
    {
        if (preg_match('/(?:^|[^A-Za-z])([A-Za-z]{2,5})(?=\d{3})/', $text, $match) !== 1) {
            return null;
        }

        return strtoupper($match[1]);
    }

    private static function seriesAllowed(string $token, string $brand): bool
    {
        $brandToken = strtoupper(preg_replace('/[^A-Za-z]/', '', $brand) ?? '');
        if ($brandToken !== '' && str_starts_with($brandToken, $token) && strlen($token) >= strlen($brandToken) - 1) {
            return false;
        }

        return strlen($token) >= 2;
    }

    public static function resolveBrand(string $raw): ?string
    {
        $fold = trim(self::fold($raw), " \t\"'`");
        if ($fold === '') {
            return null;
        }

        $exact = null;
        $prefix = null;
        $prefixLen = 0;
        $tokenHits = [];
        foreach (self::brands() as $brand) {
            $brandFold = self::fold($brand);
            if ($brandFold === $fold) {
                $exact = $brand;
                break;
            }
            if (strlen($brandFold) >= 4 && str_starts_with($fold, $brandFold) && strlen($brandFold) > $prefixLen) {
                $prefix = $brand;
                $prefixLen = strlen($brandFold);
            }
            if ($exact === null && strlen($fold) >= 4 && preg_match('/(^|[^a-z0-9])' . preg_quote($fold, '/') . '([^a-z0-9]|$)/u', $brandFold) === 1) {
                $tokenHits[$brand] = strlen($brandFold);
            }
        }
        if ($exact !== null || $prefix !== null) {
            return $exact ?? $prefix;
        }
        if ($tokenHits === []) {
            return null;
        }
        asort($tokenHits);

        return (string) array_key_first($tokenHits);
    }

    /**
     * Mesajda geçen tek marka. Birden fazla marka varsa seçim modele kalır.
     */
    public static function mentionedBrand(string $message): ?string
    {
        $fold = self::fold($message);
        if ($fold === '') {
            return null;
        }
        $hits = [];
        foreach (self::brands() as $brand) {
            $brandFold = self::fold($brand);
            if ($brandFold === '' || strlen($brandFold) < 3) {
                continue;
            }
            if (preg_match('/(^| )' . preg_quote($brandFold, '/') . '($| )/u', $fold) === 1) {
                $hits[$brand] = strlen($brandFold);
            }
        }
        if (count($hits) === 1) {
            return (string) array_key_first($hits);
        }
        foreach (preg_split('/[^a-z0-9]+/u', $fold) ?: [] as $word) {
            if (!is_string($word) || strlen($word) < 4) {
                continue;
            }
            $resolved = self::resolveBrand($word);
            if ($resolved !== null) {
                $hits[$resolved] = strlen(self::fold($resolved));
            }
        }
        if (count($hits) !== 1) {
            return null;
        }

        return (string) array_key_first($hits);
    }

    /**
     * Metinde adı geçen markalar.
     *
     * @return list<string>
     */
    public static function brandsIn(string $text): array
    {
        $fold = self::fold($text);
        if ($fold === '') {
            return [];
        }
        $hits = [];
        foreach (self::brands() as $brand) {
            $brandFold = self::fold($brand);
            if ($brandFold === '' || strlen($brandFold) < 3) {
                continue;
            }
            if (preg_match('/(^| )' . preg_quote($brandFold, '/') . '($| )/u', $fold) === 1) {
                $hits[] = $brand;
            }
        }

        return $hits;
    }

    /**
     * Çocuk adı mesajda geçiyor mu. "terazi" / "terazisi" aynı kökten sayılır.
     */
    public static function nameMatches(string $name, string $message): bool
    {
        $name = self::fold($name);
        $message = self::fold($message);
        if ($name === '' || strlen($name) < 5 || $message === '') {
            return false;
        }
        if (preg_match('/(^|[^a-z0-9])' . preg_quote($name, '/') . '([^a-z0-9]|$)/', $message) === 1) {
            return true;
        }
        $nameTokens = array_values(array_filter(
            preg_split('/[^a-z0-9]+/', $name) ?: [],
            static fn (string $token): bool => strlen($token) >= 4
        ));
        $specific = array_values(array_filter(
            $nameTokens,
            static fn (string $token): bool => !preg_match('/^terazi|^cihaz|^urun/u', $token)
        ));
        if ($specific === []) {
            return false;
        }
        $messageTokens = preg_split('/[^a-z0-9]+/', $message) ?: [];
        foreach ($specific as $token) {
            $ok = false;
            foreach ($messageTokens as $have) {
                if (!is_string($have) || $have === '') {
                    continue;
                }
                if ($token === $have) {
                    $ok = true;
                    break;
                }
                $short = strlen($token) <= strlen($have) ? $token : $have;
                $long = $short === $token ? $have : $token;
                // Yalnızca çekim eki kadar fark kabul edilir (hassas / hassaslar). hassasiyetli başka kelimedir.
                if (strlen($short) >= 5 && str_starts_with($long, $short) && strlen($long) - strlen($short) <= 3) {
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
     * Seçili kökün altında, mesajın adlandırdığı çocuk.
     */
    public static function matchingChild(string $parent, string $message): ?string
    {
        if ($parent === '' || trim($message) === '') {
            return null;
        }
        $best = null;
        $bestLen = 0;
        foreach (self::taxonomy() as $node) {
            if ($node['parent'] !== $parent || $node['products'] < 1) {
                continue;
            }
            if (!self::nameMatches($node['name'], $message)) {
                continue;
            }
            $len = strlen(self::fold($node['name']));
            if ($len > $bestLen) {
                $best = $node['slug'];
                $bestLen = $len;
            }
        }

        return $best;
    }

    /**
     * Bu ada uyan alt kategorinin bulunduğu kökler. slug => ad.
     *
     * @return array<string, string>
     */
    public static function rootsForChild(string $message): array
    {
        $out = [];
        foreach (self::taxonomy() as $node) {
            if ($node['parent'] === '' || $node['products'] < 1) {
                continue;
            }
            if (!self::nameMatches($node['name'], $message)) {
                continue;
            }
            $root = self::usageSlug($node);
            if ($root === '' || $root === $node['slug']) {
                continue;
            }
            $rootNode = self::node($root);
            if ($rootNode === null || $rootNode['name'] === '') {
                continue;
            }
            $out[$root] = $rootNode['name'];
        }

        return $out;
    }

    /**
     * Mesajın tuttuğu en uzun alt kategori adı.
     */
    public static function matchedChildName(string $message): ?string
    {
        $best = null;
        $bestLen = 0;
        foreach (self::taxonomy() as $node) {
            if ($node['parent'] === '' || $node['products'] < 1) {
                continue;
            }
            if (!self::nameMatches($node['name'], $message)) {
                continue;
            }
            $len = strlen(self::fold($node['name']));
            if ($len > $bestLen) {
                $best = $node['name'];
                $bestLen = $len;
            }
        }

        return $best;
    }

    /**
     * @return list<array{slug:string,name:string,parent:string,children:int,products:int}>
     */
    public static function taxonomy(): array
    {
        $nodes = [];
        foreach (self::products() as $product) {
            foreach ($product['categories'] ?? [] as $cat) {
                if (!is_array($cat)) {
                    continue;
                }
                $slug = trim((string) ($cat['slug'] ?? ''));
                $name = trim((string) ($cat['name'] ?? ''));
                if ($slug === '' || $name === '') {
                    continue;
                }
                if (!isset($nodes[$slug])) {
                    $nodes[$slug] = [
                        'slug'     => $slug,
                        'name'     => $name,
                        'parent'   => trim((string) ($cat['parent_slug'] ?? '')),
                        'children' => 0,
                        'products' => 0,
                        'ids'      => [],
                    ];
                }
                $nodes[$slug]['ids'][(int) $product['id']] = true;
                $parentSlug = trim((string) ($cat['parent_slug'] ?? ''));
                $parentName = trim((string) ($cat['parent_name'] ?? ''));
                if ($parentSlug !== '') {
                    if (!isset($nodes[$parentSlug])) {
                        $nodes[$parentSlug] = [
                            'slug'     => $parentSlug,
                            'name'     => $parentName !== '' ? $parentName : $parentSlug,
                            'parent'   => trim((string) ($cat['root_slug'] ?? '')),
                            'children' => 0,
                            'products' => 0,
                            'ids'      => [],
                        ];
                    }
                    $nodes[$parentSlug]['ids'][(int) $product['id']] = true;
                }
            }
        }

        foreach (self::categoryRecords() as $row) {
            $slug = trim((string) ($row['slug'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($slug === '' || $name === '') {
                continue;
            }
            if (!isset($nodes[$slug])) {
                $nodes[$slug] = [
                    'slug'     => $slug,
                    'name'     => $name,
                    'parent'   => trim((string) ($row['parent_slug'] ?? '')),
                    'children' => 0,
                    'products' => 0,
                    'ids'      => [],
                ];
            } elseif ($nodes[$slug]['name'] === $slug && $name !== '') {
                $nodes[$slug]['name'] = $name;
            }
            if ($nodes[$slug]['parent'] === '' && trim((string) ($row['parent_slug'] ?? '')) !== '') {
                $nodes[$slug]['parent'] = trim((string) $row['parent_slug']);
            }
        }

        foreach ($nodes as $slug => $node) {
            $nodes[$slug]['children'] = 0;
        }
        foreach ($nodes as $slug => $node) {
            $parent = $node['parent'];
            if ($parent !== '' && isset($nodes[$parent]) && $parent !== $slug) {
                $nodes[$parent]['children']++;
            }
        }

        $out = [];
        foreach ($nodes as $node) {
            $node['products'] = count($node['ids']);
            unset($node['ids']);
            $out[] = $node;
        }

        usort($out, static function (array $a, array $b): int {
            return ($b['products'] <=> $a['products']) ?: strcmp($a['name'], $b['name']);
        });

        return $out;
    }

    /**
     * Ürünü olmasa da product_categories kaydı. Canonical slug'lar buradan doğrulanır.
     *
     * @return list<array{slug:string,name:string,parent_slug:string}>
     */
    private static function categoryRecords(): array
    {
        try {
            $rows = Cache::remember('assistant_v2.category_records.v1', 300, static function (): array {
                return Database::select(
                    "SELECT pc.slug, pc.name, COALESCE(parent.slug, '') AS parent_slug
                     FROM product_categories pc
                     LEFT JOIN product_categories parent ON parent.id = pc.parent_id AND parent.deleted_at IS NULL
                     WHERE pc.deleted_at IS NULL"
                );
            });
        } catch (\Throwable) {
            return [];
        }

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @return array{slug:string,name:string,parent:string,children:int,products:int}|null
     */
    public static function node(string $slug): ?array
    {
        foreach (self::taxonomy() as $node) {
            if ($node['slug'] === $slug) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @return array{slug:string,name:string,parent:string,children:int,products:int}|null
     */
    public static function resolveGroup(string $proposal): ?array
    {
        $proposal = trim($proposal);
        if ($proposal === '') {
            return null;
        }
        $folded = self::fold($proposal);
        $slugish = str_replace(' ', '-', $folded);
        foreach (self::taxonomy() as $node) {
            if ($node['slug'] === $proposal || $node['slug'] === $slugish || self::fold($node['name']) === $folded) {
                return $node;
            }
        }

        $best = null;
        $bestScore = 0.0;
        foreach (self::taxonomy() as $node) {
            $tokens = self::distinctTokens($node['name'] . ' ' . str_replace('-', ' ', $node['slug']));
            if ($tokens === []) {
                continue;
            }
            $matched = [];
            foreach ($tokens as $token) {
                // Kelime sınırı: hassas, hassasiyetli içinde sayılmaz. Yalnızca kısa çekim ekine izin verilir.
                if (preg_match('/(^|[^a-z0-9])' . preg_quote($token, '/') . '[a-z]{0,3}([^a-z0-9]|$)/', $folded) === 1) {
                    $matched[] = $token;
                }
            }
            // "fiyat" tek başına fiyat sorusudur; Fiyat Hesaplamalı Teraziler için "hesap" de geçmeli
            $matched = array_values(array_filter(
                $matched,
                static fn (string $t): bool => !in_array($t, ['fiyat', 'ucret'], true) || preg_match('/hesap/', $folded) === 1
            ));
            if ($matched === []) {
                continue;
            }
            $coverage = count($matched) / count($tokens);
            if ($coverage < 0.5) {
                continue;
            }
            $score = ($coverage * 100) + array_sum(array_map('strlen', $matched));
            if ($score > $bestScore) {
                $best = $node;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Boş bir yaprağın ayırt edici adı mesajda var mı. "kantar" gibi ortak kelime yetmez.
     *
     * @param array{name?:string,slug?:string} $node
     */
    public static function messageNamesGroup(string $message, array $node): bool
    {
        $fold = self::fold($message);
        if ($fold === '') {
            return false;
        }
        $generic = [
            'terazi' => true, 'teraziler' => true, 'terazisi' => true,
            'kantar' => true, 'kantari' => true, 'kantarlar' => true,
            'tarti' => true, 'tartim' => true, 'cihaz' => true, 'cihazlari' => true,
            'sistem' => true,
        ];
        $tokens = self::distinctTokens(
            (string) ($node['name'] ?? '') . ' ' . str_replace('-', ' ', (string) ($node['slug'] ?? ''))
        );
        $specific = [];
        foreach ($tokens as $token) {
            if (!isset($generic[$token]) && strlen($token) >= 3) {
                $specific[] = $token;
            }
        }
        if ($specific === []) {
            return false;
        }
        foreach ($specific as $token) {
            if (preg_match('/(^|[^a-z0-9])' . preg_quote($token, '/') . '[a-z]{0,3}([^a-z0-9]|$)/', $fold) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function isChildOf(string $childSlug, string $parentSlug): bool
    {
        $node = self::node($childSlug);
        $guard = 0;
        while ($node !== null && $guard < 6) {
            if ($node['parent'] === $parentSlug) {
                return true;
            }
            if ($node['parent'] === '' || $node['parent'] === $node['slug']) {
                return false;
            }
            $node = self::node($node['parent']);
            $guard++;
        }

        return false;
    }

    public static function isBroad(string $slug): bool
    {
        $node = self::node($slug);
        if ($node === null) {
            return false;
        }

        return $node['children'] > 0;
    }

    public static function isJewelryParent(string $slug): bool
    {
        $node = self::node($slug);
        if ($node === null || $node['children'] < 1) {
            return false;
        }
        $name = self::fold($node['name'] . ' ' . $node['slug']);

        return str_contains($name, 'kuyumcu');
    }

    /**
     * @param array<string, mixed> $product
     */
    public static function inGroup(array $product, string $slug, bool $exact): bool
    {
        foreach ($product['categories'] ?? [] as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $own = (string) ($cat['slug'] ?? '');
            if ($own === $slug) {
                return true;
            }
            if ($exact) {
                continue;
            }
            if ((string) ($cat['parent_slug'] ?? '') === $slug || (string) ($cat['root_slug'] ?? '') === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function productSlugsIn(string $message): array
    {
        $pattern = '~(?:^|[^\w.])(?:https?://)?(?:www\.)?terazibul\.com/urun/([a-z0-9][a-z0-9-]*)~iu';
        if (preg_match_all($pattern, ' ' . $message, $matches) < 1) {
            return [];
        }
        $slugs = [];
        foreach ($matches[1] as $slug) {
            $slug = mb_strtolower(rawurldecode((string) $slug));
            $slug = preg_replace('/[^a-z0-9-].*$/u', '', $slug) ?? $slug;
            if ($slug === '' || isset($slugs[$slug])) {
                continue;
            }
            if (isset(self::bySlug()[$slug])) {
                $slugs[$slug] = $slug;
            }
        }

        return array_values($slugs);
    }

    /**
     * Mesajdaki model kodunu katalog adıyla eşler. Yalnızca tek ürüne oturursa döner.
     *
     * @return list<string>
     */
    public static function namedProductSlugs(string $message): array
    {
        $tokens = [];
        foreach (preg_split('/[^a-z0-9]+/u', self::fold($message)) ?: [] as $token) {
            if (!is_string($token) || strlen($token) < 4) {
                continue;
            }
            if (preg_match('/[a-z]/', $token) !== 1 || preg_match('/\d/', $token) !== 1) {
                continue;
            }
            $tokens[$token] = true;
        }
        if ($tokens === []) {
            return [];
        }
        $hits = [];
        foreach (self::products() as $product) {
            $hay = self::fold((string) ($product['name'] ?? '') . ' ' . (string) ($product['slug'] ?? ''));
            foreach (array_keys($tokens) as $token) {
                if (str_contains($hay, $token)) {
                    $hits[(string) $product['slug']] = true;
                    break;
                }
            }
        }

        return count($hits) === 1 ? array_keys($hits) : [];
    }

    /**
     * @return list<string>
     */
    private static function distinctTokens(string $text): array
    {
        $folded = self::fold($text);
        $parts = preg_split('/[^a-z0-9]+/u', $folded) ?: [];
        $stop = [
            'terazi' => true, 'terazisi' => true, 'teraziler' => true, 'terazileri' => true, 'terazilerinin' => true,
            'cihaz' => true, 'cihazi' => true, 'cihazlari' => true, 'urun' => true, 'urunu' => true, 'urunleri' => true,
            'model' => true, 'modeli' => true, 'modelleri' => true, 'lazim' => true, 'ariyorum' => true,
            'istiyorum' => true, 'goster' => true, 'gosterin' => true, 'icin' => true, 'olan' => true,
            'olanlar' => true, 'hepsi' => true, 'kadar' => true, 'yaklasik' => true, 'civari' => true,
            'fazla' => true, 'diger' => true, 'digerleri' => true,
        ];
        $out = [];
        foreach ($parts as $part) {
            if (strlen($part) < 3 || isset($stop[$part]) || isset($out[$part])) {
                continue;
            }
            $out[$part] = $part;
        }

        return array_values($out);
    }

    /**
     * Ürünün kategori kayıtlarındaki kullanım alanları. Arama filtresi ve tek özellik satırı değildir.
     *
     * @param array<string, mixed> $product
     * @return list<string>
     */
    public static function usagesOf(array $product): array
    {
        $rows = is_array($product['categories'] ?? null) ? $product['categories'] : [];
        $names = [];
        foreach ($rows as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $slug = trim((string) ($cat['slug'] ?? ''));
            $name = trim((string) ($cat['name'] ?? ''));
            if ($slug !== '' && $name !== '') {
                $names[$slug] = $name;
            }
            $parent = trim((string) ($cat['parent_slug'] ?? ''));
            $parentName = trim((string) ($cat['parent_name'] ?? ''));
            if ($parent !== '' && $parentName !== '') {
                $names[$parent] = $parentName;
            }
        }

        $out = [];
        foreach ($rows as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $slug = trim((string) ($cat['slug'] ?? ''));
            $parent = trim((string) ($cat['parent_slug'] ?? ''));
            $root = trim((string) ($cat['root_slug'] ?? ''));
            $usage = $root !== '' ? $root : ($parent !== '' ? $parent : $slug);
            if ($usage === '' || isset(self::CONDITION_ROOTS[$usage]) || isset($out[$usage])) {
                continue;
            }
            $label = $names[$usage] ?? '';
            if ($label === '' && $root !== '') {
                $node = self::node($usage);
                $label = is_array($node) ? trim((string) ($node['name'] ?? '')) : '';
            }
            if ($label === '') {
                continue;
            }
            $out[$usage] = $label;
        }

        return array_values($out);
    }

    /**
     * İstenen ifade, ürünün kayıtlı kullanım alanlarından birine oturuyor mu.
     *
     * @param array<string, mixed> $product
     */
    public static function usageMatches(array $product, string $query): bool
    {
        $needle = self::fold($query);
        if (mb_strlen($needle) < 4) {
            return false;
        }
        foreach (self::usagesOf($product) as $name) {
            $hay = self::fold($name);
            if ($hay !== '' && (str_contains($hay, $needle) || str_contains($needle, $hay))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Üst kullanım alanı. Kök kategorinin kendisi ya da ona çıkan ata.
     *
     * @param array{slug:string,name:string,parent:string,children:int,products:int} $node
     */
    public static function usageSlug(array $node): string
    {
        $current = $node;
        $guard = 0;
        while ($current['parent'] !== '' && $guard < 6) {
            $parent = self::node($current['parent']);
            if ($parent === null) {
                break;
            }
            $current = $parent;
            $guard++;
        }

        return $current['slug'];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array{slug:string,name:string,count:int}>
     */
    public static function usagesIn(array $products): array
    {
        $counts = [];
        foreach ($products as $product) {
            $seen = [];
            foreach (is_array($product['categories'] ?? null) ? $product['categories'] : [] as $cat) {
                if (!is_array($cat)) {
                    continue;
                }
                $slug = trim((string) ($cat['slug'] ?? ''));
                $node = $slug !== '' ? self::node($slug) : null;
                if ($node === null) {
                    $root = trim((string) ($cat['root_slug'] ?? ''));
                    $node = $root !== '' ? self::node($root) : null;
                }
                if ($node === null) {
                    continue;
                }
                $usage = self::usageSlug($node);
                $usageNode = self::node($usage);
                if ($usageNode === null || isset($seen[$usage]) || isset(self::CONDITION_ROOTS[$usage])) {
                    continue;
                }
                $seen[$usage] = true;
                $counts[$usage] = [
                    'slug'  => $usage,
                    'name'  => $usageNode['name'],
                    'count' => ($counts[$usage]['count'] ?? 0) + 1,
                ];
            }
        }
        $out = array_values($counts);
        usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $out;
    }

    public static function statedUsage(string $message): ?string
    {
        $fold = self::fold($message);
        if ($fold === '') {
            return null;
        }
        $best = null;
        $bestLen = 0;
        foreach (self::taxonomy() as $node) {
            if ($node['parent'] !== '') {
                continue;
            }
            foreach ([self::fold($node['name']), self::fold(str_replace('-', ' ', $node['slug']))] as $label) {
                if ($label !== '' && strlen($label) >= 4 && str_contains($fold, $label) && strlen($label) > $bestLen) {
                    $best = $node['slug'];
                    $bestLen = strlen($label);
                }
            }
        }
        if ($best !== null) {
            return $best;
        }

        return self::usageRootHint($fold);
    }

    /**
     * Yazım hatası / kısaltma: kıyumcu → kuyumcu, lab terazisi → laboratuvar.
     */
    private static function usageRootHint(string $fold): ?string
    {
        if (preg_match('/\b(kuyumcu|kiyumcu|kuyum)\b/u', $fold) === 1) {
            return 'kuyumcu';
        }
        if (preg_match('/\blaboratuvar\b/u', $fold) === 1) {
            return 'laboratuvar';
        }
        if (
            preg_match('/\blab\b/u', $fold) === 1
            && preg_match('/\b(terazi|terazisi|teraziler|tarti|tartisi|tartim)\b/u', $fold) === 1
        ) {
            return 'laboratuvar';
        }
        if (self::labScaleDiscovery($fold)) {
            return 'laboratuvar';
        }

        return null;
    }

    /**
     * Mikro / yarı mikro / analitik terazi niyeti (laboratuvar tartım ailesi).
     */
    /**
     * @param array<string, mixed> $filters
     */
    public static function labMeasureFlow(array $filters): bool
    {
        $cat = is_string($filters['category'] ?? null) ? $filters['category'] : '';
        if ($cat !== 'laboratuvar') {
            return false;
        }
        $type = is_string($filters['type_token'] ?? null) ? $filters['type_token'] : '';
        if (in_array($type, ['mikro', 'yari mikro', 'analitik', 'yari analitik', 'hassas'], true)) {
            return true;
        }
        $subSlug = is_string($filters['subcategory'] ?? null) ? trim($filters['subcategory']) : '';
        $subFold = '';
        if ($subSlug !== '') {
            $node = self::node($subSlug);
            $subFold = $node !== null
                ? self::fold($node['name'] . ' ' . $node['slug'])
                : self::fold($subSlug);
        }

        return str_contains($subFold, 'mikro') || str_contains($subFold, 'analitik') || str_contains($subFold, 'hassas');
    }

    public static function labScaleDiscovery(string $message): bool
    {
        $fold = self::fold($message);
        if ($fold === '') {
            return false;
        }
        if (self::statedLabScaleType($fold) === null) {
            return false;
        }

        return preg_match('/\b(terazi|terazisi|teraziler|tarti|tartisi|tartim|balance|scale)\b/u', $fold) === 1;
    }

    /**
     * Mesajdaki laboratuvar terazi sınıfı. Uzun eşleşme önce (yarı mikro → mikro).
     */
    public static function statedLabScaleType(string $message): ?string
    {
        $fold = self::fold($message);
        if ($fold === '') {
            return null;
        }
        if (preg_match('/\b(yari|semi)[- ]?(mikro|micro)\b/u', $fold) === 1) {
            return 'yari mikro';
        }
        if (preg_match('/\b(yari|semi)[- ]?(analitik|analytical)\b/u', $fold) === 1) {
            return 'yari analitik';
        }
        if (preg_match('/\b(ultra[- ]?mikro|mikro[- ]?terazi|mikroterazi)\b/u', $fold) === 1) {
            return 'mikro';
        }
        if (
            preg_match('/\bmikro\b/u', $fold) === 1
            && preg_match('/\b(yari|semi)\b/u', $fold) !== 1
            && preg_match('/\b(terazi|terazisi|balance|scale|tarti)\b/u', $fold) === 1
        ) {
            return 'mikro';
        }
        if (preg_match('/\b(analitik|analytical)[- ]?(terazi|balance|scale)?\b/u', $fold) === 1) {
            return 'analitik';
        }
        if (preg_match('/\b(hassas|precision)[- ]?(terazi|balance|scale)\b/u', $fold) === 1) {
            if (preg_match('/\blaboratuvar\b|\blab\b/u', $fold) === 1) {
                return 'hassas';
            }

            return null;
        }

        return null;
    }

    /**
     * «Hassas terazi» tek başına laboratuvar değildir. Kullanım amacı ayrıca söylenmelidir.
     */
    public static function barePrecisionScale(string $message): bool
    {
        $fold = self::fold($message);
        if ($fold === '' || preg_match('/\b(hassas|precision)\b/u', $fold) !== 1) {
            return false;
        }
        if (preg_match('/\b(terazi|terazisi|teraziler|balance|scale)\b/u', $fold) !== 1) {
            return false;
        }
        if (preg_match('/\blaboratuvar\b|\blab\b|\bkuyum|\baltin\b|\btaki\b|\buretim\b|\bfabrika\b|\bendustri/u', $fold) === 1) {
            return false;
        }
        if (preg_match('/\b(analitik|analytical|mikro|micro)\b/u', $fold) === 1) {
            return false;
        }

        return true;
    }

    /**
     * Katalog eşleşmesinde mikro ile yarı mikro karışmasın.
     */
    public static function typeTokenMatches(string $hay, string $token): bool
    {
        $hay = self::fold($hay);
        $token = self::fold($token);
        if ($hay === '' || $token === '') {
            return false;
        }
        if ($token === 'yari mikro') {
            return str_contains($hay, 'yari mikro') || str_contains($hay, 'yari-mikro');
        }
        if ($token === 'mikro') {
            if (str_contains($hay, 'yari mikro') || str_contains($hay, 'yari-mikro')) {
                return false;
            }

            return preg_match('/(^|[^a-z0-9])mikro([^a-z0-9]|$)/', $hay) === 1
                || str_contains($hay, 'mikro-terazi');
        }
        if ($token === 'yari analitik') {
            return str_contains($hay, 'yari analitik') || str_contains($hay, 'yari-analitik');
        }
        if ($token === 'analitik') {
            if (str_contains($hay, 'yari analitik') || str_contains($hay, 'yari-analitik')) {
                return false;
            }

            return preg_match('/(^|[^a-z0-9])analitik([^a-z0-9]|$)/', $hay) === 1;
        }

        return str_contains($hay, $token);
    }

    /**
     * Alt kategori netleştirme havuzu: "mikro terazi" yarı mikro ürünlerini de kapsar.
     */
    public static function typeTokenMatchesFamily(string $hay, string $token): bool
    {
        $hay = self::fold($hay);
        $token = self::fold($token);
        if ($hay === '' || $token === '') {
            return false;
        }
        if ($token === 'mikro') {
            return preg_match('/(^|[^a-z0-9])mikro([^a-z0-9]|$)/', $hay) === 1
                || str_contains($hay, 'mikro-terazi')
                || str_contains($hay, 'yari-mikro');
        }

        return self::typeTokenMatches($hay, $token);
    }

    /**
     * @return list<string>
     */
    public static function childrenForType(string $usage, string $token): array
    {
        $token = self::fold($token);
        if ($usage === '' || $token === '') {
            return [];
        }
        $hits = [];
        foreach (self::taxonomy() as $node) {
            if ($node['children'] > 0 || self::usageSlug($node) !== $usage || $node['slug'] === $usage) {
                continue;
            }
            $hay = self::fold($node['name'] . ' ' . str_replace('-', ' ', $node['slug']));
            $matches = $token === 'mikro'
                ? self::typeTokenMatchesFamily($hay, $token)
                : self::typeTokenMatches($hay, $token);
            if ($matches) {
                $hits[] = $node['slug'];
            }
        }

        return $hits;
    }

    /**
     * @return array<string, mixed>
     */
    public static function labScaleFilters(string $message): array
    {
        $filters = [
            'category'    => 'laboratuvar',
            'subcategory' => null,
            'type_token'  => null,
        ];
        $type = self::statedLabScaleType($message);
        if ($type === null) {
            return $filters;
        }
        $root = self::statedUsage($message);
        if ($root !== null && $root !== 'laboratuvar') {
            return $filters;
        }
        $children = self::childrenForType('laboratuvar', $type);
        if (count($children) === 1) {
            $only = $children[0];
            $onlyNode = self::node($only);
            $onlyHay = $onlyNode !== null
                ? self::fold($onlyNode['name'] . ' ' . str_replace('-', ' ', $onlyNode['slug']))
                : '';
            if ($type === 'mikro' && $onlyHay !== '' && str_contains($onlyHay, 'yari mikro')) {
                $filters['type_token'] = 'mikro';

                return $filters;
            }
            $filters['subcategory'] = $only;

            return $filters;
        }
        if ($type === 'mikro' && count($children) >= 2) {
            $filters['type_token'] = 'mikro';

            return $filters;
        }
        if (count($children) >= 2) {
            $filters['type_token'] = $type;

            return $filters;
        }
        if (count($children) === 0 && $type !== '') {
            $filters['type_token'] = $type;
        }

        return $filters;
    }

    /**
     * Ürünü olan kullanım kökleri. Takasa Uygun ve Yenilenmiş yok.
     * slug => ad.
     *
     * @return array<string, string>
     */
    public static function usageRoots(): array
    {
        $rank = [
            'kuyumcu' => 0,
            'laboratuvar' => 1,
            'endustriyel' => 2,
            'perakende' => 3,
            'test-ve-olcum' => 4,
            'mutfak-hobi' => 5,
            'aksesuar' => 6,
        ];
        $rows = [];
        foreach (self::taxonomy() as $node) {
            if ($node['parent'] !== '' || $node['products'] < 1) {
                continue;
            }
            $label = self::fold($node['slug'] . ' ' . $node['name']);
            if (isset(self::CONDITION_ROOTS[$node['slug']]) || str_contains($label, 'yenilen') || str_contains($label, 'takas')) {
                continue;
            }
            $rows[] = $node;
        }
        usort($rows, static function (array $a, array $b) use ($rank): int {
            $ar = $rank[$a['slug']] ?? 50;
            $br = $rank[$b['slug']] ?? 50;

            return ($ar <=> $br) ?: strcmp($a['name'], $b['name']);
        });
        $out = [];
        foreach ($rows as $node) {
            $out[$node['slug']] = $node['name'];
        }

        return $out;
    }

    /**
     * Aynı tür birden fazla kullanım alanında varsa ayırt edici kelime. Örn. hassas.
     */
    public static function ambiguousTypeToken(string $message): ?string
    {
        $best = null;
        $bestLen = 0;
        foreach (self::distinctTokens($message) as $token) {
            if (strlen($token) < 5) {
                continue;
            }
            $parents = [];
            foreach (self::taxonomy() as $node) {
                if ($node['parent'] === '') {
                    continue;
                }
                $hay = self::fold($node['name'] . ' ' . str_replace('-', ' ', $node['slug']));
                if (!str_contains($hay, $token)) {
                    continue;
                }
                $usage = self::usageSlug($node);
                if ($usage !== '' && $usage !== $node['slug']) {
                    $parents[$usage] = true;
                }
            }
            if (count($parents) >= 2 && strlen($token) > $bestLen) {
                $best = $token;
                $bestLen = strlen($token);
            }
        }

        return $best;
    }

    /**
     * Tek bir kullanım alanına ait, mesajda adı geçen alt grup.
     *
     * @return array{category:string,subcategory:string}|null
     */
    public static function statedChild(string $message): ?array
    {
        if (self::ambiguousTypeToken($message) !== null || self::statedUsage($message) !== null) {
            return null;
        }
        $node = self::resolveGroup($message);
        if ($node === null || $node['parent'] === '') {
            return null;
        }
        $usage = self::usageSlug($node);
        if ($usage === '' || $usage === $node['slug']) {
            return null;
        }

        return ['category' => $usage, 'subcategory' => $node['slug']];
    }

    public static function childForType(string $usage, string $token): ?string
    {
        $token = self::fold($token);
        if ($usage === '' || $token === '') {
            return null;
        }
        $hits = [];
        foreach (self::taxonomy() as $node) {
            if ($node['children'] > 0 || self::usageSlug($node) !== $usage || $node['slug'] === $usage) {
                continue;
            }
            $hay = self::fold($node['name'] . ' ' . str_replace('-', ' ', $node['slug']));
            if (self::typeTokenMatches($hay, $token)) {
                $hits[] = $node['slug'];
            }
        }

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * Katalogda adı geçmeyen model kodu. Kısa seri adını uzun koda kırparak eşleştirmez.
     */
    public static function unresolvedModel(string $message): ?string
    {
        if (preg_match_all('/\b([A-Za-z]{2,6})[\s-]?(\d{3,6})\b/u', $message, $matches, PREG_SET_ORDER) < 1) {
            return null;
        }
        if (self::namedProductSlugs($message) !== [] || self::productSlugsIn($message) !== []) {
            return null;
        }
        foreach ($matches as $match) {
            $letters = strtolower((string) $match[1]);
            if (in_array($letters, ['model', 'seri', 'gram', 'adet', 'kadar'], true)) {
                continue;
            }
            $token = (string) $match[1] . (string) $match[2];
            $folded = self::fold($token);
            if (self::productContains($folded)) {
                return null;
            }

            return $token;
        }

        return null;
    }

    private static function productContains(string $foldedToken): bool
    {
        if ($foldedToken === '') {
            return false;
        }
        foreach (self::products() as $product) {
            $hay = self::fold((string) ($product['name'] ?? '') . ' ' . (string) ($product['slug'] ?? '') . ' ' . (string) ($product['model_no'] ?? ''));
            $compact = preg_replace('/[^a-z0-9]+/u', '', $hay) ?? $hay;
            $needle = preg_replace('/[^a-z0-9]+/u', '', $foldedToken) ?? $foldedToken;
            if ($needle !== '' && (str_contains($hay, $foldedToken) || str_contains($compact, $needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Katalog kaydından ürün tipi. Terazi varsayılanı değildir.
     *
     * @param array<string, mixed> $product
     */
    public static function kindOf(array $product): string
    {
        $parts = [(string) ($product['name'] ?? ''), (string) ($product['slug'] ?? '')];
        foreach (is_array($product['categories'] ?? null) ? $product['categories'] : [] as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $parts[] = (string) ($cat['name'] ?? '');
            $parts[] = (string) ($cat['slug'] ?? '');
        }

        return self::kindFromText(implode(' ', $parts));
    }

    /**
     * @param array{slug?:string,name?:string} $node
     */
    public static function kindOfNode(array $node): string
    {
        return self::kindFromText((string) ($node['name'] ?? '') . ' ' . (string) ($node['slug'] ?? ''));
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    public static function listNoun(array $products): string
    {
        $counts = [];
        foreach ($products as $product) {
            $kind = self::kindOf($product);
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }
        if ($counts === []) {
            return 'ürünler';
        }
        arsort($counts);
        $top = (string) array_key_first($counts);
        $nouns = [
            'kit' => 'kitler',
            'aksesuar' => 'aksesuarlar',
            'nem' => 'nem tayin cihazları',
            'tork' => 'tork ölçerler',
            'stand' => 'standlar',
            'terazi' => 'teraziler',
            'cihaz' => 'cihazlar',
        ];

        return $nouns[$top] ?? 'ürünler';
    }

    /**
     * Kit ve aksesuarda gram sorusu sorulmaz.
     *
     * @param list<array<string, mixed>> $products
     */
    public static function skipsMeasures(array $products): bool
    {
        if ($products === []) {
            return false;
        }
        foreach ($products as $product) {
            $kind = self::kindOf($product);
            if ($kind !== 'kit' && $kind !== 'aksesuar' && $kind !== 'stand' && $kind !== 'nem') {
                return false;
            }
        }

        return true;
    }

    public static function rejectsOffer(string $message): bool
    {
        $fold = self::fold($message);

        return preg_match('/(^| )(hayir|degil|istemiyorum|yanlis)( |$)/u', $fold) === 1;
    }

    /**
     * @return list<string>
     */
    public static function rejectedKinds(string $message): array
    {
        if (!self::rejectsOffer($message)) {
            return [];
        }
        $fold = self::fold($message);
        $out = [];
        if (preg_match('/kit\w*[^a-z0-9]{0,12}degil|degil[^a-z0-9]{0,12}kit/u', $fold) === 1) {
            $out[] = 'kit';
        }
        if (preg_match('/aksesuar\w*[^a-z0-9]{0,12}degil|degil[^a-z0-9]{0,12}aksesuar/u', $fold) === 1) {
            $out[] = 'aksesuar';
        }
        if (preg_match('/terazi\w*[^a-z0-9]{0,12}degil|degil[^a-z0-9]{0,12}terazi/u', $fold) === 1) {
            $out[] = 'terazi';
        }

        return $out;
    }

    /**
     * Katalog adı yakınlığı. Tek cümleye özel duvar değildir.
     *
     * @param list<string> $excludeKinds
     * @return list<array{slug:string,name:string,kind:string,score:int,direct:bool}>
     */
    public static function nearCatalog(string $message, array $excludeKinds = []): array
    {
        $fold = self::fold($message);
        $fold = preg_replace('/(^| )(hayir|degil|istemiyorum|yanlis|ariyorum|istiyorum|lazim|goster|lutfen|bana)( |$)/u', ' ', $fold) ?? $fold;
        $fold = trim(preg_replace('/\s+/u', ' ', $fold) ?? '');
        if ($fold === '') {
            return [];
        }
        $want = self::wantedKind($fold);
        $userTokens = self::contentTokens($fold);
        $measureAsk = self::wantsMeasureDevice($fold);
        $exclude = array_fill_keys($excludeKinds, true);
        if ($want === 'cihaz') {
            $exclude['kit'] = true;
        }
        $rows = [];
        foreach (self::taxonomy() as $node) {
            if ($node['products'] < 1) {
                continue;
            }
            $kind = self::kindOfNode($node);
            if (isset($exclude[$kind])) {
                continue;
            }
            if (AssistantV2Prompt::conversational() && self::isConditionNode($node)) {
                continue;
            }
            $name = trim((string) $node['name']);
            $nameFold = self::fold($name . ' ' . str_replace('-', ' ', (string) $node['slug']));
            $tokens = self::contentTokens($nameFold);
            $shared = 0;
            foreach ($userTokens as $token) {
                if (self::tokenHits($token, $tokens) || self::tokenHits($token, self::contentTokens($nameFold))) {
                    $shared++;
                }
            }
            $score = $shared * 50;
            if ($userTokens !== [] && $shared === count($userTokens)) {
                $score += 20;
            }
            if ($measureAsk && $kind !== 'terazi' && $kind !== 'kit' && $kind !== 'aksesuar') {
                if (self::textHasStem($nameFold, 'olcum') || str_contains($nameFold, 'tayin')) {
                    $score += 45;
                }
            }
            if ($want === 'kit' && $kind === 'kit') {
                $score += 25;
            }
            if ($want === 'cihaz' && $kind === 'kit') {
                $score -= 80;
            }
            if ($want === 'cihaz' && $kind === 'terazi' && !$measureAsk) {
                $score -= 20;
            }
            if ($want === 'cihaz' && $kind === 'terazi' && $measureAsk) {
                $score -= 40;
            }
            if ($score < 40) {
                continue;
            }
            $phrase = self::offerPhrase($node);
            $phraseFold = self::fold($phrase);
            $phraseTokens = self::contentTokens($phraseFold);
            $covered = $phraseTokens !== [];
            foreach ($phraseTokens as $token) {
                if (!self::tokenHits($token, $userTokens)) {
                    $covered = false;
                    break;
                }
            }
            $direct = $covered || ($phraseFold !== '' && str_contains($fold, $phraseFold));
            $rows[] = [
                'slug' => (string) $node['slug'],
                'name' => $phrase,
                'kind' => $kind,
                'score' => $score,
                'direct' => $direct,
            ];
        }
        if ($rows === []) {
            return [];
        }
        usort($rows, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['name'], $b['name']));
        $best = $rows[0]['score'];
        $pool = [];
        foreach ($rows as $row) {
            if ($measureAsk) {
                if ($row['score'] < 45 || self::nodeDepth($row['slug']) > 1) {
                    continue;
                }
            } elseif ($row['score'] < $best - 20 || $row['score'] < 40) {
                continue;
            }
            $pool[] = $row;
        }
        $childParents = [];
        foreach ($pool as $row) {
            $node = self::node($row['slug']);
            if ($node !== null && $node['parent'] !== '') {
                $childParents[$node['parent']] = true;
            }
        }
        $kept = [];
        $seen = [];
        foreach ($pool as $row) {
            $node = self::node($row['slug']);
            if ($node !== null && $node['children'] > 0 && isset($childParents[$row['slug']])) {
                continue;
            }
            $key = self::fold($row['name']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $kept[] = $row;
            if (count($kept) >= 6) {
                break;
            }
        }

        return $kept;
    }

    /**
     * Takasa Uygun ve Yenilenmiş, ürün durumu etiketidir. Takas veya 2. el soruları hizmet konusudur.
     *
     * @param array{slug?:string,name?:string,parent?:string} $node
     */
    private static function isConditionNode(array $node): bool
    {
        $slug = (string) ($node['slug'] ?? '');
        $parent = (string) ($node['parent'] ?? '');
        if (isset(self::CONDITION_ROOTS[$slug]) || ($parent !== '' && isset(self::CONDITION_ROOTS[$parent]))) {
            return true;
        }
        $fold = self::fold((string) ($node['name'] ?? '') . ' ' . $slug);

        return str_contains($fold, 'takas') || str_contains($fold, 'yenilen');
    }

    /**
     * @param array{slug:string,name:string} $node
     */
    private static function offerPhrase(array $node): string
    {
        $name = trim((string) ($node['name'] ?? ''));
        $shared = null;
        foreach (self::products() as $product) {
            if (!self::inGroup($product, (string) ($node['slug'] ?? ''), true)) {
                continue;
            }
            $phrase = self::typePhrase($product);
            if ($phrase === '') {
                continue;
            }
            if ($shared === null) {
                $shared = $phrase;
                continue;
            }
            if (self::fold($shared) !== self::fold($phrase)) {
                return $name;
            }
        }

        return $shared !== null && $shared !== '' ? $shared : $name;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function typePhrase(array $product): string
    {
        $brand = self::fold((string) ($product['brand'] ?? ''));
        $tokens = preg_split('/\s+/u', trim((string) ($product['name'] ?? ''))) ?: [];
        $kept = [];
        $started = false;
        foreach ($tokens as $token) {
            $fold = self::fold($token);
            $compact = preg_replace('/[^a-z0-9]+/u', '', $fold) ?? $fold;
            $isBrand = $brand !== '' && ($fold === $brand || str_starts_with($brand, $fold) || str_starts_with($fold, $brand));
            $isModel = preg_match('/^[a-z]{0,4}\d|\d{2,}/i', $compact) === 1 || strlen($compact) <= 3;
            if (!$started && ($isBrand || $isModel || in_array($fold, ['ve', 'and', 'ile'], true))) {
                continue;
            }
            $started = true;
            $kept[] = $token;
        }
        $phrase = trim(implode(' ', $kept));
        if (mb_strlen($phrase) < 4 || mb_strlen($phrase) > 64) {
            return '';
        }

        return $phrase;
    }

    private static function kindFromText(string $text): string
    {
        $fold = self::fold($text);
        if (self::textHasStem($fold, 'kit')) {
            return 'kit';
        }
        if (self::textHasStem($fold, 'aksesuar')) {
            return 'aksesuar';
        }
        if (str_contains($fold, 'nem') && str_contains($fold, 'tayin')) {
            return 'nem';
        }
        if (str_contains($fold, 'tork')) {
            return 'tork';
        }
        if (self::textHasStem($fold, 'stand') || str_contains($fold, 'masa')) {
            return 'stand';
        }
        if (self::textHasStem($fold, 'terazi') || str_contains($fold, 'kantar')) {
            return 'terazi';
        }

        return 'cihaz';
    }

    private static function wantedKind(string $fold): string
    {
        $kit = self::textHasStem($fold, 'kit');
        $device = self::textHasStem($fold, 'cihaz') || str_contains($fold, 'olcer') || str_contains($fold, 'tayin');
        if ($device && !$kit) {
            return 'cihaz';
        }
        if ($kit && !$device) {
            return 'kit';
        }

        return '';
    }

    private static function wantsMeasureDevice(string $fold): bool
    {
        return self::textHasStem($fold, 'olcum') && (self::textHasStem($fold, 'cihaz') || !self::textHasStem($fold, 'kit'));
    }

    /**
     * @return list<string>
     */
    private static function contentTokens(string $fold): array
    {
        $stop = [
            'icin' => true, 'olan' => true, 'olanlar' => true, 'bir' => true, 'bu' => true, 'su' => true,
            've' => true, 'ile' => true, 'mi' => true, 'mu' => true, 'var' => true, 'yok' => true,
            'ariyorum' => true, 'istiyorum' => true, 'lazim' => true, 'goster' => true, 'hayir' => true, 'degil' => true,
        ];
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/u', $fold) ?: [] as $token) {
            if (!is_string($token) || strlen($token) < 4 || isset($stop[$token]) || isset($out[$token])) {
                continue;
            }
            $stem = self::stem($token);
            if (in_array($stem, ['kit', 'cihaz', 'terazi', 'urun'], true)) {
                continue;
            }
            $out[$token] = $token;
        }

        return array_values($out);
    }

    /**
     * @param list<string> $tokens
     */
    private static function tokenHits(string $token, array $tokens): bool
    {
        $stem = self::stem($token);
        foreach ($tokens as $have) {
            if ($token === $have || self::stem($have) === $stem) {
                return true;
            }
            $short = strlen($token) <= strlen($have) ? $token : $have;
            $long = $short === $token ? $have : $token;
            if (strlen($short) >= 5 && str_starts_with($long, $short)) {
                return true;
            }
        }

        return false;
    }

    private static function textHasStem(string $fold, string $stem): bool
    {
        foreach (preg_split('/[^a-z0-9]+/u', $fold) ?: [] as $token) {
            if (is_string($token) && $token !== '' && self::stem($token) === $stem) {
                return true;
            }
        }

        return false;
    }

    private static function nodeDepth(string $slug): int
    {
        $depth = 0;
        $guard = 0;
        $node = self::node($slug);
        while ($node !== null && $node['parent'] !== '' && $guard < 6) {
            $depth++;
            $node = self::node($node['parent']);
            $guard++;
        }

        return $depth;
    }

    private static function stem(string $token): string
    {
        foreach (['olcum', 'olcer', 'olcu'] as $stem) {
            if ($token === $stem || str_starts_with($token, $stem)) {
                return 'olcum';
            }
        }
        foreach (['kit', 'cihaz', 'terazi', 'aksesuar', 'stand', 'urun'] as $stem) {
            if ($token === $stem || str_starts_with($token, $stem)) {
                return $stem;
            }
        }

        return $token;
    }
}
