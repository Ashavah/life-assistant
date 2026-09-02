<?php

use App\Jobs\ProcessKnowledgeIngestion;
use App\KnowledgeIngestionStatus;
use App\KnowledgeSourceType;
use App\Models\Character;
use App\Models\KnowledgeIngestion;
use App\Models\KnowledgeIngestionItem;
use App\Models\Memory;
use App\Models\User;
use App\Services\AiChatClient;
use App\Services\Knowledge\KnowledgeExtractor;
use App\Services\Knowledge\KnowledgeTextChunker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    config([
        'ai.api_key' => 'structured-key',
        'ai.url' => 'https://structured.example/v1/chat/completions',
        'knowledge.disk' => 'local',
        'knowledge.queue' => 'knowledge',
    ]);
});

function knowledgeCharacter(User $user, array $attributes = []): Character
{
    return Character::factory()->for($user)->create(array_merge([
        'slug' => 'nutrizionista',
        'name' => 'Nutrizionista',
        'description' => 'Alimentazione, nutrizione e abitudini alimentari.',
    ], $attributes));
}

function pendingKnowledgeIngestion(Character $character, array $attributes = []): KnowledgeIngestion
{
    return KnowledgeIngestion::factory()->for($character)->create(array_merge([
        'user_id' => $character->user_id,
        'status' => KnowledgeIngestionStatus::Pending,
        'source_type' => KnowledgeSourceType::Text,
        'temporary_text' => 'Mangio una dieta vegetariana.',
        'content_hash' => hash('sha256', fake()->unique()->sentence()),
    ], $attributes));
}

function fakeStructuredKnowledge(array $changes): void
{
    Http::fake([
        '*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode(['changes' => $changes], JSON_THROW_ON_ERROR),
                ],
            ]],
        ]),
    ]);
}

test('l’upload richiede autenticazione e proprietà del personaggio', function () {
    $owner = User::factory()->create();
    $character = knowledgeCharacter($owner);

    $this->postJson(route('knowledge-ingestions.store', $character), [
        'text' => 'Contesto privato',
    ])->assertUnauthorized();

    $this->actingAs(User::factory()->create())
        ->postJson(route('knowledge-ingestions.store', $character), [
            'text' => 'Contesto privato',
        ])
        ->assertForbidden();
});

