<?php

namespace App\Integrations;

use App\Contracts\RemoteIntegrationGateway;
use App\ExternalActionType;
use App\IntegrationService;
use App\Models\Conversation;
use App\Models\ServiceConnection;
use Carbon\CarbonImmutable;
use Throwable;

class GenericIntegrationChatContext
{
    public function __construct(
        private UniversalIntegrationPlanner $planner,
        private RemoteIntegrationGateway $gateway,
        private ServiceConnectionResolver $connections,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function prepare(
        IntegrationService $service,
        Conversation $conversation,
        array $messages,
    ): IntegrationPreparation {
        $connection = $this->connections->forService($conversation->user_id, $service);

        if (! $connection) {
            return new IntegrationPreparation(
                service: $service,
                context: $service->label().' non è collegato. Invita l’utente a collegarlo dal pannello Il mio account.',
            );
        }

        $timezone = (string) ($conversation->user()->value('timezone') ?: config('app.timezone'));

        try {
            $plan = $this->planner->plan($service, $messages, $timezone);
            $action = (string) $plan['action'];
        } catch (Throwable $exception) {
            report($exception);

            return new IntegrationPreparation(
                service: $service,
                context: $this->connectedText($service)."\nLa consultazione di questo turno non è riuscita per un problema tecnico momentaneo: dillo apertamente e proponi di riprovare, senza sostenere di non avere accesso al servizio.",
                connection: $connection,
                error: 'Non è stato possibile interpretare la richiesta relativa a '.$service->label().'.',
            );
        }

        if ($action === 'none') {
            return $this->connected($service, $connection);
        }

        if ($action === 'clarify') {
            $missing = implode(', ', $plan['missing'] ?: ['informazioni necessarie']);

            return $this->connected($service, $connection, "Prima di procedere chiedi: {$missing}.");
        }

        if (str_starts_with($action, 'propose_')) {
            return $this->proposal($service, $connection, $action, $plan);
        }

        $parameters = $this->readParameters($service, $action, $plan, $timezone);

        if ($parameters === null) {
            return $this->connected($service, $connection, 'Chiedi i dati mancanti senza inventarli.');
        }

        if ($action === 'top_tracks' && ! in_array('user-top-read', $connection->scopes ?? [], true)) {
            return $this->connected(
                $service,
                $connection,
                'Le classifiche di ascolto non sono autorizzate: invita l’utente a ricollegare Spotify da Il mio account per concedere il permesso.',
            );
        }

        try {
            $result = $this->gateway->read($connection, $service, $action, $parameters);
        } catch (Throwable $exception) {
            report($exception);

            return new IntegrationPreparation(
                service: $service,
                context: $this->connectedText($service).' La lettura non è riuscita: non inventare risultati.',
                connection: $connection,
                error: $service->label().' non ha risposto: '.$exception->getMessage(),
            );
        }

        $readAt = CarbonImmutable::now($timezone)->format('d/m/Y H:i');

        return $this->connected(
            $service,
            $connection,
            "Lettura appena eseguita sull'API ufficiale il {$readAt}: sono dati aggiornati a questo istante, non ricordi della conversazione. Presentali come consultazione fatta ora.\n".$this->json($result),
        );
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function proposal(
        IntegrationService $service,
        ServiceConnection $connection,
        string $action,
        array $plan,
    ): IntegrationPreparation {
        $type = $this->proposalType($service, $action);
        $payload = $this->proposalPayload($service, $action, $plan);

        if ($type === null || $payload === null) {
            return $this->connected($service, $connection, 'Mancano dati indispensabili. Chiedili prima di preparare la proposta.');
        }

        return new IntegrationPreparation(
            service: $service,
            context: $this->connectedText($service).' È stata preparata una proposta, ma nulla è stato ancora modificato. Chiedi conferma tramite la scheda.',
            connection: $connection,
            proposalType: $type,
            proposalPayload: $payload,
        );
    }

    private function connected(
        IntegrationService $service,
        ServiceConnection $connection,
        ?string $context = null,
    ): IntegrationPreparation {
        return new IntegrationPreparation(
            service: $service,
            context: $this->connectedText($service).($context ? "\n".$context : ''),
            connection: $connection,
        );
    }

    private function connectedText(IntegrationService $service): string
    {
        return strtoupper($service->label()).': integrazione attiva e interrogata a ogni messaggio, quindi puoi consultare il servizio quando serve. Usa soltanto i dati reali riportati qui sotto, non negare di avere accesso e non dichiarare completata alcuna modifica senza conferma.';
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>|null
     */
    private function readParameters(
        IntegrationService $service,
        string $action,
        array $plan,
        string $timezone,
    ): ?array {
        if ($service === IntegrationService::MicrosoftCalendar) {
            $start = $plan['start'] ?: CarbonImmutable::now($timezone)->startOfDay()->toIso8601String();
            $end = $plan['end'] ?: CarbonImmutable::parse($start)->addDays(7)->toIso8601String();

            return ['start' => $start, 'end' => $end];
        }

        if ($action === 'top_tracks') {
            $plan['range'] = $plan['range'] ?: 'medium_term';

            return $plan;
        }

        $required = match ($action) {
            'search', 'search_issues' => ['query'],
            'read', 'query_database' => ['id'],
            'thread' => ['channel', 'thread_ts'],
            'list_folder' => ['path'],
            'read_issue', 'read_pr' => ['owner', 'repo', 'number'],
            default => [],
        };

        foreach ($required as $key) {
            if ($plan[$key] === null || $plan[$key] === '') {
                return null;
            }
        }

        return $plan;
    }

    private function proposalType(IntegrationService $service, string $action): ?ExternalActionType
    {
        return match ([$service, $action]) {
            [IntegrationService::MicrosoftMail, 'propose_create_draft'] => ExternalActionType::MicrosoftCreateDraft,
            [IntegrationService::MicrosoftMail, 'propose_send_message'] => ExternalActionType::MicrosoftSendMessage,
            [IntegrationService::MicrosoftCalendar, 'propose_create_event'] => ExternalActionType::MicrosoftCreateEvent,
            [IntegrationService::MicrosoftOneDrive, 'propose_create_folder'] => ExternalActionType::OneDriveCreateFolder,
            [IntegrationService::MicrosoftOneDrive, 'propose_create_file'] => ExternalActionType::OneDriveCreateFile,
            [IntegrationService::Spotify, 'propose_add_to_playlist'] => ExternalActionType::SpotifyAddToPlaylist,
            [IntegrationService::Spotify, 'propose_add_to_queue'] => ExternalActionType::SpotifyAddToQueue,
            [IntegrationService::Spotify, 'propose_start_playback'] => ExternalActionType::SpotifyStartPlayback,
            [IntegrationService::Notion, 'propose_create_page'] => ExternalActionType::NotionCreatePage,
            [IntegrationService::Notion, 'propose_append_blocks'] => ExternalActionType::NotionAppendBlocks,
            [IntegrationService::Slack, 'propose_post_message'] => ExternalActionType::SlackPostMessage,
            [IntegrationService::Slack, 'propose_reply'] => ExternalActionType::SlackReply,
            [IntegrationService::Dropbox, 'propose_create_folder'] => ExternalActionType::DropboxCreateFolder,
            [IntegrationService::Dropbox, 'propose_upload_text'] => ExternalActionType::DropboxUploadText,
            [IntegrationService::GitHub, 'propose_create_issue'] => ExternalActionType::GitHubCreateIssue,
            [IntegrationService::GitHub, 'propose_create_comment'] => ExternalActionType::GitHubCreateComment,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>|null
     */
    private function proposalPayload(IntegrationService $service, string $action, array $plan): ?array
    {
        $keys = match ([$service, $action]) {
            [IntegrationService::MicrosoftMail, 'propose_create_draft'],
            [IntegrationService::MicrosoftMail, 'propose_send_message'] => ['to', 'cc', 'subject', 'body'],
            [IntegrationService::MicrosoftCalendar, 'propose_create_event'] => ['title', 'start', 'end', 'timezone', 'location', 'description'],
            [IntegrationService::MicrosoftOneDrive, 'propose_create_folder'] => ['name', 'parent_id'],
            [IntegrationService::MicrosoftOneDrive, 'propose_create_file'] => ['name', 'content', 'parent_id'],
            [IntegrationService::Spotify, 'propose_add_to_playlist'] => ['playlist_id', 'uris'],
            [IntegrationService::Spotify, 'propose_add_to_queue'] => ['uri', 'device_id'],
            [IntegrationService::Spotify, 'propose_start_playback'] => ['uris', 'context_uri', 'device_id'],
            [IntegrationService::Notion, 'propose_create_page'] => ['parent_id', 'title', 'content'],
            [IntegrationService::Notion, 'propose_append_blocks'] => ['id', 'content'],
            [IntegrationService::Slack, 'propose_post_message'] => ['channel', 'content'],
            [IntegrationService::Slack, 'propose_reply'] => ['channel', 'thread_ts', 'content'],
            [IntegrationService::Dropbox, 'propose_create_folder'] => ['path'],
            [IntegrationService::Dropbox, 'propose_upload_text'] => ['path', 'content'],
            [IntegrationService::GitHub, 'propose_create_issue'] => ['owner', 'repo', 'title', 'body'],
            [IntegrationService::GitHub, 'propose_create_comment'] => ['owner', 'repo', 'number', 'body'],
            default => [],
        };
        $payload = collect($keys)->mapWithKeys(fn (string $key): array => [$key => $plan[$key] ?? null])->all();
        $payload = $this->normalizePayload($service, $action, $payload);

        foreach ($this->requiredProposalKeys($service, $action) as $key) {
            if (($payload[$key] ?? null) === null || ($payload[$key] ?? null) === '' || ($payload[$key] ?? null) === []) {
                return null;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(IntegrationService $service, string $action, array $payload): array
    {
        if ($service === IntegrationService::MicrosoftCalendar) {
            $payload['summary'] = $payload['title'];
            unset($payload['title']);
        }

        if ($service === IntegrationService::Notion && $action === 'propose_append_blocks') {
            $payload['page_id'] = $payload['id'];
            unset($payload['id']);
        }

        if ($service === IntegrationService::Slack) {
            $payload['text'] = $payload['content'];
            unset($payload['content']);
        }

        return $payload;
    }

    /**
     * @return array<int, string>
     */
    private function requiredProposalKeys(IntegrationService $service, string $action): array
    {
        return match ([$service, $action]) {
            [IntegrationService::MicrosoftMail, 'propose_create_draft'],
            [IntegrationService::MicrosoftMail, 'propose_send_message'] => ['to', 'subject', 'body'],
            [IntegrationService::MicrosoftCalendar, 'propose_create_event'] => ['summary', 'start', 'end', 'timezone'],
            [IntegrationService::MicrosoftOneDrive, 'propose_create_folder'] => ['name'],
            [IntegrationService::MicrosoftOneDrive, 'propose_create_file'] => ['name', 'content'],
            [IntegrationService::Spotify, 'propose_add_to_playlist'] => ['playlist_id', 'uris'],
            [IntegrationService::Spotify, 'propose_add_to_queue'] => ['uri'],
            [IntegrationService::Spotify, 'propose_start_playback'] => [],
            [IntegrationService::Notion, 'propose_create_page'] => ['parent_id', 'title'],
            [IntegrationService::Notion, 'propose_append_blocks'] => ['page_id', 'content'],
            [IntegrationService::Slack, 'propose_post_message'] => ['channel', 'text'],
            [IntegrationService::Slack, 'propose_reply'] => ['channel', 'thread_ts', 'text'],
            [IntegrationService::Dropbox, 'propose_create_folder'] => ['path'],
            [IntegrationService::Dropbox, 'propose_upload_text'] => ['path', 'content'],
            [IntegrationService::GitHub, 'propose_create_issue'] => ['owner', 'repo', 'title'],
            [IntegrationService::GitHub, 'propose_create_comment'] => ['owner', 'repo', 'number', 'body'],
            default => [],
        };
    }

    private function json(array $value): string
    {
        $json = (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return mb_substr($json, 0, (int) config('integrations.max_content_characters', 12000));
    }
}
