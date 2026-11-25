<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Kategori profilleri analyzer sonucunu doğrular.
 * Cue'lar tek başına arama yapmaz; güçlü kullanım amacı boş alt grubu doldurur
 * veya çelişen üst kategoriyi düzeltir.
 */
final class AssistantV2CategoryProfiles
{
    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function apply(array $state, string $message): array
    {
        $decision = self::resolve($message, $state);
        if ($decision === null) {
            return $state;
        }

        $filterSub = is_string($state['filters']['subcategory'] ?? null) ? $state['filters']['subcategory'] : '';
        $filterCat = is_string($state['filters']['category'] ?? null) ? $state['filters']['category'] : '';
        if ($filterCat !== $decision['category'] || $filterSub !== $decision['subcategory']) {
            $state['advisor']['category'] = $decision['category'];
            $state['advisor']['subcategory'] = $decision['subcategory'];
            $state['filters']['category'] = $decision['category'];
            $state['filters']['subcategory'] = $decision['subcategory'];
            if ($decision['reset_measures'] && $filterSub !== '') {
                $blank = AssistantV2Filters::blank();
                $state['filters']['capacity'] = $blank['capacity'];
                $state['filters']['precision'] = $blank['precision'];
            }
        } else {
            $state['advisor']['category'] = $decision['category'];
            $state['advisor']['subcategory'] = $decision['subcategory'];
        }
        if ($decision['clear_lab_type']) {
            $state['filters']['type_token'] = null;
            unset($state['advisor']['facts']['type_token']);
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array{category:string,subcategory:string,reset_measures:bool,clear_lab_type:bool}|null
     */
    public static function resolve(string $message, array $state): ?array
    {
        $purpose = self::purposeMatch($message);
        if ($purpose !== null) {
            return self::decision($purpose, $state, true);
        }

        $analytical = self::analyticalMatch($state, $message);
        if ($analytical !== null) {
            return self::decision($analytical, $state, false);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $state
     * @return array{category:string,subcategory:string,reset_measures:bool,clear_lab_type:bool}|null
     */
    private static function decision(array $profile, array $state, bool $purpose): ?array
    {
        $placed = self::placement($profile);
        if ($placed === null) {
            return null;
        }
        $currentSub = self::currentSub($state);
        $currentCat = self::currentCat($state);
        $switching = $purpose && $currentSub !== '' && $currentSub !== $placed['subcategory'];

        return [
            'category'        => $placed['category'],
            'subcategory'     => $placed['subcategory'],
            'reset_measures'  => $switching,
            'clear_lab_type'  => $purpose && ($profile['retains_lab_type'] ?? false) !== true,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function purposeMatch(string $message): ?array
    {
        $fold = AssistantV2Catalog::fold($message);
        if ($fold === '') {
            return null;
        }
        $best = null;
        $bestLen = 0;
        $tied = false;
        foreach (self::profiles() as $profile) {
            $cues = array_merge(
                is_array($profile['purpose_cues'] ?? null) ? $profile['purpose_cues'] : [],
                is_array($profile['aliases'] ?? null) ? $profile['aliases'] : []
            );
            $len = 0;
            foreach ($cues as $cue) {
                if (!is_string($cue)) {
                    continue;
                }
                $needle = AssistantV2Catalog::fold($cue);
                if (mb_strlen($needle) < 4 || !str_contains($fold, $needle)) {
                    continue;
                }
                $len = max($len, mb_strlen($needle));
            }
            if ($len < 4) {
                continue;
            }
            if ($len > $bestLen) {
                $best = $profile;
                $bestLen = $len;
                $tied = false;
            } elseif ($len === $bestLen && $best !== null && ($best['subcategory'] ?? '') !== ($profile['subcategory'] ?? '')) {
                $tied = true;
            }
        }
        if ($tied) {
            return null;
        }

        return $best;
    }

    /**
     * 0,0001 g zaten normalize edilmiş okunabilirliktir. Metin yeniden parse edilmez.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private static function analyticalMatch(array $state, string $message): ?array
    {
        $stated = AssistantV2Catalog::statedLabScaleType($message);
        if (in_array($stated, ['yari mikro', 'mikro', 'hassas'], true)) {
            return null;
        }
        $precision = is_array($state['filters']['precision'] ?? null) ? $state['filters']['precision'] : [];
        if (($precision['unresolved'] ?? false) === true) {
            return null;
        }
        $nano = $precision['nano'] ?? null;
        if (!is_int($nano) && is_numeric($precision['value'] ?? null)) {
            $nano = (int) round(((float) $precision['value']) * 1000000000);
        }
        if (!is_int($nano)) {
            return null;
        }
        foreach (self::profiles() as $profile) {
            if (!is_int($profile['readability_nano'] ?? null) && !is_numeric($profile['readability_nano'] ?? null)) {
                continue;
            }
            if ((int) $profile['readability_nano'] !== $nano) {
                continue;
            }
            $currentCat = self::currentCat($state);
            if ($stated !== 'analitik' && $currentCat !== '' && $currentCat !== 'laboratuvar') {
                return null;
            }

            return $profile;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $profile
     * @return array{category:string,subcategory:string}|null
     */
    private static function placement(array $profile): ?array
    {
        $sub = is_string($profile['subcategory'] ?? null) ? $profile['subcategory'] : '';
        if ($sub === '') {
            return null;
        }
        $node = AssistantV2Catalog::node($sub);
        if ($node !== null && $node['parent'] !== '') {
            return [
                'category'    => $node['parent'],
                'subcategory' => $node['slug'],
            ];
        }
        $category = is_string($profile['category'] ?? null) ? $profile['category'] : '';
        $parent = $category !== '' ? AssistantV2Catalog::node($category) : null;
        if ($parent === null || $parent['parent'] !== '') {
            return null;
        }

        return [
            'category'    => $category,
            'subcategory' => $sub,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function currentCat(array $state): string
    {
        $filter = $state['filters']['category'] ?? null;
        if (is_string($filter) && $filter !== '') {
            return $filter;
        }
        $advisor = $state['advisor']['category'] ?? null;

        return is_string($advisor) ? $advisor : '';
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function currentSub(array $state): string
    {
        $filter = $state['filters']['subcategory'] ?? null;
        if (is_string($filter) && $filter !== '') {
            return $filter;
        }
        $advisor = $state['advisor']['subcategory'] ?? null;

        return is_string($advisor) ? $advisor : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function profiles(): array
    {
        $path = __DIR__ . '/advisor_category_profiles.json';
        if (!is_file($path)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json)) {
            return [];
        }
        $out = [];
        foreach ($json as $profile) {
            if (is_array($profile) && (isset($profile['purpose_cues']) || isset($profile['readability_nano']))) {
                $out[] = $profile;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function hintLines(string $categorySlug, string $subcategorySlug = ''): array
    {
        $path = __DIR__ . '/advisor_category_profiles.json';
        if (!is_file($path)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json)) {
            return [];
        }
        $profile = null;
        foreach ([$subcategorySlug, $categorySlug] as $slug) {
            if ($slug === '' || !isset($json[$slug]) || !is_array($json[$slug])) {
                continue;
            }
            $profile = $json[$slug];
            break;
        }
        if ($profile === null) {
            $fold = AssistantV2Catalog::fold($categorySlug);
            foreach ($json as $key => $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                if (AssistantV2Catalog::fold((string) $key) === $fold) {
                    $profile = $candidate;
                    break;
                }
            }
        }
        if ($profile === null) {
            return [];
        }
        $lines = [];
        foreach (['important_attributes', 'critical_attributes', 'relevant_attributes'] as $key) {
            $values = is_array($profile[$key] ?? null) ? $profile[$key] : [];
            $text = [];
            foreach ($values as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $text[] = trim($value);
                }
            }
            if ($text === []) {
                continue;
            }
            if ($key === 'critical_attributes') {
                $lines[] = 'Netleşmesi gerekenler: ' . implode(', ', $text) . '.';
            } elseif ($key === 'relevant_attributes') {
                $lines[] = 'İlgili özellikler: ' . implode(', ', $text) . '.';
            } else {
                array_push($lines, ...$text);
            }
        }

        return $lines;
    }
}
