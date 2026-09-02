<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use App\Services\Knowledge\MemoryWriter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class MemoryConsolidator
{
    public function __construct(
        private AiChatClient $client,
        private MemoryWriter $memoryWriter,
    ) {}

    /**
     * @return array{summary: string|null, title: string|null, changes: int}
     */
    public function consolidate(Conversation $conversation, bool $fullTranscript = false): array
    {
        $conversation->loadMissing('character');
        $characters = $this->targetCharacters($conversation);
        $transcriptLimit = $fullTranscript
            ? (int) config('ai.memory_close_messages', 40)
            : (int) config('ai.memory_incremental_messages', 16);

        $messages = $conversation->messages()
            ->select(['id', 'role', 'content'])
            ->when(
                $fullTranscript ? null : $conversation->memory_consolidated_through_message_id,
                fn ($query, int $messageId) => $query->where('id', '>', $messageId),
            )
            ->reorder()
            ->when(
                $fullTranscript,
                fn ($query) => $query->latest('id'),
                fn ($query) => $query->oldest('id'),
            )
            ->limit($transcriptLimit)
            ->get();

        if ($fullTranscript) {
            $messages = $messages->reverse()->values();
        }

        $transcript = $messages
            ->map(fn (Message $message): string => strtoupper($message->role).': '.$message->content)
            ->implode("\n");

        if ($transcript === '') {
            return [
                'summary' => $conversation->context_summary,
                'title' => $conversation->title,
                'changes' => 0,
            ];
        }

        $existingMemories = $characters
            ->map(function (Character $character): string {
                $items = $character->memories
                    ->take((int) config('ai.max_memories_per_character', 40))
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

        $knownSummary = filled($conversation->context_summary)
            ? "RIASSUNTO GIÀ NOTO DI QUESTA CHAT:\n{$conversation->context_summary}\n\n"
            : '';
        $titleInstruction = filled($conversation->title)
            ? "TITOLO ATTUALE: {$conversation->title}. Non modificarlo e restituisci title null.\n\n"
            : "TITOLO ATTUALE: assente. Genera un titolo di 2-6 parole sull'argomento principale.\n\n";

        $result = $this->client->completeStructured(
            [[
                'role' => 'user',
                'content' => "{$titleInstruction}{$knownSummary}MEMORIE ESISTENTI:\n{$existingMemories}\n\nMESSAGGI DA ASSORBIRE:\n{$transcript}",
            ]],
            $this->extractionPrompt($conversation->character, $characters, $fullTranscript),
        );

        $summary = Arr::get($result, 'summary');
        $summary = is_string($summary) && trim($summary) !== '' ? trim($summary) : null;
        $title = Arr::get($result, 'title');
        $title = is_string($title) && trim($title) !== ''
            ? Str::limit(trim($title), 80, '')
            : null;
        $changes = Arr::get($result, 'changes', []);

        if (! is_array($changes)) {
            throw new RuntimeException('La risposta IA non contiene una lista changes valida.');
        }

        if ($fullTranscript && $summary === null) {
            throw new RuntimeException('La risposta IA non contiene il riepilogo finale della conversazione.');
        }

        $appliedChanges = $this->memoryWriter->apply(
            $changes,
            $characters,
            $conversation,
            $conversation->messages()->where('role', 'assistant')->reorder()->latest('id')->first(),
        );

        $conversation->update([
            'context_summary' => $summary !== null
                ? Str::limit(
                    $summary,
                    (int) config('ai.conversation_summary_max_characters', 8000),
                    '',
                )
                : $conversation->context_summary,
            'title' => $conversation->title ?? $title,
            'memory_consolidated_through_message_id' => $messages->max('id'),
        ]);

        return [
            'summary' => $summary,
            'title' => $title,
            'changes' => $appliedChanges,
        ];
    }

    /**
     * @return Collection<string, Character>
     */
    private function targetCharacters(Conversation $conversation): Collection
    {
        $query = Character::query()->where('user_id', $conversation->user_id);

        if (! $conversation->character->is_global) {
            $query->whereKey($conversation->character_id);
        }

        return $query
            ->with(['memories' => fn ($memories) => $memories->active()->orderByDesc('importance')->orderByDesc('last_reinforced_at')])
            ->get()
            ->keyBy(fn (Character $character): string => $character->slug);
    }

    /**
     * @param  Collection<string, Character>  $characters
     */
    private function extractionPrompt(
        Character $speaker,
        Collection $characters,
        bool $fullTranscript,
    ): string {
        $targets = $characters
            ->map(fn (Character $character): string => "- {$character->slug}: {$character->description}")
            ->implode("\n");

        $policy = $speaker->is_global
            ? <<<'PROMPT'
Il parlante è il Globale: unico autorizzato a unire memorie.
- Assegna un fatto a uno specialista SOLO quando corrisponde chiaramente alla sua descrizione. Se non c'è una corrispondenza precisa, non modificare quello specialista.
- Ogni specialista conserva soltanto la propria prospettiva del fatto. Non fargli parlare o memorizzare conclusioni per conto di un altro.
- La memoria del Globale deve DERIVARE dalle memorie specialistiche: salva solo sintesi trasversali o connessioni supportate da almeno due fatti specialistici esistenti o creati nello stesso risultato.
- Un fatto relativo a un solo ambito appartiene soltanto allo specialista pertinente, non al Globale.
- Non duplicare nel Globale il testo delle memorie specialistiche e non creare sintesi prive di evidenza.
PROMPT
            : <<<PROMPT
Il parlante è lo specialista {$speaker->slug}.
- Puoi scrivere o disattivare SOLO memorie con character "{$speaker->slug}".
- Non unire, copiare o correggere memorie di altri specialisti.
- Ignora qualsiasi fatto che non rientra nel suo ambito.
- Arricchisci nel tempo la sua prospettiva: integra precisazioni e sviluppi nella memoria esistente pertinente.
PROMPT;

        $summaryPolicy = $fullTranscript
            ? 'Il campo summary deve essere un riepilogo finale dettagliato e autonomo: argomenti trattati, fatti importanti, decisioni, preferenze, vincoli, sviluppi e conclusioni. Non omettere elementi utili solo perché sono già nelle memorie.'
            : 'Il campo summary deve aggiornare sinteticamente la continuità della chat.';

        return <<<PROMPT
Sei il motore di memoria di Life Assistant. Estrai soltanto fatti durevoli e utili nel tempo: preferenze, vincoli, decisioni, scadenze ricorrenti, relazioni, obiettivi. Non salvare saluti, consigli dell'assistente, ipotesi o dettagli che scadono in poche ore.

{$policy}

{$summaryPolicy}

Destinatari autorizzati:
{$targets}

Le MEMORIE ESISTENTI sono lo stato corrente, non nuove osservazioni:
- riusa sempre la stessa key quando i nuovi messaggi parlano dello stesso soggetto, anche con parole diverse;
- con upsert restituisci un contenuto autonomo e completo che unisce ciò che resta valido con la nuova precisazione;
- correggi il contenuto quando i nuovi messaggi smentiscono o sostituiscono un dettaglio;
- aumenta confidence o importance solo quando i nuovi messaggi forniscono conferme reali;
- non emettere alcuna modifica se i nuovi messaggi non aggiungono, correggono o smentiscono nulla;
- usa deactivate soltanto quando l'intero fatto non è più valido; se cambia solo un dettaglio, usa upsert sulla stessa key.
Se c'è un RIASSUNTO GIÀ NOTO, aggiornalo in "summary" in modo cumulativo: conserva i fatti ancora veri e incorpora i nuovi, senza ripetere tutta la chat.

Rispondi esclusivamente con JSON:
{
  "title": "titolo breve di 2-6 parole se richiesto, altrimenti null",
  "summary": "riassunto persistente di questa chat, utile per la continuità",
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
}
