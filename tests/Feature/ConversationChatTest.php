<?php

use App\CharacterSlug;
use App\ConversationStatus;
use App\Models\Character;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    config([
        'ai.api_key' => 'test-key',
        'ai.url' => 'https://api.deepseek.com/v1/chat/completions',
        'ai.model' => 'deepseek-chat',
        'ai.debug' => true,
    ]);
});

test('crea più conversazioni indipendenti per lo stesso personaggio', function () {
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);

    $this->postJson(route('conversations.store'), ['character_id' => $doctor->id])
        ->assertCreated()
        ->assertJsonPath('conversation.title', 'Nuova conversazione');
    $this->postJson(route('conversations.store'), ['character_id' => $doctor->id])
        ->assertCreated();

    expect(Conversation::query()->whereBelongsTo($doctor)->count())->toBe(2);
});

test('invia il contesto isolato e salva entrambi i messaggi', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'model' => 'deepseek-chat',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Ti consiglio di riposare.',
                ],
            ]],
            'usage' => ['total_tokens' => 42],
        ]),
    ]);
    $doctor = Character::factory()->create([
        'slug' => CharacterSlug::Doctor,
        'system_prompt' => 'PROMPT MEDICO ISOLATO',
    ]);
    $conversation = Conversation::factory()->for($doctor)->create(['title' => null]);
    Memory::factory()->for($doctor)->create([
        'memory_key' => 'allergia',
        'content' => 'Allergia alle arachidi',
    ]);

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Sono stanco',
    ])
        ->assertOk()
        ->assertJsonPath('reply', 'Ti consiglio di riposare.')
        ->assertJsonPath('raw.status', 200);

    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Sono stanco',
    ]);
    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Ti consiglio di riposare.',
    ]);
    expect($conversation->fresh()->title)->toBe('Sono stanco');

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();
        $serialized = json_encode($payload['messages'], JSON_THROW_ON_ERROR);

        return str_contains($serialized, 'PROMPT MEDICO ISOLATO')
            && str_contains($serialized, 'Allergia alle arachidi')
            && str_contains($serialized, 'Sono stanco');
    });
});

test('invia lo storico in ordine cronologico con il nuovo messaggio per ultimo', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Bevi acqua tiepida.',
                ],
            ]],
        ]),
    ]);
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->create();
    Message::factory()->for($conversation)->create(['content' => 'chi sei?']);
    Message::factory()->for($conversation)->assistant()->create(['content' => 'Sono il Dottore.']);

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'ho mal di gola, cosa devo fare',
    ])->assertOk();

    Http::assertSent(function (Request $request): bool {
        return $request->data()['messages'] === [
            ['role' => 'system', 'content' => $request->data()['messages'][0]['content']],
            ['role' => 'user', 'content' => 'chi sei?'],
            ['role' => 'assistant', 'content' => 'Sono il Dottore.'],
            ['role' => 'user', 'content' => 'ho mal di gola, cosa devo fare'],
        ];
    });
});

test('mantiene i messaggi più recenti quando lo storico supera il limite', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Ricevuto.',
                ],
            ]],
        ]),
    ]);
    config(['ai.max_history_messages' => 3]);
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->create();
    Message::factory()->for($conversation)->create(['content' => 'messaggio molto vecchio']);
    Message::factory()->for($conversation)->assistant()->create(['content' => 'risposta vecchia']);
    Message::factory()->for($conversation)->create(['content' => 'penultimo messaggio']);

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'ultimo messaggio',
    ])->assertOk();

    Http::assertSent(function (Request $request): bool {
        $history = array_values(array_filter(
            $request->data()['messages'],
            fn (array $message): bool => $message['role'] !== 'system',
        ));

        return $history === [
            ['role' => 'assistant', 'content' => 'risposta vecchia'],
            ['role' => 'user', 'content' => 'penultimo messaggio'],
            ['role' => 'user', 'content' => 'ultimo messaggio'],
        ];
    });
});

test('chiude una chat specialista consolidando memoria e audit', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'summary' => 'L’utente segnala emicrania ricorrente.',
                        'changes' => [[
                            'character' => 'doctor',
                            'action' => 'upsert',
                            'key' => 'emicrania_ricorrente',
                            'category' => 'sintomi',
                            'content' => 'Riferisce emicrania ricorrente il lunedì',
                            'importance' => 4,
                            'confidence' => 0.95,
                            'reason' => 'Informazione sanitaria durevole',
                        ]],
                    ], JSON_THROW_ON_ERROR),
                ],
            ]],
        ]),
    ]);
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->create();
    Message::factory()->for($conversation)->create([
        'content' => 'Ogni lunedì ho emicrania',
    ]);

    $this->postJson(route('conversations.closed.store', $conversation))
        ->assertOk()
        ->assertJsonPath('memory_changes', 1);

    $this->assertDatabaseHas('memories', [
        'character_id' => $doctor->id,
        'memory_key' => 'emicrania_ricorrente',
        'content' => 'Riferisce emicrania ricorrente il lunedì',
    ]);
    $this->assertDatabaseHas('memory_changes', [
        'character_id' => $doctor->id,
        'action' => 'create',
    ]);
    expect($conversation->fresh()->status)->toBe(ConversationStatus::Closed)
        ->and($conversation->fresh()->summary)->toBe('L’utente segnala emicrania ricorrente.');
});

