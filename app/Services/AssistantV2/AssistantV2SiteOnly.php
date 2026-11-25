<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Tek tur: site katalog + işletme bağlamı → LLM. Chip/planlayıcı/kural hattı yok.
 */
final class AssistantV2SiteOnly
{
    private const MAX_CARDS = 3;

    private const RETRIEVAL_LIMIT = 12;

    /**
     * @param array<string, mixed> $state
     * @param list<array{role:string,text:string}> $history
     * @param array{brand?:string,category?:string} $page
     * @return array<string, mixed>
     */
    public static function turn(string $message, array $state, array $history, array $page = [], string $uiAction = ''): array
    {
        if ($uiAction === 'expert') {
            return self::expertHandoff($message, $state);
        }
        if ($uiAction === 'more') {
            return self::wrap(
                'Bu asistan modunda sayfalama yok. Aradığınızı bir cümleyle tekrar yazabilirsiniz.',
                [],
                $state,
                [],
                '',
                false,
                false,
                []
            );
        }
        if ($uiAction === 'show_products') {
            return self::showProductsFromState($state);
        }
        if ($message === '') {
            return self::wrap('Mesaj boş görünüyor. Nasıl yardımcı olabilirim?', [], $state, [], '', false, false, []);
        }

        $focusIds = self::focusIds($state);
        $slugs = AssistantV2Catalog::productSlugsIn($message);
        if ($slugs === []) {
            $slugs = AssistantV2Catalog::namedProductSlugs($message);
        }
        $candidates = self::retrieve($message, $history, $page, $slugs);
        $context = self::buildContext($message, $history, $page, $candidates, $slugs);
        $llm = self::callLlm($message, $history, $context);
        $text = is_array($llm) ? trim((string) ($llm['message'] ?? '')) : '';
        $retrievalIds = self::pickCardIds($message, $llm, $candidates);
        if ($retrievalIds === [] && self::shouldOfferProductListing($message, $history, $candidates)) {
            $retrievalIds = self::topScoredCandidateIds($candidates, self::MAX_CARDS);
        } elseif ($retrievalIds !== [] && count($retrievalIds) === 1) {
            $top = self::topScoredCandidateIds($candidates, self::MAX_CARDS);
            if (count($top) >= 2) {
                $retrievalIds = $top;
            }
        }
        $listingUrl = self::listingUrlForCandidates($candidates, $message, $page, $retrievalIds);
        $showProductsButton = $retrievalIds !== [] && $listingUrl !== '';
        if ($text === '') {
            $text = 'Şu anda yanıt oluşturulamadı. Terazi veya ürün ihtiyacınızı biraz daha ayrıntılı yazarsanız tekrar deneyebilirim.';
        }
        $text = self::sanitizeSiteOnlyMessage($text, $candidates, $message, $slugs);
        $text = str_replace(';', ',', $text);
        $text = trim(str_replace(['**', '__', '`'], '', $text));

        $mergedIds = array_slice(array_values(array_unique(array_merge($focusIds, $retrievalIds))), 0, 8);
        $actions = self::actionsFor($message, $text, $mergedIds, $history);
        if (self::shouldOfferLiveSupportForPrice($message, $history, $candidates, $retrievalIds)) {
            $text = self::appendLiveSupportPriceHint($text);
            $live = AssistantV2Knowledge::liveSupportWhatsAppAction(
                self::priceQuestionForWhatsapp($message, $history),
                array_slice($mergedIds, 0, 4)
            );
            if ($live !== null) {
                $actions = array_values(array_filter(
                    $actions,
                    static fn (array $action): bool => !in_array($action['type'] ?? '', ['whatsapp_write', 'handoff_whatsapp', 'price_request'], true)
                ));
                $actions[] = $live;
            }
        }
        if ($showProductsButton) {
            $text = self::stripShowProductsNavigationCta($text);
            $actions[] = [
                'type'  => 'show_products',
                'role'  => 'primary',
                'label' => 'Ürünleri Gör',
                'url'   => $listingUrl,
            ];
        }
        $stateOut = [
            'filters' => AssistantV2Filters::blank(),
            'ranking' => AssistantV2Filters::blankRanking(),
            'offset' => 0,
            'asked' => [],
            'focus_ids' => $mergedIds,
            'preferences' => [],
            'has_more' => false,
            'criterion' => null,
            'goal' => '',
            'held_child' => '',
            'excluded_kinds' => [],
            'near_options' => [],
            'products_listing_url' => $listingUrl,
        ];

        logger('assistant_v2.site_only', [
            'user_message' => mb_substr($message, 0, 120),
            'candidate_count' => count($candidates),
            'retrieval_ids' => count($retrievalIds),
            'show_products_button' => $showProductsButton,
            'llm_ok' => $text !== '',
        ]);

        $needsWhatsapp = self::actionsContainWhatsapp($actions);

        return self::wrap($text, [], $stateOut, $actions, '', false, $showProductsButton, $retrievalIds, $needsWhatsapp);
    }

