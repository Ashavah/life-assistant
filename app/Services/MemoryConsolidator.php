<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\MemoryChange;
use App\Models\Message;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MemoryConsolidator
{
    public function __construct(private AiChatClient $client) {}

    /**
     * @return array{summary: string|null, changes: int}
     */
    public function consolidate(Conversation $conversation): array
    {
        $conversation->loadMissing('character');
        $charactersQuery = Character::query()
            ->where('user_id', $conversation->user_id)
            ->when(
                $conversation->character->is_global,
                fn ($query) => $query->where('is_global', false),
                fn ($query) => $query->whereKey($conversation->character_id),
            );

        $characters = $charactersQuery
            ->with(['memories' => fn ($query) => $query->active()->orderByDesc('importance')])
            ->get()
            ->keyBy(fn (Character $character): string => $character->slug);

        $transcript = $conversation->messages()
            ->select(['role', 'content'])
            ->reorder()
            ->latest('id')
            ->limit(100)
            ->get()
            ->reverse()
            ->map(fn (Message $message): string => strtoupper($message->role).': '.$message->content)
            ->implode("\n");

        if ($transcript === '') {
            return ['summary' => null, 'changes' => 0];
        }

        $existingMemories = $characters
            ->map(function (Character $character): string {
                $items = $character->memories
                    ->map(fn (Memory $memory): string => sprintf(
                        '- %s | %s | %s',
                        $memory->memory_key,
                        $memory->category,
                        $memory->content,
                    ))
                    ->implode("\n");

                return "### {$character->slug}\n".($items !== '' ? $items : '- nessuna');
            })
            ->implode("\n\n");

        $result = $this->client->completeStructured(
            [[
                'role' => 'user',
                'content' => "MEMORIE ESISTENTI:\n{$existingMemories}\n\nCONVERSAZIONE:\n{$transcript}",
            ]],
            $this->extractionPrompt($characters),
        );

        $summary = Arr::get($result, 'summary');
        $summary = is_string($summary) && trim($summary) !== '' ? trim($summary) : null;
        $changes = Arr::get($result, 'changes', []);

        if (! is_array($changes)) {
            throw new RuntimeException('La risposta IA non contiene una lista changes valida.');
        }

        $appliedChanges = $this->applyChanges(
            $changes,
            $characters,
            $conversation,
            $conversation->messages()->where('role', 'assistant')->reorder()->latest('id')->first(),
        );

        return [
            'summary' => $summary,
            'changes' => $appliedChanges,
        ];
    }

    /**
     * @param  Collection<string, Character>  $characters
     */
    private function extractionPrompt(Collection $characters): string
    {
        $targets = $characters
            ->map(fn (Character $character): string => "- {$character->slug}: {$character->description}")
            ->implode("\n");

        return <<<PROMPT
Sei il motore di memoria di Life Assistant. Estrai soltanto fatti durevoli e utili da ricordare, non saluti, supposizioni, consigli dell'assistente o dettagli effimeri.

Destinatari autorizzati e relativi ambiti:
{$targets}

Ogni fatto va assegnato solo ai destinatari che ne hanno davvero bisogno. Non creare memoria per il personaggio global. Aggiorna una chiave esistente quando il fatto la corregge o rafforza. Usa deactivate quando un fatto esistente è esplicitamente smentito o non è più valido.

Rispondi esclusivamente con JSON:
{
  "summary": "riassunto molto breve della conversazione",
  "changes": [
    {
      "character": "uno dei destinatari autorizzati",
      "action": "upsert oppure deactivate",
      "key": "chiave_stabile_breve",
      "category": "categoria_breve",
      "content": "fatto atomico, autonomo e conciso",
      "importance": 1,
      "confidence": 0.0,
      "reason": "motivazione breve"
    }
  ]
}
importance deve essere tra 1 e 5, confidence tra 0 e 1. Se non c'è nulla da ricordare, changes deve essere [].
PROMPT;
    }

    /**
     * @param  array<int, mixed>  $changes
     * @param  Collection<string, Character>  $characters
     */
    private function applyChanges(
        array $changes,
        $characters,
        Conversation $conversation,
        ?Message $sourceMessage,
    ): int {
        return DB::transaction(function () use ($changes, $characters, $conversation, $sourceMessage): int {
            $applied = 0;

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
                    $this->recordChange($memory, $character, $conversation, $sourceMessage, 'deactivate', $change, $before);
                    $applied++;

                    continue;
                }

                $content = trim((string) Arr::get($change, 'content'));

                if ($content === '') {
                    continue;
                }

                $before = $memory?->toArray();
                $attributes = [
                    'category' => Str::limit(Str::slug((string) Arr::get($change, 'category', 'general'), '_'), 80, ''),
                    'content' => Str::limit($content, 1000, ''),
                    'importance' => min(5, max(1, (int) Arr::get($change, 'importance', 3))),
                    'confidence' => min(1, max(0, (float) Arr::get($change, 'confidence', 0.8))),
                    'source_conversation_id' => $conversation->id,
                    'source_message_id' => $sourceMessage?->id,
                    'last_reinforced_at' => now(),
                    'archived_at' => null,
                ];

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

                $this->recordChange($memory, $character, $conversation, $sourceMessage, $auditAction, $change, $before);
                $applied++;
            }

            return $applied;
        });
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  array<string, mixed>|null  $before
     */
    private function recordChange(
        Memory $memory,
        Character $character,
        Conversation $conversation,
        ?Message $sourceMessage,
        string $action,
        array $change,
        ?array $before,
    ): void {
        MemoryChange::query()->create([
            'memory_id' => $memory->id,
            'character_id' => $character->id,
            'source_conversation_id' => $conversation->id,
            'source_message_id' => $sourceMessage?->id,
            'action' => $action,
            'reason' => Str::limit((string) Arr::get($change, 'reason'), 1000, ''),
            'before' => $before,
            'after' => $memory->fresh()?->toArray(),
        ]);
    }
}
