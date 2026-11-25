<?php

declare(strict_types=1);

namespace App\Services\AssistantV3;

use App\Services\AssistantV2\AssistantV2Catalog;

/**
 * Sohbet içi ürün bulucu. Seçenekler ve sayılar katalogdan hesaplanır; seçime tıklanınca LLM'siz anında cevap verilir.
 *
 * Durum (client'ta tutulur, her istekte geri gelir):
 *   usage      kuyumcu | laboratuvar | endustriyel | test-ve-olcum
 *   sub        alt kategori (laboratuvar tipi, kuyumcuda altın analiz) | any
 *   commercial yes | any
 *   cap        kapasite bandı (c200 … cbig) | any
 *   need       gereken en az kapasite (g), kullanıcı sayı söylediyse
 *   brand      marka adı (katalogdaki yazımıyla)
 *   cond       used (yalnız 2. el / yenilenmiş)
 *   lang       en (İngilizce yazan ziyaretçi)
 */
final class AssistantV3Finder
{
    public const USAGES = ['kuyumcu', 'laboratuvar', 'endustriyel', 'test-ve-olcum'];

    private const START = [
        ['tr' => 'Kuyumcu', 'en' => 'Jewelry', 'state' => ['usage' => 'kuyumcu']],
        ['tr' => 'Laboratuvar', 'en' => 'Laboratory', 'state' => ['usage' => 'laboratuvar']],
        ['tr' => 'Eczane', 'en' => 'Pharmacy', 'state' => ['usage' => 'laboratuvar', 'sub' => 'eczane-terazisi']],
        ['tr' => 'Endüstriyel / depo / kargo', 'en' => 'Industrial / warehouse', 'state' => ['usage' => 'endustriyel']],
        ['tr' => 'Tork ölçümü', 'en' => 'Torque measurement', 'state' => ['usage' => 'test-ve-olcum']],
        ['tr' => '2. el / yenilenmiş', 'en' => 'Used / refurbished', 'state' => ['cond' => 'used']],
    ];

    /** Alt kategoriler → [üst kullanım, tr ad, en ad] */
    public const SUBS = [
        'hassas-laboratuvar-terazileri'      => ['laboratuvar', '0,1 g veya 0,01 g · Genel tartım', '0.1 g or 0.01 g · General weighing'],
        'yari-analitik-teraziler' => ['laboratuvar', '0,001 g · Yarı analitik', '0.001 g · Semi-analytical'],
        'analitik-teraziler'            => ['laboratuvar', '0,0001 g · Analitik', '0.0001 g · Analytical'],
        'yari-mikro-teraziler'  => ['laboratuvar', '0,00001 g · Yarı mikro', '0.00001 g · Semi-micro'],
        'mikro-teraziler'         => ['laboratuvar', '0,000001 g · Mikro', '0.000001 g · Micro'],
        'nem-tayin-cihazlari'               => ['laboratuvar', 'Nem tayini', 'Moisture analysis'],
        'eczane-terazisi'         => ['laboratuvar', 'Eczane', 'Pharmacy'],
        'altin-analiz-terazileri' => ['kuyumcu', 'Altın ayar analizi', 'Gold purity analysis'],
    ];

    private const NOUNS = [
        'kuyumcu'                 => ['kuyumcu terazileri', 'jewelry scales'],
        'laboratuvar'             => ['laboratuvar terazileri', 'laboratory balances'],
        'endustriyel'             => ['endüstriyel teraziler', 'industrial scales'],
        'test-ve-olcum'           => ['tork ölçerler', 'torque meters'],
        'hassas-laboratuvar-terazileri'      => ['hassas laboratuvar terazileri (0,1 g / 0,01 g)', 'precision balances (0.1 g / 0.01 g)'],
        'yari-analitik-teraziler' => ['yarı analitik teraziler (0,001 g)', 'semi-analytical balances (0.001 g)'],
        'analitik-teraziler'            => ['analitik teraziler (0,0001 g)', 'analytical balances (0.0001 g)'],
        'yari-mikro-teraziler'  => ['yarı mikro teraziler (0,00001 g)', 'semi-micro balances (0.00001 g)'],
        'mikro-teraziler'         => ['mikro teraziler (0,000001 g)', 'micro balances (0.000001 g)'],
        'nem-tayin-cihazlari'               => ['nem tayin cihazları', 'moisture analyzers'],
        'eczane-terazisi'         => ['eczane terazileri', 'pharmacy scales'],
        'altin-analiz-terazileri' => ['altın analiz cihazları', 'gold analyzers'],
    ];

