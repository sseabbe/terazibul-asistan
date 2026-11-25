<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

use App\Services\ProductAssistant\AssistantSupport;
use App\Services\ProductAssistant\ProductAssistantActions;
use App\Services\ProductAssistant\ProductAssistantBusinessKnowledge;
use App\Services\ProductAssistant\ProductAssistantCompany;

/**
 * Onaylı işletme ve servis olguları. Cümle kurmaz, fallback metnini kullanıcıya vermez.
 * LLM kural metinleri burada değil; free modda AssistantV2Prompt::VOICE_FREE / RULES_FREE kullanılır.
 */
final class AssistantV2Knowledge
{
    /**
     * @return list<string>
     */
    public static function serviceTopics(): array
    {
        return ['overview', 'warranty', 'fee', 'shipping', 'form', 'brand', 'trade', 'unknown_brand'];
    }

    /**
     * @return list<string>
     */
    public static function businessTopics(): array
    {
        return ['whatsapp', 'phone', 'email', 'address', 'hours', 'about', 'shipping', 'returns'];
    }

    /**
     * @return array{facts:list<string>,topic:string,found:bool}
     */
    public static function service(string $topic, string $message = ''): array
    {
        if (!in_array($topic, self::serviceTopics(), true)) {
            $topic = '';
        }
        if ($topic === '') {
            return ['facts' => [], 'topic' => '', 'found' => false];
        }
        $packed = ProductAssistantBusinessKnowledge::pack($topic, $topic === 'brand' ? ($message !== '' ? $message : 'marka') : $topic);
        $facts = array_values(array_filter($packed['facts'] ?? [], static fn (string $line): bool => trim($line) !== ''));
        if ($topic === 'trade' && $message !== '') {
            $fold = AssistantV2Catalog::fold($message);
            $asksRenewed = preg_match('/2\s*\.?\s*el|ikinci\s+el|yenilen/u', $fold) === 1;
            if (!$asksRenewed && preg_match('/garanti/u', $fold) !== 1) {
                $facts = array_values(array_filter(
                    $facts,
                    static fn (string $line): bool => !preg_match('/yenilen|2\s*\.?\s*el|ikinci\s+el|garanti/u', AssistantV2Catalog::fold($line))
                ));
            }
        }

        return ['facts' => $facts, 'topic' => $topic, 'found' => $facts !== []];
    }

    /**
     * @return array{facts:list<string>,topic:string,found:bool}
     */
    public static function business(string $topic): array
    {
        if (!in_array($topic, self::businessTopics(), true)) {
            return ['facts' => [], 'topic' => '', 'found' => false];
        }
        $facts = ProductAssistantCompany::factLines($topic);
        $facts = array_values(array_filter($facts, static fn (string $line): bool => trim($line) !== '' && !str_contains($line, 'görünmüyor')));

        return ['facts' => $facts, 'topic' => $topic, 'found' => $facts !== []];
    }

    /**
     * @param list<int> $productIds
     * @return array<string, mixed>
     */
    public static function whatsappAction(string $question, array $productIds): ?array
    {
        $url = AssistantSupport::whatsappUrl($question, $productIds);
        if (!is_string($url) || preg_match('#\Ahttps://wa\.me/#', $url) !== 1) {
            return null;
        }

        return [
            'type'  => 'handoff_whatsapp',
            'role'  => 'primary',
            'label' => "WhatsApp'tan Yaz",
            'url'   => $url,
        ];
    }

    /**
     * Bilgi boşluğunda kullanıcı tıklayınca açılan hazır metin. Numara formu üretmez.
     *
     * @param list<int> $productIds
     * @return array<string, mixed>|null
     */
    public static function whatsappWriteAction(string $question, array $productIds, string $label = "WhatsApp'tan Yaz"): ?array
    {
        $url = AssistantSupport::whatsappUrl($question, $productIds);
        if (!is_string($url) || preg_match('#\Ahttps://wa\.me/#', $url) !== 1) {
            return null;
        }

        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 80) {
            $label = "WhatsApp'tan Yaz";
        }

