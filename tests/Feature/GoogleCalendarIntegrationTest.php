<?php

use App\Models\ServiceConnection;
use App\Models\User;
use App\ServiceProvider;
use App\Services\GoogleOAuthService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('il redirect oauth salva uno stato legato all account', function () {
    $this->mock(GoogleOAuthService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('authorizationUrl')
            ->once()
            ->withArgs(fn (string $state, ServiceProvider $provider): bool => strlen($state) === 64
                && $provider === ServiceProvider::GoogleCalendar)
            ->andReturn('https://accounts.google.com/o/oauth2/auth');
    });

    $this->get(route('google-services.redirect', ServiceProvider::GoogleCalendar))
        ->assertRedirect('https://accounts.google.com/o/oauth2/auth')
        ->assertSessionHas('google_oauth.provider', ServiceProvider::GoogleCalendar->value);
});

test('il callback salva token cifrati sull account', function () {
    $this->mock(GoogleOAuthService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('exchange')
            ->once()
            ->with('auth-code', ServiceProvider::GoogleCalendar)
            ->andReturn([
                'access_token' => 'plain-access-token',
                'refresh_token' => 'plain-refresh-token',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/calendar.events',
            ]);
    });

    $this->withSession([
        'google_oauth' => [
            'state' => 'expected-state',
            'provider' => ServiceProvider::GoogleCalendar->value,
        ],
    ])->get(route('google-services.callback', [
        'state' => 'expected-state',
        'code' => 'auth-code',
    ]))->assertRedirect();

    $connection = ServiceConnection::query()->sole();
    expect($connection->access_token)->toBe('plain-access-token')
        ->and($connection->refresh_token)->toBe('plain-refresh-token')
        ->and($connection->user_id)->toBe($this->user->id);

    $raw = DB::table('service_connections')->where('id', $connection->id)->first();
    expect($raw->access_token)->not->toBe('plain-access-token')
        ->and($raw->refresh_token)->not->toBe('plain-refresh-token');
});

test('rifiuta callback con stato oauth errato', function () {
    $this->withSession([
        'google_oauth' => [
            'state' => 'expected-state',
            'provider' => ServiceProvider::GoogleCalendar->value,
        ],
    ])->get(route('google-services.callback', [
        'state' => 'wrong-state',
        'code' => 'auth-code',
    ]))->assertForbidden();
});

test('salva connessioni oauth separate per gli altri servizi google', function (string $providerValue) {
    $provider = ServiceProvider::from($providerValue);
    $this->mock(GoogleOAuthService::class, function (MockInterface $mock) use ($provider): void {
        $mock->shouldReceive('exchange')
            ->once()
            ->with('auth-code', $provider)
            ->andReturn([
                'access_token' => 'product-token',
                'refresh_token' => 'product-refresh',
                'expires_in' => 3600,
                'scope' => implode(' ', $provider->scopes()),
            ]);
    });

    $this->withSession([
        'google_oauth' => [
            'state' => 'expected-state',
            'provider' => $provider->value,
        ],
    ])->get(route('google-services.callback', [
        'state' => 'expected-state',
        'code' => 'auth-code',
    ]))->assertRedirect();

    expect(ServiceConnection::query()->sole()->provider)->toBe($provider);
})->with([
    ServiceProvider::GoogleDrive->value,
    ServiceProvider::GoogleGmail->value,
]);

test('la disconnessione elimina il servizio dall account', function () {
    $connection = ServiceConnection::factory()->for($this->user)->create([
        'provider' => ServiceProvider::GoogleCalendar,
    ]);
    $this->mock(GoogleOAuthService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('revoke')->once()->with('test-access-token');
    });

    $this->delete(route('google-services.destroy', ServiceProvider::GoogleCalendar))->assertRedirect();
    $this->assertDatabaseMissing('service_connections', ['id' => $connection->id]);
});