test('la chat globale distribuisce automaticamente memorie senza contaminazione', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if (isset($request->data()['response_format'])) {
            return Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode([
                            'summary' => 'Visita medica martedì e consegna progetto venerdì.',
                            'changes' => [
                                [
                                    'character' => 'secretary',
                                    'action' => 'upsert',
                                    'key' => 'visita_martedi',
                                    'category' => 'appuntamenti',
                                    'content' => 'Visita medica martedì alle 10',
                                    'importance' => 5,
                                    'confidence' => 1,
                                    'reason' => 'Appuntamento da ricordare',
                                ],
                                [
                                    'character' => 'manager',
                                    'action' => 'upsert',
                                    'key' => 'consegna_progetto',
                                    'category' => 'scadenze',
                                    'content' => 'Consegna progetto venerdì',
                                    'importance' => 5,
                                    'confidence' => 1,
                                    'reason' => 'Scadenza lavorativa',
                                ],
                                [
                                    'character' => 'global',
                                    'action' => 'upsert',
                                    'key' => 'contaminazione_non_consentita',
                                    'category' => 'general',
                                    'content' => 'Questo fatto deve essere ignorato',
                                    'importance' => 5,
                                    'confidence' => 1,
                                    'reason' => 'Target non autorizzato',
                                ],
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ]);
        }

        return Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Organizziamo entrambe le priorità.',
                ],
            ]],
        ]);
    });
    $global = Character::factory()->global()->create();
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $manager = Character::factory()->create(['slug' => CharacterSlug::Manager]);
    $secretary = Character::factory()->create(['slug' => CharacterSlug::Secretary]);
    $conversation = Conversation::factory()->for($global)->create();

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Martedì alle 10 ho una visita; venerdì consegno il progetto.',
    ])
        ->assertOk()
        ->assertJsonPath('reply', 'Organizziamo entrambe le priorità.')
        ->assertJsonPath('memory_changes', 2);

    $this->assertDatabaseHas('memories', [
        'character_id' => $secretary->id,
        'memory_key' => 'visita_martedi',
    ]);
    $this->assertDatabaseHas('memories', [
        'character_id' => $manager->id,
        'memory_key' => 'consegna_progetto',
    ]);
    expect(Memory::query()->whereBelongsTo($doctor)->count())->toBe(0)
        ->and(Memory::query()->whereBelongsTo($global)->count())->toBe(0);
});

test('non consente messaggi in una conversazione chiusa', function () {
    Http::preventStrayRequests();
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->closed()->create();

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Nuovo messaggio',
    ])
        ->assertConflict()
        ->assertJsonPath('message', 'Questa conversazione è chiusa.');

    expect(Message::query()->whereBelongsTo($conversation)->count())->toBe(0);
    Http::assertNothingSent();
});

test('aggiorna una memoria esistente senza duplicarla', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'summary' => 'Aggiornamento allergia.',
                        'changes' => [[
                            'character' => 'doctor',
                            'action' => 'upsert',
                            'key' => 'allergia',
                            'category' => 'allergie',
                            'content' => 'Allergia severa alle arachidi',
                            'importance' => 5,
                            'confidence' => 1,
                            'reason' => 'Il fatto è stato precisato',
                        ]],
                    ], JSON_THROW_ON_ERROR),
                ],
            ]],
        ]),
    ]);
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $memory = Memory::factory()->for($doctor)->create([
        'memory_key' => 'allergia',
        'content' => 'Possibile allergia alle arachidi',
    ]);
    $conversation = Conversation::factory()->for($doctor)->create();
    Message::factory()->for($conversation)->create([
        'content' => 'Confermo che l’allergia alle arachidi è severa',
    ]);

    $this->postJson(route('conversations.closed.store', $conversation))
        ->assertOk()
        ->assertJsonPath('memory_changes', 1);

    expect(Memory::query()->whereBelongsTo($doctor)->count())->toBe(1)
        ->and($memory->fresh()->content)->toBe('Allergia severa alle arachidi');
    $this->assertDatabaseHas('memory_changes', [
        'memory_id' => $memory->id,
        'action' => 'update',
    ]);
});

test('mantiene aperta la conversazione se il consolidamento non restituisce json valido', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'questa non è una risposta JSON',
                ],
            ]],
        ]),
    ]);
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->create();
    Message::factory()->for($conversation)->create();

    $this->postJson(route('conversations.closed.store', $conversation))
        ->assertConflict()
        ->assertJsonPath('message', 'La risposta IA per la memoria non è JSON valido.');

    expect($conversation->fresh()->status)->toBe(ConversationStatus::Active)
        ->and(Memory::query()->count())->toBe(0);
});

test('espone in debug il rifiuto del provider senza creare una risposta assistant', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'error' => ['message' => 'modello non disponibile'],
        ], 503),
    ]);
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->create();

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Ciao',
    ])
        ->assertStatus(502)
        ->assertJsonPath('raw.status', 503)
        ->assertJsonPath('raw.body.error.message', 'modello non disponibile');

    expect($conversation->messages()->where('role', 'user')->count())->toBe(1)
        ->and($conversation->messages()->where('role', 'assistant')->count())->toBe(0);
});

test('valida il contenuto del messaggio', function () {
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->create();

    $this->postJson(route('conversations.messages.store', $conversation), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');
});
