<?php

namespace Database\Factories;

use App\ClientType;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
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
            'account_number' => fake()->unique()->numerify('######'),
            'name' => fake()->name(),
            'client_type' => ClientType::Individual->value,
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'status' => 'active',
            'starting_balance' => fake()->randomFloat(2, 0, 50000),
            'billing_type' => 'per_person',
            'residents_count' => fake()->numberBetween(0, 6),
            'area' => fake()->randomFloat(2, 0, 250),
            'fixed_amount' => 0,
            'note' => fake()->optional()->sentence(),
        ];
    }
}
