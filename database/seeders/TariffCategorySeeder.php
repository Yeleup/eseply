<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\TariffCategory;
use Illuminate\Database\Seeder;

class TariffCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $organization = Organization::query()->first() ?? Organization::factory()->create();

        collect(['Население', 'Юрлица', 'Коммерческие объекты'])
            ->each(fn (string $name): TariffCategory => TariffCategory::factory()
                ->for($organization)
                ->create(['name' => $name]));
    }
}
