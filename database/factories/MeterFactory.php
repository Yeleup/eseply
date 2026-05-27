<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Meter;
use App\Models\Organization;
use App\Models\UtilityService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meter>
 */
class MeterFactory extends Factory
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
            'utility_service_id' => fn (array $attributes): int => UtilityService::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'client_id' => fn (array $attributes): int => Client::factory()
                ->create([
                    'organization_id' => $attributes['organization_id'],
                    'utility_service_id' => $attributes['utility_service_id'],
                    'billing_type' => 'meter',
                ])
                ->id,
            'number' => fake()->unique()->bothify('MTR-######'),
            'installed_on' => fake()->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
            'initial_reading' => fake()->randomFloat(4, 0, 1000),
            'removed_on' => null,
            'status' => 'active',
            'note' => fake()->optional()->sentence(),
        ];
    }
}
