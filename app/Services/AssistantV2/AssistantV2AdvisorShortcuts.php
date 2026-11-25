<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

use App\Support\HataKodlariRepository;

/**
 * LLM'e gitmeden kayıtlardan cevaplanan iki durum:
 *  - Hata kodu: hata kodları sayfasına link, kayıt yoksa servis yönlendirmesi.
 *  - Adı geçen tek modelin fiyatı: ürün sayfası + fiyat/WhatsApp butonu.
 */
final class AssistantV2AdvisorShortcuts
{
    /**
     * @param list<array{role:string,text:string}> $history
     * @return array{kind:string,message:string,actions:list<array<string,mixed>>,ids:list<int>}|null
     */
    public static function handle(string $message, array $history): ?array
    {
        try {
            return self::contact($message)
                ?? self::errorCode($message)
                ?? self::serviceBrand($message)
                ?? self::namedPrice($message, $history)
                ?? self::namedSpec($message)
                ?? self::genericPrice($message);
        } catch (\Throwable $e) {
            logger('assistant_v2.shortcut', ['error' => $e->getMessage()], 'error');

            return null;
        }
    }

    // ---------------------------------------------------------------- Servis markası

    /**
     * "Ohaus servisi var mı", "Mettler tamiri yapıyor musunuz" → servis verilen markalar listesinden net cevap.
     *
     * @return array{kind:string,message:string,actions:list<array<string,mixed>>,ids:list<int>}|null
     */
    private static function serviceBrand(string $message): ?array
    {
        $fold = AssistantV2Catalog::fold($message);
        if (mb_strlen($fold) > 140 || preg_match('/servis|tamir|onarim|bakim|kalibrasyon|ariza/u', $fold) !== 1
            || preg_match('/(var\s*mi|yapiyor\s*mu|veriyor\s*mu|yapar\s*mi|bakiyor\s*mu|ilgileniyor|musunuz|misiniz|olur\s*mu)/u', $fold) !== 1) {
            return null;
        }
        $brands = ['ohaus' => 'Ohaus', 'mettler' => 'Mettler Toledo', 'shimadzu' => 'Shimadzu', 'axis' => 'Axis', 'radwag' => 'Radwag',
            'kern' => 'Kern', 'precisa' => 'Precisa', 'sartorius' => 'Sartorius', 'a&d' => 'A&D', 'and' => 'A&D', 'citizen' => 'Citizen', 'aczet' => 'Aczet'];
        $found = null;
        foreach ($brands as $key => $name) {
            if (preg_match('/(^|[^a-z0-9])' . preg_quote($key, '/') . '([^a-z0-9]|$)/u', $fold) === 1 && !($key === 'and' && preg_match('/\ba\s*&?\s*d\b/u', $fold) !== 1)) {
                $found = $name;
                break;
            }
        }
        $named = AssistantV2Catalog::mentionedBrand($message);
        if ($found === null && $named === null) {
            return null;
        }
        $list = 'Ohaus, Mettler Toledo, Shimadzu, Axis, Radwag, Kern, Precisa, Sartorius, A&D, Citizen ve Aczet';
        if ($found !== null) {
            $text = 'Evet, ' . $found . ' terazilerine teknik servis veriyoruz: arıza tespiti, onarım, bakım ve kalibrasyon. '
                . 'İstanbul içinde cihazı adresinizden alıp teslim ediyoruz, İstanbul dışından kargoyla gönderebilirsiniz. '
                . 'Servisimizde işlem gören parçalar 6 ay garantilidir. Servis talebi oluşturabilir veya bize yazabilirsiniz.';
        } else {
            $text = $named . ' için servis verdiğimiz markalar arasında kayıt görünmüyor. Servis verdiğimiz markalar: ' . $list
                . '. Cihazınızın marka ve modelini WhatsApp\'tan iletirseniz yardımcı olup olamayacağımızı söyleriz.';
        }

        return ['kind' => 'service_brand', 'message' => $text, 'actions' => self::serviceActions($message, $found !== null), 'ids' => []];
    }

    // ---------------------------------------------------------------- Ürün özelliği

