<?php

namespace App\Contracts;

use App\Models\ServiceConnection;
use App\ServiceProvider;

interface OAuthDriver
{
    public function supports(ServiceProvider $provider): bool;

    public function isConfigured(ServiceProvider $provider): bool;

    public function authorizationUrl(
        ServiceProvider $provider,
        string $state,
        ?string $codeChallenge = null,
    ): string;

    /**
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null, scopes: array<int, string>, metadata: array<string, mixed>}
     */
    public function exchange(
        ServiceProvider $provider,
        string $code,
        ?string $codeVerifier = null,
    ): array;

    public function revoke(ServiceConnection $connection): void;
}
