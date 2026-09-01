<?php

use App\CharacterSlug;
use App\Models\Character;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\User;
use App\Services\ChatContextBuilder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('ogni utente vede solo i propri personaggi e conversazioni', function () {
    $mario = User::factory()->create();
    $lucia = User::factory()->create();
    $marioDoctor = Character::factory()->for($mario)->create([
        'slug' => CharacterSlug::Doctor,
        'name' => 'Medico Mario',
    ]);
    $luciaDoctor = Character::factory()->for($lucia)->create([
        'slug' => CharacterSlug::Doctor,
        'name' => 'Medico Lucia',
    ]);
    Conversation::factory()->for($marioDoctor)->create(['title' => 'Chat Mario']);
    $luciaConversation = Conversation::factory()->for($luciaDoctor)->create(['title' => 'Chat Lucia']);

    $this->actingAs($mario)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Medico Mario')
        ->assertSee('Chat Mario')
        ->assertDontSee('Medico Lucia')
        ->assertDontSee('Chat Lucia');

    $this->postJson(route('conversations.messages.store', $luciaConversation), [
        'message' => 'Tentativo cross tenant',
    ])->assertForbidden();
});

test('non può modificare o usare un personaggio di un altro utente', function () {
    $mario = User::factory()->create();
    $lucia = User::factory()->create();
    $luciaDoctor = Character::factory()->for($lucia)->create();
    $this->actingAs($mario);

    $this->patchJson(route('characters.update', $luciaDoctor), [
        'name' => 'Rubato',
        'description' => 'Descrizione',
        'system_prompt' => 'Prompt',
        'tone' => 'Tono',
    ])->assertForbidden();

    $this->postJson(route('conversations.store'), [
        'character_id' => $luciaDoctor->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('character_id');
});

test('il contesto globale non include memorie di altri utenti', function () {
    $mario = User::factory()->create();
    $lucia = User::factory()->create();
    $marioGlobal = Character::factory()->for($mario)->global()->create();
    Character::factory()->for($mario)->create([
        'slug' => CharacterSlug::Doctor,
        'name' => 'Dottore Mario',
    ]);
    $luciaDoctor = Character::factory()->for($lucia)->create([
        'slug' => CharacterSlug::Doctor,
        'name' => 'Dottore Lucia',
    ]);
    Memory::factory()->for($luciaDoctor)->create([
        'memory_key' => 'segreto_lucia',
        'content' => 'Informazione privata di Lucia',
    ]);
    $conversation = Conversation::factory()->for($marioGlobal)->create();

    $context = app(ChatContextBuilder::class)->build($conversation);

    expect($context['system_prompt'])
        ->toContain('Dottore Mario')
        ->not->toContain('Dottore Lucia')
        ->not->toContain('Informazione privata di Lucia');
});