    /**
     * "CTL600-K kaça kadar tartıyor", "PA214 ne kadar hassas" → katalog kaydından kapasite / hassasiyet.
     *
     * @return array{kind:string,message:string,actions:list<array<string,mixed>>,ids:list<int>}|null
     */
    private static function namedSpec(string $message): ?array
    {
        $fold = AssistantV2Catalog::fold($message);
        $wantsCap = preg_match('/kapasite|kaca\s*kadar|kac\s*(kilo|kg|gram|gr)|ne\s*kadar\s*(agir|tart)|maksimum|max|capacity/u', $fold) === 1;
        $wantsPrec = preg_match('/hassas|okunabil|ne\s*kadar\s*hassas|kac\s*hane|readab|precision|resolution/u', $fold) === 1;
        if (!$wantsCap && !$wantsPrec) {
            return null;
        }
        $product = self::namedProduct($message);
        if ($product === null) {
            return null;
        }
        $english = self::isEnglish($message);
        $name = trim((string) ($product['name'] ?? ''));
        $cap = trim((string) ($product['capacity_label'] ?? ''));
        $prec = trim((string) ($product['precision_label'] ?? ''));
        $bits = [];
        if ($cap !== '') {
            $bits[] = ($english ? 'capacity ' : 'maksimum kapasite ') . $cap;
        }
        if ($prec !== '') {
            $bits[] = ($english ? 'readability ' : 'hassasiyet (okunabilirlik) ') . $prec;
        }
        if ($bits === []) {
            return null;
        }
        $id = (int) ($product['id'] ?? 0);
        $text = $english
            ? 'According to our listing, ' . $name . ' has ' . implode(' and ', $bits) . '. You can see all specifications on the product page.'
            : $name . ': ' . implode(', ', $bits) . '. Tüm teknik özellikleri ürün sayfasında görebilirsiniz.';
        $actions = [['type' => 'show_products', 'role' => 'primary', 'label' => $english ? 'View Product' : 'Ürünü İncele', 'url' => (string) $product['url']]];
        $wa = AssistantV2Knowledge::liveSupportWhatsAppAction($name, [$id]);
        if ($wa !== null) {
            $wa['role'] = 'secondary';
            $actions[] = $wa;
        }

        return ['kind' => 'named_spec', 'message' => $text, 'actions' => $actions, 'ids' => $id > 0 ? [$id] : []];
    }

    // ---------------------------------------------------------------- Genel fiyat

    /**
     * Ürün adı olmadan "fiyat almak istiyorum" → model sor + WhatsApp/telefon.
     *
     * @return array{kind:string,message:string,actions:list<array<string,mixed>>,ids:list<int>}|null
     */
    private static function genericPrice(string $message): ?array
    {
        $fold = AssistantV2Catalog::fold($message);
        if (mb_strlen($fold) > 80 || !self::isPriceQuestion($fold) || self::namedProduct($message) !== null) {
            return null;
        }
        // Kategori / kullanım bilgisi varsa normal akış ürün aramasını yapsın
        if (AssistantV2Catalog::statedUsage($message) !== null || AssistantV2Catalog::mentionedBrand($message) !== null
            || preg_match('/terazi|kantar|tarti|baskul|nem|analiz/u', $fold) === 1) {
            return null;
        }
        $english = self::isEnglish($message);
        $text = $english
            ? 'Sure. Which product would you like a price for? You can write the model name here, or message us on WhatsApp and we will send the current price right away.'
            : 'Tabii. Hangi ürün için fiyat istersiniz? Model adını buraya yazabilirsiniz; dilerseniz WhatsApp\'tan yazın, güncel fiyatı hemen iletelim.';
        $actions = [];
        $wa = AssistantV2Knowledge::liveSupportWhatsAppAction($message, []);
        if ($wa !== null) {
            $actions[] = ['type' => 'price_request', 'role' => 'primary', 'label' => $english ? 'Get a Price' : 'Hemen Fiyat Al', 'url' => (string) $wa['url']];
        }
        $phone = AssistantV2Knowledge::phoneAction();
        if ($phone !== null) {
            $phone['role'] = 'secondary';
            $phone['label'] = $english ? 'Call Us' : 'Bizi Arayın';
            $actions[] = $phone;
        }

        return ['kind' => 'generic_price', 'message' => $text, 'actions' => $actions, 'ids' => []];
    }

    // ---------------------------------------------------------------- İletişim

