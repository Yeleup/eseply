<?php

use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\MetersRelationManager;
use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Filament\Resources\MeterReadings\Pages\EditMeterReading;
use App\Filament\Resources\MeterReadings\Pages\ListMeterReadings;
use App\Filament\Resources\Meters\MeterResource;
use App\Filament\Resources\Meters\Pages\CreateMeter;
use App\Filament\Resources\Meters\Pages\EditMeter;
use App\Filament\Resources\Meters\Pages\ListMeters;
use App\Filament\Resources\Meters\RelationManagers\ReadingsRelationManager;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\Region;
use App\Models\Street;
use App\Models\User;
use App\Models\UtilityService;
use App\OrganizationMemberRole;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

function actingAsMeterController(Organization $organization, Region $region): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, [
        'role' => OrganizationMemberRole::Controller->value,
    ]);

    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'region_id' => $region->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

/**
 * A meter of a region-assigned client with an open month, where the previous
 * reading is the meter's initial reading.
 *
 * @return array{organization: Organization, region: Region, meter: Meter}
 */
function meterWithPreviousReading(int $previousReading): array
{
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create();
    $street = Street::factory()->for($region)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($region)
        ->for($street)
        ->create([
            'billing_type' => 'meter',
        ]);
    $meter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'number' => 'MTR-BELOW-PREVIOUS',
            'initial_reading' => $previousReading,
        ]);

    billingPeriodFor($organization);

    return compact('organization', 'region', 'meter');
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
            'initial_reading' => 1224,
        ]);

    expect($meter->organization->is($organization))->toBeTrue()
        ->and($meter->client->is($client))->toBeTrue()
        ->and($meter->utilityService->is($utilityService))->toBeTrue()
        ->and($meter->initial_reading)->toBe(1224)
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

test('meter initial reading cannot be changed after creation', function () {
    $meter = Meter::factory()->create([
        'number' => 'MTR-IMMUTABLE',
        'initial_reading' => 12,
    ]);

    $meter->update([
        'number' => 'MTR-IMMUTABLE-UPDATED',
        'initial_reading' => 99,
    ]);

    expect($meter->refresh()->number)->toBe('MTR-IMMUTABLE-UPDATED')
        ->and($meter->initial_reading)->toBe(12);
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
            'previous_reading' => 120,
            'current_reading' => 155,
        ]);

    expect($reading->organization->is($meter->organization))->toBeTrue()
        ->and($reading->client->is($meter->client))->toBeTrue()
        ->and($reading->utilityService->is($meter->utilityService))->toBeTrue()
        ->and($reading->previous_reading)->toBe(120)
        ->and($reading->current_reading)->toBe(155)
        ->and($reading->consumption)->toBe(35);
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
            'current_reading' => 17,
        ]);

    closedBillingPeriodFor($meter->organization, '202604');

    $reading = MeterReading::query()->create([
        'meter_id' => $meter->id,
        'period' => '202605',
        'current_reading' => 21,
    ]);

    expect($reading->previous_reading)->toBe(17)
        ->and($reading->consumption)->toBe(4);
});

test('one meter reading is allowed per meter and period', function () {
    $meter = Meter::factory()->create();

    MeterReading::factory()->for($meter)->create([
        'period' => '202605',
    ]);

    expect(fn () => MeterReading::factory()->for($meter)->create([
        'period' => '202605',
    ]))->toThrow(
        ValidationException::class,
        'За текущий расчётный месяц по этому счётчику уже есть запись. Измените существующую запись вместо создания новой.',
    );
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
        ->assertFormFieldEnabled('initial_reading')
        ->assertFormSet([
            'installed_on' => today()->toDateString(),
        ])
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

    Livewire::test(EditMeter::class, [
        'record' => $meter->getRouteKey(),
    ])
        ->assertFormFieldDisabled('initial_reading');

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
            'current_reading' => 137,
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

    expect($reading->previous_reading)->toBe(100)
        ->and($reading->consumption)->toBe(37);

    Livewire::test(CreateMeterReading::class)
        ->fillForm([
            'meter_id' => $meter->id,
            'current_reading' => 150,
        ])
        ->call('create')
        ->assertHasFormErrors(['current_reading']);

    Livewire::test(ListMeterReadings::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$reading])
        ->assertCanNotSeeTableRecords([$otherTenantReading]);
});