    /** Ürün kapasite bantları (çakışmasız): anahtar → [tr, en, alt sınır (g, hariç), üst sınır (g, dahil)] */
    public const CAPS = [
        'c200'  => ['250 g\'a kadar', 'up to 250 g', 0, 250],
        'c600'  => ['300 – 700 g', '300 – 700 g', 250, 700],
        'c1200' => ['0,7 – 1,7 kg', '0.7 – 1.7 kg', 700, 1700],
        'c3200' => ['1,7 – 4,5 kg', '1.7 – 4.5 kg', 1700, 4500],
        'c6200' => ['4,5 – 8 kg', '4.5 – 8 kg', 4500, 8000],
        'cbig'  => ['8 kg üstü', 'over 8 kg', 8000, PHP_INT_MAX],
    ];

    /** Özellikler: anahtar → [tr sıfat, en sıfat, ürün metninde aranacak desen] */
    public const FEATURES = [
        'karat'    => ['karat gösteren', 'carat-reading', '/karat|\bct\b|carat/u'],
        'batarya'  => ['bataryalı', 'battery-powered', '/batarya|li-?ion|\bpil\b|battery/u'],
        'dahili'   => ['dahili kalibrasyonlu', 'internally calibrated', '/otomatik kal|dahili kal|ic kal|autocal|internal cal|scs\b|dahili kalibrasyon/u'],
        'iyonizer' => ['iyonizerli', 'with ionizer', '/iyonizer|ionizer/u'],
        'wifi'     => ['Wi-Fi bağlantılı', 'Wi-Fi', '/wi-?fi/u'],
        'bluetooth'=> ['Bluetooth bağlantılı', 'Bluetooth', '/bluetooth/u'],
        'usb'      => ['USB bağlantılı', 'USB', '/\busb\b/u'],
        'ethernet' => ['Ethernet bağlantılı', 'Ethernet', '/ethernet/u'],
    ];

    /** Hassasiyet (g) → laboratuvar alt tipi */
    private const READ_SUB = [
        '0.1'      => 'hassas-laboratuvar-terazileri',
        '0.01'     => 'hassas-laboratuvar-terazileri',
        '0.001'    => 'yari-analitik-teraziler',
        '0.0001'   => 'analitik-teraziler',
        '0.00001'  => 'yari-mikro-teraziler',
        '0.000001' => 'mikro-teraziler',
    ];

    /** Metinler [tr, en] */
    private const TXT = [
        'ask_usage'    => ['Teraziyi hangi alanda kullanacaksınız? Seçiminize göre uygun modelleri listeleyeyim.', 'What will you use the scale for? I will list the right models for you.'],
        'ask_usage_b'  => ['%s markasında %d ürünümüz var. Hangi alanda kullanacaksınız?', 'We have %d %s products. What will you use it for?'],
        'ask_lab'      => ['%s: %d ürün. Ne kadar hassas tartmanız gerekiyor?', '%s: %d products. What readability do you need?'],
        'ask_jewel'    => ['%s: %d ürün. Alım-satımda kullanacaksanız ticari onaylı (damgalı) terazi gerekir. Ticari onay gerekli mi?', '%s: %d products. For buying and selling gold you need a legal-for-trade (verified) scale. Do you need one?'],
        'ask_cap'      => ['%s: %d ürün. Tek seferde en fazla ne kadar tartacaksınız?', '%s: %d products. What is the heaviest load you will weigh at once?'],
        'result'       => ['%s: %d ürün.', '%s: %d products.'],
        'result_more'  => ['İlk 4 tanesi aşağıda, tümünü listede görebilirsiniz.', 'The first 4 are below, see the full list for all.'],
        'result_note'  => ['Fiyat ve stok bilgisi için kartlardaki Fiyat Al\'a dokunabilirsiniz.', 'Tap Get a Price on a card for price and stock.'],
        'relaxed'      => ['Bu seçimlerin hepsine uyan ürünümüz yok. En yakın seçenekler %s, aşağıda.', 'Nothing matches all of these choices. The closest options are %s, below.'],
        'none'         => ['Bu seçime uyan ürünümüz yok. İhtiyacınızı WhatsApp\'tan yazarsanız ekibimiz alternatif önersin.', 'We have nothing matching this. Message us on WhatsApp and our team will suggest an alternative.'],
        'unsure'       => ['Tabii, birlikte bulalım. Ne tartacaksınız, tek seferde en fazla kaç gram ve ne kadar hassas olması gerekiyor? Kısaca yazmanız yeterli, size uygun modelleri ben çıkarayım.', 'Sure, let us find it together. What will you weigh, what is the maximum load and how precise does it need to be? A short description is enough.'],
        'unknown'      => ['Bilmiyorum', 'Not sure'],
        'show_all'     => ['Hepsini göster', 'Show all'],
        'restart'      => ['Baştan başla', 'Start over'],
        'unsure_lbl'   => ['Emin değilim', 'Not sure'],
        'narrow_cap'   => ['Kapasiteye göre daralt', 'Filter by capacity'],
        'yes_approved' => ['Evet, onaylı olsun', 'Yes, legal for trade'],
        'not_needed'   => ['Gerekmez', 'Not needed'],
        'gold'         => ['Altın ayarı ölçmek istiyorum', 'I want to measure gold purity'],
        'all_label'    => ['Tümünü gör', 'See all'],
        'approved'     => ['ticari onaylı', 'legal-for-trade'],
        'used'         => ['2. el / yenilenmiş', 'used / refurbished'],
        'capacity'     => ['%s kapasiteli', '%s capacity'],
        'min_cap'      => ['en az %s kapasiteli', 'at least %s capacity'],
        'scales'       => ['teraziler', 'scales'],
    ];

