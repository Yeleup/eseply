<?php

use App\ClientType;
use App\Filament\Pages\Reports\ListReports;
use App\Filament\Pages\Reports\ViewReport;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Receipt;
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

function actingAsReportsTenant(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

/**
 * @return list<list<mixed>>
 */
function downloadedXlsxRows(array $downloadEffect): array
{
    $path = tempnam(sys_get_temp_dir(), 'meter-reading-sheet-');

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

test('meter reading sheet report keeps client meters together and scopes records to tenant', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create(['name' => 'Алмалинский']);
    $street = Street::factory()->for($region)->create(['name' => 'Абая']);
    $firstClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '100001',
            'name' => 'Иванов Иван',
            'billing_type' => 'meter',
            'residents_count' => 3,
            'region_id' => $region->id,
            'street_id' => $street->id,
            'house' => '10',
            'apartment' => '5',
            'status' => 'active',
        ]);
    $secondClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '100002',
            'name' => 'Петров Петр',
            'billing_type' => 'meter',
            'status' => 'active',
        ]);

    $firstMeter = Meter::factory()
        ->for($organization)
        ->for($firstClient)
        ->for($utilityService)
        ->create([
            'number' => 'MTR-001',
            'installed_on' => '2024-01-15',
            'initial_reading' => 10,
            'status' => 'active',
        ]);
    $secondMeter = Meter::factory()
        ->for($organization)
        ->for($firstClient)
        ->for($utilityService)
        ->create([
            'number' => 'MTR-002',
            'installed_on' => null,
            'initial_reading' => 20,
            'status' => 'active',
        ]);
    $thirdMeter = Meter::factory()
        ->for($organization)
        ->for($secondClient)
        ->for($utilityService)
        ->create([
            'number' => 'MTR-003',
            'initial_reading' => 30,
            'status' => 'active',
        ]);
    $otherTenantMeter = Meter::factory()
        ->for(Organization::factory())
        ->create([
            'number' => 'MTR-OTHER',
            'status' => 'active',
        ]);

    MeterReading::factory()
        ->for($firstMeter)
        ->create([
            'period' => '202604',
            'previous_reading' => 10,
            'current_reading' => 15.5,
        ]);
    closedBillingPeriodFor($organization, '202604');

    MeterReading::factory()
        ->for($firstMeter)
        ->create([
            'period' => '202605',
            'previous_reading' => 15.5,
            'current_reading' => 21.75,
        ]);

    actingAsReportsTenant($organization);

    Livewire::test(ListReports::class)
        ->assertOk()
        ->assertSee('Ведомость снятия показаний')
        ->assertSee('Список не снятых показаний')
        ->assertSee('Процент снятия по контроллерам')
        ->assertSee('Новые лицевые счета')
        ->assertSee('Отчёт по оплатам')
        ->assertSee('Отчёт по неоплаченным')
        ->assertSee('Замена/установка счётчика')
        ->assertSee('Отчёт по долгам')
        ->assertSee('Отчёт по потреблениям');

    Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->assertCanSeeTableRecords([$firstMeter, $secondMeter, $thirdMeter], inOrder: true)
        ->assertCanNotSeeTableRecords([$otherTenantMeter])
        ->assertTableColumnStateSet('client.account_number', '100001', $firstMeter)
        ->assertTableColumnStateSet('client.name', 'Иванов Иван', $firstMeter)
        ->assertTableColumnStateSet('client_address', 'Алмалинский, Абая, д. 10, кв. 5', $firstMeter)
        ->assertTableColumnStateSet('client.residents_count', 3, $firstMeter)
        ->assertTableColumnStateSet('number', 'MTR-001', $firstMeter)
        ->assertTableColumnStateSet('previous_reading_for_report', '21.7500', $firstMeter)
        ->assertTableColumnStateSet('previous_reading_for_report', '20.0000', $secondMeter);

    $download = Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->assertActionExists('downloadExcel')
        ->assertActionHasLabel('downloadExcel', 'Скачать Excel')
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'meter-reading-sheet-'.$organization->getKey().'-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $rows = downloadedXlsxRows($download->effects['download']);

    expect($rows[0])->toBe([
        'Лицевой счёт',
        'ФИО',
        'Адрес',
        'Кол. проживающих',
        'Счётчик',
        'Дата установки',
        'Предыдущее показание',
        'Показание',
    ]);
    expect(array_slice($rows[1], 0, 7))->toEqual([
        '100001',
        'Иванов Иван',
        'Алмалинский, Абая, д. 10, кв. 5',
        3,
        'MTR-001',
        '15.01.2024',
        21.75,
    ]);
    expect(array_slice($rows[2], 0, 7))->toEqual([
        '100001',
        'Иванов Иван',
        'Алмалинский, Абая, д. 10, кв. 5',
        3,
        'MTR-002',
        '',
        20,
    ]);
    expect(array_slice($rows[3], 0, 5))->toEqual([
        '100002',
        'Петров Петр',
        '-',
        $secondClient->residents_count,
        'MTR-003',
    ]);
    expect(collect($rows)->flatten()->contains('MTR-OTHER'))->toBeFalse();
});

