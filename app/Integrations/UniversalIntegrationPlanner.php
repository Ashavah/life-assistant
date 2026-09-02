<?php

namespace App\Integrations;

use App\IntegrationService;
use App\Services\AiChatClient;
use Carbon\CarbonImmutable;

class UniversalIntegrationPlanner
{
    public function __construct(private AiChatClient $client) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<string, mixed>
     */
    public function plan(IntegrationService $service, array $messages, string $timezone): array
    {
        $actions = $this->actions($service);
        $now = CarbonImmutable::now($timezone)->toIso8601String();
        $result = $this->client->completeStructured($messages, <<<PROMPT
Sei il pianificatore dell'integrazione {$service->label()}. Analizza soprattutto l'ultima richiesta.
Data corrente: {$now}. Fuso orario: {$timezone}.
Azioni consentite:
{$actions}
- clarify: il servizio serve ma mancano dati indispensabili;
- none: il servizio non serve.
Le azioni propose_* NON vengono eseguite subito: preparano una scheda che richiede conferma.
Non inventare ID, indirizzi, repository, canali, percorsi o destinatari.
Restituisci esclusivamente JSON con questa forma, lasciando null o [] i campi non usati:
{"action":"none","query":null,"id":null,"range":null,"start":null,"end":null,"owner":null,"repo":null,"number":null,"path":null,"parent_id":null,"name":null,"title":null,"content":null,"to":[],"cc":[],"subject":null,"body":null,"channel":null,"thread_ts":null,"playlist_id":null,"uris":[],"uri":null,"device_id":null,"context_uri":null,"timezone":"{$timezone}","location":null,"description":null,"missing":[]}
PROMPT);

        $action = is_string($result['action'] ?? null) ? $result['action'] : 'none';

        if (! in_array($action, [...$this->allowedActions($service), 'none', 'clarify'], true)) {
            $action = 'none';
        }

        return [
            'action' => $action,
            'query' => $this->string($result['query'] ?? null),
            'id' => $this->string($result['id'] ?? null),
            'range' => $this->range($result['range'] ?? null),
            'start' => $this->string($result['start'] ?? null),
            'end' => $this->string($result['end'] ?? null),
            'owner' => $this->string($result['owner'] ?? null),
            'repo' => $this->string($result['repo'] ?? null),
            'number' => is_numeric($result['number'] ?? null) ? (int) $result['number'] : null,
            'path' => $this->string($result['path'] ?? null),
            'parent_id' => $this->string($result['parent_id'] ?? null),
            'name' => $this->string($result['name'] ?? null),
            'title' => $this->string($result['title'] ?? null),
            'content' => $this->string($result['content'] ?? null),
            'to' => $this->strings($result['to'] ?? []),
            'cc' => $this->strings($result['cc'] ?? []),
            'subject' => $this->string($result['subject'] ?? null),
            'body' => $this->string($result['body'] ?? null),
            'channel' => $this->string($result['channel'] ?? null),
            'thread_ts' => $this->string($result['thread_ts'] ?? null),
            'playlist_id' => $this->string($result['playlist_id'] ?? null),
            'uris' => $this->strings($result['uris'] ?? []),
            'uri' => $this->string($result['uri'] ?? null),
            'device_id' => $this->string($result['device_id'] ?? null),
            'context_uri' => $this->string($result['context_uri'] ?? null),
            'timezone' => $timezone,
            'location' => $this->string($result['location'] ?? null),
            'description' => $this->string($result['description'] ?? null),
            'missing' => $this->strings($result['missing'] ?? []),
        ];
    }

    private function actions(IntegrationService $service): string
    {
        return match ($service) {
            IntegrationService::MicrosoftMail => <<<'TEXT'
- search: cerca messaggi Outlook con query;
- read: legge il messaggio con id;
- propose_create_draft: prepara bozza con to, cc, subject, body;
- propose_send_message: prepara invio con to, cc, subject, body;
TEXT,
            IntegrationService::MicrosoftCalendar => <<<'TEXT'
- list: legge eventi tra start ed end; se la finestra manca usa i prossimi 7 giorni;
- propose_create_event: prepara evento con title, start, end, timezone, location e description;
TEXT,
            IntegrationService::MicrosoftOneDrive => <<<'TEXT'
- search: cerca file con query;
- read: legge il file testuale con id;
- propose_create_folder: prepara cartella con name e parent_id facoltativo;
- propose_create_file: prepara file testuale con name, content e parent_id facoltativo;
TEXT,
            IntegrationService::Spotify => <<<'TEXT'
- now_playing: legge il brano corrente;
- recent: legge gli ascolti recenti;
- top_tracks: legge i brani più ascoltati; imposta range a short_term (ultime 4 settimane), medium_term (ultimi 6 mesi circa) o long_term (storico pluriennale), scegliendo la finestra più vicina al periodo chiesto;
- search: cerca brani con query;
- playlists: elenca playlist;
- propose_add_to_playlist: prepara aggiunta con playlist_id e uris;
- propose_add_to_queue: prepara aggiunta alla coda con uri e device_id facoltativo;
- propose_start_playback: prepara avvio con uris o context_uri e device_id facoltativo;
TEXT,
            IntegrationService::Notion => <<<'TEXT'
- search: cerca pagine con query;
- read: legge pagina con id;
- query_database: interroga data source/database con id;
- propose_create_page: prepara pagina con parent_id, title e content;
- propose_append_blocks: prepara append con id pagina e content;
TEXT,
            IntegrationService::Slack => <<<'TEXT'
- search: cerca messaggi con query;
- channels: elenca canali;
- thread: legge thread con channel e thread_ts;
- propose_post_message: prepara messaggio con channel e content;
- propose_reply: prepara risposta con channel, thread_ts e content;
TEXT,
            IntegrationService::Dropbox => <<<'TEXT'
- search: cerca file con query;
- list_folder: elenca cartella con path;
- read: legge file testuale con path;
- propose_create_folder: prepara cartella con path;
- propose_upload_text: prepara nuovo file con path e content;
TEXT,
            IntegrationService::GitHub => <<<'TEXT'
- repos: elenca repository;
- search_issues: cerca issue/PR con query;
- read_issue: legge issue con owner, repo e number;
- read_pr: legge metadati PR con owner, repo e number;
- propose_create_issue: prepara issue con owner, repo, title e body;
- propose_create_comment: prepara commento con owner, repo, number e body;
TEXT,
            default => '- none: nessuna azione disponibile;',
        };
    }

    /**
     * @return array<int, string>
     */
    private function allowedActions(IntegrationService $service): array
    {
        preg_match_all('/^- ([a-z_]+):/m', $this->actions($service), $matches);

        return $matches[1] ?? [];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function range(mixed $value): ?string
    {
        return in_array($value, ['short_term', 'medium_term', 'long_term'], true) ? $value : null;
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