test('client meter table can add or update the current month reading for the selected meter', function () {
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
            'current_reading' => 140,
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

    expect($reading->previous_reading)->toBe(125)
        ->and($reading->current_reading)->toBe(140)
        ->and($reading->consumption)->toBe(15)
        ->and($reading->read_at?->toDateString())->toBe('2026-05-29');

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->mountTableAction('addReading', $meter)
        ->assertTableActionDataSet([
            'previous_reading' => 125,
            'current_reading' => 140,
            'read_at' => '2026-05-29',
            'note' => 'Показание из карточки абонента',
        ]);

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callTableAction('addReading', $meter, data: [
            'current_reading' => 150,
            'read_at' => '2026-05-30',
            'note' => 'Исправленное показание',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    expect($reading->refresh()->previous_reading)->toBe(125)
        ->and($reading->current_reading)->toBe(150)
        ->and($reading->consumption)->toBe(25)
        ->and($reading->read_at?->toDateString())->toBe('2026-05-30')
        ->and($reading->note)->toBe('Исправленное показание')
        ->and(MeterReading::query()->whereBelongsTo($meter)->forPeriod('202605')->count())->toBe(1);

    closedBillingPeriodFor($organization, '202605');
    billingPeriodFor($organization, '202606');

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->mountTableAction('addReading', $meter)
        ->assertTableActionDataSet([
            'previous_reading' => 150,
        ]);

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callTableAction('addReading', $meter, data: [
            'current_reading' => 175,
            'read_at' => '2026-06-29',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    $nextReading = MeterReading::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($client)
        ->whereBelongsTo($meter)
        ->forPeriod('202606')
        ->sole();

    expect($nextReading->previous_reading)->toBe(150)
        ->and($nextReading->current_reading)->toBe(175)
        ->and($nextReading->consumption)->toBe(25)
        ->and($nextReading->read_at?->toDateString())->toBe('2026-06-29');
});

test('meter resource shows readings as a related table', function () {
    expect(MeterResource::getRelations())->toContain(ReadingsRelationManager::class);
});

test('meter reading actions show billing period error and disable creation without an open month', function () {
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
        ->create();
    $user = actingAsMeterTenant($organization);

    $this->actingAs($user)
        ->get("/admin/{$organization->getKey()}/meter-readings/create")
        ->assertSuccessful()
        ->assertSee('Расчётный месяц не открыт');

    Livewire::test(ListMeterReadings::class)
        ->assertActionDisabled('create');

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->assertTableActionDisabled('addReading', $meter);

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->assertTableActionDisabled('create');
});

test('meter readings are stored as whole numbers', function () {
    $meter = Meter::factory()->create([
        'initial_reading' => 1224.6,
    ]);

    $reading = MeterReading::factory()
        ->for($meter)
        ->create([
            'period' => '202605',
            'previous_reading' => 1224.4,
            'current_reading' => 1250.7,
        ]);

    expect($meter->refresh()->initial_reading)->toBe(1225)
        ->and($reading->refresh()->previous_reading)->toBe(1224)
        ->and($reading->current_reading)->toBe(1251)
        ->and($reading->consumption)->toBe(27);
});

test('meter reading forms reject fractional readings', function () {
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
            'number' => 'MTR-INTEGER',
            'initial_reading' => 100,
        ]);

    billingPeriodFor($organization);
    actingAsMeterTenant($organization);

    Livewire::test(CreateMeterReading::class)
        ->fillForm([
            'meter_id' => $meter->id,
            'current_reading' => 137.125,
        ])
        ->call('create')
        ->assertHasFormErrors(['current_reading']);

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->callTableAction('create', data: [
            'current_reading' => 137.125,
        ])
        ->assertHasTableActionErrors(['current_reading']);

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callTableAction('addReading', $meter, data: [
            'current_reading' => 137.125,
        ])
        ->assertHasTableActionErrors(['current_reading']);

    expect(MeterReading::query()->whereBelongsTo($meter)->count())->toBe(0);
});

test('meter reading forms accept 99999 and reject greater values on the server', function (): void {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create(['billing_type' => 'meter']);
    $resourceMeter = Meter::factory()->for($organization)->for($client)->for($utilityService)->create();
    $relationMeter = Meter::factory()->for($organization)->for($client)->for($utilityService)->create();
    $clientActionMeter = Meter::factory()->for($organization)->for($client)->for($utilityService)->create();
    billingPeriodFor($organization);
    actingAsMeterTenant($organization);

    Livewire::test(CreateMeterReading::class)
        ->fillForm([
            'meter_id' => $resourceMeter->id,
            'current_reading' => MeterReading::MAXIMUM_CURRENT_READING,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $resourceReading = MeterReading::query()->whereBelongsTo($resourceMeter)->sole();

    Livewire::test(EditMeterReading::class, ['record' => $resourceReading->getRouteKey()])
        ->fillForm(['current_reading' => MeterReading::MAXIMUM_CURRENT_READING + 1])
        ->call('save')
        ->assertHasFormErrors(['current_reading']);

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $relationMeter,
        'pageClass' => EditMeter::class,
    ])
        ->callTableAction('create', data: ['current_reading' => MeterReading::MAXIMUM_CURRENT_READING])
        ->assertHasNoTableActionErrors();

    $relationReading = MeterReading::query()->whereBelongsTo($relationMeter)->sole();

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $relationMeter,
        'pageClass' => EditMeter::class,
    ])
        ->callTableAction('edit', $relationReading, data: ['current_reading' => MeterReading::MAXIMUM_CURRENT_READING + 1])
        ->assertHasTableActionErrors(['current_reading']);

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callTableAction('addReading', $clientActionMeter, data: ['current_reading' => MeterReading::MAXIMUM_CURRENT_READING])
        ->assertHasNoTableActionErrors();

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callTableAction('addReading', $clientActionMeter, data: ['current_reading' => MeterReading::MAXIMUM_CURRENT_READING + 1])
        ->assertHasTableActionErrors(['current_reading']);

    expect($resourceReading->refresh()->current_reading)->toBe(MeterReading::MAXIMUM_CURRENT_READING)
        ->and($relationReading->refresh()->current_reading)->toBe(MeterReading::MAXIMUM_CURRENT_READING)
        ->and(MeterReading::query()->whereBelongsTo($clientActionMeter)->sole()->current_reading)->toBe(MeterReading::MAXIMUM_CURRENT_READING);
});

