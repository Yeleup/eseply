<?php

namespace Database\Factories;

use App\BalanceAdjustmentType;
use App\Models\BalanceAdjustment;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BalanceAdjustment>
 */
class BalanceAdjustmentFactory extends Factory
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
            'client_id' => fn (array $attributes): int => Client::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'period' => fake()->dateTimeBetween('-1 year', 'now')->format('Ym'),
            'type' => fake()->randomElement(BalanceAdjustmentType::cases())->value,
            'amount' => fake()->randomFloat(2, -10000, 10000),
            'adjusted_at' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