test('missing meter readings report lists active meter clients without current period readings', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create(['name' => 'Бостандыкский']);
    $street = Street::factory()->for($region)->create(['name' => 'Тимирязева']);

    $missingClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '200001',
            'name' => 'Сидоров Сидор',
            'billing_type' => 'meter',
            'residents_count' => 2,
            'region_id' => $region->id,
            'street_id' => $street->id,
            'house' => '25',
            'apartment' => '12',
            'status' => 'active',
        ]);
    $recordedClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '200002',
            'name' => 'Абонент с показанием',
            'billing_type' => 'meter',
            'status' => 'active',
        ]);
    $inactiveClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '200003',
            'name' => 'Неактивный абонент',
            'billing_type' => 'meter',
            'status' => 'inactive',
        ]);
    $perPersonClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '200004',
            'name' => 'Без счётчикового начисления',
            'billing_type' => 'per_person',
            'status' => 'active',
        ]);

    $missingMeter = Meter::factory()
        ->for($organization)
        ->for($missingClient)
        ->for($utilityService)
        ->create([
            'number' => 'MISS-001',
            'installed_on' => '2024-02-01',
            'initial_reading' => 5,
            'status' => 'active',
        ]);
    $initialOnlyMissingMeter = Meter::factory()
        ->for($organization)
        ->for($missingClient)
        ->for($utilityService)
        ->create([
            'number' => 'MISS-002',
            'installed_on' => null,
            'initial_reading' => 17.5,
            'status' => 'active',
        ]);
    $recordedMeter = Meter::factory()
        ->for($organization)
        ->for($recordedClient)
        ->for($utilityService)
        ->create([
            'number' => 'READ-001',
            'initial_reading' => 30,
            'status' => 'active',
        ]);
    $inactiveClientMeter = Meter::factory()
        ->for($organization)
        ->for($inactiveClient)
        ->for($utilityService)
        ->create(['number' => 'INACTIVE-001', 'status' => 'active']);
    $perPersonMeter = Meter::factory()
        ->for($organization)
        ->for($perPersonClient)
        ->for($utilityService)
        ->create(['number' => 'PER-001', 'status' => 'active']);
    $removedMeter = Meter::factory()
        ->for($organization)
        ->for($missingClient)
        ->for($utilityService)
        ->create(['number' => 'REMOVED-001', 'status' => 'removed']);
    $otherTenantMeter = Meter::factory()
        ->for(Organization::factory())
        ->create(['number' => 'OTHER-001', 'status' => 'active']);

    MeterReading::factory()
        ->for($missingMeter)
        ->create([
            'period' => '202605',
            'previous_reading' => 5,
            'current_reading' => 9.25,
        ]);
    closedBillingPeriodFor($organization, '202605');

    billingPeriodFor($organization, '202606');

    MeterReading::factory()
        ->for($recordedMeter)
        ->create([
            'period' => '202606',
            'previous_reading' => 30,
            'current_reading' => 35,
        ]);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'missing-meter-readings'])
        ->assertOk()
        ->assertCanSeeTableRecords([$missingMeter, $initialOnlyMissingMeter], inOrder: true)
        ->assertCanNotSeeTableRecords([
            $recordedMeter,
            $inactiveClientMeter,
            $perPersonMeter,
            $removedMeter,
            $otherTenantMeter,
        ])
        ->assertTableColumnStateSet('client.account_number', '200001', $missingMeter)
        ->assertTableColumnStateSet('client.name', 'Сидоров Сидор', $missingMeter)
        ->assertTableColumnStateSet('client_address', 'Бостандыкский, Тимирязева, д. 25, кв. 12', $missingMeter)
        ->assertTableColumnStateSet('client.residents_count', 2, $missingMeter)
        ->assertTableColumnStateSet('number', 'MISS-001', $missingMeter)
        ->assertTableColumnStateSet('missing_period', '06.2026', $missingMeter)
        ->assertTableColumnStateSet('previous_reading_for_report', '9.2500', $missingMeter)
        ->assertTableColumnStateSet('previous_reading_for_report', '17.5000', $initialOnlyMissingMeter);

    $download = Livewire::test(ViewReport::class, ['report' => 'missing-meter-readings'])
        ->assertOk()
        ->assertActionExists('downloadExcel')
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'missing-meter-readings-'.$organization->getKey().'-202606-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $rows = downloadedXlsxRows($download->effects['download']);

    expect($rows[0])->toBe([
        'Лицевой счёт',
        'ФИО',
        'Адрес',
        'Кол. проживающих',
        'Счётчик',
        'Дата установки',
        'Период',
        'Предыдущее показание',
    ]);
    expect($rows[1])->toEqual([
        '200001',
        'Сидоров Сидор',
        'Бостандыкский, Тимирязева, д. 25, кв. 12',
        2,
        'MISS-001',
        '01.02.2024',
        '06.2026',
        9.25,
    ]);
    expect($rows[2])->toEqual([
        '200001',
        'Сидоров Сидор',
        'Бостандыкский, Тимирязева, д. 25, кв. 12',
        2,
        'MISS-002',
        '',
        '06.2026',
        17.5,
    ]);
    expect(collect($rows)->flatten()->contains('READ-001'))->toBeFalse();
    expect(collect($rows)->flatten()->contains('OTHER-001'))->toBeFalse();
});

