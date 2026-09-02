<?php

use App\CharacterSlug;
use App\ConversationStatus;
use App\Jobs\ConsolidateConversationMemory;
use App\Models\Character;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\MemoryChange;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

/**
 * @param  array{summary: ?string, changes: array<int, mixed>}  $memory
 */
function fakeChatAndMemory(string $reply, array $memory = ['summary' => 'Riassunto della chat.', 'changes' => []]): void
{
    $memory['title'] ??= 'Titolo generato';

    Http::fake(function (Request $request) use ($reply, $memory) {
        if (isset($request->data()['response_format'])) {
            return Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode($memory, JSON_THROW_ON_ERROR),
                    ],
                ]],
            ]);
        }

        return Http::response([
            'model' => 'deepseek-chat',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => $reply,
                ],
            ]],
            'usage' => ['total_tokens' => 42],
        ]);
    });
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    config([
        'ai.api_key' => 'test-key',
        'ai.url' => 'https://api.deepseek.com/v1/chat/completions',
        'ai.model' => 'deepseek-chat',
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
    fakeChatAndMemory('Ti consiglio di riposare.');
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
        ->assertJsonPath('conversation_title', 'Titolo generato');

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
    expect($conversation->fresh()->title)->toBe('Titolo generato');

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();
        $serialized = json_encode($payload['messages'], JSON_THROW_ON_ERROR);

        return str_contains($serialized, 'PROMPT MEDICO ISOLATO')
            && str_contains($serialized, 'Allergia alle arachidi')
            && str_contains($serialized, 'Sono stanco');
    });
});

test('restituisce la risposta anche in html formattato', function () {
    Http::preventStrayRequests();
    fakeChatAndMemory("Ecco il piano:\n- **Equilibrio**: 3 serie\n- **Calf raises**: 12 ripetizioni");
    $conversation = Conversation::factory()
        ->for(Character::factory()->create(['slug' => CharacterSlug::Doctor]))
        ->create();

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'come rinforzo le caviglie?',
    ])
        ->assertOk()
        ->assertJsonPath('reply_html', "<p>Ecco il piano:</p>\n<ul>\n<li><strong>Equilibrio</strong>: 3 serie</li>\n<li><strong>Calf raises</strong>: 12 ripetizioni</li>\n</ul>\n");
});

test('mostra i messaggi dell’assistente come markdown formattato e quelli utente come testo', function () {
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->create();
    Message::factory()->for($conversation)->create(['content' => 'testo **non** interpretato']);
    Message::factory()->for($conversation)->assistant()->create(['content' => 'ecco un **fatto** importante']);

    $this->get(route('home', ['conversation' => $conversation->id]))
        ->assertOk()
        ->assertSee('ecco un <strong>fatto</strong> importante', false)
        ->assertSee('testo **non** interpretato', false);
});

test('non interpreta html contenuto nelle risposte del modello', function () {
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->create();
    Message::factory()->for($conversation)->assistant()->create([
        'content' => 'attento <script>alert(1)</script> e [link](javascript:alert(1))',
    ]);

    $this->get(route('home', ['conversation' => $conversation->id]))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('javascript:alert(1)', false);
});

test('invia lo storico in ordine cronologico con il nuovo messaggio per ultimo', function () {
    Http::preventStrayRequests();
    fakeChatAndMemory('Bevi acqua tiepida.');
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
    fakeChatAndMemory('Ricevuto.');
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
        ->and($conversation->fresh()->summary)->toBe('L’utente segnala emicrania ricorrente.')
        ->and($conversation->fresh()->context_summary)->toBe('L’utente segnala emicrania ricorrente.')
        ->and($conversation->fresh()->memory_consolidated_through_message_id)->toBeNull()
        ->and($conversation->messages()->count())->toBe(0);

    $this->get(route('home', [
        'character' => $doctor->slug,
        'conversation' => $conversation->id,
    ]))
        ->assertOk()
        ->assertSee('L’utente segnala emicrania ricorrente.')
        ->assertDontSee('Ogni lunedì ho emicrania');
});

