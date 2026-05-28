<?php

use App\Models\Organization;
use App\Models\UtilityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('organization has one utility service', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    expect($utilityService->organization->is($organization))->toBeTrue()
        ->and($organization->utilityService->is($utilityService))->toBeTrue();
});

test('organization cannot have multiple utility services', function () {
    $organization = Organization::factory()->create();

    UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
    ]);

    expect(fn () => UtilityService::factory()->for($organization)->create([
        'name' => 'Вывоз мусора',
    ]))->toThrow(QueryException::class);
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
