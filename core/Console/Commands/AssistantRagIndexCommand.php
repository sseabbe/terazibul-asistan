<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use App\Services\AssistantV2\AssistantV2AdvisorRagIndexer;
use App\Services\AssistantV2\AssistantV2AdvisorRagStore;
use Core\Console\Command;

class AssistantRagIndexCommand extends Command
{
    protected string $signature = 'assistant:rag-index';
    protected string $description = 'Terazibul advisor RAG indeksini DB ve statik kaynaklardan yeniler';

    public function handle(): int
    {
        $force = $this->hasOption('force');
        $this->info('Advisor RAG indeksi oluşturuluyor…');
        $stats = AssistantV2AdvisorRagIndexer::rebuild($force);
        $path = AssistantV2AdvisorRagStore::indexPath();
        $this->success(sprintf(
            'Tamamlandı: %d parça (+%d güncellendi, %d atlandı) → %s',
            $stats['total'],
            $stats['updated'],
            $stats['skipped'],
            $path
        ));

        return 0;
    }
}
