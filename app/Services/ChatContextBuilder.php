<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Conversation;
use App\Models\Memory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

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

        $others = Character::query()
            ->where('user_id', $conversation->character->user_id)
            ->whereKeyNot($conversation->character->getKey())
            ->orderBy('sort_order')
            ->get();

        $systemPrompt = implode("\n\n", array_filter([
            $conversation->character->system_prompt,
            'Tono: '.$conversation->character->tone,
            $this->routingContext($conversation->character, $others),
            $this->conversationContinuity($conversation),
            $this->memoryContext($conversation->character, $others, $messages),
            $externalContext,
        ]));

        return [
            'system_prompt' => $systemPrompt,
            'messages' => $messages,
        ];
    }

    /**
     * @param  Collection<int, Character>  $others
     */
    private function routingContext(Character $character, Collection $others): string
    {
        if ($character->is_global) {
            $domains = $others
                ->map(fn (Character $other): string => "- {$other->name}: {$other->description}")
                ->implode("\n");

            return <<<PROMPT
            AMBITO E MEMORIA
            Copri in modo multidisciplinare tutti gli ambiti degli assistenti specializzati:
            {$domains}
            Hai una memoria propria per fatti trasversali e sintesi. Vedi anche le memorie degli specialisti, etichettate per ruolo: usale in sola lettura per rispondere, senza parlare a nome loro.
            Solo tu puoi unire o distribuire fatti tra specialisti: lo fa il motore di memoria, non inventare di aver modificato le loro memorie.
            Se la richiesta non rientra in nessuno di questi ambiti, non rispondere nel merito: dichiara in una frase che non è di tua competenza e fermati lì. Fanno eccezione i saluti e le domande su chi sei o su cosa puoi fare, a cui rispondi sempre brevemente.
            PROMPT;
        }

        $roster = $others
            ->map(fn (Character $other): string => "- {$other->name}: {$other->description}")
            ->implode("\n");

        $handoff = $roster === ''
            ? 'Non esistono altri assistenti a cui indirizzare l\'utente.'
            : "Altri assistenti disponibili:\n{$roster}\nSe la richiesta è interamente fuori dal tuo ambito, indirizza l'utente a quell'assistente chiamandolo per nome. Se tocca più ambiti insieme, indirizzalo all'assistente globale.\nPuoi consultare i fatti di un collega SOLO se l'utente chiede un confronto o lo nomina esplicitamente. In quel caso usa quei fatti per il confronto nel TUO ambito: non rispondere al posto loro e non attribuirti il loro ruolo.";

        return <<<PROMPT
        AMBITO E MEMORIA
        Il tuo ambito è: {$character->description}.
        La tua memoria è solo tua: costruiscila e usala per la continuità, senza rileggere tutte le chat precedenti.
        {$handoff}
        Quando la richiesta non rientra nel tuo ambito non rispondere mai nel merito, nemmeno parzialmente e nemmeno se conosci la risposta: dichiara in una frase che non è di tua competenza e, se esiste l'assistente adatto, indica a chi rivolgersi. Se nessun assistente copre l'argomento, di' che non rientra nelle competenze di Life Assistant. Fanno eccezione i saluti e le domande su chi sei o su cosa puoi fare, a cui rispondi sempre brevemente.
        PROMPT;
    }

    private function conversationContinuity(Conversation $conversation): ?string
    {
        if (! filled($conversation->context_summary)) {
            return null;
        }

        return "CONTESTO DI QUESTA CHAT (riassunto persistente; i messaggi sotto sono solo il tratto recente):\n{$conversation->context_summary}";
    }

    /**
     * @param  Collection<int, Character>  $others
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function memoryContext(Character $character, Collection $others, array $messages): string
    {
        if ($character->is_global) {
            return $this->globalMemoryContext($character);
        }

        $own = $this->specialistMemoryContext($character);
        $consults = $this->consultContext($others, $messages);

        return implode("\n\n", array_filter([$own, $consults]));
    }

    private function specialistMemoryContext(Character $character): string
    {
        $memories = $this->activeMemories($character);

        if ($memories->isEmpty()) {
            return 'MEMORIA PERSONALE: nessuna memoria salvata.';
        }

        return "MEMORIA PERSONALE (usa solo questi fatti, sono il tuo contesto duraturo):\n".$this->formatMemories($memories);
    }

    private function globalMemoryContext(Character $globalCharacter): string
    {
        $own = $this->activeMemories($globalCharacter);
        $ownSection = $own->isEmpty()
            ? 'MEMORIA GLOBALE: nessuna memoria propria salvata.'
            : "MEMORIA GLOBALE (fatti trasversali e sintesi tue):\n".$this->formatMemories($own);

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
            return $ownSection."\n\nMEMORIE DEGLI SPECIALISTI: nessuna memoria salvata.";
        }

        $sections = $characters->map(function (Character $character): string {
            $memoryLines = $character->memories->isEmpty()
                ? '- Nessuna memoria'
                : $this->formatMemories($character->memories);

            return "### {$character->name}\n{$memoryLines}";
        })->implode("\n\n");

        return $ownSection."\n\nMEMORIE DEGLI SPECIALISTI (sola lettura, separate per ruolo; non parlare a nome loro):\n{$sections}";
    }

    /**
     * @param  Collection<int, Character>  $others
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function consultContext(Collection $others, array $messages): ?string
    {
        $lastUser = collect($messages)
            ->reverse()
            ->first(fn (array $message): bool => $message['role'] === 'user');
        $text = Str::lower((string) ($lastUser['content'] ?? ''));

        if ($text === '') {
            return null;
        }

        $consulted = $others->filter(function (Character $other) use ($text): bool {
            return str_contains($text, Str::lower($other->name))
                || str_contains($text, Str::lower($other->slug));
        });

        if ($consulted->isEmpty()) {
            return null;
        }

        $sections = $consulted->map(function (Character $other): string {
            $memories = $this->activeMemories($other);
            $lines = $memories->isEmpty()
                ? '- Nessuna memoria'
                : $this->formatMemories($memories);

            return "### {$other->name}\n{$lines}";
        })->implode("\n\n");

        return "CONSULTO COLLEGHI (sola lettura, chiesto in questo messaggio). Usali per un confronto nel tuo ambito; non parlare a nome loro e non copiarli nella tua memoria:\n{$sections}";
    }

    /**
     * @return Collection<int, Memory>
     */
    private function activeMemories(Character $character): Collection
    {
        return Memory::query()
            ->active()
            ->whereBelongsTo($character)
            ->orderByDesc('importance')
            ->orderByDesc('last_reinforced_at')
            ->orderBy('id')
            ->limit((int) config('ai.max_memories_per_character', 40))
            ->get();
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
