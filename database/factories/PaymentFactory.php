<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
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
            'amount' => fake()->randomFloat(2, 500, 50000),
            'paid_at' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
