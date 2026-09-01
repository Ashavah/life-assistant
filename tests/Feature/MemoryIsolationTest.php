<?php

use App\CharacterSlug;
use App\Models\Character;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use App\Models\User;
use App\Services\ChatContextBuilder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('uno specialista riceve solo la propria memoria e conversazione', function () {
    $doctor = Character::factory()->create([
        'slug' => CharacterSlug::Doctor,
        'name' => 'Dottore',
        'system_prompt' => 'PROMPT DOTTORE',
    ]);
    $manager = Character::factory()->create([
        'slug' => CharacterSlug::Manager,
        'name' => 'Manager',
        'system_prompt' => 'PROMPT MANAGER',
    ]);
    $doctorConversation = Conversation::factory()->for($doctor)->create();
    $managerConversation = Conversation::factory()->for($manager)->create();
    Memory::factory()->for($doctor)->create([
        'memory_key' => 'allergia_penicillina',
        'content' => 'Allergia alla penicillina',
    ]);
    Memory::factory()->for($manager)->create([
        'memory_key' => 'budget_progetto',
        'content' => 'Budget del progetto: 5000 euro',
    ]);
    Message::factory()->for($doctorConversation)->create([
        'content' => 'Ho mal di testa',
    ]);
    Message::factory()->for($managerConversation)->create([
        'content' => 'La scadenza del progetto è venerdì',
    ]);

    $context = app(ChatContextBuilder::class)->build($doctorConversation);

    expect($context['system_prompt'])
        ->toContain('PROMPT DOTTORE')
        ->toContain('Allergia alla penicillina')
        ->not->toContain('PROMPT MANAGER')
        ->not->toContain('Budget del progetto');
    expect($context['messages'])
        ->toHaveCount(1)
        ->and($context['messages'][0]['content'])->toBe('Ho mal di testa');
});

test('il personaggio globale legge le memorie di tutti ma non le altre conversazioni', function () {
    $global = Character::factory()->global()->create([
        'system_prompt' => 'PROMPT GLOBALE',
    ]);
    $doctor = Character::factory()->create([
        'slug' => CharacterSlug::Doctor,
        'name' => 'Dottore',
    ]);
    $manager = Character::factory()->create([
        'slug' => CharacterSlug::Manager,
        'name' => 'Manager',
    ]);
    $globalConversation = Conversation::factory()->for($global)->create();
    $doctorConversation = Conversation::factory()->for($doctor)->create();
    Memory::factory()->for($doctor)->create([
        'memory_key' => 'sonno',
        'content' => 'Dorme sei ore per notte',
    ]);
    Memory::factory()->for($manager)->create([
        'memory_key' => 'obiettivo',
        'content' => 'Vuole cambiare lavoro',
    ]);
    Message::factory()->for($globalConversation)->create([
        'content' => 'Come posso organizzarmi?',
    ]);
    Message::factory()->for($doctorConversation)->create([
        'content' => 'CONTENUTO PRIVATO DELLA CHAT MEDICA',
    ]);

    $context = app(ChatContextBuilder::class)->build($globalConversation);

    expect($context['system_prompt'])
        ->toContain('PROMPT GLOBALE')
        ->toContain('Dorme sei ore per notte')
        ->toContain('Vuole cambiare lavoro');
    expect(json_encode($context['messages'], JSON_THROW_ON_ERROR))
        ->toContain('Come posso organizzarmi?')
        ->not->toContain('CONTENUTO PRIVATO DELLA CHAT MEDICA');
});

test('uno specialista riceve i nomi degli altri assistenti per lo smistamento', function () {
    $doctor = Character::factory()->create([
        'slug' => CharacterSlug::Doctor,
        'name' => 'Dottore',
        'description' => 'Salute, benessere e abitudini',
        'sort_order' => 1,
    ]);
    Character::factory()->create([
        'slug' => CharacterSlug::Secretary,
        'name' => 'Segretaria',
        'description' => 'Agenda, scadenze e organizzazione',
        'sort_order' => 3,
    ]);
    $conversation = Conversation::factory()->for($doctor)->create();

    $context = app(ChatContextBuilder::class)->build($conversation);

    expect($context['system_prompt'])
        ->toContain('Il tuo ambito è: Salute, benessere e abitudini.')
        ->toContain('- Segretaria: Agenda, scadenze e organizzazione')
        ->toContain('non è di tua competenza')
        ->not->toContain('- Dottore: Salute, benessere e abitudini');
});

test('senza altri assistenti lo specialista dichiara solo la non competenza', function () {
    $doctor = Character::factory()->create([
        'slug' => CharacterSlug::Doctor,
        'description' => 'Salute, benessere e abitudini',
    ]);
    $conversation = Conversation::factory()->for($doctor)->create();

    $context = app(ChatContextBuilder::class)->build($conversation);

    expect($context['system_prompt'])
        ->toContain('Non esistono altri assistenti a cui indirizzare l\'utente.')
        ->not->toContain('Altri assistenti disponibili');
});

test('il personaggio globale copre tutti gli ambiti senza smistare', function () {
    $global = Character::factory()->global()->create(['sort_order' => 0]);
    Character::factory()->create([
        'slug' => CharacterSlug::Manager,
        'name' => 'Manager',
        'description' => 'Obiettivi, lavoro e decisioni',
        'sort_order' => 2,
    ]);
    $conversation = Conversation::factory()->for($global)->create();

    $context = app(ChatContextBuilder::class)->build($conversation);

    expect($context['system_prompt'])
        ->toContain('Copri in modo multidisciplinare')
        ->toContain('- Manager: Obiettivi, lavoro e decisioni')
        ->toContain('non è di tua competenza')
        ->not->toContain('indirizza l\'utente');
});

test('il globale include ambito e memoria degli specialisti personalizzati', function () {
    $global = Character::factory()->global()->create();
    $trainer = Character::factory()->create([
        'slug' => 'personal-trainer',
        'name' => 'Personal Trainer',
        'description' => 'Allenamento e attività fisica',
    ]);
    Memory::factory()->for($trainer)->create([
        'memory_key' => 'obiettivo_corsa',
        'content' => 'Vuole correre cinque chilometri',
    ]);
    $conversation = Conversation::factory()->for($global)->create();

    $context = app(ChatContextBuilder::class)->build($conversation);

    expect($context['system_prompt'])
        ->toContain('Personal Trainer: Allenamento e attività fisica')
        ->toContain('Vuole correre cinque chilometri');
});

test('le memorie archiviate non entrano nel contesto', function () {
    $doctor = Character::factory()->create([
        'slug' => CharacterSlug::Doctor,
        'system_prompt' => 'PROMPT DOTTORE',
    ]);
    $conversation = Conversation::factory()->for($doctor)->create();
    Memory::factory()->for($doctor)->archived()->create([
        'memory_key' => 'vecchio_fatto',
        'content' => 'Fatto non più valido',
    ]);

    $context = app(ChatContextBuilder::class)->build($conversation);

    expect($context['system_prompt'])->not->toContain('Fatto non più valido');
});
