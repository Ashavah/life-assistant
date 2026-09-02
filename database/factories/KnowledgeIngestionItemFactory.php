<?php

namespace Database\Factories;

use App\Models\Character;
use App\Models\KnowledgeIngestion;
use App\Models\KnowledgeIngestionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeIngestionItem>
 */
class KnowledgeIngestionItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'knowledge_ingestion_id' => KnowledgeIngestion::factory(),
            'character_id' => Character::factory(),
            'action' => 'upsert',
            'memory_key' => fake()->unique()->slug(3),
            'category' => 'general',
            'content' => fake()->sentence(),
            'importance' => 3,
            'confidence' => 0.85,
            'reason' => fake()->sentence(),
            'selected' => true,
            'sort_order' => 0,
        ];
    }
}
