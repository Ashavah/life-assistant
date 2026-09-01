<?php

namespace Database\Factories;

use App\Models\Character;
use App\Models\Memory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Memory>
 */
class MemoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'character_id' => Character::factory(),
            'category' => 'general',
            'memory_key' => Str::slug(fake()->unique()->words(3, true), '_'),
            'content' => fake()->sentence(),
            'importance' => 3,
            'confidence' => 0.9,
            'last_reinforced_at' => now(),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'archived_at' => now(),
        ]);
    }
}