test('controller meter reading progress report calculates percentages for assigned zones', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $firstRegion = Region::factory()->for($organization)->create(['name' => 'Алмалинский']);
    $secondRegion = Region::factory()->for($organization)->create(['name' => 'Медеуский']);
    $firstStreet = Street::factory()->for($firstRegion)->create(['name' => 'Абая']);
    $secondStreet = Street::factory()->for($secondRegion)->create(['name' => 'Достык']);

    $firstController = User::factory()->create([
        'name' => 'Controller A',
        'email' => 'controller-a@example.test',
    ]);
    $secondController = User::factory()->create([
        'name' => 'Controller B',
        'email' => 'controller-b@example.test',
    ]);
    $controllerWithoutZone = User::factory()->create([
        'name' => 'Controller C',
        'email' => 'controller-c@example.test',
    ]);
    $operator = User::factory()->create(['name' => 'Operator']);
    $otherTenantController = User::factory()->create(['name' => 'Other Controller']);

    $organization->users()->attach($firstController, ['role' => OrganizationMemberRole::Controller->value]);
    $organization->users()->attach($secondController, ['role' => OrganizationMemberRole::Controller->value]);
    $organization->users()->attach($controllerWithoutZone, ['role' => OrganizationMemberRole::Controller->value]);
    $organization->users()->attach($operator, ['role' => OrganizationMemberRole::Operator->value]);
    Organization::factory()->create()->users()->attach($otherTenantController, [
        'role' => OrganizationMemberRole::Controller->value,
    ]);

    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $firstController->id,
        'region_id' => $firstRegion->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('organization_user_streets')->insert([
        'organization_id' => $organization->id,
        'user_id' => $secondController->id,
        'street_id' => $secondStreet->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $firstClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '300001',
            'billing_type' => 'meter',
            'region_id' => $firstRegion->id,
            'street_id' => $firstStreet->id,
            'status' => 'active',
        ]);
    $secondClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '300002',
            'billing_type' => 'meter',
            'region_id' => $secondRegion->id,
            'street_id' => $secondStreet->id,
            'status' => 'active',
        ]);
    $inactiveClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'meter',
            'region_id' => $firstRegion->id,
            'street_id' => $firstStreet->id,
            'status' => 'inactive',
        ]);
    $perPersonClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'per_person',
            'region_id' => $firstRegion->id,
            'street_id' => $firstStreet->id,
            'status' => 'active',
        ]);

    $firstReadMeter = Meter::factory()
        ->for($organization)
        ->for($firstClient)
        ->for($utilityService)
        ->create(['number' => 'CTRL-A-READ-1', 'status' => 'active']);
    $secondReadMeter = Meter::factory()
        ->for($organization)
        ->for($firstClient)
        ->for($utilityService)
        ->create(['number' => 'CTRL-A-READ-2', 'status' => 'active']);
    $missingMeter = Meter::factory()
        ->for($organization)
        ->for($firstClient)
        ->for($utilityService)
        ->create(['number' => 'CTRL-A-MISSING', 'status' => 'active']);
    $secondControllerMeter = Meter::factory()
        ->for($organization)
        ->for($secondClient)
        ->for($utilityService)
        ->create(['number' => 'CTRL-B-READ', 'status' => 'active']);
    $inactiveClientMeter = Meter::factory()
        ->for($organization)
        ->for($inactiveClient)
        ->for($utilityService)
        ->create(['number' => 'CTRL-INACTIVE', 'status' => 'active']);
    $perPersonMeter = Meter::factory()
        ->for($organization)
        ->for($perPersonClient)
        ->for($utilityService)
        ->create(['number' => 'CTRL-PER-PERSON', 'status' => 'active']);
    $removedMeter = Meter::factory()
        ->for($organization)
        ->for($firstClient)
        ->for($utilityService)
        ->create(['number' => 'CTRL-REMOVED', 'status' => 'removed']);

    MeterReading::factory()
        ->for($missingMeter)
        ->create(['period' => '202605']);
    closedBillingPeriodFor($organization, '202605');

    billingPeriodFor($organization, '202606');

    MeterReading::factory()
        ->for($firstReadMeter)
        ->create(['period' => '202606']);
    MeterReading::factory()
        ->for($secondReadMeter)
        ->create(['period' => '202606']);
    MeterReading::factory()
        ->for($secondControllerMeter)
        ->create(['period' => '202606']);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'controller-meter-reading-progress'])
        ->assertOk()
        ->assertCanSeeTableRecords([$firstController, $secondController, $controllerWithoutZone], inOrder: true)
        ->assertCanNotSeeTableRecords([$operator, $otherTenantController])
        ->assertTableColumnStateSet('name', 'Controller A', $firstController)
        ->assertTableColumnStateSet('email', 'controller-a@example.test', $firstController)
        ->assertTableColumnStateSet('assigned_regions_for_report', 'Алмалинский', $firstController)
        ->assertTableColumnStateSet('assigned_streets_for_report', '-', $firstController)
        ->assertTableColumnStateSet('billing_period_for_report', '06.2026', $firstController)
        ->assertTableColumnStateSet('total_meters_for_report', 3, $firstController)
        ->assertTableColumnStateSet('read_meters_for_report', 2, $firstController)
        ->assertTableColumnStateSet('missing_meters_for_report', 1, $firstController)
        ->assertTableColumnStateSet('reading_completion_percent_for_report', '66.67%', $firstController)
        ->assertTableColumnStateSet('assigned_regions_for_report', '-', $secondController)
        ->assertTableColumnStateSet('assigned_streets_for_report', 'Медеуский / Достык', $secondController)
        ->assertTableColumnStateSet('total_meters_for_report', 1, $secondController)
        ->assertTableColumnStateSet('read_meters_for_report', 1, $secondController)
        ->assertTableColumnStateSet('missing_meters_for_report', 0, $secondController)
        ->assertTableColumnStateSet('reading_completion_percent_for_report', '100.00%', $secondController)
        ->assertTableColumnStateSet('total_meters_for_report', 0, $controllerWithoutZone)
        ->assertTableColumnStateSet('read_meters_for_report', 0, $controllerWithoutZone)
        ->assertTableColumnStateSet('missing_meters_for_report', 0, $controllerWithoutZone)
        ->assertTableColumnStateSet('reading_completion_percent_for_report', '0.00%', $controllerWithoutZone);

    $download = Livewire::test(ViewReport::class, ['report' => 'controller-meter-reading-progress'])
        ->assertOk()
        ->assertActionExists('downloadExcel')
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'controller-meter-reading-progress-'.$organization->getKey().'-202606-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $rows = downloadedXlsxRows($download->effects['download']);

    expect($rows[0])->toBe([
        'Контроллер',
        'Email',
        'Регионы',
        'Улицы',
        'Период',
        'Всего счётчиков',
        'Снято',
        'Не снято',
        'Процент снятия',
    ]);
    expect($rows[1])->toEqual([
        'Controller A',
        'controller-a@example.test',
        'Алмалинский',
        '-',
        '06.2026',
        3,
        2,
        1,
        66.67,
    ]);
    expect($rows[2])->toEqual([
        'Controller B',
        'controller-b@example.test',
        '-',
        'Медеуский / Достык',
        '06.2026',
        1,
        1,
        0,
        100.0,
    ]);
    expect($rows[3])->toEqual([
        'Controller C',
        'controller-c@example.test',
        '-',
        '-',
        '06.2026',
        0,
        0,
        0,
        0.0,
    ]);
    expect(collect($rows)->flatten()->contains('Operator'))->toBeFalse();
    expect(collect($rows)->flatten()->contains('Other Controller'))->toBeFalse();
    expect(collect($rows)->flatten()->contains('CTRL-INACTIVE'))->toBeFalse();
    expect(collect($rows)->flatten()->contains('CTRL-PER-PERSON'))->toBeFalse();
    expect(collect($rows)->flatten()->contains('CTRL-REMOVED'))->toBeFalse();
});

