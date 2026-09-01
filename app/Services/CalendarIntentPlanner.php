<?php

namespace App\Services;

use App\CalendarIntent;
use Carbon\CarbonImmutable;
use Throwable;

class CalendarIntentPlanner
{
    public function __construct(private AiChatClient $client) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{intent: string, summary: string|null, start: CarbonImmutable|null, end: CarbonImmutable|null, timezone: string, location: string|null, description: string|null, missing: array<int, string>}
     */
    public function plan(array $messages, string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);
        $result = $this->client->completeStructured(
            $messages,
            <<<PROMPT
            Sei un pianificatore per Google Calendar. Analizza la richiesta più recente usando anche la conversazione precedente.
            Data e ora corrente: {$now->toIso8601String()}. Fuso orario: {$timezone}.

            Scegli una sola intent:
            - none: non serve accedere al calendario;
            - list: l'utente chiede eventi, disponibilità o pianificazione basata sull'agenda;
            - propose_create: l'utente chiede esplicitamente di creare/fissare/aggiungere un evento e sono presenti titolo, data e ora;
            - clarify: vuole creare un evento ma mancano informazioni indispensabili.

            Restituisci esclusivamente JSON:
            {
              "intent": "none|list|propose_create|clarify",
              "summary": "titolo evento o null",
              "start": "data ISO 8601 con offset o null",
              "end": "data ISO 8601 con offset o null",
              "timezone": "{$timezone}",
              "location": "luogo o null",
              "description": "note utili o null",
              "missing": ["campi mancanti"]
            }
            Per list, start/end delimitano la finestra richiesta; se non specificata usa i prossimi 7 giorni.
            Per propose_create, usa durata predefinita di 60 minuti soltanto se manca la durata. Non inventare data, ora o titolo.
            PROMPT,
        );

        $intent = CalendarIntent::tryFrom((string) ($result['intent'] ?? '')) ?? CalendarIntent::None;
        $start = $this->date($result['start'] ?? null, $timezone);
        $end = $this->date($result['end'] ?? null, $timezone);
        $missing = is_array($result['missing'] ?? null)
            ? array_values(array_filter($result['missing'], 'is_string'))
            : [];
        $summary = $this->nullableString($result['summary'] ?? null);

        if ($intent === CalendarIntent::List) {
            $start ??= $now->startOfDay();
            $end ??= $start->addDays(7);
            $end = $end->min($start->addDays(90));
        }

        if ($intent === CalendarIntent::ProposeCreate && (! $summary || ! $start || ! $end || $end->lessThanOrEqualTo($start))) {
            $intent = CalendarIntent::Clarify;
            $missing = $missing ?: ['titolo, data, ora di inizio e durata'];
        }

        return [
            'intent' => $intent->value,
            'summary' => $summary,
            'start' => $start,
            'end' => $end,
            'timezone' => $timezone,
            'location' => $this->nullableString($result['location'] ?? null),
            'description' => $this->nullableString($result['description'] ?? null),
            'missing' => $missing,
        ];
    }

    private function date(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $timezone)->setTimezone($timezone);
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
