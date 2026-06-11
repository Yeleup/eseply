<?php

namespace Database\Factories;

use App\Models\BillingPeriod;
use App\Models\BillingPeriodClosureError;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingPeriodClosureError>
 */
class BillingPeriodClosureErrorFactory extends Factory
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
            'billing_period_id' => fn (array $attributes): int => BillingPeriod::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'client_id' => fn (array $attributes): int => Client::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'account_number' => fake()->unique()->numerify('######'),
            'client_name' => fake()->name(),
            'billing_type' => 'fixed',
            'code' => 'missing_fixed_amount',
            'message' => 'Не указана фиксированная сумма.',
            'context' => null,
        ];
    }
}
