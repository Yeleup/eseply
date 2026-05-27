<?php

namespace Database\Factories;

use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeterReading>
 */
class MeterReadingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $previousReading = fake()->randomFloat(4, 0, 1000);

        return [
            'organization_id' => Organization::factory(),
            'meter_id' => fn (array $attributes): int => Meter::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'client_id' => fn (array $attributes): int => Meter::query()
                ->findOrFail($attributes['meter_id'])
                ->client_id,
            'utility_service_id' => fn (array $attributes): ?int => Meter::query()
                ->findOrFail($attributes['meter_id'])
                ->utility_service_id,
            'period' => fake()->dateTimeBetween('-1 year', 'now')->format('Ym'),
            'previous_reading' => $previousReading,
            'current_reading' => $previousReading + fake()->randomFloat(4, 1, 250),
            'read_at' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
