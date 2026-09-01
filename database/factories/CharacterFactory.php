<?php

namespace Database\Factories;

use App\CharacterSlug;
use App\Models\Character;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Character>
 */
class CharacterFactory extends Factory
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
            'slug' => CharacterSlug::Doctor,
            'name' => 'Dottore',
            'description' => 'Salute e benessere',
            'system_prompt' => 'Sei un assistente dedicato alla salute.',
            'tone' => 'Calmo, prudente e chiaro.',
            'is_global' => false,
            'sort_order' => 1,
        ];
    }

    public function global(): static
    {
        return $this->state(fn (array $attributes): array => [
            'slug' => CharacterSlug::Global,
            'name' => 'Globale',
            'is_global' => true,
            'sort_order' => 0,
        ]);
    }
}
