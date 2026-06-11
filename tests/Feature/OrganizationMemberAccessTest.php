<?php

use App\Filament\Pages\Reports\ViewReport;
use App\Filament\Pages\Tenancy\EditOrganizationProfile;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\RelationManagers\MetersRelationManager;
use App\Filament\Resources\Clients\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Filament\Resources\MeterReadings\Pages\EditMeterReading;
use App\Filament\Resources\MeterReadings\Pages\ListMeterReadings;
use App\Filament\Resources\Meters\Pages\CreateMeter;
use App\Filament\Resources\Meters\Pages\EditMeter;
use App\Filament\Resources\Meters\Pages\ListMeters;
use App\Filament\Resources\Meters\RelationManagers\ReadingsRelationManager;
use App\Filament\Resources\Tariffs\Pages\ListTariffs;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Reader;

uses(RefreshDatabase::class);

function actingAsOrganizationAccessTenant(Organization $organization, OrganizationMemberRole $role): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, [
        'role' => $role->value,
    ]);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

/**
 * @return list<list<mixed>>
 */
function organizationAccessDownloadedXlsxRows(array $downloadEffect): array
{
    $path = tempnam(sys_get_temp_dir(), 'organization-access-meter-reading-sheet-');

    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary XLSX file for assertions.');
    }

    $content = base64_decode((string) data_get($downloadEffect, 'content'), true);

    if ($content === false || file_put_contents($path, $content) === false) {
        throw new RuntimeException('Unable to write downloaded XLSX content for assertions.');
    }

    $reader = new Reader;

    try {
        $reader->open($path);

        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(
                    fn (Cell $cell): mixed => $cell->getValue(),
                    $row->getCells(),
                );
            }

            break;
        }

        return $rows;
    } finally {
        $reader->close();
        @unlink($path);
    }
}

test('organization member role is scoped to the organization membership', function () {
    $user = User::factory()->create();
    $controllerOrganization = Organization::factory()->create();
    $operatorOrganization = Organization::factory()->create();

    $user->organizations()->attach($controllerOrganization, [
        'role' => OrganizationMemberRole::Controller->value,
    ]);
    $user->organizations()->attach($operatorOrganization, [
        'role' => OrganizationMemberRole::Operator->value,
    ]);

    expect($user->organizationRole($controllerOrganization))->toBe(OrganizationMemberRole::Controller)
        ->and($user->organizationRole($operatorOrganization))->toBe(OrganizationMemberRole::Operator)
        ->and($user->isOrganizationController($controllerOrganization))->toBeTrue()
        ->and($user->isOrganizationOperator($operatorOrganization))->toBeTrue();
});

test('controller lists are limited to assigned regions and streets', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $assignedRegion = Region::factory()->for($organization)->create(['name' => 'Алмалинский']);
    $assignedRegionStreet = Street::factory()->for($assignedRegion)->create(['name' => 'Абая']);
    $streetOnlyRegion = Region::factory()->for($organization)->create(['name' => 'Бостандыкский']);
    $assignedStreet = Street::factory()->for($streetOnlyRegion)->create(['name' => 'Сатпаева']);
    $unassignedStreet = Street::factory()->for($streetOnlyRegion)->create(['name' => 'Жандосова']);

    $regionClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($assignedRegion)
        ->for($assignedRegionStreet)
        ->create([
            'account_number' => '100001',
            'name' => 'Абонент района',
            'billing_type' => 'meter',
            'status' => 'active',
        ]);
    $streetClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($streetOnlyRegion, 'region')
        ->for($assignedStreet)
        ->create([
            'account_number' => '100002',
            'name' => 'Абонент улицы',
            'billing_type' => 'meter',
            'status' => 'active',
        ]);
    $outsideClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($streetOnlyRegion, 'region')
        ->for($unassignedStreet)
        ->create([
            'account_number' => '100003',
            'name' => 'Абонент вне зоны',
            'billing_type' => 'meter',
            'status' => 'active',
        ]);

    $regionMeter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($regionClient)
        ->create(['number' => 'MTR-REGION', 'status' => 'active']);
    $streetMeter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($streetClient)
        ->create(['number' => 'MTR-STREET', 'status' => 'active']);
    $outsideMeter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($outsideClient)
        ->create(['number' => 'MTR-OUTSIDE', 'status' => 'active']);

    $regionReading = MeterReading::factory()->for($regionMeter)->create(['period' => '202605']);
    $streetReading = MeterReading::factory()->for($streetMeter)->create(['period' => '202605']);
    $outsideReading = MeterReading::factory()->for($outsideMeter)->create(['period' => '202605']);

    $user = actingAsOrganizationAccessTenant($organization, OrganizationMemberRole::Controller);
    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'region_id' => $assignedRegion->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('organization_user_streets')->insert([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'street_id' => $assignedStreet->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(ListClients::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$regionClient, $streetClient])
        ->assertCanNotSeeTableRecords([$outsideClient]);

    Livewire::test(ListMeters::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$regionMeter, $streetMeter])
        ->assertCanNotSeeTableRecords([$outsideMeter]);

    Livewire::test(ListMeterReadings::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$regionReading, $streetReading])
        ->assertCanNotSeeTableRecords([$outsideReading]);

    $download = Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->assertCanSeeTableRecords([$regionMeter, $streetMeter])
        ->assertCanNotSeeTableRecords([$outsideMeter])
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'meter-reading-sheet-'.$organization->getKey().'-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $flatRows = collect(organizationAccessDownloadedXlsxRows($download->effects['download']))->flatten();

    expect($flatRows->contains('MTR-REGION'))->toBeTrue()
        ->and($flatRows->contains('MTR-STREET'))->toBeTrue()
        ->and($flatRows->contains('MTR-OUTSIDE'))->toBeFalse();
});

