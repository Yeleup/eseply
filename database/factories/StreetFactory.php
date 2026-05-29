<?php

namespace Database\Factories;

use App\Models\Region;
use App\Models\Street;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Street>
 */
class StreetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'region_id' => Region::factory(),
            'organization_id' => fn (array $attributes): int => Region::query()
                ->findOrFail($attributes['region_id'])
                ->organization_id,
            'name' => fake()->unique()->streetName(),
        ];
    }
}
