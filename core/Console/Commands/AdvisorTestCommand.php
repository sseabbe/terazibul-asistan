<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Command;

final class AdvisorTestCommand extends Command
{
    protected string $signature = 'advisor:test';

    protected string $description = 'Advisor V2 mocked conversation integration tests';

    public function handle(): int
    {
        $script = base_path('scripts/advisor_conversation_tests.php');
        if (!is_file($script)) {
            echo "Test script missing: {$script}\n";

            return 1;
        }

        passthru(PHP_BINARY . ' ' . escapeshellarg($script), $code);

        return (int) $code;
    }
}
