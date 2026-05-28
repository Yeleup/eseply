<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Tariff;
use App\Models\TariffCategory;
use App\Models\UtilityService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tariff>
 */
class TariffFactory extends Factory
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
            'utility_service_id' => fn (array $attributes): int => UtilityService::query()
                ->where('organization_id', $attributes['organization_id'])
                ->value('id') ?? UtilityService::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'tariff_category_id' => fn (array $attributes): int => TariffCategory::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'price' => fake()->randomFloat(2, 50, 5000),
            'starts_on' => fake()->dateTimeBetween('-1 year', '+1 month')->format('Y-m-d'),
            'status' => 'active',
        ];
    }
}
