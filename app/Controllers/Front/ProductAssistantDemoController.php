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
 * Canlı site launcher bu uçları kullanır. Mesaj V2 beynine gider; destek aktarımı AssistantSupport'ta kalır.
 * /demo/asistan-v2 ayrı deneme sayfasıdır.
 */
class ProductAssistantDemoController extends Controller
{
    public function show(Request $request): Response
    {
        $html = $this->view('front/demo/urun-asistani', [
            'endpoint' => url('/demo/urun-asistani/mesaj'),
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

        $freemode = AssistantV2::freemodeEnabled();

        $previous = $request->input('previous_product_ids', []);
        $ids = [];
        if (!$freemode && is_array($previous)) {
            foreach (array_slice($previous, 0, 8) as $id) {
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $ids[] = (int) $id;
                }
            }
        }

        $pageBrand = $request->input('page_brand', '');
        if (!is_string($pageBrand)) {
            $pageBrand = '';
        }
        $pageCategory = $request->input('page_category', '');
        if (!is_string($pageCategory) || preg_match('/^[a-z0-9-]{2,60}$/', $pageCategory) !== 1) {
            $pageCategory = '';
        }
        $offset = $request->input('offset', 0);
        $offset = is_int($offset) || (is_string($offset) && ctype_digit($offset)) ? (int) $offset : 0;
        if ($offset < 0 || $offset > 40) {
            $offset = 0;
        }
        $state = $freemode ? [] : $this->v2State($request->input('memory', []));
        if (!$freemode && ($state['focus_ids'] ?? []) === [] && $ids !== []) {
            $state['focus_ids'] = $ids;
        }
        $ui = $request->input('ui_action', '');
        if (!is_string($ui)) {
            $ui = '';
        }
        $ui = trim($ui);
        if ($freemode) {
            $ui = '';
            $offset = 0;
        } elseif ($ui === '' && $offset > 0 && $this->stateHasConstraint($state)) {
            $state['offset'] = $offset;
            $state['has_more'] = true;
            $ui = 'more';
        }
        if (!in_array($ui, ['', 'more', 'expert', 'show_products'], true)) {
            $ui = '';
        }
        $history = $freemode ? [] : $this->history($request->input('history', []));

        try {
            $payload = $this->livePayload(AssistantV2::turn(
                $message,
                $state,
                $history,
                ['brand' => $pageBrand, 'category' => $pageCategory],
                $ui
            ), $message);
        } catch (\Throwable $e) {
            logger('product_assistant.error', ['error' => $e->getMessage()], 'error');
            $payload = [
                'message'        => 'Şu anda ürün kayıtlarına bakılamıyor. Biraz sonra yeniden deneyin.',
                'cards'          => [],
                'total'          => 0,
                'more'           => null,
                'actions'        => [],
                'needs_whatsapp' => false,
                'whatsapp'       => null,
                'prompts'        => null,
                'product_ids'    => [],
                'memory'         => [],
            ];
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
                $payload = ['ok' => true, 'whatsapp_url' => AssistantSupport::whatsappUrl($question, $ids)];
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
            logger('product_assistant.support', ['error' => $e->getMessage()], 'error');
            $payload = ['ok' => false, 'message' => 'Şu anda iletemedik. Biraz sonra yeniden deneyin.'];
        }

        return $this->assistantJson($payload);
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
     * Launcher `memory` alanıyla V2 durumunu taşır. Eski sıralama belleği yok sayılır.
     *
     * @return array<string, mixed>
     */
    private function v2State(mixed $memory): array
    {
        if (!is_array($memory) || !is_array($memory['filters'] ?? null)) {
            return [];
        }

        return $memory;
    }

    private function listingMoreUrl(string $url): string
    {
        $url = trim($url);
        $path = parse_url($url, PHP_URL_PATH);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $schemeOk = $scheme === null || in_array(strtolower((string) $scheme), ['http', 'https'], true);
        if ($schemeOk && is_string($path) && preg_match('#/(?:urunler|products)(?:/|$)#', $path) === 1) {
            return $url;
        }

        return seg_url('/urunler');
    }

    /**
     * @param array<string, mixed> $state
     */
    private function stateHasConstraint(array $state): bool
    {
        $filters = $state['filters'] ?? null;
        if (!is_array($filters)) {
            return false;
        }
        foreach (['category', 'subcategory', 'calibration'] as $key) {
            if (is_string($filters[$key] ?? null) && $filters[$key] !== '') {
                return true;
            }
        }
        if (is_array($filters['brands'] ?? null) && $filters['brands'] !== []) {
            return true;
        }
        $precision = $filters['precision'] ?? null;
        if (is_array($precision) && is_numeric($precision['value'] ?? null)) {
            return true;
        }
        $capacity = $filters['capacity'] ?? null;
        if (is_array($capacity) && (is_numeric($capacity['value_g'] ?? null) || is_numeric($capacity['min_g'] ?? null))) {
            return true;
        }
        if (is_string($filters['capacity_slug'] ?? null) && $filters['capacity_slug'] !== '') {
            return true;
        }

        return is_bool($filters['commercial_approval'] ?? null);
    }

    /**
     * V2 zarfını canlı launcher'ın çizdiği alanlara indirger.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function livePayload(array $payload, string $question): array
    {
        $siteOnly = is_array($payload['debug'] ?? null) && ($payload['debug']['site_only'] ?? false) === true;
        $freemode = is_array($payload['debug'] ?? null) && ($payload['debug']['freemode'] ?? false) === true;
        $advisor = is_array($payload['debug'] ?? null) && ($payload['debug']['advisor'] ?? false) === true;
        $noProductUi = $siteOnly || $freemode || $advisor;
        $responseMode = (string) ($payload['mode'] ?? '');
        $writeMode = (string) ($payload['write_mode'] ?? '');
        $cardsAttached = ($payload['cards_attached'] ?? false) === true;
        $nonListingModes = ['clarify', 'spec', 'guidance', 'uncertainty', 'decisionGuidance', 'gap', 'callback', 'empty', 'unlisted', 'no_price', 'count', 'compare', 'rank', 'lookup'];
        $allowCards = !$noProductUi
            && $cardsAttached
            && ($responseMode === '' || in_array($responseMode, ['list', 'near'], true))
            && ($writeMode === '' || !in_array($writeMode, $nonListingModes, true));

        $cards = [];
        foreach (is_array($payload['cards'] ?? null) ? $payload['cards'] : [] as $card) {
            if (!$allowCards) {
                break;
            }
            if (!is_array($card)) {
                continue;
            }
            $capacity = trim((string) ($card['capacity'] ?? ''));
            $precision = trim((string) ($card['precision'] ?? ''));
            $bits = array_values(array_filter([$capacity, $precision], static fn (string $bit): bool => $bit !== ''));
            $meta = trim((string) ($card['meta'] ?? ''));
            if ($meta === '' && $bits !== []) {
                $meta = implode(' / ', $bits);
            }
            $cards[] = [
                'id'    => (int) ($card['id'] ?? 0),
                'name'  => (string) ($card['name'] ?? ''),
                'order' => (int) ($card['order'] ?? 0),
                'image' => (string) ($card['image'] ?? ''),
                'meta'  => $meta,
                'badge' => (string) ($card['badge'] ?? ''),
                'url'   => (string) ($card['url'] ?? ''),
            ];
            if (count($cards) >= 5) {
                break;
            }
        }

        [$actions, $prompts, $needsExpert] = $this->liveActions(is_array($payload['actions'] ?? null) ? $payload['actions'] : []);
        $state = is_array($payload['state'] ?? null) ? $payload['state'] : [];
        $productIds = [];
        foreach (is_array($payload['product_ids'] ?? null) ? $payload['product_ids'] : [] as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $id = (int) $id;
                if ($id > 0) {
                    $productIds[] = $id;
                }
            }
        }
        if ($productIds === []) {
            foreach ($cards as $card) {
                if ($card['id'] > 0) {
                    $productIds[] = $card['id'];
                }
            }
        }
        if ($productIds === [] && is_array($state['focus_ids'] ?? null)) {
            foreach ($state['focus_ids'] as $id) {
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $id = (int) $id;
                    if ($id > 0) {
                        $productIds[] = $id;
                    }
                }
            }
        }
        $productIds = array_slice(array_values(array_unique($productIds)), 0, 8);

        $more = null;
        if ($allowCards && is_array($payload['more'] ?? null)) {
            $label = trim((string) ($payload['more']['label'] ?? ''));
            if ($label !== '') {
                $more = [
                    'label' => $label,
                    'url'   => $this->listingMoreUrl((string) ($payload['more']['url'] ?? '')),
                    'count' => (int) ($payload['more']['count'] ?? $payload['total'] ?? 0),
                ];
            }
        }

        if ($noProductUi) {
            $cards = [];
            $more = null;
        }

        $showProductsButton = ($payload['show_products_button'] ?? false) === true;
        $needsWhatsapp = ($payload['needs_whatsapp'] ?? false) === true;
        if (!$needsWhatsapp) {
            foreach ($actions as $action) {
                if (in_array($action['type'] ?? '', ['whatsapp_write', 'handoff_whatsapp', 'price_request'], true)) {
                    $needsWhatsapp = true;
                    break;
                }
            }
        }
        $whatsapp = null;
        if ($needsWhatsapp) {
            foreach ($actions as $action) {
                $url = (string) ($action['url'] ?? '');
                if (preg_match('#\Ahttps://wa\.me/#', $url) !== 1) {
                    continue;
                }
                $whatsapp = [
                    'label' => trim((string) ($action['label'] ?? '')) !== '' ? (string) $action['label'] : 'Hemen Destek Al',
                    'url'   => $url,
                ];
                break;
            }
            if ($whatsapp === null) {
                try {
                    $built = \App\Services\ProductAssistant\AssistantSupport::whatsappUrl(
                        $question !== '' ? $question : 'Fiyat bilgisi almak istiyorum.',
                        array_slice($productIds, 0, 4)
                    );
                } catch (\Throwable) {
                    $built = null;
                }
                if (is_string($built) && preg_match('#\Ahttps://wa\.me/#', $built) === 1) {
                    $whatsapp = ['label' => 'Hemen Destek Al', 'url' => $built];
                }
            }
        }

        $out = [
            'message'              => (string) ($payload['message'] ?? ''),
            'cards'                => $cards,
            'total'                => $noProductUi ? 0 : (int) ($payload['total'] ?? count($cards)),
            'more'                 => $more,
            'actions'              => $actions,
            'prompts'              => $prompts !== [] ? $prompts : null,
            'product_ids'          => $freemode ? [] : $productIds,
            'memory'               => $freemode ? [] : $state,
            'needs_whatsapp'       => $needsWhatsapp,
            'whatsapp'             => $whatsapp,
            'show_products_button' => $showProductsButton,
        ];
        if ($freemode) {
            $out['freemode'] = true;
        }
        if ($cards !== [] || $showProductsButton || $productIds !== []) {
            $out['topic'] = 'product';
        }
        if ($needsExpert || $this->hasHandoff($actions)) {
            $out['expert'] = $this->expertContext($question, $productIds, is_array($payload['actions'] ?? null) ? $payload['actions'] : []);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $raw
     * @return array{0:list<array<string, mixed>>,1:list<string>,2:bool}
     */
    private function liveActions(array $raw): array
    {
        $prompts = [];
        $actions = [];
        $hasExpert = false;

        foreach ($raw as $action) {
            if (!is_array($action)) {
                continue;
            }
            $type = (string) ($action['type'] ?? '');
            $label = trim((string) ($action['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 80) {
                continue;
            }
            $role = ($action['role'] ?? '') === 'primary' ? 'primary' : 'secondary';

            if ($type === 'prompt') {
                $prompt = trim((string) ($action['prompt'] ?? $label));
                if ($prompt !== '' && mb_strlen($prompt) <= 80) {
                    $prompts[] = $prompt;
                }
                continue;
            }
            if ($type === 'compare') {
                $prompt = trim((string) ($action['prompt'] ?? $label));
                $actions[] = [
                    'type'   => 'prompt',
                    'role'   => 'secondary',
                    'label'  => $label,
                    'prompt' => $prompt !== '' ? $prompt : $label,
                    'url'    => '',
                ];
                continue;
            }
            if (in_array($type, ['whatsapp_write', 'handoff_whatsapp'], true)) {
                $url = (string) ($action['url'] ?? '');
                if (preg_match('#\Ahttps://wa\.me/#', $url) === 1) {
                    $actions[] = [
                        'type'  => 'whatsapp_write',
                        'role'  => ($action['role'] ?? '') === 'secondary' ? 'secondary' : 'primary',
                        'label' => $label !== '' ? $label : "WhatsApp'tan Yazın",
                        'url'   => $url,
                    ];
                }
                continue;
            }
            if ($type === 'handoff_chat') {
                $actions[] = [
                    'type'  => 'handoff_chat',
                    'role'  => $role,
                    'label' => $label,
                    'url'   => '',
                ];
                continue;
            }
            if (in_array($type, ['phone_lead', 'expert'], true)) {
                continue;
            }
            if ($type === 'price_request') {
                $url = (string) ($action['url'] ?? '');
                if (preg_match('#\Ahttps://wa\.me/#', $url) === 1) {
                    $actions[] = [
                        'type'  => 'price_request',
                        'role'  => 'primary',
                        'label' => $label !== '' ? $label : 'Hemen Fiyat Al',
                        'url'   => $url,
                    ];
                }
                continue;
            }
            if ($type === 'whatsapp') {
                continue;
            }
            if ($type === 'phone') {
                $url = (string) ($action['url'] ?? '');
                if (str_starts_with($url, 'tel:')) {
                    $actions[] = [
                        'type'  => 'phone',
                        'role'  => $role,
                        'label' => $label,
                        'url'   => $url,
                    ];
                }
                continue;
            }
            if ($type === 'service_form') {
                $url = (string) ($action['url'] ?? '');
                if (preg_match('#\Ahttps://#', $url) === 1 || str_starts_with($url, '/')) {
                    $actions[] = [
                        'type'  => 'service_form',
                        'role'  => $role,
                        'label' => $label,
                        'url'   => $url,
                    ];
                }
                continue;
            }
            if ($type === 'page_link') {
                // Yalnızca site içi sayfalar (ör. hata kodu sayfası)
                $url = trim((string) ($action['url'] ?? ''));
                $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
                $own = (string) (parse_url((string) url('/'), PHP_URL_HOST) ?? '');
                if ($url !== '' && (str_starts_with($url, '/') || ($host !== '' && $host === $own))) {
                    $actions[] = [
                        'type'  => 'page_link',
                        'role'  => $role,
                        'label' => $label,
                        'url'   => $url,
                    ];
                }
                continue;
            }
            if ($type === 'show_products') {
                $url = trim((string) ($action['url'] ?? ''));
                if ($url !== '' && (preg_match('#\Ahttps://#', $url) === 1 || str_starts_with($url, '/'))) {
                    $actions[] = [
                        'type'  => 'show_products',
                        'role'  => ($action['role'] ?? '') === 'secondary' ? 'secondary' : 'primary',
                        'label' => $label !== '' ? $label : 'Ürünleri Gör',
                        'url'   => $url,
                    ];
                }
            }
        }

        $prompts = array_values(array_unique(array_slice($prompts, 0, 24)));

        return [$actions, $prompts, $hasExpert];
    }

    /**
     * @param list<array<string, mixed>> $actions
     */
    private function hasHandoff(array $actions): bool
    {
        foreach ($actions as $action) {
            if (in_array($action['type'] ?? '', ['phone_lead', 'expert', 'expert_chat', 'expert_whatsapp', 'handoff_chat', 'handoff_whatsapp', 'whatsapp_write', 'phone'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $productIds
     * @param list<array<string, mixed>> $rawActions
     * @return array{question:string,product_ids:list<int>,whatsapp_url:?string}
     */
    private function expertContext(string $question, array $productIds, array $rawActions): array
    {
        $question = trim(preg_replace('/\s+/u', ' ', $question) ?? '');
        if (mb_strlen($question) > 500) {
            $question = mb_substr($question, 0, 500);
        }
        $url = null;
        foreach ($rawActions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $candidate = (string) ($action['url'] ?? '');
            if (preg_match('#\Ahttps://wa\.me/#', $candidate) === 1) {
                $url = $candidate;
                break;
            }
        }
        if ($url === null) {
            try {
                $built = AssistantSupport::whatsappUrl($question !== '' ? $question : 'Bilgi almak istiyorum.', array_slice($productIds, 0, 4));
            } catch (\Throwable) {
                $built = null;
            }
            if (is_string($built) && preg_match('#\Ahttps://wa\.me/#', $built) === 1) {
                $url = $built;
            }
        }

        return [
            'question'     => $question,
            'product_ids'  => array_slice($productIds, 0, 4),
            'whatsapp_url' => $url,
        ];
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
        foreach (array_slice($history, -6) as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $text = $turn['text'] ?? '';
            if (!is_string($text)) {
                continue;
            }
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > 300) {
                $text = mb_substr($text, 0, 300);
            }
            $out[] = [
                'role' => ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                'text' => $text,
            ];
        }

        return $out;
    }
}
