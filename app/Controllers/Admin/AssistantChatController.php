<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;

class AssistantChatController extends Controller
{
    public function index(Request $request): Response
    {
        $dateRaw = trim((string) ($request->query('date') ?? ''));
        $sonRaw = (int) ($request->query('son') ?? 0);
        $today = date('Y-m-d');
        $from = null;
        $mode = 'range';
        $days = in_array($sonRaw, [1, 7, 14, 30], true) ? $sonRaw : 14;

        if ($dateRaw === 'all') {
            $mode = 'all';
        } elseif ($dateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) === 1 && strtotime($dateRaw) !== false) {
            // Dashboard'dan tek gün
            $mode = 'day';
            $from = min(date('Y-m-d', (int) strtotime($dateRaw)), $today);
        } else {
            $from = date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' days'));
        }

        if ($mode === 'all') {
            $rows = $this->rows(null, null);
            $label = 'Tüm zamanlar';
        } elseif ($mode === 'day') {
            $rows = $this->rows($from, date('Y-m-d', strtotime($from . ' +1 day')));
            $label = date('d.m.Y', strtotime($from));
        } else {
            $rows = $this->rows($from, date('Y-m-d', strtotime($today . ' +1 day')));
            $label = $days === 1 ? 'Bugün' : 'Son ' . $days . ' gün';
        }
        $conversations = $this->group($rows, $mode === 'all');

        return $this->view('back/assistant-chats/index', [
            'title'         => 'Asistan Sohbetleri',
            'conversations' => $conversations,
            'mode'          => $mode,
            'days'          => $mode === 'range' ? $days : 0,
            'date'          => $from,
            'dayLabel'      => $label,
            'dashboardUrl'  => $mode === 'day' ? url('/admin/dashboard') . '?date=' . $from : '',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(?string $from, ?string $to): array
    {
        try {
            if ($from === null) {
                return Database::select(
                    "SELECT id, conversation_id, user_message_redacted, assistant_message_redacted, created_at
                     FROM assistant_conversation_events
                     WHERE TRIM(user_message_redacted) <> ''
                     ORDER BY created_at DESC, id DESC
                     LIMIT 1000"
                );
            }

            return Database::select(
                "SELECT id, conversation_id, user_message_redacted, assistant_message_redacted, created_at
                 FROM assistant_conversation_events
                 WHERE TRIM(user_message_redacted) <> ''
                   AND created_at >= ?
                   AND created_at < ?
                 ORDER BY created_at ASC, id ASC",
                [$from . ' 00:00:00', $to . ' 00:00:00']
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{started_at:string,turns:list<array<string, mixed>>}>
     */
    private function group(array $rows, bool $newestFirst): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $cid = trim((string) ($row['conversation_id'] ?? ''));
            $key = $cid !== '' ? $cid : 'row-' . (int) ($row['id'] ?? 0);
            $groups[$key][] = $row;
        }

        $conversations = [];
        foreach ($groups as $turns) {
            if ($newestFirst) {
                $turns = array_reverse($turns);
            }
            $conversations[] = [
                'started_at' => (string) ($turns[0]['created_at'] ?? ''),
                'turns'      => $turns,
            ];
        }

        if (!$newestFirst) {
            $conversations = array_reverse($conversations);
        }

        return $conversations;
    }
}
