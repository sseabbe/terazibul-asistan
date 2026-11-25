<?php

declare(strict_types=1);

/**
 * Kalıcı gerçek kullanıcı regression suite.
 * 36 production senaryosu + son kullanım regression'ları.
 *
 * Chatbot kodunu çağırır; analyzer/state/search/evidence/handoff/RAG/listing değiştirmez.
 * Web'den çalışmaz.
 *
 * Run: php scripts/advisor_real_world_scenarios.php
 */

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit;
}

use App\Services\AssistantV2\AssistantV2AdvisorEvidence;
use App\Services\AssistantV2\AssistantV2AdvisorSearch;
use App\Services\AssistantV2\AssistantV2AdvisorTestSupport;
use App\Services\AssistantV2\AssistantV2Catalog;
use App\Services\AssistantV2\AssistantV2Filters;
use App\Services\AssistantV2\AssistantV2QuestionPlan;

require dirname(__DIR__) . '/vendor/autoload.php';

\Core\Env::load(dirname(__DIR__) . '/.env');
$app = new \Core\Application(dirname(__DIR__));
$app->boot();

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function analysis(array $extra = []): array
{
    return array_merge([
        'intent'                    => 'product_discovery',
        'ready_for_product_results' => false,
        'needs_catalog_search'      => true,
        'needsWebSearch'            => false,
    ], $extra);
}

/**
 * Üretimde geçen turların analyzer sözleşmesi. Kullanıcı metinleri aynı.
 *
 * @return list<array{id:string,group:string,turns:list<string>,analyses:list<array<string,mixed>>,checks:list<array<string,mixed>>}>
 */