    /**
     * "WhatsApp numaranız var mı", "telefonunuz", "adresiniz nerede" → numara metinde + butonlar.
     *
     * @return array{kind:string,message:string,actions:list<array<string,mixed>>,ids:list<int>}|null
     */
    private static function contact(string $message): ?array
    {
        $fold = AssistantV2Catalog::fold($message);
        if (mb_strlen($fold) > 120 || preg_match('/(whatsapp|whatsap|watsap|wp|telefon|numara|iletisim|ulas(abilir|irim|mak)|adres|nerede(siniz)?|calisma saat|kacta (acil|kapan)|phone|contact|address)/u', $fold) !== 1) {
            return null;
        }
        if (preg_match('/hata|error|ariza|fiyat|terazi\s+(ari|lazim|istiyorum)/u', $fold) === 1) {
            return null;
        }
        $english = self::isEnglish($message);
        $c = self::contactInfo();
        $parts = [];
        if (($c['phone_display'] ?? '') !== '') {
            $parts[] = ($english ? 'Phone: ' : 'Telefon: ') . $c['phone_display'];
        }
        if (($c['whatsapp_display'] ?? '') !== '') {
            $parts[] = 'WhatsApp: ' . $c['whatsapp_display'];
        }
        if (($c['address'] ?? '') !== '' && preg_match('/adres|nerede|address/u', $fold) === 1) {
            $parts[] = ($english ? 'Address: ' : 'Adres: ') . $c['address'];
        }
        if ($parts === []) {
            return null;
        }
        $text = ($english ? 'You can reach us here. ' : 'Bize buradan ulaşabilirsiniz. ') . implode(' · ', $parts) . '.';
        $actions = [];
        $wa = AssistantV2Knowledge::whatsappWriteAction($english ? 'Hello' : 'Merhaba', [], $english ? 'Message on WhatsApp' : "WhatsApp'tan Yazın");
        if ($wa !== null) {
            $actions[] = $wa;
        }
        $phone = AssistantV2Knowledge::phoneAction();
        if ($phone !== null) {
            $phone['role'] = 'secondary';
            $phone['label'] = $english ? 'Call Us' : 'Bizi Arayın';
            $actions[] = $phone;
        }

        return ['kind' => 'contact', 'message' => $text, 'actions' => $actions, 'ids' => []];
    }

    /** @return array{phone_display:string,whatsapp_display:string,address:string} */
    private static function contactInfo(): array
    {
        $phone = format_tr_phone_display((string) \App\Models\Setting::get('site_phone', ''));
        $waRaw = (string) \App\Models\Setting::get('whatsapp_number', '');
        $wa = $waRaw !== '' ? format_tr_phone_display($waRaw) : '';

        return [
            'phone_display'    => trim($phone),
            'whatsapp_display' => trim($wa) !== trim($phone) ? trim($wa) : '',
            'address'          => trim((string) \App\Models\Setting::get('site_address', '')),
        ];
    }

    // ---------------------------------------------------------------- Hata kodu

