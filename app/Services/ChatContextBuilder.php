<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Conversation;
use App\Models\Memory;
use Illuminate\Database\Eloquent\Collection;

class ChatContextBuilder
{
    /**
     * @return array{system_prompt: string, messages: array<int, array{role: string, content: string}>}
     */
    public function build(Conversation $conversation, ?string $externalContext = null): array
    {
        $conversation->loadMissing('character');

        // reorder() scarta l'ordinamento cronologico della relazione: qui servono gli ultimi messaggi, non i primi.
        $messages = $conversation->messages()
            ->select(['id', 'conversation_id', 'role', 'content'])
            ->reorder()
            ->latest('id')
            ->limit((int) config('ai.max_history_messages', 30))
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($message): array => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->all();

        $memoryContext = $conversation->character->is_global
            ? $this->globalMemoryContext($conversation->character)
            : $this->specialistMemoryContext($conversation->character);

        $systemPrompt = implode("\n\n", array_filter([
            $conversation->character->system_prompt,
            'Tono: '.$conversation->character->tone,
            $this->routingContext($conversation->character),
            $memoryContext,
            $externalContext,
        ]));

        return [
            'system_prompt' => $systemPrompt,
            'messages' => $messages,
        ];
    }

    private function routingContext(Character $character): string
    {
        $others = Character::query()
            ->where('user_id', $character->user_id)
            ->whereKeyNot($character->getKey())
            ->orderBy('sort_order')
            ->get();

        if ($character->is_global) {
            $domains = $others
                ->map(fn (Character $other): string => "- {$other->name}: {$other->description}")
                ->implode("\n");

            return <<<PROMPT
            AMBITO E SMISTAMENTO
            Copri in modo multidisciplinare tutti gli ambiti degli assistenti specializzati:
            {$domains}
            Se la richiesta non rientra in nessuno di questi ambiti, non rispondere nel merito: dichiara in una frase che non è di tua competenza e fermati lì. Fanno eccezione i saluti e le domande su chi sei o su cosa puoi fare, a cui rispondi sempre brevemente.
            PROMPT;
        }

        $roster = $others
            ->map(fn (Character $other): string => "- {$other->name}: {$other->description}")
            ->implode("\n");

        $handoff = $roster === ''
            ? 'Non esistono altri assistenti a cui indirizzare l\'utente.'
            : "Altri assistenti disponibili:\n{$roster}\nSe la richiesta rientra nell'ambito di uno di loro, indirizza l'utente a quell'assistente chiamandolo per nome. Se tocca più ambiti insieme, indirizzalo all'assistente globale.";

        return <<<PROMPT
        AMBITO E SMISTAMENTO
        Il tuo ambito è: {$character->description}.
        {$handoff}
        Quando la richiesta non rientra nel tuo ambito non rispondere mai nel merito, nemmeno parzialmente e nemmeno se conosci la risposta: dichiara in una frase che non è di tua competenza e, se esiste l'assistente adatto, indica a chi rivolgersi. Se nessun assistente copre l'argomento, di' che non rientra nelle competenze di Life Assistant. Fanno eccezione i saluti e le domande su chi sei o su cosa puoi fare, a cui rispondi sempre brevemente.
        PROMPT;
    }

    private function specialistMemoryContext(Character $character): string
    {
        $memories = Memory::query()
            ->active()
            ->whereBelongsTo($character)
            ->orderByDesc('importance')
            ->orderByDesc('last_reinforced_at')
            ->orderBy('id')
            ->limit((int) config('ai.max_memories_per_character', 40))
            ->get();

        if ($memories->isEmpty()) {
            return 'MEMORIA PERSONALE: nessuna memoria salvata.';
        }

        return "MEMORIA PERSONALE (usa solo questi fatti):\n".$this->formatMemories($memories);
    }

    private function globalMemoryContext(Character $globalCharacter): string
    {
        $characters = Character::query()
            ->where('user_id', $globalCharacter->user_id)
            ->where('is_global', false)
            ->with(['memories' => function ($query): void {
                $query->active()
                    ->orderByDesc('importance')
                    ->orderByDesc('last_reinforced_at')
                    ->orderBy('id')
                    ->limit((int) config('ai.max_memories_per_character', 40));
            }])
            ->orderBy('sort_order')
            ->get();

        if ($characters->every(fn (Character $character): bool => $character->memories->isEmpty())) {
            return 'MEMORIE DEGLI SPECIALISTI: nessuna memoria salvata.';
        }

        $sections = $characters->map(function (Character $character): string {
            $memoryLines = $character->memories->isEmpty()
                ? '- Nessuna memoria'
                : $this->formatMemories($character->memories);

            return "### {$character->name}\n{$memoryLines}";
        })->implode("\n\n");

        return "MEMORIE DEGLI SPECIALISTI (sola lettura, separate per ruolo):\n{$sections}";
    }

    /**
     * @param  Collection<int, Memory>  $memories
     */
    private function formatMemories(Collection $memories): string
    {
        return $memories
            ->map(fn (Memory $memory): string => sprintf(
                '- [%s:%s|i%d] %s',
                $memory->category,
                $memory->memory_key,
                $memory->importance,
                $memory->content,
            ))
            ->implode("\n");
    }
}
