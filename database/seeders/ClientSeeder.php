<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $organization = Organization::query()->first() ?? Organization::factory()->create();

        Client::factory()
            ->count(5)
            ->for($organization)
            ->create();
    }
}
