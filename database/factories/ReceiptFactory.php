<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Receipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
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
        $period = fake()->dateTimeBetween('-1 year', 'now')->format('Ym');
        $accountNumber = fake()->unique()->numerify('######');

        return [
            'organization_id' => Organization::factory(),
            'client_id' => fn (array $attributes): int => Client::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'accrual_id' => null,
            'receipt_number' => "{$period}-{$accountNumber}",
            'period' => $period,
            'account_number' => $accountNumber,
            'client_name' => fake()->name(),
            'utility_service_name' => fake()->randomElement(['Водоснабжение', 'Электроэнергия', 'Вывоз мусора']),
            'billing_type' => 'fixed',
            'amount' => $amount,
            'paid_amount' => 0,
            'adjustment_amount' => $adjustmentAmount,
            'opening_balance' => $openingBalance,
            'closing_balance' => $openingBalance + $amount + $adjustmentAmount,
            'issued_at' => now(),
        ];
    }
}
