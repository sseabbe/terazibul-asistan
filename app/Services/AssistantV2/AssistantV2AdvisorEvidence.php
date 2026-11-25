<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Exact teknik iddia, aynı modelin yapılandırılmış kanıtında yoksa cevapta kalmaz.
 * Fiyat ve stok yalnız katalog fiyat/stok alanından gelir.
 */
final class AssistantV2AdvisorEvidence
{
    public const NO_EVIDENCE = 'Bu bilgiyi mevcut kaynaklarda doğrulayamıyorum.';

    /**
     * @param array<string, mixed> $state
     * @return list<array<string, mixed>>
     */
    public static function supportedFacts(array $state): array
    {
        $byId = AssistantV2Catalog::byId();
        $rows = [];
        foreach (array_slice($state['focus_ids'] ?? [], 0, 4) as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                continue;
            }
            $product = $byId[(int) $id] ?? null;
            if (!is_array($product)) {
                continue;
            }
            $rows[] = self::factRow($product);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function apply(string $reply, string $userMessage, array $state, string $ragBlock): string
    {
        unset($state);

        return self::guard($reply, $userMessage, AssistantV2Catalog::products(), $ragBlock);
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    public static function guard(string $reply, string $userMessage, array $products, string $ragBlock = ''): string
    {
        $reply = trim($reply);
        if ($reply === '') {
            return $reply;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $reply) ?: [$reply];
        $kept = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }
            if (!self::sentenceUnsupported($sentence, $userMessage, $products, $ragBlock)) {
                $kept[] = $sentence;
            }
        }

        if ($kept === []) {
            return self::NO_EVIDENCE;
        }

        return trim(implode(' ', $kept));
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private static function factRow(array $product): array
    {
        $row = [
            'id'          => (int) ($product['id'] ?? 0),
            'brand'       => (string) ($product['brand'] ?? ''),
            'model'       => (string) ($product['model_no'] ?? ''),
            'capacity'    => (string) ($product['capacity_label'] ?? ''),
            'readability' => (string) ($product['precision_label'] ?? ''),
            'calibration' => (string) ($product['calibration'] ?? ''),
        ];
        if (is_numeric($product['price_try'] ?? null) && (float) $product['price_try'] > 0) {
            $row['price_try'] = round((float) $product['price_try'], 2);
        }
        if (array_key_exists('in_stock', $product)) {
            $row['in_stock'] = ($product['in_stock'] ?? false) === true;
        }
        $specs = [];
        foreach (is_array($product['specs'] ?? null) ? $product['specs'] : [] as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $label = trim((string) ($spec['label'] ?? ''));
            $value = trim((string) ($spec['value'] ?? ''));
            if ($label !== '' && $value !== '') {
                $specs[] = $label . ': ' . $value;
            }
        }
        if ($specs !== []) {
            $row['specs'] = $specs;
        }

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function sentenceUnsupported(string $sentence, string $userMessage, array $products, string $ragBlock): bool
    {
        $named = self::namedProducts($sentence, $products);
        if (self::unsupportedCommerce($sentence, $named)) {
            return true;
        }
        if ($named === []) {
            $fromUser = self::namedProducts($userMessage, $products);
            if (count($fromUser) !== 1) {
                return false;
            }
            $named = $fromUser;
        }

        $claims = self::technicalClaims($sentence);
        $phrases = self::phraseClaims($sentence);
        if ($claims === [] && $phrases === []) {
            return false;
        }

        $subjects = $named;
        $userClaims = self::technicalClaims($userMessage);
        $userPhrases = self::phraseClaims($userMessage);
        $needsEvidence = false;
        foreach (array_keys($claims) as $claim) {
            if (!isset($userClaims[$claim])) {
                $needsEvidence = true;
                break;
            }
        }
        foreach (array_keys($phrases) as $phrase) {
            if (!isset($userPhrases[$phrase])) {
                $needsEvidence = true;
                break;
            }
        }
        if (!$needsEvidence) {
            return false;
        }
        if ($subjects === []) {
            return true;
        }

        foreach ($subjects as $product) {
            $evidence = self::evidenceText($product, $ragBlock, $products);
            $evidenceClaims = self::technicalClaims($evidence);
            $evidencePhrases = self::phraseClaims($evidence);
            $fold = AssistantV2Catalog::fold($evidence);
            foreach (array_keys($claims) as $claim) {
                if (isset($userClaims[$claim])) {
                    continue;
                }
                if (!isset($evidenceClaims[$claim])) {
                    return true;
                }
            }
            foreach (array_keys($phrases) as $phrase) {
                if (isset($userPhrases[$phrase])) {
                    continue;
                }
                if (!isset($evidencePhrases[$phrase]) && !str_contains($fold, $phrase)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $subjects
     */
    private static function unsupportedCommerce(string $sentence, array $subjects): bool
    {
        $fold = AssistantV2Catalog::fold($sentence);
        $price = preg_match('/\b(fiyat|tl|try)\b/u', $fold) === 1 || str_contains($sentence, '₺');
        $stock = preg_match('/\bstok/u', $fold) === 1;
        if (!$price && !$stock) {
            return false;
        }
        if ($subjects === []) {
            // Ürün yokken yalnızca somut tutar veya stok iddiası atılır; "fiyat için WhatsApp'tan yazın" gibi yönlendirmeler kalır
            return ($price && self::priceAmounts($sentence) !== [])
                || ($stock && preg_match('/\bstok(ta|larda|umuzda)?\s*(var|yok|mevcut|bulun|\d)/u', $fold) === 1);
        }

        if ($price) {
            $amounts = self::priceAmounts($sentence);
            if ($amounts === []) {
                return false;
            }
            foreach ($subjects as $product) {
                $allowed = self::priceValues($product);
                foreach ($amounts as $amount) {
                    $ok = false;
                    foreach ($allowed as $have) {
                        if (abs($have - $amount) < 0.02) {
                            $ok = true;
                            break;
                        }
                    }
                    if (!$ok) {
                        return true;
                    }
                }
            }
        }

        if ($stock) {
            if (preg_match('/\d+\s*adet/iu', $sentence) === 1 || preg_match('/\bstok\w*\s+\d+/u', $fold) === 1) {
                return true;
            }
            $want = preg_match('/\byok\b/u', $fold) !== 1;
            foreach ($subjects as $product) {
                if ((($product['in_stock'] ?? false) === true) !== $want) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Tek bir modelin kurutma sıcaklığı. Kayıt yoksa her çağrıda aynı doğrulanamadı cümlesi.
     *
     * @param list<array<string, mixed>>|null $products
     */
    public static function namedDryingReply(string $message, ?array $products = null): string
    {
        $fold = AssistantV2Catalog::fold($message);
        if ($fold === '' || preg_match('/kurut|kac\s+derece/u', $fold) !== 1) {
            return '';
        }
        $products = $products ?? AssistantV2Catalog::products();
        $named = self::namedProducts($message, $products);
        if (count($named) !== 1) {
            return '';
        }
        $product = $named[0];
        $label = self::dryingLabel($product);
        $model = trim((string) ($product['model_no'] ?? ''));
        if ($model === '') {
            $model = trim((string) ($product['name'] ?? ''));
        }
        if ($label === null || $model === '') {
            return self::NO_EVIDENCE;
        }

        return $model . ' için katalog kaydında maksimum kurutma sıcaklığı ' . $label . ' yer alıyor.';
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function dryingLabel(array $product): ?string
    {
        if (is_numeric($product['drying_c'] ?? null) && (float) $product['drying_c'] > 0) {
            return rtrim(rtrim(number_format((float) $product['drying_c'], 2, '.', ''), '0'), '.') . '°C';
        }
        foreach (is_array($product['specs'] ?? null) ? $product['specs'] : [] as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $label = AssistantV2Catalog::fold((string) ($spec['label'] ?? ''));
            $value = trim((string) ($spec['value'] ?? ''));
            if ($value !== '' && str_contains($label, 'kurutma') && (str_contains($label, 'sicaklik') || str_contains($label, 'sicaklig'))) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $product
     * @param list<array<string, mixed>> $products
     */
    private static function evidenceText(array $product, string $ragBlock, array $products = []): string
    {
        $parts = [
            self::textPart($product['capacity_label'] ?? ''),
            self::textPart($product['precision_label'] ?? ''),
            self::textPart($product['calibration'] ?? ''),
            self::textPart($product['housing'] ?? ''),
        ];
        foreach (['communication', 'accessories'] as $key) {
            $parts[] = self::textPart($product[$key] ?? '');
        }
        if (is_numeric($product['drying_c'] ?? null)) {
            $parts[] = (string) $product['drying_c'] . ' °C';
        }
        foreach (is_array($product['specs'] ?? null) ? $product['specs'] : [] as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $parts[] = self::textPart($spec['label'] ?? '') . ' ' . self::textPart($spec['value'] ?? '');
        }
        $structured = trim(implode("\n", array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
        $rag = self::ragForProduct($product, $ragBlock, $products, self::technicalClaims($structured));
        if ($rag !== '') {
            $structured .= "\n" . $rag;
        }

        return trim($structured);
    }

    /**
     * Yalnızca bu modeli adlandıran satırlar. Teknik özellikteki sayı ile çelişen RAG satırı düşer.
     *
     * @param array<string, mixed> $product
     * @param list<array<string, mixed>> $products
     * @param array<string, true> $structuredClaims
     */
    private static function ragForProduct(array $product, string $ragBlock, array $products, array $structuredClaims = []): string
    {
        if (trim($ragBlock) === '') {
            return '';
        }
        $structuredKinds = [];
        foreach (array_keys($structuredClaims) as $claim) {
            $kind = strstr($claim, ':', true);
            if (is_string($kind) && $kind !== '') {
                $structuredKinds[$kind] = true;
            }
        }
        $kept = [];
        foreach (preg_split('/\n+/u', $ragBlock) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || !self::textNamesProduct($line, $product)) {
                continue;
            }
            $fold = AssistantV2Catalog::fold($line);
            if (preg_match('/\b(fiyat|stok|tl|try)\b/u', $fold) === 1 || str_contains($line, '₺')) {
                continue;
            }
            if (self::ragConflictsWithSpecs($line, $structuredClaims, $structuredKinds)) {
                continue;
            }
            $mixed = false;
            foreach ($products as $other) {
                if (!is_array($other) || (int) ($other['id'] ?? 0) === (int) ($product['id'] ?? 0)) {
                    continue;
                }
                if (self::textNamesProduct($line, $other)) {
                    $mixed = true;
                    break;
                }
            }
            if ($mixed) {
                continue;
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * Canlı teknik özellikte aynı türde bir sayı varsa eski RAG değeri onu doğrulayamaz.
     *
     * @param array<string, true> $structuredClaims
     * @param array<string, true> $structuredKinds
     */
    private static function ragConflictsWithSpecs(string $line, array $structuredClaims, array $structuredKinds): bool
    {
        if ($structuredClaims === []) {
            return false;
        }
        foreach (array_keys(self::technicalClaims($line)) as $claim) {
            if (isset($structuredClaims[$claim])) {
                continue;
            }
            $kind = strstr($claim, ':', true);
            if (is_string($kind) && isset($structuredKinds[$kind])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private static function technicalClaims(string $text): array
    {
        $claims = [];
        if (preg_match_all('/ip\s*(\d{2})/iu', $text, $ips, PREG_SET_ORDER) > 0) {
            foreach ($ips as $ip) {
                $claims['ip:' . $ip[1]] = true;
            }
        }
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(°\s*c|derece|mm|cm|kg|mg|µg|μg|ug|gr|g)\b/iu', $text, $found, PREG_SET_ORDER) > 0) {
            foreach ($found as $match) {
                $unit = AssistantV2Catalog::fold((string) $match[2]);
                $unit = str_replace([' ', '°'], '', $unit);
                if ($unit === 'derece' || $unit === 'c') {
                    $claims['c:' . self::plainNumber((string) $match[1])] = true;
                    continue;
                }
                if (in_array($unit, ['mm', 'cm'], true)) {
                    $claims[$unit . ':' . self::plainNumber((string) $match[1])] = true;
                    continue;
                }
                $grams = AssistantV2Filters::normalizeWeightGrams(str_replace(',', '.', (string) $match[1]), $unit);
                $canonical = $grams === null ? null : AssistantV2Filters::canonicalGramString($grams);
                if ($canonical !== null) {
                    $claims['g:' . $canonical] = true;
                }
            }
        }

        return $claims;
    }

    /**
     * @return array<string, true>
     */
    private static function phraseClaims(string $text): array
    {
        $fold = AssistantV2Catalog::fold($text);
        $compact = str_replace(['-', ' '], '', $fold);
        $claims = [];
        foreach ([
            'dahili kalibrasyon',
            'harici kalibrasyon',
            'otomatik kalibrasyon',
            'ticari onayli',
            'halojen',
            'infrared',
            'kizilotesi',
            'rs232',
            'usb',
            'bluetooth',
            'ethernet',
        ] as $phrase) {
            $needle = str_replace(['-', ' '], '', $phrase);
            if (str_contains($compact, $needle) || str_contains($fold, $phrase)) {
                $claims[$phrase] = true;
            }
        }
        if (preg_match('/(\d+)\s*yil\s*garanti/u', $fold, $match) === 1) {
            $claims['garanti:' . $match[1]] = true;
        }

        return $claims;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    private static function namedProducts(string $text, array $products): array
    {
        $hits = [];
        foreach ($products as $product) {
            if (!is_array($product) || !self::textNamesProduct($text, $product)) {
                continue;
            }
            $hits[(int) ($product['id'] ?? count($hits))] = $product;
        }

        return array_values($hits);
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function textNamesProduct(string $text, array $product): bool
    {
        $fold = AssistantV2Catalog::fold($text);
        if ($fold === '') {
            return false;
        }
        $tokens = [];
        $model = AssistantV2Catalog::fold((string) ($product['model_no'] ?? ''));
        if (mb_strlen($model) >= 3) {
            $tokens[] = $model;
        }
        $name = AssistantV2Catalog::fold((string) ($product['name'] ?? ''));
        if (preg_match_all('/[a-z]*\d[a-z0-9-]{2,}/u', $name, $found) > 0) {
            foreach ($found[0] as $token) {
                if (mb_strlen((string) $token) >= 4) {
                    $tokens[] = (string) $token;
                }
            }
        }
        foreach ($tokens as $token) {
            $quoted = preg_quote($token, '/');
            if (preg_match('/(^|[^a-z0-9])' . $quoted . '([^a-z0-9]|$)/u', $fold) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<float>
     */
    private static function priceAmounts(string $sentence): array
    {
        $amounts = [];
        if (preg_match_all('/(\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+(?:[.,]\d+)?)\s*(?:tl|try|₺)/iu', $sentence, $found) > 0) {
            foreach ($found[1] as $raw) {
                $amount = self::moneyAmount((string) $raw);
                if ($amount !== null) {
                    $amounts[] = $amount;
                }
            }
        }

        return $amounts;
    }

    private static function moneyAmount(string $raw): ?float
    {
        $raw = trim($raw);
        if (preg_match('/^\d{1,3}(?:\.\d{3})+(?:,\d+)?$/', $raw) === 1) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace(',', '.', $raw);
        }

        return is_numeric($raw) ? round((float) $raw, 2) : null;
    }

    /**
     * @param array<string, mixed> $product
     * @return list<float>
     */
    private static function priceValues(array $product): array
    {
        if (!is_numeric($product['price_try'] ?? null) || (float) $product['price_try'] <= 0) {
            return [];
        }

        return [round((float) $product['price_try'], 2)];
    }

    private static function textPart(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string) $value);
        }
        if (!is_array($value)) {
            return '';
        }
        $bits = [];
        foreach ($value as $item) {
            $bit = self::textPart($item);
            if ($bit !== '') {
                $bits[] = $bit;
            }
        }

        return implode(', ', $bits);
    }

    private static function plainNumber(string $raw): string
    {
        $raw = str_replace(',', '.', trim($raw));
        if (!is_numeric($raw)) {
            return $raw;
        }
        $text = rtrim(rtrim(number_format((float) $raw, 4, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }
}