test('uno specialista salva memoria a ogni messaggio e ignora gli altri personaggi', function () {
    Http::preventStrayRequests();
    fakeChatAndMemory('Evita la penicillina.', [
        'summary' => 'Allergia confermata.',
        'changes' => [
            [
                'character' => 'doctor',
                'action' => 'upsert',
                'key' => 'allergia_penicillina',
                'category' => 'allergie',
                'content' => 'Allergia alla penicillina',
                'importance' => 5,
                'confidence' => 1,
                'reason' => 'Fatto medico durevole',
            ],
            [
                'character' => 'manager',
                'action' => 'upsert',
                'key' => 'non_dovrebbe_entrare',
                'category' => 'lavoro',
                'content' => 'Questo fatto appartiene al manager',
                'importance' => 5,
                'confidence' => 1,
                'reason' => 'Tentativo di unione non autorizzata',
            ],
        ],
    ]);
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    Character::factory()->create(['slug' => CharacterSlug::Manager]);
    $conversation = Conversation::factory()->for($doctor)->create();

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Sono allergico alla penicillina',
    ])->assertOk();

    $this->assertDatabaseHas('memories', [
        'character_id' => $doctor->id,
        'memory_key' => 'allergia_penicillina',
    ]);
    expect(Memory::query()->where('memory_key', 'non_dovrebbe_entrare')->count())->toBe(0)
        ->and($conversation->fresh()->context_summary)->toBe('Allergia confermata.');
});

test('il consolidamento incrementale assorbe ogni messaggio una volta sola', function () {
    Http::preventStrayRequests();
    $structuredPayloads = [];
    Http::fake(function (Request $request) use (&$structuredPayloads) {
        if (isset($request->data()['response_format'])) {
            $structuredPayloads[] = $request->data()['messages'][1]['content'];

            return Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'Riassunto cumulativo.',
                            'changes' => [],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ]);
        }

        return Http::response([
            'choices' => [['message' => ['content' => 'Ricevuto.']]],
        ]);
    });
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->create();

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Primo fatto medico',
    ])->assertOk();
    $firstCursor = $conversation->fresh()->memory_consolidated_through_message_id;

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Seconda precisazione medica',
    ])->assertOk();

    expect($structuredPayloads)->toHaveCount(2)
        ->and($structuredPayloads[0])->toContain('Primo fatto medico')
        ->and($structuredPayloads[1])->toContain('Seconda precisazione medica')
        ->and($structuredPayloads[1])->not->toContain('Primo fatto medico')
        ->and($conversation->fresh()->memory_consolidated_through_message_id)->toBeGreaterThan($firstCursor);
});

test('la chat globale unisce memorie sugli specialisti e può salvare la propria', function () {
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
                                    'key' => 'settimana_impegnata',
                                    'category' => 'sintesi',
                                    'content' => 'Settimana con visita medica e scadenza di progetto',
                                    'importance' => 3,
                                    'confidence' => 0.9,
                                    'reason' => 'Sintesi trasversale del globale',
                                ],
                                [
                                    'character' => 'inesistente',
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
        ->assertJsonPath('reply', 'Organizziamo entrambe le priorità.');

    $this->assertDatabaseHas('memories', [
        'character_id' => $secretary->id,
        'memory_key' => 'visita_martedi',
    ]);
    $this->assertDatabaseHas('memories', [
        'character_id' => $manager->id,
        'memory_key' => 'consegna_progetto',
    ]);
    $this->assertDatabaseHas('memories', [
        'character_id' => $global->id,
        'memory_key' => 'settimana_impegnata',
    ]);
    expect(Memory::query()->whereBelongsTo($doctor)->count())->toBe(0)
        ->and($conversation->fresh()->context_summary)->toBe('Visita medica martedì e consegna progetto venerdì.');
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
        ->and($conversation->messages()->count())->toBe(1)
        ->and(Memory::query()->count())->toBe(0);
});

test('rifiuta il provider senza creare una risposta assistant', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'error' => ['message' => 'modello non disponibile'],
        ], 503),
    ]);
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->create();

    $response = $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Ciao',
    ]);

    $response
        ->assertStatus(502)
        ->assertJsonPath('message', 'L’endpoint IA ha rifiutato la richiesta. Controlla chiave, modello e URL.');

    expect($response->json())->not->toHaveKey('raw');

    expect($conversation->messages()->where('role', 'user')->count())->toBe(1)
        ->and($conversation->messages()->where('role', 'assistant')->count())->toBe(0);
});