test('new client accounts report lists clients created in current billing period', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create(['name' => 'Наурызбайский']);
    $street = Street::factory()->for($region)->create(['name' => 'Жандосова']);

    billingPeriodFor($organization, '202606');

    $firstClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '400001',
            'name' => 'Новый абонент',
            'client_type' => 'individual',
            'billing_type' => 'per_person',
            'residents_count' => 4,
            'phone' => '+7 701 000 00 01',
            'region_id' => $region->id,
            'street_id' => $street->id,
            'house' => '7',
            'apartment' => '21',
            'status' => 'active',
            'created_at' => '2026-06-05 09:15:00',
            'updated_at' => '2026-06-05 09:15:00',
        ]);
    $secondClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '400002',
            'name' => 'Закрытый новый счёт',
            'client_type' => 'commercial',
            'billing_type' => 'fixed',
            'residents_count' => 1,
            'phone' => null,
            'status' => 'inactive',
            'created_at' => '2026-06-16 18:30:00',
            'updated_at' => '2026-06-16 18:30:00',
        ]);
    $previousPeriodClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '399999',
            'name' => 'Майский абонент',
            'created_at' => '2026-05-31 23:59:59',
            'updated_at' => '2026-05-31 23:59:59',
        ]);
    $nextPeriodClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '400003',
            'name' => 'Июльский абонент',
            'created_at' => '2026-07-01 00:00:00',
            'updated_at' => '2026-07-01 00:00:00',
        ]);
    $otherOrganization = Organization::factory()->create();
    $otherUtilityService = UtilityService::factory()->for($otherOrganization)->create();
    $otherTenantClient = Client::factory()
        ->for($otherOrganization)
        ->for($otherUtilityService)
        ->create([
            'account_number' => '400004',
            'name' => 'Чужой абонент',
            'created_at' => '2026-06-10 10:00:00',
            'updated_at' => '2026-06-10 10:00:00',
        ]);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'new-client-accounts'])
        ->assertOk()
        ->assertCanSeeTableRecords([$firstClient, $secondClient], inOrder: true)
        ->assertCanNotSeeTableRecords([$previousPeriodClient, $nextPeriodClient, $otherTenantClient])
        ->assertTableColumnStateSet('account_number', '400001', $firstClient)
        ->assertTableColumnStateSet('name', 'Новый абонент', $firstClient)
        ->assertTableColumnStateSet('client_address', 'Наурызбайский, Жандосова, д. 7, кв. 21', $firstClient)
        ->assertTableColumnStateSet('client_type', ClientType::Individual, $firstClient)
        ->assertTableColumnStateSet('billing_type', 'per_person', $firstClient)
        ->assertTableColumnStateSet('status', 'active', $firstClient)
        ->assertTableColumnStateSet('residents_count', 4, $firstClient)
        ->assertTableColumnStateSet('current_billing_period_for_report', '06.2026', $firstClient)
        ->assertTableColumnStateSet('billing_type', 'fixed', $secondClient)
        ->assertTableColumnStateSet('status', 'inactive', $secondClient);

    $download = Livewire::test(ViewReport::class, ['report' => 'new-client-accounts'])
        ->assertOk()
        ->assertActionExists('downloadExcel')
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'new-client-accounts-'.$organization->getKey().'-202606-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $rows = downloadedXlsxRows($download->effects['download']);

    expect($rows[0])->toBe([
        'Лицевой счёт',
        'ФИО / Наименование',
        'Адрес',
        'Тип',
        'Тип начисления',
        'Статус',
        'Кол. проживающих',
        'Телефон',
        'Период',
        'Создан',
    ]);
    expect($rows[1])->toEqual([
        '400001',
        'Новый абонент',
        'Наурызбайский, Жандосова, д. 7, кв. 21',
        'Физ. лицо',
        'На одного человека',
        'Активный',
        4,
        '+7 701 000 00 01',
        '06.2026',
        '05.06.2026 09:15',
    ]);
    expect($rows[2])->toEqual([
        '400002',
        'Закрытый новый счёт',
        '-',
        'Коммерческие объекты',
        'Фиксированная сумма',
        'Неактивный',
        1,
        '',
        '06.2026',
        '16.06.2026 18:30',
    ]);
    expect(collect($rows)->flatten()->contains('Майский абонент'))->toBeFalse();
    expect(collect($rows)->flatten()->contains('Июльский абонент'))->toBeFalse();
    expect(collect($rows)->flatten()->contains('Чужой абонент'))->toBeFalse();
});

