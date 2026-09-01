<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'mario@example.com',
        'password' => 'password1234',
    ]);
    $this->actingAs($this->user);
});

test('aggiorna i dati personali e il fuso orario', function () {
    $this->patchJson(route('account.profile.update'), [
        'name' => 'Mario Rossi',
        'email' => 'mario.rossi@example.com',
        'timezone' => 'America/New_York',
    ])->assertOk()
        ->assertJsonPath('user.timezone', 'America/New_York');

    expect($this->user->refresh())
        ->name->toBe('Mario Rossi')
        ->email->toBe('mario.rossi@example.com')
        ->timezone->toBe('America/New_York');
});

test('non permette di usare l email di un altro account', function () {
    User::factory()->create(['email' => 'lucia@example.com']);

    $this->patchJson(route('account.profile.update'), [
        'name' => 'Mario',
        'email' => 'lucia@example.com',
        'timezone' => 'Europe/Rome',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('cambia password soltanto conoscendo quella attuale', function () {
    $this->patchJson(route('account.password.update'), [
        'current_password' => 'sbagliata',
        'password' => 'nuovapassword1234',
        'password_confirmation' => 'nuovapassword1234',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

    $this->patchJson(route('account.password.update'), [
        'current_password' => 'password1234',
        'password' => 'nuovapassword1234',
        'password_confirmation' => 'nuovapassword1234',
    ])->assertOk();

    expect(Hash::check('nuovapassword1234', $this->user->refresh()->password))->toBeTrue();
});

test('il pannello account contiene profilo e integrazioni separati dal personaggio', function () {
    $this->get(route('home', ['account_settings' => 1]))
        ->assertOk()
        ->assertSee('account-settings-backdrop')
        ->assertSee('Salva profilo')
        ->assertSee('Cambia password')
        ->assertSee('Integrazioni');
});