test('controller can create and edit meter readings only for assigned clients', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $assignedRegion = Region::factory()->for($organization)->create();
    $assignedStreet = Street::factory()->for($assignedRegion)->create();
    $outsideRegion = Region::factory()->for($organization)->create();
    $outsideStreet = Street::factory()->for($outsideRegion)->create();

    $assignedClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($assignedRegion)
        ->for($assignedStreet)
        ->create(['billing_type' => 'meter']);
    $outsideClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($outsideRegion, 'region')
        ->for($outsideStreet)
        ->create(['billing_type' => 'meter']);

    $assignedMeter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($assignedClient)
        ->create(['initial_reading' => 10]);
    $outsideMeter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($outsideClient)
        ->create(['initial_reading' => 20]);
    $outsideReading = MeterReading::factory()
        ->for($outsideMeter)
        ->create([
            'period' => '202604',
            'previous_reading' => 20,
            'current_reading' => 25,
        ]);
    closedBillingPeriodFor($organization, '202604');

    $user = actingAsOrganizationAccessTenant($organization, OrganizationMemberRole::Controller);
    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'region_id' => $assignedRegion->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    billingPeriodFor($organization);

    Livewire::test(CreateMeterReading::class)
        ->fillForm([
            'meter_id' => $assignedMeter->id,
            'current_reading' => 15,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    expect(MeterReading::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($assignedMeter)
        ->forPeriod('202605')
        ->exists())->toBeTrue();

    Livewire::test(CreateMeterReading::class)
        ->fillForm([
            'meter_id' => $outsideMeter->id,
            'current_reading' => 30,
        ])
        ->call('create')
        ->assertHasFormErrors(['meter_id']);

    expect(MeterReading::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($outsideMeter)
        ->forPeriod('202605')
        ->exists())->toBeFalse();

    Livewire::test(EditMeterReading::class, [
        'record' => $outsideReading->getRouteKey(),
    ])->assertNotFound();
});

test('controller can view clients and meters but cannot manage them', function () {
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
            'name' => 'Абонент контроллера',
            'billing_type' => 'meter',
        ]);
    $meter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($client)
        ->create([
            'number' => 'MTR-CONTROLLER',
            'initial_reading' => 5,
        ]);

    $user = actingAsOrganizationAccessTenant($organization, OrganizationMemberRole::Controller);
    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'region_id' => $region->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(ListClients::class)
        ->assertOk()
        ->assertActionHidden('create')
        ->assertTableActionVisible('open', $client)
        ->assertTableActionHidden('edit', $client);

    Livewire::test(EditClient::class, [
        'record' => $client->getRouteKey(),
    ])
        ->assertOk()
        ->assertActionHidden('delete');

    Livewire::test(CreateClient::class)->assertForbidden();

    Livewire::test(ListMeters::class)
        ->assertOk()
        ->assertActionHidden('create')
        ->assertTableActionVisible('open', $meter)
        ->assertTableActionHidden('edit', $meter)
        ->assertTableActionHidden('archive', $meter);

    Livewire::test(EditMeter::class, [
        'record' => $meter->getRouteKey(),
    ])
        ->assertOk()
        ->assertActionHidden('delete');

    Livewire::test(CreateMeter::class)->assertForbidden();
});

test('controller can add and edit readings from meter context without meter operations', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create();
    $street = Street::factory()->for($region)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($region)
        ->for($street)
        ->create(['billing_type' => 'meter']);
    $meter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($client)
        ->create([
            'number' => 'MTR-READINGS',
            'initial_reading' => 10,
        ]);
    $reading = MeterReading::factory()
        ->for($meter)
        ->create([
            'period' => '202604',
            'previous_reading' => 10,
            'current_reading' => 15,
        ]);
    closedBillingPeriodFor($organization, '202604');

    billingPeriodFor($organization);

    $user = actingAsOrganizationAccessTenant($organization, OrganizationMemberRole::Controller);
    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'region_id' => $region->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->assertOk()
        ->assertTableActionVisible('open', $meter)
        ->assertTableActionVisible('addReading', $meter)
        ->assertTableActionHidden('edit', $meter)
        ->assertTableActionHidden('delete', $meter)
        ->assertTableActionHidden('archive', $meter)
        ->callTableAction('addReading', $meter, data: [
            'current_reading' => 20,
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    expect($meter->readings()->forPeriod('202605')->exists())->toBeTrue();

    $currentReading = $meter->readings()->forPeriod('202605')->sole();

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->assertOk()
        ->assertTableActionHidden('edit', $reading)
        ->assertTableActionVisible('edit', $currentReading)
        ->assertTableActionHidden('delete', $reading)
        ->callTableAction('edit', $currentReading, data: [
            'previous_reading' => 15,
            'current_reading' => 21,
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    expect($currentReading->refresh()->current_reading)->toBe('21.0000');
});

test('controller cannot access organization setup and non-reading operations', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create();
    $street = Street::factory()->for($region)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($region)
        ->for($street)
        ->create();

    $user = actingAsOrganizationAccessTenant($organization, OrganizationMemberRole::Controller);
    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'region_id' => $region->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(EditOrganizationProfile::class)->assertNotFound();
    Livewire::test(ListTariffs::class)->assertForbidden();
    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])->assertForbidden();
});
