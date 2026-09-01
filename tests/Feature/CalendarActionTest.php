<?php

use App\CalendarIntent;
use App\CharacterSlug;
use App\Contracts\CalendarGateway;
use App\Exceptions\CalendarGatewayException;
use App\ExternalActionStatus;
use App\Models\Character;
use App\Models\Conversation;
use App\Models\ExternalActionProposal;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\CalendarChatContextService;
use App\Services\CalendarIntentPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('la chat prepara una proposta senza creare subito l evento', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'model' => 'deepseek-chat',
            'choices' => [['message' => ['content' => 'Ho preparato la proposta. Confermala dalla scheda.']]],
        ]),
    ]);
    config([
        'ai.api_key' => 'test-key',
        'ai.url' => 'https://api.deepseek.com/v1/chat/completions',
        'ai.model' => 'deepseek-chat',
    ]);

    $secretary = Character::factory()->create(['slug' => CharacterSlug::Secretary]);
    $connection = ServiceConnection::factory()->for($this->user)->create();
    $conversation = Conversation::factory()->for($secretary)->create();
    $start = CarbonImmutable::parse('2026-09-03 15:00', 'Europe/Rome');
    $end = $start->addHour();

    $this->mock(CalendarIntentPlanner::class, function (MockInterface $mock) use ($start, $end): void {
        $mock->shouldReceive('plan')->once()->andReturn([
            'intent' => CalendarIntent::ProposeCreate->value,
            'summary' => 'Dentista',
            'start' => $start,
            'end' => $end,
            'timezone' => 'Europe/Rome',
            'location' => 'Studio Rossi',
            'description' => null,
            'missing' => [],
        ]);
    });
    $this->mock(CalendarGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('eventsBetween')->once()->andReturn([]);
        $mock->shouldNotReceive('createEvent');
    });

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Fissami il dentista giovedì alle 15',
    ])->assertOk()
        ->assertJsonPath('proposal.status', 'pending')
        ->assertJsonPath('proposal.payload.summary', 'Dentista');

    $this->assertDatabaseHas('external_action_proposals', [
        'conversation_id' => $conversation->id,
        'status' => ExternalActionStatus::Pending->value,
    ]);
});

test('la chat riceve gli eventi reali soltanto per un personaggio collegato', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Domani hai una visita alle 10.']]],
        ]),
    ]);
    config([
        'ai.api_key' => 'test-key',
        'ai.url' => 'https://api.deepseek.com/v1/chat/completions',
        'ai.model' => 'deepseek-chat',
    ]);
    $secretary = Character::factory()->create(['slug' => CharacterSlug::Secretary]);
    $connection = ServiceConnection::factory()->for($this->user)->create();
    $conversation = Conversation::factory()->for($secretary)->create();
    $start = CarbonImmutable::parse('2026-09-02 00:00', 'Europe/Rome');
    $end = $start->addDay();

    $this->mock(CalendarIntentPlanner::class, function (MockInterface $mock) use ($start, $end): void {
        $mock->shouldReceive('plan')->once()->andReturn([
            'intent' => CalendarIntent::List->value,
            'summary' => null,
            'start' => $start,
            'end' => $end,
            'timezone' => 'Europe/Rome',
            'location' => null,
            'description' => null,
            'missing' => [],
        ]);
    });
    $this->mock(CalendarGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('eventsBetween')->once()->andReturn([[
            'id' => 'event-1',
            'summary' => 'Visita medica',
            'start' => '2026-09-02T10:00:00+02:00',
            'end' => '2026-09-02T11:00:00+02:00',
            'all_day' => false,
            'location' => 'Studio',
            'html_link' => null,
        ]]);
    });

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Cosa ho domani?',
    ])->assertOk()
        ->assertJsonPath('proposal', null);

    Http::assertSent(fn ($request): bool => str_contains(
        json_encode($request->data()['messages'], JSON_THROW_ON_ERROR),
        'Visita medica',
    ));
});

test('una connessione account è disponibile a tutti gli specialisti', function () {
    ServiceConnection::factory()->for($this->user)->create();
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $manager = Character::factory()->create(['slug' => CharacterSlug::Manager]);
    $doctorConversation = Conversation::factory()->for($doctor)->create();
    $managerConversation = Conversation::factory()->for($manager)->create();
    $start = CarbonImmutable::parse('2026-09-02 00:00', 'Europe/Rome');
    $end = $start->addDay();

    $this->mock(CalendarIntentPlanner::class, function (MockInterface $mock) use ($start, $end): void {
        $mock->shouldReceive('plan')->twice()->andReturn([
            'intent' => CalendarIntent::List->value,
            'summary' => null,
            'start' => $start,
            'end' => $end,
            'timezone' => 'Europe/Rome',
            'location' => null,
            'description' => null,
            'missing' => [],
        ]);
    });
    $this->mock(CalendarGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('eventsBetween')->twice()->andReturn([]);
    });

    $calendar = app(CalendarChatContextService::class);

    expect($calendar->prepare($doctorConversation, [])['connection'])->not->toBeNull()
        ->and($calendar->prepare($managerConversation, [])['connection'])->not->toBeNull();
});

test('non consulta il calendario quando il messaggio non lo richiede', function () {
    fakeAiReply('Dimmi pure di cosa hai bisogno.');
    $conversation = connectedSecretaryConversation($this->user);

    $this->mock(CalendarIntentPlanner::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('plan');
    });
    $this->mock(CalendarGateway::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('eventsBetween');
    });

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Ciao, come va?',
    ])->assertOk()->assertJsonPath('calendar_error', null);

    Http::assertSent(fn ($request): bool => ! str_contains(
        json_encode($request->data()['messages'], JSON_THROW_ON_ERROR),
        'Non dire mai di non avere accesso al calendario',
    ));
});

