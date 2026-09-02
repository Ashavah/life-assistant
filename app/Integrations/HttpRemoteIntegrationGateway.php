<?php

namespace App\Integrations;

use App\Contracts\RemoteIntegrationGateway;
use App\Exceptions\IntegrationGatewayException;
use App\IntegrationService;
use App\Models\ServiceConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class HttpRemoteIntegrationGateway implements RemoteIntegrationGateway
{
    public function __construct(private GenericAccessTokenManager $tokens) {}

    public function read(
        ServiceConnection $connection,
        IntegrationService $service,
        string $action,
        array $parameters,
    ): array {
        try {
            $result = match ($service) {
                IntegrationService::MicrosoftMail => $this->readMicrosoftMail($connection, $action, $parameters),
                IntegrationService::MicrosoftCalendar => $this->readMicrosoftCalendar($connection, $action, $parameters),
                IntegrationService::MicrosoftOneDrive => $this->readOneDrive($connection, $action, $parameters),
                IntegrationService::Spotify => $this->readSpotify($connection, $action, $parameters),
                IntegrationService::Notion => $this->readNotion($connection, $action, $parameters),
                IntegrationService::Slack => $this->readSlack($connection, $action, $parameters),
                IntegrationService::Dropbox => $this->readDropbox($connection, $action, $parameters),
                IntegrationService::GitHub => $this->readGitHub($connection, $action, $parameters),
                default => throw new IntegrationGatewayException('Servizio non supportato dal gateway HTTP.'),
            };
        } catch (IntegrationGatewayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new IntegrationGatewayException(
                'Il servizio esterno non ha risposto correttamente.',
                ['provider' => $connection->provider->value, 'service' => $service->value, 'action' => $action],
                previous: $exception,
            );
        }

        $connection->update(['last_used_at' => now()]);

        return $this->limit($result);
    }

    public function write(
        ServiceConnection $connection,
        IntegrationService $service,
        string $action,
        array $payload,
        string $idempotencyKey,
    ): array {
        try {
            $result = match ($service) {
                IntegrationService::MicrosoftMail => $this->writeMicrosoftMail($connection, $action, $payload, $idempotencyKey),
                IntegrationService::MicrosoftCalendar => $this->writeMicrosoftCalendar($connection, $action, $payload, $idempotencyKey),
                IntegrationService::MicrosoftOneDrive => $this->writeOneDrive($connection, $action, $payload, $idempotencyKey),
                IntegrationService::Spotify => $this->writeSpotify($connection, $action, $payload),
                IntegrationService::Notion => $this->writeNotion($connection, $action, $payload, $idempotencyKey),
                IntegrationService::Slack => $this->writeSlack($connection, $action, $payload, $idempotencyKey),
                IntegrationService::Dropbox => $this->writeDropbox($connection, $action, $payload, $idempotencyKey),
                IntegrationService::GitHub => $this->writeGitHub($connection, $action, $payload, $idempotencyKey),
                default => throw new IntegrationGatewayException('Servizio non supportato dal gateway HTTP.'),
            };
        } catch (IntegrationGatewayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new IntegrationGatewayException(
                'Il servizio esterno non ha completato l’azione.',
                ['provider' => $connection->provider->value, 'service' => $service->value, 'action' => $action],
                previous: $exception,
            );
        }

        $connection->update(['last_used_at' => now()]);

        return $result;
    }

    private function readMicrosoftMail(ServiceConnection $connection, string $action, array $parameters): array
    {
        $request = $this->api($connection, 'https://graph.microsoft.com/v1.0');

        if ($action === 'read') {
            return $request->get('/me/messages/'.rawurlencode((string) $parameters['id']), [
                '$select' => 'id,subject,from,toRecipients,ccRecipients,receivedDateTime,body,webLink',
            ])->throw()->json();
        }

        return $request->get('/me/messages', [
            '$search' => '"'.str_replace('"', '', (string) ($parameters['query'] ?? '')).'"',
            '$top' => 15,
            '$select' => 'id,subject,from,toRecipients,receivedDateTime,bodyPreview,webLink',
        ])->throw()->json('value', []);
    }

    private function readMicrosoftCalendar(ServiceConnection $connection, string $action, array $parameters): array
    {
        return $this->api($connection, 'https://graph.microsoft.com/v1.0')
            ->get('/me/calendarView', [
                'startDateTime' => $parameters['start'],
                'endDateTime' => $parameters['end'],
                '$top' => 50,
                '$select' => 'id,subject,start,end,location,bodyPreview,webLink',
                '$orderby' => 'start/dateTime',
            ])->throw()->json('value', []);
    }

    private function readOneDrive(ServiceConnection $connection, string $action, array $parameters): array
    {
        $request = $this->api($connection, 'https://graph.microsoft.com/v1.0');

        if ($action === 'read') {
            $id = rawurlencode((string) $parameters['id']);
            $metadata = $request->get("/me/drive/items/{$id}", [
                '$select' => 'id,name,file,folder,lastModifiedDateTime,size,webUrl,parentReference',
            ])->throw()->json();
            $content = $request->get("/me/drive/items/{$id}/content")->throw()->body();

            return array_merge(is_array($metadata) ? $metadata : [], [
                'content' => Str::limit($content, (int) config('integrations.max_content_characters', 12000), ''),
            ]);
        }

        $query = rawurlencode((string) ($parameters['query'] ?? ''));

        return $request->get("/me/drive/root/search(q='{$query}')", [
            '$top' => 15,
            '$select' => 'id,name,file,folder,lastModifiedDateTime,size,webUrl,parentReference',
        ])->throw()->json('value', []);
    }

    private function readSpotify(ServiceConnection $connection, string $action, array $parameters): array
    {
        $request = $this->api($connection, 'https://api.spotify.com/v1');

        return match ($action) {
            'now_playing' => $this->spotifyPlayback($request->get('/me/player/currently-playing')),
            'recent' => $request->get('/me/player/recently-played', ['limit' => 15])->throw()->json('items', []),
            'top_tracks' => $this->spotifyTopTracks($request, (string) ($parameters['range'] ?? 'medium_term')),
            'playlists' => $request->get('/me/playlists', ['limit' => 20])->throw()->json('items', []),
            default => $request->get('/search', [
                'q' => (string) ($parameters['query'] ?? ''),
                'type' => 'track',
                'limit' => 15,
            ])->throw()->json('tracks.items', []),
        };
    }

    private function readNotion(ServiceConnection $connection, string $action, array $parameters): array
    {
        $request = $this->notion($connection);

        return match ($action) {
            'read' => [
                'page' => $request->get('/pages/'.rawurlencode((string) $parameters['id']))->throw()->json(),
                'blocks' => $request->get('/blocks/'.rawurlencode((string) $parameters['id']).'/children', [
                    'page_size' => 50,
                ])->throw()->json('results', []),
            ],
            'query_database' => $request->post('/data_sources/'.rawurlencode((string) $parameters['id']).'/query', [
                'page_size' => 15,
            ])->throw()->json('results', []),
            default => $request->post('/search', [
                'query' => (string) ($parameters['query'] ?? ''),
                'page_size' => 10,
                'sort' => ['direction' => 'descending', 'timestamp' => 'last_edited_time'],
            ])->throw()->json('results', []),
        };
    }

    private function readSlack(ServiceConnection $connection, string $action, array $parameters): array
    {
        $request = $this->api($connection, 'https://slack.com/api');
        $response = match ($action) {
            'channels' => $request->get('/conversations.list', [
                'limit' => 30,
                'types' => 'public_channel,private_channel,im,mpim',
            ]),
            'thread' => $request->get('/conversations.replies', [
                'channel' => $parameters['channel'],
                'ts' => $parameters['thread_ts'],
                'limit' => 20,
            ]),
            default => $request->get('/search.messages', [
                'query' => $parameters['query'] ?? '',
                'count' => 10,
                'sort' => 'timestamp',
                'sort_dir' => 'desc',
            ]),
        };
        $json = $this->slackResponse($response);

        return match ($action) {
            'channels' => Arr::get($json, 'channels', []),
            'thread' => Arr::get($json, 'messages', []),
            default => Arr::get($json, 'messages.matches', []),
        };
    }

    private function readDropbox(ServiceConnection $connection, string $action, array $parameters): array
    {
        $request = $this->api($connection, 'https://api.dropboxapi.com/2');

        if ($action === 'read') {
            $response = Http::withToken($this->tokens->accessToken($connection))
                ->connectTimeout((int) config('integrations.connect_timeout', 5))
                ->timeout((int) config('integrations.timeout', 15))
                ->withHeaders(['Dropbox-API-Arg' => json_encode(['path' => $parameters['path']])])
                ->post('https://content.dropboxapi.com/2/files/download')
                ->throw();

            return [
                'metadata' => json_decode((string) $response->header('Dropbox-API-Result'), true),
                'content' => Str::limit($response->body(), (int) config('integrations.max_content_characters', 12000), ''),
            ];
        }

        if ($action === 'list_folder') {
            return $request->post('/files/list_folder', [
                'path' => (string) ($parameters['path'] ?? ''),
                'limit' => 30,
            ])->throw()->json('entries', []);
        }

        return $request->post('/files/search_v2', [
            'query' => (string) ($parameters['query'] ?? ''),
            'options' => ['max_results' => 15, 'file_status' => 'active'],
        ])->throw()->json('matches', []);
    }

    private function readGitHub(ServiceConnection $connection, string $action, array $parameters): array
    {
        $request = $this->github($connection);

        return match ($action) {
            'repos' => $request->get('/user/repos', [
                'affiliation' => 'owner,collaborator,organization_member',
                'sort' => 'updated',
                'per_page' => 20,
            ])->throw()->json(),
            'read_issue' => [
                'issue' => $request->get($this->repoPath($parameters).'/issues/'.(int) $parameters['number'])->throw()->json(),
                'comments' => $request->get($this->repoPath($parameters).'/issues/'.(int) $parameters['number'].'/comments', [
                    'per_page' => 15,
                ])->throw()->json(),
            ],
            'read_pr' => [
                'pull_request' => $request->get($this->repoPath($parameters).'/pulls/'.(int) $parameters['number'])->throw()->json(),
                'files' => array_slice($request->get($this->repoPath($parameters).'/pulls/'.(int) $parameters['number'].'/files', [
                    'per_page' => 5,
                ])->throw()->json(), 0, 5),
            ],
            default => $request->get('/search/issues', [
                'q' => (string) ($parameters['query'] ?? ''),
                'per_page' => 15,
            ])->throw()->json('items', []),
        };
    }

    private function writeMicrosoftMail(
        ServiceConnection $connection,
        string $action,
        array $payload,
        string $idempotencyKey,
    ): array {
        $request = $this->api($connection, 'https://graph.microsoft.com/v1.0');
        $message = [
            'subject' => $payload['subject'],
            'body' => ['contentType' => 'Text', 'content' => $payload['body']],
            'toRecipients' => $this->graphRecipients($payload['to'] ?? []),
            'ccRecipients' => $this->graphRecipients($payload['cc'] ?? []),
            'internetMessageHeaders' => [[
                'name' => 'x-life-assistant-idempotency-key',
                'value' => $idempotencyKey,
            ]],
        ];

        if ($action === 'create_draft') {
            $created = $request->post('/me/messages', $message)->throw()->json();

            return [
                'id' => Arr::get($created, 'id'),
                'web_link' => Arr::get($created, 'webLink'),
                'status' => 'draft',
            ];
        }

        $request->post('/me/sendMail', ['message' => $message, 'saveToSentItems' => true])->throw();

        return ['status' => 'sent'];
    }

    private function writeMicrosoftCalendar(
        ServiceConnection $connection,
        string $action,
        array $payload,
        string $idempotencyKey,
    ): array {
        $created = $this->api($connection, 'https://graph.microsoft.com/v1.0')
            ->post('/me/events', [
                'subject' => $payload['summary'],
                'body' => ['contentType' => 'Text', 'content' => $payload['description'] ?? ''],
                'start' => ['dateTime' => $payload['start'], 'timeZone' => $payload['timezone']],
                'end' => ['dateTime' => $payload['end'], 'timeZone' => $payload['timezone']],
                'location' => ['displayName' => $payload['location'] ?? ''],
                'transactionId' => $idempotencyKey,
            ])->throw()->json();

        return [
            'id' => Arr::get($created, 'id'),
            'web_link' => Arr::get($created, 'webLink'),
            'status' => 'created',
        ];
    }

    private function writeOneDrive(
        ServiceConnection $connection,
        string $action,
        array $payload,
        string $idempotencyKey,
    ): array {
        $request = $this->api($connection, 'https://graph.microsoft.com/v1.0');
        $parent = rawurlencode((string) ($payload['parent_id'] ?? 'root'));

        if ($action === 'create_folder') {
            $created = $request->post("/me/drive/items/{$parent}/children", [
                'name' => $payload['name'],
                'folder' => new \stdClass,
                '@microsoft.graph.conflictBehavior' => 'fail',
                'description' => 'life-assistant:'.$idempotencyKey,
            ])->throw()->json();
        } else {
            $name = rawurlencode($idempotencyKey.'-'.basename((string) $payload['name']));
            $created = $request->withBody((string) ($payload['content'] ?? ''), 'text/plain')
                ->put("/me/drive/items/{$parent}:/{$name}:/content")
                ->throw()
                ->json();
        }

        return [
            'id' => Arr::get($created, 'id'),
            'name' => Arr::get($created, 'name'),
            'web_link' => Arr::get($created, 'webUrl'),
        ];
    }

    private function writeSpotify(ServiceConnection $connection, string $action, array $payload): array
    {
        $request = $this->api($connection, 'https://api.spotify.com/v1');

        if ($action === 'add_to_playlist') {
            $playlistId = rawurlencode((string) $payload['playlist_id']);
            $requestedUris = array_values($payload['uris'] ?? []);
            $existingUris = collect($request->get("/playlists/{$playlistId}/items", [
                'limit' => 100,
                'fields' => 'items.item.uri',
            ])->throw()->json('items', []))
                ->pluck('item.uri')
                ->filter('is_string')
                ->all();
            $missingUris = array_values(array_diff($requestedUris, $existingUris));

            if ($missingUris === []) {
                return ['status' => 'already_present'];
            }

            $request->post("/playlists/{$playlistId}/items", ['uris' => $missingUris])->throw();

            return ['status' => 'completed', 'added' => count($missingUris)];
        }

        match ($action) {
            'add_to_queue' => $request->post('/me/player/queue', array_filter([
                'uri' => $payload['uri'],
                'device_id' => $payload['device_id'] ?? null,
            ]))->throw(),
            'start_playback' => $request->put('/me/player/play', array_filter([
                'device_id' => $payload['device_id'] ?? null,
                'uris' => $payload['uris'] ?? null,
                'context_uri' => $payload['context_uri'] ?? null,
            ]))->throw(),
            default => throw new IntegrationGatewayException('Azione Spotify non consentita.'),
        };

        return ['status' => 'completed'];
    }

    private function writeNotion(
        ServiceConnection $connection,
        string $action,
        array $payload,
        string $idempotencyKey,
    ): array {
        $request = $this->notion($connection);
        $marker = 'life-assistant:'.$idempotencyKey;

        if ($action === 'create_page') {
            $created = $request->post('/pages', [
                'parent' => ['page_id' => $payload['parent_id']],
                'properties' => [
                    'title' => ['title' => [[
                        'type' => 'text',
                        'text' => ['content' => $payload['title']],
                    ]]],
                ],
                'children' => $this->notionParagraphs($payload['content'] ?? '', $marker),
            ])->throw()->json();
        } else {
            $created = $request->patch('/blocks/'.rawurlencode((string) $payload['page_id']).'/children', [
                'children' => $this->notionParagraphs($payload['content'] ?? '', $marker),
            ])->throw()->json();
        }

        return [
            'id' => Arr::get($created, 'id'),
            'web_link' => Arr::get($created, 'url'),
            'status' => 'created',
        ];
    }

    private function writeSlack(
        ServiceConnection $connection,
        string $action,
        array $payload,
        string $idempotencyKey,
    ): array {
        $json = $this->slackResponse(
            $this->api($connection, 'https://slack.com/api')->post('/chat.postMessage', array_filter([
                'channel' => $payload['channel'],
                'text' => $payload['text'],
                'thread_ts' => $payload['thread_ts'] ?? null,
                'client_msg_id' => $idempotencyKey,
            ])),
        );

        return [
            'id' => Arr::get($json, 'ts'),
            'channel' => Arr::get($json, 'channel'),
            'status' => 'sent',
        ];
    }

    private function writeDropbox(
        ServiceConnection $connection,
        string $action,
        array $payload,
        string $idempotencyKey,
    ): array {
        if ($action === 'create_folder') {
            return $this->api($connection, 'https://api.dropboxapi.com/2')
                ->post('/files/create_folder_v2', [
                    'path' => $payload['path'],
                    'autorename' => false,
                ])->throw()->json('metadata', []);
        }

        $path = rtrim(dirname((string) $payload['path']), '/').'/'.$idempotencyKey.'-'.basename((string) $payload['path']);

        return Http::withToken($this->tokens->accessToken($connection))
            ->connectTimeout((int) config('integrations.connect_timeout', 5))
            ->timeout((int) config('integrations.timeout', 15))
            ->withHeaders([
                'Dropbox-API-Arg' => json_encode([
                    'path' => $path,
                    'mode' => 'add',
                    'autorename' => false,
                    'mute' => false,
                ]),
                'Content-Type' => 'application/octet-stream',
            ])
            ->withBody((string) ($payload['content'] ?? ''), 'application/octet-stream')
            ->post('https://content.dropboxapi.com/2/files/upload')
            ->throw()
            ->json();
    }

    private function writeGitHub(
        ServiceConnection $connection,
        string $action,
        array $payload,
        string $idempotencyKey,
    ): array {
        $request = $this->github($connection);
        $path = $this->repoPath($payload);
        $marker = "\n\n<!-- life-assistant:{$idempotencyKey} -->";

        $created = $action === 'create_issue'
            ? $request->post($path.'/issues', [
                'title' => $payload['title'],
                'body' => ($payload['body'] ?? '').$marker,
            ])->throw()->json()
            : $request->post($path.'/issues/'.(int) $payload['number'].'/comments', [
                'body' => $payload['body'].$marker,
            ])->throw()->json();

        return [
            'id' => Arr::get($created, 'id'),
            'web_link' => Arr::get($created, 'html_url'),
            'status' => 'created',
        ];
    }

    private function api(ServiceConnection $connection, string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->withToken($this->tokens->accessToken($connection))
            ->acceptJson()
            ->connectTimeout((int) config('integrations.connect_timeout', 5))
            ->timeout((int) config('integrations.timeout', 15));
    }

    private function notion(ServiceConnection $connection): PendingRequest
    {
        return $this->api($connection, 'https://api.notion.com/v1')
            ->withHeaders(['Notion-Version' => (string) config('integrations.providers.notion.notion_version')]);
    }

    private function github(ServiceConnection $connection): PendingRequest
    {
        return $this->api($connection, 'https://api.github.com')
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'life-assistant',
            ]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function repoPath(array $parameters): string
    {
        $owner = rawurlencode((string) ($parameters['owner'] ?? ''));
        $repo = rawurlencode((string) ($parameters['repo'] ?? ''));

        if ($owner === '' || $repo === '') {
            throw new IntegrationGatewayException('Repository GitHub non specificato.');
        }

        return "/repos/{$owner}/{$repo}";
    }

    private function slackResponse(Response $response): array
    {
        $json = $response->throw()->json();

        if (! is_array($json) || Arr::get($json, 'ok') !== true) {
            throw new IntegrationGatewayException('Slack ha rifiutato la richiesta: '.Arr::get($json, 'error', 'errore sconosciuto').'.');
        }

        return $json;
    }

    private function spotifyPlayback(Response $response): array
    {
        if ($response->status() === 204) {
            return ['playing' => false, 'item' => null];
        }

        return $response->throw()->json();
    }

    /**
     * Spotify espone solo tre finestre temporali, quindi la classifica va sempre
     * riportata indicando il periodo effettivamente coperto.
     *
     * @return array{range: string, period: string, tracks: array<int, mixed>}
     */
    private function spotifyTopTracks(PendingRequest $request, string $range): array
    {
        $range = in_array($range, ['short_term', 'medium_term', 'long_term'], true) ? $range : 'medium_term';

        $tracks = $request->get('/me/top/tracks', [
            'limit' => (int) config('integrations.max_list_items', 15),
            'time_range' => $range,
        ])->throw()->json('items', []);

        return [
            'range' => $range,
            'period' => match ($range) {
                'short_term' => 'ultime 4 settimane circa',
                'long_term' => 'storico pluriennale con peso maggiore sugli ascolti recenti',
                default => 'ultimi 6 mesi circa',
            },
            'tracks' => $tracks,
        ];
    }

    /**
     * @param  array<int, string>  $addresses
     * @return array<int, array{emailAddress: array{address: string}}>
     */
    private function graphRecipients(array $addresses): array
    {
        return array_map(
            fn (string $address): array => ['emailAddress' => ['address' => $address]],
            array_values(array_filter($addresses, 'is_string')),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function notionParagraphs(string $content, string $marker): array
    {
        return [[
            'object' => 'block',
            'type' => 'paragraph',
            'paragraph' => [
                'rich_text' => [[
                    'type' => 'text',
                    'text' => ['content' => Str::limit($content, 1900, '')."\n".$marker],
                ]],
            ],
        ]];
    }

    private function limit(array $result): array
    {
        $maxItems = (int) config('integrations.max_list_items', 15);

        if (array_is_list($result)) {
            return array_slice($result, 0, $maxItems);
        }

        return $result;
    }
}