    /**
     * @return array{kind:string,message:string,actions:list<array<string,mixed>>,ids:list<int>}|null
     */
    private static function errorCode(string $message): ?array
    {
        $fold = AssistantV2Catalog::fold($message);
        if ($fold === '') {
            return null;
        }
        // Açık hata kelimesi (hata, error, err, kod, uyarı) şart değil ama yoksa sadece harf içeren kodlara bakılır
        $explicit = preg_match('/hata|error|\berr|\bkod|uyari|ariza/u', $fold) === 1;
        $soft = preg_match('/ekran(da|inda)|yaziyor|veriyor|cikiyor|gosteriyor|beliriyor/u', $fold) === 1;
        if (!$explicit && !$soft) {
            return null;
        }

        $matches = self::matchErrorRecords($fold, $explicit);
        $english = self::isEnglish($message);

        if ($matches === []) {
            // Kod gibi görünen bir ifade var ama kayıtlarımızda yok: servise yönlendir
            if (!$explicit || preg_match('/(?<![a-z0-9])(err|error|er|e|cal|inf)[\s.\-]?\d{1,4}(?![a-z0-9])/u', $fold) !== 1) {
                return null;
            }
            $text = $english
                ? 'We could not find this error code in our records. Diagnosing it without seeing the device would be a guess; please create a service request or write to us and our technical team will get back to you.'
                : 'Bu hata kodunu kayıtlarımızda bulamadım. Cihazı görmeden kesin neden söylemek doğru olmaz; servis talebi oluşturursanız veya bize yazarsanız teknik ekibimiz size dönüş yapar.';

            return [
                'kind'    => 'error_code_unknown',
                'message' => $text,
                'actions' => self::serviceActions($message, true),
                'ids'     => [],
            ];
        }

        $brand = AssistantV2Catalog::mentionedBrand($message);
        if ($brand !== null) {
            $brandFold = AssistantV2Catalog::fold($brand);
            $scoped = array_values(array_filter(
                $matches,
                static fn (array $r): bool => AssistantV2Catalog::fold((string) ($r['brand'] ?? '')) === $brandFold
            ));
            if ($scoped === []) {
                // Kod başka markalarda var ama bu markada kaydımız yok
                return [
                    'kind'    => 'error_code_unknown',
                    'message' => $english
                        ? 'We do not have this code on record for ' . $brand . '. Please create a service request or write to us and our technical team will get back to you.'
                        : $brand . ' için bu hata kodu kayıtlarımızda yok. Servis talebi oluşturursanız veya bize yazarsanız teknik ekibimiz size dönüş yapar.',
                    'actions' => self::serviceActions($message, true),
                    'ids'     => [],
                ];
            }
            $matches = $scoped;
        }

        // Aynı seri için tek kayıt
        $bySeries = [];
        foreach ($matches as $row) {
            $key = (string) ($row['brandSlug'] ?? '') . '/' . (string) ($row['seriesSlug'] ?? '');
            $bySeries[$key] ??= $row;
        }
        $rows = array_values($bySeries);
        $code = trim((string) ($rows[0]['displayMessage'] ?? $rows[0]['code'] ?? ''));

        $actions = [];
        if (count($rows) === 1) {
            $r = $rows[0];
            $short = trim((string) ($r['shortDescription'] ?? ''));
            $text = $english
                ? '«' . $code . '» on ' . $r['brand'] . ' ' . $r['series'] . ': ' . $r['title'] . '. You can find possible causes and solution steps on the error code page. If the problem persists, you can create a service request.'
                : $r['brand'] . ' ' . $r['series'] . ' serisinde «' . $code . '» hatası: ' . $r['title'] . '.'
                    // Kısa açıklama kod ve seri adını tekrarlıyorsa ekleme
                    . ($short !== '' && !str_contains(mb_strtolower($short), mb_strtolower($code)) ? ' ' . rtrim($short, '.') . '.' : '')
                    . ' Olası nedenleri ve çözüm adımlarını hata kodu sayfasında bulabilirsiniz. Sorun devam ederse servis talebi oluşturabilirsiniz.';
            $actions[] = [
                'type'  => 'page_link',
                'role'  => 'primary',
                'label' => $english ? 'Open Error Code Page' : 'Hata Kodu Sayfasını Aç',
                'url'   => HataKodlariRepository::showUrl((string) $r['brandSlug'], (string) $r['seriesSlug'], (string) $r['slug']),
            ];
        } else {
            $lines = [];
            foreach (array_slice($rows, 0, 3) as $r) {
                $lines[] = $r['brand'] . ' ' . $r['series'] . ': ' . $r['title'];
                $actions[] = [
                    'type'  => 'page_link',
                    'role'  => 'secondary',
                    'label' => mb_substr($r['brand'] . ' ' . $r['series'], 0, 60),
                    'url'   => HataKodlariRepository::showUrl((string) $r['brandSlug'], (string) $r['seriesSlug'], (string) $r['slug']),
                ];
            }
            $more = count($rows) > 3 ? (count($rows) - 3) : 0;
            $text = $english
                ? '«' . $code . '» means different things depending on the brand: ' . implode('; ', $lines) . '. Open the page for your device brand for causes and solution steps.'
                : '«' . $code . '» kodu markaya göre farklı anlam taşıyor: ' . implode('; ', $lines) . ($more > 0 ? ' (ve ' . $more . ' seri daha)' : '') . '. Terazinizin markasına uygun sayfada olası nedenleri ve çözüm adımlarını bulabilirsiniz.';
        }

        foreach (self::serviceActions($message, false) as $action) {
            $actions[] = $action;
        }

        return ['kind' => 'error_code', 'message' => $text, 'actions' => $actions, 'ids' => []];
    }