test('un errore di google viene riportato con il motivo reale', function () {
    fakeAiReply('Non riesco a leggere l’agenda in questo momento.');
    $conversation = connectedSecretaryConversation($this->user);

    $this->mock(CalendarIntentPlanner::class, function (MockInterface $mock): void {
        $mock->shouldReceive('plan')->once()->andReturn(planStub(CalendarIntent::List));
    });
    $this->mock(CalendarGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('eventsBetween')
            ->once()
            ->andThrow(CalendarGatewayException::because('l’API Google Calendar non è abilitata sul progetto Google Cloud'));
    });

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Cosa ho domani?',
    ])->assertOk()
        ->assertJsonPath(
            'calendar_error',
            'Google Calendar non ha risposto: l’API Google Calendar non è abilitata sul progetto Google Cloud.',
        );
});

test('un errore del pianificatore non viene attribuito a google', function () {
    fakeAiReply('Puoi ripetere la richiesta?');
    $conversation = connectedSecretaryConversation($this->user);

    $this->mock(CalendarIntentPlanner::class, function (MockInterface $mock): void {
        $mock->shouldReceive('plan')->once()->andThrow(new RuntimeException('Risposta strutturata IA vuota.'));
    });
    $this->mock(CalendarGateway::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('eventsBetween');
    });

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Cosa ho domani?',
    ])->assertOk()
        ->assertJsonPath(
            'calendar_error',
            'Non è stato possibile interpretare la richiesta di calendario. Riprova a inviare il messaggio.',
        );
});

test('la conferma crea l evento una sola volta', function () {
    [$proposal, $connection] = proposalFor($this->user);
    $this->mock(CalendarGateway::class, function (MockInterface $mock) use ($connection, $proposal): void {
        $mock->shouldReceive('createEvent')
            ->once()
            ->withArgs(fn ($actualConnection, array $payload, string $key): bool => $actualConnection->is($connection)
                && $payload['summary'] === 'Riunione'
                && $key === $proposal->idempotency_key)
            ->andReturn([
                'id' => 'google-event',
                'summary' => 'Riunione',
                'start' => $proposal->payload['start'],
                'end' => $proposal->payload['end'],
                'html_link' => 'https://calendar.google.com/event',
            ]);
    });

    $this->postJson(route('external-actions.confirm', $proposal))
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('result.id', 'google-event');
    $this->postJson(route('external-actions.confirm', $proposal))
        ->assertOk()
        ->assertJsonPath('status', 'completed');

    expect($proposal->refresh()->executed_at)->not->toBeNull();
});

test('può rifiutare o lasciare scadere una proposta', function () {
    [$proposal] = proposalFor($this->user);
    $this->postJson(route('external-actions.reject', $proposal))
        ->assertOk()
        ->assertJsonPath('status', 'rejected');

    [$expired] = proposalFor($this->user, ['expires_at' => now()->subMinute()]);
    $this->postJson(route('external-actions.confirm', $expired))
        ->assertStatus(409);
    expect($expired->refresh()->status)->toBe(ExternalActionStatus::Expired);
});

test('un errore google lascia la proposta ripetibile', function () {
    [$proposal] = proposalFor($this->user);
    $this->mock(CalendarGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createEvent')
            ->once()
            ->andThrow(new RuntimeException('Google non disponibile'));
    });

    $this->postJson(route('external-actions.confirm', $proposal))
        ->assertStatus(502)
        ->assertJsonPath('message', 'Il servizio esterno non ha completato l’azione. Puoi riprovare.');

    expect($proposal->refresh()->status)->toBe(ExternalActionStatus::Failed);
});

function fakeAiReply(string $reply): void
{
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => $reply]]],
        ]),
    ]);
    config([
        'ai.api_key' => 'test-key',
        'ai.url' => 'https://api.deepseek.com/v1/chat/completions',
        'ai.model' => 'deepseek-chat',
    ]);
}

function connectedSecretaryConversation(User $user): Conversation
{
    $secretary = Character::factory()->create(['slug' => CharacterSlug::Secretary]);
    ServiceConnection::factory()->for($user)->create();

    return Conversation::factory()->for($secretary)->create();
}

/**
 * @return array{intent: string, summary: string|null, start: CarbonImmutable, end: CarbonImmutable, timezone: string, location: string|null, description: string|null, missing: array<int, string>}
 */
function planStub(CalendarIntent $intent): array
{
    $start = CarbonImmutable::parse('2026-09-02 00:00', 'Europe/Rome');

    return [
        'intent' => $intent->value,
        'summary' => null,
        'start' => $start,
        'end' => $start->addDay(),
        'timezone' => 'Europe/Rome',
        'location' => null,
        'description' => null,
        'missing' => [],
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 * @return array{ExternalActionProposal, ServiceConnection}
 */
function proposalFor(User $user, array $attributes = []): array
{
    $character = Character::query()->firstOrCreate(
        ['slug' => CharacterSlug::Secretary],
        Character::factory()->raw(['slug' => CharacterSlug::Secretary]),
    );
    $conversation = Conversation::factory()->for($character)->create();
    $connection = ServiceConnection::query()->whereBelongsTo($user)->first()
        ?? ServiceConnection::factory()->for($user)->create();
    $proposal = ExternalActionProposal::factory()->create(array_merge([
        'service_connection_id' => $connection->id,
        'character_id' => $character->id,
        'conversation_id' => $conversation->id,
    ], $attributes));

    return [$proposal, $connection];
}
