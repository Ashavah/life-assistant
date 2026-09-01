<?php

namespace App\Contracts;

use App\Models\ServiceConnection;

interface DriveGateway
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(ServiceConnection $connection, string $query): array;

    /**
     * @return array<string, mixed>
     */
    public function read(ServiceConnection $connection, string $fileId): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createFolder(ServiceConnection $connection, array $payload, string $idempotencyKey): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createDocument(ServiceConnection $connection, array $payload, string $idempotencyKey): array;
}