    /**
     * V3 aracı için: metindeki hata koduna uyan kayıtlar (marka verilmişse ona göre daraltılır).
     *
     * @return list<array{brand:string,series:string,code:string,title:string,summary:string,url:string}>
     */
    public static function errorRecords(string $text): array
    {
        $fold = AssistantV2Catalog::fold($text);
        $rows = self::matchErrorRecords($fold, true);
        $brand = AssistantV2Catalog::mentionedBrand($text);
        if ($brand !== null) {
            $bf = AssistantV2Catalog::fold($brand);
            // Marka verildiyse yalnızca o markanın kayıtları (başka markanın sayfasını önerme)
            $rows = array_values(array_filter($rows, static fn (array $r): bool => AssistantV2Catalog::fold((string) ($r['brand'] ?? '')) === $bf));
        }
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $key = ($r['brandSlug'] ?? '') . '/' . ($r['seriesSlug'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'brand'   => (string) ($r['brand'] ?? ''),
                'series'  => (string) ($r['series'] ?? ''),
                'code'    => (string) ($r['displayMessage'] ?? $r['code'] ?? ''),
                'title'   => (string) ($r['title'] ?? ''),
                'summary' => mb_substr(trim((string) ($r['shortDescription'] ?? '')), 0, 240),
                'url'     => HataKodlariRepository::showUrl((string) $r['brandSlug'], (string) $r['seriesSlug'], (string) $r['slug']),
            ];
        }

        return $out;
    }

    /**
     * Mesajda geçen hata kodlarına uyan yayınlanmış kayıtlar; yalnızca en uzun kod eşleşmesi.
     *
     * @return list<array<string,mixed>>
     */
    private static function matchErrorRecords(string $fold, bool $explicit = true): array
    {
        $repo = new HataKodlariRepository();
        $visible = [];
        foreach ($repo->visibleSeries() as $series) {
            $visible[(string) ($series['brandSlug'] ?? '') . '/' . (string) ($series['slug'] ?? '')] = true;
        }

        $best = [];
        $bestLen = 0;
        foreach ($repo->errors() as $error) {
            if (!is_array($error) || !$repo->isPublishable($error)) {
                continue;
            }
            if (!isset($visible[(string) ($error['brandSlug'] ?? '') . '/' . (string) ($error['seriesSlug'] ?? '')])) {
                continue;
            }
            $pattern = self::codePattern((string) ($error['code'] ?? ''));
            if ($pattern === null || preg_match($pattern['regex'], $fold) !== 1) {
                continue;
            }
            // Yalnız rakamdan oluşan kodlar (ör. 202): açık hata kelimesi şart, ardından birim gelmemeli ("202 gram")
            if (!$pattern['letters']) {
                if (!$explicit || preg_match('/(?<![a-z0-9])' . preg_quote($pattern['compact'], '/') . '\s*(g|gr|gram|kg|kilo|mg|ct|adet|tl|lira)\b/u', $fold) === 1) {
                    continue;
                }
            } elseif (!$explicit && $pattern['len'] < 3) {
                continue;
            }
            if ($pattern['len'] > $bestLen) {
                $best = [$error];
                $bestLen = $pattern['len'];
            } elseif ($pattern['len'] === $bestLen) {
                $best[] = $error;
            }
        }

        return $best;
    }

    /**
     * "CAL E2" → boşluk, nokta, tire esnek; kelime sınırlı desen. Çok kısa/genel kodlar atlanır.
     *
     * @return array{regex:string,len:int,compact:string,letters:bool}|null
     */
    private static function codePattern(string $code): ?array
    {
        $code = AssistantV2Catalog::fold($code);
        $compact = preg_replace('/[^a-z0-9]+/u', '', $code) ?? '';
        $hasDigit = preg_match('/\d/', $compact) === 1;
        // "Error", "Err", "Hata" tek başına kod değildir
        if (in_array($compact, ['error', 'err', 'hata', 'fault', 'fail', 'failed', 'warning'], true)) {
            return null;
        }
        if ($compact === '' || strlen($compact) < 2 || (!$hasDigit && strlen($compact) < 4)) {
            return null;
        }
        // Harf/rakam geçişlerinde de ayırıcı serbest: "err2" = "err 2" = "err-2"
        if (preg_match_all('/[a-z]+|\d+/u', $code, $parts) < 1) {
            return null;
        }
        $chunks = array_map(static fn (string $p): string => preg_quote($p, '/'), $parts[0]);

        return [
            'regex'   => '/(?<![a-z0-9])' . implode('[\s.\-_]*', $chunks) . '(?![a-z0-9])/u',
            'len'     => strlen($compact),
            'compact' => $compact,
            'letters' => preg_match('/[a-z]/', $compact) === 1,
        ];
    }

