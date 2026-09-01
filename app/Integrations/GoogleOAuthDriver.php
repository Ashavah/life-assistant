<?php

namespace App\Integrations;

use App\Contracts\OAuthDriver;
use App\Models\ServiceConnection;
use App\ServiceProvider;
use App\Services\GoogleOAuthService;
use Illuminate\Support\Facades\Http;

class GoogleOAuthDriver implements OAuthDriver
{
    public function __construct(private GoogleOAuthService $google) {}

    public function supports(ServiceProvider $provider): bool
    {
        return in_array($provider, [
            ServiceProvider::Google,
            ServiceProvider::GoogleCalendar,
            ServiceProvider::GoogleDrive,
            ServiceProvider::GoogleGmail,
        ], true);
    }

    public function isConfigured(ServiceProvider $provider): bool
    {
        return $this->google->isConfigured();
    }

    public function authorizationUrl(
        ServiceProvider $provider,
        string $state,
        ?string $codeChallenge = null,
    ): string {
        return $this->google->authorizationUrl($state, $provider);
    }

    public function exchange(
        ServiceProvider $provider,
        string $code,
        ?string $codeVerifier = null,
    ): array {
        $token = $this->google->exchange($code, $provider);

        $metadata = [];

        if ($provider === ServiceProvider::Google) {
            $identity = Http::withToken((string) $token['access_token'])
                ->connectTimeout((int) config('integrations.connect_timeout', 5))
                ->timeout((int) config('integrations.timeout', 15))
                ->get((string) config('integrations.providers.google.identity_url'))
                ->throw()
                ->json();
            $metadata = array_filter([
                'account_id' => $identity['sub'] ?? null,
                'account_name' => $identity['name'] ?? null,
                'account_email' => $identity['email'] ?? null,
            ]);
        }

        return [
            'access_token' => (string) $token['access_token'],
            'refresh_token' => isset($token['refresh_token']) ? (string) $token['refresh_token'] : null,
            'expires_in' => isset($token['expires_in']) ? (int) $token['expires_in'] : null,
            'scopes' => isset($token['scope'])
                ? array_values(array_filter(preg_split('/\s+/', (string) $token['scope']) ?: []))
                : $provider->scopes(),
            'metadata' => $metadata,
        ];
    }

    public function revoke(ServiceConnection $connection): void
    {
        $this->google->revoke($connection->access_token);
    }
}