test('meter reading model rejects a value greater than 99999', function (): void {
    $organization = Organization::factory()->create();
    $meter = Meter::factory()->for($organization)->create();
    billingPeriodFor($organization);

    expect(fn (): MeterReading => MeterReading::query()->create([
        'meter_id' => $meter->id,
        'current_reading' => MeterReading::MAXIMUM_CURRENT_READING + 1,
    ]))->toThrow(ValidationException::class, MeterReading::maximumCurrentReadingMessage());

    expect(MeterReading::query()->whereBelongsTo($meter)->exists())->toBeFalse();
});

test('an existing reading greater than 99999 can keep its note without changing its reading', function (): void {
    $organization = Organization::factory()->create();
    $meter = Meter::factory()->for($organization)->create();
    $billingPeriod = billingPeriodFor($organization);

    $readingId = DB::table('meter_readings')->insertGetId([
        'organization_id' => $organization->id,
        'meter_id' => $meter->id,
        'client_id' => $meter->client_id,
        'utility_service_id' => $meter->utility_service_id,
        'billing_period_id' => $billingPeriod->id,
        'previous_reading' => 0,
        'current_reading' => MeterReading::MAXIMUM_CURRENT_READING + 1,
        'consumption' => MeterReading::MAXIMUM_CURRENT_READING + 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $reading = MeterReading::query()->findOrFail($readingId);
    $reading->update(['note' => 'Сохранённое старое показание']);

    expect($reading->refresh()->current_reading)->toBe(MeterReading::MAXIMUM_CURRENT_READING + 1)
        ->and($reading->note)->toBe('Сохранённое старое показание');

    expect(fn (): bool => $reading->update([
        'current_reading' => MeterReading::MAXIMUM_CURRENT_READING + 2,
    ]))->toThrow(ValidationException::class, MeterReading::maximumCurrentReadingMessage());
});

test('meter forms reject a fractional initial reading', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'meter',
        ]);

    actingAsMeterTenant($organization);

    Livewire::test(CreateMeter::class)
        ->fillForm([
            'client_id' => $client->id,
            'number' => 'MTR-FRACTIONAL',
            'initial_reading' => 15.25,
        ])
        ->call('create')
        ->assertHasFormErrors(['initial_reading']);

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callTableAction('create', data: [
            'number' => 'MTR-FRACTIONAL-REL',
            'initial_reading' => 15.25,
        ])
        ->assertHasTableActionErrors(['initial_reading']);

    expect(Meter::query()->whereBelongsTo($organization)->count())->toBe(0);
});

