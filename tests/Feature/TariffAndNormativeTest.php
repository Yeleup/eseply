<?php

use App\Models\Normative;
use App\Models\Organization;
use App\Models\Tariff;
use App\Models\TariffCategory;
use App\Models\UtilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('tariffs belong to an organization service and tariff category', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $tariffCategory = TariffCategory::factory()->for($organization)->create();

    $tariff = Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($tariffCategory)
        ->create([
            'price' => 125.50,
            'starts_on' => '2026-01-01',
        ]);

    expect($tariff->organization->is($organization))->toBeTrue()
        ->and($tariff->utilityService->is($utilityService))->toBeTrue()
        ->and($tariff->tariffCategory->is($tariffCategory))->toBeTrue()
        ->and($organization->tariffs()->whereKey($tariff)->exists())->toBeTrue()
        ->and($utilityService->tariffs()->whereKey($tariff)->exists())->toBeTrue()
        ->and($tariffCategory->tariffs()->whereKey($tariff)->exists())->toBeTrue()
        ->and($tariff->price)->toBe('125.50')
        ->and($tariff->starts_on->toDateString())->toBe('2026-01-01');
});

test('normatives belong to an organization service and tariff category', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $tariffCategory = TariffCategory::factory()->for($organization)->create();

    $normative = Normative::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($tariffCategory)
        ->create([
            'value' => 4.75,
            'calculation_type' => 'per_person',
            'starts_on' => '2026-01-01',
        ]);

    expect($normative->organization->is($organization))->toBeTrue()
        ->and($normative->utilityService->is($utilityService))->toBeTrue()
        ->and($normative->tariffCategory->is($tariffCategory))->toBeTrue()
        ->and($organization->normatives()->whereKey($normative)->exists())->toBeTrue()
        ->and($utilityService->normatives()->whereKey($normative)->exists())->toBeTrue()
        ->and($tariffCategory->normatives()->whereKey($normative)->exists())->toBeTrue()
        ->and($normative->value)->toBe('4.7500')
        ->and($normative->calculation_type)->toBe('per_person')
        ->and($normative->starts_on->toDateString())->toBe('2026-01-01');
});