test('payments report lists payments for current billing period', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create(['name' => 'Алатауский']);
    $street = Street::factory()->for($region)->create(['name' => 'Момышулы']);

    billingPeriodFor($organization, '202606');

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '500001',
            'name' => 'Плательщик',
            'region_id' => $region->id,
            'street_id' => $street->id,
            'house' => '8',
            'apartment' => '14',
        ]);
    $payment = Payment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
            'amount' => 3500,
            'paid_at' => '2026-06-09',
            'note' => 'Kaspi',
        ]);

    $otherOrganization = Organization::factory()->create();
    $otherUtilityService = UtilityService::factory()->for($otherOrganization)->create();
    $otherClient = Client::factory()
        ->for($otherOrganization)
        ->for($otherUtilityService)
        ->create(['account_number' => '500002', 'name' => 'Чужой плательщик']);
    $otherPayment = Payment::factory()
        ->for($otherOrganization)
        ->for($otherClient)
        ->create(['period' => '202606', 'amount' => 9000]);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'payments'])
        ->assertOk()
        ->assertCanSeeTableRecords([$payment])
        ->assertCanNotSeeTableRecords([$otherPayment])
        ->assertTableColumnStateSet('client.account_number', '500001', $payment)
        ->assertTableColumnStateSet('client.name', 'Плательщик', $payment)
        ->assertTableColumnStateSet('client_address', 'Алатауский, Момышулы, д. 8, кв. 14', $payment)
        ->assertTableColumnStateSet('payment_period_for_report', '06.2026', $payment);

    $download = Livewire::test(ViewReport::class, ['report' => 'payments'])
        ->assertOk()
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'payments-'.$organization->getKey().'-202606-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $rows = downloadedXlsxRows($download->effects['download']);

    expect($rows[0])->toBe([
        'Лицевой счёт',
        'Абонент',
        'Адрес',
        'Период',
        'Дата оплаты',
        'Сумма',
        'Примечание',
    ]);
    expect($rows[1])->toEqual([
        '500001',
        'Плательщик',
        'Алатауский, Момышулы, д. 8, кв. 14',
        '06.2026',
        '09.06.2026',
        3500.0,
        'Kaspi',
    ]);
    expect(collect($rows)->flatten()->contains('Чужой плательщик'))->toBeFalse();
});

