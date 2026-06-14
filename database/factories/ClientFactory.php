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
            'account_number' => null,
            'name' => fake()->name(),
            'iin' => fake()->unique()->numerify('############'),
            'client_type' => ClientType::Individual->value,
            'phone' => fake()->unique()->phoneNumber(),
            'contract' => 'Contract '.fake()->numerify('####'),
            'technical_conditions' => null,
            'region_id' => null,
            'street_id' => null,
            'house' => null,
            'apartment' => null,
            'status' => 'active',
            'billing_type' => 'per_person',
            'residents_count' => fake()->numberBetween(1, 6),
            'fixed_amount' => 0,
            'note' => fake()->optional()->sentence(),
        ];
    }
}
