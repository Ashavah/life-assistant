<?php

namespace Database\Factories;

use App\ExternalActionStatus;
use App\Models\Character;
use App\Models\Conversation;
use App\Models\ExternalActionProposal;
use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalActionProposal>
 */
class ExternalActionProposalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_connection_id' => ServiceConnection::factory(),
            'character_id' => Character::factory(),
            'conversation_id' => Conversation::factory(),
            'source_message_id' => null,
            'type' => 'calendar.create_event',
            'status' => ExternalActionStatus::Pending,
            'idempotency_key' => 'la'.fake()->unique()->regexify('[a-f0-9]{40}'),
            'payload' => [
                'summary' => 'Riunione',
                'start' => now()->addDay()->setTime(10, 0)->toIso8601String(),
                'end' => now()->addDay()->setTime(11, 0)->toIso8601String(),
                'timezone' => 'Europe/Rome',
            ],
            'expires_at' => now()->addDay(),
        ];
    }
}
