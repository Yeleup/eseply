<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Organization;
use App\Models\PaymentTransaction;
use App\PaymentTransactionProvider;
use App\PaymentTransactionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentTransaction>
 */
class PaymentTransactionFactory extends Factory
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
            'provider' => PaymentTransactionProvider::XPayment,
            'merchant_order_id' => 'esepteu-'.Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'amount' => fake()->randomFloat(2, 500, 50000),
            'status' => PaymentTransactionStatus::Pending,
            'payer_phone' => fake()->optional()->numerify('+7701#######'),
            'note' => fake()->optional()->sentence(),
        ];
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => PaymentTransactionStatus::Completed,
            'external_payment_id' => fake()->uuid(),
            'completed_at' => now(),
        ]);
    }
}
