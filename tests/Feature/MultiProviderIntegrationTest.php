<?php

use App\Contracts\RemoteIntegrationGateway;
use App\ExternalActionStatus;
use App\ExternalActionType;
use App\Integrations\GenericOAuthDriver;
use App\Integrations\IntegrationRouter;
use App\Integrations\ServiceConnectionResolver;
use App\Integrations\UniversalIntegrationPlanner;
use App\IntegrationService;
use App\Models\Character;
use App\Models\Conversation;
use App\Models\ExternalActionProposal;
use App\Models\ServiceConnection;
use App\Models\User;
use App\ServiceProvider;
use App\Services\GoogleOAuthService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('il pannello account espone google e spotify e nasconde le altre piattaforme', function () {
    Character::factory()->global()->for(multiProviderUser())->create();

    $this->get(route('home', ['account_settings' => 1]))
        ->assertOk()
        ->assertSee('Google')
        ->assertSee('Spotify')
        ->assertDontSee('Microsoft 365')
        ->assertDontSee('Notion')
        ->assertDontSee('Slack')
        ->assertDontSee('Dropbox')
        ->assertDontSee('GitHub');
});

test('oauth spotify verifica l identità e salva token cifrati', function () {
    config([
        'services.spotify.client_id' => 'spotify-client',
        'services.spotify.client_secret' => 'spotify-secret',
        'services.spotify.redirect_uri' => 'http://localhost/auth/integrations/spotify/callback',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://accounts.spotify.com/api/token' => Http::response([
            'access_token' => 'spotify-access',
            'refresh_token' => 'spotify-refresh',
            'expires_in' => 3600,
            'scope' => implode(' ', ServiceProvider::Spotify->scopes()),
        ]),
        'https://api.spotify.com/v1/me' => Http::response([
            'id' => 'spotify-user-1',
            'display_name' => 'Mario',
            'email' => 'mario@example.com',
        ]),
    ]);

    $this->get(route('integrations.redirect', ServiceProvider::Spotify))
        ->assertRedirectContains('accounts.spotify.com/authorize')
        ->assertSessionHas('integration_oauth.provider', ServiceProvider::Spotify->value);

    $pending = session('integration_oauth');

    $this->get(route('integrations.callback', [
        'provider' => ServiceProvider::Spotify,
        'state' => $pending['state'],
        'code' => 'spotify-code',
    ]))->assertRedirect();

    $connection = ServiceConnection::query()->sole();
    expect($connection->provider)->toBe(ServiceProvider::Spotify)
        ->and($connection->metadata['account_id'])->toBe('spotify-user-1')
        ->and($connection->access_token)->toBe('spotify-access');

    expect(DB::table('service_connections')->value('access_token'))->not->toBe('spotify-access');
});

test('il consenso google chiede insieme calendar drive e gmail', function () {
    config([
        'services.google.client_id' => 'google-client',
        'services.google.client_secret' => 'google-secret',
        'services.google.redirect_uri' => 'http://127.0.0.1:8000/auth/integrations/google/callback',
    ]);

    $response = $this->get(route('integrations.redirect', ServiceProvider::Google))
        ->assertSessionHas('integration_oauth.provider', ServiceProvider::Google->value);

    $authorizationUrl = urldecode($response->headers->get('location'));

    expect($authorizationUrl)
        ->toContain('accounts.google.com')
        ->toContain('http://127.0.0.1:8000/auth/integrations/google/callback')
        ->toContain('auth/calendar.events')
        ->toContain('auth/drive.file')
        ->toContain('auth/gmail.send');
});

test('google usa un solo consenso per calendar drive e gmail', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
            'sub' => 'google-user-1',
            'name' => 'Mario',
            'email' => 'mario@example.com',
        ]),
    ]);
    $this->mock(GoogleOAuthService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('exchange')
            ->once()
            ->with('google-code', ServiceProvider::Google)
            ->andReturn([
                'access_token' => 'google-access',
                'refresh_token' => 'google-refresh',
                'expires_in' => 3600,
                'scope' => implode(' ', ServiceProvider::Google->scopes()),
            ]);
    });

    $this->withSession([
        'integration_oauth' => [
            'state' => 'expected-state',
            'provider' => ServiceProvider::Google->value,
            'verifier' => 'verifier',
        ],
    ])->get(route('integrations.callback', [
        'provider' => ServiceProvider::Google,
        'state' => 'expected-state',
        'code' => 'google-code',
    ]))->assertRedirect();

    expect(ServiceConnection::query()->sole()->provider)->toBe(ServiceProvider::Google)
        ->and(ServiceConnection::query()->sole()->hasRequiredScopes())->toBeTrue();
});

