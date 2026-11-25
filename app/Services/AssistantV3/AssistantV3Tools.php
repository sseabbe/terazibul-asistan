<?php

declare(strict_types=1);

namespace App\Services\AssistantV3;

use App\Services\AssistantV2\AssistantV2AdvisorRagStore;
use App\Services\AssistantV2\AssistantV2AdvisorShortcuts;
use App\Services\AssistantV2\AssistantV2Catalog;

/**
 * Asistan V3 araçları: model yalnızca bunlar üzerinden sitedeki veriye ulaşır.
 * Her araç sonucu JSON'a çevrilebilir düz dizidir; URL'ler sunucuda üretilir.
 */
final class AssistantV3Tools
{
    /** Bu turda araçların döndürdüğü ürün id'leri (kart ve WhatsApp için izinli liste). */
    public array $seenProductIds = [];

    /** Bu turda araçların döndürdüğü URL'ler (model yalnızca bunlardan birini önerebilir). */
    public array $seenUrls = [];

    /** Fiyat doğrulaması için araçların döndürdüğü fiyat metinleri. */
    public array $seenPrices = [];

    /** Son ürün aramasının toplamı ve liste sayfası (kartlardan fazlası varsa "tümünü gör" için). */
    public int $lastSearchTotal = 0;

    /** Aramadan gelen ve adıyla istenen (get_product) ürünler */
    public array $searchIds = [];
    public array $namedIds = [];
    public string $lastListingUrl = '';

    public static function foldText(string $t): string
    {
        return AssistantV2Catalog::fold($t);
    }

    /** @return list<array<string,mixed>> OpenAI tools tanımı */
    public static function definitions(): array
    {
        return [
            self::fn('search_products', 'Katalogda ürün arar. Ürün önermeden önce mutlaka çağır. Kısıtları bilmiyorsan yalnızca kategori veya kullanım alanıyla çağır; en uygun ürünler döner.', [
                'query'              => ['type' => 'string', 'description' => 'Serbest metin: ürün tipi, kullanım alanı, model veya özellik (ör. "kuyumcu terazisi", "nem tayin", "paket tartımı").'],
                'category'           => ['type' => 'string', 'description' => 'Kategori adı veya slug (sistem mesajındaki kategori listesinden).'],
                'brand'              => ['type' => 'string'],
                'min_capacity_g'     => ['type' => 'number', 'description' => 'Gerekli en az kapasite, gram. 6 kg = 6000.'],
                'max_readability_g'  => ['type' => 'number', 'description' => 'Gerekli hassasiyet (okunabilirlik), gram. 0,01 g = 0.01; 0,1 mg = 0.0001.'],
                'condition'          => ['type' => 'string', 'enum' => ['new', 'refurbished']],
                'commercial_approval'=> ['type' => 'boolean', 'description' => 'Ticari onaylı (damgalı) olsun mu.'],
                'limit'              => ['type' => 'integer', 'description' => 'Varsayılan 5, en fazla 8.'],
            ]),
            self::fn('get_product', 'Tek bir ürünün tüm teknik özelliklerini, fiyat durumunu ve bağlantısını getirir. Kullanıcı bir model adı verdiğinde veya bir ürünü sorduğunda çağır.', [
                'product_id' => ['type' => 'integer'],
                'model'      => ['type' => 'string', 'description' => 'Model kodu veya ürün adı (ör. "CTL600-K", "PA214").'],
            ]),
            self::fn('search_site', 'Sitedeki içerikte arar: servis, kalibrasyon, garanti, teslimat, iade, 2. el/takas, şirket bilgileri, blog ve SSS.', [
                'query' => ['type' => 'string'],
            ], ['query']),
            self::fn('lookup_error_code', 'Terazi ekranındaki hata kodunu hata kodları veritabanında arar ve sayfa bağlantısını verir.', [
                'code'  => ['type' => 'string', 'description' => 'Ekrandaki kod, ör. "CAL E2", "Err 4".'],
                'brand' => ['type' => 'string'],
            ], ['code']),
        ];
    }

    /** @param array<string,mixed> $args */
    public function run(string $name, array $args): array
    {
        try {
            return match ($name) {
                'search_products'   => $this->searchProducts($args),
                'get_product'       => $this->getProduct($args),
                'search_site'       => $this->searchSite((string) ($args['query'] ?? '')),
                'lookup_error_code' => $this->lookupErrorCode((string) ($args['code'] ?? ''), (string) ($args['brand'] ?? '')),
                default             => ['error' => 'unknown_tool'],
            };
        } catch (\Throwable $e) {
            logger('assistant_v3.tool_error', ['tool' => $name, 'error' => $e->getMessage()], 'error');

            return ['error' => 'tool_failed'];
        }
    }

