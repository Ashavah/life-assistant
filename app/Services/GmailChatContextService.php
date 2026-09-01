<?php

namespace App\Services;

use App\Contracts\GmailGateway;
use App\Exceptions\GoogleGatewayException;
use App\ExternalActionStatus;
use App\ExternalActionType;
use App\GmailIntent;
use App\Integrations\ServiceConnectionResolver;
use App\IntegrationService;
use App\Models\Conversation;
use App\Models\ExternalActionProposal;
use App\Models\Message;
use App\Models\ServiceConnection;
use Illuminate\Support\Str;
use Throwable;

class GmailChatContextService
{
    private const CONNECTED = 'GMAIL: l’integrazione è attiva. Usa soltanto le email riportate nel contesto; non dichiarare di aver creato bozze o inviato messaggi senza una proposta confermata.';

    public function __construct(
        private GmailIntentPlanner $planner,
        private GmailGateway $gmail,
        private ServiceConnectionResolver $connections,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{context: string|null, connection: ServiceConnection|null, proposal_type: ExternalActionType|null, proposal_payload: array<string, mixed>|null, error: string|null}
     */
    public function prepare(Conversation $conversation, array $messages): array
    {
        $connection = $this->connection($conversation);

        if (! $connection) {
            return $this->result();
        }

        try {
            $plan = $this->planner->plan($messages);
            $intent = GmailIntent::from($plan['intent']);
        } catch (Throwable $exception) {
            report($exception);

            return $this->result(
                $connection,
                error: 'Non è stato possibile interpretare la richiesta relativa a Gmail.',
            );
        }

        try {
            return match ($intent) {
                GmailIntent::None => $this->result($connection),
                GmailIntent::Clarify => $this->result(
                    $connection,
                    'Per usare Gmail mancano: '.implode(', ', $plan['missing']).'. Chiedi questi dati.',
                ),
                GmailIntent::Search => $this->searchResult($connection, $plan['query']),
                GmailIntent::Read => $this->readResult($connection, $plan['message_id']),
                GmailIntent::ProposeDraft => $this->writeResult(
                    $connection,
                    ExternalActionType::GmailCreateDraft,
                    $plan,
                ),
                GmailIntent::ProposeSend => $this->writeResult(
                    $connection,
                    ExternalActionType::GmailSendMessage,
                    $plan,
                ),
            };
        } catch (Throwable $exception) {
            report($exception);
            $reason = $exception instanceof GoogleGatewayException
                ? $exception->getMessage()
                : 'servizio non raggiungibile';

            return $this->result($connection, error: 'Gmail non ha risposto: '.$reason.'.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createProposal(
        Conversation $conversation,
        Message $message,
        ServiceConnection $connection,
        ExternalActionType $type,
        array $payload,
    ): ExternalActionProposal {
        return ExternalActionProposal::query()->create([
            'service_connection_id' => $connection->id,
            'character_id' => $conversation->character_id,
            'conversation_id' => $conversation->id,
            'source_message_id' => $message->id,
            'type' => $type,
            'status' => ExternalActionStatus::Pending,
            'idempotency_key' => 'la'.substr(hash('sha256', (string) Str::uuid()), 0, 40),
            'payload' => [
                'to' => $payload['to'],
                'cc' => $payload['cc'],
                'subject' => $payload['subject'],
                'body' => $payload['body'],
            ],
            'expires_at' => now()->addDay(),
        ]);
    }

    private function searchResult(ServiceConnection $connection, ?string $query): array
    {
        if ($query === null) {
            return $this->result($connection, 'Chiedi quali email cercare.');
        }

        return $this->result(
            $connection,
            "Risultati reali da Gmail:\n".$this->json($this->gmail->search($connection, $query)),
        );
    }

    private function readResult(ServiceConnection $connection, ?string $messageId): array
    {
        if ($messageId === null) {
            return $this->result($connection, 'Chiedi quale email leggere.');
        }

        return $this->result(
            $connection,
            "Email reale da Gmail:\n".$this->json($this->gmail->read($connection, $messageId)),
        );
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function writeResult(
        ServiceConnection $connection,
        ExternalActionType $type,
        array $plan,
    ): array {
        if ($plan['to'] === [] || $plan['subject'] === null || $plan['body'] === null) {
            return $this->result($connection, 'Chiedi destinatario, oggetto e contenuto dell’email.');
        }

        return $this->result(
            $connection,
            'È stata preparata una proposta Gmail. Nessuna bozza o email è stata ancora creata: chiedi conferma tramite la scheda.',
            $type,
            $plan,
        );
    }

    private function connection(Conversation $conversation): ?ServiceConnection
    {
        return $this->connections->forService($conversation->user_id, IntegrationService::GoogleGmail);
    }

    /**
     * @return array{context: string|null, connection: ServiceConnection|null, proposal_type: ExternalActionType|null, proposal_payload: array<string, mixed>|null, error: string|null}
     */
    private function result(
        ?ServiceConnection $connection = null,
        ?string $context = null,
        ?ExternalActionType $proposalType = null,
        ?array $proposalPayload = null,
        ?string $error = null,
    ): array {
        return [
            'context' => $connection ? self::CONNECTED.($context ? "\n".$context : '') : null,
            'connection' => $connection,
            'proposal_type' => $proposalType,
            'proposal_payload' => $proposalPayload,
            'error' => $error,
        ];
    }

    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
