<?php

namespace App\Services;

use App\Contracts\CalendarGateway;
use App\Exceptions\CalendarGatewayException;
use App\Models\ServiceConnection;
use Carbon\CarbonInterface;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Exception as GoogleServiceException;

class GoogleCalendarGateway implements CalendarGateway
{
    public function __construct(private GoogleTokenManager $tokens) {}

    public function eventsBetween(
        ServiceConnection $connection,
        CarbonInterface $start,
        CarbonInterface $end,
    ): array {
        $service = $this->service($connection);

        try {
            $events = $service->events->listEvents('primary', [
                'singleEvents' => true,
                'orderBy' => 'startTime',
                'timeMin' => $start->toRfc3339String(),
                'timeMax' => $end->toRfc3339String(),
                'maxResults' => 100,
            ]);
        } catch (GoogleServiceException $exception) {
            throw CalendarGatewayException::fromGoogle($exception);
        }

        $connection->update([
            'last_used_at' => now(),
            'metadata' => array_merge($connection->metadata ?? [], [
                'calendar_summary' => $events->getSummary(),
                'timezone' => $events->getTimeZone(),
            ]),
        ]);

        return collect($events->getItems())
            ->map(fn (Event $event): array => $this->normalizeEvent($event))
            ->all();
    }

    public function createEvent(
        ServiceConnection $connection,
        array $payload,
        string $idempotencyKey,
    ): array {
        $service = $this->service($connection);
        $event = new Event([
            'id' => $idempotencyKey,
            'summary' => $payload['summary'],
            'description' => $payload['description'] ?? null,
            'location' => $payload['location'] ?? null,
            'start' => [
                'dateTime' => $payload['start'],
                'timeZone' => $payload['timezone'],
            ],
            'end' => [
                'dateTime' => $payload['end'],
                'timeZone' => $payload['timezone'],
            ],
        ]);

        try {
            $created = $service->events->insert('primary', $event);
        } catch (GoogleServiceException $exception) {
            if ($exception->getCode() !== 409) {
                throw CalendarGatewayException::fromGoogle($exception);
            }

            try {
                $created = $service->events->get('primary', $idempotencyKey);
            } catch (GoogleServiceException $lookupException) {
                throw CalendarGatewayException::fromGoogle($lookupException);
            }
        }

        $connection->update(['last_used_at' => now()]);

        return $this->normalizeCreatedEvent($created);
    }

    private function service(ServiceConnection $connection): Calendar
    {
        return new Calendar($this->tokens->client($connection));
    }

    /**
     * @return array{id: string, summary: string, start: string, end: string, all_day: bool, location: string|null, html_link: string|null}
     */
    private function normalizeEvent(Event $event): array
    {
        $start = $event->getStart();
        $end = $event->getEnd();

        return [
            'id' => (string) $event->getId(),
            'summary' => (string) ($event->getSummary() ?: 'Senza titolo'),
            'start' => (string) ($start?->getDateTime() ?: $start?->getDate()),
            'end' => (string) ($end?->getDateTime() ?: $end?->getDate()),
            'all_day' => $start?->getDateTime() === null,
            'location' => $event->getLocation(),
            'html_link' => $event->getHtmlLink(),
        ];
    }

    /**
     * @return array{id: string, summary: string, start: string, end: string, html_link: string|null}
     */
    private function normalizeCreatedEvent(Event $event): array
    {
        $normalized = $this->normalizeEvent($event);

        return [
            'id' => $normalized['id'],
            'summary' => $normalized['summary'],
            'start' => $normalized['start'],
            'end' => $normalized['end'],
            'html_link' => $normalized['html_link'],
        ];
    }
}
