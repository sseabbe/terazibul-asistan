<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Intent-aware site retrieval for the advisor (no web search).
 */
final class AssistantV2AdvisorRag
{
    /** @var callable|null Test override: (message, analysis, state, page) => array{block:string,debug:array} */
    public static $testRetrieveOverride = null;

    private const MIN_ABSOLUTE_SCORE = 2.2;
    private const MIN_RELATIVE_SCORE = 0.38;
    private const MAX_CHUNKS = 6;

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $state
     * @param array{brand?:string,category?:string} $page
     * @return array{block:string,debug:array<string,mixed>}
     */
    public static function retrieve(string $message, array $analysis, array $state, array $page = []): array
    {
        if (self::$testRetrieveOverride !== null) {
            $mock = (self::$testRetrieveOverride)($message, $analysis, $state, $page);
            if (is_array($mock)) {
                return [
                    'block' => (string) ($mock['block'] ?? ''),
                    'debug' => is_array($mock['debug'] ?? null) ? $mock['debug'] : [],
                ];
            }
        }

        $ragIntent = self::resolveRagIntent($message, $analysis);
        if ($ragIntent === 'error_code') {
            return self::errorCodeRetrieval($message);
        }
        if ($ragIntent === 'out_of_scope') {
            return [
                'block' => '',
                'debug' => self::debugEnvelope(false, $ragIntent, [], []),
            ];
        }

        $index = AssistantV2AdvisorRagStore::load();
        $chunks = $index['chunks'];
        if ($chunks === []) {
            return [
                'block' => '',
                'debug' => self::debugEnvelope(false, $ragIntent, [], ['reason' => 'empty_index']),
            ];
        }

        $allowedTypes = self::typesForIntent($ragIntent);
        $query = self::buildQuery($message, $analysis, $state, $page, $ragIntent);
        $tokens = self::tokens($query);
        if ($tokens === []) {
            return [
                'block' => '',
                'debug' => self::debugEnvelope(false, $ragIntent, [], ['reason' => 'empty_query']),
            ];
        }

        $categoryHint = trim((string) ($state['advisor']['category'] ?? $analysis['category'] ?? $page['category'] ?? ''));
        $subHint = trim((string) ($state['advisor']['subcategory'] ?? $analysis['subcategory'] ?? ''));
        $brandHint = trim((string) ($page['brand'] ?? ''));
        if ($brandHint === '' && is_array($state['filters']['brands'] ?? null)) {
            $brands = $state['filters']['brands'];
            if (isset($brands[0]) && is_string($brands[0])) {
                $brandHint = $brands[0];
            }
        }

        $scored = [];
        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $type = (string) ($chunk['type'] ?? '');
            if ($allowedTypes !== [] && !in_array($type, $allowedTypes, true)) {
                continue;
            }
            $score = self::scoreChunk($chunk, $tokens, $categoryHint, $subHint, $brandHint, $message, $ragIntent);
            if ($score <= 0) {
                continue;
            }
            $scored[] = ['chunk' => $chunk, 'score' => $score];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, self::MAX_CHUNKS);

        $topScore = $scored[0]['score'] ?? 0.0;
        $confidence = 'none';
        if ($topScore >= self::MIN_ABSOLUTE_SCORE) {
            $confidence = 'high';
        } elseif ($topScore > 0) {
            $confidence = 'low';
        }

        $structured = self::structuredProductContext($ragIntent, $state, $page, $message);
        $useRetrieval = $confidence === 'high'
            || ($confidence === 'low' && $ragIntent === 'error_code' && $topScore >= 1.5);

        if (!$useRetrieval && $structured === '') {
            return [
                'block' => '',
                'debug' => self::debugEnvelope(false, $ragIntent, $scored, [
                    'confidence' => $confidence,
                    'top_score'  => round($topScore, 3),
                ]),
            ];
        }

