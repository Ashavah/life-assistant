<?php

namespace Database\Factories;

use App\KnowledgeIngestionStatus;
use App\KnowledgeSourceType;
use App\Models\Character;
use App\Models\KnowledgeIngestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeIngestion>
 */
class KnowledgeIngestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn (): int|Factory => auth()->id() ?? User::factory(),
            'character_id' => Character::factory(),
            'status' => KnowledgeIngestionStatus::Pending,
            'source_type' => KnowledgeSourceType::Text,
            'mime_type' => 'text/plain',
            'size_bytes' => 20,
            'content_hash' => hash('sha256', fake()->unique()->sentence()),
            'temporary_text' => fake()->paragraph(),
            'item_count' => 0,
            'expires_at' => now()->addHours(24),
        ];
    }
}
