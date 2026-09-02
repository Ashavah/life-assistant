<?php

namespace App\Services\Knowledge;

use App\KnowledgeIngestionDecision;
use App\KnowledgeIngestionStatus;
use App\Models\Character;
use App\Models\KnowledgeIngestion;
use App\Models\KnowledgeIngestionItem;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KnowledgeIngestionReviewService
{
    public function __construct(
        private MemoryWriter $memoryWriter,
        private KnowledgeIngestionPurger $purger,
    ) {}

    /**
     * @param  array<int, int>  $selectedItemIds
     */
    public function confirm(KnowledgeIngestion $ingestion, array $selectedItemIds): int
    {
        if ($ingestion->purged_at !== null) {
            if ($ingestion->decision === KnowledgeIngestionDecision::Confirmed) {
                return 0;
            }

            throw new RuntimeException('Questa importazione è già stata chiusa.');
        }

        if ($ingestion->expires_at->isPast()) {
            $this->purger->purge($ingestion, KnowledgeIngestionDecision::Expired);

            throw new RuntimeException('Questa importazione è scaduta.');
        }

        $applied = DB::transaction(function () use ($ingestion, $selectedItemIds): int {
            $locked = KnowledgeIngestion::query()->lockForUpdate()->findOrFail($ingestion->id);

            if ($locked->decision === KnowledgeIngestionDecision::Confirmed) {
                return 0;
            }

            if ($locked->status !== KnowledgeIngestionStatus::AwaitingReview) {
                throw new RuntimeException('L’importazione non è pronta per la conferma.');
            }

            $locked->items()->update(['selected' => false]);
            $items = $locked->items()
                ->whereKey($selectedItemIds)
                ->with('character')
                ->orderBy('sort_order')
                ->get();

            if ($items->count() !== count(array_unique($selectedItemIds))) {
                throw new RuntimeException('Una o più proposte non appartengono a questa importazione.');
            }

            $items->each->update(['selected' => true]);
            $characters = Character::query()
                ->where('user_id', $locked->user_id)
                ->get()
                ->keyBy(fn (Character $character): string => $this->slug($character));
            $changes = $items
                ->map(fn (KnowledgeIngestionItem $item): array => [
                    'character' => $this->slug($item->character),
                    'action' => $item->action,
                    'key' => $item->memory_key,
                    'category' => $item->category,
                    'content' => $item->content,
                    'importance' => $item->importance,
                    'confidence' => $item->confidence,
                    'reason' => $item->reason,
                    'source_reference' => $item->source_reference,
                ])
                ->all();
            $applied = $this->memoryWriter->apply(
                $changes,
                $characters,
                sourceIngestion: $locked,
            );

            $locked->update([
                'status' => KnowledgeIngestionStatus::Confirmed,
                'decision' => KnowledgeIngestionDecision::Confirmed,
                'decided_at' => now(),
            ]);

            return $applied;
        });

        $this->purger->purge($ingestion->fresh(), KnowledgeIngestionDecision::Confirmed);

        return $applied;
    }

    public function reject(KnowledgeIngestion $ingestion): void
    {
        if ($ingestion->purged_at !== null) {
            if ($ingestion->decision === KnowledgeIngestionDecision::Rejected) {
                return;
            }

            throw new RuntimeException('Questa importazione è già stata chiusa.');
        }

        if (! in_array($ingestion->status, [
            KnowledgeIngestionStatus::Pending,
            KnowledgeIngestionStatus::Processing,
            KnowledgeIngestionStatus::AwaitingReview,
            KnowledgeIngestionStatus::Failed,
        ], true)) {
            throw new RuntimeException('Questa importazione non può essere rifiutata.');
        }

        $ingestion->update([
            'status' => KnowledgeIngestionStatus::Rejected,
            'decision' => KnowledgeIngestionDecision::Rejected,
            'decided_at' => now(),
        ]);
        $this->purger->purge($ingestion, KnowledgeIngestionDecision::Rejected);
    }

    private function slug(Character $character): string
    {
        return $character->slug instanceof BackedEnum
            ? (string) $character->slug->value
            : (string) $character->slug;
    }
}
