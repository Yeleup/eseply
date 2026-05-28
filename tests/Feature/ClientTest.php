<?php

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\Organization;
use App\Models\TariffCategory;
use App\Models\User;
use App\Models\UtilityService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsTenant(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

test('clients belong to an organization', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create();

    expect($client->organization->is($organization))->toBeTrue()
        ->and($organization->clients()->whereKey($client)->exists())->toBeTrue();
});

test('account number is unique inside an organization', function () {
    $organization = Organization::factory()->create();

    Client::factory()->for($organization)->create([
        'account_number' => '10001',
    ]);

    expect(fn () => Client::factory()->for($organization)->create([
        'account_number' => '10001',
    ]))->toThrow(QueryException::class);
});

test('account number can repeat across organizations', function () {
    Client::factory()->for(Organization::factory())->create([
        'account_number' => '10001',
    ]);

    $client = Client::factory()->for(Organization::factory())->create([
        'account_number' => '10001',
    ]);

    expect($client)->toBeInstanceOf(Client::class);
});

test('admin users can list only current tenant clients', function () {
    $organization = Organization::factory()->create();
    $currentTenantClient = Client::factory()->for($organization)->create();
    $otherTenantClient = Client::factory()->for(Organization::factory())->create();

    actingAsTenant($organization);

    Livewire::test(ListClients::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$currentTenantClient])
        ->assertCanNotSeeTableRecords([$otherTenantClient]);
});

test('admin users can create a client for the current tenant', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
    ]);
    $tariffCategory = TariffCategory::factory()->for($organization)->create([
        'name' => 'Население',
    ]);

    actingAsTenant($organization);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'account_number' => '20001',
            'name' => 'Иванов Иван',
            'client_type' => 'individual',
            'tariff_category_id' => $tariffCategory->id,
            'billing_type' => 'normative',
            'residents_count' => 3,
            'area' => 45.5,
            'fixed_amount' => 0,
            'phone' => '+7 777 111 22 33',
            'address' => 'Алматы, Абая 10',
            'status' => 'active',
            'starting_balance' => 1500,
            'note' => 'Тестовый клиент',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    expect(Client::query()
        ->whereBelongsTo($organization)
        ->where('account_number', '20001')
        ->where('name', 'Иванов Иван')
        ->whereBelongsTo($tariffCategory)
        ->whereBelongsTo($utilityService)
        ->where('billing_type', 'normative')
        ->where('residents_count', 3)
        ->exists())->toBeTrue();
});

test('clients store billing settings', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $tariffCategory = TariffCategory::factory()->for($organization)->create();

    $client = Client::factory()
        ->for($organization)
        ->for($tariffCategory)
        ->create([
            'billing_type' => 'fixed',
            'residents_count' => 2,
            'area' => 64.25,
            'fixed_amount' => 12500,
        ]);

    expect($client->refresh()->utilityService->is($utilityService))->toBeTrue()
        ->and($client->tariffCategory->is($tariffCategory))->toBeTrue()
        ->and($client->billing_type)->toBe('fixed')
        ->and($client->residents_count)->toBe(2)
        ->and($client->area)->toBe('64.25')
        ->and($client->fixed_amount)->toBe('12500.00');
});

test('admin client form validates account number uniqueness inside current tenant', function () {
    $organization = Organization::factory()->create();

    Client::factory()->for($organization)->create([
        'account_number' => '20001',
    ]);

    actingAsTenant($organization);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'account_number' => '20001',
            'name' => 'Петров Пётр',
            'client_type' => 'individual',
            'status' => 'active',
            'starting_balance' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['account_number']);

    expect(Client::query()
        ->whereBelongsTo($organization)
        ->where('account_number', '20001')
        ->count())->toBe(1);
});