test('valida formato e dimensione dei file prima di accodarli', function () {
    Queue::fake();
    $user = User::factory()->create();
    $character = knowledgeCharacter($user);

    $this->actingAs($user)
        ->postJson(route('knowledge-ingestions.store', $character), [
            'files' => [UploadedFile::fake()->create('payload.php', 2, 'text/x-php')],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('files.0');

    expect(KnowledgeIngestion::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('accoda testo privato senza attivare memorie prima della revisione', function () {
    Queue::fake();
    $user = User::factory()->create();
    $character = knowledgeCharacter($user);

    $this->actingAs($user)
        ->postJson(route('knowledge-ingestions.store', $character), [
            'text' => 'Preferisco una dieta vegetariana ricca di proteine.',
        ])
        ->assertAccepted()
        ->assertJsonPath('ingestions.0.status', 'pending');

    $ingestion = KnowledgeIngestion::query()->sole();

    expect($ingestion->temporary_text)->toContain('vegetariana')
        ->and($ingestion->getRawOriginal('temporary_text'))->not->toContain('vegetariana')
        ->and(Memory::query()->count())->toBe(0);
    Queue::assertPushed(
        ProcessKnowledgeIngestion::class,
        fn (ProcessKnowledgeIngestion $job): bool => $job->ingestionId === $ingestion->id,
    );
});

test('elabora lo specialista e mantiene le proposte fuori dalla memoria', function () {
    Http::preventStrayRequests();
    fakeStructuredKnowledge([[
        'character' => 'nutrizionista',
        'action' => 'upsert',
        'key' => 'dieta_preferita',
        'category' => 'alimentazione',
        'content' => 'L’utente segue una dieta vegetariana.',
        'importance' => 4,
        'confidence' => 0.95,
        'reason' => 'Pertinente alla nutrizione.',
    ]]);
    $user = User::factory()->create();
    $character = knowledgeCharacter($user);
    $ingestion = pendingKnowledgeIngestion($character);

    app()->call([new ProcessKnowledgeIngestion($ingestion->id), 'handle']);

    $ingestion->refresh();
    expect($ingestion->status)->toBe(KnowledgeIngestionStatus::AwaitingReview)
        ->and($ingestion->item_count)->toBe(1)
        ->and($ingestion->items()->sole()->character_id)->toBe($character->id)
        ->and(Memory::query()->count())->toBe(0);
});

test('scarta deterministicamente i target fuori ambito per uno specialista', function () {
    Http::preventStrayRequests();
    fakeStructuredKnowledge([[
        'character' => 'manager',
        'action' => 'upsert',
        'key' => 'budget',
        'category' => 'lavoro',
        'content' => 'Budget del progetto.',
        'importance' => 4,
        'confidence' => 0.9,
        'reason' => 'Materia lavorativa.',
    ]]);
    $user = User::factory()->create();
    $character = knowledgeCharacter($user);
    $ingestion = pendingKnowledgeIngestion($character, [
        'temporary_text' => 'Il budget del progetto è 20.000 euro.',
    ]);

    app()->call([new ProcessKnowledgeIngestion($ingestion->id), 'handle']);

    expect($ingestion->fresh()->item_count)->toBe(0)
        ->and(KnowledgeIngestionItem::query()->count())->toBe(0)
        ->and(Memory::query()->count())->toBe(0);
});

test('il globale distribuisce ai pertinenti e sintetizza solo con due supporti', function () {
    Http::preventStrayRequests();
    Http::fakeSequence()
        ->push([
            'choices' => [['message' => ['content' => json_encode(['changes' => [
                [
                    'character' => 'nutrizionista',
                    'action' => 'upsert',
                    'key' => 'dieta',
                    'category' => 'salute',
                    'content' => 'L’utente segue una dieta vegetariana.',
                    'importance' => 4,
                    'confidence' => 0.9,
                    'reason' => 'Pertinente.',
                ],
                [
                    'character' => 'manager',
                    'action' => 'upsert',
                    'key' => 'trasferte',
                    'category' => 'lavoro',
                    'content' => 'Le trasferte richiedono pasti vegetariani.',
                    'importance' => 3,
                    'confidence' => 0.85,
                    'reason' => 'Pertinente.',
                ],
            ]], JSON_THROW_ON_ERROR)]]],
        ])
        ->push([
            'choices' => [['message' => ['content' => json_encode(['changes' => [
                [
                    'character' => 'nutrizionista',
                    'action' => 'upsert',
                    'key' => 'dieta',
                    'category' => 'salute',
                    'content' => 'L’utente segue una dieta vegetariana.',
                    'importance' => 4,
                    'confidence' => 0.9,
                    'reason' => 'Pertinente.',
                ],
                [
                    'character' => 'manager',
                    'action' => 'upsert',
                    'key' => 'trasferte',
                    'category' => 'lavoro',
                    'content' => 'Le trasferte richiedono pasti vegetariani.',
                    'importance' => 3,
                    'confidence' => 0.85,
                    'reason' => 'Pertinente.',
                ],
            ]], JSON_THROW_ON_ERROR)]]],
        ])
        ->push([
            'choices' => [['message' => ['content' => json_encode(['changes' => [[
                'character' => 'global',
                'action' => 'upsert',
                'key' => 'trasferte_alimentazione',
                'category' => 'cross_domain',
                'content' => 'La pianificazione delle trasferte deve includere pasti vegetariani.',
                'importance' => 4,
                'confidence' => 0.85,
                'reason' => 'Unisce salute e lavoro.',
                'supporting_characters' => ['nutrizionista', 'manager'],
            ]]], JSON_THROW_ON_ERROR)]]],
        ]);
    $user = User::factory()->create();
    $global = knowledgeCharacter($user, [
        'slug' => 'global',
        'name' => 'Globale',
        'description' => 'Coordina tutti gli ambiti.',
        'is_global' => true,
    ]);
    $nutritionist = knowledgeCharacter($user);
    $manager = knowledgeCharacter($user, [
        'slug' => 'manager',
        'name' => 'Manager',
        'description' => 'Lavoro, progetti, budget e trasferte.',
    ]);
    $ingestion = pendingKnowledgeIngestion($global, [
        'temporary_text' => 'Sono vegetariano e durante le trasferte servono pasti adatti.',
    ]);

    app()->call([new ProcessKnowledgeIngestion($ingestion->id), 'handle']);

    expect($ingestion->fresh()->item_count)->toBe(3)
        ->and($ingestion->items()->pluck('character_id')->all())
        ->toContain($global->id, $nutritionist->id, $manager->id)
        ->and(Memory::query()->count())->toBe(0);
});

test('conferma selettivamente, registra la provenienza, purga ed è idempotente', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $character = knowledgeCharacter($user);
    Storage::disk('local')->put('knowledge/source.txt', 'dato temporaneo');
    $ingestion = pendingKnowledgeIngestion($character, [
        'status' => KnowledgeIngestionStatus::AwaitingReview,
        'source_type' => KnowledgeSourceType::File,
        'temporary_text' => 'testo estratto',
        'disk' => 'local',
        'path' => 'knowledge/source.txt',
        'item_count' => 2,
    ]);
    $selected = KnowledgeIngestionItem::factory()->for($ingestion, 'ingestion')->for($character)->create([
        'memory_key' => 'dieta',
        'content' => 'L’utente è vegetariano.',
    ]);
    KnowledgeIngestionItem::factory()->for($ingestion, 'ingestion')->for($character)->create([
        'memory_key' => 'bevanda',
        'content' => 'L’utente beve tè.',
    ]);

    $this->actingAs($user)
        ->postJson(route('knowledge-ingestions.confirm', $ingestion), [
            'selected_items' => [$selected->id],
        ])
        ->assertOk()
        ->assertJsonPath('status', 'purged');

    $this->assertDatabaseHas('memories', [
        'character_id' => $character->id,
        'memory_key' => 'dieta',
    ]);
    $this->assertDatabaseMissing('memories', [
        'character_id' => $character->id,
        'memory_key' => 'bevanda',
    ]);
    $this->assertDatabaseHas('memory_changes', [
        'source_knowledge_ingestion_id' => $ingestion->id,
        'action' => 'create',
    ]);
    expect($ingestion->fresh()->status)->toBe(KnowledgeIngestionStatus::Purged)
        ->and($ingestion->fresh()->temporary_text)->toBeNull()
        ->and($ingestion->items()->count())->toBe(0);
    expect(Storage::disk('local')->exists('knowledge/source.txt'))->toBeFalse();

    $this->postJson(route('knowledge-ingestions.confirm', $ingestion), [
        'selected_items' => [],
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Importazione confermata: 0 memorie aggiornate.');

    expect(Memory::query()->count())->toBe(1);
});

test('rifiuto e scadenza cancellano fisicamente i dati senza memorie', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $character = knowledgeCharacter($user);
    Storage::disk('local')->put('knowledge/reject.txt', 'segreto');
    $rejected = pendingKnowledgeIngestion($character, [
        'status' => KnowledgeIngestionStatus::AwaitingReview,
        'source_type' => KnowledgeSourceType::File,
        'disk' => 'local',
        'path' => 'knowledge/reject.txt',
    ]);
    KnowledgeIngestionItem::factory()->for($rejected, 'ingestion')->for($character)->create();

    $this->actingAs($user)
        ->postJson(route('knowledge-ingestions.reject', $rejected))
        ->assertOk();

    expect(Storage::disk('local')->exists('knowledge/reject.txt'))->toBeFalse();
    expect($rejected->fresh()->status)->toBe(KnowledgeIngestionStatus::Purged)
        ->and(Memory::query()->count())->toBe(0);

    Storage::disk('local')->put('knowledge/expired.txt', 'segreto scaduto');
    $expired = pendingKnowledgeIngestion($character, [
        'source_type' => KnowledgeSourceType::File,
        'disk' => 'local',
        'path' => 'knowledge/expired.txt',
        'expires_at' => now()->subMinute(),
    ]);
    $this->artisan('app:purge-expired-knowledge-ingestions')->assertSuccessful();

    expect(Storage::disk('local')->exists('knowledge/expired.txt'))->toBeFalse();
    expect($expired->fresh()->status)->toBe(KnowledgeIngestionStatus::Purged);
});

test('rimette in coda le importazioni bloccate senza toccare quelle in corso', function () {
    Queue::fake();
    $user = User::factory()->create();
    $character = knowledgeCharacter($user);

    $stalled = pendingKnowledgeIngestion($character, [
        'status' => KnowledgeIngestionStatus::Processing,
    ]);
    $stalled->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

    $running = pendingKnowledgeIngestion($character, [
        'status' => KnowledgeIngestionStatus::Processing,
    ]);
    $reviewable = pendingKnowledgeIngestion($character, [
        'status' => KnowledgeIngestionStatus::AwaitingReview,
    ]);
    $reviewable->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

    $this->artisan('app:requeue-stalled-knowledge-ingestions')->assertSuccessful();

    expect($stalled->fresh()->status)->toBe(KnowledgeIngestionStatus::Pending)
        ->and($running->fresh()->status)->toBe(KnowledgeIngestionStatus::Processing)
        ->and($reviewable->fresh()->status)->toBe(KnowledgeIngestionStatus::AwaitingReview);

    Queue::assertPushed(
        ProcessKnowledgeIngestion::class,
        fn (ProcessKnowledgeIngestion $job): bool => $job->ingestionId === $stalled->id,
    );
    Queue::assertPushed(ProcessKnowledgeIngestion::class, 1);
});

test('non rimette in coda le importazioni scadute', function () {
    Queue::fake();
    $user = User::factory()->create();
    $character = knowledgeCharacter($user);

    $expired = pendingKnowledgeIngestion($character, [
        'status' => KnowledgeIngestionStatus::Processing,
        'expires_at' => now()->subMinute(),
    ]);
    $expired->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

    $this->artisan('app:requeue-stalled-knowledge-ingestions')->assertSuccessful();

    expect($expired->fresh()->status)->toBe(KnowledgeIngestionStatus::Processing);
    Queue::assertNothingPushed();
});

test('isola anteprima e conferma tra utenti', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $character = knowledgeCharacter($owner);
    $ingestion = pendingKnowledgeIngestion($character, [
        'status' => KnowledgeIngestionStatus::AwaitingReview,
    ]);

    $this->actingAs($attacker)
        ->getJson(route('knowledge-ingestions.show', $ingestion))
        ->assertForbidden();
    $this->postJson(route('knowledge-ingestions.confirm', $ingestion), [
        'selected_items' => [],
    ])->assertForbidden();
    $this->postJson(route('knowledge-ingestions.reject', $ingestion))
        ->assertForbidden();
});

test('estrae DOCX e produce chunk sovrapposti entro i limiti', function () {
    Storage::fake('local');
    config([
        'knowledge.chunk_characters' => 1000,
        'knowledge.chunk_overlap' => 100,
    ]);
    $document = new PhpWord;
    $document->addSection()->addText('Piano alimentare vegetariano con legumi ogni lunedì.');
    $path = Storage::disk('local')->path('knowledge/document.docx');
    Storage::disk('local')->makeDirectory('knowledge');
    IOFactory::createWriter($document, 'Word2007')->save($path);
    $user = User::factory()->create();
    $character = knowledgeCharacter($user);
    $ingestion = pendingKnowledgeIngestion($character, [
        'source_type' => KnowledgeSourceType::File,
        'original_filename' => 'document.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'disk' => 'local',
        'path' => 'knowledge/document.docx',
        'size_bytes' => Storage::disk('local')->size('knowledge/document.docx'),
        'temporary_text' => null,
    ]);

    $text = app(KnowledgeExtractor::class)->extract($ingestion);
    $chunks = app(KnowledgeTextChunker::class)->chunk(str_repeat('Informazione durevole. ', 100));

    expect($text)->toContain('Piano alimentare vegetariano')
        ->and($chunks)->toHaveCount(3)
        ->and($chunks[0]['reference'])->toBe('sezione 1');
});

test('la vision usa il profilo premium e invia un payload multimodale', function () {
    config([
        'ai.dialogue.api_key' => 'premium-key',
        'ai.dialogue.url' => 'https://premium.example/v1',
        'ai.dialogue.model' => 'gpt-5.4-mini',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://premium.example/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Testo estratto']]],
        ]),
    ]);

    expect(app(AiChatClient::class)->extractTextFromImage('image/png', 'binary-image'))
        ->toBe('Testo estratto');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://premium.example/v1/chat/completions'
            && data_get($request->data(), 'messages.1.content.1.type') === 'image_url'
            && str_starts_with(
                (string) data_get($request->data(), 'messages.1.content.1.image_url.url'),
                'data:image/png;base64,',
            );
    });
});
