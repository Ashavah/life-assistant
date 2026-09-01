<?php

namespace App\Services;

use App\ServiceProvider;
use Google\Client;
use RuntimeException;

class GoogleOAuthService
{
    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect_uri'));
    }

    public function authorizationUrl(string $state, ServiceProvider $provider = ServiceProvider::GoogleCalendar): string
    {
        $client = $this->client($provider);
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * @return array<string, mixed>
     */
    public function exchange(string $code, ServiceProvider $provider = ServiceProvider::GoogleCalendar): array
    {
        $token = $this->client($provider)->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error']) || ! isset($token['access_token'])) {
            throw new RuntimeException(
                (string) ($token['error_description'] ?? $token['error'] ?? 'Google non ha restituito un token valido.'),
            );
        }

        return $token;
    }

    public function revoke(string $accessToken): void
    {
        $this->client(ServiceProvider::GoogleCalendar)->revokeToken($accessToken);
    }

    public function client(ServiceProvider $provider = ServiceProvider::GoogleCalendar): Client
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Google OAuth non è configurato. Imposta le credenziali nel file .env.');
        }

        $client = new Client;
        $client->setClientId((string) config('services.google.client_id'));
        $client->setClientSecret((string) config('services.google.client_secret'));
        $client->setRedirectUri((string) config('services.google.redirect_uri'));
        $client->setScopes($provider->scopes());
        $client->setAccessType('offline');
        $client->setPrompt('consent select_account');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }
}