    // ------------------------------------------------------------- Model fiyatı

    /**
     * @param list<array{role:string,text:string}> $history
     * @return array{kind:string,message:string,actions:list<array<string,mixed>>,ids:list<int>}|null
     */
    private static function namedPrice(string $message, array $history): ?array
    {
        $fold = AssistantV2Catalog::fold($message);
        $asksPrice = self::isPriceQuestion($fold);

        $product = self::namedProduct($message);
        $recentUser = [];
        foreach (array_reverse($history) as $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $recentUser[] = (string) ($turn['text'] ?? '');
                if (count($recentUser) >= 2) {
                    break;
                }
            }
        }

        $specQuestion = preg_match('/kapasite|hassas|okunabil|tart(ar|iyor|abil)|ozellik|olcu|boyut|agirlik|kalibrasyon|nasil|hangi|capacity|readab|spec/u', $fold) === 1;
        if ($product !== null && !$asksPrice && !$specQuestion && count(preg_split('/\s+/u', trim($fold)) ?: []) <= 8) {
            // Model az önce sorulan fiyat sorusunun cevabı olarak yazıldı
            foreach ($recentUser as $text) {
                if (self::isPriceQuestion(AssistantV2Catalog::fold($text))) {
                    $asksPrice = true;
                    break;
                }
            }
        }
        if ($product === null && $asksPrice) {
            // "fiyatı ne kadar?" — model bir önceki mesajlarda geçtiyse
            foreach ($recentUser as $text) {
                $product = self::namedProduct($text);
                if ($product !== null) {
                    break;
                }
            }
        }
        if ($product === null || !$asksPrice) {
            return null;
        }

        $english = self::isEnglish($message);
        $name = trim((string) ($product['name'] ?? ''));
        $specs = [];
        if (trim((string) ($product['capacity_label'] ?? '')) !== '') {
            $specs[] = ($english ? 'capacity ' : 'kapasite ') . trim((string) $product['capacity_label']);
        }
        if (trim((string) ($product['precision_label'] ?? '')) !== '') {
            $specs[] = ($english ? 'readability ' : 'hassasiyet ') . trim((string) $product['precision_label']);
        }
        $specLine = $specs !== []
            ? ($english ? ' Our listing shows ' . implode(', ', $specs) . '.' : ' Katalog kaydımızda ' . implode(', ', $specs) . ' yer alıyor.')
            : '';
        $priceLabel = trim((string) ($product['price_label'] ?? ''));
        $id = (int) ($product['id'] ?? 0);
        $question = $name . ($english ? ' price' : ' fiyatı');

        $actions = [];
        if ($priceLabel !== '') {
            $text = $english
                ? 'The current price of ' . $name . ' on our site is ' . $priceLabel . '.' . $specLine . ' You can review the product page or write to us for an order.'
                : $name . ' için sitedeki güncel fiyat ' . $priceLabel . '.' . $specLine . ' Ürün sayfasını inceleyebilir veya sipariş için bize yazabilirsiniz.';
            $actions[] = ['type' => 'show_products', 'role' => 'primary', 'label' => $english ? 'View Product' : 'Ürünü İncele', 'url' => (string) $product['url']];
            $wa = AssistantV2Knowledge::liveSupportWhatsAppAction($question, [$id]);
            if ($wa !== null) {
                $wa['role'] = 'secondary';
                $actions[] = $wa;
            }
        } else {
            $text = $english
                ? 'We share the current price of ' . $name . ' on request.' . $specLine . ' Tap the button below to message us on WhatsApp and we will send you the price.'
                : $name . ' için güncel fiyatı talep üzerine iletiyoruz.' . $specLine . ' Aşağıdaki butondan WhatsApp ile yazarsanız fiyat bilgisini size gönderelim.';
            $wa = AssistantV2Knowledge::liveSupportWhatsAppAction($question, [$id]);
            if ($wa !== null) {
                $actions[] = [
                    'type'  => 'price_request',
                    'role'  => 'primary',
                    'label' => $english ? 'Get a Price' : 'Hemen Fiyat Al',
                    'url'   => (string) $wa['url'],
                ];
            }
            $actions[] = ['type' => 'show_products', 'role' => 'secondary', 'label' => $english ? 'View Product' : 'Ürünü İncele', 'url' => (string) $product['url']];
        }

