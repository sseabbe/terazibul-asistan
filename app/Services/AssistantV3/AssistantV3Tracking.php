<?php

declare(strict_types=1);

namespace App\Services\AssistantV3;

use App\Services\CustomerDeviceService;
use Core\Database;
use Core\Session;

/**
 * Asistanda sipariş / servis durumu soruları.
 * - Mesajda kod varsa yalnızca durum bilgisi verilir (kişisel bilgi yok), oturum başına deneme sınırı vardır.
 * - Giriş yapmış müşteriye kod istenmeden kendi kayıtlarının durumu söylenir.
 * - Misafire aynı oturumda bir kez üyelik daveti gösterilir.
 */
final class AssistantV3Tracking
{
    private const MAX_LOOKUPS_PER_HOUR = 20;

    /** @return array{text:string,actions:list<array<string,string>>}|null */
    public static function handle(string $message): ?array
    {
        $upper = mb_strtoupper($message, 'UTF-8');
        $orderCode = preg_match('/\bTZ[0-9A-Z]{8,16}\b/u', $upper, $m) === 1 ? $m[0] : '';
        $serviceCode = $orderCode === '' && preg_match('/\b(?:SV[A-Z2-9]{8}|[A-Z]{3}\d{5})\b/u', $upper, $m2) === 1 ? $m2[0] : '';
        $fold = AssistantV3Tools::foldText($message);
        $asksStatus = preg_match('/(siparis|kargo|servis|cihaz|tamir|onarim|order|repair)\S*/u', $fold) === 1
            && preg_match('/nerede|nerde|durum|takip|gelmedi|ulasmadi|asama|bitti mi|hazir mi|ne oldu|gonderildi mi|kargoya verildi mi|where is|track/u', $fold) === 1;
        // Tek başına yazılmamış "ABC12345" benzeri bir ifade, takip sorusu yoksa model adı olabilir
        if ($serviceCode !== '' && !$asksStatus && mb_strlen(trim($message)) > 16) {
            $serviceCode = '';
        }
        if ($orderCode === '' && $serviceCode === '' && !$asksStatus) {
            return null;
        }

        // Asistan uç noktası oturum ara katmanı kullanmaz: giriş bilgisi ve deneme sınırı için burada açılır
        Session::start();
        $cid = function_exists('customer_id') ? customer_id() : null;
        $actions = [];

        if ($orderCode !== '' || $serviceCode !== '') {
            if (!self::allowLookup()) {
                $text = 'Çok sayıda takip sorgusu yapıldı. Lütfen biraz sonra tekrar deneyin veya takip sayfasını kullanın.';
                return ['text' => $text, 'actions' => self::trackLinks()];
            }
            $text = $orderCode !== '' ? self::orderStatus($orderCode) : self::serviceStatus($serviceCode);
            $actions = $cid !== null ? self::accountLinks() : self::trackLinks();
        } elseif ($cid !== null) {
            $text = self::accountSummary((int) $cid);
            $actions = self::accountLinks();
        } else {
            $text = 'Sipariş numaranızı (TZ ile başlar) veya servis takip kodunuzu (ör. ABC12345) yazın, durumunu hemen söyleyeyim. Kodlar sipariş ve servis onay e-postanızda yazar.';
            $actions = self::trackLinks();
        }

        if ($cid === null && !Session::get('tba_member_nudge')) {
            self::remember('tba_member_nudge', 1);
            $text .= "\n\nBir dahaki sefere kod aramayın. Hesabınızla tüm siparişleriniz ve servis kayıtlarınız tek yerde olur. Siparişte veya serviste kullandığınız e-postayla üye olursanız bu kayıtlar hesabınıza otomatik gelir.";
            $actions = array_merge([
                ['type' => 'page_link', 'role' => 'primary', 'label' => 'Hesap Oluştur', 'url' => url('/uye-ol')],
                ['type' => 'page_link', 'role' => 'secondary', 'label' => 'Giriş Yap', 'url' => url('/giris')],
            ], $actions);
        }

        return ['text' => $text, 'actions' => $actions];
    }

