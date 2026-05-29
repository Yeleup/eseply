<?php

namespace Database\Factories;

use App\Models\Accrual;
use App\Models\Client;
use App\Models\Organization;
use App\Models\UtilityService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Accrual>
 */
class AccrualFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 1000, 25000);
        $adjustmentAmount = fake()->randomFloat(2, -5000, 5000);
        $openingBalance = fake()->randomFloat(2, -5000, 5000);

        return [
            'organization_id' => Organization::factory(),
            'client_id' => fn (array $attributes): int => Client::factory()
                ->create([
                    'organization_id' => $attributes['organization_id'],
                    'billing_type' => 'fixed',
                    'fixed_amount' => $amount,
                ])
                ->id,
            'utility_service_id' => fn (array $attributes): int => UtilityService::query()
                ->where('organization_id', $attributes['organization_id'])
                ->value('id') ?? UtilityService::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'period' => fake()->dateTimeBetween('-1 year', 'now')->format('Ym'),
            'account_number' => fake()->unique()->numerify('######'),
            'client_name' => fake()->name(),
            'utility_service_name' => fake()->randomElement(['Водоснабжение', 'Электроэнергия', 'Вывоз мусора']),
            'billing_type' => 'fixed',
            'amount' => $amount,
            'paid_amount' => 0,
            'adjustment_amount' => $adjustmentAmount,
            'opening_balance' => $openingBalance,
            'closing_balance' => $openingBalance + $amount + $adjustmentAmount,
            'closed_at' => now(),
        ];
    }
}
