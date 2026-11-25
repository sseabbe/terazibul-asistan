<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * File-backed RAG chunk index (no embeddings in phase 2 — BM25-like token scoring at query time).
 */
final class AssistantV2AdvisorRagStore
{
    private const INDEX_FILE = 'assistant_v2/rag_index.json';

    /** @var array{version:int,built_at:string,chunks:list<array<string,mixed>>}|null */
    private static ?array $memory = null;

    /**
     * @return array{version:int,built_at:string,chunks:list<array<string,mixed>>}
     */
    public static function load(): array
    {
        if (self::$memory !== null) {
            return self::$memory;
        }

        $path = storage_path(self::INDEX_FILE);
        if (!is_file($path)) {
            self::$memory = ['version' => 1, 'built_at' => '', 'chunks' => []];

            return self::$memory;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw) || !is_array($raw['chunks'] ?? null)) {
            self::$memory = ['version' => 1, 'built_at' => '', 'chunks' => []];

            return self::$memory;
        }

        self::$memory = [
            'version'   => (int) ($raw['version'] ?? 1),
            'built_at'  => (string) ($raw['built_at'] ?? ''),
            'chunks'    => array_values($raw['chunks']),
        ];

        return self::$memory;
    }

    /**
     * @param list<array<string, mixed>> $chunks
     */
    public static function save(array $chunks, string $builtAt = ''): void
    {
        $dir = storage_path('assistant_v2');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $payload = [
            'version'   => 1,
            'built_at'  => $builtAt !== '' ? $builtAt : gmdate('c'),
            'chunks'    => array_values($chunks),
        ];

        $path = storage_path(self::INDEX_FILE);
        file_put_contents(
            $path,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
        self::$memory = $payload;
    }

    public static function indexPath(): string
    {
        return storage_path(self::INDEX_FILE);
    }

    public static function clearMemoryCache(): void
    {
        self::$memory = null;
    }
}
