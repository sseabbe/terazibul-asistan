<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use App\Services\AssistantV2\AssistantV2ConversationReport;
use Core\Console\Command;
use Core\Database;
use Core\Schema\Schema;

/**
 * Salt okunur konuşma kalite raporu. Üretim verisini ve sohbet davranışını değiştirmez.
 */
final class AdvisorCiReportCommand extends Command
{
    protected string $signature = 'advisor:ci-report';

    protected string $description = 'Read-only conversation intelligence report (--days=1|7|30, --version=v1.2.0)';

    public function handle(): int
    {
        $daysOption = $this->option('days');
        $days = $daysOption === null || $daysOption === true ? 7 : (int) $daysOption;
        if (!in_array($days, [1, 7, 30], true)) {
            $this->error('--days must be 1, 7, or 30.');

            return 1;
        }
        $versionOption = $this->option('version');
        $version = is_string($versionOption) ? trim($versionOption) : '';
        if (strlen($version) > 32 || preg_match('/[^A-Za-z0-9._-]/', $version) === 1) {
            $this->error('--version contains unsupported characters.');

            return 1;
        }

        $events = $this->events($days, $version);
        $report = AssistantV2ConversationReport::build($events, [
            'days' => $days,
            'version' => $version,
        ]);
        $text = AssistantV2ConversationReport::renderText($report);
        $this->line(rtrim($text));
        $path = $this->write($report, $days, $version);
        $this->info('Report file: ' . $path);

        return 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(int $days, string $version): array
    {
        if (!Schema::hasTable('assistant_conversation_events')) {
            return [];
        }
        $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
        $query = Database::table('assistant_conversation_events')->where('created_at', '>=', $cutoff);
        if ($version !== '') {
            $query->where('assistant_version', $version);
        }
        $rows = $query->get();
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function write(array $report, int $days, string $version): string
    {
        $dir = base_path('storage/assistant_v2/ci_reports');
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->warn('Report directory was not created. Terminal summary only.');

            return '';
        }
        $name = 'ci-report-' . date('Y-m-d') . '-' . $days . 'd';
        if ($version !== '') {
            $name .= '-' . $version;
        }
        $path = $dir . '/' . $name . '.md';
        $markdown = AssistantV2ConversationReport::renderMarkdown($report);
        file_put_contents($path, $markdown);

        return $path;
    }
}
