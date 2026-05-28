<?php

namespace Database\Seeders;

use App\ClientType;
use App\Models\Organization;
use App\Models\Tariff;
use App\Models\UtilityService;
use Illuminate\Database\Seeder;

class TariffSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $organization = Organization::query()->first() ?? Organization::factory()->create();
        $utilityService = $organization->utilityService
            ?? UtilityService::factory()->for($organization)->create(['name' => 'Водоснабжение']);

        Tariff::factory()
            ->for($organization)
            ->for($utilityService)
            ->create([
                'client_type' => ClientType::Individual->value,
                'unit_price' => 120,
                'per_person_price' => 600,
                'starts_on' => now()->startOfMonth()->toDateString(),
            ]);
    }
}