    /** Asistan denetleyicisi oturumu erken kapatır: yazma için kısa süreliğine yeniden açılır. */
    private static function remember(string $key, mixed $value): void
    {
        try {
            if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
                @session_start();
            }
            $_SESSION[$key] = $value;
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        } catch (\Throwable) {
        }
    }

    private static function allowLookup(): bool
    {
        $now = time();
        $hits = array_values(array_filter((array) (Session::get('tba_track_hits') ?? []), static fn ($t): bool => is_int($t) && $t > $now - 3600));
        if (count($hits) >= self::MAX_LOOKUPS_PER_HOUR) {
            return false;
        }
        $hits[] = $now;
        self::remember('tba_track_hits', $hits);

        return true;
    }

    private static function orderStatus(string $code): string
    {
        try {
            $order = Database::select('SELECT * FROM orders WHERE order_no = ? LIMIT 1', [$code])[0] ?? null;
        } catch (\Throwable) {
            $order = null;
        }
        if ($order === null) {
            return $code . ' numaralı bir sipariş bulamadım. Numarayı sipariş onay e-postanızdan kontrol edebilirsiniz.';
        }
        $text = $code . ' numaralı siparişinizin durumu: ' . self::orderLabel($order) . '.';
        $cargo = trim((string) ($order['cargo_company'] ?? ''));
        $trackNo = trim((string) ($order['cargo_tracking_no'] ?? ''));
        if ($trackNo !== '') {
            $text .= ' Kargo' . ($cargo !== '' ? ' (' . $cargo . ')' : '') . ' takip numarası: ' . $trackNo . '.';
        }

        return $text;
    }

    private static function serviceStatus(string $code): string
    {
        try {
            $row = Database::select('SELECT status, device_brand FROM service_requests WHERE tracking_code = ? LIMIT 1', [$code])[0] ?? null;
        } catch (\Throwable) {
            $row = null;
        }
        if ($row === null) {
            return $code . ' koduyla bir servis kaydı bulamadım. Kodu servis talebi e-postanızdan kontrol edebilirsiniz.';
        }

        return $code . ' kodlu servis kaydınızın durumu: ' . CustomerDeviceService::serviceStatusLabel((string) $row['status']) . '.';
    }

    /** @param array<string,mixed> $order */
    private static function orderLabel(array $order): string
    {
        if ((string) ($order['status'] ?? '') === 'cancelled') {
            return 'İptal Edildi';
        }
        $stepId = (int) ($order['tracking_step_id'] ?? 0);
        if ($stepId > 0) {
            try {
                $label = (string) (Database::select('SELECT label FROM order_tracking_steps WHERE id = ? LIMIT 1', [$stepId])[0]['label'] ?? '');
                if ($label !== '') {
                    return $label;
                }
            } catch (\Throwable) {
            }
        }

        return match ((string) ($order['status'] ?? '')) {
            'paid' => 'Ödeme Onaylandı',
            'fulfilled' => 'Teslim Edildi',
            default => 'Sipariş Alındı',
        };
    }

    private static function accountSummary(int $cid): string
    {
        $lines = [];
        try {
            foreach (Database::select('SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 3', [$cid]) as $o) {
                $lines[] = '- Sipariş ' . $o['order_no'] . ': ' . self::orderLabel($o);
            }
            foreach (Database::select("SELECT tracking_code, device_brand, status FROM service_requests WHERE customer_id = ? AND status NOT IN ('teslim_edildi','completed','cancelled') ORDER BY id DESC LIMIT 3", [$cid]) as $s) {
                $device = trim((string) ($s['device_brand'] ?? ''));
                $lines[] = '- Servis ' . $s['tracking_code'] . ($device !== '' ? ' (' . mb_substr($device, 0, 60) . ')' : '') . ': ' . CustomerDeviceService::serviceStatusLabel((string) $s['status']);
            }
        } catch (\Throwable) {
        }
        if ($lines === []) {
            return 'Hesabınızda güncel bir sipariş veya servis kaydı görünmüyor. Üye olmadan verdiğiniz bir sipariş ya da servis talebi varsa takip kodunu yazın, durumunu söyleyeyim.';
        }

        return "Hesabınızdaki güncel kayıtlar:\n" . implode("\n", $lines);
    }

    /** @return list<array<string,string>> */
    private static function trackLinks(): array
    {
        return [
            ['type' => 'page_link', 'role' => 'secondary', 'label' => 'Sipariş Takibi', 'url' => url('/siparis-takibi')],
            ['type' => 'page_link', 'role' => 'secondary', 'label' => 'Servis Takibi', 'url' => url('/servis-takibi')],
        ];
    }

    /** @return list<array<string,string>> */
    private static function accountLinks(): array
    {
        return [
            ['type' => 'page_link', 'role' => 'secondary', 'label' => 'Siparişlerim', 'url' => url('/hesabim/siparislerim')],
            ['type' => 'page_link', 'role' => 'secondary', 'label' => 'Servis Taleplerim', 'url' => url('/hesabim/servis-taleplerim')],
        ];
    }
}
