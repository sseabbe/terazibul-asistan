<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Services\AssistantV3\AssistantV3;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Asistan V3 demo: /demo/asistan-v3. Şimdilik yalnızca panele giriş yapmış yöneticiye açık.
 */
class AssistantV3Controller extends Controller
{
    public function show(Request $request): Response
    {
        if (!Session::has('auth_user_id')) {
            $this->notFound();
        }

        return $this->view('front/demo/asistan-v3', [
            'title'    => 'Asistan V3 (demo)',
            'endpoint' => url('/demo/asistan-v3/mesaj'),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** Demo ucu: yalnızca yönetici. */
    public function message(Request $request): Response
    {
        if (!Session::has('auth_user_id')) {
            return $this->json(['ok' => false, 'message' => 'Yetkisiz.'], 403);
        }

        return $this->handle($request);
    }

    /** Sitedeki asistan penceresinin ucu: /asistan/mesaj (herkese açık, hız sınırlı). */
    public function publicMessage(Request $request): Response
    {
        return $this->handle($request);
    }

    private function handle(Request $request): Response
    {
        // Oturum kilidini bırak: uzun LLM çağrısı sırasında diğer istekler beklemesin
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        set_time_limit(70);

        $message = $request->input('message', '');
        $message = is_string($message) ? $message : '';
        $history = [];
        $raw = $request->input('history', []);
        if (is_array($raw)) {
            foreach (array_slice($raw, -10) as $turn) {
                if (!is_array($turn)) {
                    continue;
                }
                $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
                $text = is_string($turn['text'] ?? null) ? trim($turn['text']) : '';
                if ($text !== '') {
                    $history[] = ['role' => $role, 'text' => mb_substr($text, 0, 700)];
                }
            }
        }
        $path = $request->input('page_path', '');
        $cid = $request->input('cid', '');
        $cid = is_string($cid) && preg_match('/^[a-f0-9]{16,32}$/', $cid) === 1 ? $cid : bin2hex(random_bytes(12));

        try {
            $state = $request->input('state', []);
            $select = $request->input('select', null);
            $payload = AssistantV3::turn(
                $message,
                $history,
                ['path' => is_string($path) ? $path : ''],
                $cid,
                is_array($state) ? $state : [],
                is_array($select) ? $select : null
            );
        } catch (\Throwable $e) {
            logger('assistant_v3.error', ['error' => $e->getMessage(), 'line' => $e->getLine(), 'file' => basename($e->getFile())], 'error');
            $payload = ['ok' => false, 'message' => 'Şu anda yanıt veremiyorum. Lütfen biraz sonra tekrar deneyin.', 'cards' => [], 'actions' => [], 'chips' => [], 'cid' => $cid];
        }

        return $this->json($payload);
    }
}
