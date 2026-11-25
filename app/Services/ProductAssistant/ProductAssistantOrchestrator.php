<?php

declare(strict_types=1);

namespace App\Services\ProductAssistant;

/**
 * Konuşmayı model yönetir. Ürün, sıralama ve işletme gerçekleri yalnızca buradaki araçlardan gelir.
 */
final class ProductAssistantOrchestrator
{
    /**
     * @param list<int> $previousIds
     * @param array<string, mixed> $memory
     * @param list<array{role:string,text:string}> $history
     * @return array<string, mixed>|null
     */
    public static function respond(string $message, array $previousIds, string $priorMessage, int $offset, string $pageBrand, array $memory, array $history, string $pageCategory = ''): ?array
    {
        if (ProductAssistantIntent::refuses($message)) {
            return self::envelope('Buna yardımcı olamam. Terazi, servis veya iletişimle ilgili bir konuda devam edebiliriz.', [], 0, null, null, false, []);
        }

        $catalog = ProductAssistantCatalog::products();
        if ($offset > 0 && ($memory['ranking_field'] ?? '') === '' && (($memory['brand_slugs'] ?? []) !== [] || ($memory['category'] ?? null) !== null || ($memory['category_slugs'] ?? []) !== [] || ($memory['precisions'] ?? []) !== [])) {
            return self::pageMore($memory, $offset);
        }

        $linked = self::linkedProducts($message, $catalog);
        $previous = ProductAssistant::productsForIds(array_slice(array_values($previousIds), 0, 8));
        $pool = $previous !== [] ? $previous : $linked;
        if (self::asksSpecCompare($message) && $pool !== []) {
            return self::recordedSpecs($message, $history, $pool, $catalog);
        }
        if (self::durabilityPreference($message, $history) && count($pool) >= 2 && !self::hasDurabilityFacts($pool)) {
            return self::durabilityLimit($message, $history, $pool, $catalog);
        }
        $contact = self::contactHandoff($message);
        if ($contact !== null) {
            return $contact;
        }
        if (self::strayFeature($message)) {
            return self::envelope('Bunu nasıl kastettiğinizi biraz açar mısınız?', [], 0, null, $memory !== [] ? $memory : null, true, []);
        }
        $unmatched = self::unsupportedWish($message, $catalog);
        if ($unmatched !== null) {
            return $unmatched;
        }
        if (!self::isSmallTalk($message) && !self::inDomain($message, $catalog, $memory)) {
            return self::envelope(self::outsideRedirect($message, $catalog, $memory), [], 0, null, null, false, []);
        }
        $guided = self::guidedDiscover($message, $catalog, $memory);
        if ($guided !== null) {
            return $guided;
        }
        $context = json_encode([
            'message'            => $message,
            'history'            => self::cleanHistory($history),
            'previous_user'      => $priorMessage,
            'previous_products'  => self::briefs($previous),
            'linked_products'    => self::briefs($linked),
            'filters'            => [
                'brands'        => array_values(is_array($memory['brand_slugs'] ?? null) ? $memory['brand_slugs'] : []),
                'category'      => $memory['category'] ?? null,
                'precisions_g'  => array_values(is_array($memory['precisions'] ?? null) ? $memory['precisions'] : []),
                'ranking_field' => ($memory['ranking_field'] ?? '') !== '' ? $memory['ranking_field'] : null,
                'ranking_direction' => ($memory['ranking_direction'] ?? '') !== '' ? $memory['ranking_direction'] : null,
            ],
            'page_brand'         => $pageBrand !== '' ? $pageBrand : null,
            'page_category'      => $pageCategory !== '' ? $pageCategory : null,
            'clarification_spent' => self::clarifySpent($history),
            'brands'             => self::brandCatalog($catalog),
            'categories'         => ['kuyumcu', 'laboratuvar', 'endustriyel', 'perakende', 'analitik-teraziler', 'yari-analitik-teraziler', 'analitik-teraziler', 'hassas-kuyumcu-terazileri', 'hassas-laboratuvar-terazileri'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($context)) {
            return null;
        }

        $plan = ProductAssistantLlm::completeJson(self::planPrompt(), $context, 16, 'plan');
        if ($plan === null) {
            return null;
        }

        $tools = self::toolsOf($plan);
        $chat = self::textOf($plan, 'chat');
        if ($chat === '') {
            $chat = self::textOf($plan, 'message');
        }
        $clarify = self::textOf($plan, 'clarify');
        if ($tools === [] && ($chat === '' || !self::chatIsSafe($chat, $message, $catalog))) {
            $retry = ProductAssistantLlm::completeJson(
                self::planPrompt(),
                $context . "\n\nÜrün, fiyat, kapasite, hassasiyet, servis, garanti veya iletişim iddiası için tools kullan. Bu iddiaları chat içine yazma.",
                14,
                'plan_retry'
            );
            if (is_array($retry)) {
                $plan = $retry;
                $tools = self::toolsOf($plan);
                $chat = self::textOf($plan, 'chat');
                if ($chat === '') {
                    $chat = self::textOf($plan, 'message');
                }
                $clarify = self::textOf($plan, 'clarify');
            }
        }

        if (self::clarifySpent($history)) {
            if (self::isQuestion($clarify)) {
                $clarify = '';
            }
            if (self::isQuestion($chat)) {
                $chat = '';
            }
        }

        if ($tools === []) {
            if ($clarify !== '' && self::clarifyIsSafe($clarify, $message) && !self::clarifySpent($history)) {
                return self::envelope($clarify, [], 0, null, null, true, []);
            }
            if (self::clarifySpent($history)) {
                if ($pool !== []) {
                    return self::recordedSpecs($message, $history, $pool, $catalog);
                }
                if ($chat === '' || self::isQuestion($chat)) {
                    $chat = 'Anladım. Bu beklentiyi kayıtlı bir ürün farkına bağlayacak ek bir ölçümüz yok.';
                }
            }
            if ($chat === '' || !self::chatIsSafe($chat, $message, $catalog)) {
                $chat = self::rewriteChat($message, $chat) ?? 'Size nasıl yardımcı olabilirim?';
            }

            return self::envelope(self::guardOutside(self::withoutSemicolon($chat), $message, $catalog, $memory), [], 0, null, null, false, []);
        }

        $executed = self::execute($tools, $message, $catalog, $memory, $previousIds, $linked, $offset);
        $fixed = false;
        $fixedText = null;
        foreach ($executed['results'] as $result) {
            if (empty($result['fixed'])) {
                continue;
            }
            $fixed = true;
            $fact = $result['facts'][0] ?? '';
            if (is_string($fact) && $fact !== '') {
                $fixedText = $fact;
            }
        }
        $spoken = $fixed ? null : self::compose($message, $history, $executed['results']);
        $text = self::guardOutside(self::withoutSemicolon($fixedText ?? $spoken ?? $executed['fallback']), $message, $catalog, $memory);
        $text = self::guardPartialCatalog($text, $executed['results']);
        if (self::shouldUseListSentence($message, $executed)) {
            $kept = is_array($executed['memory'] ?? null) ? $executed['memory'] : $memory;
            $text = self::listSentenceFor($message, $catalog, $kept);
        }
        if ($text === '') {
            $text = 'Birlikte bakalım. Ne aradığınızı biraz daha yazar mısınız?';
        }

        $payload = self::envelope(
            $text,
            $executed['cards'],
            $executed['total'],
            $executed['more_url'],
            $executed['memory'],
            false,
            $executed['actions'],
            $executed['topic']
        );
        if (self::needsExpert($executed['results'], $text, $message)) {
            return self::offerLiveSupport($payload, $message, $catalog);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $memory
     * @param list<array<string, mixed>> $catalog
     * @return array<string, mixed>
     */
    private static function pageMore(array $memory, int $offset): array
    {
        $intent = self::blankIntent();
        $intent['brand_slugs'] = array_values(is_array($memory['brand_slugs'] ?? null) ? $memory['brand_slugs'] : []);
        $intent['category'] = is_string($memory['category'] ?? null) ? $memory['category'] : null;
        $intent['category_slugs'] = self::slugList($memory['category_slugs'] ?? []);
        $intent['precisions'] = self::precisions(is_array($memory['precisions'] ?? null) ? $memory['precisions'] : []);
        $intent['precision'] = $intent['precisions'][0] ?? null;
        $intent['communication'] = self::communicationList($memory['communication'] ?? []);
        $intent['scope_ids'] = self::idList($memory['scope_ids'] ?? []);
        if (is_bool($memory['commercial'] ?? null)) {
            $intent['commercial'] = $memory['commercial'];
        }
        $calibration = $memory['calibration'] ?? null;
        if (in_array($calibration, ['auto', 'manual'], true)) {
            $intent['calibration'] = $calibration;
        }
        $found = ProductAssistantSearch::find($intent, [], $offset);
        $cards = [];
        foreach ($found['products'] as $product) {
            $cards[] = ProductAssistant::card($product);
        }
        $text = $cards === []
            ? 'Bu kriterlerle başka model kalmadı.'
            : 'Aynı kriterlerle sonraki modeller bunlar.';

        return self::envelope($text, $cards, (int) $found['total'], $found['more_url'], ProductAssistant::rankingMemory($intent), false, [], 'product');
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @param list<array<string, mixed>> $catalog
     * @param array<string, mixed> $memory
     * @param list<int> $previousIds
     * @param list<array<string, mixed>> $linked
     * @return array{results:list<array<string,mixed>>,cards:list<array<string,mixed>>,total:int,more_url:?string,memory:?array,actions:list<array<string,mixed>>,fallback:string,topic:?string}
     */
    private static function execute(array $tools, string $message, array $catalog, array $memory, array $previousIds, array $linked, int $offset): array
    {
        $results = [];
        $cards = [];
        $total = 0;
        $moreUrl = null;
        $keptMemory = null;
        $actions = [];
        $fallbacks = [];
        $topic = null;

        foreach (array_slice($tools, 0, 2) as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if (in_array($name, ['get_business_facts', 'get_service_facts', 'get_contact_actions'], true)) {
                $piece = self::business($tool, $message, $catalog);
            } elseif ($name === 'rank_products') {
                $piece = self::rank($tool, $message, $catalog, $memory);
            } elseif (in_array($name, ['compare_products', 'get_products', 'get_product_by_id', 'get_product_by_slug'], true)) {
                $piece = self::pick($tool, $catalog, $previousIds, $linked);
            } else {
                $piece = self::search($tool, $message, $catalog, $memory, $offset);
            }

            $results[] = $piece['result'];
            if ($piece['cards'] !== []) {
                $cards = $piece['cards'];
                $total = $piece['total'];
                $moreUrl = $piece['more_url'];
            }
            if ($piece['memory'] !== null) {
                $keptMemory = $piece['memory'];
            }
            if ($piece['actions'] !== []) {
                $actions = $piece['actions'];
            }
            if ($piece['fallback'] !== '') {
                $fallbacks[] = $piece['fallback'];
            }
            if ($piece['topic'] !== null) {
                $topic = $piece['topic'];
            }
        }

        if ($actions === [] && $topic === 'product') {
            $actions = ProductAssistantActions::forProduct($message);
        }

        return [
            'results'  => $results,
            'cards'    => $cards,
            'total'    => $total,
            'more_url' => $moreUrl,
            'memory'   => $keptMemory,
            'actions'  => $actions,
            'fallback' => implode(' ', $fallbacks),
            'topic'    => $topic,
        ];
    }

    /**
     * @param array<string, mixed> $tool
     * @param list<array<string, mixed>> $catalog
     * @param array<string, mixed> $memory
     * @return array{result:array<string,mixed>,cards:list<array<string,mixed>>,total:int,more_url:?string,memory:?array,actions:list<array<string,mixed>>,fallback:string,topic:?string}
     */
    private static function search(array $tool, string $message, array $catalog, array $memory, int $offset): array
    {
        $intent = self::intentFrom($tool, $catalog, $memory, false);
        $open = $intent['brand_slugs'] === []
            && ($intent['category'] ?? null) === null
            && $intent['precisions'] === []
            && ($intent['precision_ct'] ?? null) === null
            && ($intent['capacity_target'] ?? null) === null
            && ($intent['condition'] ?? null) === null
            && ($intent['commercial'] ?? null) === null
            && ($intent['communication'] ?? []) === []
            && ($intent['calibration'] ?? null) === null;
        if ($open) {
            $fact = 'Arama için marka, kategori, kapasite veya hassasiyet belirtilmedi.';

            return self::piece('clarify', $fact, [], 0, null, null, [], null, ['facts' => [$fact]]);
        }
        $found = ProductAssistantSearch::find($intent, [], $offset);
        $matched = is_array($found['matched'] ?? null) ? $found['matched'] : $found['products'];
        $products = $found['products'];
        $cards = [];
        foreach ($products as $product) {
            $cards[] = ProductAssistant::card($product);
        }
        $brandName = self::brandName($intent, $catalog);
        $aggregate = self::resultAggregate($matched, $products);
        if ($products === []) {
            $exists = self::brandHasProducts($intent['brand_slugs'], $catalog);
            $status = ($intent['brand_slugs'] !== [] && !$exists) ? 'no_products' : 'no_match';
            $fallback = $status === 'no_products' && $brandName !== null
                ? $brandName . ' markasında yayınlanmış ürün yok.'
                : 'Bu filtrelerle yayınlanmış bir model yok.';

            return self::piece($status, $fallback, [], 0, null, ProductAssistant::rankingMemory($intent), [], 'product', [
                'brand' => $brandName,
                'facts' => [$fallback],
            ]);
        }

        $facts = self::productFacts($products, (string) ($tool['detail'] ?? ''));
        $facts = array_merge($facts, self::aggregateFacts($aggregate));
        $detail = (string) ($tool['detail'] ?? '');
        if ($detail === 'min_weight') {
            $value = ProductAssistant::minimumSpec($products[0]);
            if ($value === null) {
                $fallback = 'Bu modelin minimum tartım bilgisi ürün bilgilerimizde yer almıyor.';

                return self::piece('missing_min_weight', $fallback, $cards, count($cards), null, ProductAssistant::rankingMemory($intent), [], 'product', [
                    'brand' => $brandName,
                    'facts' => array_merge($facts, [$fallback]),
                ]);
            }
            $facts[] = 'Minimum tartım ' . $value . '.';
        }

        $fallback = ProductAssistant::deterministic($products, (int) $found['total'], $intent, $offset);

        return self::piece('products_found', $fallback, $cards, (int) $found['total'], $found['more_url'], ProductAssistant::rankingMemory($intent), [], 'product', [
            'brand' => $brandName,
            'shown' => count($cards),
            'facts' => $facts,
            'aggregate' => $aggregate,
        ]);
    }

    /**
     * @param array<string, mixed> $tool
     * @param list<array<string, mixed>> $catalog
     * @param array<string, mixed> $memory
     * @return array{result:array<string,mixed>,cards:list<array<string,mixed>>,total:int,more_url:?string,memory:?array,actions:list<array<string,mixed>>,fallback:string,topic:?string}
     */
    private static function rank(array $tool, string $message, array $catalog, array $memory): array
    {
        $intent = self::intentFrom($tool, $catalog, $memory, true);
        $field = (string) ($intent['ranking_field'] ?? '');
        if (!in_array($field, ['precision', 'price', 'capacity'], true)) {
            $fallback = 'Sıralama ölçüsü belirtilmedi.';

            return self::piece('clarify', $fallback, [], 0, null, null, [], null, ['facts' => [$fallback]]);
        }

        $result = ProductAssistant::collectRanking($intent);
        $reason = array_key_exists('reason', $result) ? $result['reason'] : 'no_products';
        $brandName = is_string($result['brand_name'] ?? null) ? $result['brand_name'] : self::brandName($intent, $catalog);
        $kept = ProductAssistant::rankingMemory($intent);
        if ($reason !== null) {
            $fallback = self::rankMiss((string) $reason, $brandName, (string) $intent['ranking_direction']);

            return self::piece((string) $reason, $fallback, [], 0, null, $kept, [], 'product', [
                'brand' => $brandName,
                'facts' => [$fallback],
            ]);
        }

        $products = is_array($result['products'] ?? null) ? $result['products'] : [];
        $cards = [];
        foreach ($products as $product) {
            if (is_array($product)) {
                $cards[] = ProductAssistant::card($product);
            }
        }
        $facts = self::productFacts($products, '');
        if ((int) ($result['ct_skipped'] ?? 0) > 0) {
            $facts[] = 'Yalnızca karat cinsinden kayıtlı modeller gram hassasiyetiyle aynı ölçekte sıralanmadı.';
        }
        if ((int) ($result['tie_count'] ?? 0) > 1) {
            $facts[] = 'Aynı değerde ' . (int) $result['tie_count'] . ' model var.';
        }
        $fallback = self::rankFallback($field, (string) $intent['ranking_direction'], $brandName, $products);

        return self::piece('ranked', $fallback, $cards, count($cards), null, $kept, [], 'product', [
            'brand'  => $brandName,
            'field'  => $field,
            'facts'  => $facts,
        ]);
    }

    /**
     * @param array<string, mixed> $tool
     * @param list<array<string, mixed>> $catalog
     * @param list<int> $previousIds
     * @param list<array<string, mixed>> $linked
     * @return array{result:array<string,mixed>,cards:list<array<string,mixed>>,total:int,more_url:?string,memory:?array,actions:list<array<string,mixed>>,fallback:string,topic:?string}
     */
    private static function pick(array $tool, array $catalog, array $previousIds, array $linked): array
    {
        $ids = self::idsOf($tool, $previousIds, $linked);
        $products = ProductAssistant::productsForIds($ids);
        if ($products === [] && $linked !== []) {
            $products = $linked;
        }
        if ($products === []) {
            $fallback = 'Gösterilen ürünlere bağlanamadım. Model adını ya da ürün linkini yazarsanız bakabilirim.';

            return self::piece('no_match', $fallback, [], 0, null, null, [], 'product', ['facts' => [$fallback]]);
        }

        $detail = (string) ($tool['detail'] ?? '');
        $facts = self::productFacts($products, $detail);
        foreach (self::specFacts($products) as $line) {
            $facts[] = $line;
        }
        $cards = [];
        foreach ($products as $product) {
            $cards[] = ProductAssistant::card($product);
        }
        if ($detail === 'min_weight') {
            $value = ProductAssistant::minimumSpec($products[0]);
            if ($value === null) {
                $fallback = 'Bu modelin minimum tartım bilgisi ürün bilgilerimizde yer almıyor. İsterseniz ekibimizden netleştirebiliriz.';

                return self::piece('missing_min_weight', $fallback, $cards, count($cards), null, null, [], 'product', [
                    'facts' => array_merge($facts, [$fallback]),
                ]);
            }
            $facts[] = 'Minimum tartım ' . $value . '.';
        }

        $status = count($products) > 1 ? 'compared' : 'product';
        $fallback = count($products) > 1
            ? 'Bu modeller arasındaki kapasite ve hassasiyet farkını kartlarda görebilirsiniz.'
            : ((string) ($products[0]['name'] ?? 'Model') . ' aşağıda.');

        return self::piece($status, $fallback, $cards, count($cards), null, null, [], 'product', ['facts' => $facts]);
    }

    /**
     * @param array<string, mixed> $tool
     * @param list<array<string, mixed>> $catalog
     * @return array{result:array<string,mixed>,cards:list<array<string,mixed>>,total:int,more_url:?string,memory:?array,actions:list<array<string,mixed>>,fallback:string,topic:?string}
     */
    private static function business(array $tool, string $message, array $catalog): array
    {
        $topic = self::topicOf($tool);
        $brandNote = '';
        $brandNames = is_array($tool['brands'] ?? null) ? array_map('strval', $tool['brands']) : [];
        $brandNames[] = $message;
        $slugs = ProductAssistantIntent::brandSlugs($brandNames, $catalog);
        if ($slugs !== []) {
            $names = [];
            foreach ($catalog as $product) {
                if (in_array((string) ($product['brand_slug'] ?? ''), $slugs, true)) {
                    $name = trim((string) ($product['brand'] ?? ''));
                    if ($name !== '' && !in_array($name, $names, true)) {
                        $names[] = $name;
                    }
                }
            }
            if ($names !== []) {
                $brandNote = ' ' . implode(' ', $names);
            }
        }

        if ($topic === 'projects') {
            $fallback = 'Proje ihtiyacınızı birlikte netleştirebiliriz. Ne tartmak istediğinizi yazarsanız uygun çözüme bakarım.';

            return self::piece('no_approved_facts', $fallback, [], 0, null, null, [], 'service', [
                'facts' => ['Yayınlanmış ayrı bir proje paketi vaadi yok.', $fallback],
            ]);
        }

        if ($topic === 'quote') {
            $fallback = 'Kesin fiyatı buradan söyleyemem. İsterseniz teklif için bize yazabilirsiniz.';
            $wa = ProductAssistantActions::whatsapp('product', 'fiyat');
            if ($wa !== null) {
                $wa['role'] = 'primary';
            }

            return self::piece('quote', $fallback, [], 0, null, null, $wa !== null ? [$wa] : [], 'company', [
                'facts' => ['Kesin fiyat burada üretilmez.', 'Teklif için bize yazılabilir.'],
            ]);
        }

        if (in_array($topic, ['phone', 'whatsapp', 'email', 'address', 'hours', 'about'], true)) {
            $facts = ProductAssistantCompany::factLines($topic);
            $fallback = $facts[0] ?? 'Bu iletişim bilgisi yayınlanan kayıtlarımızda görünmüyor.';
            $actions = ProductAssistantActions::forCompany($topic);

            return self::piece($facts === [] ? 'missing' : 'business', $fallback, [], 0, null, null, $actions, 'company', [
                'facts' => $facts !== [] ? $facts : [$fallback],
            ]);
        }

        $kind = in_array($topic, ['warranty', 'shipping', 'form', 'fee', 'trade', 'brand', 'unknown_brand', 'overview'], true) ? $topic : 'overview';
        $packed = ProductAssistantBusinessKnowledge::pack($kind, $message . $brandNote);
        $actions = ProductAssistantActions::forService($kind === 'brand' ? 'overview' : $kind);
        if ($kind === 'brand' && str_contains(ProductAssistantCatalog::fold((string) $packed['fallback']), 'gorunmuyor')) {
            $actions = ProductAssistantActions::forService('unknown_brand');
        }
        if ($kind === 'trade') {
            $wa = ProductAssistantActions::whatsapp('company', '');
            if ($wa !== null) {
                $wa['role'] = 'secondary';
                $actions = [$wa];
            }
        }

        return self::piece('business', (string) $packed['fallback'], [], 0, null, null, $actions, 'service', [
            'facts' => $packed['facts'],
        ]);
    }

    /**
     * @param list<array{role:string,text:string}> $history
     * @param list<array<string, mixed>> $results
     */
    private static function compose(string $message, array $history, array $results): ?string
    {
        $facts = [];
        foreach ($results as $result) {
            foreach ($result['facts'] ?? [] as $fact) {
                if (is_string($fact) && trim($fact) !== '') {
                    $facts[] = trim($fact);
                }
            }
        }
        $payload = json_encode([
            'question' => $message,
            'history'  => self::cleanHistory($history),
            'results'  => $results,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || $facts === []) {
            return null;
        }

        $json = ProductAssistantLlm::completeJson(self::speakPrompt(), $payload, 14, 'speak');
        $written = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';
        if ($written === '' || mb_strlen($written) > 500) {
            return null;
        }
        if (preg_match('/\d+\s+uygun\s+ürün/ui', $written) === 1) {
            return null;
        }
        $factText = ProductAssistantCatalog::fold(implode(' ', $facts));
        $writtenFold = ProductAssistantCatalog::fold($written);
        foreach (['ucretsiz', 'garanti', 'istanbul', 'kargo', 'ticari onay'] as $token) {
            if (str_contains($writtenFold, $token) && !str_contains($factText, $token)) {
                return null;
            }
        }
        if (!ProductAssistant::phraseIsFaithful($written, $facts)) {
            return null;
        }

        return $written;
    }
    private static function rewriteChat(string $message, string $draft): ?string
    {
        $payload = json_encode([
            'user'  => $message,
            'draft' => $draft,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return null;
        }
        $json = ProductAssistantLlm::completeJson(
            'Terazibul asistanısın. Yalnızca doğal sohbet yaz. Kullanıcıya siz diye hitap et, kendinden biz diye söz et. Sen, sana, senden, senin, naber ve iyidir yazma. Hal hatırda "İyiyiz, teşekkür ederiz. Size nasıl yardımcı olabiliriz?" gibi yaz. Fiyat, kapasite, hassasiyet, garanti süresi, telefon, URL ve ürün iddiası ekleme. Emoji kullanma. JSON: {"message":"..."}',
            $payload,
            10,
            'chat'
        );
        $written = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';
        if ($written === '' || !self::chatIsSafe($written, $message, [])) {
            return null;
        }

        return $written;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     */
    private static function chatIsSafe(string $reply, string $user, array $catalog): bool
    {
        $reply = trim($reply);
        if ($reply === '' || mb_strlen($reply) > 500) {
            return false;
        }
        if (preg_match('/https?:|wa\.me|www\.|tel:/iu', $reply) === 1) {
            return false;
        }
        $folded = ProductAssistantCatalog::fold($reply);
        if (preg_match('/(^|[^a-z])(senden|sana|senin|senle|sende|sensin|sen|naber|napiyon|napiyorsun|nasilsin|iyidir)([^a-z]|$)/u', $folded) === 1) {
            return false;
        }
        foreach (['sayfamizda', 'kayitlara gore', 'belirtiliyor', 'sunmaktadir', 'dogrulanmis kayit', 'system prompt', 'api key'] as $banned) {
            if (str_contains($folded, $banned)) {
                return false;
            }
        }
        $allowed = ProductAssistantCatalog::fold($user);
        if (preg_match_all('/\d+/u', $reply, $numbers) > 0) {
            foreach ($numbers[0] as $number) {
                if (!str_contains($allowed, $number)) {
                    return false;
                }
            }
        }
        foreach (self::brandCatalog($catalog) as $brand) {
            $name = ProductAssistantCatalog::fold((string) ($brand['name'] ?? ''));
            if ($name !== '' && str_contains($folded, $name) && preg_match('/fiyat|kapasite|hassasiyet|garanti|onay|stok/u', $folded) === 1) {
                return false;
            }
        }

        return true;
    }

    private static function clarifyIsSafe(string $text, string $user): bool
    {
        if ($text === '' || mb_strlen($text) > 180 || !str_contains($text, '?')) {
            return false;
        }
        if (preg_match('/https?:|wa\.me|www\.|tel:/iu', $text) === 1) {
            return false;
        }
        $allowed = ProductAssistantCatalog::fold($user);
        if (preg_match_all('/\d+/u', $text, $numbers) < 1) {
            return true;
        }
        foreach ($numbers[0] as $number) {
            if (str_contains($allowed, $number)) {
                continue;
            }
            if (!in_array($number, ['0', '1', '01', '001', '0001', '00001'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $tool
     * @param list<array<string, mixed>> $catalog
     * @param array<string, mixed> $memory
     * @return array<string, mixed>
     */
    private static function intentFrom(array $tool, array $catalog, array $memory, bool $ranking): array
    {
        $intent = self::blankIntent();
        if ($ranking) {
            $intent['mode'] = 'ranking';
        }
        if (array_key_exists('brands', $tool) && is_array($tool['brands'])) {
            $intent['brand_slugs'] = ProductAssistantIntent::brandSlugs(array_map('strval', $tool['brands']), $catalog);
        }
        if (array_key_exists('category', $tool)) {
            $intent['category'] = ProductAssistantIntent::categorySlug(is_string($tool['category']) ? $tool['category'] : null);
        }
        if (array_key_exists('precisions_g', $tool) || array_key_exists('precisions', $tool)) {
            $raw = $tool['precisions_g'] ?? $tool['precisions'] ?? [];
            $intent['precisions'] = self::precisions(is_array($raw) ? $raw : []);
        }
        $intent['precision'] = $intent['precisions'][0] ?? null;
        if (array_key_exists('precision_ct', $tool) && is_numeric($tool['precision_ct'])) {
            $ct = (float) $tool['precision_ct'];
            if ($ct > 0 && $ct < 100000) {
                $intent['precision_ct'] = $ct;
                $intent['precisions'] = [];
                $intent['precision'] = null;
            }
        }

        $capacity = $tool['capacity_g'] ?? $tool['capacity'] ?? null;
        if (is_int($capacity) || is_float($capacity) || (is_string($capacity) && is_numeric($capacity))) {
            $grams = (float) $capacity;
            if ($grams > 0 && $grams < 1000000) {
                $intent['capacity_target'] = $grams;
                $mode = is_string($tool['capacity_mode'] ?? null) ? $tool['capacity_mode'] : 'around';
                $intent['capacity_mode'] = in_array($mode, ['around', 'upto', 'min'], true) ? $mode : 'around';
            }
        }
        if (array_key_exists('commercial', $tool) && is_bool($tool['commercial'])) {
            $intent['commercial'] = $tool['commercial'];
        }
        if (in_array($tool['condition'] ?? null, ['renewed', 'new'], true)) {
            $intent['condition'] = $tool['condition'];
        }

        if (array_key_exists('communication', $tool)) {
            $intent['communication'] = self::communicationList($tool['communication']);
        }
        if (in_array($tool['calibration'] ?? null, ['auto', 'manual'], true)) {
            $intent['calibration'] = $tool['calibration'];
        }

        if (!$ranking) {
            return $intent;
        }

        $field = $tool['ranking_field'] ?? null;
        if (!is_string($field) || !in_array($field, ['precision', 'price', 'capacity'], true)) {
            $field = '';
        }
        $direction = $tool['ranking_direction'] ?? null;
        if (!is_string($direction) || !in_array($direction, ['asc', 'desc'], true)) {
            $direction = $field === 'capacity' ? 'desc' : 'asc';
        }
        $intent['ranking_field'] = $field;
        $intent['ranking_direction'] = $direction;

        return $intent;
    }

    /**
     * @return array<string, mixed>
     */
    private static function blankIntent(): array
    {
        return [
            'mode'              => 'recommend',
            'category'          => null,
            'brand_slugs'       => [],
            'capacity_target'   => null,
            'capacity_mode'     => null,
            'precision'         => null,
            'precisions'        => [],
            'precision_ct'      => null,
            'category_slugs'    => [],
            'commercial'        => null,
            'calibration'       => null,
            'communication'     => [],
            'condition'         => null,
            'keywords'          => [],
            'use_previous'      => false,
            'reference'         => null,
            'ranking_field'     => null,
            'ranking_direction' => null,
        ];
    }

    /**
     * @param list<mixed> $values
     * @return list<float>
     */
    private static function precisions(array $values): array
    {
        $out = [];
        foreach (array_slice($values, 0, 4) as $value) {
            if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
                continue;
            }
            $grams = (float) $value;
            if ($grams > 0 && $grams < 1) {
                $out[] = $grams;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $plan
     * @return list<array<string, mixed>>
     */
    private static function toolsOf(array $plan): array
    {
        $tools = $plan['tools'] ?? [];
        if (!is_array($tools)) {
            return [];
        }
        $allowed = [
            'search_products', 'rank_products', 'compare_products', 'get_products',
            'get_product_by_id', 'get_product_by_slug', 'get_business_facts',
            'get_service_facts', 'get_contact_actions',
        ];
        $out = [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $name = strtolower(trim((string) ($tool['name'] ?? '')));
            if (!in_array($name, $allowed, true)) {
                continue;
            }
            $tool['name'] = $name;
            $out[] = $tool;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function textOf(array $plan, string $key): string
    {
        $text = $plan[$key] ?? '';

        return is_string($text) ? trim($text) : '';
    }
    private static function topicOf(array $tool): string
    {
        $raw = strtolower(trim((string) ($tool['topic'] ?? 'overview')));
        $map = [
            'service' => 'overview', 'service_overview' => 'overview', 'overview' => 'overview',
            'warranty' => 'warranty', 'guarantee' => 'warranty', 'shipping' => 'shipping',
            'pickup' => 'shipping', 'delivery' => 'shipping', 'form' => 'form',
            'brands' => 'brand', 'brand' => 'brand', 'trade' => 'trade', 'renewed' => 'trade',
            'second_hand' => 'trade', 'ikinci_el' => 'trade', 'fee' => 'fee',
            'phone' => 'phone', 'whatsapp' => 'whatsapp', 'email' => 'email',
            'address' => 'address', 'hours' => 'hours', 'about' => 'about',
            'quote' => 'quote', 'projects' => 'projects', 'contact' => 'phone',
        ];

        return $map[$raw] ?? 'overview';
    }

    /**
     * @param array<string, mixed> $tool
     * @param list<int> $previousIds
     * @param list<array<string, mixed>> $linked
     * @return list<int>
     */
    private static function idsOf(array $tool, array $previousIds, array $linked): array
    {
        $ids = [];
        foreach (['product_ids', 'ids'] as $key) {
            if (!is_array($tool[$key] ?? null)) {
                continue;
            }
            foreach ($tool[$key] as $id) {
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $ids[] = (int) $id;
                }
            }
        }
        if (is_string($tool['slug'] ?? null) && $tool['slug'] !== '') {
            foreach (ProductAssistantCatalog::products() as $product) {
                if ((string) ($product['slug'] ?? '') === $tool['slug']) {
                    $ids[] = (int) $product['id'];
                }
            }
        }
        if ($ids === [] && $linked !== []) {
            foreach ($linked as $product) {
                $ids[] = (int) $product['id'];
            }
        }
        $reference = is_string($tool['reference'] ?? null) ? $tool['reference'] : '';
        $previousIds = array_values(array_map(static fn ($id): int => (int) $id, $previousIds));
        if ($ids === []) {
            $ids = match ($reference) {
                'first' => array_slice($previousIds, 0, 1),
                'second' => isset($previousIds[1]) ? [$previousIds[1]] : [],
                'last' => $previousIds === [] ? [] : [$previousIds[count($previousIds) - 1]],
                'these', 'previous' => array_slice($previousIds, 0, 4),
                default => [],
            };
        }

        return array_slice(array_values(array_unique($ids)), 0, 4);
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<string>
     */
    private static function productFacts(array $products, string $detail): array
    {
        $facts = [];
        foreach (array_slice($products, 0, 4) as $product) {
            $bits = [];
            $name = trim((string) ($product['brand'] ?? '') . ' ' . (string) ($product['name'] ?? ''));
            if ($name !== '') {
                $bits[] = $name;
            }
            $capacity = trim((string) ($product['capacity_label'] ?? ''));
            if ($capacity !== '') {
                $bits[] = 'kapasite ' . $capacity;
            }
            $precision = trim((string) ($product['precision_label'] ?? ''));
            if ($precision !== '') {
                $bits[] = 'hassasiyet ' . $precision;
            }
            $price = trim((string) ($product['price_label'] ?? ''));
            if ($price !== '') {
                $bits[] = 'fiyat ' . $price;
            }
            if (($product['commercial'] ?? null) === true) {
                $bits[] = 'ticari onaylı';
            }
            $links = [];
            foreach (is_array($product['communication'] ?? null) ? $product['communication'] : [] as $link) {
                $link = trim((string) $link);
                if ($link !== '') {
                    $links[] = $link;
                }
            }
            if ($links !== []) {
                $bits[] = 'bağlantı ' . implode(', ', array_values(array_unique($links)));
            }
            $condition = ProductAssistantCatalog::fold((string) ($product['condition'] ?? ''));
            if (str_contains($condition, 'yenilen') || preg_match('/2\s*\.?\s*el|ikinci el/u', $condition) === 1) {
                $bits[] = '2. el';
            }
            if ($bits !== []) {
                $facts[] = implode(', ', $bits);
            }
        }
        if ($detail === 'min_weight' && isset($products[0]) && is_array($products[0])) {
            $value = ProductAssistant::minimumSpec($products[0]);
            if ($value !== null) {
                $facts[] = 'Minimum tartım ' . $value . '.';
            }
        }

        return $facts;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<string>
     */
    private static function specFacts(array $products): array
    {
        $facts = [];
        foreach (array_slice($products, 0, 2) as $product) {
            if (!is_array($product)) {
                continue;
            }
            $lines = [];
            foreach (array_slice(is_array($product['specs'] ?? null) ? $product['specs'] : [], 0, 12) as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $label = trim((string) ($spec['label'] ?? ''));
                $value = trim((string) ($spec['value'] ?? ''));
                if ($label === '' || $value === '') {
                    continue;
                }
                $lines[] = $label . ': ' . $value;
            }
            if ($lines === []) {
                continue;
            }
            $name = trim((string) ($product['name'] ?? 'Model'));
            $facts[] = $name . ' teknik özellikleri. ' . implode('. ', $lines) . '.';
        }

        return $facts;
    }

    /**
     * @param list<string> $slugs
     * @param list<array<string, mixed>> $catalog
     */
    private static function brandHasProducts(array $slugs, array $catalog): bool
    {
        if ($slugs === []) {
            return false;
        }
        foreach ($catalog as $product) {
            if (in_array((string) ($product['brand_slug'] ?? ''), $slugs, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $intent
     * @param list<array<string, mixed>> $catalog
     */
    private static function brandName(array $intent, array $catalog): ?string
    {
        $slugs = $intent['brand_slugs'] ?? [];
        if (!is_array($slugs) || count($slugs) !== 1) {
            return null;
        }
        foreach ($catalog as $product) {
            if ((string) ($product['brand_slug'] ?? '') === (string) $slugs[0]) {
                $name = trim((string) ($product['brand'] ?? ''));

                return $name !== '' ? $name : null;
            }
        }

        return null;
    }

    private static function rankMiss(string $reason, ?string $brand, string $direction): string
    {
        if ($reason === 'no_products') {
            return $brand !== null
                ? 'Bu markaya ait yayınlanmış ürünümüz bulunmuyor.'
                : 'Bu kritere uyan yayınlanmış bir model bulamadım.';
        }
        if ($reason === 'no_price') {
            $which = $direction === 'desc' ? 'en pahalı' : 'en ucuz';
            $scope = $brand !== null ? $brand . ' terazilerimizde' : 'Terazilerimizde';

            return $scope . ' fiyat bilgisi olmadığı için ' . $which . ' modeli belirleyemiyoruz.';
        }
        if ($reason === 'no_precision') {
            $scope = $brand !== null ? $brand . ' ürünlerinde' : 'Bu ürünlerde';

            return $scope . ' gram cinsinden karşılaştırabileceğim hassasiyet bilgisi yok. Karat değerini gram ile aynı kabul etmiyorum.';
        }
        $scope = $brand !== null ? $brand . ' ürünlerinde' : 'Bu ürünlerde';

        return $scope . ' karşılaştırabileceğim kapasite bilgisi yok.';
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function rankFallback(string $field, string $direction, ?string $brand, array $products): string
    {
        $first = $products[0] ?? [];
        $name = trim((string) ($first['name'] ?? 'model'));
        $label = match ($field) {
            'price' => trim((string) ($first['price_label'] ?? '')),
            'precision' => trim((string) ($first['precision_label'] ?? '')),
            'capacity' => trim((string) ($first['capacity_label'] ?? '')),
            default => '',
        };
        $which = match ($field) {
            'price' => $direction === 'desc' ? 'en yüksek fiyatlı' : 'en uygun fiyatlı',
            'precision' => 'en hassas',
            default => $direction === 'asc' ? 'en düşük kapasiteli' : 'en yüksek kapasiteli',
        };
        $who = $brand !== null ? $brand . ' ürünleri arasında' : 'Bu kriterlerde';
        $text = $who . ' ' . $which . ' model ' . $name;
        if ($label !== '') {
            $text .= ' (' . $label . ')';
        }
        $text .= '.';
        if (count($products) > 1) {
            $text .= ' Aynı değerde ' . count($products) . ' model var.';
        }

        return $text;
    }
    private static function communicationList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $allowed = ['bluetooth', 'usb', 'wi-fi', 'rs-232', 'ethernet'];
        $out = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if (in_array($value, $allowed, true)) {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }
    private static function idList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $ids = [];
        foreach (array_slice($values, 0, 80) as $value) {
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                continue;
            }
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
    private static function namedProducts(string $message, array $catalog): array
    {
        $folded = ProductAssistantCatalog::fold($message);
        $tokens = [];
        foreach (preg_split('/[^a-z0-9]+/u', $folded) ?: [] as $token) {
            if (!is_string($token) || strlen($token) < 5) {
                continue;
            }
            if (preg_match('/^[a-z]/', $token) !== 1 || preg_match('/\d/', $token) !== 1) {
                continue;
            }
            $tokens[] = $token;
        }
        $found = [];
        foreach ($catalog as $product) {
            if (!is_array($product)) {
                continue;
            }
            $model = trim(ProductAssistantCatalog::fold((string) ($product['model_no'] ?? '')));
            $name = ProductAssistantCatalog::fold((string) ($product['name'] ?? ''));
            $codes = [];
            if (mb_strlen($model) >= 4) {
                $codes[] = $model;
            }
            if (preg_match_all('/[a-z][a-z0-9]*\d[a-z0-9-]*/u', $name, $nameCodes) > 0) {
                foreach ($nameCodes[0] as $code) {
                    $codes[] = (string) $code;
                }
            }
            if ($codes === []) {
                continue;
            }
            $hit = false;
            foreach ($codes as $code) {
                if (preg_match('/(^|[^a-z0-9])' . preg_quote($code, '/') . '([^a-z0-9]|$)/u', $folded) === 1) {
                    $hit = true;
                    break;
                }
                foreach ($tokens as $token) {
                    if ($token === $code || str_starts_with($code, $token)) {
                        $hit = true;
                        break 2;
                    }
                }
            }
            if ($hit) {
                $found[] = $product;
            }
        }

        return array_slice($found, 0, 4);
    }
    private static function slugList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $slugs = [];
        foreach ($values as $value) {
            $slug = trim((string) $value);
            if ($slug !== '' && preg_match('/^[a-z0-9\-]+$/', $slug) === 1) {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    private static function withoutSemicolon(string $text): string
    {
        $text = str_replace([' ;', ';', '；'], ['.', '.', '.'], $text);
        $text = preg_replace('/\s+\./u', '.', $text) ?? $text;
        $text = preg_replace('/\.{2,}/u', '.', $text) ?? $text;
        $text = preg_replace('/\s{2,}/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Ürün araması ve sohbet uzman butonu almaz. Karar, eksik fark ve doğrulanamayan kayıt alır.
     *
     * @param list<array<string, mixed>> $results
     */
    private static function needsExpert(array $results, string $text, string $message): bool
    {
        unset($message);
        foreach ($results as $result) {
            if (in_array((string) ($result['status'] ?? ''), ['missing_min_weight', 'no_approved_facts'], true)) {
                return true;
            }
        }

        return preg_match('/uzmanimiza|uzman destegi|ekibimizden/u', ProductAssistantCatalog::fold($text)) === 1;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $catalog
     * @return array<string, mixed>
     */
    private static function offerLiveSupport(array $payload, string $userMessage, array $catalog): array
    {
        $line = 'İsterseniz ürün uzmanımıza sorabiliriz.';
        $message = trim((string) ($payload['message'] ?? ''));
        $folded = ProductAssistantCatalog::fold($message);
        if (!str_contains($folded, 'uzman') && !str_contains($folded, 'ekibimiz')) {
            $payload['message'] = $message === '' ? $line : $message . ' ' . $line;
        }

        $actions = [];
        foreach (is_array($payload['actions'] ?? null) ? $payload['actions'] : [] as $action) {
            if (!is_array($action) || ($action['type'] ?? '') === 'whatsapp') {
                continue;
            }
            $actions[] = $action;
        }
        $actions[] = [
            'type'  => 'expert',
            'role'  => 'primary',
            'label' => 'Uzmanımıza Sor',
            'url'   => '',
        ];
        $payload['actions'] = $actions;

        $products = [];
        foreach (is_array($payload['cards'] ?? null) ? $payload['cards'] : [] as $card) {
            if (is_array($card)) {
                $products[] = $card;
            }
        }
        foreach (self::namedProducts($userMessage, $catalog) as $product) {
            $products[] = $product;
        }
        $payload['expert'] = ProductAssistantActions::expertContext($userMessage, $products);

        return $payload;
    }

    private static function envelope(string $message, array $cards, int $total, ?string $moreUrl, ?array $memory, bool $clarify, array $actions, ?string $topic = null): array
    {
        $message = self::withoutSemicolon($message);
        $payload = ProductAssistant::payload($message, $cards, $total, $moreUrl, false, null, null, $clarify, $topic, $memory !== null, $memory);
        $payload['actions'] = $actions;

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @param array<string, mixed>|null $memory
     * @param list<array<string, mixed>> $actions
     * @param array<string, mixed> $extra
     * @return array{result:array<string,mixed>,cards:list<array<string,mixed>>,total:int,more_url:?string,memory:?array,actions:list<array<string,mixed>>,fallback:string,topic:?string}
     */
    private static function piece(string $status, string $fallback, array $cards, int $total, ?string $moreUrl, ?array $memory, array $actions, ?string $topic, array $extra): array
    {
        $result = array_merge(['status' => $status], $extra);

        return [
            'result'   => $result,
            'cards'    => $cards,
            'total'    => $total,
            'more_url' => $moreUrl,
            'memory'   => $memory,
            'actions'  => $actions,
            'fallback' => $fallback,
            'topic'    => $topic,
        ];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array{id:int,name:string,brand:string,slug:string}>
     */
    private static function briefs(array $products): array
    {
        $out = [];
        foreach (array_slice($products, 0, 4) as $product) {
            $out[] = [
                'id'    => (int) ($product['id'] ?? 0),
                'name'  => (string) ($product['name'] ?? ''),
                'brand' => (string) ($product['brand'] ?? ''),
                'slug'  => (string) ($product['slug'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @return list<array<string, mixed>>
     */
    private static function linkedProducts(string $message, array $catalog): array
    {
        $found = [];
        $seen = [];
        foreach (self::namedProducts($message, $catalog) as $product) {
            $id = (int) ($product['id'] ?? 0);
            if ($id > 0 && !isset($seen[$id])) {
                $seen[$id] = true;
                $found[] = $product;
            }
        }
        foreach (ProductAssistant::productUrlSlugs($message) as $slug) {
            foreach ($catalog as $product) {
                if ((string) ($product['slug'] ?? '') !== $slug) {
                    continue;
                }
                $id = (int) ($product['id'] ?? 0);
                if ($id > 0 && !isset($seen[$id])) {
                    $seen[$id] = true;
                    $found[] = $product;
                }
                break;
            }
        }

        return array_slice($found, 0, 4);
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @return list<array{name:string,slug:string}>
     */
    private static function brandCatalog(array $catalog): array
    {
        $brands = [];
        foreach ($catalog as $product) {
            $slug = (string) ($product['brand_slug'] ?? '');
            $name = trim((string) ($product['brand'] ?? ''));
            if ($slug === '' || $name === '' || isset($brands[$slug])) {
                continue;
            }
            $brands[$slug] = ['name' => $name, 'slug' => $slug];
        }

        return array_values($brands);
    }

    /**
     * @param list<array{role:string,text:string}> $history
     * @return list<array{role:string,text:string}>
     */
    private static function cleanHistory(array $history): array
    {
        $out = [];
        foreach (array_slice($history, -6) as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text === '' || ProductAssistantIntent::refuses($text)) {
                continue;
            }
            $out[] = [
                'role' => ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                'text' => $text,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{role:string,text:string}> $history
     */
    private static function clarifySpent(array $history): bool
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $turn = $history[$i];
            if (!is_array($turn) || ($turn['role'] ?? '') !== 'assistant') {
                continue;
            }

            return str_contains((string) ($turn['text'] ?? ''), '?');
        }

        return false;
    }

    private static function isQuestion(string $text): bool
    {
        return str_contains($text, '?');
    }

    /**
     * @param list<array{role:string,text:string}> $history
     */
    private static function durabilityPreference(string $message, array $history): bool
    {
        $blob = ProductAssistantCatalog::fold($message);
        foreach ($history as $turn) {
            if (!is_array($turn) || ($turn['role'] ?? '') === 'assistant') {
                continue;
            }
            $blob .= ' ' . ProductAssistantCatalog::fold((string) ($turn['text'] ?? ''));
        }

        return preg_match('/dayanikli|uzun omur|yillarca|sorunsuz|(^|[^a-z])saglam([^a-z]|$)|uzun sure/u', $blob) === 1;
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function hasDurabilityFacts(array $products): bool
    {
        $blob = '';
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            foreach (is_array($product['specs'] ?? null) ? $product['specs'] : [] as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $blob .= ' ' . ProductAssistantCatalog::fold((string) ($spec['label'] ?? '') . ' ' . (string) ($spec['value'] ?? ''));
            }
        }

        return preg_match('/\bomur\b|mtbf|dayanikl|ip\s*\d|koruma sinif|malzeme/u', $blob) === 1;
    }

    private static function asksSpecCompare(string $message): bool
    {
        return str_contains(ProductAssistantCatalog::fold($message), 'kayitli teknik ozellik');
    }

    /**
     * @param list<array{role:string,text:string}> $history
     * @param list<array<string, mixed>> $products
     * @param list<array<string, mixed>> $catalog
     * @return array<string, mixed>
     */
    private static function durabilityLimit(string $message, array $history, array $products, array $catalog): array
    {
        $facts = [[
            'facts' => [
                'Kullanıcı uzun ömür ve sorunsuz kullanım istiyor. Bu bir seçim kriteridir. Modellerin daha dayanıklı olduğu anlamına gelmez.',
                'Bu modellerin kullanım ömrünü, dayanıklılık testini, MTBF değerini veya koruma sınıfını doğrudan karşılaştıran doğrulanmış bir veri yok.',
                'Kayıtlı teknik özellikler karşılaştırılabilir veya ürün uzmanına sorulabilir.',
            ],
        ]];
        $spoken = self::compose($message, $history, $facts);
        $text = is_string($spoken) && $spoken !== ''
            ? $spoken
            : 'Anladım, sizin için uzun ömürlü ve yıllarca sorunsuz çalışması önemli. Elimizde bu modellerin kullanım ömrünü doğrudan karşılaştıran doğrulanmış bir veri bulunmuyor. İsterseniz kayıtlı teknik farklarına bakabiliriz veya bunu ürün uzmanımıza sorabiliriz.';
        $cards = [];
        foreach (array_slice($products, 0, 2) as $product) {
            $cards[] = ProductAssistant::card($product);
        }
        $payload = self::envelope($text, $cards, count($products), null, null, false, [
            [
                'type'   => 'prompt',
                'role'   => 'primary',
                'label'  => 'Teknik Özellikleri Karşılaştır',
                'prompt' => 'Kayıtlı teknik özellikleri karşılaştır',
                'url'    => '',
            ],
        ], 'product');

        return self::offerLiveSupport($payload, $message, $catalog);
    }

    /**
     * @param list<array{role:string,text:string}> $history
     * @param list<array<string, mixed>> $products
     * @param list<array<string, mixed>> $catalog
     * @return array<string, mixed>
     */
    private static function recordedSpecs(string $message, array $history, array $products, array $catalog): array
    {
        unset($catalog);
        $facts = self::specFacts($products);
        if ($facts === []) {
            $facts = ['Bu modeller için kayıtlı teknik özellik satırı yok.'];
        }
        $spoken = self::compose($message, $history, [['facts' => $facts, 'status' => 'compared']]);
        $text = is_string($spoken) && $spoken !== '' ? $spoken : $facts[0];
        $cards = [];
        foreach (array_slice($products, 0, 2) as $product) {
            $cards[] = ProductAssistant::card($product);
        }

        return self::envelope($text, $cards, count($products), null, null, false, [], 'product');
    }

    /**
     * @param list<array<string, mixed>> $matched
     * @param list<array<string, mixed>> $displayed
     * @return array<string, mixed>
     */
    private static function resultAggregate(array $matched, array $displayed): array
    {
        $capacities = [];
        $precisions = [];
        $brands = [];
        $commercial = [];
        $calibration = [];
        $capValues = [];
        foreach ($matched as $product) {
            if (!is_array($product)) {
                continue;
            }
            $max = $product['capacity_max'] ?? null;
            if (is_float($max) || is_int($max)) {
                $key = (int) round((float) $max);
                $capacities[$key] = self::capacityChip((float) $key);
                $capValues[] = (float) $max;
            }
            foreach (self::guidePrecisionOptions([$product]) as $option) {
                $precisions[$option['label']] = $option['prose'];
            }
            $brand = trim((string) ($product['brand'] ?? ''));
            if ($brand !== '') {
                $brands[$brand] = $brand;
            }
            $commercial[($product['commercial'] ?? null) === true ? 'Ticari onaylı' : 'Ticari onaysız'] = true;
            $cal = trim((string) ($product['calibration'] ?? ''));
            if ($cal !== '') {
                $calibration[$cal] = $cal;
            }
        }
        ksort($capacities);
        $precisionLabels = array_values($precisions);
        sort($capValues);
        $minLabel = $capValues === [] ? '' : self::capacityChip($capValues[0]);
        $maxLabel = $capValues === [] ? '' : self::capacityChip($capValues[count($capValues) - 1]);

        return [
            'total_result_count' => count($matched),
            'displayed_product_count' => count($displayed),
            'available_capacities' => array_values($capacities),
            'available_precisions' => $precisionLabels,
            'available_brands' => array_values($brands),
            'available_commercial_approval_values' => array_keys($commercial),
            'available_calibration_types' => array_values($calibration),
            'capacity_min_label' => $minLabel,
            'capacity_max_label' => $maxLabel,
        ];
    }

    /**
     * @param array<string, mixed> $aggregate
     * @return list<string>
     */
    private static function aggregateFacts(array $aggregate): array
    {
        $total = (int) ($aggregate['total_result_count'] ?? 0);
        $shown = (int) ($aggregate['displayed_product_count'] ?? 0);
        $lines = [
            'Toplam eşleşen yayınlanmış model sayısı ' . $total . '. Gösterilen kart sayısı ' . $shown . '.',
        ];
        $capacities = is_array($aggregate['available_capacities'] ?? null) ? $aggregate['available_capacities'] : [];
        $precisions = is_array($aggregate['available_precisions'] ?? null) ? $aggregate['available_precisions'] : [];
        if ($capacities !== []) {
            $lines[] = 'Tüm eşleşen ürünlerin kapasiteleri: ' . implode(', ', $capacities) . '.';
        }
        if ($precisions !== []) {
            $lines[] = 'Tüm eşleşen ürünlerin hassasiyetleri: ' . implode(', ', $precisions) . '. 0.001 ct ile 0.001 g aynı şey değildir.';
        }
        $brands = is_array($aggregate['available_brands'] ?? null) ? $aggregate['available_brands'] : [];
        if ($brands !== []) {
            $lines[] = 'Tüm eşleşen markalar: ' . implode(', ', $brands) . '.';
        }
        if ($total > $shown) {
            $lines[] = 'Gösterilen kartlar kümenin tamamı değildir. Kart kapasitelerini tüm seçenekler gibi sayma.';
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private static function guardPartialCatalog(string $text, array $results): string
    {
        foreach ($results as $result) {
            $aggregate = is_array($result) ? ($result['aggregate'] ?? null) : null;
            if (!is_array($aggregate)) {
                continue;
            }
            $total = (int) ($aggregate['total_result_count'] ?? 0);
            $shown = (int) ($aggregate['displayed_product_count'] ?? 0);
            if ($total <= $shown || $shown < 1) {
                continue;
            }
            $folded = ProductAssistantCatalog::fold($text);
            if (preg_match('/seceneklerimiz var|seceneklerimiz bunlar|kapasiteli secenek/u', $folded) !== 1) {
                continue;
            }
            $min = (string) ($aggregate['capacity_min_label'] ?? '');
            $max = (string) ($aggregate['capacity_max_label'] ?? '');
            $range = ($min !== '' && $max !== '' && $min !== $max) ? ' Kapasiteler ' . $min . ' ile ' . $max . ' arasında değişiyor.' : '';

            return $total . ' uygun model buldum. İlk ' . $shown . ' seçeneği aşağıda gösteriyorum.' . $range;
        }

        return $text;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param array<string, mixed> $memory
     */
    private static function contactHandoff(string $message): ?array
    {
        $folded = str_replace(["'", '’', '`'], '', ProductAssistantCatalog::fold($message));
        if (preg_match('/terazi|kuyumcu|laboratuvar|hassasiyet|kapasite/u', $folded) === 1
            && preg_match('/whatsapp|telefonla|ulas|danis|gorus|yazabilir/u', $folded) !== 1) {
            return null;
        }
        $kind = null;
        if (preg_match('/buradan|bu sohbet/u', $folded) === 1 && preg_match('/yaz|konus|gorus|ulas/u', $folded) === 1) {
            $kind = 'chat';
        } elseif (preg_match('/whatsapp/u', $folded) === 1) {
            $kind = 'whatsapp';
        } elseif (preg_match('/telefonla/u', $folded) === 1) {
            $kind = 'phone';
        } elseif (preg_match('/birine danis|uzmanla konus|nasil ulas|biriyle gorus|biriyle konus|danisabilir|gorusebilir miyim/u', $folded) === 1) {
            $kind = 'human';
        }
        if ($kind === null) {
            return null;
        }

        $text = match ($kind) {
            'whatsapp' => 'Evet, WhatsApp üzerinden bize ulaşabilirsiniz. İsterseniz buradaki sohbet üzerinden de ekibimize mesaj bırakabilirsiniz.',
            'phone' => 'Telefonla da ulaşabilirsiniz. İsterseniz bu sohbetten veya WhatsApp üzerinden de yazabilirsiniz.',
            default => 'Bu sohbet üzerinden ekibimize mesaj bırakabilirsiniz. İsterseniz WhatsApp üzerinden de devam edebilirsiniz.',
        };
        $chat = [
            'type' => 'expert_chat',
            'role' => 'secondary',
            'label' => 'Bu Sohbette Devam Et',
            'url' => '',
            'tone' => 'handoff',
        ];
        $whatsapp = site_whatsapp_number() !== '' ? [
            'type' => 'expert_whatsapp',
            'role' => 'secondary',
            'label' => "WhatsApp'ta Devam Et",
            'url' => '',
            'tone' => 'handoff',
        ] : null;
        $phone = ProductAssistantActions::phone();
        if ($phone !== null) {
            $phone['tone'] = 'handoff';
            $phone['role'] = 'secondary';
            $phone['label'] = 'Bizi Ara';
        }
        $service = preg_match('/servis/u', $folded) === 1 ? ProductAssistantActions::serviceForm() : null;
        if ($service !== null) {
            $service['tone'] = 'handoff';
            $service['role'] = 'secondary';
        }
        $actions = [];
        if ($kind === 'whatsapp') {
            if ($whatsapp !== null) {
                $whatsapp['role'] = 'primary';
                $actions[] = $whatsapp;
            }
            $actions[] = $chat;
        } elseif ($kind === 'phone') {
            if ($phone !== null) {
                $phone['role'] = 'primary';
                $actions[] = $phone;
            }
            $actions[] = $chat;
            if ($whatsapp !== null) {
                $actions[] = $whatsapp;
            }
        } else {
            $chat['role'] = 'primary';
            $actions[] = $chat;
            if ($whatsapp !== null) {
                $actions[] = $whatsapp;
            }
        }
        if ($service !== null) {
            $actions[] = $service;
        }
        $payload = self::envelope($text, [], 0, null, null, false, $actions, 'company');
        $payload['expert'] = ProductAssistantActions::expertContext($message, []);

        return $payload;
    }

    private static function isSmallTalk(string $message): bool
    {
        $folded = ProductAssistantCatalog::fold($message);
        if (preg_match('/\bmac\b|maci|skor|hava durumu|bitcoin|dolar|secim|tarif/u', $folded) === 1) {
            return false;
        }

        return preg_match('/merhaba|selam|nasilsin|nasilsiniz|tesekkur|iyi gun|iyi aksam|naber|yorgun|kolay gelsin/u', $folded) === 1
            && !self::inDomain($message, [], []);
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param array<string, mixed> $memory
     */
    private static function inDomain(string $message, array $catalog, array $memory): bool
    {
        if ($catalog !== [] && self::namedProducts($message, $catalog) !== []) {
            return true;
        }
        if (ProductAssistantIntent::serviceKind($message, '') !== null || ProductAssistantIntent::companyTopic($message) !== null) {
            return true;
        }
        $parsed = ProductAssistantIntent::parse($message, $catalog);
        if (($parsed['category'] ?? null) !== null || ($parsed['brand_slugs'] ?? []) !== [] || ($parsed['precisions'] ?? []) !== []) {
            return true;
        }
        if (($parsed['capacity_target'] ?? null) !== null || is_float($parsed['precision_ct'] ?? null) || is_int($parsed['precision_ct'] ?? null)) {
            return true;
        }
        if (($parsed['commercial'] ?? null) !== null || ($parsed['calibration'] ?? null) !== null || ($parsed['condition'] ?? null) !== null) {
            return true;
        }
        $folded = ProductAssistantCatalog::fold($message);
        if (preg_match('/terazi|tarti|servis|garanti|kargo|kalibrasyon|hassasiyet|kapasite/u', $folded) === 1) {
            return true;
        }

        return !empty($memory['guided']) && self::guideReply($folded, $memory);
    }

    /**
     * @param array<string, mixed> $memory
     */
    private static function guideReply(string $folded, array $memory): bool
    {
        if (preg_match('/(?<!\d)0[.,]\d+/u', $folded) === 1 || preg_match('/\d+\s*(kg|kilo|g|gr|gram)\b/u', $folded) === 1) {
            return true;
        }
        $step = (string) ($memory['guided_step'] ?? '');
        if ($step === 'commercial' && preg_match('/^(evet|hayir|gerekli|gerekmez|istiyorum|istemiyorum)[.! ]*$/u', $folded) === 1) {
            return true;
        }
        if (preg_match('/1\s*kg.a kadar|1\s*-\s*3\s*kg|3\s*kg uzeri/u', $folded) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param array<string, mixed> $memory
     */
    private static function outsideRedirect(string $message, array $catalog, array $memory): string
    {
        $json = ProductAssistantLlm::completeJson(
            'Terazibul asistanısın. Kullanıcı kapsam dışı bir olgu sordu. Skoru, havayı, kuru, haberi veya tarifi cevaplama. Doğrulayamıyoruz deme. Google, canlı skor uygulaması veya başka site önerme. Tek kısa cümleyle bu konuyu takip etmediğini söyle ve terazi, ürün seçimi veya servise dön. JSON: {"message":"..."}',
            json_encode(['message' => $message], JSON_UNESCAPED_UNICODE) ?: '{}',
            12,
            'aside'
        );
        $text = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';
        if ($text === '') {
            $text = 'Bu konuda yardımcı olamıyorum. Terazi, ürün seçimi veya servis konusunda yardımcı olabilirim.';
        }

        return self::guardOutside($text, $message, $catalog, $memory);
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param array<string, mixed> $memory
     */
    private static function guardOutside(string $text, string $message, array $catalog, array $memory): string
    {
        $folded = ProductAssistantCatalog::fold($text);
        $external = preg_match('/canli skor|baska uygulama|google|internetten|skor uygulama|uygulamasina bak|baska site|baska bir site/u', $folded) === 1;
        $verify = preg_match('/dogrulayam|dogrulayami/u', $folded) === 1 && !self::inDomain($message, $catalog, $memory);
        if (!$external && !$verify) {
            return $text;
        }
        $rewritten = self::rewriteChat($message, 'Konu Terazibul dışı. Kısa ve doğal şekilde terazi, ürün seçimi veya servise dön. Doğrulayamıyoruz deme. Başka uygulama, site veya Google önerme.');
        if (is_string($rewritten) && preg_match('/dogrulayam|dogrulayami|canli skor|baska uygulama|google|skor uygulama|baska site/u', ProductAssistantCatalog::fold($rewritten)) !== 1) {
            return $rewritten;
        }

        return 'Bu konuda yardımcı olamıyorum. Terazi, ürün seçimi veya servis konusunda yardımcı olabilirim.';
    }

    /**
     * @param list<array<string, mixed>> $catalog
     */
    private static function hasOutsideClause(string $message, array $catalog): bool
    {
        $folded = self::foldGuide($message);
        $stop = [
            'terazi', 'terazisi', 'terazin', 'tarti', 'kuyumcu', 'kuyum', 'laboratuvar', 'laboratuar',
            'endustriyel', 'endustri', 'perakende', 'hassas', 'analitik', 'kapasite', 'hassasiyet',
            'ariyorum', 'istiyorum', 'ihtiyac', 'lazim', 'civari', 'yaklasik', 'goster', 'var',
            'icin', 'bir', 'daha', 'model', 'urun', 'servis', 'ticari', 'onay', 'gram', 'kilo',
            'kadar', 'uygun', 'olan',
        ];
        $brands = [];
        foreach ($catalog as $product) {
            $name = ProductAssistantCatalog::fold((string) ($product['brand'] ?? ''));
            if ($name !== '') {
                $brands[$name] = true;
            }
        }
        foreach (preg_split('/[^a-z0-9]+/u', $folded) ?: [] as $token) {
            if (!is_string($token) || strlen($token) < 4 || preg_match('/^\d/', $token) === 1) {
                continue;
            }
            $known = isset($brands[$token]);
            foreach ($stop as $word) {
                if ($token === $word || str_starts_with($token, $word)) {
                    $known = true;
                    break;
                }
            }
            if (!$known) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param array<string, mixed> $memory
     * @return array<string, mixed>|null
     */
    private static function guidedDiscover(string $message, array $catalog, array $memory): ?array
    {
        $state = self::guideState($message, $catalog, $memory);
        if ($state === null) {
            return null;
        }
        $products = self::guideProducts($catalog, $state);
        $steps = self::guideSteps((string) $state['category']);
        foreach ($steps as $step) {
            if (self::guideFilled($step, $state)) {
                continue;
            }
            $options = self::guideOptions($step, $products);
            if (count($options) < 2 || count($products) <= 4) {
                continue;
            }
            $memoryOut = self::guideMemory($state, $step);
            $text = self::guideQuestion($step);
            if (self::hasOutsideClause($message, $catalog)) {
                $text = self::outsideRedirect($message, $catalog, $memory) . ' ' . $text;
            }
            $payload = self::envelope($text, [], 0, null, $memoryOut, true, []);
            $payload['prompts'] = array_map(static fn (array $option): string => $option['label'], $options);
            $payload['aggregate'] = self::resultAggregate($products, []);

            return $payload;
        }

        if ($products === []) {
            return self::envelope('Bu kriterlerle yayınlanmış bir model yok.', [], 0, null, self::guideMemory($state, ''), false, [], 'product');
        }
        $shown = array_slice($products, 0, 4);
        $aggregate = self::resultAggregate($products, $shown);
        $cards = [];
        foreach ($shown as $product) {
            $cards[] = ProductAssistant::card($product);
        }
        $text = self::guideSummary($state, $aggregate);
        if (self::hasOutsideClause($message, $catalog)) {
            $text = self::outsideRedirect($message, $catalog, $memory) . ' ' . $text;
        }
        $payload = self::envelope($text, $cards, count($products), null, self::guideMemory($state, ''), false, [], 'product');
        $payload['aggregate'] = $aggregate;

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param array<string, mixed> $memory
     * @return array<string, mixed>|null
     */
    private static function guideState(string $message, array $catalog, array $memory): ?array
    {
        $parsed = ProductAssistantIntent::parse($message, $catalog);
        $mode = (string) ($parsed['mode'] ?? '');
        if (in_array($mode, ['ranking', 'compare', 'min_weight', 'greeting', 'refusal'], true)) {
            return null;
        }
        if (ProductAssistantIntent::serviceKind($message, '') !== null || ProductAssistantIntent::companyTopic($message) !== null) {
            return null;
        }
        if (self::namedProducts($message, $catalog) !== []) {
            return null;
        }
        $folded = self::foldGuide($message);
        if (preg_match('/ozellik|nedir|neler|spesifikasyon|teknik bilgi/u', $folded) === 1 && preg_match('/ariyorum|lazim|istiyorum|ihtiyac/u', $folded) !== 1) {
            return null;
        }
        $guided = !empty($memory['guided']);
        $category = is_string($parsed['category'] ?? null) ? $parsed['category'] : null;
        $shopping = preg_match('/terazi|tarti|ariyorum|lazim|istiyorum|ihtiyac|goster|oner/u', $folded) === 1;
        $reply = $guided && self::guideReply($folded, $memory);
        if (!$shopping && !$reply) {
            return null;
        }
        if ($category === null && $guided && is_string($memory['category'] ?? null)) {
            $category = $memory['category'];
        }
        if (!is_string($category) || $category === '') {
            return null;
        }
        $switched = $guided && is_string($parsed['category'] ?? null) && is_string($memory['category'] ?? null) && $parsed['category'] !== $memory['category'];
        $precision = null;
        $ct = null;
        if (!$switched && $guided) {
            if (is_numeric($memory['precision_ct'] ?? null)) {
                $ct = (float) $memory['precision_ct'];
            } elseif (is_numeric($memory['precisions'][0] ?? null)) {
                $precision = (float) $memory['precisions'][0];
            }
        }
        if (is_float($parsed['precision_ct'] ?? null) || is_int($parsed['precision_ct'] ?? null)) {
            $ct = (float) $parsed['precision_ct'];
            $precision = null;
        } elseif (($parsed['precisions'] ?? []) !== []) {
            $precision = (float) $parsed['precisions'][0];
            $ct = null;
        }
        $capacity = !$switched && $guided && is_numeric($memory['capacity_target'] ?? null) ? (float) $memory['capacity_target'] : null;
        $capacityMode = !$switched && $guided && is_string($memory['capacity_mode'] ?? null) ? $memory['capacity_mode'] : 'around';
        if (is_float($parsed['capacity_target'] ?? null) || is_int($parsed['capacity_target'] ?? null)) {
            $capacity = (float) $parsed['capacity_target'];
            $capacityMode = is_string($parsed['capacity_mode'] ?? null) ? $parsed['capacity_mode'] : 'around';
        }
        if (preg_match('/1\s*kg.a kadar|1 kga kadar/u', $folded) === 1) {
            $capacity = null;
            $capacityMode = 'band_low';
        } elseif (preg_match('/1\s*-\s*3\s*kg/u', $folded) === 1) {
            $capacity = null;
            $capacityMode = 'band_mid';
        } elseif (preg_match('/3\s*kg uzeri/u', $folded) === 1) {
            $capacity = null;
            $capacityMode = 'band_high';
        }
        $commercial = !$switched && $guided && is_bool($memory['commercial'] ?? null) ? $memory['commercial'] : null;
        if (is_bool($parsed['commercial'] ?? null)) {
            $commercial = $parsed['commercial'];
        } elseif ((string) ($memory['guided_step'] ?? '') === 'commercial' || $shopping) {
            if (preg_match('/^(evet|gerekli|istiyorum)[.! ]*$/u', $folded) === 1) {
                $commercial = true;
            } elseif (preg_match('/^(hayir|gerekmez|istemiyorum)[.! ]*$/u', $folded) === 1) {
                $commercial = false;
            }
        }
        $calibration = !$switched && $guided && in_array($memory['calibration'] ?? null, ['auto', 'manual'], true) ? $memory['calibration'] : null;
        if (in_array($parsed['calibration'] ?? null, ['auto', 'manual'], true)) {
            $calibration = $parsed['calibration'];
        } elseif ((string) ($memory['guided_step'] ?? '') === 'calibration') {
            if (preg_match('/dahili|evet|otomatik/u', $folded) === 1) {
                $calibration = 'auto';
            } elseif (preg_match('/harici|hayir|manuel/u', $folded) === 1) {
                $calibration = 'manual';
            }
        }
        $brands = !$switched && $guided && is_array($memory['brand_slugs'] ?? null) ? $memory['brand_slugs'] : [];
        if (($parsed['brand_slugs'] ?? []) !== []) {
            $brands = $parsed['brand_slugs'];
        }

        return [
            'category' => $category,
            'precision' => $precision,
            'precision_ct' => $ct,
            'capacity' => $capacity,
            'capacity_mode' => $capacityMode,
            'commercial' => $commercial,
            'calibration' => $calibration,
            'brands' => array_values(is_array($brands) ? $brands : []),
        ];
    }

    /**
     * @return list<string>
     */
    private static function guideSteps(string $category): array
    {
        return match ($category) {
            'endustriyel' => ['capacity', 'precision'],
            'perakende' => ['capacity'],
            default => ['precision', 'capacity'],
        };
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function guideFilled(string $step, array $state): bool
    {
        return match ($step) {
            'precision' => $state['precision'] !== null || $state['precision_ct'] !== null,
            'capacity' => $state['capacity'] !== null || in_array($state['capacity_mode'], ['band_low', 'band_mid', 'band_high'], true),
            'commercial' => is_bool($state['commercial']),
            'calibration' => is_string($state['calibration']) && $state['calibration'] !== '',
            default => false,
        };
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param array<string, mixed> $state
     * @return list<array<string, mixed>>
     */
    private static function guideProducts(array $catalog, array $state): array
    {
        $out = [];
        foreach ($catalog as $product) {
            if (!is_array($product) || !self::guideProductMatches($product, $state)) {
                continue;
            }
            $out[] = $product;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $state
     */
    private static function guideProductMatches(array $product, array $state): bool
    {
        $inCategory = false;
        foreach (is_array($product['categories'] ?? null) ? $product['categories'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = (string) ($row['slug'] ?? '');
            $parent = (string) ($row['parent_slug'] ?? '');
            $root = (string) ($row['root_slug'] ?? '');
            if ($slug === $state['category'] || $parent === $state['category'] || $root === $state['category']) {
                $inCategory = true;
                break;
            }
        }
        if (!$inCategory) {
            return false;
        }
        $brands = is_array($state['brands'] ?? null) ? $state['brands'] : [];
        if ($brands !== [] && !in_array((string) ($product['brand_slug'] ?? ''), $brands, true)) {
            return false;
        }
        if (!self::guidePrecisionMatches($product, $state['precision'], $state['precision_ct'])) {
            return false;
        }
        if (!self::guideCapacityMatches($product, $state)) {
            return false;
        }
        if (is_bool($state['commercial']) && ($product['commercial'] ?? null) !== $state['commercial']) {
            return false;
        }
        if (is_string($state['calibration']) && $state['calibration'] !== '') {
            $cal = ProductAssistantCatalog::fold((string) ($product['calibration'] ?? ''));
            $auto = str_contains($cal, 'otomatik') || str_contains($cal, 'ic') || str_contains($cal, 'dahili');
            $manual = str_contains($cal, 'harici') || str_contains($cal, 'manuel') || str_contains($cal, 'dis');
            if ($state['calibration'] === 'auto' && !$auto) {
                return false;
            }
            if ($state['calibration'] === 'manual' && !$manual) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function guidePrecisionMatches(array $product, ?float $grams, ?float $ct): bool
    {
        if ($ct === null && $grams === null) {
            return true;
        }
        foreach (is_array($product['precision_filters'] ?? null) ? $product['precision_filters'] : [] as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $value = $filter['grams'] ?? null;
            if (!is_float($value) && !is_int($value)) {
                continue;
            }
            $unit = (string) ($filter['unit'] ?? 'g');
            $label = ProductAssistantCatalog::fold((string) ($filter['label'] ?? ''));
            if (preg_match('/(?<=\d)\s*ct\b|karat/u', $label) === 1) {
                $unit = 'ct';
            }
            $target = $ct !== null ? $ct : $grams;
            if ($target === null || $target <= 0 || ($ct !== null && $unit !== 'ct') || ($ct === null && $unit === 'ct')) {
                continue;
            }
            if (abs((float) $value - $target) / $target <= 0.02) {
                return true;
            }
        }
        if ($ct !== null) {
            return false;
        }
        $own = $product['precision_grams'] ?? null;
        $ownUnit = (string) ($product['precision_unit'] ?? 'g');
        if ($ownUnit === 'ct' || (!is_float($own) && !is_int($own)) || $grams === null || $grams <= 0) {
            return false;
        }

        return abs((float) $own - $grams) / $grams <= 0.02;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $state
     */
    private static function guideCapacityMatches(array $product, array $state): bool
    {
        $max = $product['capacity_max'] ?? null;
        if (!is_float($max) && !is_int($max)) {
            return ($state['capacity'] ?? null) === null && !in_array($state['capacity_mode'] ?? '', ['band_low', 'band_mid', 'band_high'], true);
        }
        $max = (float) $max;
        $mode = (string) ($state['capacity_mode'] ?? 'around');
        if ($mode === 'band_low') {
            return $max <= 1000;
        }
        if ($mode === 'band_mid') {
            return $max > 1000 && $max <= 3000;
        }
        if ($mode === 'band_high') {
            return $max > 3000;
        }
        $target = $state['capacity'] ?? null;
        if (!is_float($target) && !is_int($target)) {
            return true;
        }
        $target = (float) $target;
        $min = $mode === 'upto' ? $target * 0.45 : $target * 0.65;
        $hi = $target * 1.55;
        $pmin = is_float($product['capacity_min'] ?? null) || is_int($product['capacity_min'] ?? null) ? (float) $product['capacity_min'] : $max;

        return !($max < $min || $pmin > $hi);
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array{label:string,prose:string}>
     */
    private static function guideOptions(string $step, array $products): array
    {
        if ($step === 'precision') {
            return self::guidePrecisionOptions($products);
        }
        if ($step === 'capacity') {
            return self::guideCapacityOptions($products);
        }
        if ($step === 'commercial') {
            $yes = 0;
            $no = 0;
            foreach ($products as $product) {
                if (($product['commercial'] ?? null) === true) {
                    $yes++;
                } else {
                    $no++;
                }
            }
            if ($yes < 1 || $no < 1) {
                return [];
            }

            return [
                ['label' => 'Evet', 'prose' => 'Evet'],
                ['label' => 'Hayır', 'prose' => 'Hayır'],
            ];
        }
        if ($step === 'calibration') {
            $auto = 0;
            $manual = 0;
            foreach ($products as $product) {
                $cal = ProductAssistantCatalog::fold((string) ($product['calibration'] ?? ''));
                if (str_contains($cal, 'otomatik') || str_contains($cal, 'dahili') || str_contains($cal, 'ic')) {
                    $auto++;
                }
                if (str_contains($cal, 'harici') || str_contains($cal, 'manuel') || str_contains($cal, 'dis')) {
                    $manual++;
                }
            }
            if ($auto < 1 || $manual < 1) {
                return [];
            }

            return [
                ['label' => 'Dahili', 'prose' => 'Dahili'],
                ['label' => 'Harici', 'prose' => 'Harici'],
            ];
        }
        $links = [];
        foreach ($products as $product) {
            foreach (is_array($product['communication'] ?? null) ? $product['communication'] : [] as $link) {
                $link = trim((string) $link);
                if ($link !== '') {
                    $links[$link] = ['label' => $link, 'prose' => $link];
                }
            }
        }

        return count($links) >= 2 ? array_slice(array_values($links), 0, 4) : [];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array{label:string,prose:string}>
     */
    private static function guidePrecisionOptions(array $products): array
    {
        $seen = [];
        foreach ($products as $product) {
            foreach (is_array($product['precision_filters'] ?? null) ? $product['precision_filters'] : [] as $filter) {
                if (!is_array($filter)) {
                    continue;
                }
                $grams = $filter['grams'] ?? null;
                if (!is_float($grams) && !is_int($grams) || (float) $grams <= 0) {
                    continue;
                }
                $labelText = ProductAssistantCatalog::fold((string) ($filter['label'] ?? ''));
                $unit = ((string) ($filter['unit'] ?? '') === 'ct' || preg_match('/(?<=\d)\s*ct\b|karat/u', $labelText) === 1) ? 'ct' : 'g';
                $chip = self::precisionChip((float) $grams, $unit);
                $seen[$chip] = [
                    'label' => $chip,
                    'prose' => self::precisionProse((float) $grams, $unit),
                    'grams' => (float) $grams,
                    'unit' => $unit,
                ];
            }
        }
        $rows = array_values($seen);
        usort($rows, static function (array $left, array $right): int {
            $unit = (($left['unit'] === 'ct') <=> ($right['unit'] === 'ct'));
            if ($unit !== 0) {
                return $unit;
            }

            return $left['grams'] <=> $right['grams'];
        });

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array{label:string,prose:string}>
     */
    private static function guideCapacityOptions(array $products): array
    {
        $unique = [];
        foreach ($products as $product) {
            $max = $product['capacity_max'] ?? null;
            if (!is_float($max) && !is_int($max) || (float) $max <= 0) {
                continue;
            }
            $key = (int) round((float) $max);
            $unique[$key] = ($unique[$key] ?? 0) + 1;
        }
        if (count($unique) > 6) {
            $bands = [];
            foreach (array_keys($unique) as $grams) {
                if ($grams <= 1000) {
                    $bands['1 kg\'a kadar'] = true;
                } elseif ($grams <= 3000) {
                    $bands['1-3 kg'] = true;
                } else {
                    $bands['3 kg üzeri'] = true;
                }
            }
            $rows = [];
            foreach (['1 kg\'a kadar', '1-3 kg', '3 kg üzeri'] as $label) {
                if (isset($bands[$label])) {
                    $rows[] = ['label' => $label, 'prose' => $label];
                }
            }

            return $rows;
        }
        ksort($unique);
        $rows = [];
        foreach (array_keys($unique) as $grams) {
            $label = self::capacityChip((float) $grams);
            $rows[] = ['label' => $label, 'prose' => $label];
        }

        return $rows;
    }

    private static function precisionChip(float $grams, string $unit): string
    {
        $text = rtrim(rtrim(number_format($grams, 6, '.', ''), '0'), '.');

        return $text . ($unit === 'ct' ? 'ct' : 'g');
    }

    private static function precisionProse(float $grams, string $unit): string
    {
        $text = rtrim(rtrim(number_format($grams, 6, '.', ''), '0'), '.');

        return $text . ($unit === 'ct' ? ' ct' : ' g');
    }

    private static function capacityChip(float $grams): string
    {
        if ($grams >= 1000 && abs(($grams / 1000) - round($grams / 1000)) < 0.02) {
            return (int) round($grams / 1000) . ' kg';
        }

        return (int) round($grams) . ' g';
    }

    private static function guideQuestion(string $step): string
    {
        return match ($step) {
            'capacity' => 'En fazla yaklaşık kaç gram tartacaksınız?',
            'commercial' => 'Ticari onay sizin için gerekli mi?',
            'calibration' => 'Dahili kalibrasyon tercih ediyor musunuz?',
            'connection' => 'Bağlantı tarafında bir tercihiniz var mı?',
            default => 'Ne kadar hassas bir ölçüm ihtiyacınız var?',
        };
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $aggregate
     */
    private static function guideSummary(array $state, array $aggregate): string
    {
        unset($aggregate);
        $grams = is_float($state['precision']) || is_int($state['precision']) ? (float) $state['precision'] : null;
        $ct = is_float($state['precision_ct']) || is_int($state['precision_ct']) ? (float) $state['precision_ct'] : null;
        $capacity = is_float($state['capacity']) || is_int($state['capacity']) ? (float) $state['capacity'] : null;

        return self::listSentence($grams, $ct, $capacity);
    }

    private static function listSentence(?float $grams, ?float $ct, ?float $capacity): string
    {
        $tail = ' Ek olarak başka merak ettiğiniz bir özellik veya ürün var mı?';
        $precision = null;
        if ($ct !== null && $ct > 0) {
            $precision = self::precisionProse($ct, 'ct');
        } elseif ($grams !== null && $grams > 0) {
            $precision = self::precisionProse($grams, 'g');
        }
        $cap = ($capacity !== null && $capacity > 0) ? 'yaklaşık ' . self::capacityChip($capacity) . ' kapasite' : null;
        if ($precision !== null && $cap !== null) {
            return 'Aşağıda ' . $precision . ' hassasiyet ve ' . $cap . 'ye uygun terazilerimizin listesini bulabilirsiniz.' . $tail;
        }
        if ($precision !== null) {
            return 'Aşağıda ' . $precision . ' hassasiyete sahip terazilerimizin listesini bulabilirsiniz.' . $tail;
        }
        if ($cap !== null) {
            return 'Aşağıda ' . $cap . 'ye uygun terazilerimizin listesini bulabilirsiniz.' . $tail;
        }

        return 'Aşağıda terazilerimizin listesini bulabilirsiniz.' . $tail;
    }

    /**
     * @param array<string, mixed>|null $memory
     * @param list<array<string, mixed>> $catalog
     */
    private static function listSentenceFor(string $message, array $catalog, ?array $memory): string
    {
        $parsed = ProductAssistantIntent::parse($message, $catalog);
        $grams = null;
        $ct = null;
        $capacity = null;
        if (is_numeric($parsed['precision_ct'] ?? null)) {
            $ct = (float) $parsed['precision_ct'];
        } elseif (is_numeric($parsed['precisions'][0] ?? null)) {
            $grams = (float) $parsed['precisions'][0];
        } elseif (is_numeric($memory['precision_ct'] ?? null)) {
            $ct = (float) $memory['precision_ct'];
        } elseif (is_numeric($memory['precisions'][0] ?? null)) {
            $grams = (float) $memory['precisions'][0];
        }
        if (is_numeric($parsed['capacity_target'] ?? null)) {
            $capacity = (float) $parsed['capacity_target'];
        } elseif (is_numeric($memory['capacity_target'] ?? null)) {
            $capacity = (float) $memory['capacity_target'];
        }

        return self::listSentence($grams, $ct, $capacity);
    }

    /**
     * @param array{results?:list<array<string,mixed>>,cards?:list<array<string,mixed>>} $executed
     */
    private static function shouldUseListSentence(string $message, array $executed): bool
    {
        $cards = $executed['cards'] ?? [];
        if (!is_array($cards) || $cards === []) {
            return false;
        }
        $folded = self::foldGuide($message);
        if (preg_match('/ozellik|nedir|neler|spesifikasyon|teknik|karsilastir|fark/u', $folded) === 1) {
            return false;
        }
        foreach (is_array($executed['results'] ?? null) ? $executed['results'] : [] as $result) {
            if (is_array($result) && ($result['status'] ?? '') === 'products_found') {
                return true;
            }
        }

        return false;
    }

    private static function strayFeature(string $message): bool
    {
        $folded = self::foldGuide($message);
        if (preg_match('/kalibrasyon/u', $folded) === 1) {
            return false;
        }

        return preg_match('/^(dahili|harici)[.!? ]*$/u', $folded) === 1;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @return array<string, mixed>|null
     */
    private static function unsupportedWish(string $message, array $catalog): ?array
    {
        $folded = self::foldGuide($message);
        if (preg_match('/ariyorum|istiyorum|lazim|ihtiyac/u', $folded) !== 1) {
            return null;
        }
        if (self::mapsToCatalog($message, $catalog)) {
            return null;
        }
        if (!self::hasOutsideClause($message, $catalog)) {
            return null;
        }
        $text = 'Bu isteği katalogdaki kayıtlı özelliklerle eşleştiremiyorum. Dilerseniz müşteri temsilcimize bağlanabilirsiniz.';
        $actions = [[
            'type' => 'expert_chat',
            'role' => 'primary',
            'label' => 'Bu Sohbette Devam Et',
            'url' => '',
            'tone' => 'handoff',
        ]];
        if (site_whatsapp_number() !== '') {
            $actions[] = [
                'type' => 'expert_whatsapp',
                'role' => 'secondary',
                'label' => "WhatsApp'ta Devam Et",
                'url' => '',
                'tone' => 'handoff',
            ];
        }
        $payload = self::envelope($text, [], 0, null, null, false, $actions, 'company');
        $payload['expert'] = ProductAssistantActions::expertContext($message, []);

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     */
    private static function mapsToCatalog(string $message, array $catalog): bool
    {
        if (self::namedProducts($message, $catalog) !== []) {
            return true;
        }
        $parsed = ProductAssistantIntent::parse($message, $catalog);
        if (($parsed['category'] ?? null) !== null || ($parsed['brand_slugs'] ?? []) !== []) {
            return true;
        }
        if (($parsed['precisions'] ?? []) !== [] || is_numeric($parsed['precision_ct'] ?? null)) {
            return true;
        }
        if (is_numeric($parsed['capacity_target'] ?? null)) {
            return true;
        }
        if (($parsed['commercial'] ?? null) !== null || ($parsed['condition'] ?? null) !== null) {
            return true;
        }
        if (($parsed['communication'] ?? []) !== []) {
            return true;
        }

        return ($parsed['calibration'] ?? null) !== null;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function guideMemory(array $state, string $step): array
    {
        return [
            'guided' => true,
            'guided_step' => $step,
            'brand_slugs' => $state['brands'],
            'category' => $state['category'],
            'category_slugs' => [$state['category']],
            'precisions' => $state['precision'] !== null ? [$state['precision']] : [],
            'precision_ct' => $state['precision_ct'],
            'capacity_target' => $state['capacity'],
            'capacity_mode' => $state['capacity_mode'],
            'ranking_field' => '',
            'ranking_direction' => '',
            'communication' => [],
            'commercial' => $state['commercial'],
            'calibration' => $state['calibration'],
            'scope_ids' => [],
        ];
    }

    private static function foldGuide(string $message): string
    {
        return str_replace(["'", '’', '`'], '', ProductAssistantCatalog::fold($message));
    }

    private static function planPrompt(): string
    {
        return <<<'TXT'
Kullanıcının niyetini, cümlenin tamamını ve konuşma geçmişini yorumla. Yalnızca JSON plan döndür.
Bu turda geçerli filtrelerin tamamını tools içine yaz. Backend önceki filtreyi kendisi taşımaz veya silmez. Devam eden aramada hâlâ geçerli marka, kategori, hassasiyet, kapasite ve sıralamayı kopyala. Yeni bir aramada eski kriterleri yazma.
Aynı karar için en fazla bir clarification sor. clarification_spent true ise clarify boş kalsın ve tools ile ilerle.
Dayanıklı, uzun ömürlü, yıllarca çalışsın, sorunsuz çalışsın, sağlam olsun ve uzun süre kullanayım aynı seçim kriteridir. Biri söylendiyse bunu tekrar sorma. Bu tercih ürünün gerçekten daha dayanıklı olduğu anlamına gelmez.
Bu kriter için kullanım ömrü, dayanıklılık testi, MTBF veya koruma sınıfı gibi doğrulanmış veri yoksa yeni soru üretme. compare_products veya get_products ile kayıtlı özelliklere geç.
Yeni soru bir sonraki araç filtresini değiştirmeyecekse sorma. Anladıysan ilerle.
Eksik bilgi ürün seçimini gerçekten değiştiriyorsa ve henüz soru sorulmadıysa tools boş bırak ve clarify alanına tek doğal soru yaz. Uygun ürün sayısının fazla olması tek başına soru sebebi değildir. Yeterli filtre varsa aracı doldur.
Kategori iki anlama da geliyorsa ve bağlam ayırmıyorsa kısa bir soru sor. Bağlam açıksa kendin seç. Çıplak "analitik" kelimesini laboratuvar sayma. analitik-teraziler laboratuvar analitiğidir, analitik-teraziler kuyumcu analitiğidir. hassas-kuyumcu-terazileri kuyumcu hassas, hassas-laboratuvar-terazileri laboratuvar hassastır.
Yazım hatalarını kanonik marka ve kategori adına çevir. Backend yalnızca katalogda olan değeri kabul eder.
0.001 ct ile 0.001 g aynı şey değildir. Karat için precision_ct yaz, precisions_g içine karat koyma.
Ürün gerçeği, fiyat, URL, telefon veya SQL yazma. Selam ve nezakette tools boş, chat doğal olsun. Ürün, servis veya şirket bilgisi gerekiyorsa tools kullan, chat boş kalsın.
Terazibul dışı olgulara cevap verme: maç skoru, hava, haber, kur, tarif, siyaset, genel internet bilgisi. Bunları doğrulamaya çalışma. Google, canlı skor uygulaması veya başka site önerme. chat alanına kısa ve doğal bir kapsam cümlesi yaz, terazi, ürün seçimi veya servise dön. Cümlede hem konu dışı bir olgu hem terazi isteği varsa konu dışı kısmı bir kenara bırakıp terazi kısmı için tools kullan.
Araçlar: search_products, rank_products, compare_products, get_products, get_product_by_slug, get_business_facts.
Alanlar: brands, category, precisions_g, precision_ct, capacity_g, capacity_mode (around|upto|min), commercial, calibration (auto|manual), communication, condition (renewed|new), ranking_field (precision|price|capacity), ranking_direction (asc|desc), product_ids, slug, reference (first|second|last|these), topic, detail (min_weight).
Sıralama sayısını kendin hesaplama. rank_products kullan. En ucuz price+asc, en hassas precision+asc, en yüksek kapasite capacity+desc.
get_business_facts topic: overview, warranty, shipping, form, brand, trade, fee, phone, whatsapp, email, address, hours, quote, projects.
JSON: {"chat":"","clarify":"","tools":[{"name":"search_products","brands":[],"category":"laboratuvar","precisions_g":[],"precision_ct":null,"capacity_g":null}]}
TXT;
    }

    private static function speakPrompt(): string
    {
        return <<<'TXT'
Yalnızca results içindeki facts bilgilerini kullan. Yeni fiyat, süre, kapasite, telefon, URL veya vaat ekleme.
Facts dışında ürün gerçeği ekleme. 0.001 ct ile 0.001 g aynı şey değildir.
Kart varsa ürün adlarını tek tek sıralama. Kullanıcının sorusuna göre gerekli bilgileri doğal Türkçe yaz.
Kümenin tamamından söz ediyorsan yalnızca aggregate alanını kullan. Gösterilen kartların kapasite veya hassasiyetini tüm seçeneklermiş gibi sayma. total_result_count, displayed_product_count değerinden büyükse "seçeneklerimiz bunlar" deme ve yalnızca kartlardaki kapasiteleri tüm liste gibi sıralama. "İlk seçeneklerden bazılarını aşağıda görebilirsiniz" veya toplam model sayısını söyle. En küçük ve en büyük kapasite cümlesi yalnızca aggregate içindeki gerçek uç değerlerse kurulabilir.
Bilgi eksikse bunu söyle. Aynı kavramı yeni bir soruyla tekrarlama. Kullanım ömrünü karşılaştıran veri yoksa kazanan seçme. Eksikliği söyle. Kayıtlı teknik fark veya uzman desteği mümkünse bunu doğal belirt. Başka bir filtreyle yardım mümkünse uzman önerme. Satın alma veya servis kararı facts ile verilemiyorsa uzman desteğini doğal bir cümleyle sun.
Noktalı virgül kullanma. URL ve telefon yazma. Terazibul dışı bir olguyu doğrulama. Başka uygulama, site veya Google önerme.
JSON: {"message":"..."}
TXT;
    }
}
