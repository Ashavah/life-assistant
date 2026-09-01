<?php

use App\CharacterSlug;
use App\Contracts\DriveGateway;
use App\Contracts\GmailGateway;
use App\DriveIntent;
use App\ExternalActionType;
use App\GmailIntent;
use App\Models\Character;
use App\Models\Conversation;
use App\Models\ExternalActionProposal;
use App\Models\ServiceConnection;
use App\Models\User;
use App\ServiceProvider;
use App\Services\DriveIntentPlanner;
use App\Services\GmailIntentPlanner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Operazione preparata.']]],
        ]),
    ]);
    config([
        'ai.api_key' => 'test-key',
        'ai.url' => 'https://api.deepseek.com/v1/chat/completions',
        'ai.model' => 'deepseek-chat',
    ]);
});

test('crea una cartella drive soltanto dopo conferma', function () {
    $conversation = connectedConversation($this->user, ServiceProvider::GoogleDrive);
    $this->mock(DriveIntentPlanner::class, function (MockInterface $mock): void {
        $mock->shouldReceive('plan')->once()->andReturn([
            'intent' => DriveIntent::ProposeCreateFolder->value,
            'query' => null,
            'file_id' => null,
            'name' => 'Progetto Alfa',
            'content' => null,
            'parent_id' => null,
            'missing' => [],
        ]);
    });
    $this->mock(DriveGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createFolder')
            ->once()
            ->andReturn([
                'id' => 'folder-1',
                'name' => 'Progetto Alfa',
                'web_link' => 'https://drive.google.com/folder-1',
            ]);
    });

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Crea una cartella Progetto Alfa su Drive',
    ])->assertOk()
        ->assertJsonPath('proposals.0.type', ExternalActionType::DriveCreateFolder->value);

    $proposal = ExternalActionProposal::query()->sole();
    expect($proposal->status->value)->toBe('pending');

    $this->postJson(route('external-actions.confirm', $proposal))
        ->assertOk()
        ->assertJsonPath('result.id', 'folder-1');
});

test('invia una email gmail soltanto dopo conferma', function () {
    $conversation = connectedConversation($this->user, ServiceProvider::GoogleGmail);
    $this->mock(GmailIntentPlanner::class, function (MockInterface $mock): void {
        $mock->shouldReceive('plan')->once()->andReturn([
            'intent' => GmailIntent::ProposeSend->value,
            'query' => null,
            'message_id' => null,
            'to' => ['mario@example.com'],
            'cc' => [],
            'subject' => 'Riunione',
            'body' => 'Ci vediamo domani.',
            'missing' => [],
        ]);
    });
    $this->mock(GmailGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('send')
            ->once()
            ->andReturn(['id' => 'message-1', 'thread_id' => 'thread-1', 'status' => 'sent']);
    });

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Invia una mail a Mario',
    ])->assertOk()
        ->assertJsonPath('proposals.0.type', ExternalActionType::GmailSendMessage->value);

    $proposal = ExternalActionProposal::query()->sole();
    $this->postJson(route('external-actions.confirm', $proposal))
        ->assertOk()
        ->assertJsonPath('result.status', 'sent');
    $this->postJson(route('external-actions.confirm', $proposal))
        ->assertOk()
        ->assertJsonPath('result.status', 'sent');
});

test('inserisce nel prompt soltanto i risultati drive reali', function () {
    $conversation = connectedConversation($this->user, ServiceProvider::GoogleDrive);
    $this->mock(DriveIntentPlanner::class, function (MockInterface $mock): void {
        $mock->shouldReceive('plan')->once()->andReturn([
            'intent' => DriveIntent::Search->value,
            'query' => 'Piano',
            'file_id' => null,
            'name' => null,
            'content' => null,
            'parent_id' => null,
            'missing' => [],
        ]);
    });
    $this->mock(DriveGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('search')->once()->withArgs(
            fn ($connection, string $query): bool => $query === 'Piano',
        )->andReturn([['id' => 'file-1', 'name' => 'Piano annuale']]);
    });

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Cerca Piano su Drive',
    ])->assertOk()->assertJsonPath('proposal', null);

    Http::assertSent(fn ($request): bool => str_contains(
        json_encode($request->data()['messages'], JSON_THROW_ON_ERROR),
        'Piano annuale',
    ));
});

function connectedConversation(User $user, ServiceProvider $provider): Conversation
{
    $character = Character::factory()->for($user)->create([
        'slug' => CharacterSlug::Secretary,
    ]);
    ServiceConnection::factory()->for($user)->create([
        'provider' => $provider,
        'scopes' => $provider->scopes(),
    ]);

    return Conversation::factory()->for($character)->create();
}