test('unpaid receipts and debts reports use receipt payment and balance totals', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create(['name' => 'Есильский']);
    $street = Street::factory()->for($region)->create(['name' => 'Кабанбай батыр']);

    billingPeriodFor($organization, '202606');

    $unpaidClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '510001',
            'name' => 'Неоплаченный абонент',
            'region_id' => $region->id,
            'street_id' => $street->id,
            'house' => '11',
            'apartment' => '2',
        ]);
    $debtOnlyClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create(['account_number' => '510002', 'name' => 'Абонент с долгом']);
    $settledClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create(['account_number' => '510003', 'name' => 'Оплаченный абонент']);

    $unpaidReceipt = Receipt::factory()
        ->for($organization)
        ->for($unpaidClient)
        ->create([
            'period' => '202606',
            'receipt_number' => '202606-510001',
            'account_number' => '510001',
            'client_name' => 'Неоплаченный абонент',
            'billing_type' => 'fixed',
            'amount' => 6000,
            'paid_amount' => 2000,
            'adjustment_amount' => 0,
            'opening_balance' => 0,
            'closing_balance' => 4000,
            'issued_at' => '2026-06-20 10:00:00',
        ]);
    $debtOnlyReceipt = Receipt::factory()
        ->for($organization)
        ->for($debtOnlyClient)
        ->create([
            'period' => '202606',
            'receipt_number' => '202606-510002',
            'account_number' => '510002',
            'client_name' => 'Абонент с долгом',
            'billing_type' => 'fixed',
            'amount' => 1000,
            'paid_amount' => 1000,
            'adjustment_amount' => 0,
            'opening_balance' => 2500,
            'closing_balance' => 2500,
            'issued_at' => '2026-06-20 11:00:00',
        ]);
    $settledReceipt = Receipt::factory()
        ->for($organization)
        ->for($settledClient)
        ->create([
            'period' => '202606',
            'receipt_number' => '202606-510003',
            'account_number' => '510003',
            'client_name' => 'Оплаченный абонент',
            'billing_type' => 'fixed',
            'amount' => 2000,
            'paid_amount' => 2000,
            'adjustment_amount' => 0,
            'opening_balance' => 0,
            'closing_balance' => 0,
            'issued_at' => '2026-06-20 12:00:00',
        ]);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'unpaid-receipts'])
        ->assertOk()
        ->assertCanSeeTableRecords([$unpaidReceipt])
        ->assertCanNotSeeTableRecords([$debtOnlyReceipt, $settledReceipt])
        ->assertTableColumnStateSet('account_number', '510001', $unpaidReceipt)
        ->assertTableColumnStateSet('client_name', 'Неоплаченный абонент', $unpaidReceipt)
        ->assertTableColumnStateSet('client_address', 'Есильский, Кабанбай батыр, д. 11, кв. 2', $unpaidReceipt)
        ->assertTableColumnStateSet('receipt_period_for_report', '06.2026', $unpaidReceipt)
        ->assertTableColumnStateSet('unpaid_amount_for_report', 4000.0, $unpaidReceipt);

    $unpaidDownload = Livewire::test(ViewReport::class, ['report' => 'unpaid-receipts'])
        ->assertOk()
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'unpaid-receipts-'.$organization->getKey().'-202606-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $unpaidRows = downloadedXlsxRows($unpaidDownload->effects['download']);

    expect($unpaidRows[0])->toBe([
        'Лицевой счёт',
        'Абонент',
        'Адрес',
        'Период',
        'Начислено',
        'Оплачено',
        'Не оплачено',
        'Квитанция сформирована',
    ]);
    expect($unpaidRows[1])->toEqual([
        '510001',
        'Неоплаченный абонент',
        'Есильский, Кабанбай батыр, д. 11, кв. 2',
        '06.2026',
        6000.0,
        2000.0,
        4000.0,
        '20.06.2026 10:00',
    ]);
    expect(collect($unpaidRows)->flatten()->contains('Абонент с долгом'))->toBeFalse();

    Livewire::test(ViewReport::class, ['report' => 'debts'])
        ->assertOk()
        ->assertCanSeeTableRecords([$unpaidReceipt, $debtOnlyReceipt], inOrder: true)
        ->assertCanNotSeeTableRecords([$settledReceipt])
        ->assertTableColumnStateSet('debt_period_for_report', '06.2026', $unpaidReceipt)
        ->assertTableColumnStateSet('closing_balance', '4000.00', $unpaidReceipt)
        ->assertTableColumnStateSet('closing_balance', '2500.00', $debtOnlyReceipt);

    $debtsDownload = Livewire::test(ViewReport::class, ['report' => 'debts'])
        ->assertOk()
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'debts-'.$organization->getKey().'-202606-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $debtRows = downloadedXlsxRows($debtsDownload->effects['download']);

    expect($debtRows[0])->toBe([
        'Лицевой счёт',
        'Абонент',
        'Адрес',
        'Период',
        'Начальное сальдо',
        'Начислено',
        'Оплачено',
        'Корректировка',
        'Долг',
    ]);
    expect($debtRows[1])->toEqual([
        '510001',
        'Неоплаченный абонент',
        'Есильский, Кабанбай батыр, д. 11, кв. 2',
        '06.2026',
        0.0,
        6000.0,
        2000.0,
        0.0,
        4000.0,
    ]);
    expect($debtRows[2])->toEqual([
        '510002',
        'Абонент с долгом',
        '-',
        '06.2026',
        2500.0,
        1000.0,
        1000.0,
        0.0,
        2500.0,
    ]);
    expect(collect($debtRows)->flatten()->contains('Оплаченный абонент'))->toBeFalse();
});