    // ------------------------------------------------------------------ Ürünler

    /** @param array<string,mixed> $a */
    private function searchProducts(array $a): array
    {
        $fold = static fn (string $t): string => AssistantV2Catalog::fold($t);
        $query = trim((string) ($a['query'] ?? ''));
        $category = $fold(trim((string) ($a['category'] ?? '')));
        $brand = $fold(trim((string) ($a['brand'] ?? '')));
        $minCap = is_numeric($a['min_capacity_g'] ?? null) ? (float) $a['min_capacity_g'] : null;
        $maxRead = is_numeric($a['max_readability_g'] ?? null) ? (float) $a['max_readability_g'] : null;
        $condition = (string) ($a['condition'] ?? '');
        $commercial = array_key_exists('commercial_approval', $a) && is_bool($a['commercial_approval']) ? $a['commercial_approval'] : null;
        $limit = max(1, min(8, (int) ($a['limit'] ?? 5)));

        $stop = ['terazi', 'terazisi', 'teraziler', 'tarti', 'tartisi', 'cihaz', 'cihazi', 'icin', 'olan', 'bir', 've', 'ile', 'model', 'urun', 'lazim', 'ariyorum', 'istiyorum'];
        $tokens = [];
        foreach (preg_split('/[^a-z0-9]+/u', $fold($query)) ?: [] as $t) {
            if (strlen($t) >= 2 && !in_array($t, $stop, true)) {
                $tokens[] = $t;
            }
        }

        $rows = [];
        foreach (AssistantV2Catalog::products() as $p) {
            $catText = '';
            foreach (is_array($p['categories'] ?? null) ? $p['categories'] : [] as $c) {
                $catText .= ' ' . ($c['name'] ?? '') . ' ' . ($c['slug'] ?? '') . ' ' . ($c['parent_name'] ?? '') . ' ' . ($c['parent_slug'] ?? '') . ' ' . ($c['root_slug'] ?? '');
            }
            $catFold = $fold($catText);
            if ($category !== '' && !str_contains($catFold, $category) && !str_contains($catFold, str_replace(' ', '-', $category))) {
                continue;
            }
            if ($brand !== '' && $fold((string) ($p['brand'] ?? '')) !== $brand && !str_contains($fold((string) ($p['brand'] ?? '')), $brand)) {
                continue;
            }
            $capMax = is_numeric($p['capacity_max'] ?? null) ? (float) $p['capacity_max'] : null;
            if ($minCap !== null && ($capMax === null || $capMax + 1e-9 < $minCap)) {
                continue;
            }
            $prec = is_numeric($p['precision_grams'] ?? null) ? (float) $p['precision_grams'] : null;
            if ($maxRead !== null && ($prec === null || $prec > $maxRead * 1.0001)) {
                continue;
            }
            $cond = $fold((string) ($p['condition'] ?? ''));
            $isRenewed = str_contains($cond, '2. el') || str_contains($cond, '2 el') || str_contains($cond, 'yenilen') || str_contains($cond, 'ikinci');
            if ($condition === 'refurbished' && !$isRenewed) {
                continue;
            }
            if ($condition === 'new' && $isRenewed) {
                continue;
            }
            if ($commercial !== null && (($p['commercial'] ?? null) === true) !== $commercial) {
                continue;
            }
            $score = 0;
            if ($tokens !== []) {
                $specText = '';
                foreach (is_array($p['specs'] ?? null) ? $p['specs'] : [] as $sp) {
                    $specText .= ' ' . ($sp['label'] ?? '') . ' ' . ($sp['value'] ?? '');
                }
                $filterText = '';
                foreach (is_array($p['listing_filters'] ?? null) ? $p['listing_filters'] : [] as $lf) {
                    $filterText .= ' ' . (is_array($lf) ? implode(' ', array_map('strval', array_filter($lf, 'is_scalar'))) : (string) $lf);
                }
                $hay = $fold(($p['name'] ?? '') . ' ' . ($p['model_no'] ?? '') . ' ' . ($p['brand'] ?? '') . ' ' . ($p['short'] ?? '') . ' ' . $specText . ' ' . $filterText) . ' ' . $catFold;
                foreach ($tokens as $t) {
                    if (str_contains($hay, $t)) {
                        $score++;
                    }
                }
                // Yalnız serbest metinle arandıysa en az bir kelime eşleşmeli
                if ($score === 0 && $category === '' && $brand === '' && $minCap === null && $maxRead === null) {
                    continue;
                }
            }
            $rows[] = ['p' => $p, 'score' => $score, 'cap' => $capMax ?? INF, 'prec' => $prec ?? INF, 'renewed' => $isRenewed];
        }

        usort($rows, static function (array $x, array $y) use ($minCap, $maxRead): int {
            if ($x['score'] !== $y['score']) {
                return $y['score'] <=> $x['score'];
            }
            // Kısıt verildiyse ihtiyaca en yakın (gereğinden çok büyük olmayan) ürün önce
            if ($minCap !== null && $x['cap'] !== $y['cap']) {
                return $x['cap'] <=> $y['cap'];
            }
            if ($maxRead !== null && $x['prec'] !== $y['prec']) {
                return $y['prec'] <=> $x['prec'];
            }
            $sx = (int) (($x['p']['in_stock'] ?? false) === true);
            $sy = (int) (($y['p']['in_stock'] ?? false) === true);
            if ($sx !== $sy) {
                return $sy <=> $sx;
            }

            return ((int) ($x['p']['sort_order'] ?? 0)) <=> ((int) ($y['p']['sort_order'] ?? 0));
        });

        $items = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $items[] = $this->summary($row['p'], $row['renewed']);
            $this->searchIds[(int) $row['p']['id']] = true;
        }
        $listing = $this->listingUrl($category, $brand);
        $this->lastSearchTotal = count($rows);
        $this->lastListingUrl = ($category !== '' || $brand !== '') ? $listing : '';

