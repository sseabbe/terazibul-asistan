<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Tur başına yapılandırılmış JSON analiz çağrısı.
 */
final class AssistantV2AdvisorAnalyzer
{
    /** @var callable|null Test override: (message, history, state, page) => array<string,mixed> */
    public static $testAnalyzeOverride = null;

    /**
     * @param list<array{role:string,text:string}> $history
     * @param array<string, mixed> $state
     * @param array{brand?:string,category?:string} $page
     * @return array<string, mixed>
     */
    public static function analyze(string $message, array $history, array $state, array $page = []): array
    {
        if (self::$testAnalyzeOverride !== null) {
            $mock = (self::$testAnalyzeOverride)($message, $history, $state, $page);
            if (is_array($mock)) {
                return self::sanitizeAnalysis($mock, $message, $state);
            }
        }

        $state = AssistantV2AdvisorState::normalize($state);
        $category = $state['advisor']['category'] ?? '';
        $subcategory = $state['advisor']['subcategory'] ?? '';
        $profileHints = (is_string($category) && $category !== '') || (is_string($subcategory) && $subcategory !== '')
            ? AssistantV2AdvisorState::profileHints(is_string($category) ? $category : '', is_string($subcategory) ? $subcategory : '')
            : [];

        $payload = [
            'user_message'       => $message,
            'conversation_state' => AssistantV2AdvisorState::summaryForLlm($state),
            'recent_turns'         => self::formatHistory($history),
            'page_context'         => [
                'brand'    => trim((string) ($page['brand'] ?? '')),
                'category' => trim((string) ($page['category'] ?? '')),
            ],
            'category_profile_hints' => $profileHints,
            'catalog_roots'          => array_keys(AssistantV2Catalog::usageRoots()),
        ];

        $llm = AssistantV2Llm::complete(
            self::systemPrompt(),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            28,
            'v2_advisor_analyze',
            true
        );

        if (!is_array($llm)) {
            return self::fallback($message, $state);
        }

        return self::sanitizeAnalysis($llm, $message, $state);
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function sanitizeAnalysis(array $raw, string $message, array $state): array
    {
        $intent = trim((string) ($raw['intent'] ?? 'product_discovery'));
        $out = [
            'intent'                     => $intent,
            'category'                   => $raw['category'] ?? null,
            'subcategory'                => $raw['subcategory'] ?? null,
            'relation'                   => trim((string) ($raw['relation'] ?? 'continue_search')),
            'new_facts'                  => is_array($raw['new_facts'] ?? null) ? $raw['new_facts'] : [],
            'filter_updates'             => is_array($raw['filter_updates'] ?? null) ? $raw['filter_updates'] : [],
            'remove_constraints'         => AssistantV2Filters::names($raw['remove_constraints'] ?? []),
            'missing_information'        => is_array($raw['missing_information'] ?? null) ? $raw['missing_information'] : [],
            'ready_for_product_results'  => ($raw['ready_for_product_results'] ?? false) === true,
            'needs_human_support'        => ($raw['needs_human_support'] ?? false) === true,
            'needs_catalog_search'       => ($raw['needs_catalog_search'] ?? true) !== false,
            'needs_whatsapp'             => ($raw['needs_whatsapp'] ?? false) === true,
            'needs_phone'                => ($raw['needs_phone'] ?? false) === true,
            'service_topic'              => trim((string) ($raw['service_topic'] ?? '')),
            'needsWebSearch'             => ($raw['needsWebSearch'] ?? false) === true,
            'webSearchReason'            => trim((string) ($raw['webSearchReason'] ?? '')),
            'webSearchTarget'            => self::sanitizeWebTarget($raw['webSearchTarget'] ?? null),
        ];

        if ($out['filter_updates'] === [] && is_array($raw['filters'] ?? null)) {
            $out['filter_updates'] = $raw['filters'];
        }

        $rawIntent = trim((string) ($raw['intent'] ?? ''));
        $knownIntents = ['product_discovery', 'service', 'price', 'compare', 'off_topic', 'general'];
        if (!in_array($rawIntent, $knownIntents, true)
            && preg_match('/\b(servis|tamir|kalibrasyon|garanti|ariza)\b/u', AssistantV2Catalog::fold($message)) === 1
        ) {
            $out['intent'] = 'service';
            $out['ready_for_product_results'] = false;
        }

        $out = self::applyClassificationPrecedence($out, $message);

        $out = self::applyWebSearchPolicy($out, $message);

        if ($out['ready_for_product_results'] && !AssistantV2Filters::hasConstraint(
            AssistantV2Filters::merge(
                ['filters' => $state['filters'], 'ranking' => $state['ranking']],
                ['relation' => 'continue_search', 'add_constraints' => $out['filter_updates']]
            )['filters']
        )) {
            $out['ready_for_product_results'] = false;
        }

        return $out;
    }

    /**
     * Geçerli analyzer category/subcategory keyword helper tarafından ezilmez.
     * Keyword yalnızca analyzer boş veya geçersizse ve konu ürünse fallback'tir.
     *
     * @param array<string, mixed> $out
     * @return array<string, mixed>
     */
    private static function applyClassificationPrecedence(array $out, string $message): array
    {
        $analyzerCategory = self::catalogSlug($out['category'] ?? null);
        $analyzerSub = self::catalogSlug($out['subcategory'] ?? null);
        if ($analyzerCategory === null) {
            $analyzerCategory = self::catalogSlug($out['filter_updates']['category'] ?? null);
        }
        if ($analyzerSub === null) {
            $analyzerSub = self::catalogSlug($out['filter_updates']['subcategory'] ?? null);
        }
        if ($analyzerSub !== null) {
            $node = AssistantV2Catalog::node($analyzerSub);
            if ($node !== null && $node['parent'] !== ''
                && ($analyzerCategory === null || !AssistantV2Catalog::isChildOf($analyzerSub, $analyzerCategory))
            ) {
                $analyzerCategory = $node['parent'];
            }
        }

        $keyword = self::keywordClassification($message);
        $offTopic = (string) ($out['intent'] ?? '') === 'off_topic';
        $analyzerValid = $analyzerCategory !== null || $analyzerSub !== null;

        $finalCategory = $analyzerCategory;
        $finalSub = $analyzerSub;
        $source = 'none';
        if ($analyzerValid) {
            $source = 'analyzer';
        } elseif (!$offTopic && ($keyword['category'] !== null || $keyword['subcategory'] !== null)) {
            $source = 'keyword_fallback';
            $finalCategory = $keyword['category'];
            $finalSub = $keyword['subcategory'];
            if ($keyword['type_token'] !== null && !self::filledString($out['filter_updates']['type_token'] ?? null)) {
                $out['filter_updates']['type_token'] = $keyword['type_token'];
            }
        }

        if ($finalCategory !== null) {
            $out['category'] = $finalCategory;
            $out['filter_updates']['category'] = $finalCategory;
        }
        if ($finalSub !== null) {
            $out['subcategory'] = $finalSub;
            $out['filter_updates']['subcategory'] = $finalSub;
        }

        $out['classification'] = [
            'analyzer_category'     => $analyzerCategory,
            'analyzer_subcategory'  => $analyzerSub,
            'keyword_fallback'      => $keyword['label'],
            'final_category'        => $finalCategory,
            'final_subcategory'     => $finalSub,
            'classification_source' => $source,
        ];

        return $out;
    }

    /**
     * @return array{category:?string,subcategory:?string,type_token:?string,label:?string}
     */
    private static function keywordClassification(string $message): array
    {
        $usage = AssistantV2Catalog::statedUsage($message);
        $labType = AssistantV2Catalog::statedLabScaleType($message);
        $category = null;
        $sub = null;
        $type = null;

        if ($labType !== null && ($usage === null || $usage === 'laboratuvar')) {
            $lab = AssistantV2Catalog::labScaleFilters($message);
            $category = 'laboratuvar';
            $sub = is_string($lab['subcategory'] ?? null) && $lab['subcategory'] !== '' ? $lab['subcategory'] : null;
            $type = is_string($lab['type_token'] ?? null) && $lab['type_token'] !== '' ? $lab['type_token'] : null;
        }
        if ($category === null && $usage !== null) {
            $category = $usage;
        }
        if ($category === null && $sub === null) {
            $child = AssistantV2Catalog::statedChild($message);
            if ($child !== null) {
                $category = $child['category'];
                $sub = $child['subcategory'];
            }
        }

        $label = null;
        if ($category !== null || $sub !== null) {
            $label = $category ?? '';
            if ($sub !== null) {
                $label = ($label !== '' ? $label . ' > ' : '') . $sub;
            }
        }

        return [
            'category'    => $category,
            'subcategory' => $sub,
            'type_token'  => $type,
            'label'       => $label,
        ];
    }

    private static function catalogSlug(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $node = AssistantV2Catalog::node($value);
        if ($node === null) {
            $node = AssistantV2Catalog::resolveGroup($value);
        }

        return is_array($node) ? (string) $node['slug'] : null;
    }

    private static function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function fallback(string $message, array $state): array
    {
        $state = AssistantV2AdvisorState::applyCatalogHints($state, $message);
        $filterUpdates = [];
        if (is_string($state['filters']['category'] ?? null) && $state['filters']['category'] !== '') {
            $filterUpdates['category'] = $state['filters']['category'];
        }
        if (is_string($state['filters']['subcategory'] ?? null) && $state['filters']['subcategory'] !== '') {
            $filterUpdates['subcategory'] = $state['filters']['subcategory'];
        }

        return [
            'intent'                    => 'product_discovery',
            'category'                  => $state['advisor']['category'],
            'subcategory'               => $state['advisor']['subcategory'],
            'relation'                  => 'continue_search',
            'new_facts'                 => [],
            'filter_updates'            => $filterUpdates,
            'missing_information'       => $state['advisor']['category'] === null
                ? ['kullanım alanı veya ürün grubu']
                : ['kapasite veya okuma değeri'],
            'ready_for_product_results' => false,
            'needs_human_support'       => false,
            'needs_catalog_search'      => true,
            'needs_whatsapp'            => false,
            'needs_phone'               => false,
            'service_topic'             => '',
            'needsWebSearch'            => false,
            'webSearchReason'           => '',
            'webSearchTarget'           => ['brand' => '', 'model' => '', 'requestedFact' => ''],
        ];
    }

    /**
     * @return array{brand:string,model:string,requestedFact:string}
     */
    private static function sanitizeWebTarget(mixed $raw): array
    {
        $empty = ['brand' => '', 'model' => '', 'requestedFact' => ''];
        if (!is_array($raw)) {
            return $empty;
        }

        return [
            'brand'         => trim((string) ($raw['brand'] ?? '')),
            'model'         => trim((string) ($raw['model'] ?? '')),
            'requestedFact' => trim((string) ($raw['requestedFact'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $out
     * @return array<string, mixed>
     */
    private static function applyWebSearchPolicy(array $out, string $message): array
    {
        if (($out['needsWebSearch'] ?? false) !== true) {
            $out['needsWebSearch'] = false;

            return $out;
        }

        $intent = (string) ($out['intent'] ?? '');
        if ($intent === 'off_topic') {
            $out['needsWebSearch'] = false;
            $out['webSearchReason'] = '';

            return $out;
        }

        if ($intent === 'product_discovery' && preg_match('/\bterazi\s*(ariyorum|lazim|istiyorum)\b/u', AssistantV2Catalog::fold($message)) === 1) {
            $out['needsWebSearch'] = false;

            return $out;
        }

        $target = is_array($out['webSearchTarget'] ?? null) ? $out['webSearchTarget'] : [];
        if (trim((string) ($target['brand'] ?? '')) === '' || trim((string) ($target['requestedFact'] ?? '')) === '') {
            $out['needsWebSearch'] = false;

            return $out;
        }

        if (AssistantV2AdvisorWebSearch::domainForBrand((string) $target['brand']) === '') {
            $out['needsWebSearch'] = false;
            $out['webSearchReason'] = trim((string) ($out['webSearchReason'] ?? '')) . ' (domain yok)';

            return $out;
        }

        if ($intent === 'service' && ($out['service_topic'] ?? '') !== '' && !AssistantV2AdvisorRag::looksLikeErrorCodeQueryPublic($message)) {
            $fold = AssistantV2Catalog::fold($message);
            if (preg_match('/\b(manual|datasheet|sicaklik|kurutma|spec|ozellik)\b/u', $fold) !== 1) {
                $out['needsWebSearch'] = false;
            }
        }

        return $out;
    }

    /**
     * @param list<array{role:string,text:string}> $history
     */
    private static function formatHistory(array $history): string
    {
        $lines = [];
        foreach (array_slice($history, -6) as $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'Asistan' : 'Kullanıcı';
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $lines[] = $role . ': ' . $text;
        }

        return implode("\n", $lines);
    }

    private static function systemPrompt(): string
    {
        return <<<'TXT'
Sen Terazibul ürün danışmanının tur analiz modülüsün. Yalnızca geçerli JSON döndür.

Şema:
{
  "intent": "product_discovery|service|price|compare|off_topic|general",
  "category": "katalog kök slug veya null",
  "subcategory": "alt slug veya null",
  "relation": "continue_search|modify_search|broaden_search|new_search|non_product_question",
  "new_facts": { "serbest anahtar": "değer" },
  "filter_updates": {
    "category": "slug",
    "subcategory": "slug",
    "brands": ["Marka"],
    "precision": {"value": 0.0001, "unit": "g"},
    "capacity": {"operator": "min|max|approximately", "value_g": 220}
  },
  "remove_constraints": ["brand", "precision", "capacity", "series", "calibration", "category", "subcategory"],
  "missing_information": ["kısa Türkçe"],
  "ready_for_product_results": false,
  "needs_human_support": false,
  "needs_catalog_search": true,
  "needs_whatsapp": false,
  "needs_phone": false,
  "service_topic": "overview|warranty|fee|shipping|form|brand|trade|",
  "needsWebSearch": false,
  "webSearchReason": "",
  "webSearchTarget": {"brand": "", "model": "", "requestedFact": ""}
}

Kurallar:
- Ürün keşfinde yeterli teknik bağlam yoksa ready_for_product_results=false.
- Laboratuvar terazisinde genelde kapasite ve okuma (hassasiyet) netleşmeden ready=false.
- Servis/garanti/kalibrasyon talebinde intent=service, ready=false.
- filter_updates yalnızca katalogda karşılığı olan slug/marka/sayısal kısıtlar; uydurma model yok.
- Kullanıcı aynı kısıtı değiştirirse filter_updates yalnız yeni değeri taşır. Kapasite veya hassasiyet değişince söylenmeyen category, subcategory ve marka silinmez.
- Kullanıcı bir kısıtı kaldırırsa relation modify_search olur ve remove_constraints o alanı içerir: brand, precision, capacity, series, calibration, category, subcategory. Kaldırma ifadeleri: fark etmez, önemli değil, istemiyorum, gerek yok, önceki söylediğimi unut, marka tercihim yok. Kaldırılan alan filter_updates içinde tekrarlanmaz.
- approximately yalnız kullanıcı yaklaşık, civarı veya yaklaşık dediğinde. 200 g lazım ve 2 ton kapasite lazım operator min'dir. max, en fazla veya "tona/grama kadar" operator max'tır. 500 gramlık yük tartacağım operator min'dir.
- Kullanıcının söylediği markayı filter_updates.brands içine yaz. Marka adı katalogdaki tam adın parçasıysa da yaz.
- Kullanım amacı üst kategoriden önce gelir. Nem, rutubet veya granül nemi laboratuvar/nem-tayin'dir; test-ve-olcum veya perakende değildir. Yük hücresi ve load cell endustriyel/load-cell'dir; aksesuar değildir. Barkod veya etiket basan terazi perakende/barkodlu-teraziler'dir. 0,1 mg (0,0001 g) analitiktir, yarı analitik değildir.
- «Hassas terazi» tek başına laboratuvar değildir. Kullanıcı laboratuvar, kuyumcu, üretim veya ne tartacağını söylemeden category ve subcategory null kalır. Analitik, mikro veya laboratuvar açıkça geçiyorsa kategori ona göre yazılır.
- Asistan bir alan sorduysa ve kullanıcı kısa bir cevap verdiyse new_facts içinde o cevabı sakla. Hazır iki hassasiyet seçeneği (yalnız 0,01 g ve 0,001 g) dayatma.
- Kullanıcı yeni bir kullanım amacı söylerse relation new_search olur; eski kategori ve o tura ait kapasite/hassasiyet taşınmaz. Konu değişmediyse kategori korunur.
- new_facts kullanıcının söylediği bağlamı saklar (ör. numune tipi, ortam).
- off_topic: terazi/tartım dışı konular.
- needsWebSearch: varsayılan false. Web araması yapmazsın; yalnızca belirli model/özellik sorusunda ve site kataloğunda yanıt yoksa true öner.
- webSearchTarget: marka, model (varsa), requestedFact (aranan teknik olgu). Domain uydurma.
- Genel «terazi arıyorum» veya yetersiz bağlamda needsWebSearch=false.
TXT;
    }
}
