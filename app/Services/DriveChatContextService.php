<?php

namespace App\Services;

use App\Contracts\DriveGateway;
use App\DriveIntent;
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

class DriveChatContextService
{
    private const CONNECTED = 'GOOGLE DRIVE: l’integrazione è attiva. Usa soltanto file e contenuti riportati nel contesto; non dichiarare di aver creato nulla senza una proposta confermata.';

    public function __construct(
        private DriveIntentPlanner $planner,
        private DriveGateway $drive,
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
            $intent = DriveIntent::from($plan['intent']);
        } catch (Throwable $exception) {
            report($exception);

            return $this->result(
                $connection,
                error: 'Non è stato possibile interpretare la richiesta relativa a Google Drive.',
            );
        }

        try {
            return match ($intent) {
                DriveIntent::None => $this->result($connection),
                DriveIntent::Clarify => $this->result(
                    $connection,
                    'Per usare Drive mancano: '.implode(', ', $plan['missing']).'. Chiedi questi dati.',
                ),
                DriveIntent::Search => $this->searchResult($connection, $plan['query']),
                DriveIntent::Read => $this->readResult($connection, $plan['file_id']),
                DriveIntent::ProposeCreateFolder => $this->writeResult(
                    $connection,
                    ExternalActionType::DriveCreateFolder,
                    ['name' => $plan['name'], 'parent_id' => $plan['parent_id']],
                ),
                DriveIntent::ProposeCreateDocument => $this->writeResult(
                    $connection,
                    ExternalActionType::DriveCreateDocument,
                    ['name' => $plan['name'], 'content' => $plan['content'], 'parent_id' => $plan['parent_id']],
                ),
            };
        } catch (Throwable $exception) {
            report($exception);
            $reason = $exception instanceof GoogleGatewayException
                ? $exception->getMessage()
                : 'servizio non raggiungibile';

            return $this->result($connection, error: 'Google Drive non ha risposto: '.$reason.'.');
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
            'payload' => $payload,
            'expires_at' => now()->addDay(),
        ]);
    }

    private function searchResult(ServiceConnection $connection, ?string $query): array
    {
        if ($query === null) {
            return $this->result($connection, 'Chiedi cosa cercare su Drive.');
        }

        return $this->result(
            $connection,
            "Risultati reali da Drive:\n".$this->json($this->drive->search($connection, $query)),
        );
    }

    private function readResult(ServiceConnection $connection, ?string $fileId): array
    {
        if ($fileId === null) {
            return $this->result($connection, 'Chiedi quale file leggere.');
        }

        return $this->result(
            $connection,
            "File reale da Drive:\n".$this->json($this->drive->read($connection, $fileId)),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeResult(
        ServiceConnection $connection,
        ExternalActionType $type,
        array $payload,
    ): array {
        if (! is_string($payload['name'] ?? null) || trim($payload['name']) === '') {
            return $this->result($connection, 'Chiedi il nome della risorsa da creare su Drive.');
        }

        return $this->result(
            $connection,
            'È stata preparata una proposta Drive. La risorsa non esiste ancora: chiedi conferma tramite la scheda.',
            $type,
            $payload,
        );
    }

    private function connection(Conversation $conversation): ?ServiceConnection
    {
        return $this->connections->forService($conversation->user_id, IntegrationService::GoogleDrive);
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
