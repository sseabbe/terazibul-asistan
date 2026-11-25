<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AssistantV2\AssistantV2AdvisorRagIndexer;
use App\Services\AssistantV2\AssistantV2AdvisorRagStore;

/**
 * Tek seferlik advisor RAG indeks oluşturma (sunucu DB erişimi).
 */
final class AssistantRagIndexOnce
{
    private const TOKEN_PURPOSE = 'assistant-rag-index-once';

    public static function expectedHttpToken(): string
    {
        $key = (string) env('APP_KEY', '');
        if (str_starts_with($key, 'base64:')) {
            $raw = base64_decode(substr($key, 7), true);
            $key = is_string($raw) ? $raw : '';
        }
        if ($key === '') {
            return '';
        }

        return hash_hmac('sha256', self::TOKEN_PURPOSE, $key);
    }

    public static function verifyHttpToken(string $token): bool
    {
        $expected = self::expectedHttpToken();
        if ($expected === '' || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(bool $force = false): array
    {
        $stats = AssistantV2AdvisorRagIndexer::rebuild($force);
        $path = AssistantV2AdvisorRagStore::indexPath();

        return [
            'stats' => $stats,
            'path' => $path,
            'bytes' => is_file($path) ? filesize($path) : 0,
        ];
    }
}
