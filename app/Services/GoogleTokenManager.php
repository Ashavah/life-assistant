<?php

namespace App\Services;

use App\Exceptions\GoogleGatewayException;
use App\Models\ServiceConnection;
use Google\Client;

class GoogleTokenManager
{
    public function __construct(private GoogleOAuthService $oauth) {}

    public function client(ServiceConnection $connection): Client
    {
        if (! $connection->hasRequiredScopes()) {
            throw new GoogleGatewayException(
                'la connessione non include tutti i permessi richiesti; ricollega il servizio',
            );
        }

        $client = $this->oauth->client($connection->provider);
        $client->setAccessToken([
            'access_token' => $connection->access_token,
            'refresh_token' => $connection->refresh_token,
            'expires_in' => max(0, now()->diffInSeconds($connection->token_expires_at, false)),
            'created' => now()->timestamp,
        ]);

        if (! $connection->token_expires_at?->subMinute()->isPast()) {
            return $client;
        }

        if (! $connection->refresh_token) {
            throw new GoogleGatewayException('la connessione Google è scaduta e va collegata di nuovo');
        }

        $token = $client->fetchAccessTokenWithRefreshToken($connection->refresh_token);

        if (isset($token['error']) || ! isset($token['access_token'])) {
            throw new GoogleGatewayException(sprintf(
                'non è stato possibile rinnovare la connessione Google (%s)',
                $token['error'] ?? 'errore sconosciuto',
            ));
        }

        $connection->update([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
        ]);
        $client->setAccessToken($token);

        return $client;
    }
}
