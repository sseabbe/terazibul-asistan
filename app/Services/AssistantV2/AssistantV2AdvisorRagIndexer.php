<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

use App\Support\HataKodlariRepository;
use App\Services\ProductAssistant\ProductAssistantBusinessKnowledge;
use Core\Database;

/**
 * Builds / refreshes the advisor RAG chunk index from DB + verified static sources.
 */
final class AssistantV2AdvisorRagIndexer
{
    /** @var list<string> */
    private const CORPORATE_PAGE_SLUGS = [
        'garanti-ve-iade',
        'satis-ve-teslimat-kosullari',
        'hakkimizda',
        'iletisim',
        'gizlilik-politikasi',
        'kvkk',
    ];

    /** @var list<string> */
    private const SERVICE_PAGE_SLUGS = [
        'servis',
        'teknik-servis',
        'kalibrasyon',
    ];

    /**
     * @return array{added:int,updated:int,skipped:int,total:int}
     */
    public static function rebuild(bool $force = false): array
    {
        $existing = AssistantV2AdvisorRagStore::load()['chunks'];
        $byId = [];
        foreach ($existing as $chunk) {
            $id = (string) ($chunk['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $chunk;
            }
        }

        $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'total' => 0];
        $incoming = [];
        foreach (self::collectChunks() as $chunk) {
            $id = (string) ($chunk['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $hash = (string) ($chunk['content_hash'] ?? '');
            $prev = $byId[$id] ?? null;
            if (!$force && is_array($prev) && ($prev['content_hash'] ?? '') === $hash && ($prev['body'] ?? '') !== '') {
                $incoming[] = $prev;
                $stats['skipped']++;
                continue;
            }
            if ($prev === null) {
                $stats['added']++;
            } else {
                $stats['updated']++;
            }
            $incoming[] = $chunk;
        }

        $stats['total'] = count($incoming);
        AssistantV2AdvisorRagStore::save($incoming);
        AssistantV2AdvisorRagStore::clearMemoryCache();

        return $stats;
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private static function collectChunks(): \Generator
    {
        yield from self::productChunks();
        yield from self::categoryChunks();
        yield from self::serviceKnowledgeChunks();
        yield from self::pageChunks(self::SERVICE_PAGE_SLUGS, 'service');
        yield from self::pageChunks(self::CORPORATE_PAGE_SLUGS, 'corporate');
        yield from self::faqChunks();
        yield from self::errorCodeChunks();
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private static function productChunks(): \Generator
    {
        foreach (AssistantV2Catalog::products() as $product) {
            yield from self::chunksForProduct($product);
        }
    }

    /**
     * Yayınlanan ürünün teknik özellik parçalarını indekste yeniler. Tam rebuild değildir.
     *
     * @param list<int> $productIds
     */
    public static function syncProducts(array $productIds): void
    {
        $ids = [];
        foreach ($productIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return;
        }

        \App\Services\ProductAssistant\ProductAssistantCatalog::forget();
        $index = AssistantV2AdvisorRagStore::load();
        if (($index['chunks'] ?? []) === [] && ($index['built_at'] ?? '') === '') {
            return;
        }

        $kept = [];
        foreach ($index['chunks'] as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $chunkId = (string) ($chunk['id'] ?? '');
            $drop = false;
            foreach ($ids as $id) {
                if (str_starts_with($chunkId, 'product:' . $id . ':')) {
                    $drop = true;
                    break;
                }
            }
            if (!$drop) {
                $kept[] = $chunk;
            }
        }

        $byId = AssistantV2Catalog::byId();
        foreach ($ids as $id) {
            $product = $byId[$id] ?? null;
            if (!is_array($product)) {
                continue;
            }
            foreach (self::chunksForProduct($product) as $chunk) {
                $kept[] = $chunk;
            }
        }

        AssistantV2AdvisorRagStore::save($kept);
        AssistantV2AdvisorRagStore::clearMemoryCache();
    }

    public static function syncProduct(int $productId): void
    {
        self::syncProducts([$productId]);
    }

    /**
     * @param array<string, mixed> $product
     * @return \Generator<int, array<string, mixed>>
     */
    private static function chunksForProduct(array $product): \Generator
    {
        $id = (int) ($product['id'] ?? 0);
        if ($id < 1) {
            return;
        }
        $brand = trim((string) ($product['brand'] ?? ''));
        $model = trim((string) ($product['model_no'] ?? ''));
        if ($model === '') {
            $model = trim((string) ($product['name'] ?? ''));
        }
        [$category, $subcategory] = self::productCategorySlugs($product);

        $specLines = [];
        foreach (is_array($product['specs'] ?? null) ? $product['specs'] : [] as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $label = trim((string) ($spec['label'] ?? ''));
            $value = trim((string) ($spec['value'] ?? ''));
            if ($label !== '' && $value !== '') {
                $specLines[] = $label . ': ' . $value;
            }
        }
        $specBody = implode("\n", $specLines);
        if ($specBody !== '') {
            yield self::chunk(
                'product:' . $id . ':specs',
                'product',
                (string) ($product['url'] ?? ''),
                trim($brand . ' ' . $model) . ' — teknik özellikler',
                $category,
                $subcategory,
                $brand,
                $model,
                $specBody
            );
        }

        $short = trim((string) ($product['short'] ?? ''));
        if ($short !== '') {
            yield self::chunk(
                'product:' . $id . ':summary',
                'product',
                (string) ($product['url'] ?? ''),
                trim($brand . ' ' . $model),
                $category,
                $subcategory,
                $brand,
                $model,
                $short
            );
        }

        foreach (is_array($product['audiences'] ?? null) ? $product['audiences'] : [] as $i => $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            yield self::chunk(
                'product:' . $id . ':use:' . $i,
                'product',
                (string) ($product['url'] ?? ''),
                trim($brand . ' ' . $model) . ' — kullanım',
                $category,
                $subcategory,
                $brand,
                $model,
                $line
            );
        }
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private static function categoryChunks(): \Generator
    {
        $rows = Database::select(
            "SELECT pc.id, pc.slug, pc.name, pc.description, pc.listing_desc, pc.listing_h1,
                    parent.slug AS parent_slug, parent.name AS parent_name
             FROM product_categories pc
             LEFT JOIN product_categories parent ON parent.id = pc.parent_id AND parent.deleted_at IS NULL
             WHERE pc.status = 'active' AND pc.deleted_at IS NULL"
        );

        foreach ($rows as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $parentSlug = trim((string) ($row['parent_slug'] ?? ''));
            $category = $parentSlug === '' ? $slug : $parentSlug;
            $sub = $parentSlug === '' ? '' : $slug;
            $parts = array_values(array_filter([
                trim((string) ($row['listing_h1'] ?? '')),
                self::plain((string) ($row['listing_desc'] ?? '')),
                self::plain((string) ($row['description'] ?? '')),
            ], static fn (string $line): bool => $line !== ''));
            if ($parts === []) {
                continue;
            }
            $body = implode("\n\n", $parts);
            yield self::chunk(
                'category:' . $slug,
                'category',
                url('/urunler/' . ($parentSlug !== '' ? $parentSlug . '/' . $slug : $slug)),
                (string) ($row['name'] ?? $slug),
                $category,
                $sub,
                '',
                '',
                $body
            );
        }
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private static function serviceKnowledgeChunks(): \Generator
    {
        $topics = array_merge(
            AssistantV2Knowledge::serviceTopics(),
            ['overview']
        );
        $topics = array_values(array_unique($topics));
        foreach ($topics as $topic) {
            $packed = AssistantV2Knowledge::service($topic, $topic);
            if (($packed['found'] ?? false) !== true) {
                continue;
            }
            $body = implode("\n", $packed['facts'] ?? []);
            if ($body === '') {
                continue;
            }
            yield self::chunk(
                'service:knowledge:' . $topic,
                'service',
                url('/servis'),
                'Terazibul servis — ' . $topic,
                '',
                '',
                '',
                '',
                $body
            );
        }

        foreach (ProductAssistantBusinessKnowledge::serviceBrands() as $brand) {
            $name = (string) ($brand['name'] ?? '');
            $slug = (string) ($brand['slug'] ?? '');
            if ($name === '' || $slug === '') {
                continue;
            }
            yield self::chunk(
                'service:brand:' . $slug,
                'service',
                url('/servis/' . $slug),
                $name . ' teknik servis',
                '',
                '',
                $name,
                '',
                $name . ' markasına Terazibul teknik servis verir (yayınlanan servis marka listesi).'
            );
        }
    }

    /**
     * @param list<string> $slugs
     * @return \Generator<int, array<string, mixed>>
     */
    private static function pageChunks(array $slugs, string $type): \Generator
    {
        foreach ($slugs as $slug) {
            $rows = Database::select(
                "SELECT slug, title, content, updated_at FROM pages
                 WHERE slug = ? AND status = 'published' AND deleted_at IS NULL LIMIT 1",
                [$slug]
            );
            if ($rows === []) {
                continue;
            }
            $row = $rows[0];
            $body = self::plain((string) ($row['content'] ?? ''));
            if ($body === '') {
                continue;
            }
            foreach (self::splitBody($body, 900) as $i => $part) {
                yield self::chunk(
                    'page:' . $slug . ':' . $i,
                    $type,
                    url('/' . $slug),
                    (string) ($row['title'] ?? $slug),
                    '',
                    '',
                    '',
                    '',
                    $part,
                    (string) ($row['updated_at'] ?? '')
                );
            }
        }
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private static function faqChunks(): \Generator
    {
        $rows = Database::select(
            "SELECT slug, title, content, updated_at FROM pages
             WHERE status = 'published' AND deleted_at IS NULL
               AND (slug LIKE '%sss%' OR slug LIKE '%faq%' OR title LIKE '%SSS%')"
        );
        foreach ($rows as $row) {
            $slug = (string) ($row['slug'] ?? '');
            $body = self::plain((string) ($row['content'] ?? ''));
            if ($slug === '' || $body === '') {
                continue;
            }
            foreach (self::splitBody($body, 800) as $i => $part) {
                yield self::chunk(
                    'faq:' . $slug . ':' . $i,
                    'faq',
                    url('/' . $slug),
                    (string) ($row['title'] ?? $slug),
                    '',
                    '',
                    '',
                    '',
                    $part,
                    (string) ($row['updated_at'] ?? '')
                );
            }
        }
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private static function errorCodeChunks(): \Generator
    {
        $repo = new HataKodlariRepository();
        foreach ($repo->errors() as $error) {
            if (!$repo->isPublishable($error)) {
                continue;
            }
            $brandSlug = (string) ($error['brandSlug'] ?? '');
            $seriesSlug = (string) ($error['seriesSlug'] ?? '');
            $errorSlug = (string) ($error['slug'] ?? '');
            if ($brandSlug === '' || $seriesSlug === '' || $errorSlug === '') {
                continue;
            }
            $code = trim((string) ($error['code'] ?? ''));
            $title = trim((string) ($error['title'] ?? ''));
            $lines = array_values(array_filter([
                $code !== '' ? 'Kod: ' . $code : '',
                (string) ($error['meaning'] ?? ''),
                (string) ($error['shortDescription'] ?? ''),
                self::listBlock('Olası nedenler', $error['possibleCauses'] ?? []),
                self::listBlock('Kullanıcı kontrolleri', $error['userChecks'] ?? []),
                trim((string) ($error['serviceDescription'] ?? '')),
            ], static fn (string $line): bool => trim($line) !== ''));
            $body = implode("\n", $lines);
            if ($body === '') {
                continue;
            }
            $url = url(HataKodlariRepository::BASE_PATH . '/' . $brandSlug . '/' . $seriesSlug . '/' . $errorSlug);
            yield self::chunk(
                'error:' . $brandSlug . ':' . $seriesSlug . ':' . $errorSlug,
                'error_code',
                $url,
                trim((string) ($error['brand'] ?? '') . ' ' . (string) ($error['series'] ?? '') . ' — ' . ($title !== '' ? $title : $code)),
                '',
                '',
                (string) ($error['brand'] ?? ''),
                implode(', ', is_array($error['models'] ?? null) ? $error['models'] : []),
                $body
            );
        }
    }

    /**
     * @param array<string, mixed> $product
     * @return array{0:string,1:string}
     */
    private static function productCategorySlugs(array $product): array
    {
        $categories = is_array($product['categories'] ?? null) ? $product['categories'] : [];
        if ($categories === []) {
            return ['', ''];
        }
        usort($categories, static fn (array $a, array $b): int => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        $primary = $categories[0];
        $root = trim((string) ($primary['root_slug'] ?? ''));
        $parent = trim((string) ($primary['parent_slug'] ?? ''));
        $slug = trim((string) ($primary['slug'] ?? ''));
        if ($root !== '') {
            return [$root, $parent !== '' ? $slug : ''];
        }
        if ($parent !== '') {
            return [$parent, $slug];
        }

        return [$slug, ''];
    }

    /**
     * @param list<mixed> $items
     */
    private static function listBlock(string $label, array $items): string
    {
        $lines = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $lines[] = '- ' . $item;
            }
        }
        if ($lines === []) {
            return '';
        }

        return $label . ":\n" . implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private static function splitBody(string $body, int $maxChars): array
    {
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        if ($body === '') {
            return [];
        }
        if (mb_strlen($body) <= $maxChars) {
            return [$body];
        }
        $parts = preg_split('/(?<=[.!?])\s+/u', $body) ?: [];
        $out = [];
        $buf = '';
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $trial = $buf === '' ? $part : $buf . ' ' . $part;
            if (mb_strlen($trial) > $maxChars && $buf !== '') {
                $out[] = $buf;
                $buf = $part;
            } else {
                $buf = $trial;
            }
        }
        if ($buf !== '') {
            $out[] = $buf;
        }

        return $out !== [] ? $out : [mb_substr($body, 0, $maxChars)];
    }

    private static function plain(string $html): string
    {
        $html = str_replace(['<br>', '<br/>', '<br />', '</p>', '</h2>', '</h3>', '</li>'], "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n+/u', "\n", $text) ?? $text;

        return trim($text);
    }

    private static function chunk(
        string $id,
        string $type,
        string $url,
        string $title,
        string $category,
        string $subcategory,
        string $brand,
        string $model,
        string $body,
        string $updatedAt = ''
    ): array {
        $body = trim($body);
        $meta = [
            'type'         => $type,
            'url'          => $url,
            'title'        => $title,
            'category'     => $category,
            'subcategory'  => $subcategory,
            'brand'        => $brand,
            'model'        => $model,
            'updatedAt'    => $updatedAt !== '' ? $updatedAt : gmdate('c'),
            'content_hash' => hash('sha256', $type . '|' . $url . '|' . $title . '|' . $body),
        ];

        return array_merge(
            ['id' => $id, 'body' => $body, 'search' => AssistantV2Catalog::fold($title . ' ' . $body . ' ' . $brand . ' ' . $model . ' ' . $category . ' ' . $subcategory)],
            $meta
        );
    }
}
