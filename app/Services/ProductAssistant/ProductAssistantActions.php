<?php

declare(strict_types=1);

namespace App\Services\ProductAssistant;

use App\Models\Setting;

/**
 * Sohbet CTA'ları. Adresler ayar ve yayındaki sayfa yollarından gelir; model üretmez.
 */
final class ProductAssistantActions
{
    /**
     * Servis sayfası kahraman butonuyla aynı yol: /servis-talebi?from=servis
     *
     * @return array{type:string,role:string,label:string,url:string}|null
     */
    public static function serviceForm(): ?array
    {
        return self::link('service_form', 'primary', 'Servis Talebi Oluştur', url('/servis-talebi?from=servis'));
    }

    /**
     * @return array{type:string,role:string,label:string,url:string}|null
     */
    public static function whatsapp(string $context, string $subject = ''): ?array
    {
        $number = site_whatsapp_number();
        if ($number === '') {
            return null;
        }

        $text = match ($context) {
            'service' => 'Merhaba, Terazibul üzerinden servis talebi hakkında bilgi almak istiyorum.',
            'product' => self::productText($subject),
            default   => 'Merhaba, Terazibul üzerinden bilgi almak istiyorum.',
        };

        return self::link(
            'whatsapp',
            'secondary',
            "WhatsApp'tan Yaz",
            'https://wa.me/' . $number . '?text=' . rawurlencode($text)
        );
    }

    /**
     * @return array{type:string,role:string,label:string,url:string}|null
     */
    public static function phone(): ?array
    {
        $tel = format_tr_phone_tel((string) Setting::get('site_phone', ''));
        if ($tel === '' || $tel === '+') {
            return null;
        }

        return self::link('phone', 'primary', 'Bizi Ara', 'tel:' . $tel);
    }

    /**
     * @return list<array{type:string,role:string,label:string,url:string}>
     */
    public static function forService(string $kind): array
    {
        return match ($kind) {
            'offer', 'shipping' => self::pack([self::serviceForm(), self::whatsapp('service')], true),
            'form'     => self::pack([self::serviceForm()]),
            'fee', 'unknown_brand' => self::pack([self::whatsapp('service', '')]),
            default    => [],
        };
    }

    /**
     * @return list<array{type:string,role:string,label:string,url:string}>
     */
    public static function forCompany(string $topic): array
    {
        return match ($topic) {
            'phone'    => self::pack([self::phone()]),
            'whatsapp' => self::pack([self::whatsapp('company')]),
            default    => [],
        };
    }

    /**
     * @return list<array{type:string,role:string,label:string,url:string}>
     */
    public static function forProduct(string $message): array
    {
        $folded = ProductAssistantCatalog::fold($message);
        if (preg_match('/fiyat al|fiyat istiyor|teklif al|fiyat ogren/u', $folded) !== 1) {
            return [];
        }

        $action = self::whatsapp('product', $message);
        if ($action === null) {
            return [];
        }
        $action['role'] = 'primary';

        return [$action];
    }

    /**
     * @param list<array{type:string,role:string,label:string,url:string}|null> $actions
     * @return list<array{type:string,role:string,label:string,url:string}>
     */
    private static function pack(array $actions, bool $demoteAfterFirst = false): array
    {
        $out = [];
        foreach ($actions as $action) {
            if ($action === null) {
                continue;
            }
            if ($demoteAfterFirst && $out !== []) {
                $action['role'] = 'secondary';
            }
            if ($out === []) {
                $action['role'] = 'primary';
            }
            $out[] = $action;
        }

        return $out;
    }

    /**
     * @return array{type:string,role:string,label:string,url:string}
     */
    private static function link(string $type, string $role, string $label, string $url): array
    {
        return [
            'type'  => $type,
            'role'  => $role,
            'label' => $label,
            'url'   => $url,
        ];
    }

    /**
     * Uzman aktarımı için soru, katalogdaki ürün adları ve hazır WhatsApp adresi.
     * Adres model tarafından üretilmez.
     *
     * @param list<array<string, mixed>> $products
     * @return array{question:string,product_ids:list<int>,products:list<array{id:int,name:string}>,whatsapp_url:?string}
     */
    public static function expertContext(string $question, array $products): array
    {
        $question = trim(preg_replace('/\s+/u', ' ', $question) ?? '');
        if (mb_strlen($question) > 500) {
            $question = mb_substr($question, 0, 500);
        }
        $picked = [];
        $ids = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $id = (int) ($product['id'] ?? 0);
            $name = trim((string) ($product['name'] ?? ''));
            if ($id <= 0 || $name === '' || isset($picked[$id])) {
                continue;
            }
            $picked[$id] = ['id' => $id, 'name' => $name];
            $ids[] = $id;
            if (count($picked) >= 4) {
                break;
            }
        }
        $names = array_map(static fn (array $row): string => $row['name'], array_values($picked));
        $url = null;
        $number = site_whatsapp_number();
        if ($number !== '') {
            $url = 'https://wa.me/' . $number . '?text=' . rawurlencode(AssistantSupport::handoffText($question, $names));
        }

        return [
            'question'      => $question,
            'product_ids'   => $ids,
            'products'      => array_values($picked),
            'whatsapp_url'  => $url,
        ];
    }

    private static function productText(string $subject): string
    {
        $subject = trim(preg_replace('/\s+/u', ' ', $subject) ?? '');
        if ($subject === '') {
            $subject = 'bir terazi';
        }

        return 'Merhaba, Terazibul Ürün Asistanı üzerinden ' . $subject . ' hakkında bilgi almak istiyorum.';
    }
}
