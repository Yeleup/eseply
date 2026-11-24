<?php

namespace Database\Factories;

use App\Kaspi\KaspiCommand;
use App\Kaspi\KaspiOperationState;
use App\Models\Client;
use App\Models\KaspiOperation;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KaspiOperation>
 */
class KaspiOperationFactory extends Factory
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
            'command' => KaspiCommand::Check,
            'txn_id' => (string) fake()->unique()->numberBetween(10_000_000_000, 99_999_999_999),
            'account' => (string) fake()->numberBetween(100001, 199999),
            'received_at' => now(),
            'state' => KaspiOperationState::NotFound,
            'last_result' => 1,
        ];
    }

    public function forClient(Client $client): self
    {
        return $this->state(fn (): array => [
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'account' => $client->account_number,
        ]);
    }

    public function pending(float $sum = 1000): self
    {
        return $this->state(fn (): array => [
            'command' => KaspiCommand::Pay,
            'sum' => $sum,
            'txn_date' => now()->startOfSecond(),
            'state' => KaspiOperationState::Pending,
            'state_reason' => 'Идёт закрытие расчётного месяца',
            'last_result' => 4,
        ]);
    }
}
