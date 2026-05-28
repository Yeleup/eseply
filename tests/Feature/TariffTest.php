<?php

use App\ClientType;
use App\Models\Organization;
use App\Models\Tariff;
use App\Models\UtilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('tariffs belong to an organization service and client type', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
    ]);

    $tariff = Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'client_type' => ClientType::Llp->value,
            'unit_price' => 125.50,
            'per_person_price' => 900,
            'starts_on' => '2026-01-01',
        ]);

    expect($tariff->organization->is($organization))->toBeTrue()
        ->and($tariff->utilityService->is($utilityService))->toBeTrue()
        ->and($organization->tariffs()->whereKey($tariff)->exists())->toBeTrue()
        ->and($utilityService->tariffs()->whereKey($tariff)->exists())->toBeTrue()
        ->and($tariff->client_type)->toBe(ClientType::Llp)
        ->and($tariff->unit_price)->toBe('125.50')
        ->and($tariff->per_person_price)->toBe('900.00')
        ->and($tariff->starts_on->toDateString())->toBe('2026-01-01');
});

test('tariffs are selected by client type and period', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'client_type' => ClientType::Individual->value,
            'unit_price' => 100,
            'starts_on' => '2026-01-01',
        ]);

    $latestTariff = Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'client_type' => ClientType::Individual->value,
            'unit_price' => 125,
            'starts_on' => '2026-05-01',
        ]);

    Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'client_type' => ClientType::Budget->value,
            'unit_price' => 50,
            'starts_on' => '2026-05-01',
        ]);

    $tariff = Tariff::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($utilityService)
        ->where('client_type', ClientType::Individual->value)
        ->whereDate('starts_on', '<=', '2026-05-01')
        ->orderByDesc('starts_on')
        ->first();

    expect($tariff?->is($latestTariff))->toBeTrue();
});
