<?php

use App\Filament\Resources\Normatives\Pages\CreateNormative;
use App\Filament\Resources\Normatives\Pages\ListNormatives;
use App\Filament\Resources\TariffCategories\Pages\CreateTariffCategory;
use App\Filament\Resources\TariffCategories\Pages\ListTariffCategories;
use App\Filament\Resources\Tariffs\Pages\CreateTariff;
use App\Filament\Resources\Tariffs\Pages\ListTariffs;
use App\Filament\Resources\UtilityServices\Pages\CreateUtilityService;
use App\Filament\Resources\UtilityServices\Pages\ListUtilityServices;
use App\Models\Normative;
use App\Models\Organization;
use App\Models\Tariff;
use App\Models\TariffCategory;
use App\Models\User;
use App\Models\UtilityService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsCrudTenant(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

test('admin users can list only current tenant utility services', function () {
    $organization = Organization::factory()->create();
    $currentTenantService = UtilityService::factory()->for($organization)->create();
    $otherTenantService = UtilityService::factory()->for(Organization::factory())->create();

    actingAsCrudTenant($organization);

    Livewire::test(ListUtilityServices::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$currentTenantService])
        ->assertCanNotSeeTableRecords([$otherTenantService]);
});

test('admin users can create a utility service for the current tenant', function () {
    $organization = Organization::factory()->create();

    actingAsCrudTenant($organization);

    Livewire::test(CreateUtilityService::class)
        ->fillForm([
            'name' => 'Фильтрованная вода',
            'unit_of_measurement' => 'м3',
            'status' => 'active',
            'note' => 'Основная коммунальная услуга',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    expect(UtilityService::query()
        ->whereBelongsTo($organization)
        ->where('name', 'Фильтрованная вода')
        ->where('unit_of_measurement', 'м3')
        ->exists())->toBeTrue();
});

test('admin users can list only current tenant tariff categories', function () {
    $organization = Organization::factory()->create();
    $currentTenantCategory = TariffCategory::factory()->for($organization)->create();
    $otherTenantCategory = TariffCategory::factory()->for(Organization::factory())->create();

    actingAsCrudTenant($organization);

    Livewire::test(ListTariffCategories::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$currentTenantCategory])
        ->assertCanNotSeeTableRecords([$otherTenantCategory]);
});

test('admin users can create a tariff category for the current tenant', function () {
    $organization = Organization::factory()->create();

    actingAsCrudTenant($organization);

    Livewire::test(CreateTariffCategory::class)
        ->fillForm([
            'name' => 'Бюджетные организации',
            'status' => 'active',
            'note' => 'Отдельная категория тарифа',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    expect(TariffCategory::query()
        ->whereBelongsTo($organization)
        ->where('name', 'Бюджетные организации')
        ->exists())->toBeTrue();
});

test('admin users can list only current tenant tariffs', function () {
    $organization = Organization::factory()->create();
    $currentTenantTariff = Tariff::factory()->for($organization)->create();
    $otherTenantTariff = Tariff::factory()->for(Organization::factory())->create();

    actingAsCrudTenant($organization);

    Livewire::test(ListTariffs::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$currentTenantTariff])
        ->assertCanNotSeeTableRecords([$otherTenantTariff]);
});

test('admin users can create a tariff for the current tenant', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
    ]);
    $tariffCategory = TariffCategory::factory()->for($organization)->create([
        'name' => 'Население',
    ]);

    actingAsCrudTenant($organization);

    Livewire::test(CreateTariff::class)
        ->fillForm([
            'utility_service_id' => $utilityService->id,
            'tariff_category_id' => $tariffCategory->id,
            'price' => 125.50,
            'starts_on' => '2026-01-01',
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $tariff = Tariff::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($utilityService)
        ->whereBelongsTo($tariffCategory)
        ->whereDate('starts_on', '2026-01-01')
        ->firstOrFail();

    expect($tariff->price)->toBe('125.50');
});

test('admin users can list only current tenant normatives', function () {
    $organization = Organization::factory()->create();
    $currentTenantNormative = Normative::factory()->for($organization)->create();
    $otherTenantNormative = Normative::factory()->for(Organization::factory())->create();

    actingAsCrudTenant($organization);

    Livewire::test(ListNormatives::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$currentTenantNormative])
        ->assertCanNotSeeTableRecords([$otherTenantNormative]);
});

test('admin users can create a normative for the current tenant', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
    ]);
    $tariffCategory = TariffCategory::factory()->for($organization)->create([
        'name' => 'Население',
    ]);

    actingAsCrudTenant($organization);

    Livewire::test(CreateNormative::class)
        ->fillForm([
            'utility_service_id' => $utilityService->id,
            'tariff_category_id' => $tariffCategory->id,
            'value' => 4.75,
            'calculation_type' => 'per_person',
            'starts_on' => '2026-01-01',
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $normative = Normative::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($utilityService)
        ->whereBelongsTo($tariffCategory)
        ->where('calculation_type', 'per_person')
        ->whereDate('starts_on', '2026-01-01')
        ->firstOrFail();

    expect($normative->value)->toBe('4.7500');
});
