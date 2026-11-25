<?php

declare(strict_types=1);

namespace App\Services\ProductAssistant;

use App\Services\AssistantV2\AssistantV2Llm;

/**
 * Asistan geri dönüş metnini işletmenin WhatsApp sohbetine hazırlar.
 * Kayıt tutulmaz. Mesaj, ziyaretçi wa.me bağlantısından gönderilince kutuya düşer.
 */
final class AssistantLead
{
    /**
     * @param list<int> $productIds
     * @param list<array{role:string,text:string}> $history
     * @return array{ok:bool,message?:string,whatsapp_url?:string}
     */
    public static function whatsappHandoff(string $phone, string $question, array $productIds, array $history): array
    {
        $digits = self::digits($phone);
        if (preg_match('/^05\d{9}$/', $digits) !== 1) {
            return ['ok' => false, 'message' => 'Telefon numaranızı 05xx xxx xx xx biçiminde yazın.'];
        }

        $number = site_whatsapp_number();
        if ($number === '' || preg_match('/^\d{10,15}$/', $number) !== 1) {
            return ['ok' => false, 'message' => 'WhatsApp şu anda hazır değil. Biraz sonra yeniden deneyin.'];
        }

        $question = self::plain($question, 300);
        if ($question === '') {
            foreach (array_reverse($history) as $turn) {
                if (($turn['role'] ?? '') === 'user') {
                    $question = self::plain((string) ($turn['text'] ?? ''), 300);
                    break;
                }
            }
        }
        if ($question === '') {
            $question = 'Geri dönüş talebi';
        }

        $names = self::productNames($productIds);
        $summary = self::summary($history, $question, $names);
        $text = self::message(self::label($digits), $summary, $question, $names);
        $url = 'https://wa.me/' . $number . '?text=' . rawurlencode($text);
        if (preg_match('#\Ahttps://wa\.me/\d+\?text=#', $url) !== 1) {
            return ['ok' => false, 'message' => 'WhatsApp şu anda hazır değil. Biraz sonra yeniden deneyin.'];
        }

        return ['ok' => true, 'whatsapp_url' => $url];
    }

    /**
     * @param list<int> $ids
     * @return list<string>
     */
    private static function productNames(array $ids): array
    {
        $wanted = [];
        foreach (array_slice($ids, 0, 4) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $wanted[$id] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }
        $names = [];
        foreach (ProductAssistantCatalog::products() as $product) {
            $id = (int) ($product['id'] ?? 0);
            if (!isset($wanted[$id])) {
                continue;
            }
            $name = trim((string) ($product['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param list<array{role:string,text:string}> $history
     * @param list<string> $names
     */
    private static function summary(array $history, string $question, array $names): string
    {
        $fallback = self::fallbackSummary($question, $names);
        $turns = [];
        foreach (array_slice($history, -8) as $turn) {
            $text = self::plain((string) ($turn['text'] ?? ''), 400);
            if ($text === '') {
                continue;
            }
            $turns[] = [
                'role' => ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                'text' => $text,
            ];
        }
        $body = json_encode([
            'task' => 'Konuşmadan kısa bir destek özeti yaz. JSON: {"summary":"..."} Yalnızca verilen konuşma ve ürün adlarını kullan. Yeni teknik özellik, fiyat, stok veya fark uydurma. En fazla iki cümle.',
            'history' => $turns,
            'last_user_question' => $question,
            'product_names' => $names,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = is_string($body) ? AssistantV2Llm::complete(
            'Destek özeti yaz. Konuşmada olmayan olgu ekleme. sen, sana ve noktalı virgül kullanma.',
            $body,
            16,
            'lead_summary'
        ) : null;
        $summary = is_array($json) ? self::plain((string) ($json['summary'] ?? ''), 400) : '';
        if ($summary === '' || self::inventsNumber($summary, $turns, $question, $names)) {
            return $fallback;
        }

        return $summary;
    }

    /**
     * @param list<array{role:string,text:string}> $turns
     * @param list<string> $names
     */
    private static function inventsNumber(string $summary, array $turns, string $question, array $names): bool
    {
        $source = $question . ' ' . implode(' ', $names);
        foreach ($turns as $turn) {
            $source .= ' ' . $turn['text'];
        }
        preg_match_all('/\d+(?:[.,]\d+)?/u', $source, $allowed);
        $known = [];
        foreach ($allowed[0] as $number) {
            $known[$number] = true;
            $known[str_replace(',', '.', $number)] = true;
        }
        preg_match_all('/\d+(?:[.,]\d+)?/u', $summary, $used);
        foreach ($used[0] as $number) {
            $norm = str_replace(',', '.', $number);
            if (!isset($known[$number]) && !isset($known[$norm])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $names
     */
    private static function fallbackSummary(string $question, array $names): string
    {
        $text = 'Kullanıcı şunu soruyor: ' . $question;
        if ($names !== []) {
            $text .= ' İlgili ürünler: ' . implode(', ', $names) . '.';
        }

        return self::plain($text, 400);
    }

    /**
     * @param list<string> $names
     */
    private static function message(string $phone, string $summary, string $question, array $names): string
    {
        $lines = [
            'Yeni Terazibul Asistan talebi',
            '',
            'Telefon: ' . $phone,
            '',
            'Özet:',
            $summary,
            '',
            'Son soru:',
            $question,
        ];
        if ($names !== []) {
            $lines[] = '';
            $lines[] = 'İlgili ürünler:';
            foreach ($names as $name) {
                $lines[] = $name;
            }
        }

        return implode("\n", $lines);
    }

    private static function digits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0090')) {
            $digits = substr($digits, 4);
        }
        if (str_starts_with($digits, '90') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }
        if (preg_match('/^5\d{9}$/', $digits) === 1) {
            $digits = '0' . $digits;
        }

        return $digits;
    }

    private static function label(string $digits): string
    {
        return substr($digits, 0, 4) . ' ' . substr($digits, 4, 3) . ' ' . substr($digits, 7, 2) . ' ' . substr($digits, 9, 2);
    }

    private static function plain(string $text, int $limit): string
    {
        $text = trim(strip_tags($text));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit);
        }

        return $text;
    }
}
