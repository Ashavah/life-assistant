<?php

namespace App\Services\Knowledge;

use App\KnowledgeIngestionDecision;
use App\KnowledgeIngestionStatus;
use App\Models\KnowledgeIngestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class KnowledgeIngestionPurger
{
    public function purge(KnowledgeIngestion $ingestion, KnowledgeIngestionDecision $decision): void
    {
        if ($ingestion->purged_at !== null) {
            return;
        }

        if ($ingestion->disk && $ingestion->path) {
            $disk = Storage::disk($ingestion->disk);

            if ($disk->exists($ingestion->path) && ! $disk->delete($ingestion->path)) {
                throw new \RuntimeException('Non è stato possibile eliminare la sorgente temporanea.');
            }
        }

        DB::transaction(function () use ($ingestion, $decision): void {
            $ingestion->items()->delete();
            $ingestion->update([
                'status' => KnowledgeIngestionStatus::Purged,
                'decision' => $decision,
                'disk' => null,
                'path' => null,
                'temporary_text' => null,
                'decided_at' => $ingestion->decided_at ?? now(),
                'purged_at' => now(),
            ]);
        });
    }
}
