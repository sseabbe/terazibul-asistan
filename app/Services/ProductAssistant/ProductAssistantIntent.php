<?php

declare(strict_types=1);

namespace App\Services\ProductAssistant;

/**
 * Kullanıcı cümlesinden filtre çıkarır. Ürün uydurmaz; yalnızca niyet üretir.
 */
final class ProductAssistantIntent
{
    /**
     * @param list<array<string, mixed>> $products
     * @return array<string, mixed>
     */
    public static function parse(string $message, array $products): array
    {
        $folded = ProductAssistantCatalog::fold($message);
        $intent = [
            'mode'             => 'recommend',
            'category'         => null,
            'brand_slugs'      => [],
            'capacity_target'  => null,
            'capacity_mode'    => null,
            'precision'        => null,
            'precisions'       => [],
            'commercial'       => null,
            'calibration'      => null,
            'communication'    => [],
            'condition'        => null,
            'keywords'         => [],
            'use_previous'     => false,
            'reference'        => null,
            'ranking_field'    => null,
            'ranking_direction'=> null,
        ];

        if (self::isInjection($folded)) {
            $intent['mode'] = 'refusal';

            return $intent;
        }

        if (preg_match('/^(merhaba|selam|hey|iyi gunler|iyi aksamlar)[\s!.]*$/u', $folded)) {
            $intent['mode'] = 'greeting';

            return $intent;
        }

        $intent['category'] = self::category($folded);
        $intent['brand_slugs'] = self::brands($folded, $products);
        $measures = self::measures($folded);
        $intent['precisions'] = $measures['precisions'];
        $intent['precision'] = $measures['precisions'][0] ?? null;
        $intent['precision_ct'] = $measures['precision_ct'] ?? null;
        $intent['capacity_target'] = $measures['capacity'];
        $intent['capacity_mode'] = $measures['capacity_mode'];
        $intent['commercial'] = self::commercialFlag($folded);
        $intent['calibration'] = self::calibration($folded);
        $intent['communication'] = self::communication($folded);
        $intent['condition'] = self::condition($folded);
        $intent['reference'] = self::reference($folded);
        $intent['use_previous'] = $intent['reference'] !== null;
        $intent['keywords'] = self::keywords($folded, $intent);

        if (preg_match('/minimum tart|min\.?\s*tart|minimum kac|en az kac gram tart/u', $folded)) {
            $intent['mode'] = 'min_weight';

            return $intent;
        }

        $ranking = self::ranking($folded);
        if ($ranking !== null) {
            $intent['mode'] = 'ranking';
            $intent['ranking_field'] = $ranking['field'];
            $intent['ranking_direction'] = $ranking['direction'];

            return $intent;
        }

        if (preg_match('/karsilastir|arasindaki fark|farki ne|fark ne/u', $folded)) {
            $intent['mode'] = 'compare';

            return $intent;
        }

        if (!self::onTopic($folded, $intent)) {
            $intent['mode'] = 'off_topic';
        }

        return $intent;
    }

