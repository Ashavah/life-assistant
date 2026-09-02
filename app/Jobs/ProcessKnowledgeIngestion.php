<?php

namespace App\Jobs;

use App\KnowledgeIngestionDecision;
use App\KnowledgeIngestionStatus;
use App\Models\Character;
use App\Models\KnowledgeIngestion;
use App\Services\Knowledge\KnowledgeExtractor;
use App\Services\Knowledge\KnowledgeIngestionPlanner;
use App\Services\Knowledge\KnowledgeIngestionPurger;
use BackedEnum;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessKnowledgeIngestion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var array<int, int> */
    public array $backoff = [15, 60, 180];

    public int $uniqueFor = 900;

    public function __construct(public int $ingestionId)
    {
        $this->onQueue((string) config('knowledge.queue', 'knowledge'));
    }

    public function uniqueId(): string
    {
        return (string) $this->ingestionId;
    }

    public function handle(
        KnowledgeExtractor $extractor,
        KnowledgeIngestionPlanner $planner,
        KnowledgeIngestionPurger $purger,
    ): void {
        $ingestion = KnowledgeIngestion::query()->with('character')->findOrFail($this->ingestionId);

        if ($ingestion->purged_at !== null || $ingestion->status === KnowledgeIngestionStatus::AwaitingReview) {
            return;
        }

        if ($ingestion->expires_at->isPast()) {
            $purger->purge($ingestion, KnowledgeIngestionDecision::Expired);

            return;
        }

        $ingestion->update([
            'status' => KnowledgeIngestionStatus::Processing,
            'error_message' => null,
        ]);

        $text = $extractor->extract($ingestion);
        $proposals = $planner->propose($ingestion->character, $text);
        $characters = Character::query()
            ->where('user_id', $ingestion->user_id)
            ->get()
            ->keyBy(fn (Character $character): string => $character->slug instanceof BackedEnum
                ? (string) $character->slug->value
                : (string) $character->slug);

        DB::transaction(function () use ($ingestion, $text, $proposals, $characters): void {
            $locked = KnowledgeIngestion::query()->lockForUpdate()->findOrFail($ingestion->id);

            if ($locked->purged_at !== null) {
                return;
            }

            $locked->items()->delete();

            foreach ($proposals as $index => $proposal) {
                $character = $characters->get((string) ($proposal['character'] ?? ''));

                if (! $character) {
                    continue;
                }

                $locked->items()->create([
                    'character_id' => $character->id,
                    'action' => $proposal['action'],
                    'memory_key' => $proposal['key'],
                    'category' => $proposal['category'],
                    'content' => $proposal['content'],
                    'importance' => $proposal['importance'],
                    'confidence' => $proposal['confidence'],
                    'reason' => $proposal['reason'],
                    'source_reference' => $proposal['source_reference'],
                    'selected' => true,
                    'sort_order' => $index,
                ]);
            }

            $locked->update([
                'status' => KnowledgeIngestionStatus::AwaitingReview,
                'temporary_text' => $text,
                'item_count' => $locked->items()->count(),
                'processed_at' => now(),
                'error_message' => null,
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        KnowledgeIngestion::query()
            ->whereKey($this->ingestionId)
            ->whereNull('purged_at')
            ->update([
                'status' => KnowledgeIngestionStatus::Failed,
                'error_message' => mb_substr(
                    $exception?->getMessage() ?? 'Elaborazione non riuscita.',
                    0,
                    2000,
                ),
            ]);
    }
}
