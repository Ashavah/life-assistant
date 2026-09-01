<?php

namespace App\Services;

use App\CalendarIntent;
use App\Contracts\CalendarGateway;
use App\Exceptions\CalendarGatewayException;
use App\Exceptions\GoogleGatewayException;
use App\ExternalActionStatus;
use App\ExternalActionType;
use App\Integrations\ServiceConnectionResolver;
use App\IntegrationService;
use App\Models\Conversation;
use App\Models\ExternalActionProposal;
use App\Models\Message;
use App\Models\ServiceConnection;
use Illuminate\Support\Str;
use Throwable;

class CalendarChatContextService
{
    /**
     * Riga sempre presente quando l'integrazione è attiva: senza di essa il personaggio
     * risponde di non avere accesso all'agenda ogni volta che non serve leggere eventi.
     */
    private const CONNECTED_CONTEXT = 'GOOGLE CALENDAR: l’integrazione è attiva per te e puoi consultare l’agenda dell’utente. Non dire mai di non avere accesso al calendario. Usa solo gli eventi elencati qui sotto, se presenti.';

    public function __construct(
        private CalendarIntentPlanner $planner,
        private CalendarGateway $calendar,
        private ServiceConnectionResolver $connections,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{context: string|null, connection: ServiceConnection|null, proposal_payload: array<string, mixed>|null, error: string|null}
     */
    public function prepare(Conversation $conversation, array $messages): array
    {
        $connection = $this->connections->forService(
            $conversation->user_id,
            IntegrationService::GoogleCalendar,
        );

        if (! $connection) {
            return $this->emptyResult();
        }

        $timezone = (string) ($conversation->user()->value('timezone') ?: config('app.timezone'));

        try {
            $plan = $this->planner->plan($messages, $timezone);
            $intent = CalendarIntent::from($plan['intent']);
        } catch (Throwable $exception) {
            report($exception);

            return $this->result(
                $connection,
                'Non è stato possibile capire se questa richiesta riguarda l’agenda. Se l’utente sta chiedendo del calendario, invitalo a riformulare.',
                error: 'Non è stato possibile interpretare la richiesta di calendario. Riprova a inviare il messaggio.',
            );
        }

        if ($intent === CalendarIntent::None) {
            return $this->result($connection);
        }

        if ($intent === CalendarIntent::Clarify) {
            return $this->result(
                $connection,
                "Per preparare l'evento mancano: ".implode(', ', $plan['missing']).'. Chiedi questi dettagli senza inventarli.',
            );
        }

        try {
            $events = $this->calendar->eventsBetween($connection, $plan['start'], $plan['end']);
        } catch (Throwable $exception) {
            report($exception);
            $reason = $this->failureReason($exception);

            return $this->result(
                $connection,
                "La lettura dell'agenda non è riuscita ({$reason}). Dillo chiaramente senza inventare eventi.",
                error: 'Google Calendar non ha risposto: '.$reason.'.',
            );
        }

        $eventsContext = $this->formatEvents($events);

        if ($intent === CalendarIntent::List) {
            return $this->result(
                $connection,
                "Eventi reali nella finestra richiesta:\n{$eventsContext}",
            );
        }

        $payload = [
            'summary' => $plan['summary'],
            'start' => $plan['start']->toIso8601String(),
            'end' => $plan['end']->toIso8601String(),
            'timezone' => $plan['timezone'],
            'location' => $plan['location'],
            'description' => $plan['description'],
            'conflicts' => $events,
        ];

        return $this->result(
            $connection,
            "È stata preparata una proposta, ma NON è ancora stata creata. Chiedi conferma tramite la scheda mostrata nell'interfaccia. Eventi sovrapposti:\n{$eventsContext}",
            $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createProposal(
        Conversation $conversation,
        Message $message,
        ServiceConnection $connection,
        array $payload,
    ): ExternalActionProposal {
        return ExternalActionProposal::query()->create([
            'service_connection_id' => $connection->id,
            'character_id' => $conversation->character_id,
            'conversation_id' => $conversation->id,
            'source_message_id' => $message->id,
            'type' => ExternalActionType::CalendarCreateEvent,
            'status' => ExternalActionStatus::Pending,
            'idempotency_key' => 'la'.substr(hash('sha256', (string) Str::uuid()), 0, 40),
            'payload' => $payload,
            'expires_at' => now()->addDay(),
        ]);
    }

    /**
     * @param  array<int, array{id: string, summary: string, start: string, end: string, all_day: bool, location: string|null, html_link: string|null}>  $events
     */
    private function formatEvents(array $events): string
    {
        if ($events === []) {
            return '- Nessun evento';
        }

        return collect($events)
            ->map(fn (array $event): string => sprintf(
                '- %s — %s / %s%s',
                $event['summary'],
                $event['start'],
                $event['end'],
                $event['location'] ? ' — '.$event['location'] : '',
            ))
            ->implode("\n");
    }

    /**
     * @param  array<string, mixed>|null  $proposalPayload
     * @return array{context: string|null, connection: ServiceConnection|null, proposal_payload: array<string, mixed>|null, error: string|null}
     */
    private function result(
        ServiceConnection $connection,
        ?string $context = null,
        ?array $proposalPayload = null,
        ?string $error = null,
    ): array {
        return [
            'context' => $context === null
                ? self::CONNECTED_CONTEXT
                : self::CONNECTED_CONTEXT."\n".$context,
            'connection' => $connection,
            'proposal_payload' => $proposalPayload,
            'error' => $error,
        ];
    }

    private function failureReason(Throwable $exception): string
    {
        return $exception instanceof CalendarGatewayException || $exception instanceof GoogleGatewayException
            ? $exception->getMessage()
            : 'il servizio non è raggiungibile in questo momento';
    }

    /**
     * @return array{context: null, connection: null, proposal_payload: null, error: null}
     */
    private function emptyResult(): array
    {
        return [
            'context' => null,
            'connection' => null,
            'proposal_payload' => null,
            'error' => null,
        ];
    }
}
