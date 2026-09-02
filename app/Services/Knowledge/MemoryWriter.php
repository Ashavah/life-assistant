<?php

namespace App\Services\Knowledge;

use App\Models\Character;
use App\Models\Conversation;
use App\Models\KnowledgeIngestion;
use App\Models\Memory;
use App\Models\MemoryChange;
use App\Models\Message;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MemoryWriter
{
    /**
     * @param  array<int, mixed>  $changes
     * @param  Collection<string, Character>  $characters
     */
    public function apply(
        array $changes,
        Collection $characters,
        ?Conversation $conversation = null,
        ?Message $sourceMessage = null,
        ?KnowledgeIngestion $sourceIngestion = null,
    ): int {
        return DB::transaction(function () use (
            $changes,
            $characters,
            $conversation,
            $sourceMessage,
            $sourceIngestion,
        ): int {
            $applied = 0;
            $touched = [];

            foreach ($changes as $change) {
                if (! is_array($change)) {
                    continue;
                }

                $characterSlug = Arr::get($change, 'character');
                $character = is_string($characterSlug) ? $characters->get($characterSlug) : null;
                $action = Arr::get($change, 'action');
                $memoryKey = Str::limit(
                    Str::slug((string) Arr::get($change, 'key'), '_'),
                    120,
                    '',
                );

                if (! $character || ! in_array($action, ['upsert', 'deactivate'], true) || $memoryKey === '') {
                    continue;
                }

                $memory = Memory::query()
                    ->whereBelongsTo($character)
                    ->where('memory_key', $memoryKey)
                    ->lockForUpdate()
                    ->first();

                if ($action === 'deactivate') {
                    if (! $memory || $memory->archived_at !== null) {
                        continue;
                    }

                    $before = $memory->toArray();
                    $memory->update(['archived_at' => now()]);
                    $this->recordChange(
                        $memory,
                        $character,
                        $conversation,
                        $sourceMessage,
                        $sourceIngestion,
                        'deactivate',
                        $change,
                        $before,
                    );
                    $applied++;
                    $touched[$character->id] = $character;

                    continue;
                }

                $content = trim((string) Arr::get($change, 'content'));

                if ($content === '') {
                    continue;
                }

                $attributes = [
                    'category' => Str::limit(Str::slug((string) Arr::get($change, 'category', 'general'), '_'), 80, ''),
                    'content' => Str::limit($content, 1000, ''),
                    'importance' => min(5, max(1, (int) Arr::get($change, 'importance', 3))),
                    'confidence' => min(1, max(0, (float) Arr::get($change, 'confidence', 0.8))),
                    'last_reinforced_at' => now(),
                    'archived_at' => null,
                ];

                if ($conversation) {
                    $attributes['source_conversation_id'] = $conversation->id;
                    $attributes['source_message_id'] = $sourceMessage?->id;
                }

                if ($memory && $this->hasSameKnowledge($memory, $attributes)) {
                    continue;
                }

                $before = $memory?->toArray();

                if ($memory) {
                    $memory->update($attributes);
                    $auditAction = 'update';
                } else {
                    $memory = $character->memories()->create(array_merge(
                        ['memory_key' => $memoryKey],
                        $attributes,
                    ));
                    $auditAction = 'create';
                }

                $this->recordChange(
                    $memory,
                    $character,
                    $conversation,
                    $sourceMessage,
                    $sourceIngestion,
                    $auditAction,
                    $change,
                    $before,
                );
                $applied++;
                $touched[$character->id] = $character;
            }

            foreach ($touched as $character) {
                $this->prune($character);
            }

            return $applied;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function hasSameKnowledge(Memory $memory, array $attributes): bool
    {
        return $memory->archived_at === null
            && $memory->category === $attributes['category']
            && $memory->content === $attributes['content']
            && $memory->importance === $attributes['importance']
            && abs($memory->confidence - $attributes['confidence']) < 0.001;
    }

    private function prune(Character $character): void
    {
        $limit = (int) config('ai.max_memories_per_character', 40);
        $keepIds = Memory::query()
            ->active()
            ->whereBelongsTo($character)
            ->orderByDesc('importance')
            ->orderByDesc('last_reinforced_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        if ($keepIds->isEmpty()) {
            return;
        }

        Memory::query()
            ->active()
            ->whereBelongsTo($character)
            ->whereNotIn('id', $keepIds)
            ->update(['archived_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  array<string, mixed>|null  $before
     */
    private function recordChange(
        Memory $memory,
        Character $character,
        ?Conversation $conversation,
        ?Message $sourceMessage,
        ?KnowledgeIngestion $sourceIngestion,
        string $action,
        array $change,
        ?array $before,
    ): void {
        $reason = trim(implode(' — ', array_filter([
            (string) Arr::get($change, 'reason'),
            (string) Arr::get($change, 'source_reference'),
        ])));

        MemoryChange::query()->create([
            'memory_id' => $memory->id,
            'character_id' => $character->id,
            'source_conversation_id' => $conversation?->id,
            'source_message_id' => $sourceMessage?->id,
            'source_knowledge_ingestion_id' => $sourceIngestion?->id,
            'action' => $action,
            'reason' => Str::limit($reason, 1000, ''),
            'before' => $before,
            'after' => $memory->fresh()?->toArray(),
        ]);
    }
}
