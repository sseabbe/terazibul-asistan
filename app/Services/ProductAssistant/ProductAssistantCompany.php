<?php

declare(strict_types=1);

namespace App\Services\ProductAssistant;

use Core\Database;

/**
 * Kurum cevapları yalnızca ayarlar ve yayındaki sayfalardan okunur.
 */
final class ProductAssistantCompany
{
    public const MISSING = 'Bu bilgi yayınlanan kayıtlarımızda görünmüyor. İsterseniz teknik ekibimize WhatsApp üzerinden sorabilirsiniz.';

    /**
     * @return array{message:string,whatsapp:bool}
     */
    /**
     * @return list<string>
     */
    public static function factLines(string $topic): array
    {
        $lines = [];
        $answer = self::answer($topic);
        if ($answer['message'] !== self::MISSING) {
            $lines[] = $answer['message'];
        }
        if ($topic === 'phone') {
            $display = format_tr_phone_display((string) \App\Models\Setting::get('site_phone', ''));
            if ($display !== '') {
                $lines[] = 'Telefon numarası ' . $display . '.';
            }
        }
        if ($topic === 'whatsapp') {
            $display = format_tr_phone_display(site_whatsapp_number());
            if ($display !== '') {
                $lines[] = 'WhatsApp numarası ' . $display . '.';
            }
        }

        return $lines;
    }

    public static function answer(string $topic): array
    {
        return match ($topic) {
            'whatsapp' => self::whatsapp(),
            'phone'    => self::phone(),
            'email'    => self::email(),
            'address'  => self::address(),
            'hours'    => self::hours(),
            'about'    => self::about(),
            'shipping' => self::pageSnippet('satis-ve-teslimat-kosullari', ['kargo', 'teslimat']),
            'returns'  => self::pageSnippet('garanti-ve-iade', ['iade', 'degisim', 'garanti']),
            default    => ['message' => self::MISSING, 'whatsapp' => self::hasWhatsapp()],
        };
    }

    /**
     * @return array{message:string,whatsapp:bool}
     */
    private static function whatsapp(): array
    {
        $display = format_tr_phone_display(site_whatsapp_number());
        if ($display === '') {
            return ['message' => self::MISSING, 'whatsapp' => false];
        }

        return [
            'message'  => 'Tabii. WhatsApp üzerinden bize yazabilirsiniz.',
            'whatsapp' => true,
        ];
    }

    /**
     * @return array{message:string,whatsapp:bool}
     */
    private static function phone(): array
    {
        $display = format_tr_phone_display((string) \App\Models\Setting::get('site_phone', ''));
        if ($display === '') {
            return ['message' => self::MISSING, 'whatsapp' => self::hasWhatsapp()];
        }

        return ['message' => 'Evet, telefonla da bize ulaşabilirsiniz.', 'whatsapp' => false];
    }

    /**
     * @return array{message:string,whatsapp:bool}
     */
    private static function email(): array
    {
        $email = trim((string) \App\Models\Setting::get('site_email', ''));
        if ($email === '' || !str_contains($email, '@')) {
            return ['message' => self::MISSING, 'whatsapp' => self::hasWhatsapp()];
        }

        return ['message' => 'E-posta adresimiz ' . $email . '.', 'whatsapp' => false];
    }

    /**
     * @return array{message:string,whatsapp:bool}
     */
    private static function address(): array
    {
        $address = trim((string) \App\Models\Setting::get('site_address', ''));
        if ($address === '') {
            return ['message' => self::MISSING, 'whatsapp' => self::hasWhatsapp()];
        }

        return ['message' => 'Adresimiz: ' . $address . '.', 'whatsapp' => false];
    }

    /**
     * @return array{message:string,whatsapp:bool}
     */
    private static function hours(): array
    {
        $found = '';
        foreach (Database::select(
            "SELECT value FROM settings WHERE `key` LIKE '%hour%' OR `key` LIKE '%saat%' OR `key` LIKE '%mesai%'"
        ) as $row) {
            $value = trim(self::plain((string) ($row['value'] ?? '')));
            if ($value !== '' && self::looksLikeHours($value)) {
                $found = $value;
                break;
            }
        }

        if ($found === '') {
            foreach (['iletisim', 'hakkimizda'] as $slug) {
                $hit = self::sentencesMatching(self::pageText($slug), ['calisma saat', 'mesai'], 2);
                if ($hit !== '') {
                    $found = $hit;
                    break;
                }
            }
        }

        if ($found === '') {
            return ['message' => self::MISSING, 'whatsapp' => self::hasWhatsapp()];
        }

        return ['message' => 'Çalışma saatlerimiz: ' . self::clip($found, 360), 'whatsapp' => false];
    }

    /**
     * @return array{message:string,whatsapp:bool}
     */
    private static function about(): array
    {
        $text = self::firstSentences(self::pageText('hakkimizda'), 2);
        if ($text === '') {
            $text = self::firstSentences(self::plain((string) \App\Models\Setting::get('site_description', '')), 2);
        }
        if ($text === '') {
            return ['message' => self::MISSING, 'whatsapp' => self::hasWhatsapp()];
        }

        return ['message' => $text, 'whatsapp' => false];
    }

    /**
     * @param list<string> $needles
     * @return array{message:string,whatsapp:bool}
     */
    private static function pageSnippet(string $slug, array $needles): array
    {
        $hit = self::sentencesMatching(self::pageText($slug), $needles, 2);
        if ($hit === '') {
            return ['message' => self::MISSING, 'whatsapp' => self::hasWhatsapp()];
        }

        return ['message' => self::clip($hit, 480), 'whatsapp' => false];
    }

    private static function hasWhatsapp(): bool
    {
        return site_whatsapp_number() !== '';
    }

    private static function pageText(string $slug): string
    {
        $rows = Database::select(
            "SELECT content FROM pages WHERE slug = ? AND status = 'published' AND deleted_at IS NULL LIMIT 1",
            [$slug]
        );
        if ($rows === []) {
            return '';
        }

        return self::plain((string) ($rows[0]['content'] ?? ''));
    }

    private static function plain(string $html): string
    {
        $html = str_replace(['<br>', '<br/>', '<br />', '</p>', '</h2>', '</h3>', '</li>'], "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param list<string> $needles
     */
    private static function sentencesMatching(string $text, array $needles, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }

        $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
        $picked = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $folded = ProductAssistantCatalog::fold($part);
            foreach ($needles as $needle) {
                if (str_contains($folded, $needle)) {
                    $picked[] = $part;
                    break;
                }
            }
            if (count($picked) >= $max) {
                break;
            }
        }

        return implode(' ', $picked);
    }

    private static function firstSentences(string $text, int $count): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }

        $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
        $parts = array_values(array_filter(array_map(static fn ($part): string => trim((string) $part), $parts)));
        if ($parts === []) {
            return self::clip($text, 420);
        }

        return self::clip(implode(' ', array_slice($parts, 0, $count)), 420);
    }

    private static function clip(string $text, int $limit): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = rtrim(mb_substr($text, 0, $limit));
        $cut = preg_replace('/\s+\S*$/u', '', $cut) ?? $cut;

        return rtrim($cut, ' ,;') . '.';
    }

    private static function looksLikeHours(string $value): bool
    {
        $folded = ProductAssistantCatalog::fold($value);
        if (str_contains($folded, 'saat') || str_contains($folded, 'mesai') || str_contains($folded, 'pazartesi')) {
            return true;
        }

        return preg_match('/\d{1,2}[:.]\d{2}/', $value) === 1;
    }
}
