<?php

namespace Database\Factories;

use App\Models\ServiceConnection;
use App\Models\User;
use App\ServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceConnection>
 */
class ServiceConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => ServiceProvider::GoogleCalendar,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/calendar.events'],
            'metadata' => ['timezone' => 'Europe/Rome'],
            'connected_at' => now(),
        ];
    }
}