test('meter installation replacement report lists installed and removed meters in current billing period', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create(['name' => 'Медеуский']);
    $street = Street::factory()->for($region)->create(['name' => 'Достык']);

    billingPeriodFor($organization, '202606');

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '520001',
            'name' => 'Абонент со счётчиком',
            'billing_type' => 'meter',
            'region_id' => $region->id,
            'street_id' => $street->id,
            'house' => '19',
        ]);
    $installedMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'number' => 'MTR-INSTALL',
            'installed_on' => '2026-06-03',
            'initial_reading' => 12.5,
            'note' => 'Новый счётчик',
        ]);
    $removedMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'number' => 'MTR-REMOVED',
            'installed_on' => '2025-01-10',
            'initial_reading' => 30,
            'note' => 'Замена',
        ]);
    $removedMeter->forceFill([
        'removed_on' => '2026-06-12',
        'status' => 'removed',
    ])->save();
    $oldMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['number' => 'MTR-OLD', 'installed_on' => '2026-05-31']);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'meter-installation-replacement'])
        ->assertOk()
        ->assertCanSeeTableRecords([$installedMeter, $removedMeter], inOrder: true)
        ->assertCanNotSeeTableRecords([$oldMeter])
        ->assertTableColumnStateSet('client.account_number', '520001', $installedMeter)
        ->assertTableColumnStateSet('client.name', 'Абонент со счётчиком', $installedMeter)
        ->assertTableColumnStateSet('client_address', 'Медеуский, Достык, д. 19', $installedMeter)
        ->assertTableColumnStateSet('meter_operation_for_report', 'Установка', $installedMeter)
        ->assertTableColumnStateSet('meter_operation_for_report', 'Замена / снятие', $removedMeter)
        ->assertTableColumnStateSet('meter_status_for_report', 'Снят', $removedMeter);

    $download = Livewire::test(ViewReport::class, ['report' => 'meter-installation-replacement'])
        ->assertOk()
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'meter-installation-replacement-'.$organization->getKey().'-202606-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $rows = downloadedXlsxRows($download->effects['download']);

    expect($rows[0])->toBe([
        'Лицевой счёт',
        'Абонент',
        'Адрес',
        'Счётчик',
        'Операция',
        'Установлен',
        'Снят',
        'Начальное показание',
        'Статус',
        'Примечание',
    ]);
    expect($rows[1])->toEqual([
        '520001',
        'Абонент со счётчиком',
        'Медеуский, Достык, д. 19',
        'MTR-INSTALL',
        'Установка',
        '03.06.2026',
        '',
        12.5,
        'Активный',
        'Новый счётчик',
    ]);
    expect($rows[2])->toEqual([
        '520001',
        'Абонент со счётчиком',
        'Медеуский, Достык, д. 19',
        'MTR-REMOVED',
        'Замена / снятие',
        '10.01.2025',
        '12.06.2026',
        30.0,
        'Снят',
        'Замена',
    ]);
    expect(collect($rows)->flatten()->contains('MTR-OLD'))->toBeFalse();
});

