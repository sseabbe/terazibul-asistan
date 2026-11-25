<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Serbest sohbet: tek LLM çağrısı, yalnızca güncel mesaj; geçmiş/bellek/katalog yok.
 */
final class AssistantV2Freemode
{
    /**
     * @param array<string, mixed> $state
     * @param list<array{role:string,text:string}> $history
     * @param array{brand?:string,category?:string} $page
     * @return array<string, mixed>
     */
    public static function turn(string $message, array $state, array $history, array $page = [], string $uiAction = ''): array
    {
        unset($page, $state, $history);

        if ($uiAction === 'more') {
            return self::envelope(
                'Bu sohbet modunda sayfalama yok. Sorunuzu yazmaya devam edebilirsiniz.'
            );
        }
        if ($message === '') {
            return self::envelope('Mesaj boş görünüyor. Nasıl yardımcı olabilirim?');
        }

        $llm = self::callLlm($message);
        $text = is_array($llm) ? trim((string) ($llm['message'] ?? '')) : '';
        if ($text === '' && is_array($llm)) {
            foreach (['reply', 'answer', 'content', 'text'] as $key) {
                $candidate = trim((string) ($llm[$key] ?? ''));
                if ($candidate !== '') {
                    $text = $candidate;
                    break;
                }
            }
        }
        if ($text === '') {
            $text = 'Şu anda yanıt oluşturulamadı. Biraz sonra tekrar deneyebilir misiniz?';
        }
        $text = trim(str_replace(['**', '__', '`'], '', $text));

        logger('assistant_v2.freemode', [
            'user_message' => mb_substr($message, 0, 120),
            'llm_ok' => is_array($llm),
            'history_turns' => 0,
        ]);

        return self::envelope($text);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function callLlm(string $message): ?array
    {
        return AssistantV2Llm::complete(AssistantV2Prompt::freemodeVoice(), $message, 22, 'v2_freemode', false);
    }

    private static function envelope(string $message): array
    {
        return [
            'message' => $message,
            'cards' => [],
            'total' => 0,
            'more' => null,
            'actions' => [],
            'state' => [],
            'product_ids' => [],
            'mode' => '',
            'write_mode' => 'answer',
            'cards_attached' => false,
            'needs_whatsapp' => false,
            'show_products_button' => false,
            'debug' => [
                'freemode' => true,
                'final_response_generated' => $message !== '',
            ],
        ];
    }
}