    public static function t(string $key, array $state): string
    {
        return self::TXT[$key][($state['lang'] ?? '') === 'en' ? 1 : 0];
    }

    /** @param array<string,mixed> $raw */
    public static function normalizeState(array $raw): array
    {
        $s = [];
        $u = (string) ($raw['usage'] ?? '');
        if ($u === 'perakende') {
            $u = 'endustriyel';
        }
        if (in_array($u, self::USAGES, true)) {
            $s['usage'] = $u;
        }
        $sub = (string) ($raw['sub'] ?? '');
        if (isset(self::SUBS[$sub])) {
            $s['sub'] = $sub;
            $s['usage'] = self::SUBS[$sub][0];
        } elseif ($sub === 'any' && isset($s['usage'])) {
            $s['sub'] = 'any';
        }
        $c = (string) ($raw['commercial'] ?? '');
        if ($c === 'yes' || $c === 'any') {
            $s['commercial'] = $c;
        } elseif ($c === 'no') {
            $s['commercial'] = 'any';
        }
        $cap = (string) ($raw['cap'] ?? '');
        if ($cap === 'any' || isset(self::CAPS[$cap])) {
            $s['cap'] = $cap;
        }
        if (is_numeric($raw['need'] ?? null) && (float) $raw['need'] >= 1 && (float) $raw['need'] <= 2000000) {
            $s['need'] = (float) $raw['need'];
            unset($s['cap']);
        }
        $brand = trim((string) ($raw['brand'] ?? ''));
        if ($brand !== '') {
            $canon = self::canonicalBrand($brand);
            if ($canon !== null) {
                $s['brand'] = $canon;
            }
        }
        if (($raw['cond'] ?? '') === 'used') {
            $s['cond'] = 'used';
        }
        $feat = (string) ($raw['feature'] ?? '');
        if (isset(self::FEATURES[$feat])) {
            $s['feature'] = $feat;
        }
        $read = (string) ($raw['read'] ?? '');
        if (isset(self::READ_SUB[$read])) {
            $s['read'] = $read;
            // Laboratuvarda hassasiyet alt tipi belirler
            if (($s['usage'] ?? '') === 'laboratuvar' && !isset($s['sub'])) {
                $s['sub'] = self::READ_SUB[$read];
            }
        }
        if (($raw['lang'] ?? '') === 'en') {
            $s['lang'] = 'en';
        }

        return $s;
    }

    /** Ürün önermek için kullanım alanı (ya da 2. el) belli olmalı */
    public static function hasUsage(array $state): bool
    {
        return isset($state['usage']) || ($state['cond'] ?? '') === 'used';
    }

    /**
     * Seçime tıklandı.
     *
     * @param array<string,mixed> $state
     * @param array{facet?:string,value?:string} $select
     * @return array<string,mixed>
     */
    public static function select(array $state, array $select): array
    {
        $facet = (string) ($select['facet'] ?? '');
        $value = (string) ($select['value'] ?? '');
        $keep = array_intersect_key($state, ['lang' => 1, 'brand' => 1, 'need' => 1, 'cap' => 1, 'commercial' => 1, 'feature' => 1, 'read' => 1]);
        if ($facet === 'start' || $facet === 'reset') {
            $state = array_intersect_key($state, ['lang' => 1]);
        } elseif ($facet === 'start_pick') {
            $state = array_merge($keep, self::START[(int) $value]['state'] ?? []);
        } elseif ($facet === 'unsure') {
            return self::message(self::t('unsure', $state), $state);
        } elseif (in_array($facet, ['sub', 'commercial', 'cap'], true)) {
            $state[$facet] = $value;
            if ($facet === 'cap') {
                unset($state['need']);
            }
        } elseif ($facet === 'show') {
            return self::results(self::normalizeState($state));
        }

        return self::next(self::normalizeState($state));
    }

