<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\TariffCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TariffCategory>
 */
class TariffCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->randomElement(['Население', 'Юрлица', 'Коммерческие объекты']),
            'status' => 'active',
            'note' => fake()->optional()->sentence(),
        ];
    }
}
