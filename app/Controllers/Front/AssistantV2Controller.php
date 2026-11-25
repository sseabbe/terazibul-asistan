<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Services\AssistantV2\AssistantV2;
use App\Services\ProductAssistant\AssistantLead;
use App\Services\ProductAssistant\AssistantSupport;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * Asistan V2 deneme sayfası. V1 sohbetine ve yüzen WhatsApp ikonuna bağlı değildir.
 */
class AssistantV2Controller extends Controller
{
    public function show(Request $request): Response
    {
        $debug = self::clientDebugAllowed($request);
        $html = $this->view('front/demo/asistan-v2', [
            'endpoint' => url('/demo/asistan-v2/mesaj'),
            'support'  => url('/demo/asistan-v2/destek'),
            'debug'    => $debug,
        ]);

        return $html->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function message(Request $request): Response
    {
        set_time_limit(75);
        $message = $request->input('message', '');
        if (!is_string($message)) {
            $message = '';
        }
        $ui = $request->input('ui_action', '');
        if (!is_string($ui) || !in_array($ui, ['more', 'expert', 'compare'], true)) {
            $ui = '';
        }
        $pageBrand = $request->input('page_brand', '');
        if (!is_string($pageBrand)) {
            $pageBrand = '';
        }
        $pageCategory = $request->input('page_category', '');
        if (!is_string($pageCategory) || preg_match('/^[a-z0-9-]{0,80}$/', $pageCategory) !== 1) {
            $pageCategory = '';
        }
        $state = $request->input('state', []);
        if (!is_array($state)) {
            $state = [];
        }

        try {
            $payload = AssistantV2::turn(
                $message,
                $state,
                $this->history($request->input('history', [])),
                ['brand' => $pageBrand, 'category' => $pageCategory],
                $ui
            );
        } catch (\Throwable $e) {
            logger('assistant_v2.error', ['error' => $e->getMessage()], 'error');
            $payload = [
                'message' => 'Şu anda kayıtlara bakılamıyor. Biraz sonra yeniden deneyin.',
                'cards'   => [],
                'total'   => 0,
                'actions' => [],
                'state'   => [],
                'debug'   => ['escalation_reason' => 'error'],
            ];
        }

        if (!self::clientDebugAllowed($request)) {
            unset($payload['debug']);
        }

        return $this->assistantJson($payload);
    }

    public function support(Request $request): Response
    {
        $action = $request->input('action', '');
        if (!is_string($action)) {
            $action = '';
        }
        $visitor = AssistantSupport::visitorToken();
        $public = $request->input('token', '');
        if (!is_string($public)) {
            $public = '';
        }
        $question = $request->input('question', '');
        if (!is_string($question)) {
            $question = '';
        }
        $note = $request->input('note', '');
        if (!is_string($note)) {
            $note = '';
        }
        $ids = [];
        $rawIds = $request->input('product_ids', []);
        if (is_array($rawIds)) {
            foreach (array_slice($rawIds, 0, 4) as $id) {
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $ids[] = (int) $id;
                }
            }
        }

        try {
            if ($action === 'whatsapp') {
                $url = AssistantSupport::whatsappUrl($question, $ids);
                $payload = [
                    'ok' => is_string($url) && preg_match('#\Ahttps://wa\.me/#', $url) === 1,
                    'whatsapp_url' => $url,
                ];
            } elseif ($action === 'lead') {
                $phone = $request->input('phone', '');
                $payload = AssistantLead::whatsappHandoff(
                    is_string($phone) ? $phone : '',
                    $question,
                    $ids,
                    $this->history($request->input('history', []))
                );
            } elseif ($action === 'start') {
                $phone = $request->input('phone', '');
                $payload = AssistantSupport::start($visitor, $question, $ids, $this->history($request->input('history', [])), $note, is_string($phone) ? $phone : '');
            } elseif ($action === 'message') {
                $text = $request->input('message', '');
                $payload = AssistantSupport::addUserMessage($visitor, $public, is_string($text) ? $text : '');
            } elseif ($action === 'poll') {
                $after = $request->input('after_id', 0);
                $after = is_int($after) || (is_string($after) && ctype_digit($after)) ? (int) $after : 0;
                $payload = AssistantSupport::poll($visitor, $public, $after);
            } else {
                $payload = ['ok' => false, 'message' => 'İstek anlaşılamadı.'];
            }
        } catch (\Throwable $e) {
            logger('assistant_v2.support', ['error' => $e->getMessage()], 'error');
            $payload = ['ok' => false, 'message' => 'Şu anda iletemedik. Biraz sonra yeniden deneyin.'];
        }

        return $this->assistantJson($payload);
    }

    /**
     * Public debug çıktısı production'da kapalıdır. APP_DEBUG tek başına yetmez.
     * local/development/testing ve yalnız loopback: APP_DEBUG veya ?debug=1.
     * Public host (APP_ENV=local olsa bile): yalnız ASSISTANT_V2_DEBUG_TOKEN ile ve ?debug=1.
     */
    public static function clientDebugAllowed(Request $request): bool
    {
        return self::debugAllowedFor(
            (string) config('app.env', 'production'),
            (bool) config('app.debug'),
            (string) $request->query('debug', '') === '1',
            (string) env('ASSISTANT_V2_DEBUG_TOKEN', ''),
            self::providedDebugToken($request),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    /**
     * Public internet is closed even when APP_ENV=local or APP_DEBUG=true.
     * Loopback + local/development/testing keeps the existing debug switch.
     * Any other host needs ASSISTANT_V2_DEBUG_TOKEN together with ?debug=1.
     */
    public static function debugAllowedFor(
        string $env,
        bool $appDebug,
        bool $debugQuery,
        string $configuredToken,
        string $providedToken,
        string $remoteAddr = '',
    ): bool {
        $env = strtolower(trim($env));
        $devEnv = in_array($env, ['local', 'development', 'dev', 'testing', 'test'], true);
        $remote = strtolower(trim($remoteAddr));
        if (str_starts_with($remote, '::ffff:')) {
            $remote = substr($remote, 7);
        }
        $loopback = $remote === '127.0.0.1' || $remote === '::1';

        if ($devEnv && $loopback) {
            return $appDebug || $debugQuery;
        }

        if (!$debugQuery) {
            return false;
        }

        $configured = trim($configuredToken);
        $provided = trim($providedToken);
        if ($configured === '' || $provided === '' || strlen($configured) !== strlen($provided)) {
            return false;
        }

        return hash_equals($configured, $provided);
    }

    private static function providedDebugToken(Request $request): string
    {
        $header = $request->header('X-Assistant-Debug-Token');
        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }
        $query = $request->query('debug_token', '');

        return is_string($query) ? trim($query) : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assistantJson(array $payload): Response
    {
        $visitor = AssistantSupport::visitorToken();
        $fresh = (($_COOKIE[AssistantSupport::COOKIE] ?? '') !== $visitor);
        $response = $this->json($payload, 200, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag'  => 'noindex, nofollow',
        ]);
        if ($fresh) {
            $response->header('Set-Cookie', AssistantSupport::cookieHeader($visitor));
        }

        return $response;
    }

    /**
     * @return list<array{role:string,text:string}>
     */
    private function history(mixed $history): array
    {
        if (!is_array($history)) {
            return [];
        }
        $out = [];
        foreach (array_slice($history, -8) as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > 400) {
                $text = mb_substr($text, 0, 400);
            }
            $out[] = [
                'role' => ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                'text' => $text,
            ];
        }

        return $out;
    }
}