test('i driver oauth normalizzano token e identità dei provider', function (
    string $providerValue,
    array $tokenResponse,
    ?string $identityUrl,
    array $identityResponse,
) {
    $provider = ServiceProvider::from($providerValue);
    $key = $provider->configurationKey();
    config([
        "services.{$key}.client_id" => 'client-id',
        "services.{$key}.client_secret" => 'client-secret',
        "services.{$key}.redirect_uri" => 'http://localhost/callback',
    ]);
    $responses = [
        config("integrations.providers.{$key}.token_url") => Http::response($tokenResponse),
    ];

    if ($identityUrl !== null) {
        $responses[$identityUrl] = Http::response($identityResponse);
    }

    Http::preventStrayRequests();
    Http::fake($responses);

    $token = app(GenericOAuthDriver::class)->exchange($provider, 'code', 'verifier');

    expect($token['access_token'])->toBe(
        $provider === ServiceProvider::Slack
            ? data_get($tokenResponse, 'authed_user.access_token')
            : $tokenResponse['access_token'],
    )->and($token['metadata'])->toBeArray();
})->with([
    'microsoft' => [
        ServiceProvider::Microsoft->value,
        ['access_token' => 'microsoft-token', 'refresh_token' => 'refresh', 'expires_in' => 3600, 'scope' => implode(' ', ServiceProvider::Microsoft->scopes())],
        'https://graph.microsoft.com/v1.0/me',
        ['id' => 'ms-1', 'displayName' => 'Mario', 'mail' => 'mario@example.com'],
    ],
    'notion' => [
        ServiceProvider::Notion->value,
        ['access_token' => 'notion-token', 'workspace_id' => 'workspace-1', 'workspace_name' => 'Team', 'bot_id' => 'bot-1', 'owner' => ['user' => ['id' => 'notion-user', 'name' => 'Mario']]],
        null,
        [],
    ],
    'slack' => [
        ServiceProvider::Slack->value,
        ['ok' => true, 'authed_user' => ['id' => 'U1', 'access_token' => 'slack-token', 'scope' => implode(',', ServiceProvider::Slack->scopes())], 'team' => ['id' => 'T1', 'name' => 'Team']],
        'https://slack.com/api/auth.test',
        ['ok' => true, 'user_id' => 'U1', 'user' => 'mario', 'team_id' => 'T1', 'team' => 'Team'],
    ],
    'dropbox' => [
        ServiceProvider::Dropbox->value,
        ['access_token' => 'dropbox-token', 'refresh_token' => 'refresh', 'expires_in' => 14400, 'scope' => implode(' ', ServiceProvider::Dropbox->scopes())],
        'https://api.dropboxapi.com/2/users/get_current_account',
        ['account_id' => 'dbid:1', 'name' => ['display_name' => 'Mario'], 'email' => 'mario@example.com'],
    ],
    'github' => [
        ServiceProvider::GitHub->value,
        ['access_token' => 'github-token', 'scope' => ''],
        'https://api.github.com/user',
        ['id' => 42, 'login' => 'mario'],
    ],
    'spotify' => [
        ServiceProvider::Spotify->value,
        ['access_token' => 'spotify-token', 'refresh_token' => 'refresh', 'expires_in' => 3600, 'scope' => implode(' ', ServiceProvider::Spotify->scopes())],
        'https://api.spotify.com/v1/me',
        ['id' => 'spotify-1', 'display_name' => 'Mario'],
    ],
]);

test('una scrittura slack viene proposta e parte soltanto dopo conferma', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Ho preparato il messaggio Slack.']]],
        ]),
    ]);
    config([
        'ai.api_key' => 'test-key',
        'ai.url' => 'https://api.deepseek.com/v1/chat/completions',
    ]);

    $character = Character::factory()->for(multiProviderUser())->create(['slug' => 'collaboratore']);
    $conversation = Conversation::factory()->for($character)->create();
    ServiceConnection::factory()->for(multiProviderUser())->create([
        'provider' => ServiceProvider::Slack,
        'scopes' => ServiceProvider::Slack->scopes(),
    ]);
    $this->mock(UniversalIntegrationPlanner::class, function (MockInterface $mock): void {
        $mock->shouldReceive('plan')->once()->andReturn([
            'action' => 'propose_post_message',
            'channel' => 'C123',
            'content' => 'Aggiornamento completato',
            'missing' => [],
        ]);
    });
    $this->mock(RemoteIntegrationGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('write')
            ->once()
            ->withArgs(fn ($connection, $service, string $action, array $payload): bool => $action === 'post_message'
                && $payload['channel'] === 'C123'
                && $payload['text'] === 'Aggiornamento completato')
            ->andReturn(['status' => 'sent', 'id' => '171.001']);
    });

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Scrivi su Slack nel canale del progetto',
    ])->assertOk()
        ->assertJsonPath('proposal.type', ExternalActionType::SlackPostMessage->value)
        ->assertJsonPath('proposal.status', ExternalActionStatus::Pending->value);

    $proposal = ExternalActionProposal::query()->sole();
    expect($proposal->status)->toBe(ExternalActionStatus::Pending);

    $this->postJson(route('external-actions.confirm', $proposal))
        ->assertOk()
        ->assertJsonPath('status', ExternalActionStatus::Completed->value);
    $this->postJson(route('external-actions.confirm', $proposal))
        ->assertOk()
        ->assertJsonPath('status', ExternalActionStatus::Completed->value);
});

