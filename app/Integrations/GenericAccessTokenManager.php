<?php

namespace App\Integrations;

use App\Contracts\AccessTokenManager;
use App\Exceptions\IntegrationGatewayException;
use App\Models\ServiceConnection;
use App\ServiceProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class GenericAccessTokenManager implements AccessTokenManager
{
    public function accessToken(ServiceConnection $connection): string
    {
        if (! $connection->hasRequiredScopes()) {
            throw new IntegrationGatewayException(
                'La connessione non include tutti i permessi richiesti; ricollega il servizio.',
                ['provider' => $connection->provider->value],
            );
        }

        if ($connection->token_expires_at === null || ! $connection->token_expires_at->subMinute()->isPast()) {
            return $connection->access_token;
        }

        return Cache::lock('integration-token-refresh-'.$connection->id, 20)
            ->block(5, function () use ($connection): string {
                $connection->refresh();

                if ($connection->token_expires_at === null || ! $connection->token_expires_at->subMinute()->isPast()) {
                    return $connection->access_token;
                }

                return $this->refresh($connection);
            });
    }

    private function refresh(ServiceConnection $connection): string
    {
        if (! $connection->refresh_token) {
            throw new IntegrationGatewayException(
                'La connessione è scaduta e va collegata di nuovo.',
                ['provider' => $connection->provider->value],
            );
        }

        $provider = $connection->provider;
        $key = $provider->configurationKey();
        $payload = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
            'client_id' => config("services.{$key}.client_id"),
            'client_secret' => config("services.{$key}.client_secret"),
        ];

        try {
            $response = $this->request($provider)
                ->post((string) config("integrations.providers.{$key}.token_url"), $payload)
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            throw new IntegrationGatewayException(
                'Non è stato possibile rinnovare la connessione; collegala di nuovo.',
                ['provider' => $provider->value],
                previous: $exception,
            );
        }

        if (! is_array($response)) {
            throw new IntegrationGatewayException('Risposta di rinnovo OAuth non valida.');
        }

        $accessToken = $provider === ServiceProvider::Slack
            ? Arr::get($response, 'authed_user.access_token')
            : Arr::get($response, 'access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new IntegrationGatewayException('Il rinnovo non ha restituito un access token.');
        }

        $refreshToken = $provider === ServiceProvider::Slack
            ? Arr::get($response, 'authed_user.refresh_token')
            : Arr::get($response, 'refresh_token');
        $expiresIn = $provider === ServiceProvider::Slack
            ? Arr::get($response, 'authed_user.expires_in')
            : Arr::get($response, 'expires_in');

        $connection->update([
            'access_token' => $accessToken,
            'refresh_token' => is_string($refreshToken) && $refreshToken !== ''
                ? $refreshToken
                : $connection->refresh_token,
            'token_expires_at' => is_numeric($expiresIn)
                ? now()->addSeconds((int) $expiresIn)
                : null,
        ]);

        return $accessToken;
    }

    private function request(ServiceProvider $provider): PendingRequest
    {
        $key = $provider->configurationKey();
        $request = Http::connectTimeout((int) config('integrations.connect_timeout', 5))
            ->timeout((int) config('integrations.timeout', 15))
            ->acceptJson();

        if ($provider === ServiceProvider::Notion) {
            return $request->asJson()
                ->withBasicAuth(
                    (string) config("services.{$key}.client_id"),
                    (string) config("services.{$key}.client_secret"),
                )
                ->withHeaders(['Notion-Version' => (string) config('integrations.providers.notion.notion_version')]);
        }

        $request = $request->asForm();

        if (in_array($provider, [ServiceProvider::Spotify, ServiceProvider::Dropbox], true)) {
            $request = $request->withBasicAuth(
                (string) config("services.{$key}.client_id"),
                (string) config("services.{$key}.client_secret"),
            );
        }

        return $request;
    }
}
