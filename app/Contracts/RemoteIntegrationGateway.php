<?php

namespace App\Contracts;

use App\IntegrationService;
use App\Models\ServiceConnection;

interface RemoteIntegrationGateway
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    public function read(
        ServiceConnection $connection,
        IntegrationService $service,
        string $action,
        array $parameters,
    ): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function write(
        ServiceConnection $connection,
        IntegrationService $service,
        string $action,
        array $payload,
        string $idempotencyKey,
    ): array;
}
