<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\UtilityService;
use Illuminate\Database\Seeder;

class UtilityServiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $organization = Organization::query()->first() ?? Organization::factory()->create();

        collect([
            ['name' => 'Водоснабжение', 'unit_of_measurement' => 'м3'],
            ['name' => 'Электроэнергия', 'unit_of_measurement' => 'кВт⋅ч'],
            ['name' => 'Вывоз мусора', 'unit_of_measurement' => 'месяц'],
            ['name' => 'Канализация', 'unit_of_measurement' => 'м3'],
            ['name' => 'Обслуживание', 'unit_of_measurement' => 'месяц'],
        ])->each(fn (array $service): UtilityService => UtilityService::factory()
            ->for($organization)
            ->create($service));
    }
}
