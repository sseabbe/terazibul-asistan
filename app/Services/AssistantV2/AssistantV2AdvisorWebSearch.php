<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Controlled manufacturer-site web search fallback (default off).
 */
final class AssistantV2AdvisorWebSearch
{
    public const SOURCE_MANUFACTURER_PAGE = 'manufacturer_page';
    public const SOURCE_MANUFACTURER_MANUAL = 'manufacturer_manual';
    public const SOURCE_MANUFACTURER_DATASHEET = 'manufacturer_datasheet';
    public const SOURCE_MANUFACTURER_SUPPORT = 'manufacturer_support';
    public const SOURCE_DISTRIBUTOR = 'distributor';

    /** @var callable|null Test hook: (message, analysis, target, domain) => list<array<string,mixed>> */
    public static $testSearchOverride = null;

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $state
     * @param array<string, mixed> $ragDebug
     * @return array{block:string,debug:array<string,mixed>,evidence:array<string,mixed>|null}
     */
    public static function maybeRetrieve(string $message, array $analysis, array $state, array $ragDebug): array
    {
        $emptyDebug = self::debugEnvelope(false, 'disabled', null, null, []);

        if (!self::featureEnabled()) {
            return ['block' => '', 'debug' => $emptyDebug, 'evidence' => null];
        }

        $gate = self::gateReason($message, $analysis, $state, $ragDebug);
        if ($gate !== '') {
            return [
                'block'     => '',
                'debug'     => self::debugEnvelope(false, 'skipped', null, null, ['skip_reason' => $gate]),
                'evidence'  => null,
            ];
        }

        $target = self::normalizeTarget($analysis);
        if ($target === null) {
            return [
                'block'     => '',
                'debug'     => self::debugEnvelope(false, 'skipped', null, null, ['skip_reason' => 'invalid_target']),
                'evidence'  => null,
            ];
        }

        $domain = self::domainForBrand($target['brand']);
        if ($domain === '') {
            logger('assistant_v2.web_search', ['event' => 'no_domain', 'brand' => $target['brand']], 'info');

            return [
                'block'     => '',
                'debug'     => self::debugEnvelope(false, 'skipped', $target, null, ['skip_reason' => 'unknown_brand_domain']),
                'evidence'  => null,
            ];
        }

        $cacheKey = self::cacheKey($target);
        $cached = self::cacheGet($cacheKey);
        if ($cached !== null) {
            $evidence = self::normalizeEvidence($cached, $target, $domain, true);

            return self::packResult($evidence, $target, $domain, true);
        }

        $hits = self::executeSearch($message, $analysis, $target, $domain);
        if ($hits === []) {
            self::cachePut($cacheKey, ['hits' => [], 'fetched_at' => time()]);

            return [
                'block'     => '',
                'debug'     => self::debugEnvelope(false, 'empty', $target, $domain, ['provider' => self::providerName()]),
                'evidence'  => null,
            ];
        }

        $evidence = self::normalizeEvidence(['hits' => $hits, 'fetched_at' => time()], $target, $domain, false);
        self::cachePut($cacheKey, ['hits' => $hits, 'fetched_at' => time(), 'confidence' => $evidence['confidence'] ?? 'low']);

        return self::packResult($evidence, $target, $domain, false);
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $state
     * @param array<string, mixed> $ragDebug
     */
    public static function gateReason(string $message, array $analysis, array $state, array $ragDebug): string
    {
        if (($analysis['needsWebSearch'] ?? false) !== true) {
            return 'analyzer_off';
        }

        $intent = trim((string) ($analysis['intent'] ?? ''));
        if ($intent === 'off_topic') {
            return 'out_of_scope';
        }

        if ($intent === 'product_discovery' && self::isGenericScaleShopping($message)) {
            return 'generic_product_discovery';
        }

        if ($intent === 'product_discovery' && ($analysis['ready_for_product_results'] ?? false) !== true) {
            $missing = is_array($analysis['missing_information'] ?? null) ? $analysis['missing_information'] : [];
            if ($missing !== [] && trim((string) ($analysis['webSearchTarget']['model'] ?? '')) === '') {
                return 'clarification_preferred';
            }
        }

        if (!self::isInScopeTechnicalQuestion($message, $analysis)) {
            return 'not_technical';
        }

        if (!self::ragInsufficient($message, $analysis, $ragDebug)) {
            return 'rag_sufficient';
        }

        if (!self::catalogInsufficient($message, $analysis, $state)) {
            return 'catalog_sufficient';
        }

        if (!self::externalFactVerifiable($analysis)) {
            return 'fact_not_verifiable';
        }

        return '';
    }

    public static function featureEnabled(): bool
    {
        $flag = trim((string) env('ASSISTANT_WEB_SEARCH_ENABLED', '0'));

        return $flag === '1' || strtolower($flag) === 'true';
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array{brand:string,model:string,requestedFact:string}|null
     */
    public static function normalizeTarget(array $analysis): ?array
    {
        $raw = is_array($analysis['webSearchTarget'] ?? null) ? $analysis['webSearchTarget'] : [];
        $brand = trim((string) ($raw['brand'] ?? ''));
        $model = trim((string) ($raw['model'] ?? ''));
        $fact = trim((string) ($raw['requestedFact'] ?? ''));
        if ($brand === '' || $fact === '') {
            return null;
        }

        return [
            'brand'         => $brand,
            'model'         => $model,
            'requestedFact' => $fact,
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private static function isInScopeTechnicalQuestion(string $message, array $analysis): bool
    {
        $intent = trim((string) ($analysis['intent'] ?? ''));
        if (in_array($intent, ['service', 'general', 'compare'], true)) {
            return true;
        }
        if ($intent === 'product_discovery') {
            $target = self::normalizeTarget($analysis);

            return $target !== null && $target['model'] !== '';
        }

        $fold = AssistantV2Catalog::fold($message);

        return preg_match('/\b(model|sicaklik|kurutma|hata|err|spec|ozellik|datasheet|manuel|nem|tayin|kalibrasyon)\b/u', $fold) === 1;
    }

    private static function isGenericScaleShopping(string $message): bool
    {
        $fold = AssistantV2Catalog::fold($message);
        if (preg_match('/\bterazi\s*(ariyorum|lazim|istiyorum|oner)\b/u', $fold) !== 1) {
            return false;
        }

        return preg_match('/\b(laboratuvar|analitik|200\s*g|0\.0001|kapasite|hassasiyet|model)\b/u', $fold) !== 1;
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $ragDebug
     */
    private static function ragInsufficient(string $message, array $analysis, array $ragDebug): bool
    {
        $ragIntent = AssistantV2AdvisorRag::resolveRagIntent($message, $analysis);
        if ($ragIntent === 'out_of_scope') {
            return false;
        }

        $used = ($ragDebug['used'] ?? false) === true;
        $confidence = (string) ($ragDebug['confidence'] ?? 'none');

        if ($ragIntent === 'error_code') {
            return !$used;
        }

        if (!$used) {
            return true;
        }

        return $confidence !== 'high';
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $state
     */
    private static function catalogInsufficient(string $message, array $analysis, array $state): bool
    {
        unset($message);
        $target = self::normalizeTarget($analysis);
        if ($target !== null && ($target['model'] !== '' || $target['requestedFact'] !== '')) {
            return true;
        }

        if (($analysis['needs_catalog_search'] ?? true) === false) {
            return true;
        }

        if (!AssistantV2Filters::hasConstraint($state['filters'])) {
            return true;
        }

        return ($analysis['ready_for_product_results'] ?? false) !== true;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private static function externalFactVerifiable(array $analysis): bool
    {
        $target = self::normalizeTarget($analysis);
        if ($target === null) {
            return false;
        }
        if (self::domainForBrand($target['brand']) === '') {
            return false;
        }

        return mb_strlen($target['requestedFact']) >= 3;
    }

    /**
     * @param array{brand:string,model:string,requestedFact:string} $target
     */
    private static function cacheKey(array $target): string
    {
        $parts = [
            AssistantV2Catalog::fold($target['brand']),
            AssistantV2Catalog::fold($target['model']),
            AssistantV2Catalog::fold($target['requestedFact']),
        ];

        return implode(':', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /**
     * @param array{brand:string,model:string,requestedFact:string} $target
     * @return list<array<string,mixed>>
     */
    private static function executeSearch(string $message, array $analysis, array $target, string $domain): array
    {
        if (self::$testSearchOverride !== null) {
            $hits = (self::$testSearchOverride)($message, $analysis, $target, $domain);

            return is_array($hits) ? $hits : [];
        }

        $query = self::buildQuery($target, $domain);
        $provider = self::providerName();
        if ($provider === 'stub') {
            logger('assistant_v2.web_search', ['event' => 'stub', 'query' => $query], 'info');

            return [];
        }

        return match ($provider) {
            'serpapi' => self::searchSerpApi($query),
            'bing'    => self::searchBing($query),
            default   => self::searchSerpApi($query),
        };
    }

    /**
     * @param array{brand:string,model:string,requestedFact:string} $target
     */
    public static function buildQuery(array $target, string $domain): string
    {
        $parts = ['site:' . $domain];
        if ($target['model'] !== '') {
            $parts[] = $target['model'];
        }
        $parts[] = $target['requestedFact'];

        return trim(implode(' ', $parts));
    }

    /**
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private static function searchSerpApi(string $query): array
    {
        $key = trim((string) env('ASSISTANT_WEB_SEARCH_API_KEY', ''));
        if ($key === '') {
            logger('assistant_v2.web_search', ['event' => 'missing_api_key', 'provider' => 'serpapi'], 'warning');

            return [];
        }

        $url = 'https://serpapi.com/search.json?' . http_build_query([
            'engine'  => 'google',
            'q'       => $query,
            'api_key' => $key,
            'num'     => 5,
        ]);

        $raw = self::httpGet($url);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach (is_array($decoded['organic_results'] ?? null) ? $decoded['organic_results'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'title'   => trim((string) ($row['title'] ?? '')),
                'url'     => trim((string) ($row['link'] ?? '')),
                'snippet' => self::sanitizeSnippet(trim((string) ($row['snippet'] ?? ''))),
            ];
            if (count($out) >= 4) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private static function searchBing(string $query): array
    {
        $key = trim((string) env('ASSISTANT_WEB_SEARCH_API_KEY', ''));
        if ($key === '') {
            logger('assistant_v2.web_search', ['event' => 'missing_api_key', 'provider' => 'bing'], 'warning');

            return [];
        }

        $url = 'https://api.bing.microsoft.com/v7.0/search?' . http_build_query(['q' => $query, 'count' => 5]);
        $raw = self::httpGet($url, ['Ocp-Apim-Subscription-Key: ' . $key]);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach (is_array($decoded['webPages']['value'] ?? null) ? $decoded['webPages']['value'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'title'   => trim((string) ($row['name'] ?? '')),
                'url'     => trim((string) ($row['url'] ?? '')),
                'snippet' => self::sanitizeSnippet(trim((string) ($row['snippet'] ?? ''))),
            ];
            if (count($out) >= 4) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $headers
     */
    private static function httpGet(string $url, array $headers = []): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }
        $baseHeaders = ['Accept: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => array_merge($baseHeaders, $headers),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            logger('assistant_v2.web_search', ['event' => 'http_error', 'status' => $status], 'warning');

            return '';
        }

        return $raw;
    }

    public static function sanitizeSnippet(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/<\/?(?:system|assistant|user|instruction|SITE_CONTEXT|CATALOG_CONTEXT)[^>]*>/iu', '', $text) ?? $text;
        $patterns = [
            '/\b(ignore (all )?previous|disregard|you are now|system prompt|act as|jailbreak)\b/iu',
            '/\b(follow these instructions|new instructions|override)\b/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return '[snippet removed: untrusted instruction-like content]';
            }
        }

        return trim(mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 480));
    }

    /**
     * @param array<string, mixed> $cached
     * @param array{brand:string,model:string,requestedFact:string} $target
     * @return array<string, mixed>
     */
    private static function normalizeEvidence(array $cached, array $target, string $domain, bool $fromCache): array
    {
        $hits = is_array($cached['hits'] ?? null) ? $cached['hits'] : [];
        $items = [];
        $exactModel = $target['model'] !== '';
        $modelFold = AssistantV2Catalog::fold($target['model']);
        $anyModelMatch = false;

        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $url = trim((string) ($hit['url'] ?? ''));
            $snippet = trim((string) ($hit['snippet'] ?? ''));
            $title = trim((string) ($hit['title'] ?? ''));
            if ($url === '' && $snippet === '') {
                continue;
            }
            $hostMatch = self::urlOnManufacturerDomain($url, $domain);
            $sourceType = self::inferSourceType($url, $title);
            $snippetFold = AssistantV2Catalog::fold($snippet . ' ' . $title);
            $modelMatch = $modelFold !== '' && str_contains($snippetFold, $modelFold);
            if ($modelMatch) {
                $anyModelMatch = true;
            }
            $itemConfidence = 'low';
            if ($hostMatch && $modelMatch) {
                $itemConfidence = 'high';
            } elseif ($hostMatch) {
                $itemConfidence = 'medium';
            }
            $items[] = [
                'title'            => $title,
                'url'              => $url,
                'snippet'          => $snippet,
                'sourceType'       => $sourceType,
                'confidence'       => $itemConfidence,
                'exactModelMatch'  => $modelMatch,
            ];
        }

        $confidence = 'low';
        if ($items !== []) {
            $best = $items[0]['confidence'] ?? 'low';
            $confidence = is_string($best) ? $best : 'low';
            foreach ($items as $item) {
                if (($item['confidence'] ?? '') === 'high') {
                    $confidence = 'high';
                    break;
                }
                if (($item['confidence'] ?? '') === 'medium' && $confidence === 'low') {
                    $confidence = 'medium';
                }
            }
        }

        return [
            'brand'              => $target['brand'],
            'model'              => $target['model'],
            'requestedFact'      => $target['requestedFact'],
            'manufacturerDomain' => $domain,
            'confidence'         => $confidence,
            'exactModelMatch'    => $exactModel && $anyModelMatch,
            'fromCache'          => $fromCache,
            'items'              => $items,
        ];
    }

    private static function inferSourceType(string $url, string $title): string
    {
        $blob = AssistantV2Catalog::fold($url . ' ' . $title);
        if (preg_match('/\b(manual|kullanim|user guide|instruction)\b/u', $blob) === 1) {
            return self::SOURCE_MANUFACTURER_MANUAL;
        }
        if (preg_match('/\b(datasheet|data sheet|spec sheet|technical data)\b/u', $blob) === 1) {
            return self::SOURCE_MANUFACTURER_DATASHEET;
        }
        if (preg_match('/\b(support|service|faq|help)\b/u', $blob) === 1) {
            return self::SOURCE_MANUFACTURER_SUPPORT;
        }

        return self::SOURCE_MANUFACTURER_PAGE;
    }

    private static function urlOnManufacturerDomain(string $url, string $domain): bool
    {
        if ($url === '') {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);
        $domain = strtolower($domain);

        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    /**
     * @param array<string, mixed> $evidence
     * @param array{brand:string,model:string,requestedFact:string} $target
     * @return array{block:string,debug:array<string,mixed>,evidence:array<string,mixed>|null}
     */
    private static function packResult(array $evidence, array $target, string $domain, bool $fromCache): array
    {
        $items = is_array($evidence['items'] ?? null) ? $evidence['items'] : [];
        if ($items === []) {
            return [
                'block'     => '',
                'debug'     => self::debugEnvelope(false, 'empty', $target, $domain, ['from_cache' => $fromCache]),
                'evidence'  => null,
            ];
        }

        $lines = [
            '[Harici üretici kanıtı — fiyat/stok/kondisyon için katalog önceliklidir]',
            'Marka: ' . $target['brand'],
            'Model: ' . ($target['model'] !== '' ? $target['model'] : '(belirtilmedi)'),
            'Aranan bilgi: ' . $target['requestedFact'],
        ];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $lines[] = '---';
            $lines[] = 'Kaynak: ' . (string) ($item['title'] ?? '');
            $lines[] = 'URL: ' . (string) ($item['url'] ?? '');
            $lines[] = 'sourceType: ' . (string) ($item['sourceType'] ?? '');
            $lines[] = 'confidence: ' . (string) ($item['confidence'] ?? 'low');
            $lines[] = trim((string) ($item['snippet'] ?? ''));
        }

        $block = '<EXTERNAL_EVIDENCE confidence="' . (string) ($evidence['confidence'] ?? 'low') . "\">\n"
            . implode("\n", $lines)
            . "\n</EXTERNAL_EVIDENCE>";

        return [
            'block'    => $block,
            'debug'    => self::debugEnvelope(true, 'ok', $target, $domain, [
                'from_cache'        => $fromCache,
                'confidence'        => $evidence['confidence'] ?? 'low',
                'exactModelMatch'   => ($evidence['exactModelMatch'] ?? false) === true,
                'provider'          => self::providerName(),
                'query'             => self::buildQuery($target, $domain),
                'item_count'        => count($items),
            ]),
            'evidence' => $evidence,
        ];
    }

    /**
     * @param array{brand:string,model:string,requestedFact:string}|null $target
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function debugEnvelope(bool $used, string $status, ?array $target, ?string $domain, array $extra = []): array
    {
        return array_merge([
            'used'    => $used,
            'status'  => $status,
            'enabled' => self::featureEnabled(),
            'target'  => $target,
            'domain'  => $domain,
        ], $extra);
    }

    private static function providerName(): string
    {
        $name = strtolower(trim((string) env('ASSISTANT_WEB_SEARCH_PROVIDER', 'stub')));

        return in_array($name, ['stub', 'serpapi', 'bing'], true) ? $name : 'stub';
    }

    public static function domainForBrand(string $brand): string
    {
        $config = self::manufacturerConfig();
        $fold = AssistantV2Catalog::fold($brand);
        foreach ($config['brands'] as $canonical => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (AssistantV2Catalog::fold((string) $canonical) === $fold) {
                $domains = is_array($row['domains'] ?? null) ? $row['domains'] : [];

                return trim((string) ($domains[0] ?? ''));
            }
            foreach (is_array($row['aliases'] ?? null) ? $row['aliases'] : [] as $alias) {
                if (AssistantV2Catalog::fold((string) $alias) === $fold) {
                    $domains = is_array($row['domains'] ?? null) ? $row['domains'] : [];

                    return trim((string) ($domains[0] ?? ''));
                }
            }
        }

        foreach (AssistantV2Catalog::brandsIn($brand) as $detected) {
            foreach ($config['brands'] as $canonical => $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (AssistantV2Catalog::fold((string) $canonical) === AssistantV2Catalog::fold($detected)) {
                    $domains = is_array($row['domains'] ?? null) ? $row['domains'] : [];

                    return trim((string) ($domains[0] ?? ''));
                }
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function manufacturerConfig(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }
        $path = __DIR__ . '/advisor_manufacturer_domains.json';
        if (!is_file($path)) {
            $cached = ['brands' => []];

            return $cached;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        $cached = is_array($decoded) ? $decoded : ['brands' => []];

        return $cached;
    }

    private static function cachePath(): string
    {
        return base_path('storage/assistant_v2/web_search_cache.json');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function cacheGet(string $key): ?array
    {
        $all = self::cacheLoadAll();
        $entry = $all[$key] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $fetched = (int) ($entry['fetched_at'] ?? 0);
        if ($fetched < 1 || (time() - $fetched) > self::cacheTtlSeconds()) {
            unset($all[$key]);
            self::cacheSaveAll($all);

            return null;
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function cachePut(string $key, array $entry): void
    {
        $all = self::cacheLoadAll();
        $all[$key] = $entry;
        if (count($all) > 200) {
            uasort($all, static fn (array $a, array $b): int => ((int) ($b['fetched_at'] ?? 0)) <=> ((int) ($a['fetched_at'] ?? 0)));
            $all = array_slice($all, 0, 200, true);
        }
        self::cacheSaveAll($all);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function cacheLoadAll(): array
    {
        $path = self::cachePath();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, array<string, mixed>> $all
     */
    private static function cacheSaveAll(array $all): void
    {
        $path = self::cachePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private static function cacheTtlSeconds(): int
    {
        $days = (int) env('ASSISTANT_WEB_SEARCH_CACHE_TTL_DAYS', 7);
        if ($days < 1) {
            $days = 7;
        }

        return $days * 86400;
    }
}
