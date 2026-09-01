<?php

namespace App\Http\Controllers;

use App\Models\ServiceConnection;
use App\ServiceProvider;
use App\Services\GoogleOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class GoogleServiceConnectionController extends Controller
{
    public function redirect(
        Request $request,
        ServiceProvider $provider,
        GoogleOAuthService $oauth,
    ): RedirectResponse {
        $state = Str::random(64);
        $request->session()->put('google_oauth', [
            'state' => $state,
            'provider' => $provider->value,
        ]);

        return redirect()->away($oauth->authorizationUrl($state, $provider));
    }

    public function callback(Request $request, GoogleOAuthService $oauth): RedirectResponse
    {
        $pending = $request->session()->pull('google_oauth');
        abort_unless(
            is_array($pending)
            && is_string($pending['state'] ?? null)
            && hash_equals($pending['state'], (string) $request->query('state')),
            403,
            'Stato OAuth non valido.',
        );

        $provider = ServiceProvider::from((string) $pending['provider']);

        if ($request->filled('error')) {
            return $this->settingsRedirect('Collegamento Google annullato.', true);
        }

        $code = $request->string('code')->toString();

        if ($code === '') {
            return $this->settingsRedirect('Google non ha restituito il codice di autorizzazione.', true);
        }

        $token = $oauth->exchange($code, $provider);
        $connection = ServiceConnection::query()->firstOrNew([
            'user_id' => $request->user()->id,
            'provider' => $provider,
        ]);
        $connection->fill([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'scopes' => isset($token['scope'])
                ? explode(' ', (string) $token['scope'])
                : $provider->scopes(),
            'connected_at' => now(),
        ])->save();

        return $this->settingsRedirect($provider->label().' collegato al tuo account.');
    }

    public function destroy(
        Request $request,
        ServiceProvider $provider,
        GoogleOAuthService $oauth,
    ): RedirectResponse {
        $connection = $this->connectionFor($request, $provider);

        try {
            $oauth->revoke($connection->access_token);
        } catch (Throwable $exception) {
            report($exception);
        }

        $connection->delete();

        return $this->settingsRedirect($provider->label().' scollegato dal tuo account.');
    }

    private function connectionFor(Request $request, ServiceProvider $provider): ServiceConnection
    {
        return $request->user()
            ->serviceConnections()
            ->where('provider', $provider)
            ->firstOrFail();
    }

    private function settingsRedirect(
        string $message,
        bool $error = false,
    ): RedirectResponse {
        return redirect()->route('home', [
            'account_settings' => 1,
        ])->with($error ? 'error' : 'status', $message);
    }
}
