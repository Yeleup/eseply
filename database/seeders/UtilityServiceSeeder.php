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

        UtilityService::query()->firstOrCreate(
            ['organization_id' => $organization->id],
            [
                'name' => 'Водоснабжение',
                'unit_of_measurement' => 'м3',
                'status' => 'active',
            ],
        );
    }
}
