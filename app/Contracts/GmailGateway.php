<?php

namespace App\Contracts;

use App\Models\ServiceConnection;

interface GmailGateway
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(ServiceConnection $connection, string $query): array;

    /**
     * @return array<string, mixed>
     */
    public function read(ServiceConnection $connection, string $messageId): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createDraft(ServiceConnection $connection, array $payload, string $idempotencyKey): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function send(ServiceConnection $connection, array $payload, string $idempotencyKey): array;
}
