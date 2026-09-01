<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('la registrazione pubblica è disponibile', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Registrati');
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Crea il tuo spazio');
});

test('registra più utenti e prepara personaggi separati', function () {
    $this->post(route('register.store'), [
        'name' => 'Alberto',
        'email' => 'alberto@example.com',
        'password' => 'password1234',
        'password_confirmation' => 'password1234',
    ])->assertRedirect(route('home'));

    $this->assertAuthenticated();
    expect(auth()->user()->characters)->toHaveCount(4);
    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->post(route('register.store'), [
        'name' => 'Altro',
        'email' => 'altro@example.com',
        'password' => 'password1234',
        'password_confirmation' => 'password1234',
    ])->assertRedirect(route('home'));

    expect(User::query()->count())->toBe(2)
        ->and(auth()->user()->characters)->toHaveCount(4)
        ->and(User::query()->first()->characters->pluck('id')->intersect(
            auth()->user()->characters->pluck('id'),
        ))->toBeEmpty();
});

test('effettua login e protegge le rotte applicative', function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'password' => 'password1234',
    ]);

    $this->get(route('home'))->assertRedirect(route('login'));
    $this->post(route('login.store'), [
        'email' => 'owner@example.com',
        'password' => 'sbagliata',
    ])->assertSessionHasErrors('email');
    $this->post(route('login.store'), [
        'email' => 'owner@example.com',
        'password' => 'password1234',
    ])->assertRedirect(route('home'));
    $this->assertAuthenticated();
});