        return [
            'total'       => count($rows),
            'products'    => $items,
            'listing_url' => $listing,
            'note'        => count($rows) === 0 ? 'Bu kısıtlara uyan ürün yok. Kısıtı gevşetip tekrar ara veya kullanıcıya en yakın seçenekleri sor.' : '',
        ];
    }

    /** @param array<string,mixed> $a */
    private function getProduct(array $a): array
    {
        $id = (int) ($a['product_id'] ?? 0);
        $model = trim((string) ($a['model'] ?? ''));
        $product = null;
        if ($id > 0) {
            foreach (AssistantV2Catalog::products() as $p) {
                if ((int) $p['id'] === $id) {
                    $product = $p;
                    break;
                }
            }
        }
        if ($product === null && $model !== '') {
            $needle = preg_replace('/[^a-z0-9]+/u', '', AssistantV2Catalog::fold($model)) ?? '';
            $hits = [];
            foreach (AssistantV2Catalog::products() as $p) {
                $m = preg_replace('/[^a-z0-9]+/u', '', AssistantV2Catalog::fold((string) ($p['model_no'] ?? ''))) ?? '';
                $n = preg_replace('/[^a-z0-9]+/u', '', AssistantV2Catalog::fold((string) ($p['name'] ?? ''))) ?? '';
                if ($needle !== '' && strlen($needle) >= 3 && (($m !== '' && ($m === $needle || str_contains($needle, $m) || str_contains($m, $needle))) || str_contains($n, $needle))) {
                    $hits[] = $p;
                }
            }
            if (count($hits) === 1) {
                $product = $hits[0];
            } elseif (count($hits) > 1) {
                return [
                    'ambiguous' => true,
                    'matches'   => array_map(fn (array $p): array => $this->summary($p, null), array_slice($hits, 0, 6)),
                ];
            }
        }
        if ($product === null) {
            return ['found' => false, 'note' => 'Katalogda bu model yok. Satmadığımız bir ürün olabilir; benzer ürünleri search_products ile önerebilir veya WhatsApp yönlendirmesi yapabilirsin.'];
        }
        $out = $this->summary($product, null);
        $specs = [];
        foreach (is_array($product['specs'] ?? null) ? $product['specs'] : [] as $s) {
            $label = trim((string) ($s['label'] ?? ''));
            $value = trim((string) ($s['value'] ?? ''));
            if ($label !== '' && $value !== '') {
                $specs[] = $label . ': ' . $value;
            }
        }
        $out['found'] = true;
        $this->namedIds[(int) $product['id']] = true;
        $out['specs'] = $specs;

        return $out;
    }

    /** @param array<string,mixed> $p */
    private function summary(array $p, ?bool $renewed): array
    {
        $id = (int) ($p['id'] ?? 0);
        $this->seenProductIds[$id] = true;
        $url = (string) ($p['url'] ?? '');
        if ($url !== '') {
            $this->seenUrls[$url] = true;
        }
        $price = trim((string) ($p['price_label'] ?? ''));
        if ($price !== '') {
            $this->seenPrices[] = $price;
        }
        if ($renewed === null) {
            $c = AssistantV2Catalog::fold((string) ($p['condition'] ?? ''));
            $renewed = str_contains($c, '2. el') || str_contains($c, 'yenilen');
        }

        return [
            'id'          => $id,
            'name'        => (string) ($p['name'] ?? ''),
            'brand'       => (string) ($p['brand'] ?? ''),
            'model'       => (string) ($p['model_no'] ?? ''),
            'capacity'    => (string) ($p['capacity_label'] ?? ''),
            'readability' => (string) ($p['precision_label'] ?? ''),
            'condition'   => $renewed ? '2. el / yenilenmiş' : 'sıfır',
            'commercial_approval' => ($p['commercial'] ?? null) === true ? 'onaylı' : (($p['commercial'] ?? null) === false ? 'onaysız' : 'belirtilmemiş'),
            'price'       => $price !== '' ? $price : 'sitede fiyat yok — fiyat WhatsApp/telefonla verilir',
            'in_stock'    => ($p['in_stock'] ?? false) === true,
            'usage'       => implode(', ', array_slice(array_values(AssistantV2Catalog::usagesOf($p)), 0, 4)),
            'summary'     => mb_substr(trim((string) ($p['short'] ?? '')), 0, 220),
            'url'         => $url,
        ];
    }

    private function listingUrl(string $category, string $brand): string
    {
        $path = '/urunler';
        if ($category !== '') {
            $node = AssistantV2Catalog::node(str_replace(' ', '-', $category)) ?? AssistantV2Catalog::resolveGroup($category);
            if (is_array($node)) {
                $chain = [];
                $slug = (string) $node['slug'];
                for ($i = 0; $i < 4 && $slug !== ''; $i++) {
                    $n = AssistantV2Catalog::node($slug);
                    if ($n === null) {
                        break;
                    }
                    array_unshift($chain, $n['slug']);
                    $slug = (string) ($n['parent'] ?? '');
                }
                if ($chain !== []) {
                    $path .= '/' . implode('/', $chain);
                }
            }
        }
        $url = url($path);
        $this->seenUrls[$url] = true;

        return $url;
    }

    // ------------------------------------------------------------------ Site içeriği

    private function searchSite(string $query): array
    {
        $fold = AssistantV2Catalog::fold($query);
        $stop = ['ve', 'ile', 'icin', 'bir', 'mi', 'mu', 'ne', 'nasil', 'var', 'yok', 'olarak', 'bu', 'da', 'de', 'misiniz', 'musunuz'];
        $tokens = [];
        foreach (preg_split('/[^a-z0-9]+/u', $fold) ?: [] as $t) {
            if (strlen($t) >= 3 && !in_array($t, $stop, true)) {
                $tokens[] = strlen($t) > 6 ? substr($t, 0, 6) : $t; // kök: "kalibrasyonu" → "kalibr"
            }
        }
        if ($tokens === []) {
            return ['results' => []];
        }
        $scored = [];
        foreach (AssistantV2AdvisorRagStore::load()['chunks'] ?? [] as $c) {
            if (($c['type'] ?? '') === 'product') {
                continue;
            }
            $hay = (string) ($c['search'] ?? '');
            $s = 0;
            foreach ($tokens as $t) {
                $n = substr_count($hay, $t);
                if ($n > 0) {
                    $s += 3 + min($n, 5);
                }
            }
            if ($s > 0) {
                if (str_starts_with((string) ($c['id'] ?? ''), 'service:') || str_starts_with((string) ($c['id'] ?? ''), 'page:')) {
                    $s += 2;
                }
                $scored[] = [$s, $c];
            }
        }
        usort($scored, static fn ($x, $y) => $y[0] <=> $x[0]);
        $out = [];
        foreach (array_slice($scored, 0, 4) as [$s, $c]) {
            $url = (string) ($c['url'] ?? '');
            if ($url !== '') {
                $this->seenUrls[$url] = true;
            }
            $out[] = [
                'title' => (string) ($c['title'] ?? ''),
                'url'   => $url,
                'text'  => mb_substr(trim((string) ($c['body'] ?? '')), 0, 900),
            ];
        }

        return ['results' => $out];
    }

    // ------------------------------------------------------------------ Hata kodu

    private function lookupErrorCode(string $code, string $brand): array
    {
        $rows = AssistantV2AdvisorShortcuts::errorRecords(trim($brand . ' ' . $code . ' hata'));
        if ($rows === []) {
            return ['found' => false, 'note' => 'Bu kod kayıtlarımızda yok. Teşhis uydurma; servis talebi / WhatsApp / telefon öner.'];
        }
        $out = [];
        foreach (array_slice($rows, 0, 5) as $r) {
            $this->seenUrls[$r['url']] = true;
            $out[] = $r;
        }

        return ['found' => true, 'matches' => $out, 'more' => max(0, count($rows) - 5)];
    }

    /**
     * @param array<string,mixed> $props
     * @param list<string> $required
     */
    private static function fn(string $name, string $desc, array $props, array $required = []): array
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => $name,
                'description' => $desc,
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => (object) $props,
                    'required'   => $required,
                ],
            ],
        ];
    }
}
