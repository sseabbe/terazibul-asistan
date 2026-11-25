<?php

declare(strict_types=1);

namespace App\Services\ProductAssistant;

/**
 * Demo asistan akışı: niyet → DB arama → sınırlı context → cevap doğrulama.
 */
final class ProductAssistant
{
    private const MISSING = 'Bu bilgi ürün kayıtlarımızda doğrulanmış olarak bulunmuyor. Teknik ekibimizden teyit edebilirsiniz.';

    /**
     * @param list<int> $previousIds
     * @return array<string, mixed>
     */
    public static function reply(string $message, array $previousIds, string $priorMessage = '', int $offset = 0, string $priorTopic = '', string $pageBrand = '', array $memory = [], array $history = [], string $pageCategory = ''): array
    {
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');
        if (mb_strlen($message) > 500) {
            $message = mb_substr($message, 0, 500);
        }
        $priorMessage = trim(preg_replace('/\s+/u', ' ', $priorMessage) ?? '');
        if (mb_strlen($priorMessage) > 500) {
            $priorMessage = mb_substr($priorMessage, 0, 500);
        }
        $followUp = $priorMessage !== '';
        $combined = $followUp ? trim($priorMessage . ' ' . $message) : $message;

        if ($message !== '' && ProductAssistantLlm::enabled()) {
            $orchestrated = ProductAssistantOrchestrator::respond($message, $previousIds, $priorMessage, $offset, $pageBrand, $memory, $history, $pageCategory);
            if (is_array($orchestrated)) {
                return $orchestrated;
            }
        }

        $productSlugs = self::productUrlSlugs($message);
        if ($productSlugs !== []) {
            return self::replyForProductUrls($message, $productSlugs);
        }

        $serviceKind = ProductAssistantIntent::serviceKind($message, $priorTopic);
        if ($serviceKind !== null) {
            return self::serviceReply($message, $serviceKind);
        }

        $companyTopic = ProductAssistantIntent::companyTopic($message);
        if ($companyTopic !== null) {
            return self::companyReply($message, $companyTopic);
        }

        $smallTalk = ProductAssistantIntent::smallTalkKind($message);
        if ($smallTalk !== null) {
            return self::smallTalkReply($message, $smallTalk);
        }

        if (self::isLinkedFollowUp($message) && $previousIds !== [] && !ProductAssistantIntent::hasRanking($message)) {
            $linked = self::productsForIds(array_slice(array_values($previousIds), 0, 2));
            if (count($linked) === count(array_slice(array_values($previousIds), 0, 2))) {
                return self::replyForLinkedProducts($message, $linked);
            }
        }

        $catalog = ProductAssistantCatalog::products();
        $intent = ProductAssistantIntent::parse($combined, $catalog);
        self::applyRankingMemory($message, $intent, $memory);
        if (($intent['brand_slugs'] ?? []) === [] && $pageBrand !== '') {
            $pageBrand = strtolower(trim($pageBrand));
            foreach ($catalog as $product) {
                if ((string) ($product['brand_slug'] ?? '') === $pageBrand) {
                    $intent['brand_slugs'] = [$pageBrand];
                    break;
                }
            }
        }
        $mode = (string) $intent['mode'];

        if ($mode === 'ranking') {
            return self::rankingReply($message, $intent);
        }

        if ($mode === 'refusal') {
            $payload = self::payload(
                'Bu konuda yardımcı olamam. Terazibul ürünleri ve tartım çözümleri hakkında soru sorabilirsiniz.',
                [],
                0,
                null,
                false,
                null
            );
            self::log($message, $intent, [], [], [], false);

            return $payload;
        }

        if ($mode === 'greeting') {
            return self::smallTalkReply($message, 'hello');
        }

        if ($mode === 'off_topic') {
            $payload = self::payload(
                'Ben Terazibul ürünleri ve tartım çözümleri konusunda yardımcı olabiliyorum.',
                [],
                0,
                null,
                false,
                null
            );
            self::log($message, $intent, [], [], [], false);

            return $payload;
        }

        if (!$followUp && $mode === 'recommend' && ($intent['reference'] ?? null) === null && self::needsClarification($intent)) {
            $payload = self::payload(self::clarificationQuestion($intent), [], 0, null, false, null, null, true);
            self::log($combined, $intent, [], [], [], false);

            return $payload;
        }

        $found = ProductAssistantSearch::find($intent, $previousIds, max(0, $offset));
        $candidates = $found['products'];
        $candidateIds = array_map(static fn (array $p): int => (int) $p['id'], $candidates);

        if (!empty($found['reference_miss'])) {
            $text = $previousIds === []
                ? 'Son gösterilen ürünlere bağlanamadım. Önce bir arama yapın, sonra bu ürün ya da diğeri diye sorabilirsiniz.'
                : 'Gösterilen listede buna karşılık gelen başka bir ürün yok.';
            $payload = self::payload($text, [], 0, null, false, null);
            self::log($message, $intent, [], [], [], false);

            return $payload;
        }

        if ($mode === 'min_weight') {
            return self::minimumWeight($message, $intent, $candidates, $candidateIds);
        }

        if ($candidates === []) {
            $subject = mb_substr($message, 0, 160);
            $payload = self::payload(
                'Bu kriterlere uyan yayınlanmış bir ürün kaydı bulamadım. ' . self::MISSING,
                [],
                0,
                null,
                true,
                null,
                $subject
            );
            self::log($message, $intent, [], [], [], true);

            return $payload;
        }

        $shown = $candidates;
        $sent = self::context($shown);
        $sentIds = array_map(static fn (array $p): int => (int) $p['product_id'], $sent);
        if ($sent !== []) {
            ProductAssistantLlm::complete($message, $sent, $mode === 'compare' ? 'compare' : 'recommend');
        }
        $text = $mode === 'compare'
            ? self::shortCompare($shown)
            : self::deterministic($shown, (int) $found['total'], $intent, $offset);
        $whatsapp = false;

        $cards = [];
        foreach ($shown as $product) {
            $cards[] = self::card($product);
        }

        $payload = self::payload(
            $text,
            $cards,
            (int) $found['total'],
            $found['more_url'],
            $whatsapp,
            null,
            null,
            false,
            'product',
            true,
            null
        );
        $productActions = ProductAssistantActions::forProduct($message);
        if ($productActions !== []) {
            $payload['actions'] = $productActions;
        }
        self::log($message, $intent, $candidateIds, $sentIds, $sentIds, $whatsapp);

        return $payload;
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $memory
     */
    private static function applyRankingMemory(string $message, array &$intent, array $memory): void
    {
        $field = (string) ($memory['ranking_field'] ?? '');
        $direction = (string) ($memory['ranking_direction'] ?? '');
        if (!in_array($field, ['precision', 'price', 'capacity'], true)) {
            return;
        }
        if (!in_array($direction, ['asc', 'desc'], true)) {
            return;
        }

        $isRanking = ($intent['mode'] ?? '') === 'ranking';
        if (!$isRanking && !self::isBrandRankingFollowUp($message, $intent)) {
            return;
        }

        if (!$isRanking) {
            $intent['mode'] = 'ranking';
            $intent['ranking_field'] = $field;
            $intent['ranking_direction'] = $direction;
            if (($intent['category'] ?? null) === null && is_string($memory['category'] ?? null) && $memory['category'] !== '') {
                $intent['category'] = $memory['category'];
            }
            $precisions = $memory['precisions'] ?? [];
            if (($intent['precisions'] ?? []) === [] && is_array($precisions) && $precisions !== []) {
                $intent['precisions'] = array_values(array_map(static fn ($value): float => (float) $value, $precisions));
                $intent['precision'] = $intent['precisions'][0] ?? null;
            }
        } elseif (($intent['category'] ?? null) === null && is_string($memory['category'] ?? null) && $memory['category'] !== '') {
            $intent['category'] = $memory['category'];
        }

        if (($intent['brand_slugs'] ?? []) === [] && is_array($memory['brand_slugs'] ?? null) && $memory['brand_slugs'] !== []) {
            $intent['brand_slugs'] = array_values($memory['brand_slugs']);
        }
    }

    /**
     * @param array<string, mixed> $intent
     */
    private static function isBrandRankingFollowUp(string $message, array $intent): bool
    {
        if (($intent['mode'] ?? '') !== 'recommend') {
            return false;
        }
        if (($intent['brand_slugs'] ?? []) === []) {
            return false;
        }
        if (($intent['precisions'] ?? []) !== [] || ($intent['capacity_target'] ?? null) !== null) {
            return false;
        }
        if (($intent['commercial'] ?? null) !== null || ($intent['calibration'] ?? null) !== null) {
            return false;
        }
        if (($intent['communication'] ?? []) !== [] || ($intent['condition'] ?? null) !== null) {
            return false;
        }
        if (($intent['category'] ?? null) !== null) {
            return false;
        }
        $folded = ProductAssistantCatalog::fold($message);
        if (preg_match('/goster|ariyorum|listele|oner|istiyorum|var mi/u', $folded) === 1) {
            return false;
        }

        return mb_strlen($folded) <= 48;
    }

    /**
     * Sıralama filtresi skor kapısına takılmaz. Sayısal kazanan burada seçilir.
     *
     * @param array<string, mixed> $intent
     * @return array{reason:?string,products:list<array<string,mixed>>,tie_count:int,brand_name:?string,ct_skipped:int}
     */
    public static function collectRanking(array $intent): array
    {
        $field = (string) ($intent['ranking_field'] ?? '');
        $direction = (string) ($intent['ranking_direction'] ?? 'asc');
        $catalog = ProductAssistantCatalog::products();
        $pool = [];
        foreach ($catalog as $product) {
            if (self::passesRankingFilter($product, $intent)) {
                $pool[] = $product;
            }
        }

        $brandName = self::rankingBrandName($intent, $pool !== [] ? $pool : $catalog);
        if ($pool === []) {
            return [
                'reason'     => 'no_products',
                'products'   => [],
                'tie_count'  => 0,
                'brand_name' => $brandName,
                'ct_skipped' => 0,
            ];
        }

        $eligible = [];
        $ctSkipped = 0;
        foreach ($pool as $product) {
            if ($field === 'precision' && ($product['precision_unit'] ?? null) === 'ct') {
                $ctSkipped++;
            }
            $value = self::rankingMetric($product, $field);
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
            if (!self::sameRankingValue((float) $product['_rank'], $best)) {
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
     * @param array<string, mixed> $intent
     */
    private static function passesRankingFilter(array $product, array $intent): bool
    {
        $category = $intent['category'] ?? null;
        if (is_string($category) && $category !== '') {
            $ok = false;
            foreach ($product['categories'] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (($row['slug'] ?? '') === $category || ($row['parent_slug'] ?? '') === $category) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }

        $brands = $intent['brand_slugs'] ?? [];
        if (is_array($brands) && $brands !== [] && !in_array((string) ($product['brand_slug'] ?? ''), $brands, true)) {
            return false;
        }

        $targets = $intent['precisions'] ?? [];
        if ($targets === [] && ($intent['precision'] ?? null) !== null) {
            $targets = [(float) $intent['precision']];
        }
        if (is_array($targets) && $targets !== []) {
            $grams = $product['precision_grams'] ?? null;
            if (is_string($grams) && is_numeric($grams)) {
                $grams = (float) $grams;
            }
            if (!is_float($grams) && !is_int($grams)) {
                return false;
            }
            $matched = false;
            foreach ($targets as $target) {
                $target = (float) $target;
                if ($target > 0 && abs((float) $grams - $target) / $target <= 0.2) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        if (($intent['capacity_target'] ?? null) !== null) {
            if (($product['capacity_min'] ?? null) === null || ($product['capacity_max'] ?? null) === null) {
                return false;
            }
            $target = (float) $intent['capacity_target'];
            $mode = (string) ($intent['capacity_mode'] ?? 'around');
            $min = $mode === 'upto' ? $target * 0.45 : $target * 0.65;
            $max = $mode === 'upto' ? $target * 1.25 : $target * 1.55;
            $pmin = (float) $product['capacity_min'];
            $pmax = (float) $product['capacity_max'];
            if ($pmax < $min || $pmin > $max) {
                return false;
            }
        }

        if (($intent['commercial'] ?? null) === true && ($product['commercial'] ?? null) !== true) {
            return false;
        }
        if (($intent['commercial'] ?? null) === false && ($product['commercial'] ?? null) === true) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function rankingMetric(array $product, string $field): ?float
    {
        $number = static function (mixed $value): ?float {
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }
            if (is_string($value) && is_numeric($value)) {
                return (float) $value;
            }

            return null;
        };

        if ($field === 'precision') {
            if (($product['precision_unit'] ?? null) === 'ct') {
                return null;
            }
            $grams = $number($product['precision_grams'] ?? null);

            return $grams !== null && $grams > 0 ? $grams : null;
        }
        if ($field === 'price') {
            $price = $number($product['price_try'] ?? null);

            return $price !== null && $price > 0 ? $price : null;
        }
        if ($field === 'capacity') {
            $max = $number($product['capacity_max'] ?? null);

            return $max !== null && $max > 0 ? $max : null;
        }

        return null;
    }

    private static function sameRankingValue(float $a, float $b): bool
    {
        $scale = max(abs($a), abs($b), 1e-12);

        return abs($a - $b) <= $scale * 1e-6;
    }

    /**
     * @param array<string, mixed> $intent
     * @param list<array<string, mixed>> $products
     */
    private static function rankingBrandName(array $intent, array $products): ?string
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

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private static function rankingReply(string $message, array $intent): array
    {
        $result = self::collectRanking($intent);
        $memory = self::rankingMemory($intent);
        $field = (string) ($intent['ranking_field'] ?? '');
        $direction = (string) ($intent['ranking_direction'] ?? 'asc');
        $brandName = is_string($result['brand_name'] ?? null) ? $result['brand_name'] : null;
        $reason = array_key_exists('reason', $result) ? $result['reason'] : 'no_products';
        $ctSkipped = (int) ($result['ct_skipped'] ?? 0);

        if ($reason !== null) {
            $text = self::rankingMiss($reason, $brandName, $direction, $ctSkipped);
            $whatsapp = $reason === 'no_price';
            $payload = self::payload($text, [], 0, null, $whatsapp, null, $whatsapp ? 'fiyat bilgisi' : null, false, 'ranking', true, $memory);
            if ($whatsapp) {
                $wa = ProductAssistantActions::whatsapp('product', 'fiyat bilgisi');
                $payload['actions'] = $wa !== null ? [$wa] : [];
            }
            self::log($message, $intent, [], [], [], $whatsapp);

            return $payload;
        }

        $products = $result['products'];
        $tieCount = (int) $result['tie_count'];
        $cards = [];
        foreach ($products as $product) {
            $cards[] = self::card($product);
        }
        $text = self::rankingSentence($intent, $brandName, $products, $tieCount, $ctSkipped);
        $payload = self::payload($text, $cards, count($cards), null, false, null, null, false, 'ranking', true, $memory);
        $ids = array_map(static fn (array $card): int => (int) $card['id'], $cards);
        self::log($message, $intent, $ids, $ids, $ids, false);

        return $payload;
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    public static function rankingMemory(array $intent): array
    {
        $precisions = [];
        foreach ($intent['precisions'] ?? [] as $value) {
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $grams = (float) $value;
                if ($grams > 0 && $grams < 1) {
                    $precisions[] = $grams;
                }
            }
        }

        return [
            'brand_slugs'        => array_values(is_array($intent['brand_slugs'] ?? null) ? $intent['brand_slugs'] : []),
            'category'           => is_string($intent['category'] ?? null) ? $intent['category'] : null,
            'category_slugs'     => array_values(is_array($intent['category_slugs'] ?? null) ? $intent['category_slugs'] : []),
            'precisions'         => $precisions,
            'ranking_field'      => (string) ($intent['ranking_field'] ?? ''),
            'ranking_direction'  => (string) ($intent['ranking_direction'] ?? ''),
            'communication'      => array_values(is_array($intent['communication'] ?? null) ? $intent['communication'] : []),
            'commercial'         => is_bool($intent['commercial'] ?? null) ? $intent['commercial'] : null,
            'calibration'        => in_array($intent['calibration'] ?? null, ['auto', 'manual'], true) ? $intent['calibration'] : null,
            'scope_ids'          => self::scopeIds($intent['scope_ids'] ?? []),
        ];
    }

    /**
     * @return list<int>
     */
    private static function scopeIds(mixed $values): array
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

    public static function rankingMiss(string $reason, ?string $brandName, string $direction, int $ctSkipped): string
    {
        if ($reason === 'no_products') {
            return $brandName !== null
                ? 'Bu markaya ait yayınlanmış bir ürün bulamadım.'
                : 'Bu kritere uyan yayınlanmış bir ürün bulamadım.';
        }
        if ($reason === 'no_price') {
            $which = $direction === 'desc' ? 'en pahalı' : 'en ucuz';
            $scope = $brandName !== null ? $brandName . ' terazilerimizde' : 'Terazilerimizde';

            return $scope . ' fiyat bilgisi olmadığı için ' . $which . ' modeli belirleyemiyoruz.';
        }
        if ($reason === 'no_precision') {
            $scope = $brandName !== null ? 'Bu markadaki ürünlerde' : 'Bu ürünlerde';
            if ($ctSkipped > 0) {
                return $scope . ' gram cinsinden karşılaştırabileceğim doğrulanmış hassasiyet bilgisi bulunmuyor. Karat (ct) değerlerini gram ile aynı ölçekte karşılaştırmıyorum.';
            }

            return $scope . ' karşılaştırabileceğim doğrulanmış hassasiyet bilgisi bulunmuyor.';
        }

        $scope = $brandName !== null ? 'Bu markadaki ürünlerde' : 'Bu ürünlerde';

        return $scope . ' karşılaştırabileceğim doğrulanmış kapasite bilgisi bulunmuyor.';
    }

    /**
     * @param array<string, mixed> $intent
     * @param list<array<string, mixed>> $products
     */
    private static function rankingSentence(array $intent, ?string $brandName, array $products, int $tieCount, int $ctSkipped): string
    {
        $field = (string) ($intent['ranking_field'] ?? '');
        $lead = self::rankingLead($intent, $brandName);
        $value = self::rankingValueLabel($field, $products);
        if ($field === 'precision') {
            $head = $lead . ' kayıtlarımızdaki en yüksek hassasiyet ' . $value . '.';
            $tail = $tieCount === 1
                ? 'Bu hassasiyete sahip şu model var:'
                : 'Bu hassasiyeti paylaşan ' . $tieCount . ' model var:';
        } elseif ($field === 'price') {
            $which = ($intent['ranking_direction'] ?? '') === 'desc' ? 'en yüksek' : 'en düşük';
            $head = $lead . ' kayıtlarımızdaki ' . $which . ' yayınlanmış fiyat ' . $value . '.';
            $tail = $tieCount === 1
                ? 'Bu fiyattaki model:'
                : 'Bu fiyatı paylaşan ' . $tieCount . ' model var:';
        } else {
            $which = ($intent['ranking_direction'] ?? '') === 'asc' ? 'en düşük' : 'en yüksek';
            $head = $lead . ' kayıtlarımızdaki ' . $which . ' tartım kapasitesi ' . $value . '.';
            $tail = $tieCount === 1
                ? 'Bu kapasiteye sahip şu model var:'
                : 'Bu kapasiteyi paylaşan ' . $tieCount . ' model var:';
        }
        if (count($products) < $tieCount) {
            $tail .= ' İlk ' . count($products) . ' modeli gösteriyorum.';
        }
        $text = $head . ' ' . $tail;
        if ($field === 'precision' && self::rankingUsedGramEquivalent($products)) {
            $text .= ' Karat (ct) yazan kayıtlarda karşılaştırmayı parantezdeki gram değerine göre yaptım. 0,001 ct ile 0,001 g aynı değildir.';
        }
        if ($field === 'precision' && $ctSkipped > 0) {
            $text .= ' Yalnızca karat cinsinden kayıtlı modelleri gram hassasiyetiyle aynı ölçekte karşılaştırmadım.';
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $intent
     */
    private static function rankingLead(array $intent, ?string $brandName): string
    {
        $field = (string) ($intent['ranking_field'] ?? '');
        $precision = self::precisionPhrase($intent);
        if ($precision !== '' && $field !== 'precision') {
            if ($brandName !== null) {
                return $precision . ' hassasiyetindeki ' . $brandName . ' ürünleri arasında';
            }

            return $precision . ' hassasiyetindeki ürünler arasında';
        }
        if ($brandName !== null) {
            return $brandName . ' ürünleri arasında';
        }
        $category = self::categoryLead(is_string($intent['category'] ?? null) ? $intent['category'] : null);
        if ($category !== null) {
            return $category;
        }

        return 'Katalogdaki teraziler arasında';
    }

    private static function categoryLead(?string $slug): ?string
    {
        $names = [
            'kuyumcu'     => 'Kuyumcu terazileri arasında',
            'laboratuvar' => 'Laboratuvar terazileri arasında',
            'endustriyel' => 'Endüstriyel teraziler arasında',
            'perakende'   => 'Perakende terazileri arasında',
        ];

        return $names[$slug ?? ''] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function rankingValueLabel(string $field, array $products): string
    {
        if ($field === 'precision') {
            $shared = self::sharedLabel($products, 'precision_label');
            if ($shared !== null) {
                return $shared;
            }
            $grams = $products[0]['precision_grams'] ?? null;
            if (is_float($grams) || is_int($grams)) {
                $text = rtrim(rtrim(number_format((float) $grams, 6, ',', ''), '0'), ',');

                return ($text === '' ? '0' : $text) . ' g';
            }

            return 'kayıtlı değer';
        }
        if ($field === 'price') {
            $amount = $products[0]['price_try'] ?? null;
            if (is_float($amount) || is_int($amount)) {
                $decimals = abs((float) $amount - round((float) $amount)) < 0.001 ? 0 : 2;

                return number_format((float) $amount, $decimals, ',', '.') . '₺';
            }

            return 'kayıtlı fiyat';
        }

        $shared = self::sharedLabel($products, 'capacity_label');
        if ($shared !== null) {
            return $shared;
        }
        $max = $products[0]['capacity_max'] ?? null;
        if (is_float($max) || is_int($max)) {
            $grams = (float) $max;
            if ($grams >= 1000 && abs($grams - round($grams / 1000) * 1000) < 1) {
                return rtrim(rtrim(number_format($grams / 1000, 3, ',', ''), '0'), ',') . ' kg';
            }
            $text = rtrim(rtrim(number_format($grams, 3, ',', ''), '0'), ',');

            return ($text === '' ? '0' : $text) . ' g';
        }

        return 'kayıtlı kapasite';
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function sharedLabel(array $products, string $key): ?string
    {
        $label = null;
        foreach ($products as $product) {
            $value = trim((string) ($product[$key] ?? ''));
            if ($value === '') {
                return null;
            }
            if ($label === null) {
                $label = $value;
                continue;
            }
            if ($label !== $value) {
                return null;
            }
        }

        return $label;
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function rankingUsedGramEquivalent(array $products): bool
    {
        foreach ($products as $product) {
            $label = ProductAssistantCatalog::fold((string) ($product['precision_label'] ?? ''));
            if ($label !== '' && (str_contains($label, 'ct') || str_contains($label, 'karat')) && ($product['precision_unit'] ?? 'g') !== 'ct') {
                return true;
            }
        }

        return false;
    }

    /**
     * Kuyumcu ve laboratuvarda hassasiyet ile kapasite yoksa tek soru yeter.
     * Marka, ticari onay veya ölçü varsa doğrudan aranır.
     *
     * @param array<string, mixed> $intent
     */
    private static function needsClarification(array $intent): bool
    {
        $category = $intent['category'] ?? null;
        if (!in_array($category, ['kuyumcu', 'laboratuvar'], true)) {
            return false;
        }
        if ($intent['precision'] !== null || $intent['capacity_target'] !== null) {
            return false;
        }
        if (($intent['brand_slugs'] ?? []) !== [] || $intent['commercial'] !== null) {
            return false;
        }
        if (($intent['calibration'] ?? null) !== null || ($intent['communication'] ?? []) !== [] || ($intent['condition'] ?? null) !== null) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $intent
     */
    private static function clarificationQuestion(array $intent): string
    {
        $category = (string) ($intent['category'] ?? '');
        $precisions = [];
        $capacities = [];
        foreach (ProductAssistantCatalog::products() as $product) {
            $matches = false;
            foreach ($product['categories'] as $row) {
                if (($row['slug'] ?? '') === $category || ($row['parent_slug'] ?? '') === $category) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                continue;
            }
            if ($product['precision_grams'] !== null) {
                $precisions[(string) $product['precision_grams']] = true;
            }
            if (is_string($product['capacity_label']) && $product['capacity_label'] !== '') {
                $capacities[$product['capacity_label']] = true;
            }
        }

        if (count($capacities) > count($precisions) && count($capacities) > 1) {
            return 'Yaklaşık kaç gram veya kilogram tartmanız gerekiyor?';
        }

        $question = 'Hangi okunabilirlik/hassasiyet gerekiyor? Gram olarak biliyorsanız yazabilirsiniz. Bilmiyorsanız ne tartacağınızı ve ne kadar hassas ölçmeniz gerektiğini söyleyin.';
        $examples = [];
        foreach (array_keys($precisions) as $grams) {
            if (!is_numeric($grams)) {
                continue;
            }
            $label = rtrim(rtrim(number_format((float) $grams, 4, ',', ''), '0'), ',');
            if ($label !== '') {
                $examples[$label . ' g'] = true;
            }
            if (count($examples) >= 4) {
                break;
            }
        }
        if ($examples !== []) {
            $question .= ' Katalogda örneğin ' . implode(', ', array_keys($examples)) . ' değerleri var.';
        }

        return $question;
    }

    /**
     * @param array<string, mixed> $intent
     * @param list<array<string, mixed>> $candidates
     * @param list<int> $candidateIds
     * @return array<string, mixed>
     */
    private static function minimumWeight(string $message, array $intent, array $candidates, array $candidateIds): array
    {
        $product = $candidates[0] ?? null;
        if ($product === null) {
            $named = ($intent['brand_slugs'] ?? []) !== [] || ($intent['keywords'] ?? []) !== [];
            $text = $named
                ? 'Bu modele ait yayınlanmış bir ürün kaydı bulamadım. ' . self::MISSING
                : 'Hangi modelin minimum tartımını sorduğunuzu yazın. Kayıtlarda modeli bulamazsam değeri tahmin etmem.';
            $payload = self::payload($text, [], 0, null, $named, null, $named ? mb_substr($message, 0, 160) : null);
            self::log($message, $intent, [], [], [], $named);

            return $payload;
        }

        $value = self::minimumSpec($product);
        $card = self::card($product);
        if ($value === null) {
            $payload = self::payload(
                $product['name'] . ' — ' . self::MISSING,
                [$card],
                1,
                null,
                true,
                null,
                (string) $product['name']
            );
            self::log($message, $intent, $candidateIds, $candidateIds, [(int) $product['id']], true);

            return $payload;
        }

        $payload = self::payload(
            $product['name'] . ' kaydındaki minimum tartım değeri: ' . $value . '.',
            [$card],
            1,
            null,
            false,
            null
        );
        self::log($message, $intent, $candidateIds, $candidateIds, [(int) $product['id']], false);

        return $payload;
    }

    /**
     * @param array<string, mixed> $product
     */
    public static function minimumSpec(array $product): ?string
    {
        foreach ($product['specs'] as $spec) {
            $label = ProductAssistantCatalog::fold((string) $spec['label']);
            if (str_contains($label, 'tavsiye') || str_contains($label, 'numune') || str_contains($label, 'kapasite')) {
                continue;
            }
            if (str_contains($label, 'minimum tart') || str_contains($label, 'min tart') || str_contains($label, 'minimum yuk') || $label === 'min. tartim') {
                $value = trim((string) $spec['value']);

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    /**
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed> $intent
     */
    public static function deterministic(array $products, int $total, array $intent, int $offset): string
    {
        if ($offset > 0) {
            return 'Aynı kriterlerle sonraki modeller bunlar.';
        }

        $shown = count($products);
        $brands = [];
        foreach ($products as $product) {
            $name = trim((string) ($product['brand'] ?? ''));
            if ($name !== '' && !in_array($name, $brands, true)) {
                $brands[] = $name;
            }
        }
        $precision = self::precisionPhrase($intent);
        if ($brands !== [] && $precision !== '') {
            $sentence = ProductAssistantBusinessKnowledge::joinTr($brands) . ' markalarında ' . $precision . ' hassasiyetli modeller buldum.';
        } elseif ($precision !== '') {
            $sentence = $precision . ' hassasiyet kriterinize uyan modeller buldum.';
        } elseif ($brands !== []) {
            $sentence = ProductAssistantBusinessKnowledge::joinTr($brands) . ' markasında modeller buldum.';
        } else {
            $sentence = 'Bu özelliklere uyan ' . $total . ' model var.';
        }
        if ($total > $shown) {
            $sentence .= ' İlk ' . $shown . ' modeli aşağıda gösteriyorum.';
        } else {
            $sentence .= ' Aşağıda görebilirsiniz.';
        }

        return $sentence;
    }

    /**
     * @param array<string, mixed> $intent
     */
    private static function precisionPhrase(array $intent): string
    {
        $values = $intent['precisions'] ?? [];
        if ($values === [] && ($intent['precision'] ?? null) !== null) {
            $values = [(float) $intent['precision']];
        }
        if (!is_array($values) || $values === []) {
            return '';
        }
        $labels = [];
        foreach ($values as $grams) {
            $text = rtrim(rtrim(number_format((float) $grams, 6, ',', ''), '0'), ',');
            $labels[] = ($text === '' ? '0' : $text) . ' g';
        }

        return ProductAssistantBusinessKnowledge::joinTr($labels);
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function shortCompare(array $products): string
    {
        if (count($products) < 2) {
            return 'Karşılaştırma için kayıtlı en az iki model gerekiyor.';
        }
        $left = $products[0];
        $right = $products[1];
        $samePrecision = (string) ($left['precision_label'] ?? '') !== ''
            && (string) ($left['precision_label'] ?? '') === (string) ($right['precision_label'] ?? '');
        $parts = [];
        if ($samePrecision) {
            $parts[] = 'İki model de ' . $left['precision_label'] . ' hassasiyet sunuyor.';
        } elseif (($left['precision_label'] ?? '') !== '' || ($right['precision_label'] ?? '') !== '') {
            $parts[] = 'Hassasiyet kaydı: ' . ($left['precision_label'] ?: 'Belirtilmemiş') . ' ve ' . ($right['precision_label'] ?: 'Belirtilmemiş') . '.';
        }
        if (($left['capacity_label'] ?? '') !== '' && ($right['capacity_label'] ?? '') !== '' && $left['capacity_label'] !== $right['capacity_label']) {
            $parts[] = 'Temel fark kapasitede: biri ' . $left['capacity_label'] . ', diğeri ' . $right['capacity_label'] . ' kapasiteye sahip.';
        }

        return $parts !== [] ? implode(' ', $parts) : 'Kartlardaki kayıtlı alanlar dışında doğrulanmış bir fark cümlesi kurmuyorum.';
    }

    /**
     * @return array<string, mixed>
     */
    private static function serviceReply(string $message, string $kind): array
    {
        $pack = ProductAssistantBusinessKnowledge::pack($kind, $message);
        $text = self::phraseFromFacts($message, $pack['facts'], $pack['fallback']);
        $actions = ProductAssistantActions::forService($kind);
        $needsWhatsapp = self::hasAction($actions, 'whatsapp');
        $payload = self::payload(
            $text,
            [],
            0,
            null,
            $needsWhatsapp,
            null,
            $needsWhatsapp ? 'servis' : null,
            false,
            'service'
        );
        $payload['actions'] = $actions;
        self::log($message, self::linkedIntent('service'), [], [], [], $needsWhatsapp);

        return $payload;
    }

    /**
     * @param list<string> $facts
     */
    private static function phraseFromFacts(string $question, array $facts, string $fallback): string
    {
        $written = ProductAssistantLlm::phrase($question, $facts);
        if ($written === null || !self::phraseIsFaithful($written, $facts)) {
            return $fallback;
        }

        return $written;
    }

    /**
     * @param list<string> $facts
     */
    public static function phraseIsFaithful(string $written, array $facts): bool
    {
        $folded = ProductAssistantCatalog::fold($written);
        if ($folded === '' || mb_strlen($written) > 500) {
            return false;
        }
        if (preg_match('/https?:|wa\.me|www\.|tel:/iu', $written) === 1) {
            return false;
        }
        foreach (['sayfamizda', 'kayitlara gore', 'belirtiliyor', 'yaziyor', 'sunmaktadir'] as $banned) {
            if (str_contains($folded, $banned)) {
                return false;
            }
        }
        $allowed = ProductAssistantCatalog::fold(implode(' ', $facts));
        if (preg_match_all('/\d+/u', $written, $numbers) > 0) {
            foreach ($numbers[0] as $number) {
                if (!str_contains($allowed, $number)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param list<array{type:string,role:string,label:string,url:string}> $actions
     */
    private static function hasAction(array $actions, string $type): bool
    {
        foreach ($actions as $action) {
            if (($action['type'] ?? '') === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Model claim'i ancak ürün kaydındaki değerle aynıysa tutulur. Görünen metin kayıttan yazılır.
     *
     * @param list<array{product_id:int,field:string,value:string}> $claims
     * @param list<array<string, mixed>> $products
     * @return list<array{product_id:int,field:string,value:string}>
     */
    private static function verifyClaims(array $claims, array $products): array
    {
        $byId = [];
        foreach ($products as $product) {
            $byId[(int) $product['id']] = $product;
        }

        $verified = [];
        $seen = [];
        foreach ($claims as $claim) {
            $id = (int) $claim['product_id'];
            $field = (string) $claim['field'];
            if (!isset($byId[$id])) {
                continue;
            }
            $canonical = self::canonicalField($byId[$id], $field);
            if ($canonical === null || $canonical === '') {
                continue;
            }
            $given = trim((string) ($claim['value'] ?? ''));
            if ($given !== '' && !self::sameValue($given, $canonical)) {
                continue;
            }
            $key = $id . ':' . $field;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $verified[] = ['product_id' => $id, 'field' => $field, 'value' => $canonical];
        }

        return $verified;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function canonicalField(array $product, string $field): ?string
    {
        $value = match ($field) {
            'capacity'            => $product['capacity_label'] ?? null,
            'precision'           => $product['precision_label'] ?? null,
            'calibration'         => $product['calibration'] ?? null,
            'commercial_approval' => $product['commercial'] === null ? null : self::commercialText($product['commercial']),
            'communication'       => ($product['communication'] ?? []) === [] ? null : implode(', ', $product['communication']),
            'housing'             => ($product['housing'] ?? []) === [] ? null : implode(', ', $product['housing']),
            'accessories'         => ($product['accessories'] ?? []) === [] ? null : implode(', ', $product['accessories']),
            'condition'           => $product['condition'] ?? null,
            'price'               => $product['price_label'] ?? null,
            'brand'               => ($product['brand'] ?? '') !== '' ? $product['brand'] : null,
            'name'                => $product['name'] ?? null,
            default               => null,
        };

        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }

    private static function sameValue(string $given, string $canonical): bool
    {
        $norm = static function (string $value): string {
            $value = str_replace([',', ' '], ['.', ''], ProductAssistantCatalog::fold($value));

            return preg_replace('/[^a-z0-9.]/u', '', $value) ?? '';
        };

        $left = $norm($given);
        $right = $norm($canonical);
        if ($left === '' || $right === '') {
            return false;
        }
        if ($left === $right) {
            return true;
        }

        foreach (preg_split('/\s*[,;\/|]\s*/u', $canonical) ?: [] as $part) {
            if ($norm((string) $part) === $left) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param list<array{product_id:int,field:string,value:string}> $claims
     */
    private static function renderClaims(array $products, array $claims, int $total, string $mode): string
    {
        $grouped = [];
        foreach ($claims as $claim) {
            $grouped[(int) $claim['product_id']][] = $claim;
        }

        $lines = [$mode === 'compare'
            ? 'Karşılaştırma yalnızca doğrulanan kayıt alanlarıyla yapılabilir.'
            : ($total > 4
                ? $total . ' uygun ürün bulundu. Kayıtlara göre ilk eşleşenler:'
                : 'Kayıtlara göre uygun ürünler:')];

        foreach ($products as $product) {
            $bits = [];
            foreach ($grouped[(int) $product['id']] ?? [] as $claim) {
                if ($claim['field'] === 'name') {
                    continue;
                }
                $bits[] = self::fieldLabel((string) $claim['field']) . ' ' . $claim['value'];
            }
            if ($bits === []) {
                continue;
            }
            $lines[] = $product['name'] . ': ' . implode(', ', $bits) . '.';
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    private static function fieldLabel(string $field): string
    {
        return match ($field) {
            'capacity'            => 'kapasite',
            'precision'           => 'hassasiyet',
            'calibration'         => 'kalibrasyon',
            'commercial_approval' => 'ticari onay',
            'communication'       => 'bağlantı',
            'housing'             => 'kasa',
            'accessories'         => 'aksesuar',
            'condition'           => 'durum',
            'price'               => 'fiyat',
            'brand'               => 'marka',
            default               => $field,
        };
    }

    private static function commercialText(mixed $value): string
    {
        if ($value === true) {
            return 'Ticari Onaylı';
        }
        if ($value === false) {
            return 'Ticari onaylı değil';
        }

        return 'Belirtilmemiş';
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    private static function context(array $products): array
    {
        $context = [];
        foreach ($products as $product) {
            $context[] = [
                'product_id'           => (int) $product['id'],
                'name'                 => $product['name'],
                'brand'                => $product['brand'] !== '' ? $product['brand'] : null,
                'model_no'             => $product['model_no'] !== '' ? $product['model_no'] : null,
                'capacity'             => $product['capacity_label'],
                'precision'            => $product['precision_label'],
                'commercial_approval'  => self::commercialText($product['commercial']),
                'calibration'          => $product['calibration'],
                'communication'        => $product['communication'],
                'housing'              => $product['housing'],
                'accessories'          => $product['accessories'],
                'condition'            => $product['condition'],
                'in_stock'             => $product['in_stock'],
                'price'                => $product['price_label'],
                'specs'                => array_slice($product['specs'], 0, 12),
            ];
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public static function card(array $product): array
    {
        $bits = [];
        if (is_string($product['capacity_label']) && $product['capacity_label'] !== '') {
            $bits[] = $product['capacity_label'];
        }
        if (is_string($product['precision_label']) && $product['precision_label'] !== '') {
            $bits[] = $product['precision_label'];
        }

        return [
            'id'    => (int) $product['id'],
            'name'  => (string) $product['name'],
            'image' => (string) $product['image'],
            'meta'  => implode(' / ', $bits),
            'badge' => self::cardBadge($product),
            'url'   => (string) $product['url'],
        ];
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function cardBadge(array $product): string
    {
        $cond = ProductAssistantCatalog::fold((string) ($product['condition'] ?? ''));
        if (str_contains($cond, 'yenilen') || preg_match('/2\s*\.?\s*el|ikinci el/u', $cond) === 1) {
            return (string) __('common.product_badge_renewed');
        }

        return $product['commercial'] === true ? 'Ticari Onaylı' : '';
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @param list<string>|null $prompts
     * @return array<string, mixed>
     */
    public static function payload(
        string $message,
        array $cards,
        int $total,
        ?string $moreUrl,
        bool $needsWhatsapp,
        ?array $prompts,
        ?string $whatsappSubject = null,
        bool $clarify = false,
        ?string $topic = null,
        bool $withMemory = false,
        ?array $memory = null
    ): array {
        $more = null;
        if ($total > count($cards)) {
            $remaining = $total - count($cards);
            $more = [
                'label'  => $moreUrl !== null ? 'Diğer ürünleri görüntüle' : ($remaining . ' ürün daha göster'),
                'url'    => $moreUrl,
                'count'  => $total,
                'offset' => count($cards),
            ];
        }

        $out = [
            'message'         => $message,
            'cards'           => $cards,
            'total'           => $total,
            'more'            => $more,
            'topic'           => $topic,
            'needs_whatsapp'  => $needsWhatsapp,
            'whatsapp'        => $needsWhatsapp ? self::whatsapp($whatsappSubject ?? 'bu ürün') : null,
            'prompts'         => $prompts,
            'product_ids'     => array_map(static fn (array $card): int => (int) $card['id'], $cards),
            'clarify'         => $clarify,
        ];
        if ($withMemory) {
            $out['memory'] = $memory;
        }

        return $out;
    }

    /**
     * @return array{label:string,url:string}|null
     */
    private static function whatsapp(string $subject): ?array
    {
        $number = site_whatsapp_number();
        if ($number === '') {
            return null;
        }

        $subject = trim(preg_replace('/\s+/u', ' ', $subject) ?? '');
        if ($subject === '') {
            $subject = 'bir terazi';
        }
        $text = 'Merhaba, Terazibul Ürün Asistanı üzerinden ' . $subject . ' hakkında bilgi almak istiyorum.';

        return [
            'label' => "WhatsApp'tan Yaz",
            'url'   => 'https://wa.me/' . $number . '?text=' . rawurlencode($text),
        ];
    }

    /**
     * @return list<string>
     */
    public static function productUrlSlugs(string $message): array
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
            $slugs[$slug] = $slug;
        }

        return array_values($slugs);
    }

    private static function stripProductUrls(string $message): string
    {
        $stripped = preg_replace('~(?:https?://)?(?:www\.)?terazibul\.com/urun/[a-z0-9-]+~iu', ' ', $message) ?? $message;

        return trim(preg_replace('/\s+/u', ' ', $stripped) ?? $stripped);
    }

    private static function isLinkedFollowUp(string $message): bool
    {
        $folded = ProductAssistantCatalog::fold($message);
        if (preg_match('/ariyorum|gosterin|onerir/u', $folded) === 1) {
            return false;
        }

        return preg_match('/hangisi|ikisi de|ikisinin|arasindaki fark|farklari nedir|farki nedir|temel fark/u', $folded) === 1;
    }

    /**
     * @param list<string> $slugs
     * @return array<string, mixed>
     */
    private static function replyForProductUrls(string $message, array $slugs): array
    {
        $products = [];
        foreach ($slugs as $slug) {
            $product = self::productBySlug($slug);
            if ($product === null) {
                $payload = self::payload('Bu ürün bağlantısını kayıtlarımızda bulamadım.', [], 0, null, false, null);
                self::log($message, self::linkedIntent('url_miss'), [], [], [], false);

                return $payload;
            }
            $products[] = $product;
        }

        return self::replyForLinkedProducts($message, $products);
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return array<string, mixed>
     */
    private static function replyForLinkedProducts(string $message, array $products): array
    {
        $cards = [];
        $ids = [];
        foreach ($products as $product) {
            $cards[] = self::card($product);
            $ids[] = (int) $product['id'];
        }

        $payload = self::payload(self::linkedText($message, $products), $cards, count($cards), null, false, null);
        self::log($message, self::linkedIntent('url_compare'), $ids, $ids, $ids, false);

        return $payload;
    }

    /**
     * @param list<int|string> $ids
     * @return list<array<string, mixed>>
     */
    public static function productsForIds(array $ids): array
    {
        $map = ProductAssistantCatalog::byId();
        $found = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0 || !isset($map[$id])) {
                return [];
            }
            $found[] = $map[$id];
        }

        return $found;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function productBySlug(string $slug): ?array
    {
        $slug = mb_strtolower(rawurldecode($slug));
        foreach (ProductAssistantCatalog::products() as $product) {
            if (mb_strtolower((string) ($product['slug'] ?? '')) === $slug) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function linkedText(string $message, array $products): string
    {
        $question = self::stripProductUrls($message);

        return match (self::linkedQuestionKind($question)) {
            'commercial'  => self::commercialAnswer($products),
            'calibration' => self::calibrationAnswer($products, $question),
            'capacity'    => self::capacityAnswer($products),
            'precision'   => self::precisionAnswer($products, $question),
            default       => self::fullCompare($products),
        };
    }

    private static function linkedQuestionKind(string $question): string
    {
        $folded = ProductAssistantCatalog::fold($question);
        if (preg_match('/ticari onay/u', $folded) === 1) {
            return 'commercial';
        }
        if (preg_match('/kalibrasyon/u', $folded) === 1) {
            return 'calibration';
        }
        if (preg_match('/kapasite/u', $folded) === 1) {
            return 'capacity';
        }
        if (preg_match('/hassasiyet|ikisi de/u', $folded) === 1 || preg_match('/\d+(?:[.,]\d+)?\s*(mg|g)\b/u', $folded) === 1) {
            return 'precision';
        }

        return 'compare';
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function fullCompare(array $products): string
    {
        $rows = self::linkedFields($products);
        $lines = [
            count($products) === 2
                ? 'Bu iki ürünü yalnızca kayıtlı alanlarla karşılaştırdım.'
                : 'Bu ürünleri yalnızca kayıtlı alanlarla karşılaştırdım.',
            '',
        ];
        foreach ($products as $index => $product) {
            $lines[] = (string) $product['name'];
            foreach ($rows as $row) {
                $lines[] = $row['label'] . ': ' . self::showValue($row['values'][$index] ?? null);
            }
            $lines[] = '';
        }
        $summary = self::differenceSummary($products, $rows);
        $lines[] = 'Özet: ' . ($summary === []
            ? 'Karşılaştırılan alanlarda kayıtlı değerler aynı.'
            : implode(' ', $summary));

        return trim(implode("\n", $lines));
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param list<array{label:string,values:list<?string>}> $rows
     * @return list<string>
     */
    private static function differenceSummary(array $products, array $rows): array
    {
        if (count($products) !== 2) {
            return [];
        }

        $left = $products[0];
        $right = $products[1];
        $notes = [];
        $capacityNoted = false;
        $leftMax = $left['capacity_max'] ?? null;
        $rightMax = $right['capacity_max'] ?? null;
        if (is_numeric($leftMax) && is_numeric($rightMax) && abs((float) $leftMax - (float) $rightMax) > 0.0001) {
            $higher = (float) $leftMax > (float) $rightMax ? $left : $right;
            $lower = $higher === $left ? $right : $left;
            $notes[] = 'Kayıtlı kapasite ' . self::shortName($higher) . ' için ' . $higher['capacity_label']
                . ', ' . self::shortName($lower) . ' için ' . $lower['capacity_label']
                . '. Daha yüksek kapasite ' . self::shortName($higher) . ' kaydında.';
            $capacityNoted = true;
        }

        foreach ($rows as $row) {
            if ($capacityNoted && $row['label'] === 'Kapasite') {
                continue;
            }
            $a = self::showValue($row['values'][0] ?? null);
            $b = self::showValue($row['values'][1] ?? null);
            if (self::sameLoose($a, $b)) {
                continue;
            }
            $notes[] = $row['label'] . ': ' . rtrim($a, '.') . ' / ' . rtrim($b, '.') . '.';
        }

        return $notes;
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function capacityAnswer(array $products): string
    {
        $lines = [];
        foreach ($products as $product) {
            $label = self::textOrNull($product['capacity_label'] ?? null);
            $lines[] = $label === null
                ? $product['name'] . ' için kapasite: Ürün kayıtlarında doğrulanmış bilgi yok.'
                : $product['name'] . ' kaydındaki kapasite: ' . $label . '.';
        }
        if (count($products) === 2) {
            $notes = self::differenceSummary($products, [[
                'label'  => 'Kapasite',
                'values' => [
                    self::textOrNull($products[0]['capacity_label'] ?? null),
                    self::textOrNull($products[1]['capacity_label'] ?? null),
                ],
            ]]);
            if ($notes !== []) {
                $lines[] = implode(' ', $notes);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function precisionAnswer(array $products, string $question): string
    {
        $folded = ProductAssistantCatalog::fold($question);
        $target = null;
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(mg|g)\b/u', $folded, $match) === 1) {
            $target = ($match[2] === 'mg' ? (float) str_replace(',', '.', $match[1]) / 1000 : (float) str_replace(',', '.', $match[1]));
        }

        $lines = [];
        $matches = 0;
        $known = 0;
        foreach ($products as $product) {
            $label = self::textOrNull($product['precision_label'] ?? null);
            $grams = $product['precision_grams'] ?? null;
            if ($label === null && !is_numeric($grams)) {
                $lines[] = $product['name'] . ' için hassasiyet: Ürün kayıtlarında doğrulanmış bilgi yok.';
                continue;
            }
            $known++;
            $shown = $label ?? self::formatQuestionGrams((float) $grams);
            $line = $product['name'] . ' kaydındaki hassasiyet: ' . $shown . '.';
            if ($target !== null && is_numeric($grams)) {
                $same = abs((float) $grams - $target) <= 0.0000005;
                $asked = self::formatQuestionGrams($target);
                $line .= $same
                    ? ' Bu değer sorudaki ' . $asked . ' ile aynı.'
                    : ' Bu değer sorudaki ' . $asked . ' ile aynı değil.';
                if ($same) {
                    $matches++;
                }
            }
            $lines[] = $line;
        }
        if ($target !== null && count($products) === 2 && $known === 2) {
            $lines[] = $matches === 2
                ? 'İkisinin de kayıtlı hassasiyeti sorudaki değerle aynı.'
                : ($matches === 0 ? 'İkisinin kayıtlı hassasiyeti de sorudaki değerle aynı değil.' : '');
        }

        return trim(implode("\n", array_filter($lines, static fn (string $line): bool => $line !== '')));
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function calibrationAnswer(array $products, string $question): string
    {
        $foldedQuestion = ProductAssistantCatalog::fold($question);
        $asksInternal = str_contains($foldedQuestion, 'dahili') || str_contains($foldedQuestion, 'ic kalibrasyon');
        $lines = [];
        $internal = [];
        foreach ($products as $product) {
            $value = self::textOrNull($product['calibration'] ?? null) ?? self::specByNeedles($product, ['kalibrasyon']);
            if ($value === null) {
                $lines[] = $product['name'] . ' için kalibrasyon: Ürün kayıtlarında doğrulanmış bilgi yok.';
                continue;
            }
            $lines[] = $product['name'] . ' kaydındaki kalibrasyon: ' . rtrim($value, '.') . '.';
            $folded = ProductAssistantCatalog::fold($value);
            if (str_contains($folded, 'dahili') || str_contains($folded, 'ic kalibrasyon')) {
                $internal[] = (string) $product['name'];
            }
        }
        if ($asksInternal) {
            $lines[] = $internal === []
                ? 'Kayıtlarda dahili veya iç kalibrasyon ifadesi yok.'
                : 'Dahili veya iç kalibrasyon kaydı bulunan: ' . implode(', ', $internal) . '.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function commercialAnswer(array $products): string
    {
        $lines = [];
        foreach ($products as $product) {
            if ($product['commercial'] === true) {
                $lines[] = $product['name'] . ' kaydında ticari onay: Ticari Onaylı.';
            } elseif ($product['commercial'] === false) {
                $lines[] = $product['name'] . ' kaydında ticari onay: Ticari onaylı değil.';
            } else {
                $lines[] = $product['name'] . ' için ticari onay: Ürün kayıtlarında doğrulanmış bilgi yok.';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array{label:string,values:list<?string>}>
     */
    private static function linkedFields(array $products): array
    {
        $readers = [
            'Marka'        => static fn (array $product): ?string => self::textOrNull($product['brand'] ?? null),
            'Model'        => static fn (array $product): ?string => self::textOrNull($product['model_no'] ?? null),
            'Kapasite'     => static fn (array $product): ?string => self::textOrNull($product['capacity_label'] ?? null),
            'Hassasiyet'   => static fn (array $product): ?string => self::textOrNull($product['precision_label'] ?? null),
            'Ticari onay'  => static fn (array $product): ?string => $product['commercial'] === null ? null : self::commercialText($product['commercial']),
            'Kalibrasyon'  => static fn (array $product): ?string => self::textOrNull($product['calibration'] ?? null) ?? self::specByNeedles($product, ['kalibrasyon']),
            'Kasa'         => static fn (array $product): ?string => self::textOrNull(self::joinList($product['housing'] ?? [])),
            'Ekran'        => static fn (array $product): ?string => self::specByNeedles($product, ['ekran', 'gosterge', 'display']),
            'Bağlantı'     => static fn (array $product): ?string => self::textOrNull(self::joinList($product['communication'] ?? [])),
            'Güç'          => static fn (array $product): ?string => self::specByNeedles($product, ['guc kayn', 'besleme', 'adaptor', 'guc']),
            'Kefe'         => static fn (array $product): ?string => self::specByNeedles($product, ['kefe', 'tartim tabl', 'plaka olc']),
            'Durum'        => static fn (array $product): ?string => self::textOrNull($product['condition'] ?? null),
            'Fiyat'        => static fn (array $product): ?string => self::textOrNull($product['price_label'] ?? null),
        ];

        $rows = [];
        foreach ($readers as $label => $reader) {
            $values = [];
            $any = false;
            foreach ($products as $product) {
                $value = $reader($product);
                $values[] = $value;
                if ($value !== null && $value !== '') {
                    $any = true;
                }
            }
            if ($any) {
                $rows[] = ['label' => $label, 'values' => $values];
            }
        }

        foreach (self::sharedSpecRows($products) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array{label:string,values:list<?string>}>
     */
    private static function sharedSpecRows(array $products): array
    {
        $skip = ['kapasite', 'hassasiyet', 'okunabilirlik', 'kalibrasyon', 'ekran', 'gosterge', 'display', 'baglanti', 'haberlesme', 'iletisim', 'kasa', 'kefe', 'guc', 'besleme', 'adapt', 'fiyat', 'marka', 'model', 'ticari', 'durum', 'minimum'];
        $byLabel = [];
        foreach ($products as $index => $product) {
            foreach ($product['specs'] ?? [] as $spec) {
                $label = trim((string) ($spec['label'] ?? ''));
                $folded = ProductAssistantCatalog::fold($label);
                if ($label === '') {
                    continue;
                }
                $blocked = false;
                foreach ($skip as $needle) {
                    if (str_contains($folded, $needle)) {
                        $blocked = true;
                        break;
                    }
                }
                if ($blocked) {
                    continue;
                }
                $byLabel[$folded]['label'] = $label;
                $byLabel[$folded]['values'][$index] = trim((string) ($spec['value'] ?? ''));
            }
        }

        $rows = [];
        $count = count($products);
        foreach ($byLabel as $item) {
            if (count($item['values'] ?? []) < $count) {
                continue;
            }
            $values = [];
            for ($index = 0; $index < $count; $index++) {
                $value = trim((string) ($item['values'][$index] ?? ''));
                $values[] = $value === '' ? null : $value;
            }
            $rows[] = ['label' => (string) $item['label'], 'values' => $values];
            if (count($rows) >= 4) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $product
     * @param list<string> $needles
     */
    private static function specByNeedles(array $product, array $needles): ?string
    {
        foreach ($product['specs'] ?? [] as $spec) {
            $label = ProductAssistantCatalog::fold((string) ($spec['label'] ?? ''));
            foreach ($needles as $needle) {
                if ($label !== '' && str_contains($label, ProductAssistantCatalog::fold($needle))) {
                    $value = trim((string) ($spec['value'] ?? ''));

                    return $value === '' ? null : $value;
                }
            }
        }

        return null;
    }

    private static function textOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function joinList(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }
        $parts = [];
        foreach ($value as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function shortName(array $product): string
    {
        $brand = self::textOrNull($product['brand'] ?? null);

        return $brand ?? (string) $product['name'];
    }

    private static function showValue(?string $value): string
    {
        return ($value === null || trim($value) === '') ? 'Belirtilmemiş' : $value;
    }

    private static function sameLoose(string $left, string $right): bool
    {
        $norm = static function (string $value): string {
            $value = str_replace([',', ' '], ['.', ''], ProductAssistantCatalog::fold($value));

            return preg_replace('/[^a-z0-9.]/u', '', $value) ?? '';
        };

        return $norm($left) === $norm($right);
    }

    private static function formatQuestionGrams(float $grams): string
    {
        $text = rtrim(rtrim(number_format($grams, 6, '.', ''), '0'), '.');

        return ($text === '' ? '0' : $text) . ' g';
    }

    /**
     * @return array<string, mixed>
     */
    private static function linkedIntent(string $mode): array
    {
        return [
            'mode'            => $mode,
            'category'        => null,
            'brand_slugs'     => [],
            'capacity_target' => null,
            'precision'       => null,
            'commercial'      => null,
            'calibration'     => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function companyReply(string $message, string $topic): array
    {
        $answer = ProductAssistantCompany::answer($topic);
        $folded = ProductAssistantCatalog::fold($message);
        if ($topic === 'phone' && preg_match('/numara/u', $folded) === 1) {
            $display = format_tr_phone_display((string) \App\Models\Setting::get('site_phone', ''));
            if ($display !== '') {
                $answer['message'] = 'Telefon numaramız ' . $display . '.';
            }
        }
        if ($topic === 'whatsapp' && preg_match('/numara/u', $folded) === 1) {
            $display = format_tr_phone_display(site_whatsapp_number());
            if ($display !== '') {
                $answer['message'] = 'WhatsApp numaramız ' . $display . '. Buradan da yazabilirsiniz.';
            }
        } elseif ($topic === 'whatsapp' && str_contains($folded, 'servis')) {
            $answer['message'] = 'Tabii. Servis talebiniz için WhatsApp üzerinden bize ulaşabilirsiniz.';
        }
        $actions = ProductAssistantActions::forCompany($topic);
        $needsWhatsapp = $answer['whatsapp'] || self::hasAction($actions, 'whatsapp');
        $payload = self::payload(
            $answer['message'],
            [],
            0,
            null,
            $needsWhatsapp,
            null,
            $needsWhatsapp ? 'iletişim' : null
        );
        $payload['actions'] = $actions;
        self::log($message, [
            'mode'            => 'company',
            'category'        => null,
            'brand_slugs'     => [],
            'capacity_target' => null,
            'precision'       => null,
            'commercial'      => null,
            'calibration'     => null,
        ], [], [], [], $needsWhatsapp);

        return $payload;
    }

    private static function smallTalkReply(string $message, string $kind): array
    {
        $text = match ($kind) {
            'how_are_you' => 'İyiyiz, teşekkür ederiz. Size nasıl yardımcı olabiliriz?',
            'thanks' => 'Rica ederim 😊 Başka bir konuda yardımcı olabilirim.',
            'identity' => 'Ben Terazibul Ürün Asistanıyım. İhtiyacınıza uygun terazi ve tartım çözümlerini bulmanıza yardımcı olabilirim.',
            'ability' => 'Terazi ve tartım ihtiyacınıza uygun modelleri kayıtlarımızdan bulmanıza yardımcı olabilirim.',
            'mood' => 'Yoğun bir gün olmuş gibi görünüyor 🙂 Terazi veya ürün seçimiyle ilgili bir konuda yardımcı olmamı isterseniz buradayım.',
            'hello' => 'Merhaba 👋 Nasıl yardımcı olabilirim?',
            default => 'Merhaba 👋 Nasıl yardımcı olabilirim?',
        };

        $payload = self::payload($text, [], 0, null, false, null);
        self::log($message, self::linkedIntent('small_talk'), [], [], [], false);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private static function prompts(): array
    {
        return [
            '0.001 g hassasiyetli kuyumcu terazisi arıyorum',
            'Yaklaşık 5 kg kapasiteli 0.01 g terazi var mı?',
            'Ticari onaylı kuyumcu terazilerini göster',
            'Axis ve Ohaus modellerini karşılaştır',
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @param list<int> $candidateIds
     * @param list<int> $sentIds
     * @param list<int> $suggestedIds
     */
    private static function log(
        string $question,
        array $intent,
        array $candidateIds,
        array $sentIds,
        array $suggestedIds,
        bool $whatsapp
    ): void {
        logger('product_assistant', [
            'question'       => mb_substr($question, 0, 400),
            'mode'           => $intent['mode'] ?? '',
            'filters'        => [
                'category'    => $intent['category'] ?? null,
                'brands'      => $intent['brand_slugs'] ?? [],
                'capacity'    => $intent['capacity_target'] ?? null,
                'precision'   => $intent['precision'] ?? null,
                'commercial'  => $intent['commercial'] ?? null,
                'calibration' => $intent['calibration'] ?? null,
            ],
            'candidate_ids'  => $candidateIds,
            'sent_ids'       => $sentIds,
            'suggested_ids'  => $suggestedIds,
            'whatsapp'       => $whatsapp,
        ]);
    }
}
