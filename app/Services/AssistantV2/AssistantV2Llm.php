<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * OpenAI uyumlu JSON çağrısı. V1 kimlik metnini eklemez.
 * Anahtar ve adres mevcut PRODUCT_ASSISTANT_API_* ortamından okunur.
 */
final class AssistantV2Llm
{
    /** @var callable|null Test override: (system, user, timeout, task, jsonObject) => ?array */
    public static $testCompleteOverride = null;

    /**
     * @param bool $jsonObject Yanıt JSON nesnesi bekleniyorsa true. Freemode boş sistemde false (düz metin).
     */
    public static function complete(string $system, string $user, int $timeout, string $task, bool $jsonObject = true): ?array
    {
        if (self::$testCompleteOverride !== null) {
            $mock = (self::$testCompleteOverride)($system, $user, $timeout, $task, $jsonObject);

            return is_array($mock) ? $mock : null;
        }

        $key = trim((string) env('PRODUCT_ASSISTANT_API_KEY', ''));
        if ($key === '' || trim($user) === '') {
            return null;
        }

        // Boş sistem: kasıtlı boş freemode; API boş content reddediyorsa tek boşluk.
        $systemContent = trim($system) === '' ? ' ' : $system;

        $base = rtrim((string) env('PRODUCT_ASSISTANT_API_BASE', 'https://api.openai.com/v1'), '/');
        $payload = [
            'model'    => 'gpt-6-luna',
            'messages' => [
                ['role' => 'system', 'content' => $systemContent],
                ['role' => 'user', 'content' => $user],
            ],
        ];
        if ($jsonObject) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $ch = curl_init($base . '/chat/completions');
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(4, $timeout),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            logger('assistant_v2.llm_error', ['status' => $status, 'task' => $task], 'warning');

            return null;
        }

        logger('assistant_v2.llm', ['status' => $status, 'task' => $task]);
        $decoded = json_decode($raw, true);
        $content = is_array($decoded) ? ($decoded['choices'][0]['message']['content'] ?? null) : null;
        if (!is_string($content) || $content === '') {
            return null;
        }

        if (!$jsonObject) {
            return ['message' => trim($content)];
        }

        $json = json_decode($content, true);
        if (!is_array($json) && preg_match('/\{.*\}/su', $content, $match) === 1) {
            $json = json_decode($match[0], true);
        }

        return is_array($json) ? $json : null;
    }
}
