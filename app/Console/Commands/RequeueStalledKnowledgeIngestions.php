<?php

namespace App\Console\Commands;

use App\Jobs\ProcessKnowledgeIngestion;
use App\KnowledgeIngestionStatus;
use App\Models\KnowledgeIngestion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('app:requeue-stalled-knowledge-ingestions')]
#[Description('Rimette in coda le importazioni rimaste bloccate senza un worker che le completi')]
class RequeueStalledKnowledgeIngestions extends Command
{
    public function handle(): int
    {
        $threshold = now()->subMinutes((int) config('knowledge.stalled_after_minutes', 15));
        $count = 0;

        KnowledgeIngestion::query()
            ->whereNull('purged_at')
            ->whereIn('status', [KnowledgeIngestionStatus::Pending, KnowledgeIngestionStatus::Processing])
            ->where('updated_at', '<=', $threshold)
            ->where('expires_at', '>', now())
            ->chunkById(100, function (Collection $ingestions) use (&$count): void {
                foreach ($ingestions as $ingestion) {
                    $ingestion->update([
                        'status' => KnowledgeIngestionStatus::Pending,
                        'error_message' => null,
                    ]);

                    ProcessKnowledgeIngestion::dispatch($ingestion->id);
                    $count++;
                }
            });

        $this->info("Importazioni rimesse in coda: {$count}");

        return self::SUCCESS;
    }
}
