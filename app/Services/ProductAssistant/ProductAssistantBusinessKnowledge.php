<?php

declare(strict_types=1);

namespace App\Services\ProductAssistant;

/**
 * Servis sayfası ve ilgili sabit metinlerden doğrulanmış işletme bilgisi.
 * Canlı sayfa kazınmaz; metinler resources/lang/tr/service.php ve servis şablonuyla aynıdır.
 */
final class ProductAssistantBusinessKnowledge
{
    /**
     * @return list<array{name:string,slug:string,keys:list<string>}>
     */
    public static function serviceBrands(): array
    {
        return [
            ['name' => 'Ohaus', 'slug' => 'ohaus', 'keys' => ['ohaus']],
            ['name' => 'Mettler Toledo', 'slug' => 'mettler-toledo', 'keys' => ['mettler toledo', 'mettler']],
            ['name' => 'Shimadzu', 'slug' => 'shimadzu', 'keys' => ['shimadzu']],
            ['name' => 'Axis', 'slug' => 'axis', 'keys' => ['axis']],
            ['name' => 'Radwag', 'slug' => 'radwag', 'keys' => ['radwag']],
            ['name' => 'Kern', 'slug' => 'kern', 'keys' => ['kern']],
            ['name' => 'Precisa', 'slug' => 'precisa', 'keys' => ['precisa']],
            ['name' => 'Sartorius', 'slug' => 'sartorius', 'keys' => ['sartorius']],
            ['name' => 'A&D', 'slug' => 'and', 'keys' => ['a&d', 'a & d']],
            ['name' => 'Citizen', 'slug' => 'citizen', 'keys' => ['citizen']],
            ['name' => 'Aczet', 'slug' => 'aczet', 'keys' => ['aczet']],
        ];
    }

    /**
     * Yalnızca seçilen olgular. Cümle bunlarla sınırlıdır.
     *
     * @return array{facts:list<string>,fallback:string}
     */
    public static function pack(string $kind, string $message): array
    {
        $folded = ProductAssistantCatalog::fold($message);

        return match ($kind) {
            'warranty' => self::bundle(
                ['Servis garantisi 6 aydır ve yalnızca servisimizde işlem gören / değiştirilen parçaları kapsar; cihazın tamamını kapsamaz.'],
                'Evet. Servisimizde işlem gören parçalar için 6 ay servis garantisi veriyoruz. Garanti cihazın tamamını değil, işlem yaptığımız parçaları kapsar.'
            ),
            'fee' => self::bundle(
                array_values(array_filter([
                    'Arıza tespit ücretinin tutarı yayınlanmış servis bilgilerinde yok.',
                    self::published('service.step3_text'),
                    'Yapılacak işlemler onayınızdan sonra ilerliyor.',
                ])),
                'Arıza tespit ücretinin tutarı yayınlanmış bilgilerimizde yok. Cihaz elimize ulaştıktan sonra 1 gün içinde arıza tespiti yapıyoruz ve işlemlere onayınızdan sonra başlıyoruz. Ücreti netleştirmek için bize yazabilirsiniz.'
            ),
            'shipping' => self::bundle(
                [
                    'Cihazı kargoyla gönderebilirsiniz.',
                    'İstanbul içinde cihazı adresinizden alıp servis sonrası tekrar teslim ediyoruz.',
                    'İstanbul içi alım ve teslimat ücretsizdir.',
                    'Servis talebini online formdan veya WhatsApp üzerinden açabilirsiniz.',
                ],
                'Terazinizi bize birkaç farklı şekilde ulaştırabilirsiniz. İstanbul\'daysanız cihazınızı adresinizden alıp servis sonrası tekrar teslim edebiliyoruz. İstanbul dışından ise kargoyla gönderebilirsiniz. İsterseniz servis talebinizi hemen oluşturabilirsiniz.'
            ),
            'form' => self::bundle(
                ['Servis talebini online formdan oluşturabilirsiniz.'],
                'Evet. Servis talebinizi online formumuzdan oluşturabilirsiniz.'
            ),
            'brand', 'follow' => self::brandPack($folded),
            'unknown_brand' => self::bundle(
                ['Yalnızca servis sayfasında listelenen markalara teknik servis veriyoruz.', 'Sorulan marka bu listede yok.'],
                'Bu marka servis verdiğimiz liste arasında görünmüyor. Yalnızca listedeki markalara teknik servis veriyoruz. Emin olmak isterseniz bize yazabilirsiniz.'
            ),
            'trade' => self::bundle(
                [
                    'Kullanılmış terazi için cihaz incelendikten sonra teklif paylaşıyoruz.',
                    'Yenilenmiş ürünler teknik kontrolden geçmiş 2. el terazilerdir ve 12 ay teknik servis garantisi veriyoruz.',
                ],
                'Kullanılmış teraziyi inceliyoruz ve ardından teklif paylaşıyoruz. Yenilenmiş ürünlerimiz teknik kontrolden geçmiş 2. el teraziler; bunlarda 12 ay teknik servis garantisi veriyoruz.'
            ),
            default => self::bundle(
                [
                    'Kuyumcu terazileri, laboratuvar terazileri, endüstriyel hassas teraziler ve komparatör terazilere teknik servis veriyoruz.',
                    'Servis verdiğimiz markalar: Ohaus, Mettler Toledo, Shimadzu, Axis, Radwag, Kern, Precisa, Sartorius, A&D, Citizen ve Aczet.',
                    'Yalnızca bu listedeki markalara teknik servis veriyoruz.',
                ],
                'Evet, teknik servis veriyoruz. Kuyumcu, laboratuvar ve endüstriyel hassas teraziler ile komparatör terazilere bakıyoruz. Cihazınızın markasını yazarsanız listemizde olup olmadığına bakabilirim.'
            ),
        };
    }

    /**
     * @param list<string> $facts
     * @return array{facts:list<string>,fallback:string}
     */
    private static function bundle(array $facts, string $fallback): array
    {
        return ['facts' => $facts, 'fallback' => $fallback];
    }

    /**
     * @return array{facts:list<string>,fallback:string}
     */
    private static function brandPack(string $folded): array
    {
        $matched = [];
        foreach (self::serviceBrands() as $brand) {
            foreach ($brand['keys'] as $key) {
                if (preg_match('/(^|[^a-z0-9])' . preg_quote($key, '/') . '([^a-z0-9]|$)/u', $folded) === 1) {
                    $matched[] = $brand['name'];
                    break;
                }
            }
        }
        $matched = array_values(array_unique($matched));
        if ($matched === []) {
            if (preg_match('/marka|servis veriyor|servisiniz/u', $folded) === 1) {
                return self::pack('unknown_brand', $folded);
            }

            return self::pack('overview', $folded);
        }

        $names = self::joinTr($matched);

        return self::bundle(
            [$names . ' markasına teknik servis veriyoruz.', 'Yalnızca servis sayfasında listelenen markalara teknik servis veriyoruz.'],
            'Evet. ' . $names . ' markasına teknik servis veriyoruz.'
        );
    }

    /**
     * @param list<string> $items
     */
    public static function joinTr(array $items): string
    {
        $items = array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
        $count = count($items);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $items[0];
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' ve ' . $last;
    }

    private static function published(string $key): string
    {
        if (!function_exists('__')) {
            return '';
        }
        $value = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', (string) __($key))));
        if ($value === '' || $value === $key) {
            return '';
        }

        return $value;
    }

    private static function serviceFact(string $key, string $fallback): string
    {
        $value = self::published($key);
        if ($value === '') {
            return $fallback;
        }

        return 'Servisten geçen cihazlara ' . $value . '.';
    }
}
