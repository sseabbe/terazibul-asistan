<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

use App\Support\HataKodlariRepository;

/**
 * Hata kodu eşlemesi: normalize edilmiş exact kod. Substring final açıklama değildir.
 */
final class AssistantV2AdvisorErrorMatch
{
    /**
     * @param list<array<string, mixed>>|null $records
     * @return array{found:bool,query:string,tier:string,ambiguous:bool,matches:list<array<string,mixed>>}
     */
    public static function match(string $message, ?array $records = null): array
    {
        $query = self::extractCode($message);
        $empty = ['found' => false, 'query' => $query, 'tier' => '', 'ambiguous' => false, 'matches' => []];
        if ($query === '') {
            return $empty;
        }

        $records = $records ?? self::publishedRecords();
        $exact = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $code = self::normalizeCode((string) ($record['code'] ?? ''));
            if ($code === '' || $code !== $query) {
                continue;
            }
            $exact[] = $record;
        }
        if ($exact === []) {
            return $empty;
        }

        $fold = AssistantV2Catalog::fold($message);
        $brandScoped = self::narrow($exact, $fold, 'brand');
        $namedBrand = AssistantV2Catalog::mentionedBrand($message);
        if ($namedBrand !== null && $brandScoped === []) {
            return $empty;
        }
        $pool = $brandScoped !== [] ? $brandScoped : $exact;
        $series = self::narrow($pool, $fold, 'series');
        if ($series !== []) {
            return self::pack($query, 'series', $series);
        }
        if ($brandScoped !== []) {
            return self::pack($query, 'brand', $brandScoped);
        }

        return self::pack($query, 'code', $exact);
    }

    public static function normalizeCode(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ı' => 'i', 'ö' => 'o', 'ç' => 'c', 'İ' => 'i',
        ]);
        $value = (string) preg_replace('/[^a-z0-9.]+/u', '', $value);
        $value = (string) preg_replace('/(?<!\d)\.|\.(?!\d)/', '', $value);

        return $value;
    }

    public static function extractCode(string $message): string
    {
        if (preg_match('/\b((?:err|error|e)\s*[-.]?\s*\d+(?:\.\d+)?)\b/iu', $message, $match) !== 1) {
            return '';
        }

        return self::normalizeCode((string) $match[1]);
    }

    /**
     * @param list<array<string, mixed>> $matches
     */
    public static function evidenceBlock(array $matches, bool $ambiguous): string
    {
        if ($matches === []) {
            return '';
        }
        $lines = ['[Hata kodu exact eşleşme]'];
        if ($ambiguous) {
            $lines[] = 'Aynı kod birden fazla seri/modelde farklı anlam taşıyor. Seri belirtilmeden tek anlam yazma.';
        }
        foreach (array_slice($matches, 0, 4) as $row) {
            $lines[] = 'Kod: ' . trim((string) ($row['code'] ?? ''));
            $lines[] = 'Marka: ' . trim((string) ($row['brand'] ?? ''));
            $lines[] = 'Seri: ' . trim((string) ($row['series'] ?? ''));
            $meaning = trim((string) ($row['meaning'] ?? $row['shortDescription'] ?? $row['title'] ?? ''));
            if ($meaning !== '') {
                $lines[] = 'Anlam: ' . $meaning;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function publishedRecords(): array
    {
        $repo = new HataKodlariRepository();
        $out = [];
        foreach ($repo->errors() as $error) {
            if (!is_array($error) || !$repo->isPublishable($error)) {
                continue;
            }
            $out[] = $error;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $record
     * @return list<string>
     */
    private static function brandNeedles(array $record): array
    {
        $out = [];
        foreach ([(string) ($record['brand'] ?? ''), (string) ($record['brandSlug'] ?? '')] as $value) {
            $fold = AssistantV2Catalog::fold($value);
            if ($fold !== '') {
                $out[] = $fold;
            }
            foreach (preg_split('/[^a-z0-9]+/u', $fold) ?: [] as $part) {
                if (is_string($part) && $part !== '') {
                    $out[] = $part;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private static function narrow(array $records, string $fold, string $kind): array
    {
        $hits = [];
        foreach ($records as $record) {
            $needles = $kind === 'brand'
                ? self::brandNeedles($record)
                : array_merge(
                    [(string) ($record['series'] ?? ''), (string) ($record['seriesSlug'] ?? '')],
                    is_array($record['models'] ?? null) ? $record['models'] : []
                );
            foreach ($needles as $needle) {
                $token = AssistantV2Catalog::fold((string) $needle);
                if ($token === '' || mb_strlen($token) < 3) {
                    continue;
                }
                $quoted = preg_quote($token, '/');
                if (preg_match('/(^| )' . $quoted . '($| )/u', $fold) === 1) {
                    $hits[] = $record;
                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * @param list<array<string, mixed>> $matches
     * @return array{found:bool,query:string,tier:string,ambiguous:bool,matches:list<array<string,mixed>>}
     */
    private static function pack(string $query, string $tier, array $matches): array
    {
        $meanings = [];
        foreach ($matches as $row) {
            $meanings[AssistantV2Catalog::fold((string) ($row['meaning'] ?? $row['title'] ?? ''))] = true;
        }

        return [
            'found'      => true,
            'query'      => $query,
            'tier'       => $tier,
            'ambiguous'  => $tier === 'code' && count($meanings) > 1,
            'matches'    => array_values($matches),
        ];
    }
}
