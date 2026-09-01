<?php

namespace App\Services;

use App\DriveIntent;

class DriveIntentPlanner
{
    public function __construct(private AiChatClient $client) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{intent: string, query: string|null, file_id: string|null, name: string|null, content: string|null, parent_id: string|null, missing: array<int, string>}
     */
    public function plan(array $messages): array
    {
        $result = $this->client->completeStructured($messages, <<<'PROMPT'
Sei un pianificatore per Google Drive. Analizza soprattutto l'ultima richiesta.
Intent ammesse:
- none: Drive non serve;
- search: cercare file o cartelle (compila query);
- read: leggere un file noto dal contesto (compila file_id);
- propose_create_folder: creare una cartella (compila name e parent_id se noto);
- propose_create_document: creare un documento Google (compila name, content e parent_id se noto);
- clarify: serve Drive ma mancano dati indispensabili.
Non inventare ID. Le creazioni saranno eseguite solo dopo conferma.
Restituisci esclusivamente JSON:
{"intent":"none|search|read|propose_create_folder|propose_create_document|clarify","query":null,"file_id":null,"name":null,"content":null,"parent_id":null,"missing":[]}
PROMPT);

        $intent = DriveIntent::tryFrom((string) ($result['intent'] ?? '')) ?? DriveIntent::None;

        return [
            'intent' => $intent->value,
            'query' => $this->string($result['query'] ?? null),
            'file_id' => $this->string($result['file_id'] ?? null),
            'name' => $this->string($result['name'] ?? null),
            'content' => $this->string($result['content'] ?? null),
            'parent_id' => $this->string($result['parent_id'] ?? null),
            'missing' => is_array($result['missing'] ?? null)
                ? array_values(array_filter($result['missing'], 'is_string'))
                : [],
        ];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