    private static function isInjection(string $folded): bool
    {
        $needles = [
            'onceki talimat',
            'talimatlari unut',
            'ignore previous',
            'system prompt',
            'sistem prompt',
            'promptunu goster',
            'promptunu yaz',
            'database sifre',
            'veritabani sifre',
            'db password',
            'api key',
            'api anahtar',
            '.env',
            'tum urunleri goster sql',
            'drop table',
        ];

        foreach ($needles as $needle) {
            if (str_contains($folded, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function hasRanking(string $message): bool
    {
        return self::ranking(ProductAssistantCatalog::fold($message)) !== null;
    }

    /**
     * @return array{field:string,direction:string}|null
     */
    private static function ranking(string $folded): ?array
    {
        $patterns = [
            ['field' => 'precision', 'direction' => 'asc', 're' => '/en hassas/u'],
            ['field' => 'price', 'direction' => 'asc', 're' => '/en ucuz|en uygun fiyat/u'],
            ['field' => 'price', 'direction' => 'desc', 're' => '/en pahal/u'],
            ['field' => 'capacity', 'direction' => 'desc', 're' => '/en yuksek kapasite|en buyuk kapasite|en yuksek tartim kapasite/u'],
            ['field' => 'capacity', 'direction' => 'asc', 're' => '/en dusuk kapasite|en kucuk kapasite|en hafif/u'],
        ];
        $found = null;
        $at = -1;
        foreach ($patterns as $row) {
            if (preg_match($row['re'], $folded, $match, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $pos = (int) $match[0][1];
            if ($pos >= $at) {
                $at = $pos;
                $found = ['field' => $row['field'], 'direction' => $row['direction']];
            }
        }

        return $found;
    }

    /**
     * Günlük konuşma. Ürün kısıtı varsa null; arama ve doğrulama akışı bozulmaz.
     */
    public static function smallTalkKind(string $message): ?string
    {
        $folded = ProductAssistantCatalog::fold($message);
        $folded = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $folded));
        $folded = trim((string) preg_replace('/\s+/u', ' ', $folded));
        if ($folded === '' || self::isInjection($folded) || self::hasProductConstraint($folded)) {
            return null;
        }

        if (preg_match('/^(nasilsin|nasilsiniz|naber|nbr|ne haber|nasil gidiyor)$/u', $folded)) {
            return 'how_are_you';
        }
        if (preg_match('/^(selam|merhaba|hey|gunaydin|iyi aksamlar|iyi gunler|iyi geceler)$/u', $folded)) {
            return 'hello';
        }
        if (preg_match('/^(tesekkurler|tesekkur ederim|cok tesekkurler|sag ol|sagol|sag olun)$/u', $folded)) {
            return 'thanks';
        }
        if (preg_match('/^(sen kimsin|sen kim|kimsin)$/u', $folded)) {
            return 'identity';
        }
        if (preg_match('/^(ne yapabiliyorsun|ne yapabilirsin|neler yapabiliyorsun|ne ise yararsin)$/u', $folded)) {
            return 'ability';
        }
        if (preg_match('/yoruldum|yorgunum/u', $folded)) {
            return 'mood';
        }

        return null;
    }

    /**
     * İletişim / kurum sorusu. Ürün kısıtı varsa null; katalog araması bozulmaz.
     */
    public static function companyTopic(string $message): ?string
    {
        $folded = ProductAssistantCatalog::fold($message);
        if ($folded === '' || self::isInjection($folded) || self::hasProductConstraint($folded)) {
            return null;
        }
        if (preg_match('/whatsapp|whats app/u', $folded)) {
            return 'whatsapp';
        }
        if (preg_match('/e-?posta|eposta|\bmail\b|email/u', $folded)) {
            return 'email';
        }
        if (preg_match('/telefon/u', $folded) && !str_contains($folded, 'telefonlu')) {
            return 'phone';
        }
        if (preg_match('/calisma saat|mesai|acik misiniz/u', $folded)) {
            return 'hours';
        }
        if (preg_match('/adresiniz|neredesi|neredesiniz|nerede bulun|konumunuz|ofisiniz/u', $folded)) {
            return 'address';
        }
        if (preg_match('/\biade\b|cayma hak|geri odeme/u', $folded)) {
            return 'returns';
        }
        if (preg_match('/\bkargo\b|teslimat/u', $folded)) {
            return 'shipping';
        }
        if (preg_match('/hakkinizda|hakkimizda|kimsiniz|sirketiniz|firmaniz/u', $folded) && !preg_match('/\bsen kim/u', $folded)) {
            return 'about';
        }
        if (preg_match('/numaraniz|numara nedir|numaraniz ne/u', $folded)) {
            return 'whatsapp';
        }

        return null;
    }

    /**
     * Servis sorusu. Ürün araması (hassasiyet, kapasite, terazi modeli) bunu ezmez.
     */
    public static function serviceKind(string $message, string $priorTopic = ''): ?string
    {
        $folded = ProductAssistantCatalog::fold($message);
        $folded = trim((string) preg_replace('/[^\p{L}\p{N}\s&]+/u', ' ', $folded));
        $folded = trim((string) preg_replace('/\s+/u', ' ', $folded));
        if ($folded === '' || self::isInjection($folded)) {
            return null;
        }

        $productSearch = ($folded !== 'ohaus' && self::measures($folded)['precisions'] !== [])
            || self::measures($folded)['capacity'] !== null
            || preg_match('/\b(fiyat|stok|model no)\b/u', $folded) === 1;
        if ($productSearch && preg_match('/servis|tamir|ariza/u', $folded) !== 1) {
            return null;
        }

        if (preg_match('/servis form|talep form|formunuz|formu var|form var/u', $folded) === 1) {
            return 'form';
        }
        if (preg_match('/ariza tespit.{0,24}ucret|tespit ucret|ucretli mi/u', $folded) === 1) {
            return 'fee';
        }
        if (preg_match('/servis garant|servis.{0,24}kac ay/u', $folded) === 1) {
            return 'warranty';
        }
        if (preg_match('/nasil gonder|nasil gondere|gondereceg|kargo ile|cihaz gonder/u', $folded) === 1) {
            return 'shipping';
        }
        if (preg_match('/takas|2\.?\s*el|yenilenmis|kullanilmis terazi/u', $folded) === 1 && preg_match('/servis|alir|aliyor|sat/u', $folded) === 1) {
            return 'trade';
        }
        if (preg_match('/servis hizmet|teknik servis|tamir|servise|servisiniz|servis veriyor|servisi var/u', $folded) === 1) {
            $named = self::brands($folded, ProductAssistantCatalog::products());
            if ($named !== []) {
                return 'brand';
            }
            if (preg_match('/satmadig|hic |baska marka|listede yok/u', $folded) === 1) {
                return 'unknown_brand';
            }

            return 'overview';
        }
        if ($priorTopic === 'service' && $productSearch === false) {
            if (preg_match('/gonder|kargo|teslim/u', $folded) === 1) {
                return 'shipping';
            }
            if (self::brands($folded, ProductAssistantCatalog::products()) !== [] || preg_match('/marka/u', $folded) === 1) {
                return 'brand';
            }
            if (preg_match('/evet|tamam|devam/u', $folded) === 1) {
                return 'overview';
            }
        }

        return null;
    }

    private static function hasProductConstraint(string $folded): bool
    {
        if (self::reference($folded) !== null) {
            return true;
        }
        if (preg_match('/minimum tart|min\.?\s*tart|karsilastir|arasindaki fark/u', $folded)) {
            return true;
        }
        if (self::category($folded) !== null) {
            return true;
        }
        $measures = self::measures($folded);
        if (($measures['precisions'] ?? []) !== [] || $measures['capacity'] !== null) {
            return true;
        }
        if (self::commercialFlag($folded) !== null || self::calibration($folded) !== null) {
            return true;
        }
        if (self::communication($folded) !== [] || self::condition($folded) !== null) {
            return true;
        }
        if (self::ranking($folded) !== null) {
            return true;
        }
        foreach (['terazi', 'tarti', 'tartim', 'kefe', 'kalibrasyon', 'load cell', 'hassasiyet', 'kapasite'] as $signal) {
            if (str_contains($folded, $signal)) {
                return true;
            }
        }

        return false;
    }

    private static function category(string $folded): ?string
    {
        $map = [
            'kuyumcu'      => ['kuyumcu', 'kuyum', 'mucevher', 'altin'],
            'laboratuvar'  => ['laboratuvar', 'laboratuar'],
            'endustriyel'  => ['endustriyel', 'endustri'],
            'perakende'    => ['perakende', 'market'],
        ];

        foreach ($map as $slug => $words) {
            foreach ($words as $word) {
                if (str_contains($folded, $word)) {
                    return $slug;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<string>
     */
    private static function brands(string $folded, array $products): array
    {
        $hay = str_replace(["'", '’', '`', '´'], '', $folded);
        $candidates = [];
        $seen = [];
        foreach ($products as $product) {
            $slug = (string) ($product['brand_slug'] ?? '');
            $name = ProductAssistantCatalog::fold((string) ($product['brand'] ?? ''));
            if ($slug === '' || $name === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $candidates[] = ['slug' => $slug, 'name' => $name];
        }
        usort($candidates, static fn (array $a, array $b): int => strlen($b['name']) <=> strlen($a['name']));

        $found = [];
        $suffix = '(?:dan|den|tan|ten|nin|nun|in|un|da|de|ta|te|ya|ye|yi|yu|na|ne)?';
        foreach ($candidates as $brand) {
            if ($brand['slug'] === 'and') {
                if (str_contains($hay, 'a&d') || str_contains($hay, 'a & d') || str_contains($folded, 'a&d')) {
                    $found[] = 'and';
                }
                continue;
            }
            $name = preg_quote($brand['name'], '/');
            if (preg_match('/(^|[^a-z0-9])' . $name . $suffix . '([^a-z0-9]|$)/u', $hay) === 1) {
                $found[] = $brand['slug'];
            }
        }

        return $found;
    }

    /**
     * @return array{precisions:list<float>,capacity:?float,capacity_mode:?string}
     */
    private static function measures(string $folded): array
    {
        $precisions = [];
        $capacity = null;
        $capacityMode = null;
        $addPrecision = static function (float $grams) use (&$precisions): void {
            if ($grams <= 0 || $grams >= 1) {
                return;
            }
            foreach ($precisions as $have) {
                if (abs($have - $grams) / $grams < 0.02) {
                    return;
                }
            }
            $precisions[] = $grams;
        };

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(mg|g)?\s*hassasiyet/u', $folded, $hits, PREG_SET_ORDER)) {
            foreach ($hits as $m) {
                $addPrecision(self::toGrams((float) $m[1], ($m[2] ?? '') !== '' ? $m[2] : 'g'));
            }
        }
        if (preg_match_all('/hassasiyet(?:li|i)?\s*(\d+(?:\.\d+)?)\s*(mg|g)?/u', $folded, $hits, PREG_SET_ORDER)) {
            foreach ($hits as $m) {
                $addPrecision(self::toGrams((float) $m[1], ($m[2] ?? '') !== '' ? $m[2] : 'g'));
            }
        }
        $precisionCt = null;
        if (preg_match('/(?<!\d)(0[.,]\d{1,6})\s*ct\b/u', $folded, $ctHit) === 1) {
            $precisionCt = (float) str_replace(',', '.', $ctHit[1]);
        }
        if (preg_match_all('/(?<!\d)(0[.,]\d{1,6})(?!\d)/u', $folded, $bare, PREG_OFFSET_CAPTURE)) {
            foreach ($bare[1] as $token) {
                $start = (int) $token[1];
                $after = substr($folded, $start + strlen((string) $token[0]), 4);
                if (preg_match('/^\s*ct\b/u', $after) === 1) {
                    continue;
                }
                $addPrecision((float) str_replace(',', '.', (string) $token[0]));
            }
        }

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(kg|kilo|g|gr|gram|mg)\b/u', $folded, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $unit = $m[2] === 'kilo' ? 'kg' : ($m[2] === 'gr' || $m[2] === 'gram' ? 'g' : $m[2]);
                $grams = self::toGrams((float) $m[1], $unit);
                if ($grams < 1 && ($unit === 'g' || $unit === 'mg')) {
                    $addPrecision($grams);
                    continue;
                }
                if ($grams >= 1 || $unit === 'kg') {
                    $capacity = $grams;
                }
            }
        }

        if ($capacity !== null) {
            $capacityMode = str_contains($folded, 'kadar') ? 'upto' : 'around';
        }

        return [
            'precisions'    => $precisions,
            'precision_ct'  => $precisionCt,
            'capacity'      => $capacity,
            'capacity_mode' => $capacityMode,
        ];
    }

    private static function commercialFlag(string $folded): ?bool
    {
        $mentions = str_contains($folded, 'ticari onay') || str_contains($folded, 'onaysiz');
        if (!$mentions) {
            return null;
        }
        $negated = str_contains($folded, 'onaysiz')
            || preg_match('/ticari onay\w{0,12}\s+(olmasin|olmayan|degil|istemiyorum|istemem|haric)/u', $folded) === 1
            || preg_match('/(olmasin|olmayan|degil|haric|istemiyorum)\s+.{0,30}ticari onay/u', $folded) === 1;

        return $negated ? false : true;
    }

    private static function calibration(string $folded): ?string
    {
        if (preg_match('/otomatik|ic kalibrasyon|auto ?cal|dahili kalibrasyon/u', $folded)) {
            return 'auto';
        }
        if (preg_match('/manuel|harici kalibrasyon|dis kalibrasyon/u', $folded)) {
            return 'manual';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function communication(string $folded): array
    {
        $map = [
            'rs-232'    => ['rs-232', 'rs232', 'rs 232'],
            'usb'       => ['usb'],
            'wi-fi'     => ['wi-fi', 'wifi', 'wi fi'],
            'bluetooth' => ['bluetooth'],
            'ethernet'  => ['ethernet'],
        ];
        $found = [];
        foreach ($map as $key => $words) {
            foreach ($words as $word) {
                if (str_contains($folded, $word)) {
                    $found[] = $key;
                    break;
                }
            }
        }

        return $found;
    }

    private static function condition(string $folded): ?string
    {
        if (preg_match('/yenilenmis|2\.?\s*el|ikinci el/u', $folded)) {
            return 'renewed';
        }
        if (str_contains($folded, 'sifir') || str_contains($folded, 'yeni urun')) {
            return 'new';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $intent
     * @return list<string>
     */
    private static function keywords(string $folded, array $intent): array
    {
        $stop = [
            'ariyorum', 'ariyor', 'terazi', 'terazisi', 'teraziler', 'terazilerini', 'terazinin', 'hassas',
            'hassasiyet', 'hassasiyetli', 'kapasiteli', 'civarinda', 'yaklasik', 'kadar', 'var',
            'goster', 'gosterin', 'modellerini', 'modelleri', 'icin', 'bir', 'ile', 'olan', 'gram',
            'kilo', 'kuyumcu', 'kuyum', 'laboratuvar', 'endustriyel', 'endustri', 'perakende',
            'ticari', 'onayli', 'onay', 'otomatik', 'manuel', 'karsilastir', 'arasindaki', 'fark',
            'bunlarin', 'bunlardan', 'bunlari', 'bunun', 'bu', 'ne', 'mi', 'mu', 'nasil', 'hakkinda', 'istiyorum', 'lutfen',
            'merhaba', 'model', 'modelin', 'urun', 'urunu', 'urunler', 'urunleri', 'tartim', 'tartimi', 'minimum', 'min',
            'kac', 'nedir', 'degeri', 'soyle', 'misin', 'olcum', 'tartiyor', 'digeri', 'oteki', 'obur',
            'hangisi', 'ikinci', 'ikincisi', 'ucuncu', 'birinci', 'ilki',
        ];
        foreach ($intent['brand_slugs'] as $slug) {
            $stop[] = ProductAssistantCatalog::fold((string) $slug);
        }

        $words = preg_split('/[^a-z0-9]+/u', $folded) ?: [];
        $keywords = [];
        foreach ($words as $word) {
            if (strlen($word) < 3 || in_array($word, $stop, true) || preg_match('/^\d/', $word)) {
                continue;
            }
            $keywords[] = $word;
        }

        return array_values(array_unique($keywords));
    }

    /**
     * @param array<string, mixed> $intent
     */
    private static function onTopic(string $folded, array $intent): bool
    {
        if ($intent['category'] || $intent['brand_slugs'] || $intent['precision'] || $intent['capacity_target']
            || $intent['commercial'] || $intent['calibration'] || $intent['communication'] || $intent['condition']
            || $intent['reference']) {
            return true;
        }

        $signals = ['terazi', 'tarti', 'tartim', 'kefe', 'kalibrasyon', 'load cell', 'olcum'];
        foreach ($signals as $signal) {
            if (str_contains($folded, $signal)) {
                return true;
            }
        }

        return false;
    }

    private static function reference(string $folded): ?string
    {
        if (preg_match('/bunlardan|bunlarin|bunlari|aralarindaki/u', $folded)) {
            return 'set';
        }
        if (preg_match('/digeri|oteki|obur|ikinci/u', $folded)) {
            return 'other';
        }
        if (preg_match('/ucuncu/u', $folded)) {
            return 'index:2';
        }
        if (preg_match('/birinci|ilk urun|ilki/u', $folded)) {
            return 'index:0';
        }
        if (str_contains($folded, 'bu urun') || str_contains($folded, 'bu terazi') || str_contains($folded, 'bu model') || preg_match('/\b(bunu|bunun)\b/u', $folded)) {
            return 'first';
        }

        return null;
    }

    private static function toGrams(float $value, string $unit): float
    {
        return product_filter_to_grams($value, $unit === 'kilo' ? 'kg' : $unit);
    }

    public static function refuses(string $message): bool
    {
        return self::isInjection(ProductAssistantCatalog::fold($message));
    }

    /**
     * @param list<string> $names
     * @param list<array<string, mixed>> $products
     * @return list<string>
     */
    public static function brandSlugs(array $names, array $products): array
    {
        $found = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                continue;
            }
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            foreach (self::brands(ProductAssistantCatalog::fold($name), $products) as $slug) {
                $found[] = $slug;
            }
            $folded = ProductAssistantCatalog::fold($name);
            foreach ($products as $product) {
                $slug = (string) ($product['brand_slug'] ?? '');
                if ($slug !== '' && ProductAssistantCatalog::fold($slug) === $folded) {
                    $found[] = $slug;
                }
            }
        }

        return array_values(array_unique($found));
    }

    public static function categorySlug(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $folded = ProductAssistantCatalog::fold(trim($value));
        if ($folded === '') {
            return null;
        }
        if (in_array($folded, [
            'kuyumcu', 'laboratuvar', 'endustriyel', 'perakende',
            'yari-analitik-teraziler', 'analitik-teraziler',
            'hassas-kuyumcu-terazileri', 'hassas-laboratuvar-terazileri',
        ], true)) {
            return $folded;
        }

        return self::category($folded);
    }
}