    /**
     * Sıradaki adım: eksik ve ayırt edici bir soru varsa sor, yoksa sonuçları göster.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public static function next(array $state): array
    {
        $pool = self::filter($state);
        if (!self::hasUsage($state)) {
            // Markanın tüm ürünleri tek bir alandaysa sormadan o alana geç
            if (isset($state['brand'])) {
                $only = null;
                $hits = 0;
                foreach (self::START as $opt) {
                    if (isset($opt['state']['cond']) || isset($opt['state']['sub'])) {
                        continue;
                    }
                    if (self::filter(array_merge($state, $opt['state'])) !== []) {
                        $hits++;
                        $only = $opt['state'];
                    }
                }
                if ($hits === 1 && $only !== null) {
                    return self::next(self::normalizeState(array_merge($state, $only)));
                }
            }
            if (isset($state['brand'])) {
                $txt = ($state['lang'] ?? '') === 'en'
                    ? sprintf(self::t('ask_usage_b', $state), count($pool), $state['brand'])
                    : sprintf(self::t('ask_usage_b', $state), $state['brand'], count($pool));

                return self::ask($state, 'usage', $txt);
            }

            return self::ask($state, 'usage', self::t('ask_usage', $state));
        }
        $label = self::ucfirstTr(self::summary($state));
        $usage = $state['usage'] ?? '';
        if ($usage === 'laboratuvar' && !isset($state['sub']) && !isset($state['brand']) && count($pool) > 6 && self::subOptionCount($state) >= 2) {
            return self::ask($state, 'sub', sprintf(self::t('ask_lab', $state), $label, count($pool)));
        }
        if ($usage === 'kuyumcu' && !isset($state['commercial']) && !isset($state['sub']) && count($pool) > 4
            && count(self::filter(array_merge($state, ['commercial' => 'yes']))) < count($pool)) {
            return self::ask($state, 'commercial', sprintf(self::t('ask_jewel', $state), $label, count($pool)));
        }
        if (!isset($state['cap']) && !isset($state['need']) && count($pool) > 8 && count(self::capOptions($pool)) >= 2) {
            return self::ask($state, 'cap', sprintf(self::t('ask_cap', $state), $label, count($pool)));
        }

        return self::results($state);
    }

    private static function subOptionCount(array $state): int
    {
        $n = 0;
        foreach (self::SUBS as $slug => [$usage]) {
            if ($usage === ($state['usage'] ?? '') && self::filter(array_merge($state, ['sub' => $slug])) !== []) {
                $n++;
            }
        }

        return $n;
    }

    /** @return array<string,mixed> */
    private static function message(string $text, array $state, array $choices = []): array
    {
        return ['ok' => true, 'engine' => 'v3', 'message' => $text, 'cards' => [], 'actions' => [], 'choices' => $choices, 'state' => $state, 'listing' => null];
    }

    /** @return array<string,mixed> */
    private static function ask(array $state, string $facet, string $text): array
    {
        return self::message($text, $state, self::choices($state, $facet));
    }

