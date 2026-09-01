<?php

namespace Database\Factories;

use App\Models\Character;
use App\Models\MemoryChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemoryChange>
 */
class MemoryChangeFactory extends Factory
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
            'action' => 'create',
            'reason' => fake()->sentence(),
            'before' => null,
            'after' => ['content' => fake()->sentence()],
        ];
    }
}
