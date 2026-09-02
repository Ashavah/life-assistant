<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmKnowledgeIngestionRequest;
use App\Http\Requests\StoreKnowledgeIngestionRequest;
use App\Jobs\ProcessKnowledgeIngestion;
use App\KnowledgeIngestionStatus;
use App\KnowledgeSourceType;
use App\Models\Character;
use App\Models\KnowledgeIngestion;
use App\Services\Knowledge\KnowledgeIngestionReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class KnowledgeIngestionController extends Controller
{
    public function store(
        StoreKnowledgeIngestionRequest $request,
        Character $character,
    ): JsonResponse {
        $ingestions = collect();
        $text = trim((string) $request->validated('text', ''));

        if ($text !== '') {
            $ingestions->push($this->createTextIngestion($character, $text));
        }

        foreach ($request->file('files', []) as $file) {
            $ingestions->push($this->createFileIngestion($character, $file));
        }

        $ingestions
            ->unique('id')
            ->each(fn (KnowledgeIngestion $ingestion) => ProcessKnowledgeIngestion::dispatch($ingestion->id)->afterResponse());

        return response()->json([
            'message' => 'Importazione avviata. Potrai revisionare ogni fatto prima di salvarlo.',
            'ingestions' => $ingestions
                ->unique('id')
                ->values()
                ->map(fn (KnowledgeIngestion $ingestion): array => $this->serialize($ingestion)),
        ], 202);
    }

    public function show(KnowledgeIngestion $knowledgeIngestion): JsonResponse
    {
        $this->authorize('view', $knowledgeIngestion);

        return response()->json([
            'ingestion' => $this->serialize(
                $knowledgeIngestion->load(['items.character']),
            ),
        ]);
    }

    public function confirm(
        ConfirmKnowledgeIngestionRequest $request,
        KnowledgeIngestion $knowledgeIngestion,
        KnowledgeIngestionReviewService $reviews,
    ): JsonResponse {
        try {
            $applied = $reviews->confirm(
                $knowledgeIngestion,
                $request->validated('selected_items'),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'message' => "Importazione confermata: {$applied} memorie aggiornate.",
            'status' => KnowledgeIngestionStatus::Purged->value,
        ]);
    }

    public function reject(
        Request $request,
        KnowledgeIngestion $knowledgeIngestion,
        KnowledgeIngestionReviewService $reviews,
    ): JsonResponse {
        $this->authorize('reject', $knowledgeIngestion);

        try {
            $reviews->reject($knowledgeIngestion);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Importazione rifiutata e dati temporanei eliminati.',
            'status' => KnowledgeIngestionStatus::Purged->value,
        ]);
    }

    private function createTextIngestion(Character $character, string $text): KnowledgeIngestion
    {
        $hash = hash('sha256', $text);

        return $this->activeDuplicate($character, $hash)
            ?? $character->knowledgeIngestions()->create([
                'status' => KnowledgeIngestionStatus::Pending,
                'source_type' => KnowledgeSourceType::Text,
                'mime_type' => 'text/plain',
                'size_bytes' => strlen($text),
                'content_hash' => $hash,
                'temporary_text' => $text,
                'expires_at' => now()->addHours((int) config('knowledge.ttl_hours', 24)),
            ]);
    }

    private function createFileIngestion(Character $character, UploadedFile $file): KnowledgeIngestion
    {
        $hash = hash_file('sha256', $file->getRealPath());

        if (! is_string($hash)) {
            throw new RuntimeException('Non è stato possibile verificare il file.');
        }

        $existing = $this->activeDuplicate($character, $hash);

        if ($existing) {
            return $existing;
        }

        $disk = (string) config('knowledge.disk', 'local');
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $filename = Str::uuid().($extension !== '' ? '.'.$extension : '');
        $path = Storage::disk($disk)->putFileAs(
            "knowledge-ingestions/{$character->user_id}/{$character->id}",
            $file,
            $filename,
        );

        if (! is_string($path)) {
            throw new RuntimeException('Non è stato possibile conservare temporaneamente il file.');
        }

        $mimeType = (string) ($file->getMimeType() ?: $file->getClientMimeType());

        try {
            return $character->knowledgeIngestions()->create([
                'status' => KnowledgeIngestionStatus::Pending,
                'source_type' => str_starts_with($mimeType, 'image/')
                    ? KnowledgeSourceType::Image
                    : KnowledgeSourceType::File,
                'original_filename' => Str::limit($file->getClientOriginalName(), 255, ''),
                'mime_type' => Str::limit($mimeType, 100, ''),
                'size_bytes' => $file->getSize(),
                'content_hash' => $hash,
                'disk' => $disk,
                'path' => $path,
                'expires_at' => now()->addHours((int) config('knowledge.ttl_hours', 24)),
            ]);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    private function activeDuplicate(Character $character, string $hash): ?KnowledgeIngestion
    {
        return $character->knowledgeIngestions()
            ->where('content_hash', $hash)
            ->whereNull('purged_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(KnowledgeIngestion $ingestion): array
    {
        return [
            'id' => $ingestion->id,
            'status' => $ingestion->status->value,
            'decision' => $ingestion->decision?->value,
            'source_type' => $ingestion->source_type->value,
            'name' => $ingestion->original_filename ?? 'Testo incollato',
            'item_count' => $ingestion->item_count,
            'error' => $ingestion->error_message,
            'expires_at' => $ingestion->expires_at?->toIso8601String(),
            'status_url' => route('knowledge-ingestions.show', $ingestion),
            'confirm_url' => route('knowledge-ingestions.confirm', $ingestion),
            'reject_url' => route('knowledge-ingestions.reject', $ingestion),
            'items' => $ingestion->relationLoaded('items')
                ? $ingestion->items
                    ->map(fn ($item): array => [
                        'id' => $item->id,
                        'character' => [
                            'id' => $item->character->id,
                            'name' => $item->character->name,
                            'slug' => $item->character->slug,
                            'is_global' => $item->character->is_global,
                        ],
                        'action' => $item->action,
                        'key' => $item->memory_key,
                        'category' => $item->category,
                        'content' => $item->content,
                        'importance' => $item->importance,
                        'confidence' => $item->confidence,
                        'reason' => $item->reason,
                        'source_reference' => $item->source_reference,
                        'selected' => $item->selected,
                    ])
                    ->values()
                : [],
        ];
    }
}
