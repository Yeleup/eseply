<?php

use App\Models\Organization;
use App\Models\UtilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('utility services belong to an organization', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    expect($utilityService->organization->is($organization))->toBeTrue()
        ->and($organization->utilityServices()->whereKey($utilityService)->exists())->toBeTrue();
});

test('utility services can repeat names across organizations', function () {
    UtilityService::factory()->for(Organization::factory())->create([
        'name' => 'Водоснабжение',
    ]);

    $utilityService = UtilityService::factory()->for(Organization::factory())->create([
        'name' => 'Водоснабжение',
    ]);

    expect($utilityService)->toBeInstanceOf(UtilityService::class);
});
