<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Asistan V2. Model niyeti seçer ve cümleyi yazar. Katalog, sıralama ve CTA backend'dedir.
 * Konuşma metni AssistantV2Prompt içindedir.
 *
 * Mod seçimi (turn() sırası):
 * - ASSISTANT_V2_ADVISOR=1 (varsayılan): AssistantV2Advisor — analiz + katalog araması + bellek.
 * - ASSISTANT_V2_ADVISOR=0 ve ASSISTANT_V2_FREEMODE=1: AssistantV2Freemode — tek LLM, bellek yok.
 * - ADVISOR=0, FREEMODE=0 ve ASSISTANT_V2_SITE_ONLY=1: AssistantV2SiteOnly.
 * - Hepsi kapalı: legacy V2 (planlayıcı).
 */
final class AssistantV2
{
    /** @deprecated AssistantV2Prompt::FREE_LLM_MODE kullanın; geriye dönük okuma için. */
    public const FREE_LLM_MODE = true;

    private const USAGE_WRITE = ' displayed, product, products ve winner içindeki usages kategori kayıtlarıdır. Birden fazla kullanım alanı varsa hepsini söyle. search_filter kullanıcının arama kısıtıdır, ürünün kategorisi değildir. Özelliklerdeki tek satır usages listesini daraltmaz. usages içinde olan bir alanı doğrulanamadı diye kapatma. Özellikte yazmayan bir alanı yok kabul etme.';

    public static function advisorEnabled(): bool
    {
        $raw = env('ASSISTANT_V2_ADVISOR', null);
        if ($raw !== null && $raw !== '') {
            return !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
        }

        return true;
    }

    public static function freemodeEnabled(): bool
    {
        if (self::advisorEnabled()) {
            return false;
        }
        $raw = env('ASSISTANT_V2_FREEMODE', null);
        if ($raw !== null && $raw !== '') {
            return !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
        }

        return true;
    }

    public static function siteOnlyMode(): bool
    {
        $raw = env('ASSISTANT_V2_SITE_ONLY', null);
        if ($raw !== null && $raw !== '') {
            return !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
        }

        return true;
    }

    public static function freeLlmMode(): bool
    {
        return AssistantV2Prompt::freeLlmMode();
    }

    /**
     * @param array<string, mixed> $state
     * @param list<array{role:string,text:string}> $history
     * @param array{brand?:string,category?:string} $page
     * @return array<string, mixed>
     */
    public static function turn(string $message, array $state, array $history, array $page = [], string $uiAction = ''): array
    {
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');
        if (mb_strlen($message) > 500) {
            $message = mb_substr($message, 0, 500);
        }
        if (self::advisorEnabled()) {
            return AssistantV2Advisor::turn($message, $state, $history, $page, $uiAction);
        }
        if (self::freemodeEnabled()) {
            return AssistantV2Freemode::turn($message, $state, $history, $page, $uiAction);
        }
        if (self::siteOnlyMode()) {
            return AssistantV2SiteOnly::turn($message, $state, $history, $page, $uiAction);
        }
        $state = self::state($state);
        $state['dialog_corpus'] = self::userCorpus($message, $history);

        if ($uiAction === 'expert') {
            return self::expertChoice($message, $state);
        }
        if ($uiAction === 'more') {
            $result = self::runSearch($state, (int) $state['offset'], 'list', false);
            $result['facts']['mode'] = 'more';
            $result['facts']['continuation'] = true;

            return self::finish('', $history, $state, $result);
        }
        if ($message === '') {
            return self::envelope('Mesaj boş görünüyor. Nasıl yardımcı olabilirim?', [], $state, self::debug($message, [], $state, [], 'none', 0, [], [], [], null), []);
        }
        if (self::freeLlmMode()) {
            try {
                return self::freeTurn($message, $history, $state, $page, $uiAction);
            } catch (\Throwable $e) {
                logger('assistant_v2.turn', ['user_message' => self::redact($message), 'response_failure_reason' => 'free_turn_exception', 'final_response_generated' => true], 'error');

                return self::failure($message, $state, 'free_turn_exception');
            }
        }
        $sampleGoal = self::resolveSampleGoalAnswer($message, $state);
        if ($sampleGoal === 'moisture') {
            $opened = self::openMoistureCatalog($state);
            if ($opened !== null) {
                return self::finish($message, $history, $state, $opened);
            }
        } elseif ($sampleGoal === 'scale') {
            $roots = AssistantV2Catalog::usageRoots();
            if ($roots !== []) {
                return self::finish($message, $history, $state, self::rootClarify($state, self::emptyPlan(), $roots, ''));
            }
        }
        if (
            AssistantV2Prompt::conversational()
            && self::vagueSampleUtterance($message, $history, $state)
            && !self::wantsProductCards($message)
            && !self::nemIntentConfirmed($message, $history, $state)
        ) {
            return self::finish($message, $history, $state, self::vagueSampleIntentClarify($state, $message));
        }
        if (self::bareDiscovery($message)) {
            if (AssistantV2Filters::hasConstraint($state['filters']) || ($state['focus_ids'] ?? []) !== []) {
                return self::finish($message, $history, $state, self::runSearch($state, 0, 'list', false));
            }
            $roots = AssistantV2Catalog::usageRoots();
            if ($roots !== []) {
                $state = self::resetSearch($state);

                return self::finish($message, $history, $state, self::rootClarify($state, self::emptyPlan(), $roots, ''));
            }
        }
        if (self::continueMoisture($message, $state)) {
            return self::finish($message, $history, $state, self::discoverOrSearch($state, self::emptyPlan(), $message));
        }
        if (AssistantV2Prompt::conversational() && self::userExpressesUncertainty($message)) {
            return self::finish($message, $history, $state, self::uncertaintyResult($state, $message));
        }
        if (self::keepContext($message, $state) && self::wantsProductCards($message)) {
            return self::finish($message, $history, $state, self::runSearch($state, 0, 'list', false));
        }
        if (
            self::keepContext($message, $state)
            && !AssistantV2Catalog::rejectsOffer($message)
            && !self::wantsProductCards($message)
            && self::hasSpecContext($state)
            && (self::isSpecQuestion($message) || self::touchesSpecLabel($message, $state) || self::isEducationalProductQuestion($message, $state))
        ) {
            return self::finish($message, $history, $state, self::specResult($state, $message));
        }
        $root = self::pureRootSearch($message);
        if ($root !== null) {
            $state = self::resetSearch($state);
            $state['filters']['category'] = $root;

            return self::finish($message, $history, $state, self::discoverOrSearch($state, self::emptyPlan(), $message));
        }

        if (AssistantV2Prompt::conversational() && self::asksSecondHand($message)) {
            $state = self::resetSearch($state);

            return self::finish($message, $history, $state, self::secondHandResult($state, $message));
        }

        if (AssistantV2Prompt::conversational() && self::tradeInOnlyQuestion($message)) {
            $plan = self::emptyPlan();
            $plan['intent'] = 'service';
            $plan['tool'] = 'get_service_facts';
            $plan['arguments'] = ['topic' => 'trade'];

            return self::finish($message, $history, $state, self::runService($plan, $message));
        }

        if (
            AssistantV2Prompt::conversational()
            && !AssistantV2Catalog::rejectsOffer($message)
            && self::jewelerGoldCapabilityAsk($message)
            && self::jewelerGoldThread($message, $history, $state)
        ) {
            $state = self::primeJewelerGoldSpec($state);

            return self::finish($message, $history, $state, self::specResult($state, $message));
        }

        $routed = self::routeCatalogPhrase($message, $state);
        if ($routed !== null) {
            return self::finish($message, $history, $state, $routed);
        }

        $chipRouted = self::chipAnswerShortcut($message, $state);
        if ($chipRouted !== null) {
            logger('assistant_v2.chip_shortcut', ['ask' => self::pendingAsk($state), 'message' => self::redact($message)]);

            return self::finish($message, $history, $state, $chipRouted);
        }

        $slugs = AssistantV2Catalog::productSlugsIn($message);
        if ($slugs === []) {
            $slugs = AssistantV2Catalog::namedProductSlugs($message);
        }
        try {
            $plan = self::plan($message, $state, $history, $page, $slugs);
        } catch (\Throwable $e) {
            logger('assistant_v2.turn', ['user_message' => self::redact($message), 'response_failure_reason' => 'plan_exception', 'final_response_generated' => true], 'error');

            return self::failure($message, $state, 'plan_exception');
        }
        if ($plan === null) {
            return self::failure($message, $state, 'plan_failed');
        }
        $measureChip = self::capacityChip($message) || self::precisionChip($message);
        if (count($slugs) >= 2 && !$measureChip) {
            $plan['tool'] = 'compare_products';
            $plan['domain'] = 'in_domain';
            $plan['arguments']['slugs'] = $slugs;
            $plan['clarification'] = null;
            $plan['unverified'] = false;
        } elseif (count($slugs) === 1 && !$measureChip) {
            $plan['tool'] = 'get_product_by_slug';
            $plan['domain'] = 'in_domain';
            $plan['arguments']['slug'] = $slugs[0];
        } elseif ($measureChip) {
            $plan['unverified'] = false;
            $plan['unsupported'] = null;
            $plan['criterion'] = '';
            $plan['domain'] = 'in_domain';
            $plan['intent'] = 'search';
            $plan['tool'] = 'search_products';
            $plan['relation'] = 'continue_search';
            $plan['remove_constraints'] = [];
            $plan['drop'] = [];
        }

        if (in_array($plan['domain'], ['small_talk', 'out_of_domain'], true)) {
            $result = self::chatResult($plan['domain']);
        } elseif ($plan['unsupported'] !== null && count($state['focus_ids']) >= 2) {
            $plan['unverified'] = true;
            $result = self::unverifiedCompare($state, $plan);
            $result['plan'] = $plan;
        } elseif ($plan['unsupported'] !== null) {
            $result = self::gapResult('unsupported_attribute', [
                'attribute' => $plan['unsupported'],
                'reason'    => 'Bu istek kayıtlı katalog özellikleriyle eşleştirilemedi.',
            ], $state, $plan);
        } else {
            $previousFilters = $state['filters'];
            $merged = AssistantV2Filters::merge($state, $plan);
            $switchRoot = self::switchRoot($message, $previousFilters, $state);
            $answeringRoot = self::answersRoot($message, $state);
            if ($switchRoot !== null) {
                $merged['filters'] = AssistantV2Filters::blank();
                $merged['filters']['category'] = $switchRoot;
                $merged['ranking'] = AssistantV2Filters::blankRanking();
                $merged['replaced'] = true;
                $previousFilters = AssistantV2Filters::blank();
                $state['asked'] = [];
                $state['criterion'] = null;
                $state['focus_ids'] = [];
                $state['held_child'] = '';
            } elseif ($merged['replaced'] && !$answeringRoot) {
                $state['asked'] = [];
                $state['criterion'] = null;
                $state['focus_ids'] = [];
                $state['held_child'] = '';
            }
            $state['filters'] = AssistantV2Filters::alignUsage(
                $merged['filters'],
                $previousFilters,
                $message,
                $merged['replaced'],
                $state['asked']
            );
            $held = is_string($state['held_child'] ?? null) ? $state['held_child'] : '';
            if ($held !== '' && $answeringRoot) {
                $picked = AssistantV2Catalog::statedUsage($message);
                $child = $picked !== null ? AssistantV2Catalog::matchingChild($picked, $held) : null;
                if ($picked !== null && $child !== null) {
                    $state['filters']['category'] = $picked;
                    $state['filters']['subcategory'] = $child;
                    $state['filters']['type_token'] = null;
                    $state['held_child'] = '';
                }
            } elseif (($state['filters']['category'] ?? null) === null || ($state['filters']['category'] ?? '') === '') {
                $childName = AssistantV2Catalog::matchedChildName($message);
                $roots = $childName !== null ? AssistantV2Catalog::rootsForChild($childName) : [];
                if (count($roots) >= 2) {
                    $state['filters'] = AssistantV2Filters::blank();
                    $state['held_child'] = $childName;
                } elseif (count($roots) === 1 && $childName !== null) {
                    $only = (string) array_key_first($roots);
                    $child = AssistantV2Catalog::matchingChild($only, $childName);
                    $state['filters']['category'] = $only;
                    $state['filters']['subcategory'] = $child;
                    $state['filters']['type_token'] = null;
                    $state['held_child'] = '';
                }
            }
            if (!AssistantV2Prompt::legacy()) {
                $state['filters'] = AssistantV2Filters::applyUtteranceMeasures($state['filters'], $message);
            }
            $state['ranking'] = $merged['ranking'];
            $state['filters']['brands'] = AssistantV2Catalog::brandsIn($switchRoot !== null ? $message : self::userCorpus($message, $history));
            $spoken = $switchRoot !== null ? $message : self::userCorpus($message, $history);
            $series = is_string($state['filters']['series'] ?? null) ? $state['filters']['series'] : '';
            if ($series !== '' && !self::seriesSpoken($series, $spoken)) {
                $state['filters']['series'] = null;
            }
            if (!str_contains(AssistantV2Catalog::fold($spoken), 'kalibr')) {
                $state['filters']['calibration'] = null;
            }
            $fact = (string) ($plan['arguments']['requested_fact'] ?? 'none');
            $intent = (string) ($plan['intent'] ?? '');
            $otherTopic = in_array($intent, ['small_talk', 'service', 'business', 'contact', 'out_of_domain', 'compare'], true);
            $keepQuote = $fact === 'price'
                || ($plan['criterion'] ?? '') === 'price'
                || (self::messageAsksPrice($message) && !$otherTopic)
                || (($state['goal'] ?? '') === 'price' && self::quoteStillOpen($plan) && self::answersPriceQuestion($message, $slugs));
            $state['goal'] = $keepQuote ? 'price' : '';
            if (
                $state['goal'] === 'price'
                && !self::messageNamesCatalog($message)
                && !AssistantV2Filters::hasConstraint($previousFilters)
                && $state['focus_ids'] === []
            ) {
                $state['filters'] = AssistantV2Filters::blank();
            }
            if ($state['goal'] === 'price' && !self::messageNamesCatalog($message)) {
                foreach (['brands', 'category', 'subcategory', 'series', 'type_token'] as $key) {
                    $current = $state['filters'][$key] ?? null;
                    $empty = $current === null || $current === [] || $current === '';
                    $previous = $previousFilters[$key] ?? null;
                    if ($empty && $previous !== null && $previous !== [] && $previous !== '') {
                        $state['filters'][$key] = $previous;
                    }
                }
            }
            $measureText = $switchRoot !== null ? $message : self::userCorpus($message, $history);
            if (self::statedPrecision($measureText) === null) {
                $state['filters']['precision'] = ['value' => null, 'unit' => null];
            }
            $statedPrecision = self::statedPrecision($message);
            if ($statedPrecision !== null) {
                $state['filters']['precision'] = $statedPrecision;
            }
            if (!self::messageStatesCapacity($measureText)) {
                $state['filters']['capacity'] = AssistantV2Filters::blank()['capacity'];
            }
            $state['filters'] = AssistantV2Filters::applyChipCapacity($state['filters'], $message);
            if (AssistantV2Prompt::conversational()) {
                // Kullanıcı alt grubu adıyla söylediyse planlayıcının yakın ama farklı bir grup seçmesine izin verme.
                $rootNow = is_string($state['filters']['category'] ?? null) ? $state['filters']['category'] : '';
                if ($rootNow !== '') {
                    $named = AssistantV2Catalog::matchingChild($rootNow, $message);
                    if ($named !== null && $named !== ($state['filters']['subcategory'] ?? null)) {
                        $state['filters']['subcategory'] = $named;
                    }
                }
            }
            $floor = AssistantV2Filters::rangeFloorGrams($message);
            if ($floor !== null) {
                $state['filters']['capacity'] = [
                    'operator' => 'min',
                    'value_g' => $floor,
                    'min_g' => null,
                    'max_g' => null,
                    'sense' => 'required_load',
                ];
                $state['filters']['capacity_slug'] = null;
            }
            if ((string) ($plan['intent'] ?? '') === 'more' && ($statedPrecision !== null || self::answersPending($message, $state, $previousFilters))) {
                $plan['intent'] = 'search';
            }
            $sameFilters = json_encode($previousFilters) === json_encode($state['filters']);
            if ($merged['replaced'] || !$sameFilters) {
                $state['offset'] = 0;
            } elseif (!empty($state['has_more']) && in_array((string) ($plan['intent'] ?? ''), ['more', 'search'], true)) {
                // Sürüm 4: yalnızca devam isteği sonraki sayfadır. Yeni ihtiyaç, fiyat veya yorum aynı kriteri yeniden sayfalamaz.
                $continues = !AssistantV2Prompt::refined()
                    || (string) ($plan['intent'] ?? '') === 'more'
                    || preg_match('/devam|daha fazla|baska|diger|sonraki|hepsi/u', AssistantV2Catalog::fold($message)) === 1;
                if ($continues) {
                    $plan['intent'] = 'more';
                }
            }
            $heldNow = is_string($state['held_child'] ?? null) ? $state['held_child'] : '';
            $categoryNow = is_string($state['filters']['category'] ?? null) ? $state['filters']['category'] : '';
            if ($heldNow !== '' && $categoryNow === '' && ($state['goal'] ?? '') !== 'price') {
                $roots = AssistantV2Catalog::rootsForChild($heldNow);
                $result = count($roots) >= 2
                    ? self::rootClarify($state, $plan, $roots, $heldNow)
                    : self::execute($plan, $state, $message);
            } elseif (
                self::moistureContext($state['filters'])
                && !self::moistureBrowseReady($state, $message)
                && !self::wantsProductCards($message)
            ) {
                $result = self::forceMoistureTempAsk($state, $plan, $message) ?? self::execute($plan, $state, $message);
            } else {
                $result = self::execute($plan, $state, $message);
            }
            $result['inherited'] = $merged['replaced'] ? AssistantV2Filters::blank() : $previousFilters;
            $result['extracted_hard'] = $plan['add_constraints'] ?? $plan['hard'];
            $result['extracted_soft'] = $plan['soft'];
            $result['plan'] = $plan;
            $result['trace'] = [
                'relation' => (string) ($plan['relation'] ?? ''),
                'previous_active_constraints' => $previousFilters,
                'add_constraints' => $plan['add_constraints'] ?? [],
                'remove_constraints' => $plan['remove_constraints'] ?? [],
                'preserve_constraints' => $plan['preserve_constraints'] ?? [],
                'final_active_constraints' => $state['filters'],
                'referenced_product_ids' => $state['focus_ids'],
            ];
        }
        if (!isset($result['plan'])) {
            $result['plan'] = $plan;
            $result['extracted_hard'] = $plan['hard'];
            $result['extracted_soft'] = $plan['soft'];
            $result['inherited'] = $state['filters'];
        }

        try {
            return self::finish($message, $history, $state, $result);
        } catch (\Throwable $e) {
            logger('assistant_v2.turn', ['user_message' => self::redact($message), 'response_failure_reason' => 'finish_exception', 'final_response_generated' => true], 'error');

            return self::failure($message, $state, 'finish_exception');
        }
    }

