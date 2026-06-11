<?php

use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\MetersRelationManager;
use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Filament\Resources\MeterReadings\Pages\ListMeterReadings;
use App\Filament\Resources\Meters\MeterResource;
use App\Filament\Resources\Meters\Pages\CreateMeter;
use App\Filament\Resources\Meters\Pages\ListMeters;
use App\Filament\Resources\Meters\RelationManagers\ReadingsRelationManager;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use App\Models\UtilityService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsMeterTenant(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

test('meters belong to an organization client and utility service', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'meter',
        ]);

    $meter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'number' => 'MTR-10001',
            'initial_reading' => 15.25,
        ]);

    expect($meter->organization->is($organization))->toBeTrue()
        ->and($meter->client->is($client))->toBeTrue()
        ->and($meter->utilityService->is($utilityService))->toBeTrue()
        ->and($meter->initial_reading)->toBe('15.2500')
        ->and($organization->meters()->whereKey($meter)->exists())->toBeTrue();
});

test('meter number is unique inside an organization', function () {
    $organization = Organization::factory()->create();

    Meter::factory()->for($organization)->create([
        'number' => 'MTR-10001',
    ]);

    expect(fn () => Meter::factory()->for($organization)->create([
        'number' => 'MTR-10001',
    ]))->toThrow(QueryException::class);
});

test('a client can have multiple active meters', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'meter',
        ]);

    $firstMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'status' => 'active',
        ]);

    $secondMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'status' => 'active',
        ]);

    $removedMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'status' => 'removed',
        ]);

    expect($firstMeter)->toBeInstanceOf(Meter::class)
        ->and($secondMeter)->toBeInstanceOf(Meter::class)
        ->and($removedMeter)->toBeInstanceOf(Meter::class)
        ->and($client->meters()->where('status', 'active')->count())->toBe(2);
});

test('meter readings calculate consumption from current and previous readings', function () {
    $organization = Organization::factory()->create();
    $meter = Meter::factory()->for($organization)->create();

    $reading = MeterReading::factory()
        ->for($meter)
        ->create([
            'period' => '202605',
            'previous_reading' => 120.5,
            'current_reading' => 155.75,
        ]);

    expect($reading->organization->is($meter->organization))->toBeTrue()
        ->and($reading->client->is($meter->client))->toBeTrue()
        ->and($reading->utilityService->is($meter->utilityService))->toBeTrue()
        ->and($reading->previous_reading)->toBe('120.5000')
        ->and($reading->current_reading)->toBe('155.7500')
        ->and($reading->consumption)->toBe('35.2500');
});

test('meter readings default previous reading from meter history', function () {
    $meter = Meter::factory()->create([
        'initial_reading' => 10,
    ]);

    MeterReading::factory()
        ->for($meter)
        ->create([
            'period' => '202604',
            'previous_reading' => 10,
            'current_reading' => 17.5,
        ]);

    closedBillingPeriodFor($meter->organization, '202604');

    $reading = MeterReading::query()->create([
        'meter_id' => $meter->id,
        'period' => '202605',
        'current_reading' => 21.25,
    ]);

    expect($reading->previous_reading)->toBe('17.5000')
        ->and($reading->consumption)->toBe('3.7500');
});

test('one meter reading is allowed per meter and period', function () {
    $meter = Meter::factory()->create();

    MeterReading::factory()->for($meter)->create([
        'period' => '202605',
    ]);

    expect(fn () => MeterReading::factory()->for($meter)->create([
        'period' => '202605',
    ]))->toThrow(QueryException::class);
});

test('the same period can be used for different meters', function () {
    $organization = Organization::factory()->create();

    $firstReading = MeterReading::factory()
        ->for(Meter::factory()->for($organization))
        ->create([
            'period' => '202605',
        ]);

    $secondReading = MeterReading::factory()
        ->for(Meter::factory()->for($organization))
        ->create([
            'period' => '202605',
        ]);

    expect($firstReading)->toBeInstanceOf(MeterReading::class)
        ->and($secondReading)->toBeInstanceOf(MeterReading::class);
});

