<?php

namespace Database\Seeders;

use App\Models\Normative;
use App\Models\Organization;
use App\Models\TariffCategory;
use App\Models\UtilityService;
use Illuminate\Database\Seeder;

class NormativeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $organization = Organization::query()->first() ?? Organization::factory()->create();
        $utilityService = UtilityService::query()->whereBelongsTo($organization)->first()
            ?? UtilityService::factory()->for($organization)->create(['name' => 'Водоснабжение']);
        $tariffCategory = TariffCategory::query()->whereBelongsTo($organization)->first()
            ?? TariffCategory::factory()->for($organization)->create(['name' => 'Население']);

        Normative::factory()
            ->for($organization)
            ->for($utilityService)
            ->for($tariffCategory)
            ->create([
                'value' => 4.5,
                'calculation_type' => 'per_person',
                'starts_on' => now()->startOfMonth()->toDateString(),
            ]);
    }
}