test('accoda il consolidamento invece di eseguirlo durante la richiesta', function () {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Ti consiglio di riposare.']]],
        ]),
    ]);
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->create();

    $this->postJson(route('conversations.messages.store', $conversation), [
        'message' => 'Sono stanco',
    ])->assertOk();

    Http::assertSentCount(1);
    expect(Memory::query()->count())->toBe(0);
    Queue::assertPushed(
        ConsolidateConversationMemory::class,
        fn (ConsolidateConversationMemory $job): bool => $job->conversationId === $conversation->id,
    );
});

test('il consolidamento accodato non tocca una conversazione già chiusa', function () {
    Http::preventStrayRequests();
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->closed()->create();
    Message::factory()->for($conversation)->create();

    app()->call([new ConsolidateConversationMemory($conversation->id), 'handle']);

    expect(Memory::query()->count())->toBe(0);
});

test('elimina una chat attiva senza chiamare l’IA e annulla le memorie di quella conversazione', function () {
    Http::preventStrayRequests();
    Queue::fake();
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->create();
    $otherConversation = Conversation::factory()->for($doctor)->create();
    Message::factory()->for($conversation)->create(['content' => 'Segreto da non conservare']);

    $created = Memory::factory()->for($doctor)->create([
        'memory_key' => 'fatto_da_questa_chat',
        'content' => 'Creato da questa chat',
        'source_conversation_id' => $conversation->id,
    ]);
    MemoryChange::factory()->for($created)->for($doctor)->create([
        'source_conversation_id' => $conversation->id,
        'action' => 'create',
        'before' => null,
    ]);

    $kept = Memory::factory()->for($doctor)->create([
        'memory_key' => 'allergia_preesistente',
        'content' => 'Allergia lieve alle arachidi',
        'source_conversation_id' => $otherConversation->id,
    ]);
    MemoryChange::factory()->for($kept)->for($doctor)->create([
        'source_conversation_id' => $conversation->id,
        'action' => 'update',
        'before' => $kept->only([
            'category',
            'memory_key',
            'content',
            'importance',
            'confidence',
            'source_conversation_id',
            'source_message_id',
            'archived_at',
        ]),
    ]);
    $kept->update([
        'content' => 'Allergia severa alle arachidi',
        'source_conversation_id' => $conversation->id,
    ]);

    $this->deleteJson(route('conversations.destroy', $conversation))
        ->assertOk()
        ->assertJsonPath('message', 'Conversazione eliminata. Nessuna memoria è stata salvata da questa chat.');

    Http::assertNothingSent();
    Queue::assertNothingPushed();
    $this->assertModelMissing($conversation);
    $this->assertModelMissing($created);
    expect(Message::query()->where('conversation_id', $conversation->id)->count())->toBe(0)
        ->and($kept->fresh()->content)->toBe('Allergia lieve alle arachidi')
        ->and($kept->fresh()->source_conversation_id)->toBe($otherConversation->id);
});

test('l’eliminazione di una chat chiusa non tocca le memorie già salvate', function () {
    Http::preventStrayRequests();
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->closed()->create();
    $memory = Memory::factory()->for($doctor)->create([
        'memory_key' => 'emicrania_ricorrente',
        'content' => 'Emicrania il lunedì',
        'source_conversation_id' => $conversation->id,
    ]);

    $this->deleteJson(route('conversations.destroy', $conversation))
        ->assertOk();

    $this->assertModelMissing($conversation);
    expect($memory->fresh()->content)->toBe('Emicrania il lunedì')
        ->and($memory->fresh()->source_conversation_id)->toBeNull();
});

test('un altro utente non può eliminare una conversazione', function () {
    $owner = User::factory()->create();
    $doctor = Character::factory()->for($owner)->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->create();

    $this->actingAs(User::factory()->create())
        ->deleteJson(route('conversations.destroy', $conversation))
        ->assertForbidden();

    $this->assertModelExists($conversation);
});

test('valida il contenuto del messaggio', function () {
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);
    $conversation = Conversation::factory()->for($doctor)->create();

    $this->postJson(route('conversations.messages.store', $conversation), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');
});
