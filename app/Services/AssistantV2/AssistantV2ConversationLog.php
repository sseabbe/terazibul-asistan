<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

use Core\Database;
use Core\Schema\Schema;

/**
 * Tur sonu konuşma olayı. Yazma hatası sohbet yanıtını bozmaz.
 */
final class AssistantV2ConversationLog
{
    public const DEFAULT_VERSION = 'v1.1.0';

    public const DEFAULT_RETENTION_DAYS = 90;

    /** @var list<array<string, mixed>>|null */
    public static ?array $testCapture = null;

    public static bool $testFailWrite = false;

    private static bool $tableReady = false;

    private static bool $purged = false;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $prior
     * @param array<string, mixed> $meta
     */
    public static function record(string $userMessage, array $payload, array $prior, float $startedAt, array $meta = []): void
    {
        try {
            $event = self::event($userMessage, $payload, $prior, $startedAt, $meta);
            if (self::$testFailWrite) {
                throw new \RuntimeException('conversation log write failed');
            }
            if (is_array(self::$testCapture)) {
                self::$testCapture[] = $event;

                return;
            }
            if (self::testsAreDoubled()) {
                return;
            }
            // Panelden giriş yapmış yönetici denemeleri müşteri sohbetlerine karışmasın
            if (\Core\Session::has('auth_user_id')) {
                return;
            }
            self::persist($event);
        } catch (\Throwable) {
        }
    }

    public static function version(): string
    {
        $version = trim((string) \Core\Env::get('ASSISTANT_VERSION', self::DEFAULT_VERSION));
        if ($version === '') {
            return self::DEFAULT_VERSION;
        }

        return mb_substr($version, 0, 32);
    }

