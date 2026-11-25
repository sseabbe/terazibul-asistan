<?php

declare(strict_types=1);

namespace App\Services\AssistantV3;

/**
 * OpenAI uyumlu chat/completions çağrısı, araç (tool) desteğiyle.
 * Anahtar, adres ve model .env'den: PRODUCT_ASSISTANT_API_KEY, PRODUCT_ASSISTANT_API_BASE, PRODUCT_ASSISTANT_MODEL.
 */
final class AssistantV3Llm
{
    /**
     * @param list<array<string,mixed>> $messages
     * @param list<array<string,mixed>> $tools
     * @return array{content:?string,tool_calls:list<array<string,mixed>>}|null
     */
    public static function chat(array $messages, array $tools, int $timeout): ?array
    {
        $key = trim((string) env('PRODUCT_ASSISTANT_API_KEY', ''));
        if ($key === '') {
            return null;
        }
        $base = rtrim((string) env('PRODUCT_ASSISTANT_API_BASE', 'https://api.openai.com/v1'), '/');
        $model = trim((string) env('PRODUCT_ASSISTANT_MODEL', 'gpt-6-luna')) ?: 'gpt-6-luna';

        $payload = ['model' => $model, 'messages' => $messages];
        // chat/completions + araç çağırma bu modelde yalnızca reasoning_effort=none ile çalışıyor
        $effort = trim((string) env('ASSISTANT_V3_REASONING', 'none'));
        if ($effort !== '') {
            $payload['reasoning_effort'] = $effort;
        }
        // Son cevap her zaman JSON olmalı (geçmişteki düz metin cevaplar modeli düz metne kaydırmasın)
        $payload['response_format'] = ['type' => 'json_object'];
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
            $payload['parallel_tool_calls'] = true;
        }

        $ch = curl_init($base . '/chat/completions');
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => max(6, $timeout),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $t0 = microtime(true);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ms = (int) round((microtime(true) - $t0) * 1000);

        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            logger('assistant_v3.llm_error', ['status' => $status, 'ms' => $ms, 'body' => is_string($raw) ? mb_substr($raw, 0, 300) : ''], 'warning');

            return null;
        }
        $decoded = json_decode($raw, true);
        $msg = is_array($decoded) ? ($decoded['choices'][0]['message'] ?? null) : null;
        if (!is_array($msg)) {
            return null;
        }
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        logger('assistant_v3.llm', [
            'ms'     => $ms,
            'tools'  => count($msg['tool_calls'] ?? []),
            'in'     => (int) ($usage['prompt_tokens'] ?? 0),
            'out'    => (int) ($usage['completion_tokens'] ?? 0),
        ]);

        return [
            'content'    => is_string($msg['content'] ?? null) ? $msg['content'] : null,
            'tool_calls' => is_array($msg['tool_calls'] ?? null) ? $msg['tool_calls'] : [],
        ];
    }
}
