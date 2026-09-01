<?php

namespace App\Contracts;

use App\Models\ServiceConnection;
use Carbon\CarbonInterface;

interface CalendarGateway
{
    /**
     * @return array<int, array{id: string, summary: string, start: string, end: string, all_day: bool, location: string|null, html_link: string|null}>
     */
    public function eventsBetween(
        ServiceConnection $connection,
        CarbonInterface $start,
        CarbonInterface $end,
    ): array;

    /**
     * @param  array{summary: string, start: string, end: string, timezone: string, location?: string|null, description?: string|null}  $payload
     * @return array{id: string, summary: string, start: string, end: string, html_link: string|null}
     */
    public function createEvent(
        ServiceConnection $connection,
        array $payload,
        string $idempotencyKey,
    ): array;
}