        $phone = AssistantV2Knowledge::phoneAction();
        if ($phone !== null) {
            $phone['role'] = 'secondary';
            $phone['label'] = $english ? 'Call Us' : 'Bizi Arayın';
            $actions[] = $phone;
        }

        return ['kind' => 'named_price', 'message' => $text, 'actions' => $actions, 'ids' => $id > 0 ? [$id] : []];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function namedProduct(string $text): ?array
    {
        $slugs = AssistantV2Catalog::productSlugsIn($text);
        if (count($slugs) !== 1) {
            $slugs = AssistantV2Catalog::namedProductSlugs($text);
        }
        if (count($slugs) !== 1) {
            // Model no tiresiz/boşluksuz yazılmış olabilir: "CTL600-K" = "CTL600K"
            $compactMsg = preg_replace('/[^a-z0-9]+/u', ' ', AssistantV2Catalog::fold($text)) ?? '';
            $squeezed = str_replace(' ', '', $compactMsg);
            $hits = [];
            foreach (AssistantV2Catalog::products() as $product) {
                $model = preg_replace('/[^a-z0-9]+/u', '', AssistantV2Catalog::fold((string) ($product['model_no'] ?? ''))) ?? '';
                if (strlen($model) >= 5 && preg_match('/[a-z]/', $model) === 1 && preg_match('/\d/', $model) === 1 && str_contains($squeezed, $model)) {
                    $hits[(string) $product['slug']] = true;
                }
            }
            $slugs = count($hits) === 1 ? array_keys($hits) : [];
        }
        if (count($slugs) !== 1) {
            return null;
        }
        $product = AssistantV2Catalog::bySlug()[$slugs[0]] ?? null;

        return is_array($product) ? $product : null;
    }

    private static function isPriceQuestion(string $fold): bool
    {
        return preg_match('/\b(fiyat\w*|ucret\w*|kac\s*(para|lira|tl)|kaca\s*(satiyor|veriyor)|ne\s*kadar(dir|a)?\s*(tutar|eder|ucret|fiyat)|price|pricing|cost|how\s*much|quote)\b/u', $fold) === 1
            && preg_match('/fiyat\s*hesap/u', $fold) !== 1;
    }

    /** Ham (fold edilmemiş) metin üzerinde çalışır: Türkçe karakter varsa İngilizce değildir. */
    private static function isEnglish(string $raw): bool
    {
        $lower = mb_strtolower($raw, 'UTF-8');
        if (preg_match('/[ğüşıöçİ]/u', $lower) === 1) {
            return false;
        }
        $en = preg_match_all('/\b(the|could|would|price|please|hello|hi|what|how|does|is|are|my|scale|balance|you|your|for|with|can|get)\b/u', $lower);
        $tr = preg_match_all('/\b(ve|bir|icin|nedir|hata|terazi|terazim|fiyat|var|mi|mı|ne|nasil|cihaz|cihazim|yaziyor|veriyor)\b/u', $lower);

        return $en >= 2 && $en > $tr;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function serviceActions(string $message, bool $primary): array
    {
        $out = [];
        $form = AssistantV2Knowledge::serviceAction();
        if ($form !== null) {
            $form['role'] = $primary ? 'primary' : 'secondary';
            $out[] = $form;
        }
        $wa = AssistantV2Knowledge::liveSupportWhatsAppAction($message, []);
        if ($wa !== null) {
            $wa['role'] = 'secondary';
            $out[] = $wa;
        }
        $phone = AssistantV2Knowledge::phoneAction();
        if ($phone !== null) {
            $phone['role'] = 'secondary';
            $phone['label'] = 'Bizi Arayın';
            $out[] = $phone;
        }

        return $out;
    }
}