    /**
     * LLM-first tur: chip kısa devreleri, ölçü kapıları ve zorunlu clarify yok; plan + katalog araçları kalır.
     *
     * @param array<string, mixed> $state
     * @param list<array{role:string,text:string}> $history
     * @param array{brand?:string,category?:string} $page
     * @return array<string, mixed>
     */
    private static function freeTurn(string $message, array $history, array $state, array $page, string $uiAction): array
    {
        if ($uiAction === 'expert') {
            return self::expertChoice($message, $state);
        }
        if ($uiAction === 'more') {
            $result = self::runSearch($state, (int) $state['offset'], 'list', false);
            $result['facts']['mode'] = 'more';
            $result['facts']['continuation'] = true;

            return self::finish($message, $history, $state, $result);
        }

        $slugs = AssistantV2Catalog::productSlugsIn($message);
        if ($slugs === []) {
            $slugs = AssistantV2Catalog::namedProductSlugs($message);
        }
        $plan = self::plan($message, $state, $history, $page, $slugs);
        if ($plan === null) {
            return self::failure($message, $state, 'plan_failed');
        }
        if (count($slugs) >= 2) {
            $plan['tool'] = 'compare_products';
            $plan['domain'] = 'in_domain';
            $plan['arguments']['slugs'] = $slugs;
            $plan['clarification'] = null;
        } elseif (count($slugs) === 1) {
            $plan['tool'] = 'get_product_by_slug';
            $plan['domain'] = 'in_domain';
            $plan['arguments']['slug'] = $slugs[0];
        }
        $plan['clarification'] = null;

        $previousFilters = $state['filters'];
        $merged = AssistantV2Filters::merge($state, $plan);
        $state['filters'] = AssistantV2Filters::applyUtteranceMeasures($merged['filters'], $message);
        $state['filters']['brands'] = AssistantV2Catalog::brandsIn(self::userCorpus($message, $history));
        $state['ranking'] = $merged['ranking'];
        if ($merged['replaced']) {
            $state['offset'] = 0;
            $state['asked'] = [];
            $state['focus_ids'] = [];
        }
        if ((string) ($plan['intent'] ?? '') === 'more') {
            $state['offset'] = max(0, (int) ($state['offset'] ?? 0));
        }

        if (in_array($plan['domain'], ['small_talk', 'out_of_domain'], true)) {
            $result = self::chatResult($plan['domain']);
        } else {
            $result = self::freeExecute($plan, $state, $message);
        }
        $result['plan'] = $plan;
        $result['inherited'] = $merged['replaced'] ? AssistantV2Filters::blank() : $previousFilters;
        $result['extracted_hard'] = $plan['add_constraints'] ?? $plan['hard'] ?? [];
        $result['extracted_soft'] = $plan['soft'] ?? [];
        $result['trace'] = [
            'relation' => (string) ($plan['relation'] ?? ''),
            'previous_active_constraints' => $previousFilters,
            'add_constraints' => $plan['add_constraints'] ?? [],
            'remove_constraints' => $plan['remove_constraints'] ?? [],
            'preserve_constraints' => $plan['preserve_constraints'] ?? [],
            'final_active_constraints' => $state['filters'],
            'referenced_product_ids' => $state['focus_ids'],
            'free_llm' => true,
        ];

        return self::finish($message, $history, $state, $result);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function freeExecute(array $plan, array $state, string $message): array
    {
        if ((string) ($plan['intent'] ?? '') === 'more') {
            $result = self::runSearch($state, (int) $state['offset'], 'list', false, $plan);
            $result['facts']['mode'] = 'more';
            $result['facts']['continuation'] = true;

            return $result;
        }
        $fact = (string) ($plan['arguments']['requested_fact'] ?? 'none');
        if ($fact === 'price' || ($plan['criterion'] ?? '') === 'price') {
            return self::quoteReply($state, $plan, 'price', $message);
        }
        if ($fact === 'stock') {
            return self::quoteReply($state, $plan, 'stock', $message);
        }

        return match ((string) ($plan['tool'] ?? 'none')) {
            'rank_products' => self::runRank($state, $plan),
            'compare_products' => self::runCompare($plan, $state),
            'get_product_by_id', 'get_product_by_slug' => self::runLookup($plan, $state, $message),
            'get_business_facts' => self::runBusiness($plan, $message),
            'get_service_facts' => self::runService($plan, $message),
            'get_contact_actions' => self::runContact($plan, $message),
            'create_expert_handoff' => self::unverifiedCompare($state, $plan),
            default => self::freeSearchOrChat($state, $plan, $message),
        };
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function freeSearchOrChat(array $state, array $plan, string $message): array
    {
        if (!AssistantV2Filters::hasConstraint($state['filters'])) {
            $lab = AssistantV2Catalog::labScaleFilters($message);
            foreach (['category', 'subcategory', 'type_token'] as $key) {
                $value = $lab[$key] ?? null;
                if ($value !== null && $value !== '') {
                    $state['filters'][$key] = $value;
                }
            }
            $root = self::pureRootSearch($message);
            if ($root !== null && ($state['filters']['category'] ?? null) === null) {
                $state['filters']['category'] = $root;
            }
        }
        $searchIntent = in_array((string) ($plan['intent'] ?? ''), ['search', 'discover', 'rank', 'count', 'more'], true)
            || (string) ($plan['tool'] ?? '') === 'search_products';
        if (
            AssistantV2Filters::hasConstraint($state['filters'])
            || self::wantsProductCards($message)
            || $searchIntent
        ) {
            $mode = ($plan['intent'] ?? '') === 'count' ? 'count' : 'list';

            return self::runSearch($state, 0, $mode, false, $plan);
        }

        return [
            'mode' => 'answer',
            'tool' => 'none',
            'facts' => ['mode' => 'free_chat'],
            'cards' => [],
            'total' => 0,
            'facets' => [],
            'answered' => true,
            'gap' => null,
            'cta' => 'none',
            'focus_ids' => $state['focus_ids'],
            'offset' => 0,
            'filters' => $state['filters'],
            'plan' => $plan,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function failure(string $message, array $state, string $reason): array
    {
        $action = self::teamWriteAction($message, $state);
        $actions = $action !== null ? [$action] : [];
        $text = $action !== null
            ? 'Şu anda bu aramayı tamamlayamadım. İsterseniz tekrar deneyebilir veya WhatsApp\'tan yazabilirsiniz.'
            : 'Şu anda bu aramayı tamamlayamadım. İsterseniz tekrar deneyebilirsiniz.';
        $debug = self::debug($message, self::emptyPlan(), $state, ['failure' => $reason, 'trace' => []], 'none', 0, [], [], [], $actions, $reason);
        $debug['final_response_generated'] = true;
        $debug['response_failure_reason'] = $reason;
        logger('assistant_v2.turn', $debug, 'warning');

        return self::envelope($text, [], $state, $debug, $actions);
    }

    /**
     * @param array<string, mixed> $state
     * @param list<array{role:string,text:string}> $history
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function finish(string $message, array $history, array $state, array $result): array
    {
        $freeLlm = self::freeLlmMode();
        $plan = is_array($result['plan'] ?? null) ? $result['plan'] : self::emptyPlan();
        $facts = is_array($result['facts'] ?? null) ? $result['facts'] : [];
        if (
            ($result['mode'] ?? '') === 'clarify'
            && trim((string) ($facts['usage_root'] ?? '')) === ''
        ) {
            $clarifyFilters = is_array($result['filters'] ?? null)
                ? $result['filters']
                : (is_array($state['filters'] ?? null) ? $state['filters'] : []);
            if ($clarifyFilters !== []) {
                $facts = array_merge($facts, self::clarifyCategoryFacts($clarifyFilters));
            }
        }
        $actions = self::actions($result, $message, $state);
        $ask = (string) ($facts['ask'] ?? '');
        $chipAsk = !$freeLlm && in_array($ask, ['root', 'subcategory', 'precision', 'capacity', 'near', 'moisture_form', 'moisture_use', 'moisture_temp', 'sample_goal'], true);
        $chipLimit = $ask === 'moisture_use' ? 96 : 64;
        if (($result['mode'] ?? '') === 'clarify' && $chipAsk) {
            $hasPromptChip = false;
            foreach ($actions as $existing) {
                if (is_array($existing) && ($existing['type'] ?? '') === 'prompt') {
                    $hasPromptChip = true;
                    break;
                }
            }
            if (!$hasPromptChip) {
                foreach (is_array($facts['options'] ?? null) ? $facts['options'] : [] as $option) {
                    if (!is_string($option) || $option === '' || mb_strlen($option) > $chipLimit) {
                        continue;
                    }
                    $actions[] = [
                        'type'   => 'prompt',
                        'role'   => 'secondary',
                        'label'  => $option,
                        'prompt' => $option,
                        'url'    => null,
                    ];
                }
            }
        }
        if (!$chipAsk || $freeLlm) {
            $facts['options'] = [];
        }
        if ($freeLlm) {
            $actions = array_values(array_filter(
                $actions,
                static fn (array $action): bool => ($action['type'] ?? '') !== 'prompt'
            ));
        }
        $facts['buttons'] = array_map(static fn (array $action): string => (string) $action['label'], $actions);
        $facts['offer_handoff'] = self::offersHandoff($result);
        $writeMode = (string) ($facts['mode'] ?? $result['mode'] ?? 'answer');
        if ($freeLlm && $writeMode === 'free_chat') {
            $writeMode = 'answer';
        }
        $fixedRaw = $result['reply'] ?? '';
        $fixed = is_string($fixedRaw) ? trim($fixedRaw) : '';
        $chipFast = !$freeLlm
            && ($result['mode'] ?? '') === 'clarify'
            && $chipAsk
            && self::chipClarifyHasCatalogOptions($facts, $actions);
        $fastText = $chipFast ? trim(self::chipClarifyFastReply($facts, $ask)) : '';
        if ($chipFast && $fastText === '') {
            $chipFast = false;
        }
        $lunaClarify = AssistantV2Prompt::lunaConsultant()
            && ($result['mode'] ?? '') === 'clarify'
            && $chipAsk
            && !$chipFast;
        $clarifyPath = 'writer';
        $clarifyMs = 0;
        if ($chipFast) {
            $clarifyPath = 'chip_fast';
            $text = trim(self::withCallbackInvite($fastText, $actions));
        } elseif ($fixed !== '' && !$lunaClarify) {
            $clarifyPath = 'fixed';
            $text = $fixed;
        } else {
            $writeStarted = microtime(true);
            $text = trim(self::withCallbackInvite(self::write($message, $history, $facts, $writeMode), $actions));
            $clarifyMs = (int) round((microtime(true) - $writeStarted) * 1000);
            if (($result['mode'] ?? '') === 'clarify' && $chipAsk) {
                $clarifyPath = 'luna_clarify';
            }
        }
        if ($text === '') {
            $result['failure'] = 'empty_writer';
            if ($actions === [] && !in_array($writeMode, ['small_talk', 'out_of_domain'], true)) {
                $fallback = self::teamWriteAction($message, $state, $result);
                $actions = $fallback !== null ? [$fallback] : [];
            }
            $text = self::withCallbackInvite(
                'Şu anda bu aramayı tamamlayamadım. İsterseniz tekrar deneyebilirsiniz.',
                $actions
            );
        }
        if ($freeLlm) {
            $cardsRaw = is_array($result['cards'] ?? null) ? $result['cards'] : [];
            $attachCards = $cardsRaw !== [] && in_array($writeMode, ['list', 'near', 'rank', 'more'], true);
        } else {
            $attachCards = self::shouldAttachProductCards($message, $result, $writeMode, $state);
        }
        $explicitListRequest = self::wantsProductCards($message) || self::userConfirmedProductListing($message);
        if (
            !$freeLlm
            && $attachCards
            && !$explicitListRequest
            && (self::assistantDefersProductCards($text) || self::assistantSuggestsSingleProductNotGrid($text))
        ) {
            $attachCards = false;
        }
        if (
            !$freeLlm
            && $attachCards
            && !$explicitListRequest
            && self::userSeekingProductWithoutListRequest($message)
            && !self::assistantIntroducesProductListing($text)
        ) {
            $attachCards = false;
        }
        if (!$freeLlm && self::userExpressesUncertainty($message)) {
            $actions = AssistantV2Knowledge::humanHandoffActions($message, self::focusProductIds($state, $result));
        } elseif (!$attachCards) {
            $actions = array_values(array_filter(
                $actions,
                static fn (array $action): bool => ($action['type'] ?? '') !== 'price_request'
            ));
        }
        $cards = $attachCards && is_array($result['cards'] ?? null) ? $result['cards'] : [];
        $state = self::nextState($state, $result, $cards);
        $debug = self::debug(
            $message,
            $plan,
            $state,
            $result,
            (string) ($result['tool'] ?? $plan['tool'] ?? ''),
            (int) ($result['total'] ?? 0),
            array_map(static fn (array $card): int => (int) $card['id'], $cards),
            is_array($result['facets'] ?? null) ? $result['facets'] : [],
            $facts,
            $actions,
            $result['gap'] ?? null
        );
        $more = null;
        $total = $attachCards ? (int) ($result['total'] ?? 0) : 0;
        if ($cards !== [] && $total > AssistantV2Filters::PAGE && ($result['mode'] ?? '') === 'list') {
            $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
            $more = [
                'label' => 'Diğerlerini gör (' . $total . ')',
                'url'   => self::listingUrl($filters),
                'count' => $total,
            ];
        }

        logger('assistant_v2.turn', [
            'user_message' => self::redact($message),
            'relation' => $debug['relation'] ?? null,
            'previous_active_constraints' => $debug['previous_active_constraints'] ?? [],
            'add_constraints' => $debug['add_constraints'] ?? [],
            'remove_constraints' => $debug['remove_constraints'] ?? [],
            'preserve_constraints' => $debug['preserve_constraints'] ?? [],
            'final_active_constraints' => $debug['final_active_constraints'] ?? [],
            'referenced_product_ids' => $debug['referenced_product_ids'] ?? [],
            'candidate_product_ids' => $debug['candidate_product_ids'] ?? [],
            'tool_calls' => $debug['tool_calls'] ?? [],
            'tool_result_status' => $debug['tool_result_status'] ?? '',
            'final_response_generated' => $text !== '',
            'response_failure_reason' => $debug['response_failure_reason'] ?? null,
            'clarify_path' => ($result['mode'] ?? '') === 'clarify' ? $clarifyPath : null,
            'clarify_ms' => ($result['mode'] ?? '') === 'clarify' ? $clarifyMs : null,
            'clarify_ask' => ($result['mode'] ?? '') === 'clarify' ? $ask : null,
        ]);

        return self::envelope(
            $text,
            $cards,
            $state,
            $debug,
            $actions,
            $more,
            $total,
            (string) ($result['mode'] ?? ''),
            $writeMode,
            $attachCards
        );
    }

    /**
     * @param array<string, mixed> $state
     * @return array{catalog_measures:array{capacity:list<string>,precision:list<string>},candidate_specs:list<array{name:string,specs:list<array{label:string,value:string}>}>}
     */
    private static function planningContext(string $message, array $state): array
    {
        $empty = [
            'catalog_measures' => ['capacity' => [], 'precision' => []],
            'candidate_specs' => [],
        ];
        if (!self::messageNeedsSpecs($message) && !self::isSpecQuestion($message)) {
            return $empty;
        }
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : AssistantV2Filters::blank();
        if (!AssistantV2Filters::hasConstraint($filters)) {
            $opened = self::planningGroup($message);
            if ($opened === null) {
                return $empty;
            }
            $filters = $opened;
        }
        $found = AssistantV2Filters::search($filters, 0, 8);
        $capacity = [];
        foreach (is_array($found['facets']['available_capacities'] ?? null) ? $found['facets']['available_capacities'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '' || AssistantV2Filters::measureRangeLabel($label)) {
                continue;
            }
            $capacity[] = $label;
            if (count($capacity) >= 16) {
                break;
            }
        }
        $precision = [];
        foreach (is_array($found['facets']['available_precisions'] ?? null) ? $found['facets']['available_precisions'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '' || AssistantV2Filters::measureRangeLabel($label)) {
                continue;
            }
            $precision[] = $label;
            if (count($precision) >= 16) {
                break;
            }
        }
        $candidates = [];
        if ((int) ($found['total'] ?? 0) > 0 && (int) $found['total'] <= 8) {
            foreach (is_array($found['page'] ?? null) ? $found['page'] : [] as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $candidates[] = [
                    'name' => (string) ($product['name'] ?? ''),
                    'specs' => AssistantV2Filters::answerSpecs($product, 12),
                ];
                if (count($candidates) >= 6) {
                    break;
                }
            }
        }

        return [
            'catalog_measures' => ['capacity' => $capacity, 'precision' => $precision],
            'candidate_specs' => $candidates,
        ];
    }

    private static function messageNeedsSpecs(string $message): bool
    {
        return preg_match('/terazi|kantar|cihaz|analiz|yogunluk|nem|numune|kapasite|hassasiyet|sicaklik|karat|olcum|sivi|kati|tart/u', AssistantV2Catalog::fold($message)) === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function planningGroup(string $message): ?array
    {
        $child = AssistantV2Catalog::matchedChildName($message);
        $roots = $child !== null ? AssistantV2Catalog::rootsForChild($child) : [];
        if ($child !== null && count($roots) === 1) {
            $parent = (string) array_key_first($roots);
            $slug = AssistantV2Catalog::matchingChild($parent, $child);
            if (is_string($slug) && $slug !== '') {
                $filters = AssistantV2Filters::blank();
                $filters['category'] = $parent;
                $filters['subcategory'] = $slug;

                return $filters;
            }
        }
        $candidates = AssistantV2Catalog::nearCatalog($message, []);
        if (count($candidates) !== 1 || empty($candidates[0]['direct'])) {
            return null;
        }
        $node = AssistantV2Catalog::node((string) $candidates[0]['slug']);
        if ($node === null) {
            return null;
        }
        $filters = AssistantV2Filters::blank();
        $root = AssistantV2Catalog::usageSlug($node);
        $filters['category'] = $root;
        if ($node['slug'] !== $root) {
            $filters['subcategory'] = $node['slug'];
        }

        return $filters;
    }

    /**
     * @param list<string> $slugs
     * @param array<string, mixed> $state
     * @param list<array{role:string,text:string}> $history
     * @param array{brand?:string,category?:string} $page
     * @return array<string, mixed>|null
     */
    private static function plan(string $message, array $state, array $history, array $page, array $slugs): ?array
    {
        $specContext = self::planningContext($message, $state);
        $payload = [
            'task' => 'Bu çağrı kullanıcıya gösterilmez. Yalnızca şemadaki JSON planı üret. Kullanıcı cümlesi yazma.',
            'message' => $message,
            'history' => $history,
            'state' => [
                'filters' => $state['filters'],
                'ranking' => $state['ranking'],
                'asked' => $state['asked'],
                'focus_ids' => $state['focus_ids'],
                'has_more' => $state['has_more'],
            ],
            'page' => [
                'brand' => (string) ($page['brand'] ?? ''),
                'category' => (string) ($page['category'] ?? ''),
            ],
            'taxonomy' => AssistantV2Catalog::taxonomy(),
            'brands' => AssistantV2Catalog::brands(),
            'series' => AssistantV2Catalog::seriesList(),
            'active_constraints' => $state['filters'],
            'preferences' => is_array($state['preferences'] ?? null) ? $state['preferences'] : [],
            'referenced_product_ids' => $state['focus_ids'],
            'product_slugs' => $slugs,
            'catalog_measures' => $specContext['catalog_measures'],
            'candidate_specs' => $specContext['candidate_specs'],
            'schema' => [
                'intent' => 'discover|search|rank|compare|lookup|count|more|business|service|contact|small_talk|out_of_domain|unsupported|expert',
                'relation' => 'continue_search|modify_search|broaden_search|new_search|product_followup|non_product_question',
                'domain' => 'small_talk|out_of_domain|in_domain',
                'tool' => 'search_products|get_product_by_id|get_product_by_slug|get_catalog_taxonomy|get_available_facets|compare_products|rank_products|get_business_facts|get_service_facts|get_contact_actions|create_expert_handoff|none',
                'arguments' => [
                    'ranking' => 'cheapest|most_expensive|most_precise|highest_capacity|lowest_capacity|null',
                    'topic' => 'service overview|warranty|fee|shipping|form|brand|trade|unknown_brand veya business whatsapp|phone|email|address|hours|about|shipping|returns veya contact whatsapp|phone|service|person',
                    'slug' => 'string|null',
                    'id' => 'number|null',
                    'slugs' => 'string[]',
                    'ids' => 'number[]',
                    'requested_spec' => 'string|null',
                    'requested_fact' => 'price|stock|spec|none',
                ],
                'add_constraints' => self::filterShape(),
                'remove_constraints' => ['brand', 'series', 'precision', 'capacity', 'category', 'subcategory', 'commercial_approval', 'calibration'],
                'preserve_constraints' => ['brand', 'series', 'precision', 'capacity', 'category', 'subcategory'],
                'preferences' => ['commercial_approval' => 'bool|null', 'calibration' => 'string|null'],
                'clarification' => 'string|null',
                'missing_decision_fact' => 'bool',
                'decision_criterion' => 'null|precision|capacity|price|calibration|commercial|lifespan|durability|quality|other',
                'unsupported_attribute' => 'string|null',
                'page_context' => 'bool',
                'suggested_categories' => 'taxonomy kök slug listesi, en fazla 2, ya da []',
                'not_in_catalog' => 'string|null',
            ],
            'rules' => AssistantV2Prompt::planRules(),
        ];
        if (AssistantV2Prompt::legacy()) {
            unset($payload['catalog_measures'], $payload['candidate_specs']);
        }
        if (!AssistantV2Prompt::conversational()) {
            unset($payload['schema']['suggested_categories'], $payload['schema']['not_in_catalog']);
        }
        $user = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = is_string($user) ? AssistantV2Llm::complete(AssistantV2Prompt::voice(), $user, 24, 'v2_plan') : null;
        if (!is_array($json)) {
            $json = is_string($user) ? AssistantV2Llm::complete(AssistantV2Prompt::voice(), $user, 18, 'v2_plan_retry') : null;
        }
        if (!is_array($json)) {
            return null;
        }

        return self::normalizePlan($json);
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function normalizePlan(array $json): array
    {
        $tool = (string) ($json['tool'] ?? 'none');
        $allowed = [
            'search_products', 'get_product_by_id', 'get_product_by_slug', 'get_catalog_taxonomy',
            'get_available_facets', 'compare_products', 'rank_products', 'get_business_facts',
            'get_service_facts', 'get_contact_actions', 'create_expert_handoff', 'none',
        ];
        if (!in_array($tool, $allowed, true)) {
            $tool = 'none';
        }
        $domain = (string) ($json['domain'] ?? 'in_domain');
        if (!in_array($domain, ['small_talk', 'out_of_domain', 'in_domain'], true)) {
            $domain = 'in_domain';
        }
        $relation = trim((string) ($json['relation'] ?? ''));
        $relation = $relation === '' ? 'continue_search' : AssistantV2Filters::relation($relation);
        $args = is_array($json['arguments'] ?? null) ? $json['arguments'] : [];
        $add = is_array($json['add_constraints'] ?? null) ? $json['add_constraints'] : [];
        $hard = $add !== [] ? $add : (is_array($json['hard'] ?? null) ? $json['hard'] : []);
        $soft = is_array($json['preferences'] ?? null) ? $json['preferences'] : (is_array($json['soft'] ?? null) ? $json['soft'] : []);
        $remove = AssistantV2Filters::names($json['remove_constraints'] ?? $json['drop'] ?? []);
        $preserve = AssistantV2Filters::names($json['preserve_constraints'] ?? []);
        foreach (['category', 'subcategory', 'brands', 'series', 'precision', 'capacity', 'commercial_approval', 'calibration'] as $key) {
            if (!array_key_exists($key, $hard) && array_key_exists($key, $args)) {
                $hard[$key] = $args[$key];
            }
        }
        $criterion = (string) ($json['decision_criterion'] ?? '');
        $unverifiedNames = ['lifespan', 'durability', 'quality', 'other'];
        $unsupportedRaw = trim((string) ($json['unsupported_attribute'] ?? ''));
        $unsupported = $unsupportedRaw;
        if ($unsupported !== '' && (self::knownAttribute($unsupported) || self::isQualityGap($unsupported))) {
            if (self::isQualityGap($unsupported) && !in_array($criterion, $unverifiedNames, true)) {
                $criterion = 'lifespan';
            }
            $unsupported = '';
        }

        $clarification = is_string($json['clarification'] ?? null) ? trim((string) $json['clarification']) : null;
        if (self::freeLlmMode()) {
            $clarification = null;
        }

        return [
            'intent' => (string) ($json['intent'] ?? ''),
            'relation' => $relation,
            'domain' => $domain,
            'tool' => $tool,
            'arguments' => $args,
            'hard' => $hard,
            'soft' => $soft,
            'add_constraints' => $hard,
            'remove_constraints' => $remove,
            'preserve_constraints' => $preserve,
            'preferences' => $soft,
            'drop' => $remove,
            'clarification' => $clarification,
            'missing' => !empty($json['missing_decision_fact']) || in_array($criterion, $unverifiedNames, true),
            'criterion' => $criterion,
            'unverified' => in_array($criterion, $unverifiedNames, true) || self::isQualityGap($unsupportedRaw),
            'unsupported' => $unsupported !== '' ? $unsupported : null,
            'page_context' => !empty($json['page_context']),
            'ranking' => $args['ranking'] ?? ($json['ranking'] ?? null),
            'suggested' => AssistantV2Prompt::conversational() ? self::suggestedRoots($json['suggested_categories'] ?? []) : [],
            'not_in_catalog' => AssistantV2Prompt::conversational() && is_string($json['not_in_catalog'] ?? null) && mb_strlen(trim((string) $json['not_in_catalog'])) >= 3
                ? mb_substr(trim((string) $json['not_in_catalog']), 0, 60)
                : null,
        ];
    }

    /**
     * Planlayıcının önerdiği kök kategoriler yalnızca ürünü olan gerçek kökler olabilir.
     *
     * @return list<string>
     */
    private static function suggestedRoots(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $roots = AssistantV2Catalog::usageRoots();
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $slug = trim($item);
            if (!isset($roots[$slug])) {
                $node = AssistantV2Catalog::resolveGroup($slug);
                $slug = $node !== null ? AssistantV2Catalog::usageSlug($node) : '';
            }
            if ($slug !== '' && isset($roots[$slug]) && !in_array($slug, $out, true)) {
                $out[] = $slug;
            }
            if (count($out) >= 2) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function execute(array $plan, array $state, string $message): array
    {
        $qualityGap = in_array((string) ($plan['criterion'] ?? ''), ['lifespan', 'durability', 'quality'], true);
        if ((($plan['intent'] ?? '') === 'expert' || ($plan['tool'] ?? '') === 'create_expert_handoff') && !$qualityGap && empty($plan['unverified'])) {
            return self::callbackResult($state, $plan);
        }
        $expertDetour = ($plan['intent'] ?? '') === 'expert' || ($plan['tool'] ?? '') === 'create_expert_handoff' || !empty($plan['unverified']);
        if ($expertDetour && !$qualityGap && count($state['focus_ids']) < 2 && AssistantV2Filters::hasConstraint($state['filters'])) {
            $plan['unverified'] = false;
            $plan['intent'] = 'discover';
            $plan['tool'] = 'search_products';

            return self::discoverOrSearch($state, $plan, $message);
        }
        if (
            AssistantV2Prompt::conversational()
            && !empty($plan['unverified'])
            && $state['focus_ids'] === []
            && !AssistantV2Filters::hasConstraint($state['filters'])
            && (string) ($plan['intent'] ?? '') !== 'expert'
        ) {
            $roots = AssistantV2Catalog::usageRoots();
            if ($roots !== []) {
                return self::rootClarify($state, $plan, $roots, '', true);
            }
        }
        if ($plan['intent'] === 'expert') {
            $plan['unverified'] = true;
        }
        if ($plan['intent'] === 'more' && AssistantV2Filters::specificEnough($state['filters'], $state['asked'])) {
            $result = self::runSearch($state, (int) $state['offset'], 'list', false, $plan);
            $result['facts']['mode'] = 'more';
            $result['facts']['continuation'] = true;

            return $result;
        }
        if ($plan['unverified']) {
            return self::unverifiedCompare($state, $plan);
        }
        $fact = (string) ($plan['arguments']['requested_fact'] ?? 'none');
        if ($fact === 'price' || ($plan['criterion'] ?? '') === 'price' || ($state['goal'] ?? '') === 'price') {
            return self::quoteReply($state, $plan, 'price', $message);
        }
        if ($fact === 'stock') {
            return self::quoteReply($state, $plan, 'stock', $message);
        }
        $tool = (string) $plan['tool'];
        $namedProduct = AssistantV2Catalog::productSlugsIn($message) !== [] || AssistantV2Catalog::namedProductSlugs($message) !== [];
        $measureAnswer = self::statedPrecision($message) !== null || self::messageStatesCapacity($message);
        if ($measureAnswer && !$namedProduct) {
            return self::discoverOrSearch($state, $plan, $message);
        }
        if (
            AssistantV2Prompt::conversational()
            && !self::wantsProductCards($message)
            && !AssistantV2Filters::hasConstraint($state['filters'])
            && $tool === 'search_products'
            && !in_array((string) ($plan['intent'] ?? ''), ['count', 'more', 'business', 'service', 'contact', 'compare'], true)
        ) {
            $plan['intent'] = 'discover';

            return self::discoverOrSearch($state, $plan, $message);
        }
        if (
            !$namedProduct
            && ($plan['relation'] ?? '') !== 'product_followup'
            && in_array($tool, ['get_product_by_id', 'get_product_by_slug', 'rank_products'], true)
            && !AssistantV2Filters::specificEnough($state['filters'], $state['asked'])
        ) {
            return self::discoverOrSearch($state, $plan, $message);
        }

        return match ($tool) {
            'rank_products' => self::runRank($state, $plan),
            'compare_products' => self::runCompare($plan, $state),
            'get_product_by_id', 'get_product_by_slug' => self::runLookup($plan, $state, $message),
            'get_business_facts' => self::runBusiness($plan, $message),
            'get_service_facts' => self::runService($plan, $message),
            'get_contact_actions' => self::runContact($plan, $message),
            'create_expert_handoff' => self::unverifiedCompare($state, $plan),
            default => self::discoverOrSearch($state, $plan, $message),
        };
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function discoverOrSearch(array $state, array $plan, string $message = ''): array
    {
        $filters = $state['filters'];
        if (!AssistantV2Prompt::legacy() && $message !== '') {
            $outside = self::outsideCatalog($message, $state, $plan);
            if ($outside !== null) {
                return $outside;
            }
        }
        if (!AssistantV2Filters::hasConstraint($filters)) {
            $roots = AssistantV2Catalog::usageRoots();
            if ($message !== '') {
                $inferredRoot = self::pureRootSearch($message);
                if ($inferredRoot !== null && isset($roots[$inferredRoot])) {
                    $state['filters']['category'] = $inferredRoot;

                    return self::discoverOrSearch($state, $plan, $message);
                }
            }
            if (AssistantV2Prompt::conversational() && $message !== '') {
                $absent = is_string($plan['not_in_catalog'] ?? null) ? trim((string) $plan['not_in_catalog']) : '';
                if (
                    $absent !== ''
                    && AssistantV2Catalog::statedUsage($message) === null
                    && AssistantV2Catalog::matchedChildName($message) === null
                    && AssistantV2Catalog::nearCatalog($message, []) === []
                ) {
                    return self::unlistedQuote($state, $plan, $absent);
                }
                $suggested = is_array($plan['suggested'] ?? null) ? $plan['suggested'] : [];
                if ($suggested !== [] && $roots !== []) {
                    $subset = [];
                    foreach ($suggested as $slug) {
                        if (isset($roots[$slug])) {
                            $subset[$slug] = $roots[$slug];
                        }
                    }
                    if ($subset !== []) {
                        if (count($subset) === 1) {
                            $only = (string) array_key_first($subset);
                            if (
                                AssistantV2Catalog::statedUsage($message) === $only
                                || self::pureRootSearch($message) === $only
                            ) {
                                $state['filters']['category'] = $only;

                                return self::discoverOrSearch($state, $plan, $message);
                            }
                        }

                        return self::rootClarify($state, $plan, $subset, '', true);
                    }
                }
            }
            if ($roots !== []) {
                return self::rootClarify($state, $plan, $roots, '');
            }
        }
        if (AssistantV2Filters::isMoistureGroup($filters)) {
            $moisture = self::moistureClarify($state, $plan, $message);
            if ($moisture !== null) {
                return $moisture;
            }
            if (AssistantV2Prompt::conversational() && !self::moistureBrowseReady($state, $message)) {
                $retry = self::moistureClarify($state, $plan, $message);
                if ($retry !== null) {
                    return $retry;
                }

                if (!self::nemIntentConfirmed($message, [], $state)) {
                    return self::vagueSampleIntentClarify($state, $message);
                }
                $forced = self::forceMoistureTempAsk($state, $plan, $message);
                if ($forced !== null) {
                    return $forced;
                }
            }
        }
        if (
            AssistantV2Prompt::refined()
            && $message !== ''
            && AssistantV2Filters::brandOnly($filters)
            && AssistantV2Catalog::mentionedBrand($message) !== null
        ) {
            // Yalnız marka sorulduysa önce o markanın ürünleri görünür, ölçü sorusu sonra gelir.
            return self::runSearch($state, 0, 'list', false, $plan);
        }
        if (!AssistantV2Filters::specificEnough($filters, $state['asked'])) {
            $ask = AssistantV2Filters::nextQuestion($filters, $state['asked']);
            if ($ask !== null) {
                return self::measureClarifyResult($state, $plan, $filters, $ask, $message);
            }
        }
        if (($plan['intent'] ?? '') !== 'count') {
            $found = AssistantV2Filters::search($filters, 0, 1);
            if (
                (int) $found['total'] === 0
                && AssistantV2Prompt::refined()
                && is_string($filters['subcategory'] ?? null)
                && ($filters['subcategory'] ?? '') !== ''
                && is_numeric($filters['precision']['value'] ?? null)
            ) {
                $relaxed = $filters;
                $relaxed['subcategory'] = null;
                $retry = AssistantV2Filters::search($relaxed, 0, 1);
                if ((int) $retry['total'] > 0) {
                    $state['filters'] = $relaxed;
                    $filters = $relaxed;
                    $found = $retry;
                }
            }
            if ((int) $found['total'] === 0 && AssistantV2Prompt::legacy()) {
                return self::unlistedQuote($state, $plan);
            }
        }
        if (
            AssistantV2Prompt::conversational()
            && $message !== ''
            && !self::wantsProductCards($message)
            && ($plan['intent'] ?? '') === 'discover'
            && AssistantV2Filters::hasConstraint($filters)
            && !AssistantV2Filters::hasMeasure($filters)
            && !AssistantV2Filters::brandOnly($filters)
        ) {
            $wide = AssistantV2Filters::search($filters, 0, 1);
            $wideTotal = (int) ($wide['total'] ?? 0);
            if (
                $wideTotal > 5
                && AssistantV2Filters::nextQuestion($filters, $state['asked']) === null
                && !in_array('usage', $state['asked'], true)
            ) {
                return self::usageClarify($state, $plan, $filters, $wideTotal);
            }
        }
        if (
            AssistantV2Prompt::conversational()
            && AssistantV2Filters::isMoistureGroup($filters)
            && !self::moistureBrowseReady($state, $message)
            && !self::wantsProductCards($message)
        ) {
            $moisture = self::moistureClarify($state, $plan, $message);
            if ($moisture !== null) {
                return $moisture;
            }

            if (!self::nemIntentConfirmed($message, [], $state)) {
                return self::vagueSampleIntentClarify($state, $message);
            }
            $forced = self::forceMoistureTempAsk($state, $plan, $message);
            if ($forced !== null) {
                return $forced;
            }
        }
        if (
            AssistantV2Prompt::conversational()
            && AssistantV2Filters::isMoistureGroup($state['filters'])
            && !self::moistureBrowseReady($state, $message)
            && !self::wantsProductCards($message)
        ) {
            $forced = self::forceMoistureTempAsk($state, $plan, $message);
            if ($forced !== null) {
                return $forced;
            }
        }
        if (
            !self::wantsProductCards($message)
            && AssistantV2Filters::pendingPrecisionAfterCapacity($filters, $state['asked'])
        ) {
            return self::measureClarifyResult($state, $plan, $filters, 'precision', $message);
        }
        if (
            !self::wantsProductCards($message)
            && !AssistantV2Filters::hasMeasure($filters)
            && AssistantV2Catalog::labMeasureFlow($filters)
            && AssistantV2Filters::nextQuestion($filters, $state['asked']) === null
            && is_string($filters['subcategory'] ?? null)
            && ($filters['subcategory'] ?? '') !== ''
        ) {
            $preview = AssistantV2Filters::search($filters, 0, 1);
            $capRows = is_array($preview['facets']['available_capacities'] ?? null)
                ? $preview['facets']['available_capacities']
                : [];
            if (count($capRows) >= 2) {
                return self::measureClarifyResult($state, $plan, $filters, 'capacity', $message);
            }
        }
        $mode = $plan['intent'] === 'count' ? 'count' : 'list';

        return self::runSearch($state, 0, $mode, false, $plan);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function measureClarifyResult(array $state, array $plan, array $filters, string $ask, string $message): array
    {
        $preview = AssistantV2Filters::search($filters, 0, 1);
        $asked = $state['asked'];
        $asked[] = $ask;
        $capacityLocked = $ask === 'precision' && is_numeric($filters['capacity']['value_g'] ?? null);
        $varied = AssistantV2Prompt::conversational()
            && !$capacityLocked
            && in_array($ask, ['precision', 'subcategory'], true)
            && (
                ($filters['brands'] ?? []) !== []
                || count(preg_split('/\s+/u', trim($message)) ?: []) >= 3
            );
        $clarifyFacts = array_merge([
            'mode' => 'clarify',
            'ask' => $ask,
            'options' => self::facetOptions($preview['facets'], $ask),
        ], self::clarifyCategoryFacts($filters));
        if ($varied) {
            $clarifyFacts['user_need'] = self::needFacts($filters);
            $clarifyFacts['matching_models'] = (int) $preview['total'];
        }
        self::attachConsultantHints($clarifyFacts, $filters, $ask);

        return [
            'mode' => 'clarify',
            'tool' => 'get_available_facets',
            'facts' => $clarifyFacts,
            'reply' => $varied ? null : self::clarifyLine($ask),
            'cards' => [],
            'total' => (int) $preview['total'],
            'facets' => $preview['facets'],
            'answered' => true,
            'gap' => null,
            'cta' => 'none',
            'focus_ids' => [],
            'offset' => 0,
            'asked' => $asked,
            'filters' => $filters,
            'plan' => $plan,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed>|null $plan
     * @return array<string, mixed>
     */
    private static function runSearch(array $state, int $offset, string $mode, bool $named, ?array $plan = null): array
    {
        $asked = is_array($state['asked'] ?? null) ? $state['asked'] : [];
        if (AssistantV2Filters::pendingPrecisionAfterCapacity($state['filters'], $asked)) {
            return self::measureClarifyResult(
                $state,
                $plan ?? self::emptyPlan(),
                $state['filters'],
                'precision',
                ''
            );
        }
        if (
            self::moistureContext($state['filters'])
            && !self::moistureBrowseReady($state, '')
        ) {
            $forced = self::forceMoistureTempAsk($state, $plan ?? self::emptyPlan(), '');
            if ($forced !== null) {
                return $forced;
            }
        }
        $found = AssistantV2Filters::search($state['filters'], $offset, AssistantV2Filters::PAGE);
        $near = ($found['quality'] ?? '') === 'near';
        $cards = [];
        $brief = [];
        foreach ($found['page'] as $product) {
            $cards[] = AssistantV2Filters::card($product, $near);
            $brief[] = AssistantV2Filters::publicFacts($product, $named);
        }
        $empty = (int) $found['total'] === 0;
        $factMode = $empty ? 'empty' : ($near ? 'near' : $mode);
        $searchFacts = [
            'mode' => $factMode,
            'total_result_count' => (int) $found['total'],
            'displayed_count' => count($cards),
            'match_quality' => $found['quality'],
            'requested_capacity_g' => $state['filters']['capacity']['value_g'] ?? null,
            'nearest_capacity_g' => $found['nearest_g'],
            'nearest_capacity' => is_numeric($found['nearest_g'] ?? null) ? AssistantV2Filters::formatGrams((float) $found['nearest_g']) : null,
            'capacity_distinct' => count($found['facets']['available_capacities'] ?? []),
            'displayed' => $brief,
            'brand' => $state['filters']['brands'] ?? [],
            'brand_named_by_user' => ($state['filters']['brands'] ?? []) !== [],
            'series' => $state['filters']['series'] ?? null,
            'constraints_kept' => true,
        ];
        $searchFilter = self::groupLabel($state['filters']);
        if ($searchFilter !== '') {
            $searchFacts['search_filter'] = $searchFilter;
        }
        $searchFacts['active_filters'] = $state['filters'];
        $reply = null;
        if (AssistantV2Prompt::conversational()) {
            if ($cards !== [] && $mode === 'list') {
                $searchFacts['differences'] = self::differences($found['page']);
                $searchFacts['user_need'] = self::needFacts($state['filters']);
            }
        } elseif ($cards !== [] && $mode === 'list' && !$near && count($cards) > 1) {
            $reply = self::listReply($found['page'], $searchFacts);
        } elseif ($cards !== [] && $mode !== 'count' && !($mode === 'list' && count($cards) === 1)) {
            $reply = self::requiredLoadReply($state['filters']);
        }

        return [
            'mode' => $empty ? 'empty' : $mode,
            'tool' => 'search_products',
            'facts' => $searchFacts,
            'candidate_ids' => $found['all_ids'],
            'tool_status' => $empty ? 'empty' : 'ok',
            'cards' => $cards,
            'total' => (int) $found['total'],
            'facets' => $found['facets'],
            'answered' => true,
            'gap' => null,
            'cta' => 'none',
            'focus_ids' => array_map(static fn (array $card): int => (int) $card['id'], $cards),
            'offset' => $offset + count($cards),
            'filters' => $state['filters'],
            'plan' => $plan,
            'reply' => $reply,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function runRank(array $state, array $plan): array
    {
        $ranking = $state['ranking'];
        if (($ranking['field'] ?? null) === null) {
            $ranking = AssistantV2Filters::rankingFromPlan($plan);
        }
        $ranked = AssistantV2Filters::rank($state['filters'], $ranking['field'] ?? null, $ranking['direction'] ?? null);
        $cards = [];
        $winner = null;
        if (is_array($ranked['winner'])) {
            $cards[] = AssistantV2Filters::card($ranked['winner']);
            $winner = AssistantV2Filters::publicFacts($ranked['winner'], true);
        }
        $gap = $ranked['reason'] === 'no_published_price' ? 'no_price' : ($ranked['reason'] === 'missing_measure' ? 'missing_measure' : null);

        return [
            'mode' => $gap === null ? 'rank' : 'gap',
            'tool' => 'rank_products',
            'facts' => [
                'mode' => $gap === 'no_price' ? 'no_price' : ($gap === null ? 'rank' : 'gap'),
                'ranking' => $ranking,
                'pool' => $ranked['pool'],
                'priced' => $ranked['priced'],
                'winner' => $winner,
                'total_result_count' => $ranked['pool'],
                'reason' => $ranked['reason'],
            ],
            'cards' => $gap === null ? $cards : [],
            'total' => (int) $ranked['pool'],
            'facets' => $ranked['facets'],
            'answered' => $gap === null && $winner !== null,
            'gap' => $gap,
            'cta' => $gap === null ? 'none' : 'handoff',
            'focus_ids' => $winner !== null ? [(int) $winner['id']] : [],
            'offset' => count($cards),
            'ranking' => $ranking,
            'filters' => $state['filters'],
            'plan' => $plan,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function runCompare(array $plan, array $state): array
    {
        $products = self::productsFromPlan($plan, $state);
        if (count($products) < 2 && AssistantV2Prompt::conversational() && count($state['focus_ids']) < 2) {
            if (AssistantV2Filters::hasConstraint($state['filters'])) {
                return self::discoverOrSearch($state, $plan, '');
            }
            $roots = AssistantV2Catalog::usageRoots();
            if ($roots !== []) {
                return self::rootClarify($state, $plan, $roots, '', true);
            }
        }
        if (count($products) < 2) {
            return self::gapResult('missing_products', ['reason' => 'Karşılaştırma için iki kayıtlı ürün netleşmedi.'], $state, $plan);
        }
        $diff = AssistantV2Filters::compare($products);
        $cards = [];
        $named = [];
        foreach (array_slice($products, 0, 2) as $product) {
            $cards[] = AssistantV2Filters::card($product);
            $named[] = AssistantV2Filters::publicFacts($product, true);
        }
        $blocked = (bool) $plan['unverified'];

        return [
            'mode' => $blocked ? 'gap' : 'compare',
            'tool' => 'compare_products',
            'facts' => [
                'mode' => $blocked ? 'undecided' : 'compare',
                'products' => $named,
                'shared' => $diff['shared'],
                'different' => $diff['different'],
                'decision_blocked' => $blocked,
                'criterion' => $plan['criterion'] ?: null,
            ],
            'cards' => $cards,
            'total' => count($products),
            'facets' => [],
            'answered' => !$blocked,
            'gap' => $blocked ? 'unverified_difference' : null,
            'cta' => $blocked ? 'handoff' : 'none',
            'focus_ids' => array_map(static fn (array $product): int => (int) $product['id'], $products),
            'offset' => 0,
            'decision_blocked' => $blocked,
            'filters' => $state['filters'],
            'plan' => $plan,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function runLookup(array $plan, array $state, string $message = ''): array
    {
        $product = self::oneProduct($plan, $state);
        if ($product === null) {
            if (!AssistantV2Prompt::legacy()) {
                $outside = self::outsideCatalog($message, $state, $plan);

                return $outside ?? self::gapResult('missing_product', ['reason' => 'Bu ürün kaydı bulunamadı.'], $state, $plan);
            }

            return self::gapResult('missing_product', ['reason' => 'Bu ürün kaydı bulunamadı.'], $state, $plan);
        }
        $fact = (string) ($plan['arguments']['requested_fact'] ?? 'none');
        $spec = trim((string) ($plan['arguments']['requested_spec'] ?? ''));
        $facts = AssistantV2Filters::publicFacts($product, true);
        $gap = null;
        if ($fact === 'price' && ($facts['price'] === null || $facts['price'] === '')) {
            $gap = 'no_price';
        } elseif (
            $spec !== ''
            && !AssistantV2Filters::specPresent($product, $spec . ' ' . $message)
            && !AssistantV2Catalog::usageMatches($product, $spec)
            && !(AssistantV2Prompt::refined() && str_contains(AssistantV2Catalog::fold($spec . ' ' . $message), 'marka') && trim((string) ($product['brand'] ?? '')) !== '')
        ) {
            $gap = 'missing_spec';
            $facts['missing_spec'] = $spec;
        }
        if ($fact === 'stock' && $gap === null && !array_key_exists('in_stock', $product)) {
            $gap = 'no_stock';
        }

        return [
            'mode' => $gap === null ? 'lookup' : 'gap',
            'tool' => 'get_product_by_slug',
            'facts' => ['mode' => $gap === null ? 'lookup' : 'gap', 'product' => $facts, 'gap' => $gap],
            'cards' => $gap === null ? [AssistantV2Filters::card($product)] : [],
            'total' => 1,
            'facets' => [],
            'answered' => $gap === null,
            'gap' => $gap,
            'cta' => $gap === null ? 'none' : 'handoff',
            'focus_ids' => [(int) $product['id']],
            'offset' => 0,
            'filters' => $state['filters'],
            'plan' => $plan,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function runBusiness(array $plan, string $message): array
    {
        $topic = self::businessTopic((string) ($plan['arguments']['topic'] ?? ''), $message);
        $packed = AssistantV2Knowledge::business($topic);
        $cta = match ($topic) {
            'whatsapp' => 'none',
            'phone' => AssistantV2Knowledge::phoneAction() === null ? 'handoff' : 'phone',
            default => $packed['found'] ? 'none' : 'handoff',
        };
        if ($topic === 'phone' && AssistantV2Knowledge::phoneAction() === null) {
            $packed['found'] = false;
        }

        return self::knowledgeResult('business', $packed, $cta, $plan);
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function runService(array $plan, string $message): array
    {
        $topic = self::serviceTopic((string) ($plan['arguments']['topic'] ?? ''), $message);
        $packed = AssistantV2Knowledge::service($topic, $message);
        $cta = in_array($topic, ['shipping', 'form'], true) && $packed['found'] ? 'service' : ($packed['found'] ? 'none' : 'handoff');

        return self::knowledgeResult('service', $packed, $cta, $plan);
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function runContact(array $plan, string $message): array
    {
        $topic = self::contactTopic((string) ($plan['arguments']['topic'] ?? ''), $message);
        if ($topic === 'person') {
            return self::callbackResult(['focus_ids' => [], 'filters' => []], $plan);
        }
        $cta = match ($topic) {
            'whatsapp' => 'none',
            'phone' => 'phone',
            'service' => 'service',
            default => 'phone_lead',
        };
        $facts = match ($topic) {
            'whatsapp' => AssistantV2Knowledge::business('whatsapp'),
            'phone' => AssistantV2Knowledge::business('phone'),
            'service' => AssistantV2Knowledge::service('shipping'),
            default => ['facts' => [], 'found' => true, 'topic' => 'callback'],
        };
        if ($topic === 'phone' && AssistantV2Knowledge::phoneAction() === null) {
            $facts['found'] = false;
            $cta = 'handoff';
        }

        return self::knowledgeResult('contact', $facts, $cta, $plan);
    }

    /**
     * @param array{facts:list<string>,topic:string,found:bool} $packed
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function knowledgeResult(string $mode, array $packed, string $cta, array $plan): array
    {
        $tool = match ($mode) {
            'service' => 'get_service_facts',
            'business' => 'get_business_facts',
            default => 'get_contact_actions',
        };

        return [
            'mode' => $packed['found'] ? $mode : 'gap',
            'tool' => $tool,
            'facts' => ['mode' => $packed['found'] ? $mode : 'gap', 'topic' => $packed['topic'], 'facts' => $packed['facts']],
            'cards' => [],
            'total' => 0,
            'facets' => [],
            'answered' => $packed['found'],
            'gap' => $packed['found'] ? null : 'missing_knowledge',
            'cta' => $packed['found'] ? $cta : 'handoff',
            'focus_ids' => [],
            'offset' => 0,
            'plan' => $plan,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function unverifiedCompare(array $state, array $plan): array
    {
        $plan['unverified'] = true;
        if (count(self::productsFromPlan($plan, $state)) >= 2) {
            return self::runCompare($plan, $state);
        }

        return self::gapResult('unverified_difference', [
            'reason' => 'Bu konuda hangi ürünün üstün olduğunu gösteren kayıtlı bir veri yok.',
            'criterion' => $plan['criterion'] ?: 'unverified',
        ], $state, $plan, 'handoff');
    }

    private static function messageAsksPrice(string $message): bool
    {
        return preg_match('/f+iyat|teklif/u', AssistantV2Catalog::fold($message)) === 1;
    }

    /**
     * "Hangi ürün hakkında" sorusuna cevap, bir ürün, marka, kategori veya ölçü söyler.
     * Yeni bir ihtiyaç veya konu fiyat hedefini taşımaz.
     *
     * @param list<string> $slugs
     */
    private static function answersPriceQuestion(string $message, array $slugs): bool
    {
        if (!AssistantV2Prompt::conversational()) {
            return true;
        }
        if ($slugs !== [] || self::messageNamesCatalog($message)) {
            return true;
        }
        if (AssistantV2Catalog::unresolvedModel($message) !== null) {
            return true;
        }

        return self::statedPrecision($message) !== null || self::messageStatesCapacity($message);
    }

    /**
     * Mesaj katalogda bir marka, kullanım alanı, tür veya seri söylüyor mu.
     */
    private static function messageNamesCatalog(string $message): bool
    {
        if (AssistantV2Catalog::mentionedBrand($message) !== null || AssistantV2Catalog::statedUsage($message) !== null) {
            return true;
        }
        if (AssistantV2Catalog::ambiguousTypeToken($message) !== null) {
            return true;
        }
        $fold = AssistantV2Catalog::fold($message);
        foreach (AssistantV2Catalog::seriesList() as $series) {
            $token = AssistantV2Catalog::fold((string) $series);
            if ($token !== '' && preg_match('/(^| )' . preg_quote($token, '/') . '($| )/u', $fold) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fiyat sorusu açıkken servis, iletişim veya karşılaştırma ayrı konudur.
     *
     * @param array<string, mixed> $plan
     */
    private static function quoteStillOpen(array $plan): bool
    {
        $intent = (string) ($plan['intent'] ?? '');
        if (in_array($intent, ['business', 'service', 'contact', 'expert', 'small_talk', 'out_of_domain', 'unsupported', 'compare'], true)) {
            return false;
        }

        return empty($plan['unverified']);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function quoteReply(array $state, array $plan, string $kind, string $message = ''): array
    {
        if (!AssistantV2Prompt::legacy() && $message !== '') {
            $outside = self::outsideCatalog($message, $state, $plan);
            if ($outside !== null) {
                return $outside;
            }
        }
        $absent = is_string($plan['not_in_catalog'] ?? null) ? trim((string) $plan['not_in_catalog']) : '';
        if (
            AssistantV2Prompt::refined()
            && $absent !== ''
            && $message !== ''
            && ($state['focus_ids'] ?? []) === []
            && AssistantV2Catalog::statedUsage($message) === null
            && AssistantV2Catalog::matchedChildName($message) === null
            && AssistantV2Catalog::nearCatalog($message, []) === []
        ) {
            return self::unlistedQuote($state, $plan, $absent);
        }
        $products = $kind === 'price'
            ? self::priceProducts($plan, $state)
            : self::productsFromPlan($plan, $state);
        if ($kind === 'price' && $products === []) {
            if (AssistantV2Filters::hasConstraint($state['filters'])) {
                if (!AssistantV2Filters::specificEnough($state['filters'], $state['asked'])) {
                    return self::discoverOrSearch($state, $plan, $message);
                }
                $found = AssistantV2Filters::search($state['filters'], 0, 4);
                foreach ($found['page'] as $product) {
                    if (is_array($product)) {
                        $products[] = $product;
                    }
                }
                if ($products === []) {
                    $empty = self::runSearch($state, 0, 'list', false, $plan);
                    $empty['goal'] = '';

                    return $empty;
                }
            } else {
                return [
                    'mode' => 'price_which',
                    'tool' => 'none',
                    'facts' => ['mode' => 'price_which'],
                    'cards' => [],
                    'total' => 0,
                    'facets' => [],
                    'answered' => true,
                    'gap' => null,
                    'cta' => 'none',
                    'focus_ids' => $state['focus_ids'],
                    'offset' => 0,
                    'filters' => $state['filters'],
                    'plan' => $plan,
                    'reply' => 'Hangi ürün hakkında fiyat almak istiyorsunuz?',
                    'goal' => 'price',
                ];
            }
        }
        if ($kind !== 'price' && $products === [] && AssistantV2Filters::hasConstraint($state['filters'])) {
            $found = AssistantV2Filters::search($state['filters'], 0, 8);
            $byId = AssistantV2Catalog::byId();
            foreach (array_slice($found['all_ids'], 0, 4) as $id) {
                if (isset($byId[$id])) {
                    $products[] = $byId[$id];
                }
            }
        }
        $known = [];
        $missing = false;
        foreach ($products as $product) {
            if ($kind === 'price') {
                $amount = $product['price_try'] ?? null;
                $label = trim((string) ($product['price_label'] ?? ''));
                if ($label !== '' && is_numeric($amount) && (float) $amount > 0) {
                    $known[] = ['name' => (string) $product['name'], 'price' => $label];
                } else {
                    $missing = true;
                }
            } else {
                if (array_key_exists('in_stock', $product) && is_bool($product['in_stock'])) {
                    $known[] = ['name' => (string) $product['name'], 'in_stock' => $product['in_stock']];
                } else {
                    $missing = true;
                }
            }
        }
        $ids = array_map(static fn (array $product): int => (int) $product['id'], $products);
        if ($products !== [] && !$missing) {
            return [
                'mode' => 'lookup',
                'tool' => 'search_products',
                'facts' => ['mode' => 'lookup', 'kind' => $kind, 'known' => $known],
                'cards' => [],
                'total' => count($products),
                'facets' => [],
                'answered' => true,
                'gap' => null,
                'cta' => 'none',
                'focus_ids' => $ids,
                'offset' => 0,
                'filters' => $state['filters'],
                'plan' => $plan,
                'goal' => '',
            ];
        }
        if ($kind === 'price') {
            $line = count($products) > 1
                ? 'Bu ürünler hakkında fiyat almak için WhatsApp hattımızdan bize yazabilir, anında teklif alabilirsiniz.'
                : 'Bu ürün hakkında fiyat almak için WhatsApp hattımızdan bize yazabilir, anında teklif alabilirsiniz.';

            return [
                'mode' => 'no_price',
                'tool' => 'search_products',
                'facts' => [
                    'mode' => 'no_price',
                    'gap' => 'no_price',
                    'kind' => 'price',
                    'reason' => 'Yayınlanmış fiyat bu ürünler için doğrulanamadı.',
                ],
                'cards' => [],
                'total' => count($products),
                'facets' => [],
                'answered' => false,
                'gap' => 'no_price',
                'cta' => 'price_request',
                'price_products' => $products,
                'focus_ids' => $ids !== [] ? $ids : $state['focus_ids'],
                'offset' => 0,
                'filters' => $state['filters'],
                'plan' => $plan,
                'reply' => AssistantV2Prompt::conversational() ? null : $line,
                'goal' => '',
            ];
        }

        return [
            'mode' => 'gap',
            'tool' => 'search_products',
            'facts' => [
                'mode' => 'gap',
                'gap' => 'no_stock',
                'kind' => $kind,
                'reason' => 'Stok bilgisi bu ürünler için doğrulanamadı.',
            ],
            'cards' => [],
            'total' => count($products),
            'facets' => [],
            'answered' => false,
            'gap' => 'no_stock',
            'cta' => 'handoff',
            'focus_ids' => $ids !== [] ? $ids : $state['focus_ids'],
            'offset' => 0,
            'filters' => $state['filters'],
            'plan' => $plan,
        ];
    }

    /**
     * Fiyat sorusunda yalnızca bu mesajda adı geçen ürün ya da daha önce gösterilen ürünler sayılır.
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $state
     * @return list<array<string, mixed>>
     */
    private static function priceProducts(array $plan, array $state): array
    {
        $byId = AssistantV2Catalog::byId();
        $bySlug = AssistantV2Catalog::bySlug();
        $args = is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [];
        $out = [];
        $slug = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        if ($slug !== '' && isset($bySlug[$slug])) {
            $out[(int) $bySlug[$slug]['id']] = $bySlug[$slug];
        }
        foreach (is_array($args['slugs'] ?? null) ? $args['slugs'] : [] as $item) {
            if (is_string($item) && isset($bySlug[$item])) {
                $out[(int) $bySlug[$item]['id']] = $bySlug[$item];
            }
        }
        foreach (is_array($args['ids'] ?? null) ? $args['ids'] : [] as $id) {
            $id = (int) $id;
            if (isset($byId[$id])) {
                $out[$id] = $byId[$id];
            }
        }
        $id = (int) ($args['id'] ?? 0);
        if ($id > 0 && isset($byId[$id])) {
            $out[$id] = $byId[$id];
        }
        if ($out === []) {
            foreach ($state['focus_ids'] as $focusId) {
                $focusId = (int) $focusId;
                if (isset($byId[$focusId])) {
                    $out[$focusId] = $byId[$focusId];
                }
            }
        }

        return array_slice(array_values($out), 0, 4);
    }

    /**
     * Katalogda karşılığı olmayan ihtiyaç için teklif. Reddetmez.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function unlistedQuote(array $state, array $plan, string $raw = ''): array
    {
        $need = $raw !== '' ? $raw : self::needPhrase($state['filters']);
        $legacy = AssistantV2Prompt::legacy();

        return [
            'mode' => 'unlisted',
            'tool' => 'search_products',
            'facts' => [
                'mode' => 'unlisted',
                'gap' => 'unlisted',
                'requested' => $need,
                'reason' => 'Bu model yayınlanmış katalog kaydında yok. Tedarik sorulabilir.',
            ],
            'cards' => [],
            'total' => 0,
            'facets' => [],
            'answered' => false,
            'gap' => 'unlisted',
            'cta' => 'price_request',
            'price_products' => [],
            'need' => $need,
            'focus_ids' => [],
            'offset' => 0,
            'filters' => $state['filters'],
            'plan' => $plan,
            'reply' => $legacy
                ? 'Bu ürün hakkında fiyat almak için WhatsApp hattımızdan bize yazabilir, anında teklif alabilirsiniz.'
                : null,
            'goal' => '',
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @return array<string, mixed>|null
     */
    private static function outsideCatalog(string $message, array $state, array $plan): ?array
    {
        $token = AssistantV2Catalog::unresolvedModel($message);
        if ($token === null) {
            return null;
        }

        $result = self::unlistedQuote($state, $plan, $token);
        if (AssistantV2Prompt::conversational()) {
            $brand = AssistantV2Catalog::mentionedBrand($message);
            if ($brand !== null) {
                $models = self::brandModels($brand, $token);
                if ($models !== []) {
                    $result['facts']['brand'] = $brand;
                    $result['facts']['brand_models'] = $models;
                }
            }
        }

        return $result;
    }

    /**
     * Sitede olmayan model için aynı markadan gerçek kayıtlar. Sayıca en yakın kapasite önde.
     *
     * @return list<array{name:string,capacity:string,precision:string}>
     */
    private static function brandModels(string $brand, string $token): array
    {
        $digits = preg_replace('/\D+/u', '', $token) ?? '';
        $rows = [];
        foreach (AssistantV2Catalog::products() as $product) {
            if (AssistantV2Catalog::fold((string) ($product['brand'] ?? '')) !== AssistantV2Catalog::fold($brand)) {
                continue;
            }
            $capacity = trim((string) ($product['capacity_label'] ?? ''));
            if ($capacity === '' || AssistantV2Catalog::kindOf($product) !== 'terazi') {
                continue;
            }
            $hit = $digits !== '' && str_contains(preg_replace('/\D+/u', '', $capacity) ?? '', $digits) ? 0 : 1;
            $rows[] = [
                'hit' => $hit,
                'order' => (int) ($product['sort_order'] ?? 0),
                'name' => (string) $product['name'],
                'capacity' => $capacity,
                'precision' => trim((string) ($product['precision_label'] ?? '')),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => ($a['hit'] <=> $b['hit']) ?: ($a['order'] <=> $b['order']));
        $out = [];
        foreach (array_slice($rows, 0, 4) as $row) {
            $out[] = ['name' => $row['name'], 'capacity' => $row['capacity'], 'precision' => $row['precision']];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private static function needPhrase(array $filters): string
    {
        $bits = [];
        foreach (is_array($filters['brands'] ?? null) ? $filters['brands'] : [] as $brand) {
            if (is_string($brand) && $brand !== '') {
                $bits[] = $brand;
            }
        }
        $group = self::groupLabel($filters);
        if ($group !== '') {
            $bits[] = $group;
        }
        $precision = is_array($filters['precision'] ?? null) ? $filters['precision'] : [];
        if (is_numeric($precision['value'] ?? null)) {
            $unit = (string) ($precision['unit'] ?? 'g');
            $bits[] = rtrim(rtrim((string) $precision['value'], '0'), '.') . $unit;
        }
        $capacity = is_array($filters['capacity'] ?? null) ? $filters['capacity'] : [];
        if (is_numeric($capacity['value_g'] ?? null)) {
            $bits[] = (string) $capacity['value_g'] . 'g';
        }

        return implode(' ', $bits);
    }

    private static function unlistedPriceAction(string $need): ?array
    {
        $number = site_whatsapp_number();
        if ($number === '') {
            return null;
        }
        $text = 'Merhaba, fiyat almak istiyorum.' . ($need !== '' ? ' ' . $need : '');

        return [
            'type'  => 'price_request',
            'role'  => 'primary',
            'label' => 'Tedarik durumunu sorun',
            'url'   => 'https://wa.me/' . $number . '?text=' . rawurlencode($text),
        ];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return array<string, mixed>|null
     */
    private static function priceRequestAction(array $products): ?array
    {
        if ($products === []) {
            return null;
        }
        if (count($products) === 1) {
            $url = product_whatsapp_url($products[0]);
        } else {
            $number = site_whatsapp_number();
            $names = [];
            foreach ($products as $product) {
                $name = trim((string) ($product['name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            $text = 'Merhaba, şu ürünler için fiyat alabilir miyim? ' . implode(', ', $names);
            $url = $number !== '' ? ('https://wa.me/' . $number . '?text=' . rawurlencode($text)) : '';
        }
        if (!is_string($url) || preg_match('#\Ahttps://wa\.me/#', $url) !== 1) {
            return null;
        }

        return [
            'type'  => 'price_request',
            'role'  => 'primary',
            'label' => 'Hemen Fiyat Al',
            'url'   => $url,
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $state
     * @param array<string, mixed>|null $plan
     * @return array<string, mixed>
     */
    private static function gapResult(string $gap, array $extra, array $state, ?array $plan = null, string $cta = 'handoff'): array
    {
        return [
            'mode' => 'gap',
            'tool' => $plan['tool'] ?? 'none',
            'facts' => array_merge(['mode' => 'gap', 'gap' => $gap], $extra),
            'cards' => [],
            'total' => 0,
            'facets' => [],
            'answered' => false,
            'gap' => $gap,
            'cta' => $cta,
            'focus_ids' => $state['focus_ids'],
            'offset' => 0,
            'filters' => $state['filters'],
            'plan' => $plan,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function chatResult(string $domain): array
    {
        return [
            'mode' => $domain,
            'tool' => 'none',
            'facts' => ['mode' => $domain],
            'cards' => [],
            'total' => 0,
            'facets' => [],
            'answered' => true,
            'gap' => null,
            'cta' => 'none',
            'focus_ids' => [],
            'offset' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $state
     * @return list<array<string, mixed>>
     */
    private static function actions(array $result, string $message, array $state): array
    {
        $cta = (string) ($result['cta'] ?? 'none');

        if ($cta === 'price_request') {
            $products = is_array($result['price_products'] ?? null) ? $result['price_products'] : [];
            $action = $products !== []
                ? self::priceRequestAction($products)
                : self::unlistedPriceAction((string) ($result['need'] ?? ''));

            return $action !== null ? [$action] : [];
        }

        $actions = match ($cta) {
            'human_handoff' => AssistantV2Knowledge::humanHandoffActions($message, self::focusProductIds($state, $result)),
            'handoff', 'person', 'whatsapp_contact', 'phone_lead' => array_values(array_filter([self::teamWriteAction($message, $state, $result)])),
            'phone' => array_values(array_filter([AssistantV2Knowledge::phoneAction()])),
            'service' => array_values(array_filter([AssistantV2Knowledge::serviceAction()])),
            'expert_compare' => array_values(array_filter([AssistantV2Knowledge::compareAction(), self::teamWriteAction($message, $state, $result)])),
            default => [],
        };
        if ($actions === [] && ($result['mode'] ?? '') === 'list' && is_array($result['cards'] ?? null) && $result['cards'] !== []) {
            $price = self::catalogListPriceAction();
            if ($price !== null) {
                $actions[] = $price;
            }
        }

        return $actions;
    }

    /**
     * Liste altındaki yeşil fiyat butonu. Numara ayardan gelir, cümleye yazılmaz.
     *
     * @return array<string, mixed>|null
     */
    private static function catalogListPriceAction(): ?array
    {
        $number = site_whatsapp_number();
        if ($number === '') {
            return null;
        }

        return [
            'type'  => 'price_request',
            'role'  => 'primary',
            'label' => 'Hemen Fiyat Al',
            'url'   => 'https://wa.me/' . $number . '?text=' . rawurlencode('Merhaba, fiyat almak istiyorum.'),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $result
     * @return array<string, mixed>|null
     */
    private static function teamWriteAction(string $message, array $state, array $result = []): ?array
    {
        $source = is_array($result['focus_ids'] ?? null) && $result['focus_ids'] !== []
            ? $result['focus_ids']
            : ($state['focus_ids'] ?? []);
        $ids = [];
        if (is_array($source)) {
            foreach ($source as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }

        return AssistantV2Knowledge::whatsappWriteAction($message, array_values($ids));
    }

    /**
     * @param list<array<string, mixed>> $actions
     */
    private static function withCallbackInvite(string $text, array $actions): string
    {
        $text = self::withoutPhoneInvite($text);
        $offers = false;
        foreach ($actions as $action) {
            if (($action['type'] ?? '') === 'whatsapp_write' && preg_match('#\Ahttps://wa\.me/#', (string) ($action['url'] ?? '')) === 1) {
                $offers = true;
                break;
            }
        }
        if (!$offers || str_contains(AssistantV2Catalog::fold($text), 'whatsapp')) {
            return $text;
        }
        $invite = "WhatsApp'tan yazabilirsiniz.";
        $text = rtrim($text);
        if ($text !== '' && preg_match('/[.!?]$/u', $text) !== 1) {
            $text .= '.';
        }

        return trim($text . ($text !== '' ? ' ' : '') . $invite);
    }

    private static function withoutPhoneInvite(string $text): string
    {
        $text = preg_replace('/\s*[^.!?\n]*numaranızı bırakın[^.!?\n]*[.!?]?/iu', '', $text) ?? $text;

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text);
    }

    private static function withoutSentClaim(string $text): string
    {
        $text = preg_replace('/\s*[^.!?\n]*(?:ilettim|iletildi|gönderildi|mesajınız hazır|WhatsApp açıldı)[^.!?\n]*[.!?]?/iu', '', $text) ?? $text;

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function callbackResult(array $state, array $plan): array
    {
        $result = [
            'mode' => 'callback',
            'tool' => 'get_contact_actions',
            'facts' => ['mode' => 'callback'],
            'cards' => [],
            'total' => 0,
            'facets' => [],
            'answered' => false,
            'gap' => 'callback',
            'cta' => 'phone_lead',
            'focus_ids' => is_array($state['focus_ids'] ?? null) ? $state['focus_ids'] : [],
            'offset' => 0,
            'plan' => $plan,
        ];
        if (is_array($state['filters'] ?? null) && $state['filters'] !== []) {
            $result['filters'] = $state['filters'];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function offersHandoff(array $result): bool
    {
        if (!empty($result['answered'])) {
            return false;
        }

        return in_array((string) ($result['cta'] ?? ''), ['handoff', 'human_handoff', 'expert_compare', 'person', 'whatsapp_contact', 'phone_lead'], true);
    }

    /**
     * @param list<array{role:string,text:string}> $history
     * @param array<string, mixed> $facts
     */
    private static function write(string $message, array $history, array $facts, string $mode): string
    {
        if (self::freeLlmMode()) {
            $body = json_encode([
                'task' => 'Müşteriye doğal Türkçe cevap yaz. facts içindeki doğrulanmış bilgileri kullan. JSON: {"message":"..."}',
                'history' => array_slice($history, -6),
                'question' => $message,
                'facts' => $facts,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $json = is_string($body) ? AssistantV2Llm::complete(AssistantV2Prompt::voice(), $body, 18, 'v2_write_free') : null;
            $text = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';
            if ($text !== '') {
                return str_replace(';', ',', $text);
            }

            return '';
        }
        $conversational = AssistantV2Prompt::conversational();
        if ($conversational && !self::asksStock($message)) {
            $facts = self::withoutStock($facts);
        }
        $task = match ($mode) {
            'clarify' => $conversational
                ? 'Kart yok. Katalog slug söyleme. Önce kullanıcının bu turda ve geçmişte söylediği ihtiyacı (marka, numune, iş, ölçü) tek kısa cümleyle kabul et; Merhaba ile başlama, aynı kalıp giriş kullanma. facts.consultant_hints varsa rehber al, madde madde okuma. Sonra yalnızca facts.ask için tek soru sor. ask moisture_form: numunenin sıvı mı katı mı olduğunu sor, kurutma yöntemi seçimini kısaca gerekçelendir. ask moisture_use: hangi numune/grup için ölçüm yapılacağını sor. ask moisture_temp: prosedürdeki maksimum kurutma sıcaklığını sor, °C seçenekleri düğmede. ask sample_goal: nem tayini mi tartım mı netleştir. ask subcategory: cihaz türü. Hassasiyette ölçüm hassasiyetini sor. Kapasitede en ağır yükü sor. ask root: kullanım alanı. ask usage: ne tartılacağı veya iş ihtiyacı (ölçü sorma). Seçenekler düğmede, cümlede tek tek sayma. Cihaz kapasitesine üst sınır diye sorma. Tek soru işareti.'
                : 'Kart yok. Alt kategori veya katalog slug sorma. Yalnızca facts.ask için tek soru sor. Hassasiyette ölçüm hassasiyetini sor. Kapasitede kullanıcının kaç kapasiteye kadar ürün tartacağını sor. Cihaz kapasitesine üst sınır diye sorma. Seçenekleri cümlede tek tek sayma. facts.candidates varsa adları cümlede say.',
            'near' => 'Tam eşleşme yoksa bunu söyle. En yakın kapasiteyi yakın seçenek olarak adlandır.' . self::USAGE_WRITE,
            'count' => 'total_result_count değerini söyle. displayed_count toplam değildir.',
            'list' => 'Kartlar ayrıca görünecek. Kartların bütün alanlarını tekrar etme; ürün adı veya model kodunu cümle içinde en fazla bir kez, kısaca kullan. Birden fazla modeli metin içinde sıralama veya listeleme. Tek ürün varsa kapasite ve hassasiyeti facts.displayed specs satırındaki metinle söyle. Aralık uydurma. Spec satırında olmayan alanı yazma. Toplam model sayısıyla başlama. Kullanıcı marka söylemediyse tek marka ilan etme. Tekrarlanabilirlik facts içinde yoksa söyleme. Ekrandaki kart sayısı toplam değildir. search_filter dışındaki kök kategoriyi arama kapsamı diye söyleme.' . self::USAGE_WRITE,
            'more' => 'Sonraki kartlar eklenecek. Tek kısa cümle. Ürün adı, toplam sayı ve yeni soru yok.',
            'rank' => 'Kazananı doğrulanmış alanlarla anlat. Fiyatı olmayan ürünü sıfır fiyat sayma.' . self::USAGE_WRITE,
            'no_price' => 'Kart yok. Ürün adı sayma. Güncel fiyatın burada doğrulanamadığını söyle. Numara isteme, WhatsApp veya kanal sayma. Ürün bulunamadı deme.',
            'unlisted' => 'Model uydurma. Satmıyoruz veya temin edemeyiz deme. Stok veya kesin tedarik sözü verme. Sitede listelenmediğini söyle. Tedarik ve fiyat için WhatsApp seçeneğinin hazır olduğunu söyle. Numarayı yazma.',
            'gap' => 'Eksikliği kısa ve doğal açıkla. Yalnızca bilmiyorum deme. Yeni soru sorma. Numara isteme. Mesajın gönderildiğini veya iletildiğini söyleme. WhatsApp veya kanal adı yazma.' . self::USAGE_WRITE,
            'callback' => 'Tek cümle kur: WhatsApp\'tan yazabilirsiniz. Numara isteme. Mesajın iletildiğini veya gönderildiğini söyleme. Telefon numarası yazma.',
            'compare' => 'Ortak ve farklı kayıtları yorumla. Kazanan ilan etme. Yalnızca facts içindeki spec satırlarını kullan. Kayıtta olmayan özelliği söyleme.' . self::USAGE_WRITE,
            'spec' => 'Kart yok. Yalnızca facts.products içindeki spec satırlarından kısa cevap yaz, en fazla üç cümle. Kapasite, hassasiyet ve diğer satırları aynen kullan. Kayıtta olmayan özelliği uydurma; o konu yoksa kayıtta yok de. Sıvı veya katı ayrımı satırlarda yoksa uygun diye yazma. Ürün listeleme.',
            'undecided' => 'Kayıtlı temel bilgiler yakınsa bunu söyle. Üstünlük verisi yoksa kazanan seçme ve yeni soru sorma.',
            'lookup' => 'Doğrulanmış fiyat veya stok varsa söyle. Kapasite, hassasiyet veya başka bir teknik soruda yalnızca facts.product.specs satırını kullan ve metni aynen söyle. Aralık uydurma. Kartları yeniden listeleme.' . self::USAGE_WRITE,
            'small_talk' => 'Kısa selam ver ve yardımcı olmayı teklif et. Kullanıcı halini sormadıysa iyiyim, teşekkür ederim veya kendi durumun yok. Hal sorulursa tekil iyiyim de. Ürün, fiyat veya ekibe bağlanma yok.',
            'out_of_domain' => 'İstenen olguyu cevaplama. Başka uygulama önerme. Ekibe bağlanma. Şu anlama yakın kal: Bu konuda yardımcı olamıyorum. Terazi, ürün seçimi veya servisle ilgili bir konuda yardımcı olabilirim.',
            'service', 'business', 'contact' => 'facts.facts doluysa bu bilgileri birinci çoğul şahısla söyle. Bilgi yok veya ulaşamıyoruz deme. sunmaktadır, kayıtlarımızda ve sayfasında belirtiliyor deme.',
            'empty' => 'Koşula uyan kayıt olmadığını söyle. facts.series veya marka doluysa o kısıtın durduğunu söyle. Başka serinin ürününü sayma. Aynı markada seri kısıtını kaldırmayı en fazla bir soruyla teklif edebilirsin. Model uydurma.',
            default => 'Doğrulanmış olguları kısa ve doğal yaz. Yeni olgu ekleme.',
        };
        if ($conversational) {
            $task = self::conversationalTask($mode, $task);
        }
        $body = json_encode([
            'task' => $task . ' JSON: {"message":"..."}',
            'history' => array_slice($history, -4),
            'question' => $message,
            'facts' => $facts,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $text = '';
        $notes = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $prompt = is_string($body) ? $body : '';
            if ($notes !== []) {
                $prompt .= "\n" . (string) json_encode(['fix' => $notes], JSON_UNESCAPED_UNICODE);
            }
            $json = $prompt !== '' ? AssistantV2Llm::complete(AssistantV2Prompt::voice(), $prompt, 16, 'v2_write') : null;
            $text = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';
            $notes = self::styleNotes($text, $facts, $mode, $message);
            if ($text !== '' && $notes === []) {
                break;
            }
        }
        $text = str_replace(';', ',', $text);
        if (AssistantV2Prompt::refined()) {
            // Panel düz metin gösterir: markdown ve telefon numarası metne girmez (WhatsApp düğmesi ayrı).
            $text = trim(str_replace(['**', '__', '`'], '', $text));
            $text = trim((string) preg_replace('/\s*:?\s*\(?\+?9?0?[\s-]?\(?5\d{2}\)?[\s-]?\d{3}[\s-]?\d{2}[\s-]?\d{2}\b/u', '', $text));
        }
        if ($notes !== [] && in_array($mode, ['service', 'business', 'contact'], true)) {
            $lines = array_values(array_filter(
                is_array($facts['facts'] ?? null) ? $facts['facts'] : [],
                static fn (mixed $line): bool => is_string($line) && trim($line) !== ''
            ));
            if ($lines !== []) {
                $text = str_replace(';', ',', implode(' ', $lines));
                $notes = [];
            }
        }
        if ($notes !== [] && in_array($mode, ['list', 'near'], true)) {
            $blocked = false;
            foreach ($notes as $note) {
                if (
                    str_contains($note, 'tekrarlan')
                    || str_contains($note, 'marka')
                    || str_contains($note, 'olmayan sayı')
                    || str_contains($note, 'aralık')
                    || str_contains($note, 'ürün ad')
                ) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked && (int) ($facts['displayed_count'] ?? 0) === 1) {
                $grounded = self::groundedMeasureLine($facts);
                $text = $grounded !== '' ? $grounded : $text;
                $notes = $grounded !== '' ? [] : $notes;
            } elseif ($blocked) {
                $text = self::consultantListFallback($facts);
                $notes = [];
            }
        }
        if ($notes !== [] && $mode === 'count') {
            $total = (int) ($facts['total_result_count'] ?? 0);
            if ($total > 0) {
                $text = 'Bu kriterlerle toplam ' . $total . ' model bulunuyor.';
            }
        }
        if ($mode === 'spec') {
            $guarded = self::specGuard($text, $facts, $message);
            if ($guarded !== null) {
                $text = $guarded;
            }
        }
        if ($notes !== [] && $mode === 'more') {
            $text = 'Sonraki seçenekler aşağıda.';
        }
        if ($notes !== [] && $mode === 'unlisted') {
            $name = trim((string) ($facts['name'] ?? $facts['requested'] ?? ''));
            $text = $name !== ''
                ? $name . ' sitemizdeki yayınlanmış kayıtlarda görünmüyor. Tedarik ve fiyat bilgisi için WhatsApp üzerinden ekibimize yazabilirsiniz.'
                : 'Bu model sitemizde listelenmiyor. Tedarik ve fiyat bilgisi için WhatsApp üzerinden ekibimize yazabilirsiniz.';
            $notes = [];
        }
        if ($notes !== [] && ($facts['mode'] ?? '') === 'no_price') {
            $text = 'Bu ürünlerin güncel fiyatını burada doğrulayamıyorum.';
            $notes = [];
        }
        if ($notes !== [] && $mode === 'callback') {
            $text = "WhatsApp'tan yazabilirsiniz.";
            $notes = [];
        }
        if (in_array($mode, ['gap', 'callback'], true)) {
            $foldNow = AssistantV2Catalog::fold($text);
            if (str_contains($foldNow, 'numaranizi birakin') || preg_match('/ilettim|iletildi|gonderildi|mesajiniz hazir|whatsapp acildi/u', $foldNow) === 1) {
                $text = $mode === 'callback'
                    ? "WhatsApp'tan yazabilirsiniz."
                    : self::withoutSentClaim(self::withoutPhoneInvite($text));
            }
        }
        if ($mode === 'small_talk' && !self::askedWellbeing($message) && preg_match('/(?<![\p{L}])iyiyim(?![\p{L}])/u', AssistantV2Catalog::fold($text)) === 1) {
            $text = 'Merhaba, size nasıl yardımcı olabilirim?';
        }
        if ($mode === 'clarify' && str_contains(AssistantV2Catalog::fold($text), 'hangi urun')) {
            $ask = (string) ($facts['ask'] ?? '');
            $text = match ($ask) {
                'usage' => 'Hangi alanda kullanmayı düşünüyorsunuz? Örn. Kuyumcu, Laboratuvar, Endüstriyel vb.',
                'capacity' => 'Kaç kapasiteye kadar ürün tartmayı düşünüyorsunuz?',
                'brand' => 'Hangi markayı tercih ediyorsunuz?',
                default => 'Hangi ölçüm hassasiyetine ihtiyacınız var?',
            };
        }
        $asksMeasure = preg_match('/kapasite|hassasiyet/u', AssistantV2Catalog::fold($message)) === 1;
        if ($mode === 'lookup' && $asksMeasure) {
            $pair = self::measurePair($facts);
            $fold = AssistantV2Catalog::fold($message);
            $bits = [];
            if (str_contains($fold, 'kapasite') && $pair['capacity'] !== '') {
                $bits[] = 'Kapasitesi ' . $pair['capacity'];
            }
            if (str_contains($fold, 'hassasiyet') && $pair['precision'] !== '') {
                $bits[] = 'Hassasiyeti ' . $pair['precision'];
            }
            if ($bits === [] ) {
                $grounded = self::groundedMeasureLine($facts);
                if ($grounded !== '') {
                    $text = $grounded;
                }
            } else {
                $text = implode(', ', $bits) . '.';
            }
        } elseif ($mode === 'list' && (int) ($facts['displayed_count'] ?? 0) === 1) {
            $grounded = self::groundedMeasureLine($facts);
            $strict = !AssistantV2Prompt::conversational() && !self::citesSpecMeasures($text, $facts);
            if ($grounded !== '' && ($text === '' || self::inventedMeasureRange($text, $facts) || $strict)) {
                $text = $grounded;
            }
        }
        if ($mode === 'clarify' && AssistantV2Prompt::conversational()) {
            $aligned = self::alignClarifyReply($text, (string) ($facts['ask'] ?? ''), $facts);
            if ($aligned !== null) {
                $text = $aligned;
            } elseif ($text === '' || $notes !== []) {
                $fallbackLine = self::clarifyLine((string) ($facts['ask'] ?? ''));
                if ($fallbackLine !== '') {
                    $text = $fallbackLine;
                }
            }
        }
        if ($text === '' && $mode === 'list' && (int) ($facts['displayed_count'] ?? 0) > 0) {
            $text = self::listIntroFallback([], $facts);
        }
        if (in_array($mode, ['list', 'near'], true) && (int) ($facts['displayed_count'] ?? 0) > 0) {
            $text = self::polishListReply($text, $facts);
        }
        if ($text === '') {
            $text = 'Şu anda yanıtı netleştiremedim. Soruyu biraz daha açık yazarsanız yeniden bakalım.';
        }

        return $text;
    }

    /**
     * Sürüm 3 yazıcı görevleri. Kalıp cümle vermez, davranış ilkesi verir.
     */
    private static function conversationalTask(string $mode, string $default): string
    {
        return match ($mode) {
            'clarify' => 'Kart yok. Deneyimli satış danışmanı gibi yaz: önce ihtiyacı kısaca yansıt, sonra facts.ask için tek net soru. Nem tayininde prosedür ve numune tipini önemse. Robot kalıbı ve tekrarlayan giriş yok. Seçenekleri düğmede bırak.',
            'list' => 'Kartlar ayrıca görünecek, kart alanlarını tekrar etme. Danışman gibi en fazla iki kısa cümle yaz; detay kartlarda. Ürün adı veya model kodunu metinde en fazla bir kez kullan, asla üç veya daha fazla modeli cümle içinde sıralama. facts.user_need ve konuşma geçmişindeki ihtiyaca bağla: neden uygun olduklarını specs satırlarına dayanarak özetle. Kapasite ve hassasiyeti satırdaki metinle yaz, aralık uydurma, satırda olmayan özelliği yazma. Tek ürün varsa kısaca tanıt. Birden fazla ürün varsa facts.differences içindeki tek bir gerçek farkla seçime yardım et; gerekirse sonda tek doğal soru (tek soru işareti). Seçtiğiniz ölçülere uyan veya aşağıda diye başlama. Toplam model sayısıyla başlama. Kullanıcı marka söylemediyse tek marka ilan etme. Tekrarlanabilirlik facts içinde yoksa söyleme. Ekrandaki kart sayısı toplam değildir. Stok söyleme. facts.second_hand true ise bunların 2. el olduğunu söyle, total_result_count kaç 2. el ürün olduğudur.' . self::USAGE_WRITE,
            'compare' => 'Ortak ve farklı kayıtları yorumla. Müşterinin söylediği ihtiyaca (konuşma geçmişi) göre hangisinin neden uygun olduğunu söyle ve kısa bir öneri yap. İhtiyaç bilinmiyorsa farkları anlat ve ihtiyacı tek soruyla sor. Yalnızca facts içindeki spec satırlarını kullan. Kayıtta olmayan özellik, dayanıklılık, ömür veya kalite üstünlüğü iddia etme. Kapasite ve hassasiyeti satırdaki gibi yaz.' . self::USAGE_WRITE,
            'undecided' => 'Ürünlerin kayıtlarında ömür, dayanıklılık veya kalite gibi bir üstünlük verisi olmadığını açıkla. Yine de kayıtlı farklara (kapasite, hassasiyet, kalibrasyon, ekran gibi) ve müşterinin ihtiyacına göre hangisinin neden uygun olabileceğini söyle. İhtiyaç belli değilse tek soruyla netleştir.',
            'rank' => 'Kazananı doğrulanmış alanlarla ve kısa gerekçeyle anlat. Fiyatı olmayan ürünü sıfır fiyat sayma. İstersen sonda alternatif bakılabileceğini tek cümleyle belirt.' . self::USAGE_WRITE,
            'no_price' => 'Kart yok. Ürün adı sayma. Bu ürünler için güncel fiyatın burada gösterilmediğini söyle ve ekranda çıkan Hemen Fiyat Al düğmesiyle öğrenilebileceğini belirt. Daha ucuz veya en ucuz soruluyorsa fiyatlar yayınlanmadığı için sıralama yapılamadığını açıkça söyle. Numara isteme, WhatsApp kelimesini kullanma. Ürün bulunamadı deme. Önceki cevabı aynen tekrarlama.',
            'spec' => 'Kart yok. Yalnızca facts.products içindeki spec satırlarından cevap yaz, en fazla üç cümle. Sorulan özelliği önce söyle, değerleri satırdaki gibi kullan. Kayıtta olmayan özelliği uydurma, kayıtta yoksa bunu kısaca söyle. Sıvı veya katı ayrımı satırlarda yoksa uygun diye yazma. Ürün listeleme. Kullanıcı bilmiyor, stajyer veya emin değilse yargısız destekle, prosedür veya sorumlu kişiye danışmayı öner; model kartı veya satın alma baskısı yapma. Gerekirse sonda tek bir doğal devam sorusu sor.',
            'guidance' => 'Kart yok. Kullanıcı emin değil, kararsız veya hangisini seçeceğini bilmiyor. Empatiyle kısa cevap ver. Seçim için 2–3 net kriter sırala (ör. prosedürdeki kurutma sıcaklığı, numune kapasitesi, yöntemdeki hassasiyet). facts.decision_criteria varsa onları kullan. facts.example_models en fazla iki kısa model adı olabilir, spec detayına girme. Tek doğal soru sorabilirsin (prosedür sıcaklığı belli mi). Ürün gridi yok. Numara yazma.',
            'lookup' => $default . ' Marka sorulursa facts.product.brand değerini söyle. Cevabı müşterinin ihtiyacına bağla ve gerekirse sonda tek bir devam sorusu sor.',
            'unlisted' => $default . ' facts.brand_models doluysa aynı markadan bu gerçek kayıtların sitede bulunduğunu söyle, istenen ölçüye yakın olanı öne çıkar ve bunlara bakmak isteyip istemediğini sor. Listede olmayan model veya ölçü yazma.',
            default => $default,
        };
    }

    private static function asksStock(string $message): bool
    {
        return preg_match('/stok|mevcut mu|elinizde|hemen teslim/u', AssistantV2Catalog::fold($message)) === 1;
    }

    /**
     * Stok bilgisi yalnızca sorulduğunda yazıcıya gider.
     *
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function withoutStock(array $facts): array
    {
        $strip = static function (mixed $row): mixed {
            if (is_array($row)) {
                unset($row['in_stock']);
            }

            return $row;
        };
        foreach (['displayed', 'products'] as $key) {
            if (is_array($facts[$key] ?? null)) {
                $facts[$key] = array_map($strip, $facts[$key]);
            }
        }
        foreach (['product', 'winner'] as $key) {
            if (is_array($facts[$key] ?? null)) {
                $facts[$key] = $strip($facts[$key]);
            }
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $facts
     * @return array{capacity:string,precision:string}
     */
    private static function measurePair(array $facts): array
    {
        $shown = is_array($facts['displayed'] ?? null) ? $facts['displayed'] : [];
        if ($shown === [] && is_array($facts['product'] ?? null)) {
            $shown = [$facts['product']];
        }
        $first = is_array($shown[0] ?? null) ? $shown[0] : [];
        $cap = '';
        $prec = '';
        foreach (is_array($first['specs'] ?? null) ? $first['specs'] : [] as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $label = AssistantV2Catalog::fold((string) ($spec['label'] ?? ''));
            $value = trim((string) ($spec['value'] ?? ''));
            if ($value === '' || AssistantV2Filters::measureRangeLabel($value)) {
                continue;
            }
            if ($cap === '' && str_contains($label, 'kapasite') && !str_contains($label, 'yogunluk')) {
                $cap = $value;
            }
            if ($prec === '' && ($label === 'hassasiyet' || str_starts_with($label, 'hassasiyet ')) && !str_contains($label, 'kalibrasyon')) {
                $prec = $value;
            }
        }
        if ($cap === '' && is_string($first['capacity'] ?? null) && !AssistantV2Filters::measureRangeLabel(trim($first['capacity']))) {
            $cap = trim($first['capacity']);
        }
        if ($prec === '' && is_string($first['precision'] ?? null) && !AssistantV2Filters::measureRangeLabel(trim($first['precision']))) {
            $prec = trim($first['precision']);
        }

        return ['capacity' => $cap, 'precision' => $prec];
    }

    /**
     * Tek üründe cümle, Kapasite ve Hassasiyet satırının metnidir.
     *
     * @param array<string, mixed> $facts
     */
    private static function groundedMeasureLine(array $facts): string
    {
        $pair = self::measurePair($facts);
        $bits = [];
        if ($pair['capacity'] !== '') {
            $bits[] = 'kapasitesi ' . $pair['capacity'];
        }
        if ($pair['precision'] !== '') {
            $bits[] = 'hassasiyeti ' . $pair['precision'];
        }

        return $bits === [] ? '' : ucfirst(implode(', ', $bits)) . '.';
    }

    /**
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function measureTokens(array $facts): array
    {
        $pair = self::measurePair($facts);
        $out = [];
        foreach ([$pair['capacity'], $pair['precision']] as $token) {
            if ($token !== '') {
                $out[] = $token;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function citesSpecMeasures(string $text, array $facts): bool
    {
        $need = self::measureTokens($facts);
        if ($need === []) {
            return true;
        }
        $hay = self::compactMeasure($text);
        foreach ($need as $token) {
            if (!str_contains($hay, self::compactMeasure($token))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function inventedMeasureRange(string $text, array $facts): bool
    {
        if (preg_match_all('/\d+(?:[.,]\d+)?\s*(?:kg|mg|g)\s*[-–—]\s*\d+(?:[.,]\d+)?\s*(?:kg|mg|g)/iu', $text, $matches) < 1) {
            return false;
        }
        $hay = self::compactMeasure(self::specBlob($facts));
        foreach ($matches[0] as $range) {
            if (!str_contains($hay, self::compactMeasure((string) $range))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function specBlob(array $facts): string
    {
        $parts = [];
        foreach (['displayed', 'products'] as $key) {
            foreach (is_array($facts[$key] ?? null) ? $facts[$key] : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach (is_array($row['specs'] ?? null) ? $row['specs'] : [] as $spec) {
                    if (!is_array($spec)) {
                        continue;
                    }
                    $parts[] = (string) ($spec['label'] ?? '') . ' ' . (string) ($spec['value'] ?? '');
                }
                $parts[] = (string) ($row['capacity'] ?? '');
                $parts[] = (string) ($row['precision'] ?? '');
            }
        }
        if (is_array($facts['product'] ?? null)) {
            $product = $facts['product'];
            foreach (is_array($product['specs'] ?? null) ? $product['specs'] : [] as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $parts[] = (string) ($spec['label'] ?? '') . ' ' . (string) ($spec['value'] ?? '');
            }
            $parts[] = (string) ($product['capacity'] ?? '');
            $parts[] = (string) ($product['precision'] ?? '');
        }

        return implode(' ', $parts);
    }

    private static function compactMeasure(string $text): string
    {
        $fold = str_replace(',', '.', AssistantV2Catalog::fold($text));

        return preg_replace('/\s+/u', '', $fold) ?? $fold;
    }

    private static function askedWellbeing(string $question): bool
    {
        $fold = AssistantV2Catalog::fold($question);

        return preg_match('/nasil|naber|halin|iyi misin|iyisin/u', $fold) === 1;
    }

    private static function redact(string $message): string
    {
        $message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email]', $message) ?? $message;
        $message = preg_replace('/(?:\+?90|0)?\s*5\d{2}[\s.\-]?\d{3}[\s.\-]?\d{2}[\s.\-]?\d{2}/u', '[phone]', $message) ?? $message;

        return mb_substr($message, 0, 180);
    }

    /**
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function styleNotes(string $text, array $facts, string $mode, string $question): array
    {
        if (self::freeLlmMode()) {
            return [];
        }
        if ($text === '') {
            return ['message boş'];
        }
        $notes = [];
        $fold = AssistantV2Catalog::fold($text);
        if (str_contains($text, ';')) {
            $notes[] = 'noktalı virgül var';
        }
        foreach (['sen', 'sana', 'senden', 'senin', 'naber', 'iyidir'] as $word) {
            if (preg_match('/(?<![\p{L}])' . $word . '(?![\p{L}])/u', $fold) === 1) {
                $notes[] = $word . ' var';
            }
        }
        if (preg_match('/sunmaktadir|kayitlarimizda|belirtiliyor/u', $fold) === 1) {
            $notes[] = 'uzak anlatım var';
        }
        if ($mode === 'small_talk' && preg_match('/(?<![\p{L}])iyiyiz(?![\p{L}])/u', $fold) === 1) {
            $notes[] = 'kendi durumun için iyiyim de';
        }
        if ($mode === 'small_talk' && !self::askedWellbeing($question) && preg_match('/(?<![\p{L}])iyiyim(?![\p{L}])/u', $fold) === 1) {
            $notes[] = 'hal sorulmadı, iyiyim deme';
        }
        if (in_array($mode, ['service', 'business', 'contact'], true) && is_array($facts['facts'] ?? null) && $facts['facts'] !== []) {
            if (preg_match('/ulasami|erisemiy|dogrulayami|dogrulayamad|dogrulanami|dogrulama|bilgiye su anda|bilgi bulami|bilgi yok|paylasmak iste|netlestir|baglayabilir|yonlendir/u', $fold) === 1) {
                $notes[] = 'olgular varken bilgi yok denmiş';
            }
            if (!self::echoesFacts($fold, $facts['facts'])) {
                $notes[] = 'onaylı olgular cümlede yok';
            }
        }
        if ($mode === 'clarify' && substr_count($text, '?') > 1) {
            $notes[] = 'birden fazla soru var';
        }
        if ($mode === 'clarify' && str_contains($fold, 'alt kategori')) {
            $notes[] = 'katalog dili var';
        }
        if ($mode === 'clarify' && str_contains($fold, 'hangi urun')) {
            $notes[] = 'ürün zaten belli, facts.ask sorusunu sor';
        }
        if ($mode === 'out_of_domain' && (str_contains($fold, 'whatsapp') || str_contains($fold, 'ekib') || str_contains($fold, 'numaranizi'))) {
            $notes[] = 'konu dışında aktarım var';
        }
        if (in_array($mode, ['gap', 'no_price'], true) && str_contains($fold, 'whatsapp')) {
            $notes[] = 'kanal seçeneği var';
        }
        if ($mode === 'callback' && !str_contains($fold, 'whatsapp')) {
            $notes[] = 'whatsapp yok';
        }
        if (in_array($mode, ['gap', 'callback', 'no_price'], true) && (str_contains($fold, 'numaranizi birakin') || preg_match('/ilettim|iletildi|gonderildi|mesajiniz hazir|whatsapp acildi/u', $fold) === 1)) {
            $notes[] = 'numara veya iletildi iddiası var';
        }
        if (in_array($mode, ['unlisted', 'empty', 'gap'], true) && preg_match('/\d{3}[\s.\-]?\d{3}[\s.\-]?\d{2}[\s.\-]?\d{2}/u', $text) === 1) {
            $notes[] = 'telefon numarası metinde';
        }
        if (in_array($mode, ['small_talk', 'out_of_domain'], true) && preg_match('/\d/u', $text) === 1) {
            $notes[] = 'konu dışı sayı var';
        }
        if (($facts['mode'] ?? '') === 'no_price' && preg_match('/urun bulunamadi|hic urun yok|urunumuz yok/u', $fold) === 1) {
            $notes[] = 'ürün yok denmiş ama fiyat yok';
        }
        $total = $facts['total_result_count'] ?? null;
        $shown = $facts['displayed_count'] ?? null;
        if ($mode === 'count' && is_numeric($total) && !str_contains($text, (string) (int) $total)) {
            $notes[] = 'toplam sayı ' . (int) $total . ' cümlede yok';
        }
        if (AssistantV2Prompt::conversational() && $mode === 'list') {
            if (substr_count($text, '?') > 1) {
                $notes[] = 'birden fazla soru var';
            }
            if (preg_match('/^(secti(g|ğ)iniz|sectiginiz)|olculere uyan/u', $fold) === 1) {
                $notes[] = 'kalıp girişle başlama, ihtiyaca bağlı yaz';
            }
        }
        if (in_array($mode, ['list', 'near'], true) && self::countProductNameHits($text, $facts) >= 2) {
            $notes[] = 'ürün adlarını sayma';
        }
        if (in_array($mode, ['list', 'near'], true) && self::looksLikeProductDump($text)) {
            $notes[] = 'ürün adlarını sayma';
        }
        if (
            !(AssistantV2Prompt::conversational() && $mode === 'list')
            && in_array($mode, ['list', 'count'], true)
            && (int) ($facts['displayed_count'] ?? 0) > 0
            && str_contains($text, '?')
        ) {
            $notes[] = 'listeyle birlikte soru var';
        }
        if (AssistantV2Prompt::conversational() && $mode === 'clarify') {
            $askKey = (string) ($facts['ask'] ?? '');
            if ($askKey === 'precision' && !str_contains($fold, 'hassas')) {
                $notes[] = 'hassasiyeti sor';
            }
            if (str_contains($fold, 'teknik servis') || str_contains($fold, 'whatsapp')) {
                $notes[] = 'konu dışı';
            }
        }
        if (in_array($mode, ['list', 'count', 'near'], true) && (int) ($facts['capacity_distinct'] ?? 0) > 3) {
            if (preg_match_all('/\d+(?:[.,]\d+)?\s*(?:kg|g|ct)\b/iu', $text) >= 3) {
                $notes[] = 'kapasiteleri katalogun tamamı gibi sayma';
            }
        }
        if (in_array($mode, ['list', 'spec', 'lookup', 'near'], true) && self::inventedMeasureRange($text, $facts)) {
            $notes[] = 'spec satırında olmayan aralık var';
        }
        if (in_array($mode, ['list', 'near', 'lookup', 'compare', 'rank'], true) && preg_match('/tekrarlan/u', $fold) === 1) {
            $blob = AssistantV2Catalog::fold((string) json_encode($facts, JSON_UNESCAPED_UNICODE));
            if (!str_contains($blob, 'tekrarlan')) {
                $notes[] = 'tekrarlanabilirlik kayıtta yok';
            }
        }
        if (in_array($mode, ['list', 'near'], true) && empty($facts['brand_named_by_user'])) {
            foreach (AssistantV2Catalog::brands() as $brand) {
                $brandFold = AssistantV2Catalog::fold($brand);
                if ($brandFold !== '' && preg_match('/' . preg_quote($brandFold, '/') . "(?:['’])?(?:nin|nun|in|un)(?![a-z0-9])/u", $fold) === 1) {
                    $notes[] = 'kullanıcı marka söylemedi';
                    break;
                }
            }
        }
        if (in_array($mode, ['list', 'near', 'lookup', 'compare', 'rank', 'gap'], true) && self::deniesAssignedUsage($fold, $facts)) {
            $notes[] = 'usages içindeki kullanım alanlarının hepsini söyle. search_filter ürün kategorisi değildir. Kayıtlı alanı doğrulanamadı diye kapatma';
        }
        $allowed = self::numbersIn((string) json_encode($facts, JSON_UNESCAPED_UNICODE) . ' ' . $question);
        foreach (self::numbersIn($text) as $number => $unused) {
            $norm = str_replace(',', '.', (string) $number);
            $asFloat = is_numeric($norm) ? (string) (float) $norm : $norm;
            if (!isset($allowed[$number]) && !isset($allowed[$norm]) && !isset($allowed[$asFloat])) {
                $notes[] = 'olguda olmayan sayı ' . $number;
                break;
            }
        }
        unset($unused);

        return $notes;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function deniesAssignedUsage(string $fold, array $facts): bool
    {
        $groups = [];
        $push = static function (mixed $row) use (&$groups): void {
            if (!is_array($row)) {
                return;
            }
            $names = [];
            foreach (is_array($row['usages'] ?? null) ? $row['usages'] : [] as $name) {
                if (is_string($name) && trim($name) !== '') {
                    $names[] = trim($name);
                }
            }
            if (count($names) >= 2) {
                $groups[] = $names;
            }
        };
        foreach (['displayed', 'products'] as $key) {
            foreach (is_array($facts[$key] ?? null) ? $facts[$key] : [] as $row) {
                $push($row);
            }
        }
        $push($facts['product'] ?? null);
        $push($facts['winner'] ?? null);
        if ($groups === []) {
            return false;
        }
        foreach ($groups as $names) {
            $hits = 0;
            foreach ($names as $name) {
                $token = AssistantV2Catalog::fold($name);
                if ($token === '') {
                    continue;
                }
                if (str_contains($fold, $token)) {
                    $hits++;
                }
                $quoted = preg_quote($token, '/');
                if (preg_match('/' . $quoted . '.{0,90}(dogrulayami|dogrulayamad)|(dogrulayami|dogrulayamad).{0,90}' . $quoted . '/u', $fold) === 1) {
                    return true;
                }
            }
            if (preg_match('/yalnizca|sadece|urun tipi/u', $fold) === 1 && $hits < count($names)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private static function numbersIn(string $text): array
    {
        preg_match_all('/\d+(?:[.,]\d+)?/u', $text, $matches);
        $out = [];
        foreach ($matches[0] as $raw) {
            $out[$raw] = true;
            $norm = str_replace(',', '.', $raw);
            $out[$norm] = true;
            if (is_numeric($norm)) {
                $out[(string) (float) $norm] = true;
                $out[(string) (int) round((float) $norm)] = true;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $result
     * @param list<array<string, mixed>> $cards
     * @return array<string, mixed>
     */
    private static function nextState(array $state, array $result, array $cards): array
    {
        if (isset($result['filters']) && is_array($result['filters'])) {
            $state['filters'] = $result['filters'];
        }
        if (isset($result['ranking']) && is_array($result['ranking'])) {
            $state['ranking'] = $result['ranking'];
        }
        if (isset($result['asked']) && is_array($result['asked'])) {
            $state['asked'] = array_values($result['asked']);
        }
        if (isset($result['focus_ids']) && is_array($result['focus_ids']) && $result['focus_ids'] !== []) {
            $state['focus_ids'] = array_map('intval', $result['focus_ids']);
        }
        if (isset($result['offset'])) {
            $state['offset'] = (int) $result['offset'];
        }
        $state['has_more'] = $cards !== [] && (int) ($result['total'] ?? 0) > (int) $state['offset'];
        if (($result['mode'] ?? '') === 'clarify') {
            $state['offset'] = 0;
            $state['has_more'] = false;
        }
        if (array_key_exists('goal', $result)) {
            $state['goal'] = $result['goal'] === 'price' ? 'price' : '';
        }
        if (array_key_exists('held_child', $result)) {
            $state['held_child'] = is_string($result['held_child']) ? $result['held_child'] : '';
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function expertChoice(string $message, array $state): array
    {
        $action = self::teamWriteAction($message, $state);
        $actions = $action !== null ? [$action] : [];
        $debug = self::debug($message, ['intent' => 'expert', 'tool' => 'create_expert_handoff'], $state, [], 'create_expert_handoff', 0, [], [], [], $actions, 'callback');

        return self::envelope(
            $action !== null ? "WhatsApp'tan yazabilirsiniz." : 'Şu anda bu isteği tamamlayamadım. İsterseniz yeniden yazın.',
            [],
            $state,
            $debug,
            $actions
        );
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @param array<string, mixed> $state
     * @param array<string, mixed> $debug
     * @param list<array<string, mixed>> $actions
     * @param array<string, mixed>|null $more
     * @return array<string, mixed>
     */
    private static function envelope(
        string $message,
        array $cards,
        array $state,
        array $debug,
        array $actions,
        ?array $more = null,
        int $total = 0,
        string $responseMode = '',
        string $writeMode = '',
        bool $cardsAttached = false
    ): array {
        return [
            'message' => $message,
            'cards' => $cards,
            'total' => $total,
            'more' => $more,
            'actions' => $actions,
            'state' => self::publicState($state),
            'product_ids' => array_map(static fn (array $card): int => (int) ($card['id'] ?? 0), $cards),
            'mode' => $responseMode,
            'write_mode' => $writeMode,
            'cards_attached' => $cardsAttached,
            'debug' => $debug,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $state
     * @param array<string, mixed> $result
     * @param list<int> $ids
     * @param array<string, mixed> $facets
     * @param array<string, mixed> $facts
     * @param list<array<string, mixed>> $actions
     * @return array<string, mixed>
     */
    private static function debug(string $message, array $plan, array $state, array $result, string $tool, int $total, array $ids, array $facets, array $facts, array $actions, mixed $gap): array
    {
        $trace = is_array($result['trace'] ?? null) ? $result['trace'] : [];

        return [
            'user_message' => self::redact($message),
            'interpreted_intent' => (string) ($plan['intent'] ?? ''),
            'inherited_constraints' => $result['inherited'] ?? [],
            'newly_extracted' => [
                'hard' => $plan['hard'] ?? ($result['extracted_hard'] ?? []),
                'soft' => $plan['soft'] ?? ($result['extracted_soft'] ?? []),
            ],
            'final_filters' => $result['filters'] ?? ($state['filters'] ?? []),
            'hard_constraints' => $plan['hard'] ?? [],
            'soft_preferences' => $plan['soft'] ?? [],
            'selected_tool' => $tool,
            'total_matching_products' => $total,
            'displayed_product_ids' => $ids,
            'aggregate_facets' => $facets,
            'facts_sent' => $facts,
            'final_cta' => $actions,
            'escalation_reason' => is_string($gap) ? $gap : null,
            'relation' => (string) ($trace['relation'] ?? $plan['relation'] ?? ''),
            'previous_active_constraints' => $trace['previous_active_constraints'] ?? ($result['inherited'] ?? []),
            'add_constraints' => $trace['add_constraints'] ?? ($plan['add_constraints'] ?? []),
            'remove_constraints' => $trace['remove_constraints'] ?? ($plan['remove_constraints'] ?? []),
            'preserve_constraints' => $trace['preserve_constraints'] ?? ($plan['preserve_constraints'] ?? []),
            'final_active_constraints' => $trace['final_active_constraints'] ?? ($result['filters'] ?? ($state['filters'] ?? [])),
            'referenced_product_ids' => $trace['referenced_product_ids'] ?? ($state['focus_ids'] ?? []),
            'candidate_product_ids' => array_slice(is_array($result['candidate_ids'] ?? null) ? $result['candidate_ids'] : $ids, 0, 40),
            'tool_calls' => [(string) ($plan['tool'] ?? $tool)],
            'tool_result_status' => (string) ($result['tool_status'] ?? ($total > 0 ? 'ok' : 'empty')),
            'final_response_generated' => true,
            'response_failure_reason' => is_string($result['failure'] ?? null) ? $result['failure'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function state(array $state): array
    {
        $filters = AssistantV2Filters::blank();
        if (is_array($state['filters'] ?? null)) {
            $incoming = $state['filters'];
            $filters = array_merge($filters, AssistantV2Filters::criteria([
                'category' => $incoming['category'] ?? null,
                'subcategory' => $incoming['subcategory'] ?? null,
                'brands' => $incoming['brands'] ?? [],
                'precision' => $incoming['precision'] ?? [],
                'capacity' => $incoming['capacity'] ?? [],
                'series' => $incoming['series'] ?? null,
                'commercial_approval' => $incoming['commercial_approval'] ?? null,
                'calibration' => $incoming['calibration'] ?? null,
            ]));
            if (is_string($incoming['type_token'] ?? null) && $incoming['type_token'] !== '') {
                $filters['type_token'] = $incoming['type_token'];
            }
            if (is_string($incoming['capacity_slug'] ?? null) && $incoming['capacity_slug'] !== '') {
                $filters['capacity_slug'] = $incoming['capacity_slug'];
            }
            if (in_array($incoming['moisture_form'] ?? null, ['liquid', 'solid'], true)) {
                $filters['moisture_form'] = $incoming['moisture_form'];
            }
            $use = trim((string) ($incoming['moisture_use'] ?? ''));
            if ($use !== '' && mb_strlen($use) <= 120) {
                $filters['moisture_use'] = $use;
            }
            if (is_numeric($incoming['moisture_temp'] ?? null)) {
                $filters['moisture_temp'] = (int) $incoming['moisture_temp'];
            }
            $filters = AssistantV2Filters::normalizeState($filters);
        }
        $ranking = AssistantV2Filters::blankRanking();
        if (is_array($state['ranking'] ?? null) && in_array($state['ranking']['field'] ?? '', ['price', 'precision', 'capacity'], true)) {
            $ranking = [
                'field' => $state['ranking']['field'],
                'direction' => ($state['ranking']['direction'] ?? '') === 'desc' ? 'desc' : 'asc',
            ];
        }
        $asked = [];
        foreach (is_array($state['asked'] ?? null) ? $state['asked'] : [] as $item) {
            if (is_string($item) && in_array($item, ['usage', 'root', 'precision', 'capacity', 'commercial_approval', 'brand', 'subcategory', 'calibration', 'moisture_form', 'moisture_use', 'moisture_temp', 'sample_goal'], true)) {
                $asked[] = $item;
            }
        }
        $ids = [];
        $byId = AssistantV2Catalog::byId();
        foreach (is_array($state['focus_ids'] ?? null) ? $state['focus_ids'] : [] as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($byId[$id])) {
                $ids[] = $id;
            }
        }

        return [
            'filters' => $filters,
            'ranking' => $ranking,
            'offset' => max(0, (int) ($state['offset'] ?? 0)),
            'asked' => $asked,
            'focus_ids' => array_slice($ids, 0, 8),
            'preferences' => is_array($state['preferences'] ?? null) ? $state['preferences'] : [],
            'has_more' => !empty($state['has_more']),
            'criterion' => is_string($state['criterion'] ?? null) ? $state['criterion'] : null,
            'goal' => ($state['goal'] ?? '') === 'price' ? 'price' : '',
            'held_child' => self::heldChild($state['held_child'] ?? ''),
            'excluded_kinds' => self::kindList($state['excluded_kinds'] ?? []),
            'near_options' => self::nearOptions($state['near_options'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function publicState(array $state): array
    {
        return [
            'filters' => $state['filters'],
            'ranking' => $state['ranking'],
            'offset' => (int) ($state['offset'] ?? 0),
            'asked' => $state['asked'],
            'focus_ids' => $state['focus_ids'],
            'preferences' => is_array($state['preferences'] ?? null) ? $state['preferences'] : [],
            'has_more' => (bool) ($state['has_more'] ?? false),
            'goal' => ($state['goal'] ?? '') === 'price' ? 'price' : '',
            'held_child' => self::heldChild($state['held_child'] ?? ''),
            'excluded_kinds' => self::kindList($state['excluded_kinds'] ?? []),
            'near_options' => self::nearOptions($state['near_options'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $facets
     * @return list<string>
     */
    private static function facetOptions(array $facets, string $ask): array
    {
        if ($ask === 'usage') {
            $rows = [];
            foreach (is_array($facets['available_usages'] ?? null) ? $facets['available_usages'] : [] as $row) {
                if (is_array($row) && (string) ($row['name'] ?? '') !== '') {
                    $rows[] = $row;
                }
            }
            $rank = ['kuyumcu' => 0, 'laboratuvar' => 1, 'endustriyel' => 2];
            usort($rows, static function (array $a, array $b) use ($rank): int {
                $ar = $rank[(string) ($a['slug'] ?? '')] ?? 9;
                $br = $rank[(string) ($b['slug'] ?? '')] ?? 9;

                return $ar <=> $br ?: ((int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0));
            });
            $out = [];
            foreach ($rows as $row) {
                $out[] = (string) $row['name'];
                if (count($out) >= 6) {
                    break;
                }
            }

            return $out;
        }
        if ($ask === 'subcategory') {
            $out = [];
            foreach (is_array($facets['available_subcategories'] ?? null) ? $facets['available_subcategories'] : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                if ($name !== '') {
                    $out[] = $name;
                }
            }

            return $out;
        }
        if ($ask === 'precision') {
            $rows = [];
            foreach ($facets['available_precisions'] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $label = trim((string) ($row['label'] ?? ''));
                if ($label === '' || !is_numeric($row['grams'] ?? null) || AssistantV2Filters::measureRangeLabel($label)) {
                    continue;
                }
                $rows[] = $row;
            }
            usort($rows, static function (array $a, array $b): int {
                $unit = strcmp((string) ($a['unit'] ?? ''), (string) ($b['unit'] ?? ''));

                return $unit !== 0 ? $unit : ((float) $a['grams'] <=> (float) $b['grams']);
            });
            $out = [];
            foreach ($rows as $row) {
                $out[] = (string) $row['label'];
                if (count($out) >= 24) {
                    break;
                }
            }

            return $out;
        }
        if ($ask === 'brand') {
            return array_slice(array_keys(is_array($facets['available_brands'] ?? null) ? $facets['available_brands'] : []), 0, 6);
        }
        if ($ask === 'capacity') {
            $rows = [];
            foreach ($facets['available_capacities'] ?? [] as $row) {
                if (is_array($row) && trim((string) ($row['label'] ?? '')) !== '') {
                    $rows[] = $row;
                }
            }
            usort($rows, static function (array $a, array $b): int {
                $order = ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0));

                return $order !== 0 ? $order : strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            });
            $out = [];
            foreach ($rows as $row) {
                $label = trim((string) ($row['label'] ?? ''));
                if ($label === '' || AssistantV2Filters::measureRangeLabel($label) || in_array($label, $out, true)) {
                    continue;
                }
                $out[] = $label;
                if (count($out) >= 24) {
                    break;
                }
            }

            return $out;
        }
        if ($ask === 'commercial_approval') {
            $out = [];
            $commercial = is_array($facets['available_commercial_approval'] ?? null) ? $facets['available_commercial_approval'] : [];
            if (($commercial['yes'] ?? 0) > 0) {
                $out[] = 'Ticari onaylı';
            }
            if (($commercial['no'] ?? 0) > 0) {
                $out[] = 'Ticari onaysız';
            }

            return $out;
        }
        if ($ask === 'calibration') {
            $keys = array_keys(is_array($facets['available_calibrations'] ?? null) ? $facets['available_calibrations'] : []);

            return array_slice(array_map('strval', $keys), 0, 4);
        }

        return [];
    }

    private static function heldChild(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 80) {
            return '';
        }

        return $value;
    }

    /**
     * @param list<array{role:string,text:string}> $history
     */
    private static function userCorpus(string $message, array $history): string
    {
        $parts = [$message];
        foreach ($history as $turn) {
            if (($turn['role'] ?? '') === 'user' && is_string($turn['text'] ?? null)) {
                $parts[] = $turn['text'];
            }
        }

        return trim(implode(' ', $parts));
    }

    private static function isFreshRequest(string $message): bool
    {
        $fold = AssistantV2Catalog::fold($message);
        if (self::statedPrecision($message) !== null && preg_match('/hassas|okuma|gram|mg/u', $fold) === 1) {
            return false;
        }
        if (self::messageStatesCapacity($message) && str_contains($fold, 'kapasite')) {
            return false;
        }

        return preg_match('/ariyorum|ariyor|ariyorm|ariorm|istiyorum|lazim|goster|bakar/u', $fold) === 1;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function pendingAsk(array $state): ?string
    {
        $asked = is_array($state['asked'] ?? null) ? $state['asked'] : [];
        for ($i = count($asked) - 1; $i >= 0; $i--) {
            $item = $asked[$i];
            if (is_string($item) && in_array($item, ['root', 'subcategory', 'precision', 'capacity'], true)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Kısa çip cevabı bekleyen soruya aittir. "Laboratuvar terazisi arıyorum" yeni ana kategoridir.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $previous
     */
    private static function answersPending(string $message, array $state, array $previous): bool
    {
        if (self::isFreshRequest($message)) {
            return false;
        }
        $pending = self::pendingAsk($state);
        if ($pending === 'precision') {
            return self::statedPrecision($message) !== null;
        }
        if ($pending === 'capacity') {
            return self::messageStatesCapacity($message);
        }
        if ($pending === 'subcategory') {
            $cat = is_string($previous['category'] ?? null) ? $previous['category'] : '';

            return $cat !== '' && AssistantV2Catalog::matchingChild($cat, $message) !== null;
        }
        if ($pending === 'root') {
            return AssistantV2Catalog::statedUsage($message) !== null;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function answersRoot(string $message, array $state): bool
    {
        return self::pendingAsk($state) === 'root'
            && !self::isFreshRequest($message)
            && AssistantV2Catalog::statedUsage($message) !== null;
    }

    /**
     * Yeni ana kategori, eski kuyumcu filtresini taşımaz.
     *
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $state
     */
    private static function switchRoot(string $message, array $previous, array $state): ?string
    {
        $root = AssistantV2Catalog::statedUsage($message);
        if ($root === null || self::answersPending($message, $state, $previous)) {
            return null;
        }
        $current = is_string($previous['category'] ?? null) ? $previous['category'] : '';
        if (!self::isFreshRequest($message)) {
            return ($current !== '' && $root !== $current) ? $root : null;
        }

        return $root;
    }

    private static function seriesSpoken(string $series, string $text): bool
    {
        $token = AssistantV2Catalog::fold($series);
        $fold = AssistantV2Catalog::fold($text);

        return $token !== '' && preg_match('/(^|[^a-z0-9])' . preg_quote($token, '/') . '([^a-z0-9]|$)/', $fold) === 1;
    }

    private static function clarifyLine(string $ask): string
    {
        return match ($ask) {
            'root' => 'Hangi kategoride arıyorsunuz?',
            'subcategory' => 'Hangi tür terazi arıyorsunuz?',
            'precision' => 'Hangi hassasiyete ihtiyacınız var?',
            'capacity' => 'Kaç kapasiteye kadar ürün tartmayı düşünüyorsunuz?',
            'moisture_form' => 'Numune sıvı mı, katı mı?',
            'moisture_use' => 'Hangi numuneyi ölçeceksiniz?',
            'moisture_temp' => 'Maksimum kurutma sıcaklığı kaç derece olsun?',
            'sample_goal' => 'Nem tayini mi yapmak istiyorsunuz, yoksa tartım mı?',
            default => '',
        };
    }

    /**
     * Katalog çipleri varken Luna yazıcı turunu atlamak için.
     *
     * @param array<string, mixed> $facts
     * @param list<array<string, mixed>> $actions
     */
    private static function chipClarifyHasCatalogOptions(array $facts, array $actions): bool
    {
        foreach (is_array($facts['options'] ?? null) ? $facts['options'] : [] as $option) {
            if (is_string($option) && trim($option) !== '') {
                return true;
            }
        }
        foreach ($actions as $action) {
            if (
                is_array($action)
                && ($action['type'] ?? '') === 'prompt'
                && trim((string) ($action['label'] ?? '')) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function chipClarifyFastReply(array $facts, string $ask): string
    {
        $category = trim((string) ($facts['category'] ?? ''));
        $fold = AssistantV2Catalog::fold($category);
        $rootFold = trim((string) ($facts['usage_root'] ?? ''));
        if ($rootFold === '') {
            $rootFold = $fold;
        }
        $material = trim((string) ($facts['sample_material'] ?? ''));
        $warm = static fn (string $body): string => $material !== ''
            ? ($material . ' için ' . $body)
            : $body;

        $moisture = match ($ask) {
            'moisture_temp' => $warm('nem tayininde prosedürünüzdeki maksimum kurutma sıcaklığı kaç °C olmalı?'),
            'moisture_form' => $warm('numune sıvı mı katı mı? Kurutma modu buna göre değişir.'),
            'moisture_use' => $warm('hangi numune veya ürün grubu için nem ölçümü yapacaksınız?'),
            default => '',
        };
        if ($moisture !== '') {
            return $moisture;
        }
        if ($ask === 'sample_goal') {
            return $material !== ''
                ? ($material . ' için nem tayini mi yapmak istiyorsunuz, yoksa tartım mı?')
                : 'Numune için nem tayini mi yoksa tartım mı düşünüyorsunuz?';
        }

        $scope = self::chipClarifyScopeLine($rootFold, $fold, $ask);
        $typeToken = trim((string) ($facts['type_token'] ?? ''));
        if ($ask === 'subcategory' && $typeToken === 'mikro' && str_contains($rootFold, 'laboratuvar')) {
            $question = 'Mikro terazi mi yarı mikro terazi mi arıyorsunuz?';
        } else {
            $question = self::chipClarifyAskLine($ask, $rootFold, $fold);
        }
        if ($scope !== '' && $question !== '') {
            return $scope . ' ' . $question;
        }

        return $question !== '' ? $question : self::clarifyLine($ask);
    }

    private static function chipClarifyScopeLine(string $rootFold, string $labelFold, string $ask): string
    {
        $scopeFold = $rootFold !== '' ? $rootFold : $labelFold;
        if ($scopeFold === '' && $labelFold === '') {
            return '';
        }
        if ($ask === 'subcategory') {
            if (str_contains($scopeFold, 'laboratuvar') || str_contains($labelFold, 'mikro') || str_contains($labelFold, 'analitik')) {
                return 'Laboratuvarda aradığınız tartım türünü netleştirelim.';
            }
            if (str_contains($scopeFold, 'kuyumcu')) {
                return 'Kuyumcu kullanımında doğru cihaz grubunu seçelim.';
            }
            if (str_contains($scopeFold, 'endustriyel')) {
                return 'Endüstriyel tartım ihtiyacınızı doğru gruba indirelim.';
            }
            if (str_contains($scopeFold, 'perakende') || str_contains($scopeFold, 'market') || str_contains($scopeFold, 'manav')) {
                return 'Tezgah ve satış kullanımına uygun terazi grubunu seçelim.';
            }
        }
        if (in_array($ask, ['capacity', 'precision', 'near'], true)) {
            if (str_contains($labelFold, 'hassas')) {
                if (str_contains($scopeFold, 'kuyumcu')) {
                    return 'Kuyumcu kullanımı için uygun hassas teraziyi seçelim.';
                }
                if (str_contains($scopeFold, 'laboratuvar')) {
                    return 'Laboratuvar kullanımı için uygun hassas teraziyi seçelim.';
                }

                return 'Hassas terazi seçiminde ihtiyacınıza uygun ölçü aralığını bulalım.';
            }
            if (str_contains($labelFold, 'analitik')) {
                return 'Analitik terazi seçiminde doğru ölçü aralığını bulalım.';
            }
            if (str_contains($labelFold, 'yari analitik')) {
                return 'Yarı analitik terazi seçiminde ihtiyacınıza uygun ölçüye bakalım.';
            }
            if (str_contains($labelFold, 'yari mikro')) {
                return 'Yarı mikro terazi seçiminde numune kütlesine uygun kapasiteyi bulalım.';
            }
            if (str_contains($labelFold, 'nem')) {
                return 'Nem tayin cihazı seçiminde prosedürünüze uygun modeli bulalım.';
            }
        }

        return '';
    }

    private static function chipClarifyAskLine(string $ask, string $rootFold, string $labelFold): string
    {
        return match ($ask) {
            'root' => 'Hangi kullanım alanında terazi arıyorsunuz?',
            'subcategory' => 'Hangi cihaz türü size uygun?',
            'precision' => 'Hangi okuma hassasiyetine ihtiyacınız var?',
            'capacity' => self::chipClarifyCapacityAsk($rootFold, $labelFold),
            'near' => 'Hangisi size daha yakın?',
            default => self::clarifyLine($ask),
        };
    }

    private static function chipClarifyCapacityAsk(string $rootFold, string $labelFold): string
    {
        if (str_contains($rootFold, 'kuyumcu')) {
            return 'Tartacağınız en ağır yük yaklaşık kaç gram?';
        }
        if (
            str_contains($rootFold, 'laboratuvar')
            || str_contains($labelFold, 'analitik')
            || str_contains($labelFold, 'hassas')
        ) {
            return 'Tartacağınız en ağır numune yaklaşık kaç gram?';
        }

        return 'Tartacağınız en ağır yük yaklaşık kaç gram?';
    }

    /**
     * @param array<string, mixed> $filters
     */
    /**
     * Gösterilen ürünlerde değeri değişen teknik özellik satırları. Yazıcı bunlardan doğal bir sonraki adım çıkarır.
     *
     * @param list<array<string, mixed>> $products
     * @return list<array{label:string,values:list<string>}>
     */
    private static function differences(array $products): array
    {
        if (count($products) < 2) {
            return [];
        }
        $skip = ['kategori', 'urun tipi', 'urun turu', 'kapasite', 'hassasiyet', 'tekrarlanabilirlik', 'lineerlik', 'mensei', 'cihaz boyutlari', 'net cihaz agirligi', 'paket boyutlari', 'brut agirlik'];
        $rows = [];
        foreach ($products as $index => $product) {
            foreach (AssistantV2Filters::answerSpecs($product, 24) as $spec) {
                $key = AssistantV2Catalog::fold($spec['label']);
                if ($key === '' || in_array($key, $skip, true)) {
                    continue;
                }
                $rows[$key]['label'] = $spec['label'];
                $rows[$key]['values'][$index] = mb_substr($spec['value'], 0, 40);
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $values = array_values(array_unique(array_values($row['values'])));
            if (count($values) < 2 || count($row['values']) < 2) {
                continue;
            }
            $out[] = ['label' => $row['label'], 'values' => array_slice($values, 0, 4)];
            if (count($out) >= 5) {
                break;
            }
        }

        return $out;
    }

    /**
     * Müşterinin bu görüşmede söylediği ihtiyaç. Yazıcı cevabı bununla ilişkilendirir.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function needFacts(array $filters): array
    {
        $out = [];
        $capacity = is_array($filters['capacity'] ?? null) ? $filters['capacity'] : [];
        if (is_numeric($capacity['value_g'] ?? null)) {
            $out['en_agir_yuk'] = AssistantV2Filters::formatGrams((float) $capacity['value_g']);
        }
        $precision = is_array($filters['precision'] ?? null) ? $filters['precision'] : [];
        if (is_numeric($precision['value'] ?? null)) {
            $out['istenen_hassasiyet'] = rtrim(rtrim(number_format((float) $precision['value'], 6, '.', ''), '0'), '.') . (string) ($precision['unit'] ?? 'g');
        }
        foreach (is_array($filters['brands'] ?? null) ? $filters['brands'] : [] as $brand) {
            if (is_string($brand) && $brand !== '') {
                $out['marka'][] = $brand;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    /**
     * @param array<string, mixed> $facts
     */
    private static function listReply(array $products, array $facts = []): string
    {
        return self::listIntroFallback($products, $facts);
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed> $facts
     */
    private static function listIntroFallback(array $products, array $facts): string
    {
        if (self::userSelectedMeasures($facts)) {
            return 'Seçtiğiniz ölçülere uyan ' . AssistantV2Catalog::listNoun($products) . ' aşağıda.';
        }
        $label = trim((string) ($facts['search_filter'] ?? ''));
        if ($label !== '') {
            return $label . ' için uygun seçenekleri aşağıda listeledim.';
        }

        return 'İhtiyacınıza uygun seçenekleri aşağıda listeledim.';
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function consultantListFallback(array $facts): string
    {
        $need = is_array($facts['user_need'] ?? null) ? $facts['user_need'] : [];
        $prec = trim((string) ($need['istenen_hassasiyet'] ?? ''));
        $count = (int) ($facts['displayed_count'] ?? 0);
        if ($prec !== '' && $count > 0) {
            if ($count === 1) {
                return $prec . ' okuma hassasiyetine uygun bir model kartlarda; kapasite veya marka tercihiniz varsa yazabilirsiniz.';
            }

            return $prec . ' okuma hassasiyetine uygun modelleri kartlarda listeledim; tartacağınız yük veya marka tercihinize göre daraltabiliriz.';
        }
        if (self::userSelectedMeasures($facts)) {
            return 'Seçtiğiniz ölçülere uyan modelleri kartlarda gösterdim.';
        }

        return self::listIntroFallback([], $facts);
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function polishListReply(string $text, array $facts): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return self::consultantListFallback($facts);
        }
        $hits = self::countProductNameHits($trimmed, $facts);
        if ($hits >= 2 || self::looksLikeProductDump($trimmed)) {
            return self::consultantListFallback($facts);
        }
        if ($hits === 1 && mb_strlen($trimmed) > 200) {
            return self::consultantListFallback($facts);
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function countProductNameHits(string $text, array $facts): int
    {
        $fold = AssistantV2Catalog::fold($text);
        if ($fold === '') {
            return 0;
        }
        $hits = 0;
        foreach (is_array($facts['displayed'] ?? null) ? $facts['displayed'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $needle = AssistantV2Catalog::fold($name);
            if ($needle !== '' && str_contains($fold, $needle)) {
                $hits++;
            }
        }

        return $hits;
    }

    private static function looksLikeProductDump(string $text): bool
    {
        if (preg_match('/^\s*[-•*]\s+/um', $text) === 1) {
            return true;
        }
        if (substr_count($text, ',') >= 3 && preg_match('/\b[A-Z]{2,}\b/u', $text) === 1) {
            return true;
        }
        $lines = preg_split('/\R/u', $text) ?: [];
        $nonEmpty = 0;
        foreach ($lines as $line) {
            if (trim((string) $line) !== '') {
                $nonEmpty++;
            }
        }

        return $nonEmpty >= 3;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function userSelectedMeasures(array $facts): bool
    {
        $filters = is_array($facts['active_filters'] ?? null) ? $facts['active_filters'] : [];
        if ($filters !== [] && AssistantV2Filters::hasMeasure($filters)) {
            return true;
        }
        if (is_numeric($facts['requested_capacity_g'] ?? null)) {
            return true;
        }
        if (is_numeric($filters['moisture_temp'] ?? null)) {
            return true;
        }
        $need = is_array($facts['user_need'] ?? null) ? $facts['user_need'] : [];

        return ($need['en_agir_yuk'] ?? '') !== '' || ($need['istenen_hassasiyet'] ?? '') !== '';
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $filters
     */
    private static function attachConsultantHints(array &$facts, array $filters, string $ask): void
    {
        if (!AssistantV2Prompt::conversational()) {
            return;
        }
        $category = is_string($filters['category'] ?? null) ? $filters['category'] : '';
        $sub = is_string($filters['subcategory'] ?? null) ? $filters['subcategory'] : '';
        $hints = AssistantV2Knowledge::consultantHints($category, $sub, $ask);
        if ($hints !== []) {
            $facts['consultant_hints'] = $hints;
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private static function usageClarify(array $state, array $plan, array $filters, int $matchingTotal): array
    {
        $asked = is_array($state['asked'] ?? null) ? $state['asked'] : [];
        if (!in_array('usage', $asked, true)) {
            $asked[] = 'usage';
        }
        $facts = array_merge([
            'mode' => 'clarify',
            'ask' => 'usage',
            'options' => [],
            'matching_models' => $matchingTotal,
        ], self::clarifyCategoryFacts($filters));
        self::attachConsultantHints($facts, $filters, 'usage');

        return [
            'mode' => 'clarify',
            'tool' => 'get_available_facets',
            'facts' => $facts,
            'reply' => null,
            'cards' => [],
            'total' => $matchingTotal,
            'facets' => [],
            'answered' => true,
            'gap' => null,
            'cta' => 'none',
            'focus_ids' => [],
            'offset' => 0,
            'asked' => $asked,
            'filters' => $filters,
            'plan' => $plan,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    /**
     * Bekleyen alt kategori / kapasite / hassasiyet çipine yanıt: planlayıcı turunu atla.
     *
     * @param array<string, mixed> $state
     */
    private static function chipAnswerShortcut(string $message, array &$state): ?array
    {
        if (AssistantV2Catalog::rejectsOffer($message) || self::isFreshRequest($message)) {
            return null;
        }
        $previous = is_array($state['filters'] ?? null) ? $state['filters'] : AssistantV2Filters::blank();
        if (!self::answersPending($message, $state, $previous)) {
            return null;
        }
        $pending = self::pendingAsk($state);
        if ($pending === null) {
            return null;
        }
        if ($pending === 'subcategory') {
            $cat = is_string($previous['category'] ?? null) ? $previous['category'] : '';
            $slug = $cat !== '' ? AssistantV2Catalog::matchingChild($cat, $message) : null;
            if (!is_string($slug) || $slug === '') {
                return null;
            }
            $state['filters']['subcategory'] = $slug;
            $state['filters']['type_token'] = null;

            return self::discoverOrSearch($state, self::emptyPlan(), $message);
        }
        if ($pending === 'precision' && self::statedPrecision($message) !== null) {
            $state['filters'] = AssistantV2Filters::applyUtteranceMeasures($state['filters'], $message);
            $precision = self::statedPrecision($message);
            if ($precision !== null) {
                $state['filters']['precision'] = $precision;
            }

            return self::discoverOrSearch($state, self::emptyPlan(), $message);
        }
        if ($pending === 'capacity' && self::messageStatesCapacity($message)) {
            $state['filters'] = AssistantV2Filters::applyUtteranceMeasures($state['filters'], $message);
            $state['filters'] = AssistantV2Filters::applyChipCapacity($state['filters'], $message);

            return self::discoverOrSearch($state, self::emptyPlan(), $message);
        }

        return null;
    }

    private static function routeCatalogPhrase(string $message, array &$state): ?array
    {
        if (self::precisionChip($message) || (self::capacityChip($message) && !AssistantV2Catalog::rejectsOffer($message))) {
            return null;
        }
        if (AssistantV2Prompt::conversational()) {
            $pending = self::nearOptions($state['near_options'] ?? []);
            if (count($pending) === 1 && self::affirms($message)) {
                $state['near_options'] = [];

                return self::openCatalogNode($state, $pending[0]['slug']);
            }
            if (
                !AssistantV2Catalog::rejectsOffer($message)
                && (self::statedPrecision($message) !== null || self::messageStatesCapacity($message))
            ) {
                return null;
            }
        }
        $excluded = [];
        foreach (self::kindList($state['excluded_kinds'] ?? []) as $kind) {
            $excluded[$kind] = $kind;
        }
        foreach (AssistantV2Catalog::rejectedKinds($message) as $kind) {
            $excluded[$kind] = $kind;
        }
        $state['excluded_kinds'] = array_values($excluded);
        if (AssistantV2Catalog::rejectsOffer($message)) {
            $state['focus_ids'] = [];
            $state['filters'] = AssistantV2Filters::blank();
            $state['asked'] = [];
            $state['held_child'] = '';
            $state['near_options'] = [];
            $state['offset'] = 0;
            $foldReject = AssistantV2Catalog::fold($message);
            $root = AssistantV2Catalog::statedUsage($message);
            if ($root !== null && preg_match('/olsun|istiyorum|lutfen|istiyor|sec|gecelim/u', $foldReject) === 1) {
                $state['filters']['category'] = $root;
                $state['asked'] = ['subcategory'];

                return null;
            }
        }

        if (
            AssistantV2Prompt::conversational()
            && AssistantV2Catalog::statedUsage($message) !== null
            && AssistantV2Catalog::matchedChildName($message) === null
            && self::statedPrecision($message) === null
            && !self::messageStatesCapacity($message)
            && !AssistantV2Catalog::labScaleDiscovery($message)
        ) {
            return null;
        }

        foreach (self::nearOptions($state['near_options'] ?? []) as $option) {
            if (AssistantV2Catalog::fold($option['name']) === AssistantV2Catalog::fold($message)) {
                $state['near_options'] = [];

                return self::openCatalogNode($state, $option['slug']);
            }
        }

        if (
            !AssistantV2Catalog::rejectsOffer($message)
            && AssistantV2Catalog::labScaleDiscovery($message)
        ) {
            $boot = AssistantV2Catalog::labScaleFilters($message);
            $state['filters'] = AssistantV2Filters::blank();
            $state['filters']['category'] = (string) ($boot['category'] ?? 'laboratuvar');
            if (is_string($boot['subcategory'] ?? null) && ($boot['subcategory'] ?? '') !== '') {
                $state['filters']['subcategory'] = $boot['subcategory'];
            }
            if (is_string($boot['type_token'] ?? null) && ($boot['type_token'] ?? '') !== '') {
                $state['filters']['type_token'] = $boot['type_token'];
            }
            $state['near_options'] = [];
            $state['held_child'] = '';
            $state['asked'] = [];

            return self::discoverOrSearch($state, self::emptyPlan(), $message);
        }

        if (!AssistantV2Catalog::rejectsOffer($message)) {
            $child = AssistantV2Catalog::matchedChildName($message);
            $roots = $child !== null ? AssistantV2Catalog::rootsForChild($child) : [];
            if ($child !== null && count($roots) === 1) {
                $parent = (string) array_key_first($roots);
                $slug = AssistantV2Catalog::matchingChild($parent, $child);
                if (is_string($slug) && $slug !== '') {
                    $state['near_options'] = [];

                    return self::openCatalogNode($state, $slug);
                }
            }
            if ($child !== null && count($roots) > 1) {
                foreach (AssistantV2Catalog::taxonomy() as $item) {
                    if (AssistantV2Catalog::fold((string) $item['name']) !== AssistantV2Catalog::fold($child)) {
                        continue;
                    }
                    if (AssistantV2Catalog::kindOfNode($item) === 'terazi') {
                        return null;
                    }
                }
            }
        }

        $candidates = AssistantV2Catalog::nearCatalog($message, self::kindList($state['excluded_kinds'] ?? []));
        if ($candidates === []) {
            // Ret cümlesi sabit metinle kapanmaz. Bağlam temizlendi, planlayıcı müşterinin ne istediğini sorar.
            return null;
        }

        if (AssistantV2Prompt::conversational() && !AssistantV2Catalog::rejectsOffer($message) && !(count($candidates) === 1 && !empty($candidates[0]['direct']))) {
            // Uzun bir ihtiyaç cümlesi veya odaktaki ürün hakkındaki soru, yakın katalog adı eşleşmesiyle kapanmaz.
            $foldNow = AssistantV2Catalog::fold($message);
            $words = count(preg_split('/\s+/u', trim($foldNow)) ?: []);
            $inContext = ($state['focus_ids'] ?? []) !== [] || AssistantV2Filters::hasConstraint($state['filters']);
            if (self::nearOptions($state['near_options'] ?? []) !== [] || self::jewelerGoldState($state)) {
                $inContext = true;
            }
            $aboutFocus = $inContext && preg_match('/(^| )(mi|mu|mı|mü)( |$)/u', $foldNow) === 1;
            if (!$aboutFocus && self::jewelerGoldCapabilityAsk($message) && self::jewelerGoldState($state)) {
                $aboutFocus = true;
            }
            if ($words >= 4 || $aboutFocus) {
                return null;
            }
        }

        if (count($candidates) === 1 && !empty($candidates[0]['direct'])) {
            $state['near_options'] = [];

            return self::openCatalogNode($state, (string) $candidates[0]['slug']);
        }

        $names = [];
        $options = [];
        foreach ($candidates as $candidate) {
            $names[] = (string) $candidate['name'];
            $options[] = ['slug' => (string) $candidate['slug'], 'name' => (string) $candidate['name']];
        }
        $state['near_options'] = $options;
        $teraziNear = $candidates !== [];
        if ($teraziNear) {
            foreach ($candidates as $candidate) {
                if (($candidate['kind'] ?? '') !== 'terazi') {
                    $teraziNear = false;
                    break;
                }
            }
        }
        $nearAsk = $teraziNear ? 'subcategory' : 'near';

        return [
            'mode' => 'clarify',
            'tool' => 'get_catalog_taxonomy',
            'facts' => array_merge([
                'mode' => 'clarify',
                'ask' => $nearAsk,
                'options' => $names,
            ], self::clarifyCategoryFacts($state['filters'])),
            'reply' => count($names) === 1 ? ($names[0] . ' mi demek istediniz?') : 'Şunlardan biri mi?',
            'cards' => [],
            'total' => 0,
            'facets' => [],
            'answered' => true,
            'gap' => null,
            'cta' => 'none',
            'focus_ids' => [],
            'offset' => 0,
            'asked' => $state['asked'],
            'filters' => $state['filters'],
            'plan' => self::emptyPlan(),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private static function openCatalogNode(array &$state, string $slug): ?array
    {
        $node = AssistantV2Catalog::node($slug);
        if ($node === null) {
            return null;
        }
        $root = AssistantV2Catalog::usageSlug($node);
        $state['filters'] = AssistantV2Filters::blank();
        $state['filters']['category'] = $root;
        if ($node['slug'] !== $root) {
            $state['filters']['subcategory'] = $node['slug'];
        }
        $state['asked'] = ['subcategory'];
        $state['focus_ids'] = [];
        $state['near_options'] = [];
        $state['offset'] = 0;

        return self::discoverOrSearch($state, self::emptyPlan(), (string) $node['name']);
    }

    private static function tradeInOnlyQuestion(string $message): bool
    {
        $fold = AssistantV2Catalog::fold($message);
        if (preg_match('/takas|satmak|satiyorum|degerlendir|teklif|alir misiniz|aliyor musunuz/u', $fold) !== 1) {
            return false;
        }

        return preg_match('/yenilenmis|2\s*\.?\s*el|ikinci\s+el|garanti/u', $fold) !== 1;
    }

    private static function userExpressesUncertainty(string $message): bool
    {
        $fold = AssistantV2Catalog::fold($message);
        if ($fold === '') {
            return false;
        }

        if (self::userExpressesSelectionConfusion($message)) {
            return true;
        }

        return preg_match(
            '/bilmiyorum|bilemiyorum|emin degilim|kararsiz|kararsizim|fikrim yok|havada kald|yardim ed|yardimci ol|stajyer|stajyerim|yeni baslad|acik degil|net degil/u',
            $fold
        ) === 1;
    }

    private static function userExpressesSelectionConfusion(string $message): bool
    {
        $fold = AssistantV2Catalog::fold($message);

        return preg_match(
            '/hangisini al|hangisini sec|neye gore sec|nasil sec|karar ver|tercih edem|hangisi lazim|hangisini almal|secim yapam|secemedim/u',
            $fold
        ) === 1;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function uncertaintyResult(array $state, string $message): array
    {
        if (self::hasSpecContext($state)) {
            $result = self::userExpressesSelectionConfusion($message)
                ? self::decisionGuidanceResult($state, $message)
                : self::specResult($state, $message);
        } else {
            $result = [
                'mode' => 'guidance',
                'tool' => 'none',
                'facts' => [
                    'mode' => 'guidance',
                    'user_uncertain' => true,
                ],
                'reply' => '',
                'cards' => [],
                'total' => 0,
                'facets' => [],
                'answered' => true,
                'gap' => null,
                'cta' => 'human_handoff',
                'focus_ids' => $state['focus_ids'] ?? [],
                'offset' => (int) ($state['offset'] ?? 0),
                'asked' => $state['asked'] ?? [],
                'filters' => $state['filters'],
                'plan' => self::emptyPlan(),
            ];
        }
        $result['cta'] = 'human_handoff';
        $result['cards'] = [];
        $result['total'] = 0;

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $state
     */
    private static function shouldAttachProductCards(string $message, array $result, string $writeMode, array $state): bool
    {
        if (self::userExpressesUncertainty($message)) {
            return false;
        }
        if (self::wantsProductCards($message) || self::userConfirmedProductListing($message)) {
            return true;
        }
        if (in_array($writeMode, ['spec', 'gap', 'callback', 'guidance', 'service', 'business', 'contact', 'small_talk', 'out_of_domain', 'empty', 'unlisted', 'no_price', 'count', 'compare', 'rank', 'lookup', 'clarify', 'uncertainty', 'decisionGuidance'], true)) {
            return false;
        }
        $facts = is_array($result['facts'] ?? null) ? $result['facts'] : [];
        if (($facts['user_uncertain'] ?? false) === true || ($facts['decision_criteria'] ?? null) !== null) {
            return false;
        }
        $factsAsk = (string) ($facts['ask'] ?? '');
        if (in_array($factsAsk, ['root', 'subcategory', 'precision', 'capacity', 'near', 'moisture_form', 'moisture_use', 'moisture_temp', 'sample_goal', 'usage'], true)) {
            return false;
        }
        if (($result['mode'] ?? '') === 'clarify') {
            return false;
        }
        $filters = is_array($result['filters'] ?? null) ? $result['filters'] : $state['filters'];
        if (AssistantV2Filters::pendingPrecisionAfterCapacity($filters, is_array($state['asked'] ?? null) ? $state['asked'] : [])) {
            return false;
        }
        $mode = (string) ($result['mode'] ?? '');
        if (!in_array($mode, ['list', 'near'], true)) {
            return false;
        }
        if (
            AssistantV2Filters::isMoistureGroup($state['filters'])
            && !self::moistureBrowseReady($state, $message)
        ) {
            return false;
        }
        if (self::isSpecQuestion($message) || self::touchesSpecLabel($message, $state) || self::isEducationalProductQuestion($message, $state)) {
            return false;
        }
        if (($planIntent = (string) (($result['plan'] ?? [])['intent'] ?? '')) !== '' && in_array($planIntent, ['service', 'business', 'contact', 'small_talk'], true)) {
            return false;
        }
        if (
            AssistantV2Prompt::conversational()
            && $mode === 'list'
            && ($planIntent ?? '') === 'discover'
        ) {
            $filters = is_array($result['filters'] ?? null) ? $result['filters'] : $state['filters'];
            if (
                !AssistantV2Filters::hasMeasure($filters)
                && !AssistantV2Filters::brandOnly($filters)
                && self::needFacts($filters) === []
                && (int) ($result['total'] ?? 0) > 5
            ) {
                return false;
            }
        }
        return true;
    }

    private static function assistantDefersProductCards(string $text): bool
    {
        $fold = AssistantV2Catalog::fold($text);
        if ($fold === '') {
            return false;
        }
        if (preg_match('/netlestireyim|tereddut|tereddutunuz|sivi numune|numunenizle uyumu|dogrulanmiyor/u', $fold) === 1) {
            return true;
        }
        if (str_contains($text, '?') && preg_match('/uygunlugu|netlestir|tereddut|sivi|kati numune|numune tip|numuneniz/u', $fold) === 1) {
            return true;
        }
        if (str_contains($text, '?') && preg_match('/karsiliyor mu|yeterli mi|uygun mu|uyar mi|olur mu|dogru mu|bu mu|istediginiz bu mu/u', $fold) === 1) {
            return true;
        }

        return self::assistantAsksSingleProductConfirmation($text);
    }

    private static function assistantSuggestsSingleProductNotGrid(string $text): bool
    {
        $fold = AssistantV2Catalog::fold($text);
        if ($fold === '') {
            return false;
        }
        if (preg_match('/asagidaki|listelenen modeller|secenekler|modeller:|diger modeller|size uygun/u', $fold) === 1) {
            return false;
        }

        return preg_match('/oneriyorum|uygun gorunuyor|uygun aday|belirlemem gerekiyor|axis modeli|axis nem tayin|aradiginiz urunu/u', $fold) === 1;
    }

    private static function userSeekingProductWithoutListRequest(string $message): bool
    {
        if (self::wantsProductCards($message) || self::userConfirmedProductListing($message)) {
            return false;
        }
        $fold = AssistantV2Catalog::fold($message);

        return preg_match('/urun bul|urun ariy|urun arad|bana urun|ben de ariyorum|ben arıyorum/u', $fold) === 1;
    }

    private static function assistantIntroducesProductListing(string $text): bool
    {
        $fold = AssistantV2Catalog::fold($text);

        return preg_match('/asagidaki|listelenen|listede|secenekler|modeller|kartlarda|urunleri|size uygun/u', $fold) === 1;
    }

    private static function assistantAsksSingleProductConfirmation(string $text): bool
    {
        $fold = AssistantV2Catalog::fold($text);
        if ($fold === '' || !str_contains($text, '?')) {
            return false;
        }

        return preg_match('/aradiginiz urun bu mu|bu urun mu|urun bu mu|bu model mi|dogru urun mu|istediginiz urun bu mu/u', $fold) === 1;
    }

    private static function userConfirmedProductListing(string $message): bool
    {
        if (self::wantsProductCards($message)) {
            return true;
        }
        $fold = AssistantV2Catalog::fold(trim($message));
        if ($fold === '') {
            return false;
        }

        return preg_match('/^(evet|tamam|olur|dogru|aynen|onayliyorum|onayla)\b/u', $fold) === 1
            || preg_match('/urunleri goster|hepsini goster|listeyi goster|modelleri goster|kartlari goster/u', $fold) === 1;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $result
     * @return list<int>
     */
    private static function focusProductIds(array $state, array $result): array
    {
        $source = is_array($result['focus_ids'] ?? null) && $result['focus_ids'] !== []
            ? $result['focus_ids']
            : ($state['focus_ids'] ?? []);
        $ids = [];
        if (is_array($source)) {
            foreach ($source as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function isEducationalProductQuestion(string $message, array $state): bool
    {
        if (!self::hasSpecContext($state)) {
            return false;
        }
        $fold = AssistantV2Catalog::fold($message);
        if (preg_match('/\d{2,3}\s*°?\s*(c|derece)?\s*mi\s+\d{2,3}/iu', $message) === 1) {
            return true;
        }
        if (preg_match('/(^| )\d+\s*mi\s+\d+/u', $fold) === 1) {
            return true;
        }

        return preg_match('/hangisi daha|farki ne|fark ne|nasil sec|hangisini sec|yontem|standard|prosedur|uygun mu|uyar mi|yeterli mi|olur mu/u', $fold) === 1;
    }

    /**
     * @param list<array{role:string,text:string}> $history
     * @param array<string, mixed> $state
     */
    private static function jewelerGoldThread(string $message, array $history, array $state): bool
    {
        if (self::jewelerGoldState($state)) {
            return true;
        }
        $fold = AssistantV2Catalog::fold(self::userCorpus($message, $history));
        if (!str_contains($fold, 'kuyumcu')) {
            return false;
        }

        return str_contains($fold, 'altin');
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function jewelerGoldState(array $state): bool
    {
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        $subFold = AssistantV2Catalog::fold((string) ($filters['subcategory'] ?? ''));
        if ($subFold !== '' && str_contains($subFold, 'altin') && str_contains($subFold, 'analiz')) {
            return true;
        }
        foreach (self::nearOptions($state['near_options'] ?? []) as $option) {
            $name = AssistantV2Catalog::fold((string) ($option['name'] ?? ''));
            if (str_contains($name, 'altin') && str_contains($name, 'analiz')) {
                return true;
            }
        }
        $ids = is_array($state['focus_ids'] ?? null) ? $state['focus_ids'] : [];
        if ($ids === []) {
            return false;
        }
        $byId = AssistantV2Catalog::byId();
        foreach (array_slice($ids, 0, 4) as $id) {
            $product = $byId[(int) $id] ?? null;
            if (!is_array($product)) {
                continue;
            }
            $blob = AssistantV2Catalog::fold((string) ($product['name'] ?? '') . ' ' . (string) ($product['category'] ?? ''));
            if (str_contains($blob, 'altin') && str_contains($blob, 'analiz')) {
                return true;
            }
        }

        return false;
    }

    private static function jewelerGoldCapabilityAsk(string $message): bool
    {
        $fold = AssistantV2Catalog::fold($message);
        if (preg_match('/(^| )(mi|mu|mı|mü)( |$)/u', $fold) !== 1 && !self::isSpecQuestion($message)) {
            return false;
        }

        return preg_match('/ayar|karat|olcu|olcum|yogunluk|sicaklik|numune|metal/u', $fold) === 1;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function primeJewelerGoldSpec(array $state): array
    {
        foreach (AssistantV2Catalog::taxonomy() as $node) {
            if ($node['parent'] !== 'kuyumcu' || $node['products'] < 1) {
                continue;
            }
            $label = AssistantV2Catalog::fold($node['name'] . ' ' . str_replace('-', ' ', (string) $node['slug']));
            if (!str_contains($label, 'altin') || !str_contains($label, 'analiz')) {
                continue;
            }
            $state['filters'] = AssistantV2Filters::blank();
            $state['filters']['category'] = 'kuyumcu';
            $state['filters']['subcategory'] = (string) $node['slug'];
            $state['near_options'] = [];
            $found = AssistantV2Filters::search($state['filters'], 0, 6);
            $ids = [];
            foreach (is_array($found['page'] ?? null) ? $found['page'] : [] as $product) {
                if (is_array($product) && isset($product['id'])) {
                    $ids[] = (int) $product['id'];
                }
            }
            if ($ids !== []) {
                $state['focus_ids'] = $ids;
            }

            return $state;
        }

        return $state;
    }

    /**
     * Müşteri 2. el veya yenilenmiş ürün arıyor. Kendi cihazını takasa vermek hizmet konusudur.
     */
    private static function asksSecondHand(string $message): bool
    {
        $fold = AssistantV2Catalog::fold($message);
        if (preg_match('/(^|[^a-z0-9])(2\s*\.?\s*el|ikinci\s+el|yenilenmis|kullanilmis)([^a-z0-9]|$)/u', $fold) !== 1) {
            return false;
        }

        return preg_match('/takas|satmak|satiyorum|alir misiniz|aliyor musunuz|degerlendir|teklif/u', $fold) !== 1;
    }

    /**
     * Ürün durumu "yenilenmiş / 2. el" olan kayıtlar. Fiyat ve stok uydurulmaz.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function secondHandResult(array $state, string $message): array
    {
        $usage = AssistantV2Catalog::statedUsage($message);
        $rows = [];
        foreach (AssistantV2Catalog::products() as $product) {
            $condition = AssistantV2Catalog::fold((string) ($product['condition'] ?? ''));
            if (!str_contains($condition, 'yenilen') && preg_match('/2\s*\.?\s*el|ikinci el/u', $condition) !== 1) {
                continue;
            }
            if ($usage !== null && !AssistantV2Catalog::inGroup($product, $usage, false)) {
                continue;
            }
            $rows[] = $product;
        }
        usort($rows, static fn (array $a, array $b): int => ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0)));
        $page = array_slice($rows, 0, AssistantV2Filters::PAGE);
        $cards = [];
        $brief = [];
        foreach ($page as $product) {
            $cards[] = AssistantV2Filters::card($product);
            $brief[] = AssistantV2Filters::publicFacts($product, true);
        }
        $empty = $rows === [];
        $filters = AssistantV2Filters::blank();
        if ($usage !== null) {
            $filters['category'] = $usage;
        }

        return [
            'mode' => $empty ? 'empty' : 'list',
            'tool' => 'search_products',
            'facts' => [
                'mode' => $empty ? 'empty' : 'list',
                'second_hand' => true,
                'total_result_count' => count($rows),
                'displayed_count' => count($cards),
                'displayed' => $brief,
                'differences' => self::differences($page),
            ],
            'tool_status' => $empty ? 'empty' : 'ok',
            'cards' => $cards,
            'total' => count($cards),
            'facets' => [],
            'answered' => true,
            'gap' => null,
            'cta' => 'none',
            'focus_ids' => array_map(static fn (array $card): int => (int) $card['id'], $cards),
            'offset' => count($cards),
            'filters' => $filters,
            'plan' => self::emptyPlan(),
            'reply' => null,
        ];
    }

    /**
     * Kısa onay: "mi demek istediniz" sorusuna evet.
     */
    private static function affirms(string $message): bool
    {
        $fold = trim(preg_replace('/[^a-z0-9 ]+/u', ' ', AssistantV2Catalog::fold($message)) ?? '');
        $fold = trim(preg_replace('/\s+/u', ' ', $fold) ?? '');

        return preg_match('/^(evet|olur|tamam|aynen|dogru|evet lutfen|evet olur|evet onu|onu goster|onu gorelim|evet o|lutfen)$/u', $fold) === 1;
    }

    /**
     * @return list<string>
     */
    private static function kindList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $kind) {
            if (!is_string($kind) || !in_array($kind, ['kit', 'aksesuar', 'terazi', 'cihaz', 'nem', 'tork', 'stand'], true)) {
                continue;
            }
            $out[$kind] = $kind;
        }

        return array_values($out);
    }

    /**
     * @return list<array{slug:string,name:string}>
     */
    private static function nearOptions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $option) {
            if (!is_array($option)) {
                continue;
            }
            $slug = trim((string) ($option['slug'] ?? ''));
            $name = trim((string) ($option['name'] ?? ''));
            if ($slug === '' || $name === '' || mb_strlen($name) > 80) {
                continue;
            }
            $out[] = ['slug' => $slug, 'name' => $name];
            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private static function requiredLoadReply(array $filters): ?string
    {
        $capacity = is_array($filters['capacity'] ?? null) ? $filters['capacity'] : [];
        if (($capacity['operator'] ?? null) !== 'min' || !is_numeric($capacity['value_g'] ?? null)) {
            return null;
        }
        $label = AssistantV2Filters::formatGrams((float) $capacity['value_g']);

        return 'Aşağıda ' . $label . ' kapasiteye göre ürünleri sizin için listeledik. Sormak istediğiniz başka bir şey var mı?';
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function resetSearch(array $state): array
    {
        $state['filters'] = AssistantV2Filters::blank();
        $state['ranking'] = AssistantV2Filters::blankRanking();
        $state['offset'] = 0;
        $state['asked'] = [];
        $state['focus_ids'] = [];
        $state['preferences'] = [];
        $state['has_more'] = false;
        $state['criterion'] = null;
        $state['goal'] = '';
        $state['held_child'] = '';
        $state['excluded_kinds'] = [];
        $state['near_options'] = [];

        return $state;
    }

    private static function bareDiscovery(string $message): bool
    {
        return !self::namedBeyondProduct($message) && self::genericProductSearch($message, false);
    }

    private static function pureRootSearch(string $message): ?string
    {
        if (self::comparisonAsk($message)) {
            return null;
        }
        $root = AssistantV2Catalog::statedUsage($message);
        if ($root === null || AssistantV2Catalog::matchingChild($root, $message) !== null) {
            return null;
        }
        if (self::catalogSpecific($message)) {
            return null;
        }
        $fold = AssistantV2Catalog::fold($message);
        $node = AssistantV2Catalog::node($root);
        if ($node !== null) {
            foreach ([AssistantV2Catalog::fold($node['name']), AssistantV2Catalog::fold(str_replace('-', ' ', $node['slug']))] as $label) {
                if ($label !== '') {
                    $fold = str_replace($label, ' ', $fold);
                }
            }
        }

        return self::genericProductSearch($fold, true) ? $root : null;
    }

    private static function precisionChip(string $message): bool
    {
        if (self::statedPrecision($message) === null || self::comparisonAsk($message)) {
            return false;
        }
        if (AssistantV2Catalog::statedUsage($message) !== null || AssistantV2Catalog::matchedChildName($message) !== null) {
            return false;
        }
        if (AssistantV2Catalog::brandsIn($message) !== []) {
            return false;
        }
        if (AssistantV2Catalog::productSlugsIn($message) !== [] || AssistantV2Catalog::namedProductSlugs($message) !== []) {
            return false;
        }
        $stripped = preg_replace('/0[.,]\d+\s*(?:ct|karat|g|gr|gram)?\b/iu', ' ', $message) ?? $message;

        return self::genericProductSearch($stripped, true);
    }

    private static function capacityChip(string $message): bool
    {
        if (!self::messageStatesCapacity($message) || self::comparisonAsk($message)) {
            return false;
        }
        if (AssistantV2Catalog::statedUsage($message) !== null || AssistantV2Catalog::matchedChildName($message) !== null) {
            return false;
        }
        if (AssistantV2Catalog::brandsIn($message) !== []) {
            return false;
        }
        $stripped = preg_replace('/\d+(?:[.,]\d+)?\s*(?:kg|mg|g)\b/iu', ' ', $message) ?? $message;

        return self::genericProductSearch($stripped, true);
    }

    private static function namedBeyondProduct(string $message): bool
    {
        if (self::comparisonAsk($message) || AssistantV2Catalog::statedUsage($message) !== null) {
            return true;
        }
        if (AssistantV2Catalog::matchedChildName($message) !== null) {
            return true;
        }

        return self::catalogSpecific($message) || self::statedPrecision($message) !== null || self::messageStatesCapacity($message);
    }

    private static function catalogSpecific(string $message): bool
    {
        if (AssistantV2Catalog::brandsIn($message) !== []) {
            return true;
        }
        if (AssistantV2Catalog::productSlugsIn($message) !== [] || AssistantV2Catalog::namedProductSlugs($message) !== []) {
            return true;
        }
        if (AssistantV2Catalog::unresolvedModel($message) !== null) {
            return true;
        }
        foreach (AssistantV2Catalog::seriesList() as $series) {
            if (self::seriesSpoken($series, $message)) {
                return true;
            }
        }

        return false;
    }

    private static function comparisonAsk(string $message): bool
    {
        $fold = AssistantV2Catalog::fold($message);

        return preg_match('/hangisi|hangisini|karsilastir|kiyas|\bdaha iyi\b|\bfark(?:i|lari|lar)?\b|\bvs\b|versus/u', $fold) === 1;
    }

    private static function genericProductSearch(string $text, bool $allowEmpty): bool
    {
        $tokens = preg_split('/[^a-z0-9]+/u', AssistantV2Catalog::fold($text)) ?: [];
        $sawProduct = false;
        foreach ($tokens as $token) {
            if (!is_string($token) || $token === '' || self::searchNoise($token)) {
                continue;
            }
            if (self::productWord($token)) {
                $sawProduct = true;
                continue;
            }

            return false;
        }

        return $sawProduct || $allowEmpty;
    }

    private static function productWord(string $token): bool
    {
        return in_array($token, [
            'terazi', 'terazisi', 'teraziler', 'terazileri', 'teraziyi', 'terazim', 'terazinin', 'terazide',
            'tarti', 'tartisi', 'tartim', 'tartimi',
            'baskul', 'baskulu', 'baskuller', 'baskulleri',
            'urun', 'urunu', 'urunler', 'urunleri',
            'cihaz', 'cihazi', 'cihazlar',
        ], true);
    }

    private static function searchNoise(string $token): bool
    {
        if (preg_match('/^(?:ariyor|ariyorum|istiyor|istiyorum|lazim|goster|bakar)/u', $token) === 1) {
            return true;
        }

        return in_array($token, [
            'ariyorum', 'ariyoruz', 'ariyor', 'ariyorsunuz', 'ariyordum', 'ariyorm', 'ariorm', 'aramak', 'ararim',
            'istiyorum', 'istiyoruz', 'istiyor', 'isterim', 'isteriz',
            'lazim', 'lazimdir', 'gerek', 'gerekli', 'gerekiyor',
            'bakiyorum', 'bakiyoruz', 'bakiyor', 'bakar', 'bakarim',
            'goster', 'gosterin', 'gosterir',
            'bul', 'bulunur', 'bulur',
            'oner', 'onerin', 'onerir', 'tavsiye',
            'genel', 'bir', 'bi', 'bana', 'bize', 'benim', 'icin', 'biraz',
            'uygun', 'iyi', 'guzel', 'lutfen', 'rica', 'ederim',
            'merhaba', 'selam', 'selamlar',
            'var', 'mi', 'mu', 'musunuz', 'misiniz', 'musun', 'misin', 'olur',
            'herhangi', 'basit', 'normal', 'standart', 'olan',
            've', 'veya', 'ile', 'da', 'de', 'ta', 'te', 'tane', 'adet',
            'acaba', 'yardim', 'yardimci', 'ben', 'siz', 'size',
            'boyle', 'soyle',
        ], true);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @param array<string, string> $roots
     * @return array<string, mixed>
     */
    private static function rootClarify(array $state, array $plan, array $roots, string $held, bool $varied = false): array
    {
        $asked = is_array($state['asked'] ?? null) ? $state['asked'] : [];
        if (!in_array('root', $asked, true)) {
            $asked[] = 'root';
        }

        $facts = [
            'mode' => 'clarify',
            'ask' => 'root',
            'options' => array_values($roots),
            'suggested' => $varied && count($roots) < count(AssistantV2Catalog::usageRoots()),
        ];
        self::attachConsultantHints($facts, $state['filters'], 'root');

        return [
            'mode' => 'clarify',
            'tool' => 'get_available_facets',
            'facts' => $facts,
            'reply' => $varied ? null : self::clarifyLine('root'),
            'cards' => [],
            'total' => 0,
            'facets' => [],
            'answered' => true,
            'gap' => null,
            'cta' => 'none',
            'focus_ids' => [],
            'offset' => 0,
            'asked' => $asked,
            'filters' => $state['filters'],
            'held_child' => $held,
            'plan' => $plan,
        ];
    }

    /**
     * @return array{value:float,unit:string}|null
     */
    private static function statedPrecision(string $message): ?array
    {
        $text = str_replace(["'", '’', '`', '´'], ' ', $message);
        if (preg_match('/(?<!\d)(0[.,]\d+)\s*(ct|karat)\b/iu', $text, $match) === 1) {
            return ['value' => (float) str_replace(',', '.', $match[1]), 'unit' => 'ct'];
        }
        if (AssistantV2Prompt::conversational() && preg_match('/(?<![\d.,])(0[.,]\d+)\s*mg\b/iu', $text, $match) === 1) {
            return ['value' => (float) str_replace(',', '.', $match[1]) / 1000, 'unit' => 'g'];
        }
        if (preg_match('/(?<!\d)(0[.,]\d+)\s*(g|gr|gram)?\b/iu', $text, $match) === 1) {
            return ['value' => (float) str_replace(',', '.', $match[1]), 'unit' => 'g'];
        }

        return null;
    }

    private static function messageStatesCapacity(string $message): bool
    {
        $text = str_replace(["'", '’', '`', '´'], ' ', $message);
        if (AssistantV2Prompt::conversational()) {
            // Ondalıklı küçük mg değerleri (0.01 mg gibi) hassasiyettir, kapasite değil.
            $text = preg_replace('/(?<![\d.,])0[.,]\d+\s*mg\b/iu', ' ', $text) ?? $text;
        }
        if (preg_match('/\d+(?:[.,]\d+)?\s*(kg|mg)\b/iu', $text) === 1) {
            return true;
        }
        if (preg_match('/\d+(?:[.,]\d+)?\s*(?:kg|g|mg)\s*(?:a|e|dan|den)?\s*(?:kadar|fazla|az)/iu', $text) === 1) {
            return true;
        }

        return preg_match('/(?<![0-9.,])\d{2,5}(?:[.,]\d+)?\s*g\b/iu', $text) === 1;
    }

    /**
     * @param list<mixed> $lines
     */
    private static function echoesFacts(string $fold, array $lines): bool
    {
        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }
            $source = AssistantV2Catalog::fold($line);
            $parts = preg_split('/[^a-z0-9]+/u', $source) ?: [];
            foreach ($parts as $part) {
                if (strlen($part) >= 7 && str_contains($fold, $part)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Aktif kategori, alt kategori ve tek markayı katalog liste path'ine çevirir.
     * Hassasiyet, kapasite ve benzeri kısıtlar liste adresine oturmaz; en yakın /urunler sayfası kalır.
     *
     * @param array<string, mixed> $filters
     */
    private static function listingUrl(array $filters): string
    {
        $chain = self::listingCategoryChain($filters);
        $brands = self::listingBrandSlugs($filters);
        $path = '/urunler';
        $query = [];
        if (count($brands) === 1) {
            $path .= '/' . $brands[0];
        } elseif (count($brands) > 1) {
            $query['marka'] = implode(',', $brands);
        }
        foreach ($chain as $segment) {
            $path .= '/' . $segment;
        }
        $measure = self::measureSlugs($filters);
        if ($measure !== []) {
            $query['f'] = implode(',', $measure);
        }
        $href = seg_url($path);
        if ($query !== []) {
            $href .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $href;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    private static function listingCategoryChain(array $filters): array
    {
        $sub = is_string($filters['subcategory'] ?? null) ? trim($filters['subcategory']) : '';
        $cat = is_string($filters['category'] ?? null) ? trim($filters['category']) : '';
        $leaf = $sub !== '' ? $sub : $cat;
        $chain = self::listingChainFrom($leaf);
        if ($chain === [] && $cat !== '' && $cat !== $leaf) {
            $chain = self::listingChainFrom($cat);
        }
        if (count($chain) > 3) {
            $chain = array_slice($chain, 0, 3);
        }

        return $chain;
    }

    /**
     * @return list<string>
     */
    private static function listingChainFrom(string $leaf): array
    {
        if (preg_match('/^[a-z0-9-]+$/', $leaf) !== 1) {
            return [];
        }
        $chain = [];
        $slug = $leaf;
        $seen = [];
        $guard = 0;
        while ($slug !== '' && $guard < 6 && !isset($seen[$slug])) {
            $seen[$slug] = true;
            $node = AssistantV2Catalog::node($slug);
            if ($node === null) {
                break;
            }
            array_unshift($chain, $node['slug']);
            $parent = trim((string) ($node['parent'] ?? ''));
            if ($parent === '' || $parent === $slug || preg_match('/^[a-z0-9-]+$/', $parent) !== 1) {
                break;
            }
            $slug = $parent;
            $guard++;
        }

        return $chain;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    private static function measureSlugs(array $filters): array
    {
        $precision = is_array($filters['precision'] ?? null) ? $filters['precision'] : [];
        if (!is_numeric($precision['value'] ?? null) || !is_string($precision['unit'] ?? null)) {
            return [];
        }
        $found = AssistantV2Filters::search($filters, 0, 1);
        $byId = AssistantV2Catalog::byId();
        $out = [];
        $precision = is_array($filters['precision'] ?? null) ? $filters['precision'] : [];
        $capacity = is_array($filters['capacity'] ?? null) ? $filters['capacity'] : [];
        $minLoad = (($capacity['operator'] ?? null) === 'min' && is_numeric($capacity['value_g'] ?? null))
            ? (float) $capacity['value_g']
            : null;
        $wantPrecision = is_numeric($precision['value'] ?? null);
        foreach ($found['all_ids'] as $id) {
            $product = $byId[(int) $id] ?? null;
            if (!is_array($product)) {
                continue;
            }
            foreach (is_array($product['specs'] ?? null) ? $product['specs'] : [] as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $kind = null;
                $label = AssistantV2Catalog::fold((string) ($spec['label'] ?? ''));
                $value = trim((string) ($spec['value'] ?? ''));
                if ($value === '' || AssistantV2Filters::measureRangeLabel($value)) {
                    continue;
                }
                if ($wantPrecision && ($label === 'hassasiyet' || str_starts_with($label, 'hassasiyet ')) && !str_contains($label, 'kalibrasyon')) {
                    $kind = 'hassasiyet';
                }
                if ($minLoad !== null && str_contains($label, 'kapasite') && !str_contains($label, 'yogunluk')) {
                    $grams = null;
                    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(kg|mg|g)\b/iu', $value, $match) === 1) {
                        $number = (float) str_replace(',', '.', $match[1]);
                        $unit = strtolower($match[2]);
                        $grams = $unit === 'kg' ? $number * 1000 : ($unit === 'mg' ? $number / 1000 : $number);
                    }
                    if ($grams === null || $grams + 0.05 < $minLoad) {
                        continue;
                    }
                    $kind = 'kapasite';
                }
                if ($kind === null) {
                    continue;
                }
                $slug = AssistantV2Filters::specFilterSlug($kind, $value);
                if (preg_match('/^[a-z0-9.-]+$/', $slug) === 1) {
                    $out[$slug] = $slug;
                }
            }
        }

        return array_values($out);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    private static function listingBrandSlugs(array $filters): array
    {
        $wanted = [];
        foreach (is_array($filters['brands'] ?? null) ? $filters['brands'] : [] as $brand) {
            if (!is_string($brand)) {
                continue;
            }
            $fold = AssistantV2Catalog::fold($brand);
            if ($fold !== '') {
                $wanted[$fold] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }
        $slugs = [];
        foreach (AssistantV2Catalog::products() as $product) {
            $fold = AssistantV2Catalog::fold((string) ($product['brand'] ?? ''));
            if (!isset($wanted[$fold])) {
                continue;
            }
            $slug = strtolower(trim((string) ($product['brand_slug'] ?? '')));
            if (preg_match('/^[a-z0-9-]+$/', $slug) === 1) {
                $slugs[$slug] = $slug;
            }
        }

        return array_values($slugs);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private static function groupLabel(array $filters): string
    {
        $slug = is_string($filters['subcategory'] ?? null) && $filters['subcategory'] !== ''
            ? $filters['subcategory']
            : (string) ($filters['category'] ?? '');
        $node = $slug !== '' ? AssistantV2Catalog::node($slug) : null;

        return $node['name'] ?? '';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{category:string,usage_root:string}
     */
    private static function clarifyCategoryFacts(array $filters): array
    {
        $type = is_string($filters['type_token'] ?? null) ? trim($filters['type_token']) : '';

        return [
            'category' => self::groupLabel($filters),
            'usage_root' => self::filterUsageRootFold($filters),
            'type_token' => $type !== '' ? $type : null,
        ];
    }

    /**
     * Kök kullanım alanı (kuyumcu, laboratuvar vb.); alt kategori adından çıkarılmaz.
     *
     * @param array<string, mixed> $filters
     */
    private static function filterUsageRootFold(array $filters): string
    {
        $rootSlug = is_string($filters['category'] ?? null) ? trim($filters['category']) : '';
        if ($rootSlug !== '') {
            $node = AssistantV2Catalog::node($rootSlug);
            if ($node !== null) {
                return AssistantV2Catalog::fold($node['name'] . ' ' . $node['slug']);
            }
        }
        $sub = is_string($filters['subcategory'] ?? null) ? trim($filters['subcategory']) : '';
        if ($sub === '') {
            return '';
        }
        $node = AssistantV2Catalog::node($sub);
        if ($node === null) {
            return '';
        }
        $usageSlug = AssistantV2Catalog::usageSlug($node);
        $root = AssistantV2Catalog::node($usageSlug);

        return $root !== null
            ? AssistantV2Catalog::fold($root['name'] . ' ' . $root['slug'])
            : '';
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $state
     * @return list<array<string, mixed>>
     */
    private static function productsFromPlan(array $plan, array $state): array
    {
        $byId = AssistantV2Catalog::byId();
        $bySlug = AssistantV2Catalog::bySlug();
        $out = [];
        $args = is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [];
        foreach (is_array($args['slugs'] ?? null) ? $args['slugs'] : [] as $slug) {
            if (is_string($slug) && isset($bySlug[$slug])) {
                $out[(int) $bySlug[$slug]['id']] = $bySlug[$slug];
            }
        }
        foreach (is_array($args['ids'] ?? null) ? $args['ids'] : [] as $id) {
            $id = (int) $id;
            if (isset($byId[$id])) {
                $out[$id] = $byId[$id];
            }
        }
        if (count($out) < 2) {
            foreach ($state['focus_ids'] as $id) {
                if (isset($byId[$id])) {
                    $out[$id] = $byId[$id];
                }
            }
        }

        return array_slice(array_values($out), 0, 4);
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private static function oneProduct(array $plan, array $state): ?array
    {
        $args = is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [];
        $slug = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $bySlug = AssistantV2Catalog::bySlug();
        if ($slug !== '' && isset($bySlug[$slug])) {
            return $bySlug[$slug];
        }
        $id = (int) ($args['id'] ?? 0);
        $byId = AssistantV2Catalog::byId();
        if ($id > 0 && isset($byId[$id])) {
            return $byId[$id];
        }
        $products = self::productsFromPlan($plan, $state);

        return $products[0] ?? null;
    }

    private static function isQualityGap(string $name): bool
    {
        $fold = AssistantV2Catalog::fold($name);
        if ($fold === '') {
            return false;
        }
        foreach (['omur', 'dayanik', 'kalite', 'performans', 'ustun', 'daha iyi', 'lifespan', 'durability', 'lifetime', 'quality'] as $part) {
            if (str_contains($fold, $part)) {
                return true;
            }
        }

        return false;
    }

    private static function serviceTopic(string $topic, string $message): string
    {
        $allowed = AssistantV2Knowledge::serviceTopics();
        $msg = AssistantV2Catalog::fold($message);
        if (preg_match('/takas|takas yap|alir misiniz|aliyor musunuz|cihaz.*sat|degerlendir.*cihaz|terazimi sat/u', $msg) === 1) {
            return 'trade';
        }
        if (str_contains($msg, 'garanti') && !preg_match('/takas/u', $msg)) {
            return 'warranty';
        }
        $fold = AssistantV2Catalog::fold($topic);
        foreach ($allowed as $item) {
            if ($fold === $item || ($fold !== '' && str_contains($fold, $item))) {
                return $item;
            }
        }
        if (str_contains($fold, 'garanti')) {
            return 'warranty';
        }
        $ask = AssistantV2Catalog::fold($message);
        if (
            preg_match('/takas|satmak|satiyorum|degerlendir|teklif|alir misiniz|aliyor musunuz/u', $ask) === 1
            && preg_match('/yenilenmis|2\s*\.?\s*el|ikinci el|garanti/u', $ask) !== 1
        ) {
            return 'trade';
        }
        if (str_contains($fold, 'kargo') || str_contains($fold, 'gonder') || str_contains($fold, 'teslim')) {
            return 'shipping';
        }
        if (str_contains($fold, 'marka')) {
            return 'brand';
        }

        return self::pickTopic($message, $allowed, 'overview');
    }

    private static function businessTopic(string $topic, string $message): string
    {
        $allowed = AssistantV2Knowledge::businessTopics();
        $msg = AssistantV2Catalog::fold($message);
        if (preg_match('/teslimat|kargo|siparis|ne kadar surer|kac gunde/u', $msg) === 1 && !preg_match('/servis|tamir|kalibrasyon/u', $msg)) {
            return 'shipping';
        }
        $fold = AssistantV2Catalog::fold($topic);
        foreach ($allowed as $item) {
            if ($fold === $item || ($fold !== '' && str_contains($fold, $item))) {
                return $item;
            }
        }

        return self::pickTopic($message, $allowed, 'about');
    }

    private static function contactTopic(string $topic, string $message): string
    {
        $asked = AssistantV2Catalog::fold($message);
        if (preg_match('/beni ara|ara(yin|sin)|ulasin|numarami birak|bana ulas/u', $asked) === 1) {
            return 'person';
        }
        $fold = AssistantV2Catalog::fold($topic);
        if (str_contains($fold, 'whatsapp')) {
            return 'whatsapp';
        }
        if (str_contains($fold, 'phone') || str_contains($fold, 'telefon')) {
            return 'phone';
        }
        if (str_contains($fold, 'servis') || str_contains($fold, 'kargo')) {
            return 'service';
        }
        if ($fold === 'person') {
            return self::pickTopic($message, ['whatsapp', 'phone', 'service', 'person'], 'person');
        }

        return self::pickTopic($message, ['whatsapp', 'phone', 'service', 'person'], 'person');
    }

    /**
     * @param list<string> $allowed
     */
    private static function pickTopic(string $message, array $allowed, string $fallback): string
    {
        $user = json_encode([
            'task' => 'Yalnızca JSON döndür: {"topic":"..."}. topic, allowed listesinden biri olsun.',
            'message' => $message,
            'allowed' => $allowed,
        ], JSON_UNESCAPED_UNICODE);
        $json = is_string($user) ? AssistantV2Llm::complete(AssistantV2Prompt::voice(), $user, 12, 'v2_topic') : null;
        $topic = is_array($json) ? (string) ($json['topic'] ?? '') : '';

        return in_array($topic, $allowed, true) ? $topic : $fallback;
    }

    private static function knownAttribute(string $name): bool
    {
        $fold = AssistantV2Catalog::fold($name);
        foreach (['kategori', 'marka', 'hassasiyet', 'kapasite', 'ticari', 'kalibrasyon', 'fiyat', 'stok', 'brand', 'precision', 'capacity', 'price', 'stock'] as $known) {
            if (str_contains($fold, $known)) {
                return true;
            }
        }
        if (AssistantV2Catalog::resolveGroup($name) !== null || AssistantV2Catalog::resolveBrand($name) !== null) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private static function continueMoisture(string $message, array &$state): bool
    {
        if (!AssistantV2Filters::isMoistureGroup($state['filters'])) {
            return false;
        }
        $asked = is_array($state['asked'] ?? null) ? $state['asked'] : [];
        $filters = $state['filters'];
        $form = $filters['moisture_form'] ?? null;
        $use = $filters['moisture_use'] ?? null;
        $temp = $filters['moisture_temp'] ?? null;
        if (in_array('moisture_form', $asked, true) && !in_array($form, ['liquid', 'solid'], true)) {
            $spoken = self::sampleForm($message);
            if ($spoken === null) {
                return false;
            }
            $state['filters']['moisture_form'] = $spoken;

            return true;
        }
        if (in_array('moisture_use', $asked, true) && !is_string($use)) {
            $audiences = AssistantV2Filters::moistureAudiences($state['filters']);
            if ($audiences === []) {
                $state['filters']['moisture_use'] = '';
                $state['filters'] = AssistantV2Filters::normalizeState($state['filters']);
                $state['filters']['moisture_use'] = '';

                return true;
            }
            $picked = self::matchAudience($message, $audiences);
            if ($picked === null) {
                return false;
            }
            $state['filters']['moisture_use'] = $picked;

            return true;
        }
        if (in_array('moisture_temp', $asked, true) && !is_numeric($temp)) {
            $celsius = self::statedCelsius($message);
            if ($celsius === null) {
                return false;
            }
            $state['filters']['moisture_temp'] = $celsius;

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @return array<string, mixed>|null
     */
    private static function moistureClarify(array &$state, array $plan, string $message): ?array
    {
        $filters = $state['filters'];
        $asked = is_array($state['asked'] ?? null) ? $state['asked'] : [];
        $corpus = is_string($state['dialog_corpus'] ?? null) && trim($state['dialog_corpus']) !== ''
            ? (string) $state['dialog_corpus']
            : $message;
        if (!in_array($filters['moisture_form'] ?? null, ['liquid', 'solid'], true)) {
            $spoken = self::sampleForm($message) ?? self::inferSampleForm($corpus);
            if ($spoken !== null && !in_array('moisture_form', $asked, true)) {
                $filters['moisture_form'] = $spoken;
                $state['filters'] = $filters;
            } else {
                $asked[] = 'moisture_form';
                $state['filters'] = $filters;

                return self::moistureAsk($state, $plan, $asked, 'moisture_form', ['Sıvı', 'Katı'], $corpus);
            }
        }
        if (!is_string($filters['moisture_use'] ?? null)) {
            $audiences = AssistantV2Filters::moistureAudiences($filters);
            if ($audiences === []) {
                if (!in_array('moisture_use', $asked, true)) {
                    $asked[] = 'moisture_use';
                    $state['asked'] = $asked;
                }
            } else {
                $picked = self::matchAudience($message, $audiences) ?? self::matchAudience($corpus, $audiences);
                if ($picked === null && self::sampleMaterialHint($corpus) !== '' && !in_array('moisture_use', $asked, true)) {
                    $asked[] = 'moisture_use';
                    $state['asked'] = $asked;
                } elseif ($picked === null) {
                    if (!in_array('moisture_use', $asked, true)) {
                        $asked[] = 'moisture_use';
                    }
                    $state['filters'] = $filters;

                    return self::moistureAsk($state, $plan, $asked, 'moisture_use', $audiences, $corpus);
                } else {
                    $filters['moisture_use'] = $picked;
                    if (!in_array('moisture_use', $asked, true)) {
                        $asked[] = 'moisture_use';
                    }
                }
            }
            $state['filters'] = $filters;
            $state['asked'] = $asked;
        }
        if (!is_numeric($filters['moisture_temp'] ?? null)) {
            $temps = AssistantV2Filters::moistureTemps($filters);
            if ($temps === []) {
                $relaxed = $filters;
                $relaxed['moisture_form'] = null;
                $temps = AssistantV2Filters::moistureTemps($relaxed);
            }
            if ($temps === []) {
                $state['filters'] = $filters;

                return null;
            }
            $picked = in_array('moisture_temp', $asked, true) ? self::statedCelsius($message) : null;
            if ($picked === null || !in_array($picked, $temps, true)) {
                if (!in_array('moisture_temp', $asked, true)) {
                    $asked[] = 'moisture_temp';
                }
                $options = [];
                foreach ($temps as $temp) {
                    $options[] = $temp . '°C';
                }
                $state['filters'] = $filters;

                return self::moistureAsk($state, $plan, $asked, 'moisture_temp', $options, $corpus);
            }
            $filters['moisture_temp'] = $picked;
            $state['filters'] = $filters;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @param list<string> $asked
     * @param list<string> $options
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @return array<string, mixed>|null
     */
    private static function moistureContext(array $filters): bool
    {
        if (AssistantV2Filters::isMoistureGroup($filters)) {
            return true;
        }
        $sub = AssistantV2Catalog::fold((string) ($filters['subcategory'] ?? ''));

        return str_contains($sub, 'nem') && str_contains($sub, 'tayin');
    }

    private static function forceMoistureTempAsk(array &$state, array $plan, string $message): ?array
    {
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        if (!self::moistureContext($filters)) {
            return null;
        }
        if (is_numeric($state['filters']['moisture_temp'] ?? null)) {
            return null;
        }
        $corpus = is_string($state['dialog_corpus'] ?? null) ? (string) $state['dialog_corpus'] : $message;
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        if (!in_array($filters['moisture_form'] ?? null, ['liquid', 'solid'], true)) {
            $filters['moisture_form'] = self::inferSampleForm($corpus) ?? 'solid';
            $state['filters'] = $filters;
        }
        $temps = AssistantV2Filters::moistureTemps($filters);
        if ($temps === []) {
            $relaxed = $filters;
            $relaxed['moisture_form'] = null;
            $relaxed['moisture_use'] = null;
            $temps = AssistantV2Filters::moistureTemps($relaxed);
        }
        if ($temps === []) {
            foreach (AssistantV2Catalog::products() as $product) {
                if (AssistantV2Catalog::kindOf($product) !== 'nem') {
                    continue;
                }
                $have = $product['drying_c'] ?? null;
                if (is_numeric($have)) {
                    $temps[(int) $have] = (int) $have;
                }
            }
            $temps = array_values($temps);
            sort($temps, SORT_NUMERIC);
        }
        if ($temps === []) {
            $temps = [160, 200, 250];
        }
        $asked = is_array($state['asked'] ?? null) ? $state['asked'] : [];
        foreach (['moisture_use', 'moisture_form'] as $step) {
            if (!in_array($step, $asked, true)) {
                $asked[] = $step;
            }
        }
        if (!in_array('moisture_temp', $asked, true)) {
            $asked[] = 'moisture_temp';
        }
        $options = [];
        foreach ($temps as $temp) {
            $options[] = $temp . '°C';
        }

        return self::moistureAsk($state, $plan, $asked, 'moisture_temp', $options, $corpus);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @param list<string> $asked
     * @param list<string> $options
     * @return array<string, mixed>
     */
    private static function moistureAsk(array $state, array $plan, array $asked, string $ask, array $options, string $corpus = ''): array
    {
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        $facts = [
            'mode' => 'clarify',
            'ask' => $ask,
            'options' => $options,
            'user_need' => self::needFacts($filters),
        ];
        $material = self::sampleMaterialHint($corpus);
        if ($material !== '') {
            $facts['sample_material'] = $material;
        }
        self::attachConsultantHints($facts, $filters, $ask);
        $useLuna = AssistantV2Prompt::lunaConsultant() || AssistantV2Prompt::conversational();

        return [
            'mode' => 'clarify',
            'tool' => 'get_available_facets',
            'facts' => $facts,
            'reply' => $useLuna ? null : self::clarifyLine($ask),
            'cards' => [],
            'total' => 0,
            'facets' => [],
            'answered' => true,
            'gap' => null,
            'cta' => 'none',
            'focus_ids' => $state['focus_ids'],
            'offset' => 0,
            'asked' => $asked,
            'filters' => $state['filters'],
            'plan' => $plan,
        ];
    }

    /**
     * @param list<array{role:string,text:string}> $history
     * @param array<string, mixed> $state
     */
    private static function vagueSampleUtterance(string $message, array $history, array $state): bool
    {
        if (!AssistantV2Prompt::conversational()) {
            return false;
        }
        if (self::wantsProductCards($message) || self::messageNeedsSpecs($message)) {
            return false;
        }
        if (in_array('sample_goal', $state['asked'] ?? [], true)) {
            return false;
        }
        if (AssistantV2Filters::hasMeasure($state['filters']) || self::moistureBrowseReady($state)) {
            return false;
        }
        $fold = AssistantV2Catalog::fold(trim($message));
        if ($fold === '') {
            return false;
        }
        if (count(preg_split('/\s+/u', $fold) ?: []) > 4) {
            return false;
        }
        if (preg_match('/nem|tayin|terazi|tart|kapasite|hassasiyet|cihaz|olcum|kurutma/u', $fold) === 1) {
            return false;
        }

        return preg_match(
            '/(^| )(sut tozu|un|baharat|yem|tahil|gida|peynir|kakao|kahve|bebek mam|seker|tuz|numune)( |$)|tozu| toz /u',
            $fold
        ) === 1;
    }

    /**
     * @param list<array{role:string,text:string}> $history
     * @param array<string, mixed> $state
     */
    private static function nemIntentConfirmed(string $message, array $history, array $state): bool
    {
        $corpus = is_string($state['dialog_corpus'] ?? null) && trim($state['dialog_corpus']) !== ''
            ? (string) $state['dialog_corpus']
            : self::userCorpus($message, $history);
        $fold = AssistantV2Catalog::fold($corpus);
        if (preg_match('/nem tayin|nem olcum|nem olc|halojen|kurutma sicak|nem tayin cihaz/u', $fold) === 1) {
            return true;
        }
        foreach (['moisture_form', 'moisture_temp'] as $step) {
            if (in_array($step, $state['asked'] ?? [], true)) {
                return true;
            }
        }

        return AssistantV2Filters::isMoistureGroup($state['filters']) && self::moistureBrowseReady($state);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function vagueSampleIntentClarify(array $state, string $message): array
    {
        $asked = is_array($state['asked'] ?? null) ? $state['asked'] : [];
        $asked[] = 'sample_goal';
        $material = trim($message);
        $facts = [
            'mode' => 'clarify',
            'ask' => 'sample_goal',
            'options' => ['Nem tayini', 'Tartım / terazi'],
            'sample_material' => $material,
        ];
        self::attachConsultantHints($facts, $state['filters'] ?? [], 'sample_goal');
        $useLuna = AssistantV2Prompt::lunaConsultant() || AssistantV2Prompt::conversational();

        return [
            'mode' => 'clarify',
            'tool' => 'get_available_facets',
            'facts' => $facts,
            'reply' => $useLuna ? null : ($material !== ''
                ? $material . ' için nem tayini mi yapmak istiyorsunuz, yoksa tartım mı?'
                : 'Numune için nem tayini mi yoksa tartım mı düşünüyorsunuz?'),
            'cards' => [],
            'total' => 0,
            'facets' => [],
            'answered' => true,
            'gap' => null,
            'cta' => 'none',
            'focus_ids' => [],
            'offset' => 0,
            'asked' => $asked,
            'filters' => $state['filters'],
            'plan' => self::emptyPlan(),
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function resolveSampleGoalAnswer(string $message, array $state): ?string
    {
        if (!in_array('sample_goal', $state['asked'] ?? [], true)) {
            return null;
        }
        $fold = AssistantV2Catalog::fold($message);
        if (preg_match('/nem|tayin|kurutma|halojen/u', $fold) === 1) {
            return 'moisture';
        }
        if (preg_match('/terazi|tartim|tartma|kantar/u', $fold) === 1) {
            return 'scale';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private static function openMoistureCatalog(array &$state): ?array
    {
        foreach (AssistantV2Catalog::taxonomy() as $node) {
            if (AssistantV2Catalog::kindOfNode($node) !== 'nem' || (int) ($node['products'] ?? 0) < 1) {
                continue;
            }
            $state = self::resetSearch($state);

            return self::openCatalogNode($state, (string) $node['slug']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function moistureBrowseReady(array $state, string $message = ''): bool
    {
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        if (!AssistantV2Filters::isMoistureGroup($filters)) {
            return true;
        }
        if (self::wantsProductCards($message)) {
            return true;
        }
        if (!in_array($filters['moisture_form'] ?? null, ['liquid', 'solid'], true)) {
            return false;
        }
        $asked = is_array($state['asked'] ?? null) ? $state['asked'] : [];
        if (!in_array('moisture_use', $asked, true)) {
            return false;
        }
        $temps = AssistantV2Filters::moistureTemps($filters);
        if ($temps === []) {
            $relaxed = $filters;
            $relaxed['moisture_form'] = null;
            $temps = AssistantV2Filters::moistureTemps($relaxed);
        }
        if (!is_numeric($filters['moisture_temp'] ?? null)) {
            if ($temps !== [] || !in_array('moisture_temp', $asked, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function decisionGuidanceResult(array $state, string $message): array
    {
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        $names = [];
        $byId = AssistantV2Catalog::byId();
        foreach (array_slice(is_array($state['focus_ids'] ?? null) ? $state['focus_ids'] : [], 0, 3) as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($byId[$id])) {
                $names[] = (string) ($byId[$id]['name'] ?? '');
            }
        }
        $sample = is_string($filters['moisture_use'] ?? null) ? trim($filters['moisture_use']) : '';

        return [
            'mode' => 'guidance',
            'tool' => 'none',
            'facts' => [
                'mode' => 'guidance',
                'user_uncertain' => true,
                'decision_criteria' => [
                    'Prosedürdeki maksimum kurutma sıcaklığı',
                    'Numune kapasitesi',
                    'Yöntemdeki tartım hassasiyeti',
                ],
                'sample_material' => $sample,
                'example_models' => array_values(array_filter(array_slice($names, 0, 2))),
            ],
            'reply' => '',
            'cards' => [],
            'total' => 0,
            'facets' => [],
            'answered' => true,
            'gap' => null,
            'cta' => 'human_handoff',
            'focus_ids' => $state['focus_ids'] ?? [],
            'offset' => (int) ($state['offset'] ?? 0),
            'asked' => $state['asked'] ?? [],
            'filters' => $filters,
            'plan' => self::emptyPlan(),
        ];
    }

    private static function sampleForm(string $message): ?string
    {
        $fold = AssistantV2Catalog::fold($message);
        $liquid = preg_match('/(^|[^a-z])sivi([^a-z]|$)/u', $fold) === 1;
        $solid = preg_match('/(^|[^a-z])kati([^a-z]|$)/u', $fold) === 1;
        if ($liquid && !$solid) {
            return 'liquid';
        }
        if ($solid && !$liquid) {
            return 'solid';
        }

        return null;
    }

    private static function inferSampleForm(string $corpus): ?string
    {
        $fold = AssistantV2Catalog::fold($corpus);
        if ($fold === '') {
            return null;
        }
        if (preg_match('/(^|[^a-z])sivi([^a-z]|$)/u', $fold) === 1) {
            return 'liquid';
        }
        if (preg_match(
            '/sut tozu|bebek mam|un | baharat|tahil|toz|peynir|kakao|seker|tuz|yem|partikul|granul|katı|kati|pul |cips|kurutulmus|gida toz/u',
            $fold
        ) === 1) {
            return 'solid';
        }
        if (preg_match('/sut[^ ]|yogurt|surup|macun|yag[^ ]|sos|bal[^ ]|pekmez/u', $fold) === 1) {
            return 'liquid';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function alignClarifyReply(string $text, string $ask, array $facts): ?string
    {
        if ($ask === '' || trim($text) === '') {
            return null;
        }
        $fold = AssistantV2Catalog::fold($text);
        $rootFold = trim((string) ($facts['usage_root'] ?? ''));
        if (
            in_array($ask, ['capacity', 'precision', 'near'], true)
            && $rootFold !== ''
            && str_contains($rootFold, 'kuyumcu')
            && str_contains($fold, 'laboratuvar')
        ) {
            $fixed = trim(self::chipClarifyFastReply($facts, $ask));

            return $fixed !== '' ? $fixed : null;
        }
        $material = trim((string) ($facts['sample_material'] ?? ''));
        $warm = static fn (string $body): string => $material !== ''
            ? ($material . ' için ' . $body)
            : $body;
        return match ($ask) {
            'moisture_temp' => preg_match('/sicak|derece|°/u', $fold) === 1 ? null : $warm('nem tayininde prosedürünüzdeki maksimum kurutma sıcaklığı kaç °C olmalı?'),
            'moisture_form' => preg_match('/sivi|kati|katı|sıvı/u', $fold) === 1 ? null : $warm('numune sıvı mı katı mı? Kurutma modu buna göre değişir.'),
            'moisture_use' => preg_match('/numune|ornek|olcum|ölçüm/u', $fold) === 1 ? null : $warm('hangi numune veya ürün grubu için nem ölçümü yapacaksınız?'),
            default => null,
        };
    }

    private static function sampleMaterialHint(string $corpus): string
    {
        $fold = AssistantV2Catalog::fold($corpus);
        if (preg_match('/sut tozu/u', $fold) === 1) {
            return 'süt tozu';
        }
        foreach (['un', 'baharat', 'yem', 'tahıl', 'peynir', 'kakao', 'kahve', 'bebek maması'] as $word) {
            $needle = AssistantV2Catalog::fold($word);
            if ($needle !== '' && str_contains($fold, $needle)) {
                return $word;
            }
        }

        return '';
    }

    /**
     * @param list<string> $audiences
     */
    private static function matchAudience(string $message, array $audiences): ?string
    {
        $fold = AssistantV2Catalog::fold($message);
        if ($fold === '') {
            return null;
        }
        foreach ($audiences as $line) {
            if (AssistantV2Catalog::fold($line) === $fold) {
                return $line;
            }
        }
        foreach ($audiences as $line) {
            $needle = AssistantV2Catalog::fold($line);
            if ($needle !== '' && str_contains($fold, $needle)) {
                return $line;
            }
        }

        return null;
    }

    private static function statedCelsius(string $message): ?int
    {
        if (preg_match('/(\d{2,3})\s*°?\s*c?\b/iu', $message, $match) !== 1) {
            return null;
        }
        $celsius = (int) $match[1];
        if ($celsius < 40 || $celsius > 400) {
            return null;
        }

        return $celsius;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function keepContext(string $message, array $state): bool
    {
        $has = AssistantV2Filters::hasConstraint($state['filters']) || ($state['focus_ids'] ?? []) !== [];
        if (!$has) {
            return false;
        }
        if (self::wantsProductCards($message)) {
            return true;
        }
        if (self::isSpecQuestion($message) && AssistantV2Catalog::statedUsage($message) === null && AssistantV2Catalog::matchedChildName($message) === null) {
            return true;
        }
        if (self::isFreshRequest($message) && (AssistantV2Catalog::statedUsage($message) !== null || AssistantV2Catalog::matchedChildName($message) !== null)) {
            return false;
        }
        if (AssistantV2Filters::isMoistureGroup($state['filters']) && self::sampleForm($message) !== null && !self::isSpecQuestion($message)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function hasSpecContext(array $state): bool
    {
        if (($state['focus_ids'] ?? []) !== []) {
            return true;
        }
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        if (is_numeric($filters['precision']['value'] ?? null)) {
            return true;
        }
        if (is_string($filters['subcategory'] ?? null) && $filters['subcategory'] !== '') {
            return true;
        }

        return is_numeric($filters['moisture_temp'] ?? null);
    }

    private static function wantsProductCards(string $message): bool
    {
        $fold = AssistantV2Catalog::fold($message);

        return preg_match('/urunleri goster|urunleri gor|kartlari|listele|hepsini goster|modelleri goster|gosterir misin/u', $fold) === 1
            || preg_match('/(^| )(goster|gosterin|listele)( |$)/u', $fold) === 1;
    }

    private static function isSpecQuestion(string $message): bool
    {
        if (self::wantsProductCards($message) || self::precisionChip($message) || self::capacityChip($message)) {
            return false;
        }
        $fold = AssistantV2Catalog::fold($message);
        if (AssistantV2Prompt::conversational()) {
            $judges = preg_match('/yeterli mi|yeter mi|uygun mu|uyar mi|olur mu|ise yarar/u', $fold) === 1;
            if (!$judges && (self::statedPrecision($message) !== null || self::messageStatesCapacity($message))) {
                return false;
            }
            if (preg_match('/\d{2,3}\s*°?\s*(c|derece)?\s*mi\s+\d{2,3}/iu', $message) === 1) {
                return true;
            }
            if (preg_match('/(^| )\d+\s*mi\s+\d+/u', $fold) === 1) {
                return true;
            }

            return preg_match('/uygun mu|uyar mi|yeterli mi|yeter mi|olur mu|sivi|kati|sicaklik|bu ise|yogunluk|nem|numune|karat|ayar|kapasite|hassasiyet|olcum/u', $fold) === 1;
        }

        return preg_match('/uygun mu|uyar mi|daha iyi|hangisi|karsilastir|kiyas|sivi|kati|sicaklik|bu ise|farki|farklar|yogunluk|nem|numune|karat|kapasite|hassasiyet|olcum/u', $fold) === 1;
    }

    /**
     * Mesaj, odaktaki ürünün bir teknik özellik satırının adına değiniyor mu (garanti, ekran, kalibrasyon, metaller gibi).
     * Cevap o ürünün kayıtlı satırından gelir.
     *
     * @param array<string, mixed> $state
     */
    private static function touchesSpecLabel(string $message, array $state): bool
    {
        if (!AssistantV2Prompt::conversational() || self::isFreshRequest($message) || self::wantsProductCards($message)) {
            return false;
        }
        $ids = array_slice(is_array($state['focus_ids'] ?? null) ? $state['focus_ids'] : [], 0, 4);
        if ($ids === []) {
            return false;
        }
        $said = [];
        foreach (preg_split('/[^a-z0-9]+/u', AssistantV2Catalog::fold($message)) ?: [] as $token) {
            if (is_string($token) && strlen($token) >= 5) {
                $said[] = $token;
            }
        }
        if ($said === []) {
            return false;
        }
        $generic = ['kategori', 'urunun', 'urunu', 'urunler', 'terazi', 'terazisi', 'teraziler', 'cihaz'];
        $byId = AssistantV2Catalog::byId();
        foreach ($ids as $id) {
            $product = $byId[(int) $id] ?? null;
            if (!is_array($product)) {
                continue;
            }
            foreach (is_array($product['specs'] ?? null) ? $product['specs'] : [] as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                foreach (preg_split('/[^a-z0-9]+/u', AssistantV2Catalog::fold((string) ($spec['label'] ?? ''))) ?: [] as $labelToken) {
                    if (!is_string($labelToken) || strlen($labelToken) < 5 || in_array($labelToken, $generic, true)) {
                        continue;
                    }
                    foreach ($said as $token) {
                        if (in_array($token, $generic, true)) {
                            continue;
                        }
                        $common = 0;
                        $limit = min(strlen($token), strlen($labelToken));
                        while ($common < $limit && $token[$common] === $labelToken[$common]) {
                            $common++;
                        }
                        if ($common >= 5 && $common >= (int) ceil($limit * 0.75)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function specResult(array $state, string $message): array
    {
        $products = [];
        $byId = AssistantV2Catalog::byId();
        foreach (array_slice(is_array($state['focus_ids'] ?? null) ? $state['focus_ids'] : [], 0, 6) as $id) {
            if (isset($byId[(int) $id])) {
                $products[] = $byId[(int) $id];
            }
        }
        if ($products === [] && AssistantV2Filters::hasConstraint($state['filters'])) {
            $found = AssistantV2Filters::search($state['filters'], 0, 6);
            $products = is_array($found['page'] ?? null) ? $found['page'] : [];
        }
        $briefs = [];
        $rowLimit = AssistantV2Prompt::conversational() ? (count($products) <= 2 ? 24 : 12) : 14;
        foreach ($products as $product) {
            $briefs[] = [
                'name' => (string) ($product['name'] ?? ''),
                'specs' => AssistantV2Filters::answerSpecs($product, $rowLimit),
            ];
        }

        return [
            'mode' => 'spec',
            'tool' => 'get_product_by_id',
            'facts' => [
                'mode' => 'spec',
                'products' => $briefs,
                'missing_means' => 'kayıtta yok',
            ],
            'reply' => '',
            'cards' => [],
            'total' => 0,
            'facets' => [],
            'answered' => true,
            'gap' => null,
            'cta' => 'none',
            'focus_ids' => $state['focus_ids'],
            'offset' => (int) ($state['offset'] ?? 0),
            'asked' => $state['asked'],
            'filters' => $state['filters'],
            'plan' => self::emptyPlan(),
        ];
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function specGuard(string $text, array $facts, string $message): ?string
    {
        $briefs = is_array($facts['products'] ?? null) ? $facts['products'] : [];
        $blob = '';
        $any = false;
        foreach ($briefs as $brief) {
            if (!is_array($brief)) {
                continue;
            }
            foreach (is_array($brief['specs'] ?? null) ? $brief['specs'] : [] as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $blob .= ' ' . (string) ($spec['label'] ?? '') . ' ' . (string) ($spec['value'] ?? '');
                $any = true;
            }
        }
        $asksForm = self::sampleForm($message) !== null || preg_match('/sivi|kati/u', AssistantV2Catalog::fold($message)) === 1;
        $formInRecord = preg_match('/sivi|kati/u', AssistantV2Catalog::fold($blob)) === 1;
        if ($asksForm && !$formInRecord) {
            $summary = self::specSummary($briefs, $message);

            return $summary === ''
                ? 'Kayıtta sıvı veya katı ayrımı yok.'
                : 'Kayıtta sıvı veya katı ayrımı yok. ' . $summary;
        }
        if (!$any) {
            return 'Bu konuda kayıtlı teknik özellik yok.';
        }
        $cited = false;
        $foldedText = AssistantV2Catalog::fold($text);
        foreach ($briefs as $brief) {
            if (!is_array($brief)) {
                continue;
            }
            foreach (is_array($brief['specs'] ?? null) ? $brief['specs'] : [] as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $value = AssistantV2Catalog::fold((string) ($spec['value'] ?? ''));
                if (mb_strlen($value) >= 2 && str_contains($foldedText, $value)) {
                    $cited = true;
                    break 2;
                }
            }
        }
        if ($text === '' || !$cited) {
            $summary = self::specSummary($briefs, $message);

            return $summary !== '' ? $summary : 'Bu konuda kayıtlı teknik özellik yok.';
        }
        if (preg_match_all('/\d+(?:[.,]\d+)?/u', $text, $numbers) >= 1) {
            $hay = AssistantV2Catalog::fold($blob);
            foreach ($numbers[0] as $number) {
                $token = str_replace(',', '.', (string) $number);
                if (!str_contains($hay, AssistantV2Catalog::fold($token)) && !str_contains(str_replace(',', '.', $hay), $token)) {
                    return self::specSummary($briefs, $message);
                }
            }
        }

        return null;
    }

    private static function labelTouched(string $label, string $message): bool
    {
        $said = [];
        foreach (preg_split('/[^a-z0-9]+/u', AssistantV2Catalog::fold($message)) ?: [] as $token) {
            if (is_string($token) && strlen($token) >= 5) {
                $said[] = $token;
            }
        }
        foreach (preg_split('/[^a-z0-9]+/u', AssistantV2Catalog::fold($label)) ?: [] as $labelToken) {
            if (!is_string($labelToken) || strlen($labelToken) < 5) {
                continue;
            }
            foreach ($said as $token) {
                $common = 0;
                $limit = min(strlen($token), strlen($labelToken));
                while ($common < $limit && $token[$common] === $labelToken[$common]) {
                    $common++;
                }
                if ($common >= 5 && $common >= (int) ceil($limit * 0.75)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<mixed> $briefs
     */
    private static function specSummary(array $briefs, string $message = ''): string
    {
        $parts = [];
        foreach (array_slice($briefs, 0, 3) as $brief) {
            if (!is_array($brief)) {
                continue;
            }
            $bits = [];
            $rows = is_array($brief['specs'] ?? null) ? $brief['specs'] : [];
            if ($message !== '' && AssistantV2Prompt::conversational()) {
                $touched = array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row) && self::labelTouched((string) ($row['label'] ?? ''), $message)));
                if ($touched !== []) {
                    $rows = $touched;
                }
            }
            foreach (array_slice($rows, 0, 4) as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $label = trim((string) ($spec['label'] ?? ''));
                $value = trim((string) ($spec['value'] ?? ''));
                if ($label !== '' && $value !== '') {
                    $bits[] = $label . ' ' . $value;
                }
            }
            $name = trim((string) ($brief['name'] ?? ''));
            if ($name !== '' && $bits !== []) {
                $parts[] = $name . ': ' . implode(', ', $bits);
            }
        }

        return $parts === [] ? '' : implode('. ', $parts) . '.';
    }

    /**
     * @return array<string, string>
     */
    private static function filterShape(): array
    {
        return [
            'category' => 'taxonomy slug veya ad ya da null',
            'subcategory' => 'taxonomy slug veya ad ya da null',
            'brands' => 'kanonik marka adları',
            'precision' => '{value, unit:g|ct}',
            'capacity' => '{operator:exact|approximately|max|min|range|lt, value, unit:g|kg|mg, min, max}',
            'commercial_approval' => 'bool|null',
            'calibration' => 'string|null',
            'series' => 'series listesindeki kod ya da null',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyPlan(): array
    {
        return self::normalizePlan([]);
    }
}
