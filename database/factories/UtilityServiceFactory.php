<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\UtilityService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UtilityService>
 */
class UtilityServiceFactory extends Factory
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
            'name' => fake()->randomElement([
                'Водоснабжение',
                'Электроэнергия',
                'Вывоз мусора',
                'Канализация',
                'Обслуживание',
            ]),
            'unit_of_measurement' => fake()->randomElement(['м3', 'кВт⋅ч', 'месяц', 'человек', 'объект']),
            'status' => 'active',
            'note' => fake()->optional()->sentence(),
        ];
    }
}