    /**
     * @param list<array{role:string,text:string}> $history
     * @param array{brand?:string,category?:string} $page
     * @param list<string> $slugs
     * @return list<array{score:float,product:array<string,mixed>}>
     */
    private static function retrieve(string $message, array $history, array $page, array $slugs): array
    {
        $bySlug = AssistantV2Catalog::bySlug();
        if ($slugs !== []) {
            $fixed = [];
            foreach ($slugs as $slug) {
                if (!isset($bySlug[$slug])) {
                    continue;
                }
                $fixed[] = ['score' => 100.0, 'product' => $bySlug[$slug]];
            }
            if ($fixed !== []) {
                return $fixed;
            }
        }

        $corpus = self::corpus($message, $history);
        $fold = AssistantV2Catalog::fold($corpus);
        if ($fold === '') {
            return [];
        }
        $capacityHintG = self::capacityHintGrams($message, $history);

        $pageBrand = trim((string) ($page['brand'] ?? ''));
        $pageCategory = trim((string) ($page['category'] ?? ''));
        $scored = [];
        foreach (AssistantV2Catalog::products() as $product) {
            $score = self::scoreProduct($product, $fold, $pageBrand, $pageCategory, $capacityHintG);
            if ($score >= 1.5) {
                $scored[] = ['score' => $score, 'product' => $product];
            }
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp((string) $a['product']['name'], (string) $b['product']['name']));

        return array_slice($scored, 0, self::RETRIEVAL_LIMIT);
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function scoreProduct(array $product, string $fold, string $pageBrand, string $pageCategory, ?int $capacityHintG = null): float
    {
        $score = 0.0;
        if ($capacityHintG !== null && $capacityHintG > 0) {
            $capMin = (int) ($product['capacity_min'] ?? 0);
            $capMax = (int) ($product['capacity_max'] ?? 0);
            if ($capMax > 0 && $capacityHintG <= $capMax && ($capMin <= 0 || $capacityHintG >= $capMin)) {
                $score += 8.0;
            } elseif (str_contains(AssistantV2Catalog::fold((string) ($product['capacity_label'] ?? '')), (string) $capacityHintG)) {
                $score += 6.0;
            }
        }
        foreach (['name', 'brand', 'model_no', 'slug'] as $key) {
            $bit = AssistantV2Catalog::fold((string) ($product[$key] ?? ''));
            if ($bit !== '' && str_contains($fold, $bit)) {
                $score += $key === 'brand' ? 4.0 : 6.0;
            }
        }
        foreach (AssistantV2Catalog::usagesOf($product) as $usage) {
            $u = AssistantV2Catalog::fold($usage);
            if ($u !== '' && strlen($u) >= 4 && str_contains($fold, $u)) {
                $score += 5.0;
            }
        }
        foreach (is_array($product['categories'] ?? null) ? $product['categories'] : [] as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            foreach (['name', 'slug', 'parent_name'] as $key) {
                $bit = AssistantV2Catalog::fold((string) ($cat[$key] ?? ''));
                if ($bit !== '' && strlen($bit) >= 4 && str_contains($fold, str_replace('-', ' ', $bit))) {
                    $score += 4.5;
                }
            }
        }
        foreach (is_array($product['specs'] ?? null) ? $product['specs'] : [] as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $value = AssistantV2Catalog::fold((string) ($spec['value'] ?? ''));
            if ($value !== '' && strlen($value) >= 5 && str_contains($fold, $value)) {
                $score += 2.0;
            }
        }
        if ($pageBrand !== '' && AssistantV2Catalog::fold((string) ($product['brand'] ?? '')) === AssistantV2Catalog::fold($pageBrand)) {
            $score += 1.0;
        }
        if ($pageCategory !== '') {
            foreach (is_array($product['categories'] ?? null) ? $product['categories'] : [] as $cat) {
                if (!is_array($cat)) {
                    continue;
                }
                if ((string) ($cat['slug'] ?? '') === $pageCategory || (string) ($cat['parent_slug'] ?? '') === $pageCategory) {
                    $score += 1.5;
                    break;
                }
            }
        }

        $tokens = preg_split('/[^a-z0-9]+/u', $fold, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $nameFold = AssistantV2Catalog::fold((string) ($product['name'] ?? ''));
        foreach ($tokens as $token) {
            if (strlen($token) < 4) {
                continue;
            }
            if (str_contains($nameFold, $token)) {
                $score += 1.5;
            }
        }

        return $score;
    }

    /**
     * @param list<array{score:float,product:array<string,mixed>}> $candidates
     * @param list<string> $slugs
     * @return array<string, mixed>
     */
    private static function buildContext(string $message, array $history, array $page, array $candidates, array $slugs): array
    {
        $taxonomy = [];
        foreach (array_slice(AssistantV2Catalog::taxonomy(), 0, 24) as $node) {
            $taxonomy[] = [
                'slug' => $node['slug'],
                'name' => $node['name'],
                'products' => $node['products'],
            ];
        }
        $products = [];
        foreach ($candidates as $row) {
            $facts = AssistantV2Filters::publicFacts($row['product'], false);
            unset($facts['price']);
            $priceTry = $row['product']['price_try'] ?? null;
            $facts['has_public_price'] = is_numeric($priceTry) && (float) $priceTry > 0;
            $facts['catalog_ref'] = 'p' . (int) ($row['product']['id'] ?? 0);
            $facts['url'] = (string) ($row['product']['url'] ?? '');
            $facts['score'] = round($row['score'], 2);
            $products[] = $facts;
        }

        return [
            'page' => [
                'brand' => trim((string) ($page['brand'] ?? '')),
                'category' => trim((string) ($page['category'] ?? '')),
            ],
            'taxonomy_sample' => $taxonomy,
            'brands' => array_slice(AssistantV2Catalog::brands(), 0, 40),
            'products' => $products,
            'named_slugs' => $slugs,
            'service_facts' => self::detectServiceFacts($message),
            'business_facts' => self::detectBusinessFacts($message),
            'listing_url' => seg_url('/urunler'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function detectServiceFacts(string $message): array
    {
        $fold = AssistantV2Catalog::fold($message);
        $topics = [];
        if (preg_match('/servis|tamir|kalibr|bakim/u', $fold) === 1) {
            $topics[] = 'overview';
        }
        if (preg_match('/garanti/u', $fold) === 1) {
            $topics[] = 'warranty';
        }
        if (preg_match('/takas|satmak|degerlendir|cihazimi/u', $fold) === 1) {
            $topics[] = 'trade';
        }
        if (preg_match('/ucret|fiyat.*servis/u', $fold) === 1) {
            $topics[] = 'fee';
        }
        $lines = [];
        foreach (array_unique($topics) as $topic) {
            $packed = AssistantV2Knowledge::service($topic, $message);
            foreach ($packed['facts'] as $line) {
                $lines[] = $line;
            }
        }

        return array_slice(array_values(array_unique($lines)), 0, 12);
    }

    /**
     * @return list<string>
     */
    private static function detectBusinessFacts(string $message): array
    {
        $fold = AssistantV2Catalog::fold($message);
        $topics = ['about'];
        if (preg_match('/whatsapp|wp|mesaj/u', $fold) === 1) {
            $topics[] = 'whatsapp';
        }
        if (preg_match('/telefon|ara|numara/u', $fold) === 1) {
            $topics[] = 'phone';
        }
        if (preg_match('/adres|nerede|konum/u', $fold) === 1) {
            $topics[] = 'address';
        }
        if (preg_match('/kargo|teslimat|gonderim/u', $fold) === 1) {
            $topics[] = 'shipping';
        }
        if (preg_match('/iade|degisim/u', $fold) === 1) {
            $topics[] = 'returns';
        }
        if (preg_match('/calisma|mesai|saat/u', $fold) === 1) {
            $topics[] = 'hours';
        }
        $lines = [];
        foreach (array_unique($topics) as $topic) {
            $packed = AssistantV2Knowledge::business($topic);
            foreach ($packed['facts'] as $line) {
                $lines[] = $line;
            }
        }

        return array_slice(array_values(array_unique($lines)), 0, 14);
    }

    /**
     * @param list<array{role:string,text:string}> $history
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private static function callLlm(string $message, array $history, array $context): ?array
    {
        $user = json_encode([
            'question' => $message,
            'history' => array_slice($history, -6),
            'site_context' => $context,
            'response_schema' => [
                'message' => 'Türkçe cevap metni',
                'product_ids' => 'isteğe bağlı: bağlamdaki ürün id listesinden en fazla 3 (sohbette kart yok; liste arayüz düğmesiyle açılır, metinde düğmeden bahsetme)',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($user)) {
            return null;
        }

        return AssistantV2Llm::complete(AssistantV2Prompt::siteOnlyVoice(), $user, 22, 'v2_site_only');
    }

    /**
     * @param list<array{score:float,product:array<string,mixed>}> $candidates
     * @return list<int>
     */
    private static function pickCardIds(string $message, ?array $llm, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }
        $allowed = [];
        foreach ($candidates as $row) {
            $id = (int) ($row['product']['id'] ?? 0);
            if ($id > 0) {
                $allowed[$id] = true;
            }
        }
        $picked = [];
        if (is_array($llm)) {
            foreach (is_array($llm['product_ids'] ?? null) ? $llm['product_ids'] : [] as $id) {
                $id = (int) $id;
                if ($id > 0 && isset($allowed[$id])) {
                    $picked[] = $id;
                }
                if (count($picked) >= self::MAX_CARDS) {
                    return $picked;
                }
            }
            if (($llm['show_cards'] ?? false) === true) {
                foreach ($candidates as $row) {
                    $id = (int) ($row['product']['id'] ?? 0);
                    if ($id > 0) {
                        $picked[] = $id;
                    }
                    if (count($picked) >= self::MAX_CARDS) {
                        return $picked;
                    }
                }
            }
        }
        if ($picked !== []) {
            return $picked;
        }
        if (!self::wantsProductCards($message)) {
            return [];
        }
        foreach ($candidates as $row) {
            if ($row['score'] < 3.0) {
                break;
            }
            $id = (int) ($row['product']['id'] ?? 0);
            if ($id > 0) {
                $picked[] = $id;
            }
            if (count($picked) >= self::MAX_CARDS) {
                break;
            }
        }

        return $picked;
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private static function cardsForIds(array $ids): array
    {
        $byId = AssistantV2Catalog::byId();
        $cards = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $cards[] = AssistantV2Filters::card($byId[$id]);
            if (count($cards) >= self::MAX_CARDS) {
                break;
            }
        }

        return $cards;
    }

    /**
     * @param list<int> $productIds
     * @return list<array<string, mixed>>
     */
    /**
     * @param list<array{role:string,text:string}> $history
     * @return list<array<string, mixed>>
     */
    private static function actionsFor(string $message, string $text, array $productIds, array $history): array
    {
        $fold = AssistantV2Catalog::fold(self::corpus($message, $history));
        if (preg_match('/temsilci|insan|uzman|beni ara|numara birak|ulasamiyorum/u', $fold) === 1
            || preg_match('/\bwhatsapp\b|\bwp\b/u', $fold) === 1) {
            $wa = AssistantV2Knowledge::whatsappWriteAction($message, array_slice($productIds, 0, 4));
            if ($wa !== null) {
                return [$wa];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $state
     * @return list<int>
     */
    private static function focusIds(array $state): array
    {
        $ids = [];
        $byId = AssistantV2Catalog::byId();
        foreach (is_array($state['focus_ids'] ?? null) ? $state['focus_ids'] : [] as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($byId[$id])) {
                $ids[] = $id;
            }
        }

        return array_slice($ids, 0, 8);
    }

    /**
     * @param list<array{role:string,text:string}> $history
     */
    private static function corpus(string $message, array $history): string
    {
        $parts = [$message];
        foreach (array_slice($history, -4) as $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $parts[] = (string) ($turn['text'] ?? '');
            }
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
    }

    private static function wantsProductCards(string $message): bool
    {
        $fold = AssistantV2Catalog::fold($message);

        return preg_match('/urunleri goster|modelleri goster|listele|kartlari|secenekleri goster|hangi modeller/u', $fold) === 1;
    }

    /**
     * @param list<array{score:float,product:array<string,mixed>}> $candidates
     */
    /**
     * @param list<array{role:string,text:string}> $history
     * @param list<array{score:float,product:array<string,mixed>}> $candidates
     */
    private static function shouldOfferProductListing(string $message, array $history, array $candidates): bool
    {
        if ($candidates === []) {
            return false;
        }
        if (self::wantsProductCards($message)) {
            return true;
        }
        $fold = AssistantV2Catalog::fold(self::corpus($message, $history));
        if (preg_match('/terazi|terazisi|kuyumcu|laboratuvar|analitik|hassasiyet|kapasite|tartim|model|marka/u', $fold) !== 1) {
            return false;
        }
        foreach ($candidates as $row) {
            if (($row['score'] ?? 0.0) >= 3.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{score:float,product:array<string,mixed>}> $candidates
     * @return list<int>
     */
    private static function topScoredCandidateIds(array $candidates, int $limit): array
    {
        $picked = [];
        foreach ($candidates as $row) {
            if (($row['score'] ?? 0.0) < 3.0) {
                break;
            }
            $id = (int) ($row['product']['id'] ?? 0);
            if ($id > 0) {
                $picked[] = $id;
            }
            if (count($picked) >= $limit) {
                break;
            }
        }

        return $picked;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function expertHandoff(string $message, array $state): array
    {
        $ids = self::focusIds($state);
        $wa = AssistantV2Knowledge::whatsappWriteAction($message, $ids);
        $actions = $wa !== null ? [$wa] : [];
        $text = $wa !== null ? "WhatsApp'tan yazabilirsiniz." : 'Şu anda bu isteği tamamlayamadım. İsterseniz yeniden yazın.';

        return self::wrap($text, [], $state, $actions, 'callback', false, false, $ids, self::actionsContainWhatsapp($actions));
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function showProductsFromState(array $state): array
    {
        $url = trim((string) ($state['products_listing_url'] ?? ''));
        if ($url === '') {
            $url = seg_url('/urunler');
        }
        $ids = self::focusIds($state);
        $actions = [[
            'type'  => 'show_products',
            'role'  => 'primary',
            'label' => 'Ürünleri Gör',
            'url'   => $url,
        ]];

        return self::wrap(
            'İlgili ürünleri sitedeki liste sayfasında inceleyebilirsiniz.',
            [],
            $state,
            $actions,
            '',
            false,
            true,
            $ids,
            false
        );
    }

    /**
     * @param list<array{score:float,product:array<string,mixed>}> $candidates
     * @param array{brand?:string,category?:string} $page
     * @param list<int> $retrievalIds
     */
    private static function listingUrlForCandidates(array $candidates, string $message, array $page, array $retrievalIds): string
    {
        if ($retrievalIds === []) {
            return '';
        }
        $byId = AssistantV2Catalog::byId();
        $products = [];
        foreach ($retrievalIds as $id) {
            if (isset($byId[$id])) {
                $products[] = $byId[$id];
            }
        }
        if ($products === [] && $candidates !== []) {
            foreach (array_slice($candidates, 0, 5) as $row) {
                $products[] = $row['product'];
            }
        }
        if ($products === []) {
            return seg_url('/urunler');
        }

        $brandSlugs = [];
        foreach ($products as $product) {
            $slug = strtolower(trim((string) ($product['brand_slug'] ?? '')));
            if ($slug !== '' && preg_match('/^[a-z0-9-]+$/', $slug) === 1) {
                $brandSlugs[$slug] = ($brandSlugs[$slug] ?? 0) + 1;
            }
        }
        arsort($brandSlugs);
        $brandList = array_keys($brandSlugs);
        $path = '/urunler';
        $query = [];
        if (count($brandList) === 1) {
            $path .= '/' . $brandList[0];
        } elseif (count($brandList) > 1) {
            $query['marka'] = implode(',', array_slice($brandList, 0, 3));
        }

        $pageBrand = strtolower(trim((string) ($page['brand'] ?? '')));
        if ($pageBrand !== '' && preg_match('/^[a-z0-9-]+$/', $pageBrand) === 1 && $path === '/urunler') {
            $path .= '/' . $pageBrand;
        }

        $catCounts = [];
        foreach ($products as $product) {
            foreach (is_array($product['categories'] ?? null) ? $product['categories'] : [] as $cat) {
                if (!is_array($cat)) {
                    continue;
                }
                $slug = trim((string) ($cat['slug'] ?? ''));
                if ($slug === '' || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
                    continue;
                }
                $catCounts[$slug] = ($catCounts[$slug] ?? 0) + 1;
            }
        }
        if ($catCounts !== []) {
            arsort($catCounts);
            $topCat = (string) array_key_first($catCounts);
            $node = AssistantV2Catalog::node($topCat);
            if ($node !== null) {
                $chain = [];
                $slug = $topCat;
                $seen = [];
                $guard = 0;
                while ($slug !== '' && $guard < 6 && !isset($seen[$slug])) {
                    $seen[$slug] = true;
                    $current = AssistantV2Catalog::node($slug);
                    if ($current === null) {
                        break;
                    }
                    array_unshift($chain, $current['slug']);
                    $parent = trim((string) ($current['parent'] ?? ''));
                    if ($parent === '' || $parent === $slug) {
                        break;
                    }
                    $slug = $parent;
                    $guard++;
                }
                if (count($chain) > 3) {
                    $chain = array_slice($chain, 0, 3);
                }
                foreach ($chain as $segment) {
                    $path .= '/' . $segment;
                }
            }
        }

        $href = seg_url($path);
        if ($query !== []) {
            $href .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        if ($path === '/urunler' && $query === []) {
            $q = self::listingSearchQuery($message);
            if ($q !== '') {
                $href .= '?' . http_build_query(['q' => $q], '', '&', PHP_QUERY_RFC3986);
            }
        }

        return $href;
    }

    private static function listingSearchQuery(string $message): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $message) ?? '');
        if ($text === '') {
            return '';
        }
        $fold = AssistantV2Catalog::fold($text);
        $text = preg_replace('/\b(terazi|terazisi|teraziler|ariyorum|istiyorum|lazim|gerek|model|urun|urunler|marka|fiyat|alabilir|miyim|mu|mi|bir|icin|ve|ile|veya)\b/u', ' ', $fold) ?? $fold;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) < 3) {
            return '';
        }

        return mb_substr($text, 0, 80);
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @param array<string, mixed> $state
     * @param list<array<string, mixed>> $actions
     * @param list<int> $productIds
     * @return array<string, mixed>
     */
    private static function wrap(
        string $message,
        array $cards,
        array $state,
        array $actions,
        string $responseMode,
        bool $cardsAttached,
        bool $showProductsButton,
        array $productIds,
        bool $needsWhatsapp = false
    ): array {
        $ids = [];
        foreach ($productIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return [
            'message' => $message,
            'cards' => [],
            'total' => 0,
            'more' => null,
            'actions' => $actions,
            'state' => $state,
            'product_ids' => $ids,
            'mode' => $responseMode,
            'write_mode' => 'answer',
            'cards_attached' => false,
            'needs_whatsapp' => $needsWhatsapp,
            'show_products_button' => $showProductsButton,
            'debug' => [
                'site_only' => true,
                'final_response_generated' => $message !== '',
            ],
        ];
    }

    /**
     * @param list<array{score:float,product:array<string,mixed>}> $candidates
     */
    /**
     * @param list<array{score:float,product:array<string,mixed>}> $candidates
     * @param list<string> $namedSlugs
     */
    private static function sanitizeSiteOnlyMessage(string $text, array $candidates, string $userMessage = '', array $namedSlugs = []): string
    {
        $text = trim(preg_replace(
            '/fiyat\s+bilgisi\s+(paylaşılmamış|paylaşılmadı|bulunmuyor|yok|mevcut\s+değil)[^.!?]*[.!?]?\s*/iu',
            '',
            $text
        ) ?? $text);
        $text = trim(preg_replace(
            '/(?:yayınlanmış\s+)?fiyat(?:ı)?\s+(?:yok|bulunmuyor|görünmüyor|paylaşılmıyor)[^.!?]*[.!?]?\s*/iu',
            '',
            $text
        ) ?? $text);
        $userFold = AssistantV2Catalog::fold($userMessage);
        $namedSlugSet = array_fill_keys($namedSlugs, true);
        foreach ($candidates as $row) {
            $product = $row['product'];
            $slug = (string) ($product['slug'] ?? '');
            if ($slug !== '' && isset($namedSlugSet[$slug])) {
                continue;
            }
            $skipRow = false;
            foreach (['name', 'model_no'] as $key) {
                $bit = trim((string) ($product[$key] ?? ''));
                if ($bit === '') {
                    continue;
                }
                $bitFold = AssistantV2Catalog::fold($bit);
                if ($bitFold !== '' && str_contains($userFold, $bitFold)) {
                    $skipRow = true;
                    break;
                }
            }
            if ($skipRow) {
                continue;
            }
            foreach (['name', 'model_no', 'slug'] as $key) {
                $bit = trim((string) ($product[$key] ?? ''));
                if ($bit === '' || mb_strlen($bit) < 4) {
                    continue;
                }
                $text = preg_replace('/\b' . preg_quote($bit, '/') . '\b/iu', '', $text) ?? $text;
            }
        }
        $text = trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);

        return $text;
    }

    private static function stripShowProductsNavigationCta(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }
        $ctaFragment = '/(?:'
            . 'ürünleri\s+gör'
            . '|arayüzdeki\s+(?:«|"|\'|)?(?:ürünleri\s+gör\s*)?(?:»|"|\'|)?\s*düğme'
            . '|arayüzdeki\s+düğme'
            . '|arayüzdeki\s+buton'
            . '|aşağıdaki\s+(?:düğme|buton)'
            . '|düğmesinden\s+incele'
            . '|düğmeyi\s+kullan'
            . '|düğmeden\s+incele'
            . ')/iu';
        $parts = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || $parts === []) {
            $parts = [$text];
        }
        $kept = [];
        foreach ($parts as $part) {
            $chunk = trim($part);
            if ($chunk === '') {
                continue;
            }
            if (preg_match($ctaFragment, $chunk) === 1) {
                continue;
            }
            $kept[] = $chunk;
        }
        if ($kept === []) {
            return $text;
        }
        $out = trim(implode(' ', $kept));
        $out = trim(preg_replace('/\s{2,}/u', ' ', $out) ?? $out);
        $out = preg_replace('/\s+([,.!?])/u', '$1', $out) ?? $out;

        return trim($out);
    }

    /**
     * @param list<array{role:string,text:string}> $history
     */
    private static function capacityHintGrams(string $message, array $history): ?int
    {
        $corpus = self::corpus($message, $history);
        if (preg_match('/(\d{2,4})\s*(?:g|gr|gram)\b/u', $corpus, $match) === 1) {
            return (int) $match[1];
        }
        $trimmed = trim($message);
        if (preg_match('/^\s*(\d{2,4})\s*$/u', $trimmed, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * @param list<array{role:string,text:string}> $history
     */
    private static function wantsPriceInfo(string $message, array $history): bool
    {
        $fold = AssistantV2Catalog::fold(self::corpus($message, $history));

        return preg_match('/\bfiyat|\bfiyati|ne\s+kadar|kac\s+para|kac\s+tl|ucret|ucuz|pahali|tl\b|₺/u', $fold) === 1;
    }

    /**
     * @param list<array{score:float,product:array<string,mixed>}> $candidates
     * @param list<int> $retrievalIds
     */
    private static function matchedCandidatesMissingPublicPrice(array $candidates, array $retrievalIds): bool
    {
        if ($candidates === []) {
            return false;
        }
        $byId = [];
        foreach ($candidates as $row) {
            $id = (int) ($row['product']['id'] ?? 0);
            if ($id > 0) {
                $byId[$id] = $row['product'];
            }
        }
        $pool = [];
        if ($retrievalIds !== []) {
            foreach ($retrievalIds as $id) {
                if (isset($byId[$id])) {
                    $pool[] = $byId[$id];
                }
            }
        }
        if ($pool === []) {
            foreach (array_slice($candidates, 0, self::MAX_CARDS) as $row) {
                if (($row['score'] ?? 0.0) >= 3.0) {
                    $pool[] = $row['product'];
                }
            }
        }
        if ($pool === []) {
            return false;
        }
        foreach ($pool as $product) {
            $priceTry = $product['price_try'] ?? null;
            if (!is_numeric($priceTry) || (float) $priceTry <= 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{role:string,text:string}> $history
     * @param list<array{score:float,product:array<string,mixed>}> $candidates
     * @param list<int> $retrievalIds
     */
    private static function shouldOfferLiveSupportForPrice(
        string $message,
        array $history,
        array $candidates,
        array $retrievalIds
    ): bool {
        if ($candidates === [] && $retrievalIds === []) {
            return false;
        }
        if (self::wantsPriceInfo($message, $history)) {
            return true;
        }
        $fold = AssistantV2Catalog::fold(self::corpus($message, $history));
        $shopping = preg_match('/terazi|terazisi|kuyumcu|laboratuvar|analitik|hassasiyet|kapasite|tartim|model|marka/u', $fold) === 1;

        return $shopping && self::matchedCandidatesMissingPublicPrice($candidates, $retrievalIds);
    }

    private static function appendLiveSupportPriceHint(string $text): string
    {
        $fold = AssistantV2Catalog::fold($text);
        if (preg_match('/canli\s+destek|whatsapp/u', $fold) === 1) {
            return $text;
        }
        $hint = 'Dilerseniz fiyat almak için canlı desteğe bağlanabilirsiniz.';
        if ($text === '') {
            return $hint;
        }
        if (!str_ends_with($text, '.') && !str_ends_with($text, '!') && !str_ends_with($text, '?')) {
            $text .= '.';
        }

        return $text . ' ' . $hint;
    }

    /**
     * @param list<array{role:string,text:string}> $history
     */
    private static function priceQuestionForWhatsapp(string $message, array $history): string
    {
        $parts = [];
        foreach (array_slice($history, -4) as $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $bit = trim((string) ($turn['text'] ?? ''));
                if ($bit !== '') {
                    $parts[] = $bit;
                }
            }
        }
        $current = trim($message);
        if ($current !== '') {
            $parts[] = $current;
        }
        $question = trim(preg_replace('/\s+/u', ' ', implode(' — ', $parts)) ?? '');
        if ($question === '') {
            return 'Fiyat bilgisi almak istiyorum.';
        }

        return mb_substr($question, 0, 400);
    }

    /**
     * @param list<array<string, mixed>> $actions
     */
    private static function actionsContainWhatsapp(array $actions): bool
    {
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $type = (string) ($action['type'] ?? '');
            if (in_array($type, ['whatsapp_write', 'handoff_whatsapp', 'price_request'], true)) {
                return true;
            }
        }

        return false;
    }
}