    /**
     * @param array<string,mixed> $state
     * @return list<array<string,mixed>>
     */
    private static function choices(array $state, string $facet): array
    {
        $en = ($state['lang'] ?? '') === 'en';
        $pool = self::filter($state);
        $out = [];
        if ($facet === 'usage') {
            foreach (self::START as $i => $opt) {
                $n = count(self::filter(self::normalizeState(array_merge(array_intersect_key($state, ['brand' => 1, 'lang' => 1, 'need' => 1, 'feature' => 1]), $opt['state']))));
                if ($n > 0) {
                    $out[] = ['label' => $en ? $opt['en'] : $opt['tr'], 'count' => $n, 'select' => ['facet' => 'start_pick', 'value' => (string) $i]];
                }
            }
            $out[] = ['label' => self::t('unsure_lbl', $state), 'select' => ['facet' => 'unsure', 'value' => '1'], 'ghost' => true];

            return $out;
        }
        if ($facet === 'sub') {
            foreach (self::SUBS as $slug => [$usage, $tr, $enLabel]) {
                if ($usage !== ($state['usage'] ?? '')) {
                    continue;
                }
                $n = count(self::filter(array_merge($state, ['sub' => $slug])));
                if ($n > 0) {
                    $out[] = ['label' => $en ? $enLabel : $tr, 'count' => $n, 'select' => ['facet' => 'sub', 'value' => $slug]];
                }
            }
            $out[] = ['label' => self::t('unknown', $state), 'select' => ['facet' => 'sub', 'value' => 'any'], 'ghost' => true];
        } elseif ($facet === 'commercial') {
            $yes = count(self::filter(array_merge($state, ['commercial' => 'yes'])));
            if ($yes > 0) {
                $out[] = ['label' => self::t('yes_approved', $state), 'count' => $yes, 'select' => ['facet' => 'commercial', 'value' => 'yes']];
            }
            $out[] = ['label' => self::t('not_needed', $state), 'count' => count($pool), 'select' => ['facet' => 'commercial', 'value' => 'any']];
            $gold = count(self::filter(['usage' => 'kuyumcu', 'sub' => 'altin-analiz-terazileri']));
            if ($gold > 0 && ($state['usage'] ?? '') === 'kuyumcu' && !isset($state['brand'])) {
                $out[] = ['label' => self::t('gold', $state), 'count' => $gold, 'select' => ['facet' => 'sub', 'value' => 'altin-analiz-terazileri']];
            }
        } elseif ($facet === 'cap') {
            foreach (self::capOptions($pool) as $key => $n) {
                $out[] = ['label' => self::CAPS[$key][$en ? 1 : 0], 'count' => $n, 'select' => ['facet' => 'cap', 'value' => $key]];
            }
            $out[] = ['label' => self::t('unknown', $state), 'select' => ['facet' => 'cap', 'value' => 'any'], 'ghost' => true];
        }
        if (count($pool) > 0) {
            $out[] = ['label' => self::t('show_all', $state), 'count' => count($pool), 'select' => ['facet' => 'show', 'value' => '1'], 'ghost' => true];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public static function results(array $state): array
    {
        $pool = self::filter($state);
        $text = '';
        if ($pool === []) {
            // Adım adım gevşet: kapasite → onay → marka → alt tip → 2. el
            $relaxed = $state;
            foreach ([['cap', 'need'], ['commercial'], ['feature'], ['brand'], ['sub', 'read'], ['cond']] as $drop) {
                $relaxed = array_diff_key($relaxed, array_flip($drop));
                $pool = self::filter($relaxed);
                if ($pool !== [] && self::hasUsage($relaxed)) {
                    $text = sprintf(self::t('relaxed', $state), self::summary($relaxed) !== '' ? self::summary($relaxed) : self::t('scales', $state));
                    $state = $relaxed;
                    break;
                }
            }
            if ($pool === [] || $text === '') {
                $p = self::message(self::t('none', $state), $state);
                $p['actions'] = AssistantV3::contactActions('Merhaba, ' . self::summary($state) . ' arıyorum.', [], ['whatsapp', 'phone']);

                return $p;
            }
        }
        $total = count($pool);
        if ($text === '') {
            $text = sprintf(self::t('result', $state), self::ucfirstTr(self::summary($state)), $total);
            if ($total > 4) {
                $text .= ' ' . self::t('result_more', $state);
            }
        }
        $text .= "\n\n" . self::t('result_note', $state);
        $ids = array_map(static fn (array $p): int => (int) $p['id'], array_slice($pool, 0, 4));

        $choices = [];
        if (!isset($state['cap']) && !isset($state['need']) && $total > 4 && count(self::capOptions($pool)) >= 2) {
            $choices[] = ['label' => self::t('narrow_cap', $state), 'select' => ['facet' => 'cap', 'value' => '']];
        }
        $choices[] = ['label' => self::t('restart', $state), 'select' => ['facet' => 'reset', 'value' => '1'], 'ghost' => true];

        $listing = null;
        if ($total > count($ids)) {
            $listingState = array_intersect_key($state, ['usage' => 1, 'sub' => 1, 'cond' => 1, 'brand' => 1]);
            $listing = ['url' => self::listingUrl($state), 'label' => self::t('all_label', $state), 'count' => count(self::filter($listingState))];
        }

        return [
            'ok'      => true,
            'engine'  => 'v3',
            'message' => $text,
            'cards'   => AssistantV3::cardsFor($ids),
            'actions' => AssistantV3::contactActions('Merhaba, ' . self::summary(array_diff_key($state, ['lang' => 1])) . ' hakkında bilgi almak istiyorum.', $ids, ['phone']),
            'choices' => $choices,
            'state'   => $state,
            'listing' => $listing,
        ];
    }

    /** "ticari onaylı, 0,7 – 1,7 kg kapasiteli Ohaus kuyumcu terazileri" */
    public static function summary(array $state): string
    {
        $en = ($state['lang'] ?? '') === 'en';
        $adj = [];
        if (($state['cond'] ?? '') === 'used') {
            $adj[] = self::t('used', $state);
        }
        if (($state['commercial'] ?? '') === 'yes') {
            $adj[] = self::t('approved', $state);
        }
        if (isset($state['feature'])) {
            $adj[] = self::FEATURES[$state['feature']][$en ? 1 : 0];
        }
        if (isset($state['cap']) && $state['cap'] !== 'any') {
            $adj[] = sprintf(self::t('capacity', $state), self::CAPS[$state['cap']][$en ? 1 : 0]);
        } elseif (isset($state['need'])) {
            $adj[] = sprintf(self::t('min_cap', $state), self::fmtGrams((float) $state['need'], $en));
        }
        $noun = '';
        if (isset($state['sub']) && $state['sub'] !== 'any') {
            $noun = self::NOUNS[$state['sub']][$en ? 1 : 0];
        } elseif (isset($state['usage'])) {
            $noun = self::NOUNS[$state['usage']][$en ? 1 : 0];
        } elseif ($adj !== [] || isset($state['brand'])) {
            $noun = self::t('scales', $state);
        }
        if (isset($state['brand'])) {
            $noun = $state['brand'] . ' ' . $noun;
        }

        return trim(implode(', ', $adj) . ' ' . $noun);
    }

    private static function fmtGrams(float $g, bool $en): string
    {
        if ($g >= 1000) {
            $sep = $en ? '.' : ',';
            $v = rtrim(rtrim(number_format($g / 1000, 1, $sep, ''), '0'), $sep);

            return $v . ' kg';
        }

        return (string) (int) round($g) . ' g';
    }

    /**
     * @param array<string,mixed> $state
     * @return list<array<string,mixed>>
     */
    public static function filter(array $state): array
    {
        $out = [];
        foreach (AssistantV2Catalog::products() as $p) {
            $slugs = [];
            foreach (is_array($p['categories'] ?? null) ? $p['categories'] : [] as $c) {
                foreach (['slug', 'parent_slug', 'root_slug'] as $k) {
                    $v = (string) ($c[$k] ?? '');
                    if ($v !== '') {
                        $slugs[$v] = true;
                    }
                }
            }
            if (isset($state['usage']) && !isset($slugs[$state['usage']])) {
                continue;
            }
            if (isset($state['sub']) && $state['sub'] !== 'any' && !isset($slugs[$state['sub']])) {
                continue;
            }
            if (($state['cond'] ?? '') === 'used' && !self::isRenewed($p)) {
                continue;
            }
            if (isset($state['brand']) && AssistantV2Catalog::fold((string) ($p['brand'] ?? '')) !== AssistantV2Catalog::fold($state['brand'])) {
                continue;
            }
            if (($state['commercial'] ?? '') === 'yes' && ($p['commercial'] ?? null) !== true) {
                continue;
            }
            if (isset($state['feature']) && preg_match(self::FEATURES[$state['feature']][2], self::productText($p)) !== 1) {
                continue;
            }
            $capMax = is_numeric($p['capacity_max'] ?? null) ? (float) $p['capacity_max'] : null;
            $cap = $state['cap'] ?? 'any';
            if ($cap !== 'any' && isset(self::CAPS[$cap]) && ($capMax === null || !self::fitsCap($cap, $capMax))) {
                continue;
            }
            if (isset($state['need']) && ($capMax === null || $capMax < (float) $state['need'] * 0.98)) {
                continue;
            }
            $out[] = $p;
        }
        $byCap = isset($state['need']) || (isset($state['cap']) && $state['cap'] !== 'any');
        $featRx = isset($state['feature']) ? self::FEATURES[$state['feature']][2] : null;
        usort($out, static function (array $a, array $b) use ($byCap, $featRx): int {
            // Özelliği adında taşıyan ürün (ör. "Karat Terazisi") önce
            if ($featRx !== null) {
                $na = preg_match($featRx, AssistantV2Catalog::fold((string) ($a['name'] ?? ''))) === 1;
                $nb = preg_match($featRx, AssistantV2Catalog::fold((string) ($b['name'] ?? ''))) === 1;
                if ($na !== $nb) {
                    return $na ? -1 : 1;
                }
            }
            if ($byCap) {
                $ca = (float) ($a['capacity_max'] ?? INF);
                $cb = (float) ($b['capacity_max'] ?? INF);
                if ($ca !== $cb) {
                    return $ca <=> $cb;
                }
            }
            $sa = (int) (($a['in_stock'] ?? false) === true);
            $sb = (int) (($b['in_stock'] ?? false) === true);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            return ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
        });

        return $out;
    }

    /** Ürünün ad, özellik ve filtre metni (özellik aramaları için) */
    private static function productText(array $p): string
    {
        static $cache = [];
        $id = (int) ($p['id'] ?? 0);
        if (isset($cache[$id])) {
            return $cache[$id];
        }
        $t = '';
        foreach (['name', 'short', 'capacity_label', 'precision_label', 'calibration', 'communication', 'listing_filters', 'accessories', 'housing'] as $k) {
            $v = $p[$k] ?? null;
            if (is_array($v)) {
                array_walk_recursive($v, static function ($x) use (&$t): void {
                    if (is_scalar($x)) {
                        $t .= ' ' . $x;
                    }
                });
            } elseif (is_scalar($v)) {
                $t .= ' ' . $v;
            }
        }
        foreach (is_array($p['specs'] ?? null) ? $p['specs'] : [] as $sp) {
            if (is_array($sp)) {
                $t .= ' ' . (is_scalar($sp['label'] ?? null) ? $sp['label'] : '') . ': ' . (is_scalar($sp['value'] ?? null) ? $sp['value'] : '');
            }
        }

        return $cache[$id] = AssistantV2Catalog::fold($t);
    }

    /** @param array<string,mixed> $p */
    public static function isRenewed(array $p): bool
    {
        $c = AssistantV2Catalog::fold((string) ($p['condition'] ?? ''));
        if (str_contains($c, '2. el') || str_contains($c, 'yenilen')) {
            return true;
        }
        foreach (is_array($p['categories'] ?? null) ? $p['categories'] : [] as $cat) {
            if (($cat['slug'] ?? '') === 'yenilenmis') {
                return true;
            }
        }

        return false;
    }

    private static function fitsCap(string $key, float $capMax): bool
    {
        return $capMax > (float) self::CAPS[$key][2] && $capMax <= (float) self::CAPS[$key][3];
    }

    /**
     * @param list<array<string,mixed>> $pool
     * @return array<string,int>
     */
    private static function capOptions(array $pool): array
    {
        $out = [];
        foreach (array_keys(self::CAPS) as $key) {
            $n = 0;
            foreach ($pool as $p) {
                if (is_numeric($p['capacity_max'] ?? null) && self::fitsCap($key, (float) $p['capacity_max'])) {
                    $n++;
                }
            }
            if ($n > 0) {
                $out[$key] = $n;
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $state */
    public static function listingUrl(array $state): string
    {
        if (($state['cond'] ?? '') === 'used' && !isset($state['usage'])) {
            return url('/2-el-teraziler');
        }
        $path = '/urunler';
        if (isset($state['brand'])) {
            foreach (AssistantV2Catalog::products() as $p) {
                if (($p['brand'] ?? '') === $state['brand'] && ($p['brand_slug'] ?? '') !== '') {
                    $path .= '/' . $p['brand_slug'];
                    break;
                }
            }
        }
        if (isset($state['usage'])) {
            $path .= '/' . $state['usage'];
            if (isset($state['sub']) && $state['sub'] !== 'any') {
                $path .= '/' . $state['sub'];
            }
        }

        return url($path);
    }

    /**
     * LLM'in "şunu sor" isteği için seçenekler.
     *
     * @param array<string,mixed> $state
     * @return list<array<string,mixed>>
     */
    public static function choicesFor(string $facet, array $state): array
    {
        if (!in_array($facet, ['usage', 'sub', 'commercial', 'cap'], true)) {
            return [];
        }
        if ($facet !== 'usage' && !self::hasUsage($state)) {
            $facet = 'usage';
        }

        return self::choices($state, $facet);
    }

    // ------------------------------------------------------------------ Mesajdan çıkarım

    /** Katalogdaki marka adlarıyla eşleştir (kern → Kern & Sohn, a&d, mettler …) */
    public static function canonicalBrand(string $text): ?string
    {
        $fold = ' ' . AssistantV2Catalog::fold($text) . ' ';
        $brands = [];
        foreach (AssistantV2Catalog::products() as $p) {
            $b = trim((string) ($p['brand'] ?? ''));
            if ($b !== '') {
                $brands[$b] = true;
            }
        }
        $aliases = ['kern' => 'Kern & Sohn', 'mettler' => 'Mettler Toledo', 'shinko' => 'Shinko Vibra', 'a&d' => 'A&D', 'a & d' => 'A&D'];
        foreach ($aliases as $alias => $canon) {
            if (isset($brands[$canon]) && preg_match('/(^|[^a-z0-9])' . preg_quote($alias, '/') . '([^a-z0-9]|$)/u', $fold) === 1) {
                return $canon;
            }
        }
        foreach (array_keys($brands) as $b) {
            $bf = AssistantV2Catalog::fold($b);
            if (strlen($bf) >= 3 && preg_match('/(^|[^a-z0-9])' . preg_quote($bf, '/') . '([^a-z0-9]|$)/u', $fold) === 1) {
                return $b;
            }
        }

        return null;
    }

    /**
     * Mesajdaki açık ifadelerden bulucu durumu (LLM'e ek güvence).
     *
     * @return array<string,mixed>
     */
    public static function guess(string $message): array
    {
        $f = ' ' . AssistantV2Catalog::fold($message) . ' ';
        $g = [];
        if (preg_match('/kuyumcu|kuyum|altin|taki|pirlanta|elmas|gumus|karat|jewel|gold/u', $f)) {
            $g['usage'] = 'kuyumcu';
            if (preg_match('/ayar|saflik|analiz|purity|yogunluk/u', $f) && preg_match('/altin|gold/u', $f)) {
                $g['sub'] = 'altin-analiz-terazileri';
            }
        }
        if (preg_match('/endustri|depo|koli|kargo|paket|platform|fabrika|palet|industrial|warehouse/u', $f)) {
            $g['usage'] = 'endustriyel';
        }
        if (preg_match('/tork|torque|\d\s*nm\b/u', $f)) {
            $g['usage'] = 'test-ve-olcum';
        }
        $lab = [
            'eczane-terazisi'         => '/eczane|magistral|ilac|recete|pharma/u',
            'nem-tayin-cihazlari'               => '/\bnem\b|nemi|nemini|moisture|kurutma/u',
            'yari-mikro-teraziler'  => '/yari ?mikro|semi[ -]?micro|0[.,]01 ?mg|0[.,]00001/u',
            'mikro-teraziler'         => '/mikro|\bmicro\b|0[.,]001 ?mg|0[.,]000001|\b1 ?(µg|ug|mikrogram)/u',
            'analitik-teraziler'            => '/analitik|\banalytical|0[.,]1 ?mg|0[.,]0001\b/u',
            'yari-analitik-teraziler' => '/yari ?analitik|semi[ -]?analytical|0[.,]001 ?(g|gr|gram)?\b(?! ?mg)/u',
        ];
        foreach ($lab as $sub => $rx) {
            if (preg_match($rx, $f)) {
                if ($sub === 'analitik-teraziler' && preg_match('/yari ?analitik/u', $f)) {
                    continue;
                }
                if ($sub === 'mikro-teraziler' && preg_match('/yari ?mikro/u', $f)) {
                    continue;
                }
                $g['usage'] = 'laboratuvar';
                $g['sub'] = $sub;
                break;
            }
        }
        if (!isset($g['usage']) && preg_match('/laboratuvar|\blab\b|laboratory|kimya|universite|ar-?ge|kozmetik|numune|hammadde/u', $f)) {
            $g['usage'] = 'laboratuvar';
        }
        // Laboratuvarda 0,1 g / 0,01 g → genel tartım terazileri
        if (($g['usage'] ?? '') === 'laboratuvar' && !isset($g['sub']) && preg_match('/0[.,]0?1 ?(g|gr|gram)\b/u', $f)) {
            $g['sub'] = 'hassas-laboratuvar-terazileri';
        }
        if (preg_match('/2\.? ?el|ikinci el|yenilenmis|refurb|\bused\b/u', $f)) {
            $g['cond'] = 'used';
        }
        if (preg_match('/damgali|ticari onay|onayli|alim ?-?satim|legal for trade/u', $f)) {
            $g['commercial'] = 'yes';
        }
        // Kapasite: "30 kg", "1 kilo", "500 gram", "6200 g" (5 g altı değerler hassasiyettir)
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(kg|kilo|kilogram|gr|gram|g)\b/u', $f, $m, PREG_SET_ORDER)) {
            $max = 0.0;
            foreach ($m as $hit) {
                $v = (float) str_replace(',', '.', $hit[1]);
                $grams = in_array($hit[2], ['kg', 'kilo', 'kilogram'], true) ? $v * 1000 : $v;
                if ($grams >= 5 && $grams > $max) {
                    $max = $grams;
                }
            }
            if ($max > 0) {
                $g['need'] = $max;
            }
        }
        $brand = self::canonicalBrand($message);
        if ($brand !== null) {
            $g['brand'] = $brand;
        }
        $featureRx = [
            'karat' => '/karat|\bct\b|carat/u', 'batarya' => '/batarya|bataryali|sarjli|\bpil\b|battery/u',
            'dahili' => '/dahili kal|ic kal|otomatik kal|internal cal/u', 'iyonizer' => '/iyonizer|ionizer/u',
            'wifi' => '/wi-?fi/u', 'bluetooth' => '/bluetooth/u', 'usb' => '/\busb\b/u', 'ethernet' => '/ethernet/u',
        ];
        foreach ($featureRx as $k => $rx) {
            if (preg_match($rx, $f)) {
                $g['feature'] = $k;
                break;
            }
        }
        // Hassasiyet: "0.01 g", "0,001 gram", "0.1 mg"
        if (preg_match('/\b0[.,](0{0,5}1)\s*(mg|g|gr|gram)\b/u', $f, $m)) {
            $val = (float) ('0.' . $m[1]);
            if ($m[2] === 'mg') {
                $val /= 1000;
            }
            $key = rtrim(rtrim(number_format($val, 6, '.', ''), '0'), '.');
            if (isset(self::READ_SUB[$key])) {
                $g['read'] = $key;
            }
        }

        return $g;
    }

    /** Satmadığımız ürün soruluyorsa etiketi, değilse null. */
    public static function notSold(string $message): ?string
    {
        $f = ' ' . AssistantV2Catalog::fold($message) . ' ';
        $map = [
            'İnsan tartısı (baskül)'           => '/insan tart|kisi tart|vucut|baskul|bebek tart|hasta tart|person scale|body scale|bathroom scale/u',
            'Kamyon / araç kantarı'            => '/kamyon|tir kantar|arac kantar|kantar|truck scale|weighbridge/u',
            'Mutfak terazisi'                  => '/mutfak|ev tipi|evde kullan|kitchen/u',
            'Fiyat hesaplayan market terazisi' => '/fiyat hesapla|barkod|etiket bas|etiketli terazi|market terazi|manav|kasap|sarkuteri|pazar terazi|pos terazi|price computing/u',
            'Vinç kantarı / dinamometre'       => '/vinc|dinamometre|crane scale/u',
        ];
        foreach ($map as $label => $rx) {
            if (preg_match($rx, $f)) {
                return $label;
            }
        }

        return null;
    }

    private static function ucfirstTr(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
    }
}
