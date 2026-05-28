<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Tariff;
use App\Models\TariffCategory;
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
        $tariffCategory = TariffCategory::query()->whereBelongsTo($organization)->first()
            ?? TariffCategory::factory()->for($organization)->create(['name' => 'Население']);

        Tariff::factory()
            ->for($organization)
            ->for($utilityService)
            ->for($tariffCategory)
            ->create([
                'price' => 120,
                'starts_on' => now()->startOfMonth()->toDateString(),
            ]);
    }
}