        return [
            'type'  => 'whatsapp_write',
            'role'  => 'primary',
            'label' => $label,
            'url'   => $url,
        ];
    }

    /**
     * @param list<int> $productIds
     */
    public static function liveSupportWhatsAppAction(string $question, array $productIds): ?array
    {
        return self::whatsappWriteAction($question, $productIds, 'Hemen Destek Al');
    }

    /**
     * @return array<string, mixed>
     */
    public static function phoneLeadAction(): array
    {
        return [
            'type'  => 'phone_lead',
            'role'  => 'primary',
            'label' => 'Numaramı Bırak',
            'url'   => null,
        ];
    }

    public static function chatAction(string $role = 'primary'): array
    {
        return [
            'type'  => 'handoff_chat',
            'role'  => $role,
            'label' => 'Bu Sohbette Temsilciyle Görüş',
            'url'   => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function phoneAction(): ?array
    {
        $phone = ProductAssistantActions::phone();
        if ($phone === null || !is_string($phone['url'] ?? null) || !str_starts_with($phone['url'], 'tel:')) {
            return null;
        }

        return [
            'type'  => 'phone',
            'role'  => 'primary',
            'label' => 'Bizi Ara',
            'url'   => $phone['url'],
        ];
    }

    /**
     * Kullanıcı emin değilken telefon + WhatsApp (metinde numara yok).
     *
     * @param list<int> $productIds
     * @return list<array<string, mixed>>
     */
    public static function humanHandoffActions(string $question, array $productIds = []): array
    {
        $actions = [];
        $phone = self::phoneAction();
        if ($phone !== null) {
            $phone['label'] = 'Bizi Arayın';
            $phone['role'] = 'secondary';
            $actions[] = $phone;
        }
        $whatsapp = self::whatsappWriteAction($question, $productIds);
        if ($whatsapp !== null) {
            $whatsapp['role'] = 'primary';
            $actions[] = $whatsapp;
        }

        return $actions;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function serviceAction(): ?array
    {
        $form = ProductAssistantActions::serviceForm();
        if ($form === null) {
            return null;
        }

        return [
            'type'  => 'service_form',
            'role'  => 'primary',
            'label' => 'Servis Talebi Oluştur',
            'url'   => $form['url'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function expertAction(): array
    {
        return [
            'type'  => 'expert',
            'role'  => 'primary',
            'label' => 'Uzmanımıza Sor',
            'url'   => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function compareAction(): array
    {
        return [
            'type'   => 'compare',
            'role'   => 'secondary',
            'label'  => 'Teknik Özellikleri Karşılaştır',
            'url'    => null,
            'prompt' => 'Teknik özellikleri karşılaştır',
        ];
    }

    /**
     * Kategori ve daraltma adımına göre danışman ipuçları. Yalnızca yazıcıya gider; kullanıcıya ham liste basılmaz.
     *
     * @return list<string>
     */
    public static function consultantHints(string $categorySlug, string $subcategorySlug = '', string $ask = ''): array
    {
        $needle = AssistantV2Catalog::fold($categorySlug . ' ' . $subcategorySlug);
        if ($needle === '') {
            return [];
        }
        $askFold = AssistantV2Catalog::fold($ask);

        if (str_starts_with($askFold, 'moisture') || str_contains($needle, 'nem')) {
            return [
                'Alıcılar genelde nem tayin cihazını numune tipi (toz, sıvı), prosedürdeki max kurutma sıcaklığı ve numune kapasitesine göre seçer.',
                'Süt tozu, un, baharat gibi toz numuneler katı formdadır; prosedür sıcaklığı model ayrımında belirleyicidir.',
                'Önce ihtiyacı netleştir, sonra Terazibul kataloğundaki uygun modellere in; erken ürün listesi gösterme.',
            ];
        }

        $hints = match (true) {
            str_contains($needle, 'kuyumcu') => [
                'Altın alım-satım, ayar/karat ölçümü veya hassas gram tartımı farklı cihaz gruplarına gider.',
                'Ayar ölçümü ile günlük tartım aynı terazi değildir; ne tartacağınızı netleştirmek seçimi daraltır.',
            ],
            str_contains($needle, 'laboratuvar') || str_contains($needle, 'analitik') => [
                'Analitik ve yarı analitik terazilerde istenen okuma (mg veya µg) seçimi belirler.',
                'Numune kütle aralığı ve kalibrasyon tipi (iç/dış) laboratuvar kullanımında önemlidir.',
            ],
            str_contains($needle, 'endustriyel') => [
                'Platform ölçüsü, IP koruma, tartım aralığı ve ortam (toz, nem) endüstriyel seçimde belirleyicidir.',
                'Baskül mü hassas tartım mı gerektiğini netleştirmek doğru gruba yönlendirir.',
            ],
            str_contains($needle, 'perakende') || str_contains($needle, 'manav') || str_contains($needle, 'market') => [
                'Tezgah terazisi, etiketli terazi veya fiyat hesaplamalı model ihtiyaca göre değişir.',
                'Tartılacak ürün tipi (adet, kg, paket) kapasite ve yazılım ihtiyacını etkiler.',
            ],
            str_contains($needle, 'test') || str_contains($needle, 'olcum') => [
                'Test ve ölçüm cihazlarında ölçülecek büyüklük ve doğruluk sınıfı seçimi belirler.',
            ],
            str_contains($needle, 'mutfak') || str_contains($needle, 'hobi') => [
                'Ev mutfağı ve hobi kullanımında kapasite ve okunabilirlik genelde yeterli; hassasiyet ihtiyacını netleştirin.',
            ],
            default => [],
        };

        if ($hints === [] && $ask === 'root') {
            return [
                'Hangi işte kullanacağınız (kuyumcu, laboratuvar, üretim, tezgah vb.) doğru terazi grubunu belirler.',
                'Ne tartacağınızı ve kabaca ağırlık/hassasiyet ihtiyacını bilmek listeyi daraltır.',
            ];
        }

        return array_slice($hints, 0, 3);
    }
}
