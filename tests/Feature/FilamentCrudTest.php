<?php

use App\ClientType;
use App\Filament\Resources\Tariffs\Pages\CreateTariff;
use App\Filament\Resources\Tariffs\Pages\ListTariffs;
use App\Models\Organization;
use App\Models\Tariff;
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

    actingAsCrudTenant($organization);

    Livewire::test(CreateTariff::class)
        ->fillForm([
            'client_type' => ClientType::Individual->value,
            'unit_price' => 125.50,
            'per_person_price' => 650,
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
        ->where('client_type', ClientType::Individual->value)
        ->whereDate('starts_on', '2026-01-01')
        ->firstOrFail();

    expect($tariff->unit_price)->toBe('125.50')
        ->and($tariff->per_person_price)->toBe('650.00');
});
