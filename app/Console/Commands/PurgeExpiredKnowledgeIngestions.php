<?php

namespace App\Console\Commands;

use App\KnowledgeIngestionDecision;
use App\Models\KnowledgeIngestion;
use App\Services\Knowledge\KnowledgeIngestionPurger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:purge-expired-knowledge-ingestions')]
#[Description('Elimina sorgenti e anteprime di conoscenza scadute')]
class PurgeExpiredKnowledgeIngestions extends Command
{
    public function handle(KnowledgeIngestionPurger $purger): int
    {
        $count = 0;

        KnowledgeIngestion::query()
            ->whereNull('purged_at')
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($ingestions) use ($purger, &$count): void {
                foreach ($ingestions as $ingestion) {
                    $purger->purge($ingestion, KnowledgeIngestionDecision::Expired);
                    $count++;
                }
            });

        $this->info("Importazioni eliminate: {$count}");

        return self::SUCCESS;
    }
}
