<?php

use App\Filament\Pages\Reports\ListReports;
use App\Filament\Pages\Reports\ViewReport;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\Region;
use App\Models\Street;
use App\Models\User;
use App\Models\UtilityService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ->assertSee('Список не снятых показаний');

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