test('consumption report lists meter readings for current billing period', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create(['name' => 'Бостандыкский']);
    $street = Street::factory()->for($region)->create(['name' => 'Тимирязева']);

    billingPeriodFor($organization, '202606');

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '530001',
            'name' => 'Потребитель',
            'billing_type' => 'meter',
            'region_id' => $region->id,
            'street_id' => $street->id,
            'house' => '40',
            'apartment' => '7',
        ]);
    $meter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['number' => 'MTR-CONSUME', 'initial_reading' => 10]);
    $meterReading = MeterReading::factory()
        ->for($meter)
        ->create([
            'period' => '202606',
            'previous_reading' => 10,
            'current_reading' => 25.75,
            'read_at' => '2026-06-08',
        ]);

    $otherOrganization = Organization::factory()->create();
    $otherUtilityService = UtilityService::factory()->for($otherOrganization)->create();
    $otherClient = Client::factory()
        ->for($otherOrganization)
        ->for($otherUtilityService)
        ->create(['billing_type' => 'meter']);
    $otherMeter = Meter::factory()
        ->for($otherOrganization)
        ->for($otherClient)
        ->for($otherUtilityService)
        ->create(['number' => 'MTR-OTHER-CONSUME']);
    $otherReading = MeterReading::factory()
        ->for($otherMeter)
        ->create(['period' => '202606']);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'consumption'])
        ->assertOk()
        ->assertCanSeeTableRecords([$meterReading])
        ->assertCanNotSeeTableRecords([$otherReading])
        ->assertTableColumnStateSet('client.account_number', '530001', $meterReading)
        ->assertTableColumnStateSet('client.name', 'Потребитель', $meterReading)
        ->assertTableColumnStateSet('client_address', 'Бостандыкский, Тимирязева, д. 40, кв. 7', $meterReading)
        ->assertTableColumnStateSet('meter.number', 'MTR-CONSUME', $meterReading)
        ->assertTableColumnStateSet('consumption_period_for_report', '06.2026', $meterReading)
        ->assertTableColumnStateSet('consumption', '15.7500', $meterReading);

    $download = Livewire::test(ViewReport::class, ['report' => 'consumption'])
        ->assertOk()
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'consumption-'.$organization->getKey().'-202606-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $rows = downloadedXlsxRows($download->effects['download']);

    expect($rows[0])->toBe([
        'Лицевой счёт',
        'Абонент',
        'Адрес',
        'Счётчик',
        'Период',
        'Предыдущее',
        'Текущее',
        'Потребление',
        'Дата снятия',
    ]);
    expect($rows[1])->toEqual([
        '530001',
        'Потребитель',
        'Бостандыкский, Тимирязева, д. 40, кв. 7',
        'MTR-CONSUME',
        '06.2026',
        10.0,
        25.75,
        15.75,
        '08.06.2026',
    ]);
    expect(collect($rows)->flatten()->contains('MTR-OTHER-CONSUME'))->toBeFalse();
});