    public static function retentionDays(): int
    {
        $days = (int) \Core\Env::get('ASSISTANT_CI_RETENTION_DAYS', self::DEFAULT_RETENTION_DAYS);
        if ($days < 1) {
            return self::DEFAULT_RETENTION_DAYS;
        }

        return min($days, 3650);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $prior
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function event(string $userMessage, array $payload, array $prior, float $startedAt, array $meta): array
    {
        $state = is_array($payload['state'] ?? null) ? $payload['state'] : [];
        $advisor = is_array($state['advisor'] ?? null) ? $state['advisor'] : [];
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        $capacity = is_array($filters['capacity'] ?? null) ? $filters['capacity'] : [];
        $precision = is_array($filters['precision'] ?? null) ? $filters['precision'] : [];
        $brands = is_array($filters['brands'] ?? null) ? $filters['brands'] : [];
        $assistant = trim((string) ($payload['message'] ?? ''));
        $showProducts = ($payload['show_products_button'] ?? false) === true;
        $searchTotal = (int) ($state['search_total'] ?? 0);
        $searched = ($meta['searched'] ?? false) === true;
        $zeroResult = $searched && $searchTotal === 0;
        $guardTriggered = ($meta['evidence_guard_triggered'] ?? false) === true;
        $unresolved = trim((string) ($advisor['readiness_reason'] ?? '')) === 'unresolved_measure'
            || ($precision['unresolved'] ?? false) === true
            || ($capacity['unresolved'] ?? false) === true;
        $answered = [];
        foreach (is_array($advisor['answered_fields'] ?? null) ? $advisor['answered_fields'] : [] as $field) {
            if (is_string($field) && $field !== '') {
                $answered[] = $field;
            }
        }
        $current = [
            'user_message' => $userMessage,
            'assistant_message' => $assistant,
            'category' => (string) ($filters['category'] ?? ''),
            'subcategory' => (string) ($filters['subcategory'] ?? ''),
            'pending_question_field' => (string) ($advisor['pending_question_field'] ?? ''),
            'handoff_reason' => (string) ($advisor['handoff_reason'] ?? ''),
            'capacity_g' => is_numeric($capacity['value_g'] ?? null) ? (string) $capacity['value_g'] : '',
            'brands' => implode(',', array_map('strval', $brands)),
            'zero_result' => $zeroResult,
            'evidence_guard_triggered' => $guardTriggered,
            'unresolved_constraint' => $unresolved,
            'ui_action' => (string) ($meta['ui_action'] ?? ''),
            'show_products' => $showProducts,
            'search_total' => $searchTotal,
            'ready_for_products' => ($advisor['ready_for_products'] ?? false) === true,
        ];
        $signals = AssistantV2ConversationSignals::build($current, $prior);
        $latency = max(0, (int) round((microtime(true) - $startedAt) * 1000));

        return [
            'conversation_id' => (string) ($state['conversation_id'] ?? ''),
            'turn_id' => max(0, (int) ($advisor['turns'] ?? 0)),
            'user_message_redacted' => AssistantV2ConversationRedactor::text($userMessage),
            'assistant_message_redacted' => AssistantV2ConversationRedactor::text($assistant),
            'intent' => mb_substr(trim((string) ($advisor['intent'] ?? '')), 0, 40),
            'category' => mb_substr(trim((string) ($filters['category'] ?? '')), 0, 80),
            'subcategory' => mb_substr(trim((string) ($filters['subcategory'] ?? '')), 0, 80),
            'ready_for_products' => $current['ready_for_products'] ? 1 : 0,
            'search_total' => $searchTotal,
            'show_products' => $showProducts ? 1 : 0,
            'handoff_reason' => mb_substr(trim((string) ($advisor['handoff_reason'] ?? '')), 0, 40),
            'evidence_guard_triggered' => $guardTriggered ? 1 : 0,
            'zero_result' => $zeroResult ? 1 : 0,
            'classification_source' => mb_substr(trim((string) ($meta['classification_source'] ?? '')), 0, 40),
            'pending_question_field' => mb_substr(trim((string) ($advisor['pending_question_field'] ?? '')), 0, 40),
            'answered_fields' => json_encode($answered, JSON_UNESCAPED_UNICODE) ?: '[]',
            'blocking_filter' => mb_substr(trim((string) ($meta['blocking_filter'] ?? '')), 0, 40),
            'error_code' => mb_substr(trim((string) ($meta['error_code'] ?? '')), 0, 40),
            'latency_ms' => $latency,
            'assistant_version' => self::version(),
            'signals' => json_encode($signals, JSON_UNESCAPED_UNICODE) ?: '{}',
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function persist(array $event): void
    {
        self::ensureTable();
        Database::table('assistant_conversation_events')->insert($event);
        self::purgeExpired();
    }

    private static function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        if (!Schema::hasTable('assistant_conversation_events')) {
            Database::statement(self::createSql());
        }
        self::$tableReady = true;
    }

    private static function purgeExpired(): void
    {
        if (self::$purged) {
            return;
        }
        self::$purged = true;
        $cutoff = date('Y-m-d H:i:s', time() - (self::retentionDays() * 86400));
        Database::table('assistant_conversation_events')->where('created_at', '<', $cutoff)->delete();
    }

    private static function testsAreDoubled(): bool
    {
        return AssistantV2AdvisorAnalyzer::$testAnalyzeOverride !== null
            || AssistantV2Llm::$testCompleteOverride !== null;
    }

    private static function createSql(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS assistant_conversation_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id VARCHAR(32) NOT NULL DEFAULT '',
    turn_id INT UNSIGNED NOT NULL DEFAULT 0,
    user_message_redacted TEXT NOT NULL,
    assistant_message_redacted TEXT NOT NULL,
    intent VARCHAR(40) NOT NULL DEFAULT '',
    category VARCHAR(80) NOT NULL DEFAULT '',
    subcategory VARCHAR(80) NOT NULL DEFAULT '',
    ready_for_products TINYINT(1) NOT NULL DEFAULT 0,
    search_total INT NOT NULL DEFAULT 0,
    show_products TINYINT(1) NOT NULL DEFAULT 0,
    handoff_reason VARCHAR(40) NOT NULL DEFAULT '',
    evidence_guard_triggered TINYINT(1) NOT NULL DEFAULT 0,
    zero_result TINYINT(1) NOT NULL DEFAULT 0,
    classification_source VARCHAR(40) NOT NULL DEFAULT '',
    pending_question_field VARCHAR(40) NOT NULL DEFAULT '',
    answered_fields TEXT NOT NULL,
    blocking_filter VARCHAR(40) NOT NULL DEFAULT '',
    error_code VARCHAR(40) NOT NULL DEFAULT '',
    latency_ms INT UNSIGNED NULL,
    assistant_version VARCHAR(32) NOT NULL DEFAULT '',
    signals TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_assistant_ci_conversation (conversation_id),
    INDEX idx_assistant_ci_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    }
}
