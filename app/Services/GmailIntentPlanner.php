<?php

namespace App\Services;

use App\GmailIntent;

class GmailIntentPlanner
{
    public function __construct(private AiChatClient $client) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{intent: string, query: string|null, message_id: string|null, to: array<int, string>, cc: array<int, string>, subject: string|null, body: string|null, missing: array<int, string>}
     */
    public function plan(array $messages): array
    {
        $result = $this->client->completeStructured($messages, <<<'PROMPT'
Sei un pianificatore per Gmail. Analizza soprattutto l'ultima richiesta.
Intent ammesse:
- none: Gmail non serve;
- search: cercare email (compila query usando la sintassi di ricerca Gmail);
- read: leggere un messaggio noto dal contesto (compila message_id);
- propose_draft: preparare una bozza (to, cc, subject, body);
- propose_send: inviare un'email (to, cc, subject, body);
- clarify: serve Gmail ma mancano destinatari, oggetto o contenuto.
Non inventare indirizzi o ID. Bozze e invii saranno eseguiti solo dopo conferma.
Restituisci esclusivamente JSON:
{"intent":"none|search|read|propose_draft|propose_send|clarify","query":null,"message_id":null,"to":[],"cc":[],"subject":null,"body":null,"missing":[]}
PROMPT);

        $intent = GmailIntent::tryFrom((string) ($result['intent'] ?? '')) ?? GmailIntent::None;

        return [
            'intent' => $intent->value,
            'query' => $this->string($result['query'] ?? null),
            'message_id' => $this->string($result['message_id'] ?? null),
            'to' => $this->strings($result['to'] ?? []),
            'cc' => $this->strings($result['cc'] ?? []),
            'subject' => $this->string($result['subject'] ?? null),
            'body' => $this->string($result['body'] ?? null),
            'missing' => $this->strings($result['missing'] ?? []),
        ];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<int, string>
     */
    private function strings(mixed $values): array
    {
        return is_array($values)
            ? array_values(array_filter($values, fn (mixed $value): bool => is_string($value) && trim($value) !== ''))
            : [];
    }
}