test('la classifica spotify usa la finestra temporale scelta dal pianificatore', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Il brano più ascoltato è Nightcall.']]],
        ]),
    ]);
    config([
        'ai.api_key' => 'test-key',
        'ai.url' => 'https://api.deepseek.com/v1/chat/completions',
    ]);

    $character = Character::factory()->for(multiProviderUser())->create(['slug' => 'psicologo']);
    $conversation = Conversation::factory()->for($character)->create();
    ServiceConnection::factory()->for(multiProviderUser())->create([
        'provider' => ServiceProvider::Spotify,
        'scopes' => ServiceProvider::Spotify->scopes(),
    ]);
    $this->mock(UniversalIntegrationPlanner::class, function (MockInterface $mock): void {
        $mock->shouldReceive('plan')->once()->andReturn([
            'action' => 'top_tracks',
            'range' => 'medium_term',
            'missing' => [],
        ]);
    });
    $this->mock(RemoteIntegrationGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('read')
            ->once()
            ->withArgs(fn ($connection, $service, string $action, array $parameters): bool => $action === 'top_tracks'
                && $parameters['range'] === 'medium_term')
            ->andReturn([
                'range' => 'medium_term',
                'period' => 'ultimi 6 mesi circa',
                'tracks' => [['name' => 'Nightcall']],
            ]);
    });

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'qual è la canzone che ho ascoltato di più negli ultimi 8 mesi?',
    ])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains(
        json_encode($request->data()['messages'], JSON_THROW_ON_ERROR),
        'Nightcall',
    ));
});

test('senza il permesso sulle classifiche spotify chiede di ricollegare l account', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Serve ricollegare Spotify.']]],
        ]),
    ]);
    config([
        'ai.api_key' => 'test-key',
        'ai.url' => 'https://api.deepseek.com/v1/chat/completions',
    ]);

    $character = Character::factory()->for(multiProviderUser())->create(['slug' => 'psicologo']);
    $conversation = Conversation::factory()->for($character)->create();
    ServiceConnection::factory()->for(multiProviderUser())->create([
        'provider' => ServiceProvider::Spotify,
        'scopes' => array_values(array_diff(ServiceProvider::Spotify->scopes(), ['user-top-read'])),
    ]);
    $this->mock(UniversalIntegrationPlanner::class, function (MockInterface $mock): void {
        $mock->shouldReceive('plan')->once()->andReturn([
            'action' => 'top_tracks',
            'range' => 'long_term',
            'missing' => [],
        ]);
    });
    $this->mock(RemoteIntegrationGateway::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('read');
    });

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'quali sono i brani che ascolto di più?',
    ])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains(
        json_encode($request->data()['messages'], JSON_THROW_ON_ERROR),
        'ricollegare Spotify',
    ));
});

test('una domanda di seguito resta sul servizio del messaggio precedente', function () {
    $character = Character::factory()->for(multiProviderUser())->create(['slug' => 'psicologo']);
    $conversation = Conversation::factory()->for($character)->create();
    ServiceConnection::factory()->for(multiProviderUser())->create([
        'provider' => ServiceProvider::Spotify,
        'scopes' => ServiceProvider::Spotify->scopes(),
    ]);

    $services = app(IntegrationRouter::class)->route($conversation, [
        ['role' => 'user', 'content' => 'gli ultimi brani che ho ascoltato?'],
        ['role' => 'assistant', 'content' => 'Ecco i tuoi ascolti recenti.'],
        ['role' => 'user', 'content' => 'e negli ultimi 5 mesi?'],
    ]);

    expect($services)->toBe([IntegrationService::Spotify]);
});

test('un messaggio lungo su un altro argomento non trascina il servizio precedente', function () {
    $character = Character::factory()->for(multiProviderUser())->create(['slug' => 'psicologo']);
    $conversation = Conversation::factory()->for($character)->create();
    ServiceConnection::factory()->for(multiProviderUser())->create([
        'provider' => ServiceProvider::Spotify,
        'scopes' => ServiceProvider::Spotify->scopes(),
    ]);

    $services = app(IntegrationRouter::class)->route($conversation, [
        ['role' => 'user', 'content' => 'gli ultimi brani che ho ascoltato?'],
        ['role' => 'assistant', 'content' => 'Ecco i tuoi ascolti recenti.'],
        ['role' => 'user', 'content' => 'cambiando discorso, ho litigato con mio fratello e da giorni faccio fatica a dormire, vorrei capire come gestire questa tensione senza rimuginare tutta la notte'],
    ]);

    expect($services)->toBe([]);
});

test('il resolver non usa connessioni appartenenti a un altro utente', function () {
    $otherUser = User::factory()->create();
    ServiceConnection::factory()->for($otherUser)->create([
        'provider' => ServiceProvider::Dropbox,
        'scopes' => ServiceProvider::Dropbox->scopes(),
    ]);

    expect(app(ServiceConnectionResolver::class)->forService(
        multiProviderUser()->id,
        IntegrationService::Dropbox,
    ))->toBeNull();
});

function multiProviderUser(): User
{
    return User::query()->findOrFail(Auth::id());
}