test('the readings tab rejects a controller reading below the previous one', function () {
    ['organization' => $organization, 'region' => $region, 'meter' => $meter] = meterWithPreviousReading(500);

    actingAsMeterController($organization, $region);

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->callTableAction('create', data: [
            'current_reading' => 400,
        ])
        ->assertHasTableActionErrors(['current_reading']);

    expect(MeterReading::query()->whereBelongsTo($meter)->count())->toBe(0);
});

test('the readings tab rejects a controller edit below the previous reading', function () {
    ['organization' => $organization, 'region' => $region, 'meter' => $meter] = meterWithPreviousReading(500);

    $reading = MeterReading::factory()
        ->for($meter)
        ->create([
            'period' => '202605',
            'previous_reading' => 500,
            'current_reading' => 600,
        ]);

    actingAsMeterController($organization, $region);

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->callTableAction('edit', $reading, data: [
            'current_reading' => 400,
        ])
        ->assertHasTableActionErrors(['current_reading']);

    expect($reading->refresh()->current_reading)->toBe(600)
        ->and($reading->consumption)->toBe(100);
});

test('the readings tab accepts a controller reading equal to the previous one', function () {
    ['organization' => $organization, 'region' => $region, 'meter' => $meter] = meterWithPreviousReading(500);

    actingAsMeterController($organization, $region);

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->callTableAction('create', data: [
            'current_reading' => 500,
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    $reading = MeterReading::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($meter)
        ->forPeriod('202605')
        ->sole();

    expect($reading->previous_reading)->toBe(500)
        ->and($reading->current_reading)->toBe(500)
        ->and($reading->consumption)->toBe(0);
});

test('the readings tab accepts an operator reading below the previous one', function () {
    ['organization' => $organization, 'meter' => $meter] = meterWithPreviousReading(500);

    actingAsMeterTenant($organization);

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->callTableAction('create', data: [
            'current_reading' => 400,
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    $reading = MeterReading::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($meter)
        ->forPeriod('202605')
        ->sole();

    expect($reading->previous_reading)->toBe(500)
        ->and($reading->current_reading)->toBe(400)
        ->and($reading->consumption)->toBe(-100);
});

test('the readings tab ignores a tampered previous reading on edit', function () {
    ['organization' => $organization, 'region' => $region, 'meter' => $meter] = meterWithPreviousReading(500);

    $reading = MeterReading::factory()
        ->for($meter)
        ->create([
            'period' => '202605',
            'previous_reading' => 500,
            'current_reading' => 600,
        ]);

    actingAsMeterController($organization, $region);

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->callTableAction('edit', $reading, data: [
            'previous_reading' => 99999,
            'current_reading' => 600,
        ])
        ->assertHasNoTableActionErrors();

    expect($reading->refresh()->previous_reading)->toBe(500)
        ->and($reading->consumption)->toBe(100);
});

test('the readings tab lets a controller re-save a below-previous reading left by an operator', function () {
    ['organization' => $organization, 'region' => $region, 'meter' => $meter] = meterWithPreviousReading(500);

    $reading = MeterReading::factory()
        ->for($meter)
        ->create([
            'period' => '202605',
            'previous_reading' => 500,
            'current_reading' => 400,
        ]);

    actingAsMeterController($organization, $region);

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->callTableAction('edit', $reading, data: [
            'current_reading' => 400,
            'note' => 'Счётчик заменён, фото приложено',
        ])
        ->assertHasNoTableActionErrors();

    expect($reading->refresh()->note)->toBe('Счётчик заменён, фото приложено')
        ->and($reading->current_reading)->toBe(400)
        ->and($reading->consumption)->toBe(-100);
});

test('the readings tab still stops a controller from lowering a below-previous reading further', function () {
    ['organization' => $organization, 'region' => $region, 'meter' => $meter] = meterWithPreviousReading(500);

    $reading = MeterReading::factory()
        ->for($meter)
        ->create([
            'period' => '202605',
            'previous_reading' => 500,
            'current_reading' => 400,
        ]);

    actingAsMeterController($organization, $region);

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->callTableAction('edit', $reading, data: [
            'current_reading' => 350,
        ])
        ->assertHasTableActionErrors([
            'current_reading' => [MeterReading::belowPreviousReadingMessage(500)],
        ]);

    expect($reading->refresh()->current_reading)->toBe(400);
});
