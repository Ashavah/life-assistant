<?php

namespace App\Integrations;

use App\IntegrationService;
use App\Models\ServiceConnection;
use App\ServiceProvider;

class ServiceConnectionResolver
{
    public function forService(int $userId, IntegrationService $service): ?ServiceConnection
    {
        $connection = ServiceConnection::query()
            ->where('user_id', $userId)
            ->where('provider', $service->provider())
            ->first();

        if ($connection || $service->provider() !== ServiceProvider::Google) {
            return $connection;
        }

        $legacy = match ($service) {
            IntegrationService::GoogleCalendar => ServiceProvider::GoogleCalendar,
            IntegrationService::GoogleDrive => ServiceProvider::GoogleDrive,
            IntegrationService::GoogleGmail => ServiceProvider::GoogleGmail,
            default => null,
        };

        return $legacy
            ? ServiceConnection::query()
                ->where('user_id', $userId)
                ->where('provider', $legacy)
                ->first()
            : null;
    }
}