        $lines = [];
        if ($structured !== '') {
            $lines[] = '[Katalog yapılandırılmış — fiyat/stok/hassasiyet için öncelikli]';
            $lines[] = $structured;
        }
        foreach ($scored as $row) {
            if (!$useRetrieval) {
                break;
            }
            $score = (float) $row['score'];
            if ($confidence === 'low' && $score < $topScore * self::MIN_RELATIVE_SCORE) {
                continue;
            }
            if ($confidence !== 'low' && $score < self::MIN_ABSOLUTE_SCORE) {
                continue;
            }
            $chunk = $row['chunk'];
            $lines[] = '---';
            $lines[] = 'Kaynak: ' . (string) ($chunk['title'] ?? '');
            $lines[] = 'URL: ' . (string) ($chunk['url'] ?? '');
            $lines[] = trim((string) ($chunk['body'] ?? ''));
        }

        $block = self::formatSiteContext($lines, $confidence === 'high' ? 'high' : 'low');

        return [
            'block' => $block,
            'debug' => self::debugEnvelope(true, $ragIntent, $scored, [
                'confidence' => $confidence,
                'top_score'  => round($topScore, 3),
                'query'      => mb_substr($query, 0, 160),
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     */
    public static function resolveRagIntent(string $message, array $analysis): string
    {
        $intent = trim((string) ($analysis['intent'] ?? 'product_discovery'));
        $fold = AssistantV2Catalog::fold($message);

        if ($intent === 'off_topic'
            || ($intent === 'price' && preg_match('/\b(dolar|euro|usd|eur|kur|bitcoin|borsa)\b/u', $fold) === 1)) {
            return 'out_of_scope';
        }
        if (preg_match('/\b(dolar|euro|usd|eur|kur kac|doviz)\b/u', $fold) === 1 && !preg_match('/\b(terazi|tartim|kalibrasyon|servis)\b/u', $fold)) {
            return 'out_of_scope';
        }

        if (self::looksLikeErrorCodeQuery($fold)) {
            return 'error_code';
        }

        if ($intent === 'product_discovery') {
            return 'product_discovery';
        }

        if ($intent === 'service') {
            if (preg_match('/\b(kalibrasyon|kalibre)\b/u', $fold) === 1 && preg_match('/\b(servis|ariza|tamir|garanti)\b/u', $fold) !== 1) {
                return 'calibration';
            }

            return 'service';
        }

        if (preg_match('/\b(kalibrasyon|kalibre|e2|f1)\b/u', $fold) === 1) {
            return 'calibration';
        }

        if (preg_match('/\b(nem|tayin|moisture)\b/u', $fold) === 1
            && preg_match('/\b(dolar|euro|kur kac)\b/u', $fold) !== 1) {
            return 'technical_question';
        }

        if (preg_match('/\b(garanti|iade|yenilen|2\s*\.?\s*el|ikinci\s+el|kargo|teslimat|adres|mesai|hakkimizda|terazibul)\b/u', $fold) === 1) {
            return 'company_question';
        }

        if ($intent === 'general' || ($analysis['relation'] ?? '') === 'non_product_question') {
            if (preg_match('/\b(nem|prosedur|sicaklik|hassasiyet|kapasite|analitik|terazi)\b/u', $fold) === 1) {
                return 'technical_question';
            }

            return 'company_question';
        }

        if (preg_match('/\b(bu\s+urun|model|spec|ozellik|datasheet)\b/u', $fold) === 1
            || trim((string) ($analysis['relation'] ?? '')) === 'product_followup') {
            return 'product_question';
        }

        if ($intent === 'compare') {
            return 'technical_question';
        }

        return 'technical_question';
    }

    /**
     * @return list<string>
     */
    private static function typesForIntent(string $ragIntent): array
    {
        return match ($ragIntent) {
            'product_discovery' => ['category', 'product', 'faq'],
            'product_question'  => ['product', 'category'],
            'service'           => ['service', 'corporate'],
            'error_code'        => ['error_code'],
            'calibration'       => ['service', 'corporate', 'category'],
            'technical_question'=> ['category', 'product', 'faq', 'software'],
            'company_question'  => ['corporate', 'faq', 'service'],
            default             => [],
        };
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $state
     * @param array{brand?:string,category?:string} $page
     */
    private static function buildQuery(string $message, array $analysis, array $state, array $page, string $ragIntent): string
    {
        $parts = [$message];
        if ($ragIntent === 'product_discovery' || $ragIntent === 'technical_question') {
            $parts[] = (string) ($state['advisor']['category'] ?? '');
            $parts[] = (string) ($state['advisor']['subcategory'] ?? '');
            $parts[] = (string) ($analysis['category'] ?? '');
            $parts[] = (string) ($analysis['subcategory'] ?? '');
        }
        $parts[] = (string) ($page['brand'] ?? '');
        $parts[] = (string) ($page['category'] ?? '');
        if (is_array($analysis['new_facts'] ?? null)) {
            foreach ($analysis['new_facts'] as $value) {
                if (is_string($value)) {
                    $parts[] = $value;
                }
            }
        }

        return trim(implode(' ', array_filter($parts, static fn (string $p): bool => trim($p) !== '')));
    }

    /**
     * @param list<string> $tokens
     */
    private static function scoreChunk(
        array $chunk,
        array $tokens,
        string $categoryHint,
        string $subHint,
        string $brandHint,
        string $message,
        string $ragIntent
    ): float {
        $search = (string) ($chunk['search'] ?? AssistantV2Catalog::fold((string) ($chunk['body'] ?? '')));
        if ($search === '') {
            return 0.0;
        }

        $score = 0.0;
        foreach ($tokens as $token) {
            if (mb_strlen($token) < 2) {
                continue;
            }
            if (str_contains($search, $token)) {
                $score += 1.0 + min(2.0, mb_strlen($token) / 8);
            }
        }

        $catFold = AssistantV2Catalog::fold($categoryHint);
        $chunkCat = AssistantV2Catalog::fold((string) ($chunk['category'] ?? '') . ' ' . (string) ($chunk['subcategory'] ?? ''));
        if ($catFold !== '' && $chunkCat !== '' && str_contains($chunkCat, $catFold)) {
            $score += 1.5;
        }
        $subFold = AssistantV2Catalog::fold($subHint);
        if ($subFold !== '' && str_contains($chunkCat, $subFold)) {
            $score += 1.2;
        }
        $brandFold = AssistantV2Catalog::fold($brandHint);
        $chunkBrand = AssistantV2Catalog::fold((string) ($chunk['brand'] ?? ''));
        if ($brandFold !== '' && $chunkBrand !== '' && str_contains($chunkBrand, $brandFold)) {
            $score += 2.0;
        }

        if ($ragIntent === 'error_code') {
            $score += self::errorCodeBoost($message, $chunk);
        }

        return $score;
    }

    /**
     * @return array{block:string,debug:array<string,mixed>}
     */
    private static function errorCodeRetrieval(string $message): array
    {
        $match = AssistantV2AdvisorErrorMatch::match($message);
        $block = AssistantV2AdvisorErrorMatch::evidenceBlock($match['matches'], $match['ambiguous']);

        return [
            'block' => $block,
            'debug' => self::debugEnvelope($match['found'], 'error_code', [], [
                'error_match' => [
                    'found'     => $match['found'],
                    'query'     => $match['query'],
                    'tier'      => $match['tier'],
                    'ambiguous' => $match['ambiguous'],
                    'count'     => count($match['matches']),
                ],
            ]),
        ];
    }

    private static function errorCodeBoost(string $message, array $chunk): float
    {
        $query = AssistantV2AdvisorErrorMatch::extractCode($message);
        if ($query === '') {
            return 0.0;
        }
        $body = (string) ($chunk['body'] ?? '') . ' ' . (string) ($chunk['title'] ?? '');
        if (preg_match_all('/\b((?:err|error|e)\s*[-.]?\s*\d+(?:\.\d+)?)\b/iu', $body, $found) < 1) {
            return 0.0;
        }
        foreach ($found[1] as $token) {
            if (AssistantV2AdvisorErrorMatch::normalizeCode((string) $token) === $query) {
                return 4.0;
            }
        }

        return 0.0;
    }

    public static function looksLikeErrorCodeQueryPublic(string $message): bool
    {
        return self::looksLikeErrorCodeQuery(AssistantV2Catalog::fold($message));
    }

    private static function looksLikeErrorCodeQuery(string $fold): bool
    {
        return preg_match('/\b(err|error|hata\s*kodu|hata\s*mesaj|fault\s*code)\b/u', $fold) === 1
            || preg_match('/\berr?\s*[0-9]/u', $fold) === 1;
    }

    /**
     * @param array<string, mixed> $state
     * @param array{brand?:string,category?:string} $page
     */
    private static function structuredProductContext(string $ragIntent, array $state, array $page, string $message): string
    {
        if (!in_array($ragIntent, ['product_question', 'product_discovery', 'technical_question'], true)) {
            return '';
        }

        $ids = [];
        foreach (array_slice($state['focus_ids'] ?? [], 0, 3) as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }
        if ($ids === [] && trim((string) ($page['brand'] ?? '')) !== '') {
            $brandFold = AssistantV2Catalog::fold((string) $page['brand']);
            foreach (AssistantV2Catalog::products() as $product) {
                if (AssistantV2Catalog::fold((string) ($product['brand'] ?? '')) === $brandFold) {
                    $ids[] = (int) $product['id'];
                    if (count($ids) >= 2) {
                        break;
                    }
                }
            }
        }

        if ($ids === [] && $ragIntent === 'product_discovery') {
            return '';
        }

        $byId = AssistantV2Catalog::byId();
        $blocks = [];
        foreach ($ids as $id) {
            $product = $byId[$id] ?? null;
            if (!is_array($product)) {
                continue;
            }
            $blocks[] = json_encode([
                'id'        => $id,
                'brand'     => $product['brand'] ?? '',
                'model'     => $product['model_no'] ?? $product['name'] ?? '',
                'capacity'  => $product['capacity_label'] ?? '',
                'precision' => $product['precision_label'] ?? '',
                'calibration' => $product['calibration'] ?? '',
                'in_stock'  => ($product['in_stock'] ?? false) === true,
                'condition' => $product['condition'] ?? '',
                'url'       => $product['url'] ?? '',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($blocks === [] && $ragIntent !== 'product_question') {
            return '';
        }

        unset($message);

        return implode("\n", $blocks);
    }

    /**
     * @param list<string> $lines
     */
    private static function formatSiteContext(array $lines, string $confidence): string
    {
        $body = trim(implode("\n", array_filter($lines, static fn (string $line): bool => trim($line) !== '')));
        if ($body === '') {
            return '';
        }

        return '<SITE_CONTEXT confidence="' . $confidence . "\">\n" . $body . "\n</SITE_CONTEXT>";
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        $fold = AssistantV2Catalog::fold($text);
        $parts = preg_split('/[^a-z0-9]+/u', $fold) ?: [];
        $stop = ['bir', 'bu', 've', 'icin', 'ile', 'mi', 'mu', 'ne', 'nasil', 'cok', 'var', 'yok', 'the', 'and'];
        $out = [];
        $seen = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || mb_strlen($part) < 2 || in_array($part, $stop, true)) {
                continue;
            }
            // Numeric tokens ("200", "0001") must stay strings — array_keys on int keys breaks mb_strlen in scoreChunk.
            if (in_array($part, $seen, true)) {
                continue;
            }
            $seen[] = $part;
            $out[] = $part;
        }

        return $out;
    }

    /**
     * @param list<array{chunk:array<string,mixed>,score:float}> $scored
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function debugEnvelope(bool $used, string $ragIntent, array $scored, array $extra = []): array
    {
        $documents = [];
        $urls = [];
        $scores = [];
        $snippets = [];
        foreach ($scored as $row) {
            $chunk = $row['chunk'];
            $documents[] = (string) ($chunk['id'] ?? '');
            $urls[] = (string) ($chunk['url'] ?? '');
            $scores[] = round((float) $row['score'], 3);
            $snippets[] = mb_substr(trim((string) ($chunk['body'] ?? '')), 0, 120);
        }

        return array_merge([
            'used'      => $used,
            'intent'    => $ragIntent,
            'documents' => $documents,
            'urls'      => $urls,
            'scores'    => $scores,
            'chunks'    => $snippets,
        ], $extra);
    }
}
