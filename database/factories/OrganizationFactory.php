<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'bin_iin' => fake()->numerify('############'),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'bank' => fake()->company().' Bank',
            'iban' => fake()->iban('KZ'),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