function originalScenarios(): array
{
    $cap = static fn (string $op, string $value, string $unit = 'g'): array => [
        'operator' => $op,
        'value'    => $value,
        'unit'     => $unit,
    ];
    $prec = static fn (string $value, string $unit = 'g'): array => [
        'value' => $value,
        'unit'  => $unit,
    ];

    return [
        [
            'id' => 'A1', 'group' => 'original',
            'turns' => ['200 g 0.0001 g analitik terazi lazım'],
            'analyses' => [analysis([
                'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
                'ready_for_product_results' => true,
                'filter_updates' => [
                    'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
                    'capacity' => $cap('min', '200'), 'precision' => $prec('0.0001'),
                ],
            ])],
            'checks' => [[
                'intent' => 'product_discovery', 'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
                'ready' => true, 'show' => true, 'handoff' => '',
                'show_has' => ['kap_min=200', 'has_max=0.0001'],
            ]],
        ],
        [
            'id' => 'A2', 'group' => 'original',
            'turns' => ['0,1 mg hassasiyetli lab terazisi lazim, 200 gram civarı'],
            'analyses' => [analysis([
                'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
                'ready_for_product_results' => true,
                'filter_updates' => [
                    'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
                    'capacity' => $cap('approximately', '200'), 'precision' => $prec('0.0001'),
                ],
            ])],
            'checks' => [[
                'category' => 'laboratuvar', 'capacity_op' => 'approximately', 'capacity_g' => 200,
                'precision' => 0.0001, 'message_lacks' => ['Bu bilgiyi mevcut kaynaklarda doğrulayamıyorum.'],
            ]],
        ],
        [
            'id' => 'A3', 'group' => 'original',
            'turns' => ['analıtık terazi lazm 4 hane'],
            'analyses' => [analysis([
                'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
                'filter_updates' => [
                    'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
                    'precision' => $prec('0.0001'),
                ],
            ])],
            'checks' => [[
                'intent' => 'product_discovery', 'category' => 'laboratuvar',
                'ready' => false, 'show' => false, 'message_has' => ['gram'],
            ]],
        ],
        [
            'id' => 'A4', 'group' => 'original',
            'turns' => ['okul laboratuvarı için hassas terazi max 500 gr'],
            // Fold sonrası «laboratuvari» kelime sınırı «laboratuvar» sayılmaz; hassas terazi kategoriyi temizler. Kapasite max 500 kalır, liste açılmaz.
            'analyses' => [analysis([
                'category' => 'laboratuvar',
                'filter_updates' => [
                    'category' => 'laboratuvar',
                    'capacity' => $cap('max', '500'),
                ],
            ])],
            'checks' => [[
                'category' => '', 'subcategory' => '',
                'capacity_op' => 'max', 'capacity_g' => 500,
                'ready' => false, 'show' => false, 'handoff' => '',
            ]],
        ],
        [
            'id' => 'N1', 'group' => 'original',
            'turns' => ['plastik granülün nemini ölçmek istiyorum'],
            'analyses' => [analysis([
                'category' => 'laboratuvar', 'subcategory' => 'nem-tayin',
                'filter_updates' => ['category' => 'laboratuvar', 'subcategory' => 'nem-tayin'],
            ])],
            'checks' => [[
                'category' => 'laboratuvar', 'subcategory' => 'nem-tayin',
                'ready' => false, 'show' => false, 'total_lt' => 54,
                'message_lacks' => ['ne tartacaksınız', 'ne tartacaksiniz'],
            ]],
        ],
        [
            'id' => 'N2', 'group' => 'original',
            'turns' => ['gida numunesinin rutubetini olcmek istiyorum'],
            'analyses' => [analysis([
                'category' => 'laboratuvar', 'subcategory' => 'nem-tayin',
                'filter_updates' => ['category' => 'laboratuvar', 'subcategory' => 'nem-tayin'],
            ])],
            'checks' => [[
                'category' => 'laboratuvar', 'subcategory' => 'nem-tayin',
                'ready' => false, 'show' => false, 'total_lt' => 54,
                'message_lacks' => ['ne tartacaksınız', 'ne tartacaksiniz'],
            ]],
        ],
        [
            'id' => 'N3', 'group' => 'original',
            'turns' => ['nem tayin cihazı kaç para'],
            'analyses' => [analysis([
                'intent' => 'price',
                'category' => 'laboratuvar', 'subcategory' => 'nem-tayin',
                'filter_updates' => ['category' => 'laboratuvar', 'subcategory' => 'nem-tayin'],
            ])],
            'checks' => [[
                'intent' => 'price', 'subcategory' => 'nem-tayin', 'total_lt' => 54,
                'message_lacks_regex' => '/\d+\s*(tl|₺|lira)/u',
            ]],
        ],
        [
            'id' => 'K1', 'group' => 'original',
            'turns' => ['kuyumcu terazisi arıyorum'],
            'analyses' => [analysis([
                'category' => 'kuyumcu',
                'filter_updates' => ['category' => 'kuyumcu'],
            ])],
            'checks' => [[
                'category' => 'kuyumcu', 'ready' => false, 'show' => false, 'handoff' => '',
            ]],
        ],
        [
            'id' => 'K2', 'group' => 'original',
            'turns' => ['altın tartacam karat hassas olsun'],
            'analyses' => [analysis([
                'category' => 'kuyumcu',
                'filter_updates' => ['category' => 'kuyumcu'],
            ])],
            'checks' => [[
                'category' => 'kuyumcu', 'ready' => false, 'show' => false, 'handoff' => '',
            ]],
        ],
        [
            'id' => 'P1', 'group' => 'original',
            'turns' => ['kasap için barkod basan terazi arıyorum'],
            'analyses' => [analysis([
                'category' => 'perakende', 'subcategory' => 'barkodlu-teraziler',
                'filter_updates' => ['category' => 'perakende', 'subcategory' => 'barkodlu-teraziler'],
            ])],
            'checks' => [[
                'category' => 'perakende', 'subcategory' => 'barkodlu-teraziler',
                'handoff' => '', 'listing_has' => ['barkodlu-teraziler'], 'total_eq' => 0,
                'message_lacks' => ['barkod gerekli'],
            ]],
        ],
        [
            'id' => 'P2', 'group' => 'original',
            'turns' => ['market tezgahta fiş çıkaran terazi lazım'],
            'analyses' => [analysis([
                'category' => 'perakende',
                'filter_updates' => ['category' => 'perakende'],
            ])],
            'checks' => [[
                'intent' => 'product_discovery', 'category' => 'perakende',
                'ready' => false, 'handoff' => '', 'show' => false,
            ]],
        ],
        [
            'id' => 'E1', 'group' => 'original',
            'turns' => ['depoda 1 tonluk yer kantarı lazım'],
            'analyses' => [analysis([
                'category' => 'endustriyel',
                'ready_for_product_results' => true,
                'filter_updates' => [
                    'category' => 'endustriyel',
                    'capacity' => $cap('min', '1', 't'),
                ],
            ])],
            'checks' => [[
                'category' => 'endustriyel', 'capacity_op' => 'min', 'capacity_g' => 1000000,
                'handoff' => '', 'show' => false, 'no_whatsapp' => true,
                'blocking_or_message' => ['capacity', 'kapasite'],
            ]],
        ],
        [
            'id' => 'E2', 'group' => 'original',
            'turns' => ['fabrika için platform kantar 2 ton'],
            'analyses' => [analysis([
                'category' => 'endustriyel', 'subcategory' => 'platform-kantar',
                'ready_for_product_results' => true,
                'filter_updates' => [
                    'category' => 'endustriyel', 'subcategory' => 'platform-kantar',
                    'capacity' => $cap('min', '2', 't'),
                ],
            ])],
            'checks' => [[
                'capacity_op' => 'min', 'capacity_g' => 2000000,
                'listing_has' => ['kap_min='], 'listing_lacks' => ['kap_max='],
                'handoff' => '', 'show' => false, 'no_whatsapp' => true,
            ]],
        ],
        [
            'id' => 'L1', 'group' => 'original',
            'turns' => ['tankın altına dört load cell bağlayacağız'],
            'analyses' => [analysis([
                'category' => 'endustriyel', 'subcategory' => 'load-cell',
                'filter_updates' => ['category' => 'endustriyel', 'subcategory' => 'load-cell'],
            ])],
            'checks' => [[
                'category' => 'endustriyel', 'subcategory' => 'load-cell',
                'ready' => false, 'show' => false, 'handoff' => '', 'total_eq' => 0,
                'message_lacks' => ['kaç adet', 'kac adet'],
            ]],
        ],
        [
            'id' => 'L2', 'group' => 'original',
            'turns' => ['500 kg yük hücresi lazım'],
            'analyses' => [analysis([
                'category' => 'endustriyel', 'subcategory' => 'load-cell',
                'filter_updates' => [
                    'category' => 'endustriyel', 'subcategory' => 'load-cell',
                    'capacity' => $cap('min', '500', 'kg'),
                ],
            ])],
            'checks' => [[
                'category' => 'endustriyel', 'subcategory' => 'load-cell',
                'handoff' => '', 'no_whatsapp' => true, 'total_eq' => 0, 'diag_reason' => 'catalog_empty',
            ]],
        ],
        [
            'id' => 'S1', 'group' => 'original',
            'turns' => ['Ohaus terazim bozuldu'],
            'analyses' => [analysis(['intent' => 'service', 'needs_catalog_search' => false])],
            'checks' => [['intent' => 'service', 'handoff' => 'service_request']],
        ],
        [
            'id' => 'S2', 'group' => 'original',
            'turns' => ['kern terazi kalibrasyona gidecek'],
            'analyses' => [analysis(['intent' => 'service', 'needs_catalog_search' => false])],
            'checks' => [['intent' => 'service', 'handoff' => 'service_request']],
        ],
        [
            'id' => 'H1', 'group' => 'original',
            'turns' => ['Precisa 165 BJ Err 8 ne demek'],
            'analyses' => [analysis(['intent' => 'service', 'needs_catalog_search' => false, 'filter_updates' => ['brands' => ['Precisa']]])],
            'checks' => [['handoff' => 'service_request', 'message_nonempty' => true]],
        ],
        [
            'id' => 'H2', 'group' => 'original',
            'turns' => ['ekranda Err 80 yazıyor'],
            'analyses' => [analysis(['intent' => 'service', 'needs_catalog_search' => false])],
            'checks' => [['handoff' => 'service_request', 'message_nonempty' => true]],
        ],
        [
            'id' => 'H3', 'group' => 'original',
            'turns' => ['terazide err8 çıktı model bilmiyorum'],
            'analyses' => [analysis(['intent' => 'service', 'needs_catalog_search' => false])],
            'checks' => [['handoff' => 'service_request', 'message_nonempty' => true]],
        ],
        [
            'id' => 'V1', 'group' => 'original',
            'turns' => ['terazi lazım'],
            'analyses' => [analysis()],
            'checks' => [['intent_not' => 'off_topic', 'ready' => false, 'handoff' => '', 'show' => false]],
        ],
        [
            'id' => 'V2', 'group' => 'original',
            'turns' => ['laboratuvar terazisi arıyorum'],
            'analyses' => [analysis([
                'category' => 'laboratuvar',
                'filter_updates' => ['category' => 'laboratuvar'],
            ])],
            'checks' => [[
                'category' => 'laboratuvar', 'subcategory' => '',
                'ready' => false, 'show' => false, 'handoff' => '',
            ]],
        ],
        [
            'id' => 'V3', 'group' => 'original',
            'turns' => ['bişi tartcam da ne alcam bilmiom'],
            'analyses' => [analysis()],
            'checks' => [['intent_not' => 'off_topic', 'ready' => false, 'handoff' => '', 'show' => false]],
        ],
        [
            'id' => 'B1', 'group' => 'original',
            'turns' => ['sadece Kern analitik terazi, 200 g 0.0001 g'],
            'analyses' => [analysis([
                'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
                'ready_for_product_results' => true,
                'filter_updates' => [
                    'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
                    'brands' => ['Kern'],
                    'capacity' => $cap('min', '200'), 'precision' => $prec('0.0001'),
                ],
            ])],
            'checks' => [[
                'subcategory' => 'lab-analitik', 'ready' => true, 'show' => true,
                'brands_has' => 'kern', 'show_has' => ['kern'],
            ]],
        ],
        [
            'id' => 'B2', 'group' => 'original',
            'turns' => ['marka onemli degil 220g 0.1mg analitik'],
            'analyses' => [analysis([
                'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
                'ready_for_product_results' => true,
                'filter_updates' => [
                    'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
                    'capacity' => $cap('min', '220'), 'precision' => $prec('0.1', 'mg'),
                ],
            ])],
            'checks' => [[
                'subcategory' => 'lab-analitik', 'brands_empty' => true,
                'total_gt' => 0, 'show' => true, 'handoff' => '', 'precision' => 0.0001,
            ]],
        ],
        [
            'id' => 'T1', 'group' => 'original',
            'turns' => ['Axis APN220 kapasitesi ve hassasiyeti ne'],
            'analyses' => [analysis(['needs_catalog_search' => false])],
            'checks' => [[
                'message_has' => ['APN220', '220'],
                'message_has_any' => ['0.0001', '0,0001'],
            ]],
        ],
        [
            'id' => 'T2', 'group' => 'original',
            'turns' => ['ATS120 kaç derece kurutur'],
            'analyses' => [analysis(['needs_catalog_search' => false])],
            'checks' => [[
                'handoff' => '',
                'message_has' => ['ATS120', '160', 'katalog'],
                'message_lacks' => ['doğrulayamıyorum'],
            ]],
        ],
        [
            'id' => 'M1', 'group' => 'original',
            'turns' => ['exproof atex sertifikalı terazi lazım'],
            'analyses' => [analysis([
                'category' => 'endustriyel',
                'filter_updates' => ['category' => 'endustriyel'],
            ])],
            'checks' => [['handoff' => 'project_support']],
        ],
        [
            'id' => 'M2', 'group' => 'original',
            'turns' => ['bluetooth lu 0.001 g terazi var mı elinizde'],
            'analyses' => [analysis([
                'ready_for_product_results' => true,
                'filter_updates' => ['precision' => $prec('0.001')],
            ])],
            'checks' => [['ready' => false, 'show' => false, 'handoff' => '']],
        ],
        [
            'id' => 'O1', 'group' => 'original',
            'turns' => ['Dolar kaç TL?'],
            'analyses' => [analysis(['intent' => 'off_topic', 'needs_catalog_search' => false])],
            'checks' => [['intent' => 'off_topic', 'ready' => false, 'show' => false]],
        ],
        [
            'id' => 'O2', 'group' => 'original',
            'turns' => ['bugün istanbul hava nasıl'],
            'analyses' => [analysis(['intent' => 'off_topic', 'needs_catalog_search' => false])],
            'checks' => [['intent' => 'off_topic', 'ready' => false, 'show' => false]],
        ],
        [
            'id' => 'U1', 'group' => 'original',
            'turns' => ['bir uzmanla görüşmek istiyorum'],
            'analyses' => [analysis(['intent' => 'service', 'needs_catalog_search' => false])],
            'checks' => [['handoff' => 'user_requested']],
        ],
        [
            'id' => 'U2', 'group' => 'original',
            'turns' => ['beni arayın teraziyi konuşalım'],
            'analyses' => [analysis(['needs_catalog_search' => false])],
            'checks' => [[
                'handoff' => 'user_requested', 'ready' => false, 'show' => false,
                'action_any' => ['whatsapp_write', 'phone'],
            ]],
        ],
        [
            'id' => 'MT1', 'group' => 'original',
            'turns' => ['500 gramlık bir terazi arıyorum', 'aslında 1 kilo olsun'],
            'analyses' => [
                analysis(['filter_updates' => ['capacity' => $cap('min', '500', 'g')]]),
                analysis([
                    'relation' => 'modify_search',
                    'filter_updates' => ['capacity' => $cap('min', '1', 'kg')],
                ]),
            ],
            'checks' => [
                ['capacity_op' => 'min', 'capacity_g' => 500, 'show' => false],
                ['capacity_op' => 'min', 'capacity_g' => 1000, 'show' => false],
            ],
        ],
        [
            'id' => 'MT2', 'group' => 'original',
            'turns' => ['Axis laboratuvar terazisi olsun', 'marka fark etmez'],
            'analyses' => [
                analysis([
                    'category' => 'laboratuvar',
                    'filter_updates' => ['category' => 'laboratuvar', 'brands' => ['Axis']],
                ]),
                analysis([
                    'relation' => 'modify_search',
                    'category' => 'laboratuvar',
                    'remove_constraints' => ['brand'],
                    'filter_updates' => ['category' => 'laboratuvar'],
                ]),
            ],
            'checks' => [
                ['category' => 'laboratuvar', 'brands_has' => 'axis'],
                ['category' => 'laboratuvar', 'brands_empty' => true],
            ],
        ],
        [
            'id' => 'MT3', 'group' => 'original',
            'turns' => ['200 g analitik terazi bakıyorum', 'vazgeçtim, plastik granül nemi ölçeceğim'],
            'analyses' => [
                analysis([
                    'category' => 'laboratuvar',
                    'filter_updates' => [
                        'category' => 'laboratuvar',
                        'capacity' => $cap('min', '200'),
                    ],
                ]),
                analysis([
                    'relation' => 'new_search',
                    'category' => 'laboratuvar',
                    'subcategory' => 'nem-tayin',
                    'filter_updates' => ['category' => 'laboratuvar', 'subcategory' => 'nem-tayin'],
                ]),
            ],
            'checks' => [
                ['category' => 'laboratuvar', 'capacity_g' => 200, 'ready' => false, 'show' => false],
                ['category' => 'laboratuvar', 'subcategory' => 'nem-tayin', 'capacity_empty' => true],
            ],
        ],
    ];
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function grab(array $payload): array
{
    $state = is_array($payload['state'] ?? null) ? $payload['state'] : [];
    $advisor = is_array($state['advisor'] ?? null) ? $state['advisor'] : [];
    $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
    $capacity = is_array($filters['capacity'] ?? null) ? $filters['capacity'] : [];
    $precision = is_array($filters['precision'] ?? null) ? $filters['precision'] : [];
    $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];
    $types = [];
    $show = '';
    foreach ($actions as $action) {
        if (!is_array($action)) {
            continue;
        }
        $types[] = (string) ($action['type'] ?? '');
        if (($action['type'] ?? '') === 'show_products') {
            $show = (string) ($action['url'] ?? '');
        }
    }
    $diag = is_array($advisor['filter_diagnostics'] ?? null) ? $advisor['filter_diagnostics'] : [];
    $value = $capacity['value_g'] ?? null;

    return [
        'message'  => (string) ($payload['message'] ?? ''),
        'intent'   => (string) ($advisor['intent'] ?? ''),
        'category' => (string) ($filters['category'] ?? $advisor['category'] ?? ''),
        'subcategory' => (string) ($filters['subcategory'] ?? $advisor['subcategory'] ?? ''),
        'ready'    => ($advisor['ready_for_products'] ?? false) === true,
        'total'    => (int) ($state['search_total'] ?? 0),
        'handoff'  => (string) ($advisor['handoff_reason'] ?? ''),
        'show'     => $show,
        'types'    => $types,
        'op'       => (string) ($capacity['operator'] ?? ''),
        'vg'       => is_numeric($value) ? (float) $value : null,
        'brands'   => is_array($filters['brands'] ?? null) ? $filters['brands'] : [],
        'prec'     => is_numeric($precision['value'] ?? null) ? (float) $precision['value'] : null,
        'blocking' => (string) ($diag['blocking_filter'] ?? ''),
        'reason'   => (string) ($diag['reason'] ?? ''),
        'listing'  => (string) ($state['products_listing_url'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $check
 * @param array<string, mixed> $got
 * @return list<string>
 */
function mismatches(array $check, array $got): array
{
    $reasons = [];
    $need = static function (string $label, bool $ok) use (&$reasons): void {
        if (!$ok) {
            $reasons[] = $label;
        }
    };
    if (array_key_exists('intent', $check)) {
        $need('intent=' . $got['intent'], $got['intent'] === $check['intent']);
    }
    if (array_key_exists('intent_not', $check)) {
        $need('intent=' . $got['intent'], $got['intent'] !== $check['intent_not']);
    }
    if (array_key_exists('category', $check)) {
        $need('category=' . $got['category'], $got['category'] === $check['category']);
    }
    if (array_key_exists('subcategory', $check)) {
        $need('subcategory=' . $got['subcategory'], $got['subcategory'] === $check['subcategory']);
    }
    if (array_key_exists('ready', $check)) {
        $need('ready=' . ($got['ready'] ? 'yes' : 'no'), $got['ready'] === $check['ready']);
    }
    if (array_key_exists('show', $check)) {
        $has = $got['show'] !== '';
        $need('show=' . ($has ? 'yes' : 'no'), $has === $check['show']);
    }
    if (array_key_exists('handoff', $check)) {
        $need('handoff=' . $got['handoff'], $got['handoff'] === $check['handoff']);
    }
    foreach ($check['show_has'] ?? [] as $needle) {
        $need('show missing ' . $needle, str_contains(strtolower($got['show']), strtolower((string) $needle)));
    }
    foreach ($check['listing_has'] ?? [] as $needle) {
        $need('listing missing ' . $needle, str_contains($got['listing'], (string) $needle));
    }
    foreach ($check['listing_lacks'] ?? [] as $needle) {
        $need('listing has ' . $needle, !str_contains($got['listing'], (string) $needle));
    }
    if (array_key_exists('capacity_op', $check)) {
        $need('op=' . $got['op'], $got['op'] === $check['capacity_op']);
    }
    if (array_key_exists('capacity_g', $check)) {
        $need('capacity=' . var_export($got['vg'], true), $got['vg'] !== null && abs($got['vg'] - (float) $check['capacity_g']) < 0.51);
    }
    if (($check['capacity_empty'] ?? false) === true) {
        $need('capacity still set', $got['vg'] === null);
    }
    if (array_key_exists('precision', $check)) {
        $need('precision=' . var_export($got['prec'], true), $got['prec'] !== null && abs($got['prec'] - (float) $check['precision']) < 1e-6);
    }
    if (array_key_exists('total_lt', $check)) {
        $need('total=' . $got['total'], $got['total'] < (int) $check['total_lt']);
    }
    if (array_key_exists('total_eq', $check)) {
        $need('total=' . $got['total'], $got['total'] === (int) $check['total_eq']);
    }
    if (array_key_exists('total_gt', $check)) {
        $need('total=' . $got['total'], $got['total'] > (int) $check['total_gt']);
    }
    if (array_key_exists('diag_reason', $check)) {
        $need('reason=' . $got['reason'], $got['reason'] === $check['diag_reason']);
    }
    if (($check['no_whatsapp'] ?? false) === true) {
        $need('whatsapp present', !in_array('whatsapp_write', $got['types'], true));
    }
    if (array_key_exists('brands_has', $check)) {
        $blob = strtolower(implode(' ', array_map('strval', $got['brands'])));
        $need('brands=' . $blob, str_contains($blob, strtolower((string) $check['brands_has'])));
    }
    if (($check['brands_empty'] ?? false) === true) {
        $need('brands=' . json_encode($got['brands']), $got['brands'] === []);
    }
    if (array_key_exists('blocking_or_message', $check)) {
        $pair = $check['blocking_or_message'];
        $ok = $got['blocking'] === $pair[0] || str_contains(mb_strtolower($got['message']), mb_strtolower((string) $pair[1]));
        $need('blocking=' . $got['blocking'], $ok);
    }
    $lower = mb_strtolower($got['message']);
    foreach ($check['message_has'] ?? [] as $needle) {
        $need('message missing ' . $needle, str_contains($got['message'], (string) $needle));
    }
    if (isset($check['message_has_any']) && is_array($check['message_has_any'])) {
        $ok = false;
        foreach ($check['message_has_any'] as $needle) {
            if (str_contains($got['message'], (string) $needle)) {
                $ok = true;
                break;
            }
        }
        $need('message missing measure', $ok);
    }
    foreach ($check['message_lacks'] ?? [] as $needle) {
        $need('message has ' . $needle, !str_contains($lower, mb_strtolower((string) $needle)));
    }
    if (isset($check['message_lacks_regex'])) {
        $need('invented price', preg_match((string) $check['message_lacks_regex'], $lower) !== 1);
    }
    if (($check['message_nonempty'] ?? false) === true) {
        $need('empty message', trim($got['message']) !== '');
    }
    if (isset($check['action_any']) && is_array($check['action_any'])) {
        $ok = false;
        foreach ($check['action_any'] as $type) {
            if (in_array($type, $got['types'], true)) {
                $ok = true;
                break;
            }
        }
        $need('actions=' . implode(',', $got['types']), $ok);
    }

    return $reasons;
}

/** @var list<array{id:string,group:string,ok:bool,reason:string}> $results */
$results = [];

function record(string $id, string $group, bool $ok, string $reason): void
{
    global $results;
    $results[] = ['id' => $id, 'group' => $group, 'ok' => $ok, 'reason' => $reason];
    echo ($ok ? '  OK  ' : ' FAIL ') . $id . ($reason !== '' ? ' — ' . $reason : '') . "\n";
}

echo "Original 36\n";
foreach (originalScenarios() as $scenario) {
    $payloads = AssistantV2AdvisorTestSupport::runConversation($scenario['turns'], $scenario['analyses']);
    $reasons = [];
    foreach ($scenario['checks'] as $index => $check) {
        $got = grab($payloads[$index] ?? []);
        foreach (mismatches($check, $got) as $reason) {
            $reasons[] = 't' . ($index + 1) . ' ' . $reason;
        }
    }
    record($scenario['id'], 'original', $reasons === [], implode('; ', $reasons));
}

echo "\nNew regressions\n";

$sampleQuestion = 'Hangi malzemeden numune tartılacak?';
$readabilityQuestion = 'Hangi okunabilirlik/hassasiyet gerekiyor? Gram olarak biliyorsanız yazabilirsiniz. Bilmiyorsanız ne tartacağınızı ve ne kadar hassas ölçmeniz gerektiğini söyleyin.';

$nem = AssistantV2AdvisorTestSupport::runConversation(
    ['nem tayin cihazı arıyorum', 'süt tozu'],
    [
        analysis(['category' => 'laboratuvar', 'subcategory' => 'nem-tayin', 'filter_updates' => ['category' => 'laboratuvar', 'subcategory' => 'nem-tayin']]),
        analysis(['relation' => 'continue_search']),
    ]
);
$nem1 = $nem[1] ?? [];
$r1 = [];
if (str_contains((string) ($nem1['message'] ?? ''), $sampleQuestion)) {
    $r1[] = 'sample question repeated';
}
if ((string) ($nem1['state']['advisor']['facts']['sample_material'] ?? '') !== 'süt tozu') {
    $r1[] = 'sample_material not bound';
}
record('R-NEW-1', 'new', $r1 === [], implode('; ', $r1));

$repeat = AssistantV2AdvisorTestSupport::runConversation(
    ['nem tayin cihazı arıyorum', 'süt tozu dedim ya'],
    [
        analysis(['category' => 'laboratuvar', 'subcategory' => 'nem-tayin', 'filter_updates' => ['category' => 'laboratuvar', 'subcategory' => 'nem-tayin']]),
        analysis(['relation' => 'continue_search']),
    ]
);
$repeat1 = $repeat[1] ?? [];
$r2 = [];
if ((string) ($repeat1['state']['advisor']['facts']['sample_material'] ?? '') !== 'süt tozu') {
    $r2[] = 'pending sample_material not bound';
}
if (str_contains((string) ($repeat1['message'] ?? ''), $sampleQuestion)) {
    $r2[] = 'sample question repeated';
}
record('R-NEW-2', 'new', $r2 === [], implode('; ', $r2));

$hassas = AssistantV2AdvisorTestSupport::runConversation(
    ['hassas terazi arıyorum'],
    [analysis(['ready_for_product_results' => true])]
);
$hassas0 = $hassas[0] ?? [];
$r3 = [];
if (($hassas0['state']['advisor']['category'] ?? null) === 'laboratuvar' || ($hassas0['state']['filters']['category'] ?? null) === 'laboratuvar') {
    $r3[] = 'locked to laboratuvar';
}
if (!str_contains((string) ($hassas0['message'] ?? ''), AssistantV2QuestionPlan::USAGE_QUESTION)) {
    $r3[] = 'usage question missing';
}
if (AssistantV2AdvisorTestSupport::hasActionType($hassas0, 'show_products')) {
    $r3[] = 'SHOW_PRODUCTS';
}
record('R-NEW-3', 'new', $r3 === [], implode('; ', $r3));

$lab = AssistantV2AdvisorTestSupport::runConversation(
    ['hassas terazi arıyorum', 'laboratuvar', '600'],
    [
        analysis(),
        analysis(['relation' => 'continue_search']),
        analysis([
            'relation' => 'continue_search',
            'ready_for_product_results' => true,
            'filter_updates' => ['capacity' => ['operator' => 'min', 'value_g' => 600]],
        ]),
    ]
);
$lab2 = $lab[2] ?? [];
$r4 = [];
if (abs((float) ($lab2['state']['filters']['capacity']['value_g'] ?? 0) - 600) >= 0.001) {
    $r4[] = 'capacity not 600 g';
}
if (($lab2['state']['advisor']['ready_for_products'] ?? true) === true || AssistantV2AdvisorTestSupport::hasActionType($lab2, 'show_products')) {
    $r4[] = 'SHOW_PRODUCTS without readability';
}
record('R-NEW-4', 'new', $r4 === [], implode('; ', $r4));

$question = (string) ($lab2['message'] ?? '');
$r5 = [];
if (!str_contains($question, 'okunabilirlik') || !str_contains($question, $readabilityQuestion)) {
    $r5[] = 'open readability question missing';
}
if (str_contains($question, '0,01 g mı') || str_contains($question, '0,001 g mı')) {
    $r5[] = 'hardcoded 0.01/0.001 pair';
}
record('R-NEW-5', 'new', $r5 === [], implode('; ', $r5));

$jew = AssistantV2AdvisorTestSupport::runConversation(
    ['kuyumcu terazisi arıyorum', '600 g', '0.001 g hassasiyet'],
    [
        analysis(['category' => 'kuyumcu', 'filter_updates' => ['category' => 'kuyumcu']]),
        analysis(['relation' => 'continue_search']),
        analysis(['relation' => 'continue_search']),
    ]
);
$jewUrl = '';
foreach (is_array($jew[2]['actions'] ?? null) ? $jew[2]['actions'] : [] as $action) {
    if (is_array($action) && ($action['type'] ?? '') === 'show_products') {
        $jewUrl = (string) ($action['url'] ?? '');
    }
}
$r6 = [];
if (!str_contains($jewUrl, 'kap_min=600') || !str_contains($jewUrl, 'has_max=0.001')) {
    $r6[] = 'url=' . $jewUrl;
}
record('R-NEW-6', 'new', $r6 === [], implode('; ', $r6));

$jewFilters = AssistantV2Filters::normalizeState(AssistantV2Filters::criteria([
    'category' => 'kuyumcu',
    'capacity' => ['operator' => 'min', 'value' => '600', 'unit' => 'g'],
    'precision' => ['value' => '0.001', 'unit' => 'g'],
]));
$jewFound = AssistantV2Filters::search($jewFilters, 0, 50);
$jewListing = 0;
$jewCatalog = 0;
foreach (AssistantV2Catalog::products() as $product) {
    if (!AssistantV2Catalog::inGroup($product, 'kuyumcu', false)) {
        continue;
    }
    ++$jewCatalog;
    if (AssistantV2Filters::matchesListingMeasures($product, ['kap_min' => '600', 'has_max' => '0.001'])) {
        ++$jewListing;
    }
}
$built = AssistantV2AdvisorSearch::listingUrl($jewFilters);
$r7 = [];
if ($jewCatalog < 28) {
    $r7[] = 'catalog=' . $jewCatalog;
}
if ((int) $jewFound['total'] <= 0 || (int) $jewFound['total'] >= $jewCatalog || (int) $jewFound['total'] !== $jewListing) {
    $r7[] = 'advisor=' . (int) $jewFound['total'] . ' listing=' . $jewListing . ' catalog=' . $jewCatalog;
}
if (!str_contains($built, 'kap_min=600') || !str_contains($built, 'has_max=0.001')) {
    $r7[] = 'built url missing measures';
}
record('R-NEW-7', 'new', $r7 === [], implode('; ', $r7));

$stale = AssistantV2AdvisorTestSupport::runConversation(
    ['200 g 0.0001 g analitik terazi lazım', 'bluetooth da olsun'],
    [
        analysis([
            'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
            'ready_for_product_results' => true,
            'filter_updates' => [
                'category' => 'laboratuvar', 'subcategory' => 'lab-analitik',
                'capacity' => ['operator' => 'min', 'value' => '200', 'unit' => 'g'],
                'precision' => ['value' => '0.0001', 'unit' => 'g'],
            ],
        ]),
        analysis([
            'relation' => 'continue_search',
            'category' => 'laboratuvar',
            'ready_for_product_results' => true,
        ]),
    ]
);
$r8 = [];
if (!AssistantV2AdvisorTestSupport::hasActionType($stale[0] ?? [], 'show_products')) {
    $r8[] = 'first turn missing SHOW_PRODUCTS';
}
if (AssistantV2AdvisorTestSupport::hasActionType($stale[1] ?? [], 'show_products')) {
    $r8[] = 'stale SHOW_PRODUCTS carried';
}
record('R-NEW-8', 'new', $r8 === [], implode('; ', $r8));

$changed = [
    'id' => 120, 'brand' => 'Axis', 'model_no' => 'ATS120', 'name' => 'Axis ATS120',
    'specs' => [['label' => 'Maksimum Kurutma Sıcaklığı', 'value' => '180°C']],
];
$drying = AssistantV2AdvisorEvidence::namedDryingReply('ATS120 kaç derece kurutur', [$changed]);
$r9 = [];
if (!str_contains($drying, '180') || str_contains($drying, '160')) {
    $r9[] = 'resolver=' . $drying;
}
record('R-NEW-9', 'new', $r9 === [], implode('; ', $r9));

$staleRag = AssistantV2AdvisorEvidence::guard(
    'ATS120 160°C kurutur.',
    'ATS120 sıcaklığı nedir?',
    [$changed],
    'ATS120 maksimum kurutma sıcaklığı 160°C.'
);
$liveSpec = AssistantV2AdvisorEvidence::guard(
    'ATS120 180°C kurutur.',
    'ATS120 sıcaklığı nedir?',
    [$changed],
    'ATS120 maksimum kurutma sıcaklığı 160°C.'
);
$r10 = [];
if (str_contains($staleRag, '160')) {
    $r10[] = 'stale RAG kept';
}
if (!str_contains($liveSpec, '180')) {
    $r10[] = 'structured value lost';
}
record('R-NEW-10', 'new', $r10 === [], implode('; ', $r10));

$original = array_values(array_filter($results, static fn (array $row): bool => $row['group'] === 'original'));
$fresh = array_values(array_filter($results, static fn (array $row): bool => $row['group'] === 'new'));
$failed = array_values(array_filter($results, static fn (array $row): bool => $row['ok'] !== true));
$total = count($results);
$passed = $total - count($failed);
$rate = $total === 0 ? 0 : round(100 * $passed / $total, 1);

echo "\nOriginal scenarios: " . count($original) . ' PASS ' . count(array_filter($original, static fn (array $row): bool => $row['ok'])) . "\n";
echo 'New regression scenarios: ' . count($fresh) . ' PASS ' . count(array_filter($fresh, static fn (array $row): bool => $row['ok'])) . "\n";
echo "Total: {$total}\n";
echo "Passed: {$passed}\n";
echo 'Failed: ' . count($failed) . "\n";
echo "Pass rate: {$rate}%\n";
if ($failed !== []) {
    echo 'Failed IDs: ' . implode(', ', array_map(static fn (array $row): string => $row['id'], $failed)) . "\n";
    foreach ($failed as $row) {
        echo $row['id'] . ': ' . $row['reason'] . "\n";
    }
}

exit($failed === [] ? 0 : 1);