test('admin users can create and list meters for the current tenant', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Электроэнергия',
    ]);
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '60001',
            'billing_type' => 'meter',
        ]);

    $otherTenantMeter = Meter::factory()->for(Organization::factory())->create();

    actingAsMeterTenant($organization);

    Livewire::test(CreateMeter::class)
        ->fillForm([
            'client_id' => $client->id,
            'number' => 'MTR-60001',
            'installed_on' => '2026-05-01',
            'initial_reading' => 10,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $meter = Meter::query()
        ->whereBelongsTo($organization)
        ->where('number', 'MTR-60001')
        ->sole();

    Livewire::test(ListMeters::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$meter])
        ->assertCanNotSeeTableRecords([$otherTenantMeter]);
});

test('meter removed date and status are managed by archive actions', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'meter',
        ]);
    $meter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'status' => 'active',
            'removed_on' => null,
        ]);

    actingAsMeterTenant($organization);

    Livewire::test(CreateMeter::class)
        ->assertFormFieldDoesNotExist('removed_on')
        ->assertFormFieldDoesNotExist('status');

    Livewire::test(ListMeters::class)
        ->callTableAction('archive', $meter)
        ->assertHasNoTableActionErrors();

    expect($meter->refresh()->status)->toBe('removed')
        ->and($meter->removed_on?->toDateString())->toBe(today()->toDateString())
        ->and($meter->isArchived())->toBeTrue();

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callTableAction('restoreFromArchive', $meter)
        ->assertHasNoTableActionErrors();

    expect($meter->refresh()->status)->toBe('active')
        ->and($meter->removed_on)->toBeNull()
        ->and($meter->isArchived())->toBeFalse();
});

test('admin users can create and list meter readings for the current tenant', function () {
    $organization = Organization::factory()->create();
    $meter = Meter::factory()->for($organization)->create([
        'number' => 'MTR-70001',
        'initial_reading' => 100,
    ]);

    $otherTenantReading = MeterReading::factory()->for(Meter::factory()->for(Organization::factory()))->create([
        'period' => '202605',
    ]);
    billingPeriodFor($organization);

    actingAsMeterTenant($organization);

    Livewire::test(CreateMeterReading::class)
        ->fillForm([
            'meter_id' => $meter->id,
            'current_reading' => 137.125,
            'read_at' => '2026-05-26',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $reading = MeterReading::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($meter)
        ->forPeriod('202605')
        ->sole();

    expect($reading->previous_reading)->toBe('100.0000')
        ->and($reading->consumption)->toBe('37.1250');

    Livewire::test(ListMeterReadings::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$reading])
        ->assertCanNotSeeTableRecords([$otherTenantReading]);
});

test('client meter table can add a reading for the selected meter', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'meter',
        ]);
    $meter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'number' => 'MTR-CLIENT-1',
            'initial_reading' => 100,
        ]);

    MeterReading::factory()
        ->for($meter)
        ->create([
            'period' => '202604',
            'previous_reading' => 100,
            'current_reading' => 125,
        ]);
    closedBillingPeriodFor($organization, '202604');

    billingPeriodFor($organization);

    actingAsMeterTenant($organization);

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->assertTableActionExists('addReading', null, $meter)
        ->callTableAction('addReading', $meter, data: [
            'current_reading' => 140.75,
            'read_at' => '2026-05-29',
            'note' => 'Показание из карточки абонента',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    $reading = MeterReading::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($client)
        ->whereBelongsTo($meter)
        ->forPeriod('202605')
        ->sole();

    expect($reading->previous_reading)->toBe('125.0000')
        ->and($reading->current_reading)->toBe('140.7500')
        ->and($reading->consumption)->toBe('15.7500')
        ->and($reading->read_at?->toDateString())->toBe('2026-05-29');
});

test('meter resource shows readings as a related table', function () {
    expect(MeterResource::getRelations())->toContain(ReadingsRelationManager::class);
});
