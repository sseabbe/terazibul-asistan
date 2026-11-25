<?php

declare(strict_types=1);

namespace App\Services\ProductAssistant;

use Core\Database;
use Core\Schema\Blueprint;
use Core\Schema\Schema;

/**
 * Asistan içi uzman aktarımı. Bu sohbetten WhatsApp için telefon tutulur.
 */
final class AssistantSupport
{
    public const COOKIE = 'tb_assistant_visitor';

    public static function ensure(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }
        if (!Schema::hasTable('assistant_support_threads')) {
            Schema::create('assistant_support_threads', function (Blueprint $table) {
                $table->id();
                $table->string('visitor_token', 64);
                $table->string('public_token', 64)->unique();
                $table->string('status', 20)->default('waiting');
                $table->string('question', 500)->default('');
                $table->text('context');
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();
                $table->index('visitor_token');
                $table->index('status');
                $table->index('last_message_at');
            });
        }
        if (!Schema::hasTable('assistant_support_messages')) {
            Schema::create('assistant_support_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('thread_id');
                $table->string('sender_type', 20);
                $table->text('body');
                $table->timestamp('created_at')->nullable();
                $table->index('thread_id');
            });
        }
        $ready = true;
    }

    public static function visitorToken(): string
    {
        static $issued = null;
        if (is_string($issued)) {
            return $issued;
        }
        $current = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($current) && preg_match('/^[a-f0-9]{64}$/', $current) === 1) {
            $issued = $current;

            return $issued;
        }
        $issued = bin2hex(random_bytes(32));

        return $issued;
    }

    public static function cookieHeader(string $token): string
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        return self::COOKIE . '=' . $token
            . '; Path=/; Max-Age=15552000; HttpOnly; SameSite=Lax'
            . ($secure ? '; Secure' : '');
    }

    /**
     * @param list<int> $productIds
     * @param list<array{role:string,text:string}> $history
     * @return array{ok:bool,token?:string,status?:string,message?:string}
     */
    public static function start(string $visitor, string $question, array $productIds, array $history, string $note, string $phone = ''): array
    {
        self::ensure();
        $question = self::plain($question, 500);
        if ($question === '') {
            return ['ok' => false, 'message' => 'İletilecek bir soru bulamadım. Soruyu kısaca yazar mısınız?'];
        }
        $digits = self::phoneDigits($phone);
        if (preg_match('/^05\d{9}$/', $digits) !== 1) {
            return ['ok' => false, 'message' => 'Telefon numaranızı 05XX XXX XX XX formatında eksiksiz giriniz.'];
        }
        $phoneLabel = self::phoneLabel($digits);

        $products = self::products($productIds);
        $history = self::history($history);
        $note = self::plain($note, 400);
        $now = date('Y-m-d H:i:s');
        $context = json_encode([
            'question' => $question,
            'phone'    => $phoneLabel,
            'products' => $products,
            'history'  => $history,
        ], JSON_UNESCAPED_UNICODE);
        if (!is_string($context)) {
            $context = '{}';
        }

        $public = bin2hex(random_bytes(32));
        $id = Database::table('assistant_support_threads')->insert([
            'visitor_token'   => $visitor,
            'public_token'    => $public,
            'status'          => 'waiting',
            'question'        => $question,
            'context'         => $context,
            'last_message_at' => $now,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        self::addMessage($id, 'user', $question, $now);
        self::addMessage($id, 'user', 'Telefon: ' . $phoneLabel, $now);
        if ($note !== '' && $note !== $question) {
            self::addMessage($id, 'assistant', $note, $now);
        }

        return [
            'ok'      => true,
            'token'   => $public,
            'status'  => 'waiting',
            'message' => 'Numaranızı aldık. Ekibimiz WhatsApp üzerinden size yazacak. Bu pencereden de yazışmaya devam edebilirsiniz.',
        ];
    }

    /**
     * @return array{ok:bool,message?:string,status?:string}
     */
    public static function addUserMessage(string $visitor, string $publicToken, string $text): array
    {
        self::ensure();
        $thread = self::owned($visitor, $publicToken);
        if ($thread === null || ($thread['status'] ?? '') === 'closed') {
            return ['ok' => false, 'message' => 'Bu konuşma bulunamadı.'];
        }
        $text = self::plain($text, 2000);
        if ($text === '') {
            return ['ok' => false, 'message' => 'Mesaj boş olamaz.'];
        }
        $now = date('Y-m-d H:i:s');
        self::addMessage((int) $thread['id'], 'user', $text, $now);
        $status = ($thread['status'] ?? '') === 'answered' ? 'waiting' : (string) $thread['status'];
        Database::table('assistant_support_threads')->where('id', (int) $thread['id'])->update([
            'status'          => $status,
            'last_message_at' => $now,
            'updated_at'      => $now,
        ]);

        return ['ok' => true, 'status' => $status];
    }

    /**
     * @return array{ok:bool,messages:list<array{id:int,sender:string,text:string}>,status:string}
     */
    public static function poll(string $visitor, string $publicToken, int $afterId): array
    {
        self::ensure();
        $thread = self::owned($visitor, $publicToken);
        if ($thread === null) {
            return ['ok' => false, 'messages' => [], 'status' => ''];
        }
        $rows = Database::select(
            'SELECT id, sender_type, body FROM assistant_support_messages WHERE thread_id = ? AND id > ? AND sender_type = ? ORDER BY id ASC LIMIT 20',
            [(int) $thread['id'], max(0, $afterId), 'agent']
        );
        $messages = [];
        foreach ($rows as $row) {
            $messages[] = [
                'id'     => (int) $row['id'],
                'sender' => 'agent',
                'text'   => (string) $row['body'],
            ];
        }

        return [
            'ok'       => true,
            'messages' => $messages,
            'status'   => (string) ($thread['status'] ?? ''),
        ];
    }

    /**
     * @param list<int> $productIds
     */
    public static function whatsappUrl(string $question, array $productIds): ?string
    {
        $number = site_whatsapp_number();
        if ($number === '') {
            return null;
        }
        $names = [];
        foreach (self::products($productIds) as $product) {
            $names[] = $product['name'];
        }
        $text = self::handoffText($question, $names);

        return 'https://wa.me/' . $number . '?text=' . rawurlencode($text);
    }

    public static function waitingCount(): int
    {
        try {
            if (!Schema::hasTable('assistant_support_threads')) {
                return 0;
            }
            $rows = Database::select(
                "SELECT COUNT(*) AS c FROM assistant_support_threads WHERE status = 'waiting'"
            );

            return (int) ($rows[0]['c'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function threads(?string $status): array
    {
        self::ensure();
        $sql = 'SELECT id, public_token, status, question, context, created_at, last_message_at FROM assistant_support_threads';
        $bind = [];
        if (in_array($status, ['waiting', 'answered', 'closed'], true)) {
            $sql .= ' WHERE status = ?';
            $bind[] = $status;
        }
        $sql .= " ORDER BY FIELD(status, 'waiting', 'answered', 'closed'), last_message_at DESC LIMIT 100";

        return Database::select($sql, $bind);
    }

    /**
     * @return array{thread:array<string,mixed>,messages:list<array<string,mixed>>,context:array<string,mixed>}|null
     */
    public static function thread(int $id): ?array
    {
        self::ensure();
        $thread = Database::table('assistant_support_threads')->where('id', $id)->first();
        if ($thread === null) {
            return null;
        }
        $messages = Database::select(
            'SELECT id, sender_type, body, created_at FROM assistant_support_messages WHERE thread_id = ? ORDER BY id ASC',
            [$id]
        );
        $context = json_decode((string) ($thread['context'] ?? ''), true);

        return [
            'thread'   => $thread,
            'messages' => $messages,
            'context'  => is_array($context) ? $context : [],
        ];
    }

    public static function reply(int $id, string $text): bool
    {
        self::ensure();
        $thread = Database::table('assistant_support_threads')->where('id', $id)->first();
        if ($thread === null) {
            return false;
        }
        $text = self::plain($text, 4000);
        if ($text === '') {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        self::addMessage($id, 'agent', $text, $now);
        Database::table('assistant_support_threads')->where('id', $id)->update([
            'status'          => 'answered',
            'last_message_at' => $now,
            'updated_at'      => $now,
        ]);

        return true;
    }

    public static function close(int $id): void
    {
        self::ensure();
        $now = date('Y-m-d H:i:s');
        Database::table('assistant_support_threads')->where('id', $id)->update([
            'status'     => 'closed',
            'updated_at' => $now,
        ]);
    }

    public static function ago(string $at): string
    {
        $ts = strtotime($at);
        if ($ts === false) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'az önce';
        }
        if ($diff < 3600) {
            return (int) floor($diff / 60) . ' dk önce';
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600) . ' sa önce';
        }

        return date('d.m.Y H:i', $ts);
    }

    /**
     * @param list<string> $names
     */
    public static function handoffText(string $question, array $names): string
    {
        $names = array_values(array_filter(array_map(static fn (string $name): string => trim($name), $names)));
        $folded = ProductAssistantCatalog::fold($question);
        if (count($names) >= 2 && preg_match('/fark/u', $folded) === 1) {
            return 'Merhaba, ' . $names[0] . ' ile ' . $names[1] . ' arasındaki fark hakkında bilgi almak istiyorum.';
        }
        if (count($names) === 1 && preg_match('/minimum|min tart/u', $folded) === 1) {
            return 'Merhaba, ' . $names[0] . ' modelinin minimum tartım değerini öğrenmek istiyorum.';
        }
        if ($names !== []) {
            return 'Merhaba, ' . implode(' ve ', array_slice($names, 0, 2)) . ' hakkında bilgi almak istiyorum.';
        }
        $question = self::plain($question, 300);
        if ($question === '') {
            return 'Merhaba, Terazibul üzerinden bilgi almak istiyorum.';
        }

        return 'Merhaba, ' . $question;
    }

    /**
     * @param list<int> $ids
     * @return list<array{id:int,name:string}>
     */
    private static function products(array $ids): array
    {
        $wanted = [];
        foreach (array_slice($ids, 0, 4) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $wanted[$id] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }
        $found = [];
        foreach (ProductAssistantCatalog::products() as $product) {
            $id = (int) ($product['id'] ?? 0);
            if (!isset($wanted[$id])) {
                continue;
            }
            $name = trim((string) ($product['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $found[] = ['id' => $id, 'name' => $name];
        }

        return $found;
    }

    /**
     * @param list<array{role:string,text:string}> $history
     * @return list<array{role:string,text:string}>
     */
    private static function history(array $history): array
    {
        $out = [];
        foreach (array_slice($history, -4) as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $text = self::plain((string) ($turn['text'] ?? ''), 400);
            if ($text === '') {
                continue;
            }
            $out[] = [
                'role' => ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                'text' => $text,
            ];
        }

        return $out;
    }

    private static function phoneDigits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '90') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '5')) {
            $digits = '0' . $digits;
        }
        if (!str_starts_with($digits, '05')) {
            $digits = '05' . ltrim($digits, '0');
        }

        return substr($digits, 0, 11);
    }

    private static function phoneLabel(string $digits): string
    {
        return substr($digits, 0, 4) . ' ' . substr($digits, 4, 3) . ' ' . substr($digits, 7, 2) . ' ' . substr($digits, 9, 2);
    }

    private static function addMessage(int $threadId, string $sender, string $body, string $now): void
    {
        if (!in_array($sender, ['user', 'assistant', 'agent'], true)) {
            return;
        }
        Database::table('assistant_support_messages')->insert([
            'thread_id'   => $threadId,
            'sender_type' => $sender,
            'body'        => $body,
            'created_at'  => $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function owned(string $visitor, string $publicToken): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $visitor) !== 1 || preg_match('/^[a-f0-9]{64}$/', $publicToken) !== 1) {
            return null;
        }
        $thread = Database::table('assistant_support_threads')->where('public_token', $publicToken)->first();
        if ($thread === null || !hash_equals((string) $thread['visitor_token'], $visitor)) {
            return null;
        }

        return $thread;
    }

    private static function plain(string $text, int $limit): string
    {
        $text = trim(strip_tags($text));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit);
        }

        return $text;
    }
}
