<?php

use App\CharacterSlug;
use App\Models\Character;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ChatContextBuilder;
use Database\Seeders\CharacterSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('aggiorna soltanto le impostazioni modificabili del personaggio', function () {
    $doctor = Character::factory()->create(['slug' => CharacterSlug::Doctor]);

    $this->patchJson(route('characters.update', $doctor), [
        'name' => 'Medico',
        'description' => 'Nuovo ambito salute',
        'system_prompt' => 'NUOVO PROMPT',
        'tone' => 'Molto conciso',
        'slug' => 'hacker',
        'is_global' => true,
    ])->assertOk()
        ->assertJsonPath('character.name', 'Medico');

    $doctor->refresh();
    expect($doctor->slug)->toBe(CharacterSlug::Doctor->value)
        ->and($doctor->is_global)->toBeFalse();

    $conversation = Conversation::factory()->for($doctor)->create();
    $context = app(ChatContextBuilder::class)->build($conversation);
    expect($context['system_prompt'])
        ->toContain('NUOVO PROMPT')
        ->toContain('Tono: Molto conciso')
        ->toContain('Nuovo ambito salute');
});

test('valida i limiti delle impostazioni', function () {
    $doctor = Character::factory()->create();

    $this->patchJson(route('characters.update', $doctor), [
        'name' => '',
        'description' => str_repeat('x', 256),
        'system_prompt' => '',
        'tone' => '',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'description', 'system_prompt', 'tone']);
});

test('il seeder non sovrascrive le personalizzazioni', function () {
    $doctor = Character::factory()->create([
        'slug' => CharacterSlug::Doctor,
        'name' => 'Il mio medico',
    ]);

    $this->seed(CharacterSeeder::class);

    expect($doctor->refresh()->name)->toBe('Il mio medico');
});

test('crea specialisti personalizzati con slug univoco per utente', function () {
    $first = $this->postJson(route('characters.store'), [
        'name' => 'Personal Trainer',
        'description' => 'Allenamento, postura e attività fisica',
        'system_prompt' => 'Crea programmi di allenamento prudenti.',
        'tone' => 'Motivante',
    ])->assertCreated()
        ->assertJsonPath('character.slug', 'personal-trainer')
        ->assertJsonPath('character.is_global', false);

    $this->postJson(route('characters.store'), [
        'name' => 'Personal Trainer',
        'description' => 'Secondo specialista',
    ])->assertCreated()
        ->assertJsonPath('character.slug', 'personal-trainer-2');

    expect(Character::query()->where('is_global', false)->count())->toBe(2)
        ->and($first->json('url'))->toContain('personal-trainer');
});

test('elimina uno specialista con chat e memorie ma non il globale', function () {
    $specialist = Character::factory()->create([
        'slug' => 'personal-trainer',
        'is_global' => false,
    ]);
    $conversation = Conversation::factory()->for($specialist)->create();
    $specialist->memories()->create([
        'memory_key' => 'obiettivo',
        'category' => 'fitness',
        'content' => 'Correre cinque chilometri',
    ]);
    $global = Character::factory()->global()->create();

    $this->delete(route('characters.destroy', $specialist))
        ->assertRedirect(route('home'));

    $this->assertDatabaseMissing('characters', ['id' => $specialist->id]);
    $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
    $this->delete(route('characters.destroy', $global))->assertForbidden();
    $this->assertDatabaseHas('characters', ['id' => $global->id]);
});
