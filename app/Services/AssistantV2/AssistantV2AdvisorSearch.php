<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Katalog araması — ürünler yalnızca AssistantV2Filters üzerinden gelir.
 */
final class AssistantV2AdvisorSearch
{
    private const PAGE = 8;

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   total:int,
     *   ids:list<int>,
     *   listing_url:string,
     *   filters:array<string,mixed>,
     *   quality:string
     * }
     */
    public static function searchProducts(array $filters, int $offset = 0): array
    {
        $filters = AssistantV2Filters::normalizeState($filters);
        if (!AssistantV2Filters::hasConstraint($filters)) {
            return [
                'total'        => 0,
                'ids'          => [],
                'listing_url'  => seg_url('/urunler'),
                'filters'      => $filters,
                'quality'      => 'empty',
            ];
        }

        $found = AssistantV2Filters::search($filters, max(0, $offset), self::PAGE);
        $ids = array_map(static fn (array $product): int => (int) $product['id'], $found['page'] ?? []);
        if ($ids === [] && is_array($found['all_ids'] ?? null)) {
            $ids = array_slice(array_map('intval', $found['all_ids']), 0, self::PAGE);
        }

        return [
            'total'       => (int) ($found['total'] ?? 0),
            'ids'         => $ids,
            'listing_url' => self::listingUrl($filters),
            'filters'     => $filters,
            'quality'     => (string) ($found['quality'] ?? 'exact'),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function listingUrl(array $filters): string
    {
        $filters = AssistantV2Filters::normalizeState($filters);
        $chain = self::listingCategoryChain($filters);
        $brands = self::listingBrandSlugs($filters);
        $path = '/urunler';
        $query = AssistantV2Filters::listingQueryParams($filters);
        if (count($brands) === 1) {
            $path .= '/' . $brands[0];
        } elseif (count($brands) > 1) {
            $query['marka'] = implode(',', $brands);
        }
        foreach ($chain as $segment) {
            $path .= '/' . $segment;
        }
        $href = seg_url($path);
        if ($query !== []) {
            $href .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $href;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    private static function listingCategoryChain(array $filters): array
    {
        $sub = is_string($filters['subcategory'] ?? null) ? trim($filters['subcategory']) : '';
        $cat = is_string($filters['category'] ?? null) ? trim($filters['category']) : '';
        $leaf = $sub !== '' ? $sub : $cat;
        $chain = self::listingChainFrom($leaf);
        if ($chain === [] && $cat !== '' && $cat !== $leaf) {
            $chain = self::listingChainFrom($cat);
        }
        if (count($chain) > 3) {
            $chain = array_slice($chain, 0, 3);
        }

        return $chain;
    }

    /**
     * @return list<string>
     */
    private static function listingChainFrom(string $leaf): array
    {
        if (preg_match('/^[a-z0-9-]+$/', $leaf) !== 1) {
            return [];
        }
        $chain = [];
        $slug = $leaf;
        $seen = [];
        $guard = 0;
        while ($slug !== '' && $guard < 6 && !isset($seen[$slug])) {
            $seen[$slug] = true;
            $node = AssistantV2Catalog::node($slug);
            if ($node === null) {
                break;
            }
            array_unshift($chain, $node['slug']);
            $parent = trim((string) ($node['parent'] ?? ''));
            if ($parent === '' || $parent === $slug || preg_match('/^[a-z0-9-]+$/', $parent) !== 1) {
                break;
            }
            $slug = $parent;
            $guard++;
        }

        return $chain;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    private static function listingBrandSlugs(array $filters): array
    {
        $brands = is_array($filters['brands'] ?? null) ? $filters['brands'] : [];
        $slugs = [];
        foreach (AssistantV2Catalog::products() as $product) {
            $name = (string) ($product['brand'] ?? '');
            if ($name === '' || !in_array($name, $brands, true)) {
                continue;
            }
            $slug = strtolower(trim((string) ($product['brand_slug'] ?? '')));
            if ($slug !== '' && preg_match('/^[a-z0-9-]+$/', $slug) === 1) {
                $slugs[$slug] = $slug;
            }
        }

        return array_values($slugs);
    }
}
