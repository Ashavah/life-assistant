<?php

namespace App\Http\Controllers;

use App\Integrations\OAuthDriverRegistry;
use App\Models\ServiceConnection;
use App\ServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ServiceConnectionController extends Controller
{
    public function redirect(
        Request $request,
        ServiceProvider $provider,
        OAuthDriverRegistry $drivers,
    ): RedirectResponse {
        abort_if($provider->isLegacy(), 404);

        $driver = $drivers->for($provider);

        if (! $driver->isConfigured($provider)) {
            return $this->settingsRedirect(
                'L’integrazione con '.$provider->label().' non è ancora disponibile.',
                true,
            );
        }

        $state = Str::random(64);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $request->session()->put('integration_oauth', [
            'state' => $state,
            'provider' => $provider->value,
            'verifier' => $verifier,
        ]);

        return redirect()->away($driver->authorizationUrl($provider, $state, $challenge));
    }

    public function callback(
        Request $request,
        ServiceProvider $provider,
        OAuthDriverRegistry $drivers,
    ): RedirectResponse {
        abort_if($provider->isLegacy(), 404);
        $pending = $request->session()->pull('integration_oauth');
        abort_unless(
            is_array($pending)
            && ($pending['provider'] ?? null) === $provider->value
            && is_string($pending['state'] ?? null)
            && hash_equals($pending['state'], $request->string('state')->toString()),
            403,
            'Stato OAuth non valido.',
        );

        if ($request->filled('error')) {
            return $this->settingsRedirect('Collegamento a '.$provider->label().' annullato.', true);
        }

        $code = $request->string('code')->toString();

        if ($code === '') {
            return $this->settingsRedirect($provider->label().' non ha restituito il codice di autorizzazione.', true);
        }

        try {
            $token = $drivers->for($provider)->exchange(
                $provider,
                $code,
                is_string($pending['verifier'] ?? null) ? $pending['verifier'] : null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->settingsRedirect('Collegamento a '.$provider->label().' non riuscito: '.$exception->getMessage(), true);
        }

        $connection = ServiceConnection::query()->firstOrNew([
            'user_id' => $request->user()->id,
            'provider' => $provider,
        ]);
        $connection->fill([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => $token['expires_in'] === null
                ? null
                : now()->addSeconds($token['expires_in']),
            'scopes' => $token['scopes'],
            'metadata' => array_merge($connection->metadata ?? [], $token['metadata']),
            'connected_at' => now(),
        ])->save();

        return $this->settingsRedirect($provider->label().' collegato al tuo account.');
    }

    public function destroy(
        Request $request,
        ServiceProvider $provider,
        OAuthDriverRegistry $drivers,
    ): RedirectResponse {
        abort_if($provider->isLegacy(), 404);
        $connection = $request->user()
            ->serviceConnections()
            ->where('provider', $provider)
            ->firstOrFail();

        try {
            $drivers->for($provider)->revoke($connection);
        } catch (Throwable $exception) {
            report($exception);
        }

        $connection->delete();

        return $this->settingsRedirect($provider->label().' scollegato dal tuo account.');
    }

    private function settingsRedirect(string $message, bool $error = false): RedirectResponse
    {
        return redirect()->route('home', ['account_settings' => 1])
            ->with($error ? 'error' : 'status', $message);
    }
}
