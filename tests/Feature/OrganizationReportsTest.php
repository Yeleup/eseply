<?php

use App\BalanceAdjustmentType;
use App\BillingPeriodStatus;
use App\ClientType;
use App\Dashboard\DashboardMetrics;
use App\Filament\Pages\Reports\ListReports;
use App\Filament\Pages\Reports\ViewReport;
use App\Filament\Support\ControllerZoneFilter;
use App\Models\Accrual;
use App\Models\BalanceAdjustment;
use App\Models\BillingPeriod;
use App\Models\City;
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
use App\PaymentMethod;
use App\Reports\ReportSummaryGroup;
use App\Reports\ReportSummaryService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
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

function actingAsReportsController(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, [
        'role' => OrganizationMemberRole::Controller->value,
    ]);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

function meterReadingSheetMeterFor(
    Organization $organization,
    UtilityService $utilityService,
    string $accountNumber,
    ?int $regionId,
    ?int $streetId,
): Meter {
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => $accountNumber,
            'billing_type' => 'meter',
            'status' => 'active',
            'region_id' => $regionId,
            'street_id' => $streetId,
        ]);

    return Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'number' => 'MTR-'.$accountNumber,
            'status' => 'active',
        ]);
}

/**
 * Two cities, three regions and four streets of one organization, one active meter on each street.
 *
 * @return array{
 *     organization: Organization,
 *     city: City,
 *     otherCity: City,
 *     almalinsky: Region,
 *     bostandyk: Region,
 *     esil: Region,
 *     abay: Street,
 *     gogol: Street,
 *     satpaev: Street,
 *     kabanbay: Street,
 *     abayMeter: Meter,
 *     gogolMeter: Meter,
 *     satpaevMeter: Meter,
 *     esilMeter: Meter,
 * }
 */
function meterReadingSheetAddressFixture(): array
{
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    $city = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $otherCity = City::factory()->for($organization)->create(['name' => 'Астана']);

    $almalinsky = Region::factory()->for($organization)->for($city)->create(['name' => 'Алмалинский']);
    $bostandyk = Region::factory()->for($organization)->for($city)->create(['name' => 'Бостандыкский']);
    $esil = Region::factory()->for($organization)->for($otherCity)->create(['name' => 'Есильский']);

    $abay = Street::factory()->for($almalinsky)->create(['name' => 'Абая']);
    $gogol = Street::factory()->for($almalinsky)->create(['name' => 'Гоголя']);
    $satpaev = Street::factory()->for($bostandyk)->create(['name' => 'Сатпаева']);
    $kabanbay = Street::factory()->for($esil)->create(['name' => 'Кабанбай батыра']);

    return [
        'organization' => $organization,
        'city' => $city,
        'otherCity' => $otherCity,
        'almalinsky' => $almalinsky,
        'bostandyk' => $bostandyk,
        'esil' => $esil,
        'abay' => $abay,
        'gogol' => $gogol,
        'satpaev' => $satpaev,
        'kabanbay' => $kabanbay,
        'abayMeter' => meterReadingSheetMeterFor($organization, $utilityService, '710001', $almalinsky->id, $abay->id),
        'gogolMeter' => meterReadingSheetMeterFor($organization, $utilityService, '710002', $almalinsky->id, $gogol->id),
        'satpaevMeter' => meterReadingSheetMeterFor($organization, $utilityService, '710003', $bostandyk->id, $satpaev->id),
        'esilMeter' => meterReadingSheetMeterFor($organization, $utilityService, '710004', $esil->id, $kabanbay->id),
    ];
}

/**
 * @return array<int|string, mixed>
 */
function meterReadingSheetAddressFilterOptions(Testable $page, string $field): array
{
    $fields = $page->instance()
        ->getTableFiltersForm()
        ->getComponentByStatePath('address')
        ->getChildSchema()
        ->getFlatFields();

    return $fields[$field]->getOptions();
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
            'current_reading' => 15,
        ]);
    closedBillingPeriodFor($organization, '202604');

    MeterReading::factory()
        ->for($firstMeter)
        ->create([
            'period' => '202605',
            'previous_reading' => 15,
            'current_reading' => 21,
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
        ->assertTableColumnStateSet('previous_reading_for_report', 15, $firstMeter)
        ->assertTableColumnStateSet('current_reading_for_report', 21, $firstMeter)
        ->assertTableColumnStateSet('previous_reading_for_report', 20, $secondMeter)
        ->assertTableColumnStateSet('current_reading_for_report', null, $secondMeter);

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
        'Текущее показание',
    ]);
    expect($rows[1])->toEqual([
        '100001',
        'Иванов Иван',
        'Алмалинский, Абая, д. 10, кв. 5',
        3,
        'MTR-001',
        '15.01.2024',
        15,
        21,
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

/**
 * @return array{organization: Organization, utilityService: UtilityService, client: Client}
 */
function meterReadingSheetReadingsFixture(): array
{
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '730001',
            'billing_type' => 'meter',
            'status' => 'active',
        ]);

    return compact('organization', 'utilityService', 'client');
}

function meterReadingSheetReadingsMeter(array $fixture, string $number, int $initialReading): Meter
{
    return Meter::factory()
        ->for($fixture['organization'])
        ->for($fixture['client'])
        ->for($fixture['utilityService'])
        ->create([
            'number' => $number,
            'initial_reading' => $initialReading,
            'status' => 'active',
        ]);
}

function closeMeterReadingSheetMonth(BillingPeriod $billingPeriod): void
{
    $billingPeriod->forceFill([
        'status' => BillingPeriodStatus::Closed,
        'closed_at' => now(),
    ])->save();
}

function meterReadingSheetReading(Meter $meter, BillingPeriod $billingPeriod, ?int $currentReading, int $previousReading = 0): MeterReading
{
    return $meter->readings()->create([
        'billing_period_id' => $billingPeriod->getKey(),
        'previous_reading' => $previousReading,
        'current_reading' => $currentReading,
    ]);
}

test('meter reading sheet shows the reading at the start of the current month and the current month reading', function () {
    $fixture = meterReadingSheetReadingsFixture();
    $organization = $fixture['organization'];

    $taken = meterReadingSheetReadingsMeter($fixture, 'MTR-1', 5);
    $notTaken = meterReadingSheetReadingsMeter($fixture, 'MTR-2', 7);
    $visited = meterReadingSheetReadingsMeter($fixture, 'MTR-3', 9);
    $withoutReadings = meterReadingSheetReadingsMeter($fixture, 'MTR-4', 40);
    $visitedOnly = meterReadingSheetReadingsMeter($fixture, 'MTR-5', 11);

    $march = billingPeriodFor($organization, '202603');
    meterReadingSheetReading($taken, $march, 50, 5);
    meterReadingSheetReading($notTaken, $march, 70, 7);
    meterReadingSheetReading($visited, $march, 90, 9);
    closeMeterReadingSheetMonth($march);

    $april = billingPeriodFor($organization, '202604');
    meterReadingSheetReading($taken, $april, 60, 50);
    meterReadingSheetReading($notTaken, $april, 80, 70);
    // A visit mark of an earlier month is not a previous reading.
    meterReadingSheetReading($visited, $april, null, 90);
    meterReadingSheetReading($visitedOnly, $april, null, 11);
    closeMeterReadingSheetMonth($april);

    $may = billingPeriodFor($organization, '202605');
    meterReadingSheetReading($taken, $may, 75, 60);
    // A visit mark of the current month is not a current reading.
    meterReadingSheetReading($visited, $may, null, 90);
    meterReadingSheetReading($visitedOnly, $may, null, 11);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->assertTableColumnExists('previous_reading_for_report', fn ($column): bool => $column->getLabel() === 'Предыдущее показание')
        ->assertTableColumnExists('current_reading_for_report', fn ($column): bool => $column->getLabel() === 'Текущее показание')
        ->assertTableColumnDoesNotExist('reading_entry')
        ->assertCanSeeTableRecords([$taken, $notTaken, $visited, $withoutReadings, $visitedOnly], inOrder: true)
        ->assertTableColumnStateSet('previous_reading_for_report', 60, $taken)
        ->assertTableColumnStateSet('current_reading_for_report', 75, $taken)
        ->assertTableColumnStateSet('previous_reading_for_report', 80, $notTaken)
        ->assertTableColumnStateSet('current_reading_for_report', null, $notTaken)
        ->assertTableColumnStateSet('previous_reading_for_report', 90, $visited)
        ->assertTableColumnStateSet('current_reading_for_report', null, $visited)
        ->assertTableColumnStateSet('previous_reading_for_report', 40, $withoutReadings)
        ->assertTableColumnStateSet('current_reading_for_report', null, $withoutReadings)
        ->assertTableColumnStateSet('previous_reading_for_report', 11, $visitedOnly)
        ->assertTableColumnStateSet('current_reading_for_report', null, $visitedOnly);

    $download = Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'meter-reading-sheet-'.$organization->getKey().'-'.today()->format('Y-m-d').'.xlsx',
        );

    $rows = downloadedXlsxRows($download->effects['download']);

    expect(array_slice($rows[0], 4))->toBe([
        'Счётчик',
        'Дата установки',
        'Предыдущее показание',
        'Текущее показание',
    ]);
    expect(collect(array_slice($rows, 1))
        ->map(fn (array $row): array => [$row[4], $row[6], $row[7] ?? ''])
        ->all())->toEqual([
            ['MTR-1', 60, 75],
            ['MTR-2', 80, ''],
            ['MTR-3', 90, ''],
            ['MTR-4', 40, ''],
            ['MTR-5', 11, ''],
        ]);
});

test('meter reading sheet treats a failed billing month as the current one', function () {
    $fixture = meterReadingSheetReadingsFixture();
    $organization = $fixture['organization'];
    $meter = meterReadingSheetReadingsMeter($fixture, 'MTR-1', 5);

    $april = billingPeriodFor($organization, '202604');
    meterReadingSheetReading($meter, $april, 60, 5);
    closeMeterReadingSheetMonth($april);
    $may = billingPeriodFor($organization, '202605');
    meterReadingSheetReading($meter, $may, 75, 60);
    $may->forceFill(['status' => BillingPeriodStatus::Failed])->save();

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->assertTableColumnStateSet('previous_reading_for_report', 60, $meter)
        ->assertTableColumnStateSet('current_reading_for_report', 75, $meter);
});

test('meter reading sheet without an editable billing month shows the latest reading as previous and no current reading', function () {
    $fixture = meterReadingSheetReadingsFixture();
    $organization = $fixture['organization'];
    $taken = meterReadingSheetReadingsMeter($fixture, 'MTR-1', 5);
    $withoutReadings = meterReadingSheetReadingsMeter($fixture, 'MTR-2', 40);

    $april = billingPeriodFor($organization, '202604');
    meterReadingSheetReading($taken, $april, 60, 5);
    closeMeterReadingSheetMonth($april);
    $may = billingPeriodFor($organization, '202605');
    meterReadingSheetReading($taken, $may, 75, 60);
    closeMeterReadingSheetMonth($may);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->assertTableColumnStateSet('previous_reading_for_report', 75, $taken)
        ->assertTableColumnStateSet('current_reading_for_report', null, $taken)
        ->assertTableColumnStateSet('previous_reading_for_report', 40, $withoutReadings)
        ->assertTableColumnStateSet('current_reading_for_report', null, $withoutReadings);

    $download = Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->callAction('downloadExcel');

    $rows = downloadedXlsxRows($download->effects['download']);

    expect(collect(array_slice($rows, 1))
        ->map(fn (array $row): array => [$row[4], $row[6], $row[7] ?? ''])
        ->all())->toEqual([
            ['MTR-1', 75, ''],
            ['MTR-2', 40, ''],
        ]);
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
            'initial_reading' => 17,
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
            'current_reading' => 9,
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
        ->assertTableColumnStateSet('previous_reading_for_report', 9, $missingMeter)
        ->assertTableColumnStateSet('previous_reading_for_report', 17, $initialOnlyMissingMeter);

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
        9,
    ]);
    expect($rows[2])->toEqual([
        '200001',
        'Сидоров Сидор',
        'Бостандыкский, Тимирязева, д. 25, кв. 12',
        2,
        'MISS-002',
        '',
        '06.2026',
        17,
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
            'method' => PaymentMethod::Kaspi,
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
        'Способ',
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
        'Kaspi',
    ]);
    expect(collect($rows)->flatten()->contains('Чужой плательщик'))->toBeFalse();
});

test('summary reports include subscriber rows under every matching controller', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create(['name' => 'Алмалинский']);
    $street = Street::factory()->for($region)->create(['name' => 'Абая']);
    $unrelatedRegion = Region::factory()->for($organization)->create(['name' => 'Медеуский']);

    $billingPeriod = billingPeriodFor($organization, '202606');

    $controllerByRegion = User::factory()->create(['name' => 'Controller By Region']);
    $controllerByStreet = User::factory()->create(['name' => 'Controller By Street']);
    $controllerByBoth = User::factory()->create(['name' => 'Controller By Both']);
    $unrelatedController = User::factory()->create(['name' => 'Unrelated Controller']);

    $organization->users()->attach($controllerByRegion, ['role' => OrganizationMemberRole::Controller->value]);
    $organization->users()->attach($controllerByStreet, ['role' => OrganizationMemberRole::Controller->value]);
    $organization->users()->attach($controllerByBoth, ['role' => OrganizationMemberRole::Controller->value]);
    $organization->users()->attach($unrelatedController, ['role' => OrganizationMemberRole::Controller->value]);

    DB::table('organization_user_regions')->insert([
        [
            'organization_id' => $organization->id,
            'user_id' => $controllerByRegion->id,
            'region_id' => $region->id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'organization_id' => $organization->id,
            'user_id' => $controllerByBoth->id,
            'region_id' => $region->id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'organization_id' => $organization->id,
            'user_id' => $unrelatedController->id,
            'region_id' => $unrelatedRegion->id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    DB::table('organization_user_streets')->insert([
        [
            'organization_id' => $organization->id,
            'user_id' => $controllerByStreet->id,
            'street_id' => $street->id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'organization_id' => $organization->id,
            'user_id' => $controllerByBoth->id,
            'street_id' => $street->id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '500010',
            'name' => 'Сводный плательщик',
            'region_id' => $region->id,
            'street_id' => $street->id,
        ]);
    Payment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
            'amount' => 3500,
            'paid_at' => '2026-06-09',
        ]);

    $operator = actingAsReportsTenant($organization);
    $summaryService = app(ReportSummaryService::class);

    $controllerRecords = collect($summaryService->records(
        'payments',
        ReportSummaryGroup::Controller,
        $organization,
        $operator,
        $billingPeriod,
    ))->keyBy('group_label');

    expect($controllerRecords->keys()->all())->toContain(
        'Controller By Both',
        'Controller By Region',
        'Controller By Street',
    );
    expect($controllerRecords->keys()->all())->not->toContain('Unrelated Controller');

    foreach (['Controller By Both', 'Controller By Region', 'Controller By Street'] as $controllerName) {
        expect($controllerRecords->get($controllerName))->toMatchArray([
            'clients_count' => 1,
            'payments_count' => 1,
            'payment_amount' => 3500.0,
        ]);
    }

    $regionRecords = collect($summaryService->records(
        'payments',
        ReportSummaryGroup::Region,
        $organization,
        $operator,
        $billingPeriod,
    ))->keyBy('group_label');
    $streetRecords = collect($summaryService->records(
        'payments',
        ReportSummaryGroup::Street,
        $organization,
        $operator,
        $billingPeriod,
    ))->keyBy('group_label');

    expect($regionRecords->get('Алмалинский'))->toMatchArray([
        'clients_count' => 1,
        'payment_amount' => 3500.0,
    ]);
    expect($streetRecords->get('Алмалинский / Абая'))->toMatchArray([
        'clients_count' => 1,
        'payment_amount' => 3500.0,
    ]);

    Livewire::test(ViewReport::class, [
        'report' => 'payments',
        'mode' => 'summary',
        'group' => ReportSummaryGroup::Controller->value,
    ])
        ->assertOk()
        ->assertActionExists('detailMode')
        ->assertActionExists('summaryByController')
        ->assertActionExists('summaryByRegion')
        ->assertActionExists('summaryByStreet')
        ->assertSee('Controller By Both')
        ->assertSee('Controller By Region')
        ->assertSee('Controller By Street')
        ->assertDontSee('Unrelated Controller');

    $download = Livewire::test(ViewReport::class, [
        'report' => 'payments',
        'mode' => 'summary',
        'group' => ReportSummaryGroup::Controller->value,
    ])
        ->assertOk()
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'summary-payments-controller-'.$organization->getKey().'-202606-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $rows = downloadedXlsxRows($download->effects['download']);

    expect($rows[0])->toBe([
        'Контроллер',
        'Абонентов',
        'Оплат',
        'Сумма оплат',
    ]);

    $rowsByController = collect(array_slice($rows, 1))->keyBy(fn (array $row): mixed => $row[0]);

    foreach (['Controller By Both', 'Controller By Region', 'Controller By Street'] as $controllerName) {
        expect($rowsByController->get($controllerName))->toEqual([
            $controllerName,
            1,
            1,
            3500.0,
        ]);
    }
    expect($rowsByController->has('Unrelated Controller'))->toBeFalse();
});

test('unpaid receipts report uses receipt payment totals', function () {
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
});

test('debts report shows the empty state without an editable billing period', function () {
    $fixture = turnoverBalanceSheetFixture();
    $organization = $fixture['organization'];

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'debts'])
        ->assertOk()
        ->assertCanNotSeeTableRecords([$fixture['debtor'], $fixture['overpaid']])
        ->assertSee('Расчётный месяц не открыт')
        ->assertSee('Откройте расчётный месяц, чтобы увидеть долги.');

    $download = Livewire::test(ViewReport::class, ['report' => 'debts'])
        ->assertOk()
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'debts-'.$organization->getKey().'-no-open-period-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    expect(downloadedXlsxRows($download->effects['download']))->toHaveCount(1);
});

test('debts report takes every active client with a debt through the turnover engine', function () {
    $fixture = turnoverBalanceSheetFixture();
    $organization = $fixture['organization'];
    $openPeriod = billingPeriodFor($organization, '202606');

    $carried = Client::factory()
        ->for($organization)
        ->for($fixture['utilityService'])
        ->create([
            'account_number' => '800003',
            'name' => 'Без квитанции',
            'region_id' => $fixture['esil']->id,
            'street_id' => $fixture['kabanbay']->id,
            'status' => 'active',
        ]);
    $inactive = Client::factory()
        ->for($organization)
        ->for($fixture['utilityService'])
        ->create(['account_number' => '800004', 'name' => 'Неактивный', 'status' => 'inactive']);
    $settled = Client::factory()
        ->for($organization)
        ->for($fixture['utilityService'])
        ->create(['account_number' => '800005', 'name' => 'Рассчитался', 'region_id' => $fixture['esil']->id]);

    foreach ([[$carried, 1200], [$inactive, 900]] as [$client, $closingBalance]) {
        Accrual::factory()
            ->for($organization)
            ->for($client)
            ->create([
                'period' => '202605',
                'account_number' => $client->account_number,
                'client_name' => $client->name,
                'billing_type' => 'fixed',
                'opening_balance' => 0,
                'amount' => $closingBalance,
                'paid_amount' => 0,
                'adjustment_amount' => 0,
                'closing_balance' => $closingBalance,
            ]);
    }

    foreach ([[$fixture['debtor'], 3000, 500], [$settled, 2000, 2000]] as [$client, $amount, $paid]) {
        Receipt::factory()
            ->for($organization)
            ->for($client)
            ->create([
                'period' => '202606',
                'receipt_number' => '202606-'.$client->account_number,
                'account_number' => $client->account_number,
                'client_name' => $client->name,
                'billing_type' => 'fixed',
                'amount' => $amount,
                'paid_amount' => $paid,
                'adjustment_amount' => 0,
                'opening_balance' => 0,
                'closing_balance' => $amount - $paid,
            ]);
        Payment::factory()
            ->for($organization)
            ->for($client)
            ->create(['period' => '202606', 'amount' => $paid, 'paid_at' => '2026-06-09']);
    }

    $foreignOrganization = Organization::factory()->create();
    closedBillingPeriodFor($foreignOrganization, '202605');
    $foreignClient = Client::factory()->for($foreignOrganization)->create(['account_number' => '900001']);
    Accrual::factory()
        ->for($foreignOrganization)
        ->for($foreignClient)
        ->create(['period' => '202605', 'closing_balance' => 7777]);

    $operator = actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'debts'])
        ->assertOk()
        ->assertCanSeeTableRecords([$fixture['debtor'], $carried], inOrder: true)
        ->assertCanNotSeeTableRecords([$fixture['overpaid'], $inactive, $settled, $foreignClient])
        ->assertTableColumnStateSet('debt_period_for_report', '06.2026', $carried)
        ->assertTableColumnStateSet('opening_balance', '4500.00', $fixture['debtor'])
        ->assertTableColumnStateSet('accrual_amount', '3000.00', $fixture['debtor'])
        ->assertTableColumnStateSet('paid_amount', '500.00', $fixture['debtor'])
        ->assertTableColumnStateSet('debt_amount', '7000.00', $fixture['debtor'])
        ->assertTableColumnStateSet('opening_balance', '1200.00', $carried)
        ->assertTableColumnStateSet('accrual_amount', '0.00', $carried)
        ->assertTableColumnStateSet('debt_amount', '1200.00', $carried);

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
    ])
        ->and($debtRows[1])->toEqual(['800001', 'Должник', 'Алмалинский, Абая, д. 10, кв. 1', '06.2026', 4500.0, 3000.0, 500.0, 0.0, 7000.0])
        ->and($debtRows[2][0])->toBe('800003')
        ->and(array_slice($debtRows[2], 3))->toEqual(['06.2026', 1200.0, 0.0, 0.0, 0.0, 1200.0])
        ->and($debtRows)->toHaveCount(3);

    $summary = app(ReportSummaryService::class)->records('debts', ReportSummaryGroup::Region, $organization, $operator, $openPeriod);

    expect($summary['total']['clients_count'])->toBe(2)
        ->and($summary['total']['debt_amount'])->toBe(8200.0)
        ->and($summary['total']['opening_balance'])->toBe(5700.0)
        ->and($summary['total']['paid_amount'])->toBe(500.0);

    $dashboard = app(DashboardMetrics::class)->finance($organization, $openPeriod);

    expect($dashboard['debt'])->toBe(8200.0)
        ->and($dashboard['debtors_count'])->toBe(2);
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
            'initial_reading' => 12,
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
        12,
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
            'current_reading' => 25,
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
        ->assertTableColumnStateSet('consumption', 15, $meterReading);

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
        10,
        25,
        15,
        '08.06.2026',
    ]);
    expect(collect($rows)->flatten()->contains('MTR-OTHER-CONSUME'))->toBeFalse();
});

test('payments report filters payments by paid_at date range', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    billingPeriodFor($organization, '202606');

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create(['account_number' => '600001', 'name' => 'Плательщик по датам']);
    $earlyPayment = Payment::factory()
        ->for($organization)
        ->for($client)
        ->create(['period' => '202606', 'paid_at' => '2026-06-05']);
    $middlePayment = Payment::factory()
        ->for($organization)
        ->for($client)
        ->create(['period' => '202606', 'paid_at' => '2026-06-10']);
    $latePayment = Payment::factory()
        ->for($organization)
        ->for($client)
        ->create(['period' => '202606', 'paid_at' => '2026-06-20']);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'payments'])
        ->assertOk()
        ->assertCanSeeTableRecords([$earlyPayment, $middlePayment, $latePayment])
        ->filterTable('paid_at', ['start_date' => '2026-06-08', 'end_date' => null])
        ->assertCanSeeTableRecords([$middlePayment, $latePayment])
        ->assertCanNotSeeTableRecords([$earlyPayment])
        ->filterTable('paid_at', ['start_date' => '2026-06-08', 'end_date' => '2026-06-15'])
        ->assertCanSeeTableRecords([$middlePayment])
        ->assertCanNotSeeTableRecords([$earlyPayment, $latePayment])
        ->filterTable('paid_at', ['start_date' => null, 'end_date' => '2026-06-05'])
        ->assertCanSeeTableRecords([$earlyPayment])
        ->assertCanNotSeeTableRecords([$middlePayment, $latePayment])
        ->filterTable('paid_at', ['start_date' => '2026-06-05', 'end_date' => null])
        ->assertCanSeeTableRecords([$earlyPayment, $middlePayment, $latePayment])
        ->filterTable('paid_at', ['start_date' => '2026-06-08', 'end_date' => '2026-06-15'])
        ->assertCanSeeTableRecords([$middlePayment])
        ->removeTableFilter('paid_at', 'start_date')
        ->assertCanSeeTableRecords([$earlyPayment, $middlePayment])
        ->assertCanNotSeeTableRecords([$latePayment])
        ->removeTableFilter('paid_at')
        ->assertCanSeeTableRecords([$earlyPayment, $middlePayment, $latePayment]);
});

test('report date filters ignore invalid filter state', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    billingPeriodFor($organization, '202606');

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create(['account_number' => '670001', 'name' => 'Плательщик с помехами']);
    $payment = Payment::factory()
        ->for($organization)
        ->for($client)
        ->create(['period' => '202606', 'paid_at' => '2026-06-10']);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'payments'])
        ->assertOk()
        ->set('tableFilters.paid_at.start_date', 'garbage')
        ->assertOk()
        ->assertCanSeeTableRecords([$payment])
        ->set('tableFilters.paid_at.start_date', ['nested' => 'array'])
        ->assertOk()
        ->assertCanSeeTableRecords([$payment])
        ->set('tableFilters.paid_at.end_date', 'not-a-date')
        ->assertOk()
        ->assertCanSeeTableRecords([$payment]);
});

test('consumption report filters meter readings by read_at date range', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    billingPeriodFor($organization, '202606');

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create(['account_number' => '610001', 'billing_type' => 'meter']);
    $earlyMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['number' => 'MTR-READ-EARLY']);
    $lateMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['number' => 'MTR-READ-LATE']);
    $earlyReading = MeterReading::factory()
        ->for($earlyMeter)
        ->create(['period' => '202606', 'read_at' => '2026-06-05']);
    $lateReading = MeterReading::factory()
        ->for($lateMeter)
        ->create(['period' => '202606', 'read_at' => '2026-06-20']);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'consumption'])
        ->assertOk()
        ->assertCanSeeTableRecords([$earlyReading, $lateReading])
        ->filterTable('read_at', ['start_date' => '2026-06-10', 'end_date' => null])
        ->assertCanSeeTableRecords([$lateReading])
        ->assertCanNotSeeTableRecords([$earlyReading])
        ->filterTable('read_at', ['start_date' => null, 'end_date' => '2026-06-10'])
        ->assertCanSeeTableRecords([$earlyReading])
        ->assertCanNotSeeTableRecords([$lateReading]);
});

test('meter installation replacement report filters by installed_on and removed_on date ranges', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    billingPeriodFor($organization, '202606');

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create(['account_number' => '620001', 'billing_type' => 'meter']);
    $earlyInstalledMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['number' => 'MTR-INSTALL-EARLY', 'installed_on' => '2026-06-03']);
    $lateInstalledMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['number' => 'MTR-INSTALL-LATE', 'installed_on' => '2026-06-20']);
    $removedMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['number' => 'MTR-REPLACED', 'installed_on' => '2025-02-01']);
    $removedMeter->forceFill(['removed_on' => '2026-06-12', 'status' => 'removed'])->save();
    $oldInstalledRemovedMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['number' => 'MTR-REPLACED-OLD', 'installed_on' => '2024-12-01']);
    $oldInstalledRemovedMeter->forceFill(['removed_on' => '2026-06-25', 'status' => 'removed'])->save();

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'meter-installation-replacement'])
        ->assertOk()
        ->assertCanSeeTableRecords([$earlyInstalledMeter, $lateInstalledMeter, $removedMeter, $oldInstalledRemovedMeter])
        ->filterTable('installed_on', ['start_date' => '2026-06-10', 'end_date' => null])
        ->assertCanSeeTableRecords([$lateInstalledMeter])
        ->assertCanNotSeeTableRecords([$earlyInstalledMeter, $removedMeter, $oldInstalledRemovedMeter])
        ->removeTableFilter('installed_on')
        ->filterTable('removed_on', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'])
        ->assertCanSeeTableRecords([$removedMeter, $oldInstalledRemovedMeter])
        ->assertCanNotSeeTableRecords([$earlyInstalledMeter, $lateInstalledMeter])
        ->filterTable('installed_on', ['start_date' => '2025-01-01', 'end_date' => '2026-06-10'])
        ->assertCanSeeTableRecords([$removedMeter])
        ->assertCanNotSeeTableRecords([$earlyInstalledMeter, $lateInstalledMeter, $oldInstalledRemovedMeter]);
});

test('meter reading sheet report filters meters by installed_on date range', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '630001',
            'billing_type' => 'meter',
            'status' => 'active',
        ]);
    $winterMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['number' => 'MTR-SHEET-WINTER', 'installed_on' => '2026-01-10', 'status' => 'active']);
    $springMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['number' => 'MTR-SHEET-SPRING', 'installed_on' => '2026-03-15', 'status' => 'active']);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->assertCanSeeTableRecords([$winterMeter, $springMeter])
        ->filterTable('installed_on', ['start_date' => '2026-02-01', 'end_date' => null])
        ->assertCanSeeTableRecords([$springMeter])
        ->assertCanNotSeeTableRecords([$winterMeter])
        ->filterTable('installed_on', ['start_date' => null, 'end_date' => '2026-02-01'])
        ->assertCanSeeTableRecords([$winterMeter])
        ->assertCanNotSeeTableRecords([$springMeter]);
});

test('meter reading sheet report filters meters by city, region and streets', function () {
    $sheet = meterReadingSheetAddressFixture();

    actingAsReportsTenant($sheet['organization']);

    Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->assertCanSeeTableRecords([$sheet['abayMeter'], $sheet['gogolMeter'], $sheet['satpaevMeter'], $sheet['esilMeter']])
        ->filterTable('address', ['city_id' => $sheet['city']->id])
        ->assertCanSeeTableRecords([$sheet['abayMeter'], $sheet['gogolMeter'], $sheet['satpaevMeter']])
        ->assertCanNotSeeTableRecords([$sheet['esilMeter']])
        ->filterTable('address', ['city_id' => $sheet['city']->id, 'region_id' => $sheet['almalinsky']->id])
        ->assertCanSeeTableRecords([$sheet['abayMeter'], $sheet['gogolMeter']])
        ->assertCanNotSeeTableRecords([$sheet['satpaevMeter'], $sheet['esilMeter']])
        ->filterTable('address', [
            'city_id' => $sheet['city']->id,
            'region_id' => $sheet['almalinsky']->id,
            'street_ids' => [$sheet['abay']->id],
        ])
        ->assertCanSeeTableRecords([$sheet['abayMeter']])
        ->assertCanNotSeeTableRecords([$sheet['gogolMeter'], $sheet['satpaevMeter'], $sheet['esilMeter']])
        ->filterTable('address', ['street_ids' => [$sheet['abay']->id, $sheet['satpaev']->id]])
        ->assertCanSeeTableRecords([$sheet['abayMeter'], $sheet['satpaevMeter']])
        ->assertCanNotSeeTableRecords([$sheet['gogolMeter'], $sheet['esilMeter']]);
});

test('meter reading sheet report ignores tampered address filter identifiers', function () {
    $sheet = meterReadingSheetAddressFixture();

    actingAsReportsTenant($sheet['organization']);

    Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->filterTable('address', ['city_id' => 'not-a-number', 'region_id' => '0', 'street_ids' => ['', null]])
        ->assertCanSeeTableRecords([$sheet['abayMeter'], $sheet['gogolMeter'], $sheet['satpaevMeter'], $sheet['esilMeter']]);
});

test('meter reading sheet report narrows region and street filter options to the selected city and region', function () {
    $sheet = meterReadingSheetAddressFixture();

    actingAsReportsTenant($sheet['organization']);

    $page = Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])->assertOk();

    expect(array_keys(meterReadingSheetAddressFilterOptions($page, 'region_id')))
        ->toEqualCanonicalizing([$sheet['almalinsky']->id, $sheet['bostandyk']->id, $sheet['esil']->id]);

    $page->set('tableDeferredFilters.address.city_id', $sheet['city']->id);

    expect(array_keys(meterReadingSheetAddressFilterOptions($page, 'region_id')))
        ->toEqualCanonicalizing([$sheet['almalinsky']->id, $sheet['bostandyk']->id]);
    expect(array_keys(meterReadingSheetAddressFilterOptions($page, 'street_ids')))
        ->toEqualCanonicalizing([$sheet['abay']->id, $sheet['gogol']->id, $sheet['satpaev']->id]);

    $page->set('tableDeferredFilters.address.region_id', $sheet['almalinsky']->id);

    expect(array_keys(meterReadingSheetAddressFilterOptions($page, 'street_ids')))
        ->toEqualCanonicalizing([$sheet['abay']->id, $sheet['gogol']->id]);
});

test('meter reading sheet address filter clears dependent fields when the city or region changes', function () {
    $sheet = meterReadingSheetAddressFixture();

    actingAsReportsTenant($sheet['organization']);

    Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->set('tableDeferredFilters.address.city_id', $sheet['city']->id)
        ->set('tableDeferredFilters.address.region_id', $sheet['almalinsky']->id)
        ->set('tableDeferredFilters.address.street_ids', [$sheet['abay']->id])
        ->set('tableDeferredFilters.address.region_id', $sheet['bostandyk']->id)
        ->assertSet('tableDeferredFilters.address.street_ids', [])
        ->set('tableDeferredFilters.address.street_ids', [$sheet['satpaev']->id])
        ->set('tableDeferredFilters.address.city_id', $sheet['otherCity']->id)
        ->assertSet('tableDeferredFilters.address.region_id', null)
        ->assertSet('tableDeferredFilters.address.street_ids', []);
});

test('meter reading sheet report filters meters by controller zones', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    $city = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $assignedRegion = Region::factory()->for($organization)->for($city)->create(['name' => 'Алмалинский']);
    $unassignedRegion = Region::factory()->for($organization)->for($city)->create(['name' => 'Бостандыкский']);
    $assignedStreet = Street::factory()->for($unassignedRegion)->create(['name' => 'Сатпаева']);

    $regionController = User::factory()->create([
        'name' => 'Контроллер района',
        'email' => 'region-zone@example.test',
    ]);
    $streetController = User::factory()->create([
        'name' => 'Контроллер улицы',
        'email' => 'street-zone@example.test',
    ]);
    $otherTenantController = User::factory()->create(['name' => 'Чужой контроллер']);

    $organization->users()->attach($regionController, ['role' => OrganizationMemberRole::Controller->value]);
    $organization->users()->attach($streetController, ['role' => OrganizationMemberRole::Controller->value]);
    Organization::factory()->create()->users()->attach($otherTenantController, [
        'role' => OrganizationMemberRole::Controller->value,
    ]);

    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $regionController->id,
        'region_id' => $assignedRegion->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('organization_user_streets')->insert([
        'organization_id' => $organization->id,
        'user_id' => $streetController->id,
        'street_id' => $assignedStreet->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $regionMeter = meterReadingSheetMeterFor($organization, $utilityService, '720001', $assignedRegion->id, null);
    $streetMeter = meterReadingSheetMeterFor($organization, $utilityService, '720002', $unassignedRegion->id, $assignedStreet->id);
    $outsideMeter = meterReadingSheetMeterFor($organization, $utilityService, '720003', $unassignedRegion->id, null);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->assertCanSeeTableRecords([$regionMeter, $streetMeter, $outsideMeter])
        ->filterTable('controller_ids', [$regionController->id])
        ->assertCanSeeTableRecords([$regionMeter])
        ->assertCanNotSeeTableRecords([$streetMeter, $outsideMeter])
        ->filterTable('controller_ids', [$streetController->id])
        ->assertCanSeeTableRecords([$streetMeter])
        ->assertCanNotSeeTableRecords([$regionMeter, $outsideMeter])
        ->filterTable('controller_ids', [$regionController->id, $streetController->id])
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$regionMeter, $streetMeter])
        ->assertCanNotSeeTableRecords([$outsideMeter])
        ->filterTable('controller_ids', [$otherTenantController->id])
        ->assertCountTableRecords(0)
        ->assertCanNotSeeTableRecords([$regionMeter, $streetMeter, $outsideMeter]);
});

test('meter reading sheet report keeps a client in overlapping controller zones on a single row', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    $city = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $region = Region::factory()->for($organization)->for($city)->create(['name' => 'Алмалинский']);
    $street = Street::factory()->for($region)->create(['name' => 'Абая']);

    $regionController = User::factory()->create(['name' => 'Контроллер района']);
    $streetController = User::factory()->create(['name' => 'Контроллер улицы']);

    $organization->users()->attach($regionController, ['role' => OrganizationMemberRole::Controller->value]);
    $organization->users()->attach($streetController, ['role' => OrganizationMemberRole::Controller->value]);

    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $regionController->id,
        'region_id' => $region->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('organization_user_streets')->insert([
        'organization_id' => $organization->id,
        'user_id' => $streetController->id,
        'street_id' => $street->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sharedMeter = meterReadingSheetMeterFor($organization, $utilityService, '730001', $region->id, $street->id);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->filterTable('controller_ids', [$regionController->id, $streetController->id])
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$sharedMeter]);
});

test('meter reading sheet report controller filter cannot widen a controller own zone', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    $city = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $ownRegion = Region::factory()->for($organization)->for($city)->create(['name' => 'Алмалинский']);
    $foreignRegion = Region::factory()->for($organization)->for($city)->create(['name' => 'Бостандыкский']);

    $foreignController = User::factory()->create(['name' => 'Соседний контроллер']);
    $organization->users()->attach($foreignController, ['role' => OrganizationMemberRole::Controller->value]);

    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $foreignController->id,
        'region_id' => $foreignRegion->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ownMeter = meterReadingSheetMeterFor($organization, $utilityService, '740001', $ownRegion->id, null);
    $foreignMeter = meterReadingSheetMeterFor($organization, $utilityService, '740002', $foreignRegion->id, null);

    $controller = actingAsReportsController($organization);

    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $controller->id,
        'region_id' => $ownRegion->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->assertCanSeeTableRecords([$ownMeter])
        ->assertCanNotSeeTableRecords([$foreignMeter])
        ->filterTable('controller_ids', [$foreignController->id])
        ->assertCountTableRecords(0)
        ->assertCanNotSeeTableRecords([$ownMeter, $foreignMeter]);
});

test('meter reading sheet excel download repeats the applied filters', function () {
    $sheet = meterReadingSheetAddressFixture();

    actingAsReportsTenant($sheet['organization']);

    $unfiltered = Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'meter-reading-sheet-'.$sheet['organization']->getKey().'-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    expect(downloadedXlsxRows($unfiltered->effects['download']))->toHaveCount(5);

    $filtered = Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->filterTable('address', [
            'city_id' => $sheet['city']->id,
            'region_id' => $sheet['almalinsky']->id,
            'street_ids' => [$sheet['abay']->id],
        ])
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'meter-reading-sheet-'.$sheet['organization']->getKey().'-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $rows = downloadedXlsxRows($filtered->effects['download']);

    expect($rows)->toHaveCount(2);
    expect($rows[1][0])->toBe('710001');
    expect($rows[1][4])->toBe('MTR-710001');
});

test('meter reading sheet excel download ignores filter values that were not applied', function () {
    $sheet = meterReadingSheetAddressFixture();

    actingAsReportsTenant($sheet['organization']);

    $download = Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->set('tableDeferredFilters.address.street_ids', [$sheet['abay']->id])
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'meter-reading-sheet-'.$sheet['organization']->getKey().'-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    expect(downloadedXlsxRows($download->effects['download']))->toHaveCount(5);
});

test('meter reading sheet excel download repeats the applied controller filter', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    $city = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $assignedRegion = Region::factory()->for($organization)->for($city)->create(['name' => 'Алмалинский']);
    $unassignedRegion = Region::factory()->for($organization)->for($city)->create(['name' => 'Бостандыкский']);

    $controller = User::factory()->create(['name' => 'Контроллер района']);
    $organization->users()->attach($controller, ['role' => OrganizationMemberRole::Controller->value]);

    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $controller->id,
        'region_id' => $assignedRegion->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    meterReadingSheetMeterFor($organization, $utilityService, '750001', $assignedRegion->id, null);
    meterReadingSheetMeterFor($organization, $utilityService, '750002', $unassignedRegion->id, null);

    actingAsReportsTenant($organization);

    $download = Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->filterTable('controller_ids', [$controller->id])
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'meter-reading-sheet-'.$organization->getKey().'-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $rows = downloadedXlsxRows($download->effects['download']);

    expect($rows)->toHaveCount(2);
    expect($rows[1][0])->toBe('750001');
});

test('meter reading sheet excel download repeats the applied installed_on filter', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '760001',
            'billing_type' => 'meter',
            'status' => 'active',
        ]);

    Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['number' => 'MTR-EXCEL-WINTER', 'installed_on' => '2026-01-10', 'status' => 'active']);
    Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['number' => 'MTR-EXCEL-SPRING', 'installed_on' => '2026-03-15', 'status' => 'active']);

    actingAsReportsTenant($organization);

    $download = Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->filterTable('installed_on', ['start_date' => '2026-02-01', 'end_date' => null])
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'meter-reading-sheet-'.$organization->getKey().'-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $rows = downloadedXlsxRows($download->effects['download']);

    expect($rows)->toHaveCount(2);
    expect($rows[1][4])->toBe('MTR-EXCEL-SPRING');
});

test('missing meter readings report filters meters by installed_on date range', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    billingPeriodFor($organization, '202606');

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '640001',
            'billing_type' => 'meter',
            'status' => 'active',
        ]);
    $winterMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['number' => 'MTR-MISS-WINTER', 'installed_on' => '2026-01-10', 'status' => 'active']);
    $springMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['number' => 'MTR-MISS-SPRING', 'installed_on' => '2026-03-15', 'status' => 'active']);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'missing-meter-readings'])
        ->assertOk()
        ->assertCanSeeTableRecords([$winterMeter, $springMeter])
        ->filterTable('installed_on', ['start_date' => '2026-02-01', 'end_date' => null])
        ->assertCanSeeTableRecords([$springMeter])
        ->assertCanNotSeeTableRecords([$winterMeter]);
});

test('new client accounts report filters clients by created_at date range', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    billingPeriodFor($organization, '202606');

    $earlyClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '650001',
            'created_at' => '2026-06-05 09:15:00',
            'updated_at' => '2026-06-05 09:15:00',
        ]);
    $middleClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '650002',
            'created_at' => '2026-06-16 18:30:00',
            'updated_at' => '2026-06-16 18:30:00',
        ]);
    $lateClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '650003',
            'created_at' => '2026-06-25 08:00:00',
            'updated_at' => '2026-06-25 08:00:00',
        ]);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'new-client-accounts'])
        ->assertOk()
        ->assertCanSeeTableRecords([$earlyClient, $middleClient, $lateClient])
        ->filterTable('created_at', ['start_date' => '2026-06-10', 'end_date' => null])
        ->assertCanSeeTableRecords([$middleClient, $lateClient])
        ->assertCanNotSeeTableRecords([$earlyClient])
        ->filterTable('created_at', ['start_date' => '2026-06-10', 'end_date' => '2026-06-16'])
        ->assertCanSeeTableRecords([$middleClient])
        ->assertCanNotSeeTableRecords([$earlyClient, $lateClient]);
});

test('unpaid receipts report filters receipts by issued_at date range', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    billingPeriodFor($organization, '202606');

    $earlyClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create(['account_number' => '660001', 'name' => 'Ранний должник']);
    $lateClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create(['account_number' => '660002', 'name' => 'Поздний должник']);
    $earlyReceipt = Receipt::factory()
        ->for($organization)
        ->for($earlyClient)
        ->create([
            'period' => '202606',
            'receipt_number' => '202606-660001',
            'account_number' => '660001',
            'client_name' => 'Ранний должник',
            'amount' => 5000,
            'paid_amount' => 1000,
            'issued_at' => '2026-06-05 09:00:00',
        ]);
    $lateReceipt = Receipt::factory()
        ->for($organization)
        ->for($lateClient)
        ->create([
            'period' => '202606',
            'receipt_number' => '202606-660002',
            'account_number' => '660002',
            'client_name' => 'Поздний должник',
            'amount' => 7000,
            'paid_amount' => 2000,
            'issued_at' => '2026-06-20 23:30:00',
        ]);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'unpaid-receipts'])
        ->assertOk()
        ->assertCanSeeTableRecords([$earlyReceipt, $lateReceipt])
        ->filterTable('issued_at', ['start_date' => '2026-06-10', 'end_date' => null])
        ->assertCanSeeTableRecords([$lateReceipt])
        ->assertCanNotSeeTableRecords([$earlyReceipt])
        ->filterTable('issued_at', ['start_date' => '2026-06-10', 'end_date' => '2026-06-20'])
        ->assertCanSeeTableRecords([$lateReceipt])
        ->assertCanNotSeeTableRecords([$earlyReceipt]);
});

/**
 * One organization with two cities, an accrued closed month and an open month.
 *
 * @return array{
 *     organization: Organization,
 *     utilityService: UtilityService,
 *     city: City,
 *     otherCity: City,
 *     almalinsky: Region,
 *     esil: Region,
 *     abay: Street,
 *     kabanbay: Street,
 *     closedPeriod: BillingPeriod,
 *     debtor: Client,
 *     overpaid: Client,
 * }
 */
function turnoverBalanceSheetFixture(): array
{
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'unit_of_measurement' => 'м3',
    ]);

    $city = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $otherCity = City::factory()->for($organization)->create(['name' => 'Астана']);

    $almalinsky = Region::factory()->for($organization)->for($city)->create(['name' => 'Алмалинский']);
    $esil = Region::factory()->for($organization)->for($otherCity)->create(['name' => 'Есильский']);

    $abay = Street::factory()->for($almalinsky)->create(['name' => 'Абая']);
    $kabanbay = Street::factory()->for($esil)->create(['name' => 'Кабанбай батыра']);

    $closedPeriod = closedBillingPeriodFor($organization, '202605');

    $debtor = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '800001',
            'name' => 'Должник',
            'region_id' => $almalinsky->id,
            'street_id' => $abay->id,
            'house' => '10',
            'apartment' => '1',
        ]);
    $overpaid = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '800002',
            'name' => 'Переплатил',
            'region_id' => $esil->id,
            'street_id' => $kabanbay->id,
            'house' => '20',
            'apartment' => '2',
        ]);

    Accrual::factory()
        ->for($organization)
        ->for($debtor)
        ->create([
            'period' => '202605',
            'account_number' => '800001',
            'client_name' => 'Должник',
            'billing_type' => 'meter',
            'volume' => 20,
            'opening_balance' => 1000,
            'amount' => 5000,
            'paid_amount' => 2000,
            'adjustment_amount' => 500,
            'closing_balance' => 4500,
        ]);
    Accrual::factory()
        ->for($organization)
        ->for($overpaid)
        ->create([
            'period' => '202605',
            'account_number' => '800002',
            'client_name' => 'Переплатил',
            'billing_type' => 'fixed',
            'volume' => null,
            'opening_balance' => -300,
            'amount' => 1000,
            'paid_amount' => 2500,
            'adjustment_amount' => -200,
            'closing_balance' => -2000,
        ]);

    return [
        'organization' => $organization,
        'utilityService' => $utilityService,
        'city' => $city,
        'otherCity' => $otherCity,
        'almalinsky' => $almalinsky,
        'esil' => $esil,
        'abay' => $abay,
        'kabanbay' => $kabanbay,
        'closedPeriod' => $closedPeriod,
        'debtor' => $debtor,
        'overpaid' => $overpaid,
    ];
}

test('turnover balance sheet splits a closed billing period into debit and credit', function () {
    $fixture = turnoverBalanceSheetFixture();
    $organization = $fixture['organization'];

    $withoutAccrual = Client::factory()
        ->for($organization)
        ->for($fixture['utilityService'])
        ->create(['account_number' => '800003', 'name' => 'Без начисления']);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'turnover-balance-sheet'])
        ->assertOk()
        ->assertCanSeeTableRecords([$fixture['debtor'], $fixture['overpaid']])
        ->assertCanNotSeeTableRecords([$withoutAccrual])
        ->assertTableColumnStateSet('opening_debit', 1000.0, $fixture['debtor'])
        ->assertTableColumnStateSet('opening_credit', 0.0, $fixture['debtor'])
        ->assertTableColumnStateSet('turnover_debit', 5500.0, $fixture['debtor'])
        ->assertTableColumnStateSet('turnover_credit', 2000.0, $fixture['debtor'])
        ->assertTableColumnStateSet('closing_debit', 4500.0, $fixture['debtor'])
        ->assertTableColumnStateSet('closing_credit', 0.0, $fixture['debtor'])
        ->assertTableColumnStateSet('accrued_amount', 5000.0, $fixture['debtor'])
        ->assertTableColumnStateSet('adjustment_amount', 500.0, $fixture['debtor'])
        ->assertTableColumnStateSet('paid_amount', 2000.0, $fixture['debtor'])
        ->assertTableColumnStateSet('opening_debit', 0.0, $fixture['overpaid'])
        ->assertTableColumnStateSet('opening_credit', 300.0, $fixture['overpaid'])
        ->assertTableColumnStateSet('turnover_debit', 1000.0, $fixture['overpaid'])
        ->assertTableColumnStateSet('turnover_credit', 2700.0, $fixture['overpaid'])
        ->assertTableColumnStateSet('closing_debit', 0.0, $fixture['overpaid'])
        ->assertTableColumnStateSet('closing_credit', 2000.0, $fixture['overpaid'])
        ->assertTableColumnSummarySet('opening_debit', 'total', 1000.0)
        ->assertTableColumnSummarySet('opening_credit', 'total', 300.0)
        ->assertTableColumnSummarySet('turnover_debit', 'total', 6500.0)
        ->assertTableColumnSummarySet('turnover_credit', 'total', 4700.0)
        ->assertTableColumnSummarySet('closing_debit', 'total', 4500.0)
        ->assertTableColumnSummarySet('closing_credit', 'total', 2000.0)
        ->assertTableColumnSummarySet('accrued_amount', 'total', 6000.0)
        ->assertTableColumnSummarySet('adjustment_amount', 'total', 300.0)
        ->assertTableColumnSummarySet('paid_amount', 'total', 4500.0);
});

test('turnover balance sheet builds an open billing period from receipts payments and adjustments', function () {
    $fixture = turnoverBalanceSheetFixture();
    $organization = $fixture['organization'];
    $client = $fixture['debtor'];

    $openPeriod = billingPeriodFor($organization, '202606');

    Receipt::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
            'receipt_number' => '202606-800001',
            'account_number' => '800001',
            'client_name' => 'Должник',
            'amount' => 3000,
            'paid_amount' => 500,
            'issued_at' => '2026-06-05 09:00:00',
        ]);
    Payment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
            'amount' => 500,
            'paid_at' => '2026-06-09',
        ]);
    BalanceAdjustment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
            'type' => BalanceAdjustmentType::ManualAdjustment->value,
            'amount' => -100,
        ]);

    $inactive = Client::factory()
        ->for($organization)
        ->for($fixture['utilityService'])
        ->create(['account_number' => '800009', 'name' => 'Неактивный', 'status' => 'inactive']);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, [
        'report' => 'turnover-balance-sheet',
        'period' => (string) $openPeriod->getKey(),
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$client, $fixture['overpaid']])
        ->assertCanNotSeeTableRecords([$inactive])
        ->assertTableColumnStateSet('opening_debit', 4500.0, $client)
        ->assertTableColumnStateSet('accrued_amount', 3000.0, $client)
        ->assertTableColumnStateSet('paid_amount', 500.0, $client)
        ->assertTableColumnStateSet('adjustment_amount', -100.0, $client)
        ->assertTableColumnStateSet('turnover_debit', 3000.0, $client)
        ->assertTableColumnStateSet('turnover_credit', 600.0, $client)
        ->assertTableColumnStateSet('closing_debit', 6900.0, $client)
        ->assertTableColumnStateSet('closing_credit', 0.0, $client)
        ->assertTableColumnStateSet('opening_credit', 2000.0, $fixture['overpaid'])
        ->assertTableColumnStateSet('accrued_amount', 0.0, $fixture['overpaid'])
        ->assertTableColumnStateSet('closing_credit', 2000.0, $fixture['overpaid']);
});

test('turnover balance sheet shows the volume labelled with the organization unit of measurement', function () {
    $fixture = turnoverBalanceSheetFixture();
    $organization = $fixture['organization'];

    $operator = actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'turnover-balance-sheet'])
        ->assertOk()
        ->assertSee('Объём, м3')
        ->assertTableColumnStateSet('volume', 20.0, $fixture['debtor'])
        ->assertTableColumnStateSet('volume', null, $fixture['overpaid'])
        ->assertTableColumnSummarySet('volume', 'total', 20.0);

    $cityRecords = collect(app(ReportSummaryService::class)->records(
        'turnover-balance-sheet',
        ReportSummaryGroup::City,
        $organization,
        $operator,
        $fixture['closedPeriod'],
    ))->keyBy('group_label');

    expect($cityRecords->get('Алматы'))->toMatchArray(['volume' => 20.0]);
    expect($cityRecords->get('Астана'))->toMatchArray(['volume' => 0.0]);
    expect($cityRecords->get('Итого'))->toMatchArray(['volume' => 20.0]);
});

test('turnover balance sheet reports the volume of metered subscribers only', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'unit_of_measurement' => 'м3',
    ]);

    $city = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $region = Region::factory()->for($organization)->for($city)->create(['name' => 'Алмалинский']);

    $closedPeriod = closedBillingPeriodFor($organization, '202605');

    $metered = Client::factory()->for($organization)->for($utilityService)->create([
        'account_number' => '810001',
        'billing_type' => 'meter',
        'region_id' => $region->id,
    ]);
    $perPerson = Client::factory()->for($organization)->for($utilityService)->create([
        'account_number' => '810002',
        'billing_type' => 'per_person',
        'residents_count' => 3,
        'region_id' => $region->id,
    ]);
    $fixed = Client::factory()->for($organization)->for($utilityService)->create([
        'account_number' => '810003',
        'billing_type' => 'fixed',
        'region_id' => $region->id,
    ]);

    Accrual::factory()->for($organization)->for($metered)->create([
        'period' => '202605',
        'billing_type' => 'meter',
        'volume' => 20,
    ]);
    Accrual::factory()->for($organization)->for($perPerson)->create([
        'period' => '202605',
        'billing_type' => 'per_person',
        'volume' => 3,
    ]);
    Accrual::factory()->for($organization)->for($fixed)->create([
        'period' => '202605',
        'billing_type' => 'fixed',
        'volume' => null,
    ]);

    $operator = actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'turnover-balance-sheet'])
        ->assertOk()
        ->assertTableColumnStateSet('volume', 20.0, $metered)
        ->assertTableColumnStateSet('volume', null, $perPerson)
        ->assertTableColumnStateSet('volume', null, $fixed)
        ->assertTableColumnSummarySet('volume', 'total', 20.0);

    $cityRecords = collect(app(ReportSummaryService::class)->records(
        'turnover-balance-sheet',
        ReportSummaryGroup::City,
        $organization,
        $operator,
        $closedPeriod,
    ))->keyBy('group_label');

    expect($cityRecords->get('Итого'))->toMatchArray(['volume' => 20.0]);
});

test('turnover balance sheet labels the volume without a unit when the organization has no service', function () {
    $organization = Organization::factory()->create();

    $closedPeriod = closedBillingPeriodFor($organization, '202605');

    $client = Client::factory()->for($organization)->create([
        'account_number' => '820001',
        'billing_type' => 'meter',
    ]);

    Accrual::factory()->for($organization)->for($client)->create([
        'period' => '202605',
        'billing_type' => 'meter',
        'volume' => 12,
        'utility_service_id' => null,
    ]);

    $operator = actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'turnover-balance-sheet'])
        ->assertOk()
        ->assertSee('Объём')
        ->assertDontSee('Объём,')
        ->assertTableColumnStateSet('volume', 12.0, $client);

    $download = Livewire::test(ViewReport::class, [
        'report' => 'turnover-balance-sheet',
        'mode' => 'summary',
        'group' => ReportSummaryGroup::City->value,
    ])
        ->assertOk()
        ->callAction('downloadExcel');

    expect(downloadedXlsxRows($download->effects['download'])[0])->toContain('Объём');

    expect(app(ReportSummaryService::class)->records(
        'turnover-balance-sheet',
        ReportSummaryGroup::City,
        $organization,
        $operator,
        $closedPeriod,
    )['total'])->toMatchArray(['volume' => 12.0]);
});

test('turnover balance sheet summary excel export carries the volume column', function () {
    $fixture = turnoverBalanceSheetFixture();

    actingAsReportsTenant($fixture['organization']);

    $download = Livewire::test(ViewReport::class, [
        'report' => 'turnover-balance-sheet',
        'mode' => 'summary',
        'group' => ReportSummaryGroup::City->value,
    ])
        ->assertOk()
        ->callAction('downloadExcel');

    $rows = downloadedXlsxRows($download->effects['download']);

    expect($rows[0])->toBe([
        'Город',
        'Абонентов',
        'Объём, м3',
        'Сальдо нач. Дебет',
        'Сальдо нач. Кредит',
        'Оборот Дебет',
        'Оборот Кредит',
        'Сальдо кон. Дебет',
        'Сальдо кон. Кредит',
        'Начислено',
        'Корректировка',
        'Оплачено',
    ]);

    $rowsByCity = collect(array_slice($rows, 1))->keyBy(fn (array $row): mixed => $row[0]);

    expect($rowsByCity->get('Алматы')[2])->toEqual(20.0);
    expect($rowsByCity->get('Астана')[2])->toEqual(0.0);
    expect($rowsByCity->get('Итого')[2])->toEqual(20.0);
});

test('turnover balance sheet takes the volume of an open period from the receipt', function () {
    $fixture = turnoverBalanceSheetFixture();
    $organization = $fixture['organization'];
    $client = $fixture['debtor'];

    $openPeriod = billingPeriodFor($organization, '202606');

    Receipt::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
            'receipt_number' => '202606-800001',
            'account_number' => '800001',
            'client_name' => 'Должник',
            'volume' => 15,
            'amount' => 3000,
            'issued_at' => '2026-06-05 09:00:00',
        ]);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, [
        'report' => 'turnover-balance-sheet',
        'period' => (string) $openPeriod->getKey(),
    ])
        ->assertOk()
        ->assertTableColumnStateSet('volume', 15.0, $client)
        ->assertTableColumnStateSet('volume', null, $fixture['overpaid']);
});

test('turnover balance sheet shows opening balance adjustments as opening balance in an open period', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    $city = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $region = Region::factory()->for($organization)->for($city)->create(['name' => 'Алмалинский']);
    $street = Street::factory()->for($region)->create(['name' => 'Абая']);

    $openPeriod = billingPeriodFor($organization, '202606');

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '800101',
            'name' => 'Новый абонент',
            'region_id' => $region->id,
            'street_id' => $street->id,
        ]);

    BalanceAdjustment::factory()
        ->count(2)
        ->for($organization)
        ->for($client)
        ->sequence(
            [
                'period' => '202606',
                'type' => BalanceAdjustmentType::OpeningBalance->value,
                'amount' => 500,
            ],
            [
                'period' => '202606',
                'type' => BalanceAdjustmentType::ManualAdjustment->value,
                'amount' => -100,
            ],
        )
        ->create();

    Receipt::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
            'receipt_number' => '202606-800101',
            'account_number' => '800101',
            'client_name' => 'Новый абонент',
            'amount' => 3000,
            'paid_amount' => 500,
            'issued_at' => '2026-06-05 09:00:00',
        ]);
    Payment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
            'amount' => 500,
            'paid_at' => '2026-06-09',
        ]);

    $operator = actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, [
        'report' => 'turnover-balance-sheet',
        'period' => (string) $openPeriod->getKey(),
    ])
        ->assertOk()
        ->assertTableColumnStateSet('opening_debit', 500.0, $client)
        ->assertTableColumnStateSet('opening_credit', 0.0, $client)
        ->assertTableColumnStateSet('accrued_amount', 3000.0, $client)
        ->assertTableColumnStateSet('adjustment_amount', -100.0, $client)
        ->assertTableColumnStateSet('paid_amount', 500.0, $client)
        ->assertTableColumnStateSet('turnover_debit', 3000.0, $client)
        ->assertTableColumnStateSet('turnover_credit', 600.0, $client)
        ->assertTableColumnStateSet('closing_debit', 2900.0, $client)
        ->assertTableColumnStateSet('closing_credit', 0.0, $client)
        ->assertTableColumnSummarySet('opening_debit', 'total', 500.0)
        ->assertTableColumnSummarySet('turnover_debit', 'total', 3000.0)
        ->assertTableColumnSummarySet('closing_debit', 'total', 2900.0);

    $cityRecords = collect(app(ReportSummaryService::class)->records(
        'turnover-balance-sheet',
        ReportSummaryGroup::City,
        $organization,
        $operator,
        $openPeriod,
    ))->keyBy('group_label');

    expect($cityRecords->get('Алматы'))->toMatchArray([
        'opening_debit' => 500.0,
        'opening_credit' => 0.0,
        'turnover_debit' => 3000.0,
        'turnover_credit' => 600.0,
        'closing_debit' => 2900.0,
        'closing_credit' => 0.0,
        'adjustment_amount' => -100.0,
    ]);
});

test('turnover balance sheet excel export repeats the two level heading and totals', function () {
    $fixture = turnoverBalanceSheetFixture();

    actingAsReportsTenant($fixture['organization']);

    $download = Livewire::test(ViewReport::class, ['report' => 'turnover-balance-sheet'])
        ->assertOk()
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'turnover-balance-sheet-'.$fixture['organization']->getKey().'-202605-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $rows = downloadedXlsxRows($download->effects['download']);

    expect($rows[0][0])->toBe('Лицевой счёт');
    expect($rows[0][4])->toBe('Объём, м3');
    expect($rows[0][5])->toBe('Сальдо на начало периода');
    expect($rows[0][7])->toBe('Обороты за период');
    expect($rows[0][9])->toBe('Сальдо на конец периода');
    expect($rows[0][11])->toBe('Расшифровка оборотов');
    expect($rows[1][5])->toBe('Дебет');
    expect($rows[1][6])->toBe('Кредит');
    expect($rows[1][11])->toBe('Начислено');
    expect($rows[1][12])->toBe('Корректировка');
    expect($rows[1][13])->toBe('Оплачено');

    expect($rows[2][0])->toBe('800001');
    expect($rows[2][3])->toBe('05.2026');
    expect($rows[2][4])->toEqual(20.0);
    expect(array_slice($rows[2], 5, 9))->toEqual([1000.0, 0.0, 5500.0, 2000.0, 4500.0, 0.0, 5000.0, 500.0, 2000.0]);
    expect($rows[3][0])->toBe('800002');
    expect($rows[3][4])->toBe('');
    expect(array_slice($rows[3], 5, 9))->toEqual([0.0, 300.0, 1000.0, 2700.0, 0.0, 2000.0, 1000.0, -200.0, 2500.0]);

    expect($rows[4][0])->toBe('Итого');
    expect($rows[4][4])->toEqual(20.0);
    expect(array_slice($rows[4], 5, 9))->toEqual([1000.0, 300.0, 6500.0, 4700.0, 4500.0, 2000.0, 6000.0, 300.0, 4500.0]);

    $totals = array_slice($rows[4], 5, 6);

    expect($totals[0] - $totals[1] + $totals[2] - $totals[3])->toEqual($totals[4] - $totals[5]);
});

test('turnover balance sheet summarizes by city region street and controller with totals', function () {
    $fixture = turnoverBalanceSheetFixture();
    $organization = $fixture['organization'];

    $controller = User::factory()->create(['name' => 'Контроллер Алматы']);
    $organization->users()->attach($controller, ['role' => OrganizationMemberRole::Controller->value]);
    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $controller->id,
        'region_id' => $fixture['almalinsky']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $operator = actingAsReportsTenant($organization);
    $summaryService = app(ReportSummaryService::class);

    $cityRecords = collect($summaryService->records(
        'turnover-balance-sheet',
        ReportSummaryGroup::City,
        $organization,
        $operator,
        $fixture['closedPeriod'],
    ))->keyBy('group_label');

    expect($cityRecords->get('Алматы'))->toMatchArray([
        'clients_count' => 1,
        'opening_debit' => 1000.0,
        'opening_credit' => 0.0,
        'turnover_debit' => 5500.0,
        'turnover_credit' => 2000.0,
        'closing_debit' => 4500.0,
        'closing_credit' => 0.0,
    ]);
    expect($cityRecords->get('Астана'))->toMatchArray([
        'opening_credit' => 300.0,
        'turnover_debit' => 1000.0,
        'turnover_credit' => 2700.0,
        'closing_credit' => 2000.0,
    ]);
    expect($cityRecords->get('Итого'))->toMatchArray([
        'clients_count' => 2,
        'opening_debit' => 1000.0,
        'opening_credit' => 300.0,
        'turnover_debit' => 6500.0,
        'turnover_credit' => 4700.0,
        'closing_debit' => 4500.0,
        'closing_credit' => 2000.0,
        'accrued_amount' => 6000.0,
        'adjustment_amount' => 300.0,
        'paid_amount' => 4500.0,
    ]);

    $regionRecords = collect($summaryService->records(
        'turnover-balance-sheet',
        ReportSummaryGroup::Region,
        $organization,
        $operator,
        $fixture['closedPeriod'],
    ))->keyBy('group_label');
    $streetRecords = collect($summaryService->records(
        'turnover-balance-sheet',
        ReportSummaryGroup::Street,
        $organization,
        $operator,
        $fixture['closedPeriod'],
    ))->keyBy('group_label');
    $controllerRecords = collect($summaryService->records(
        'turnover-balance-sheet',
        ReportSummaryGroup::Controller,
        $organization,
        $operator,
        $fixture['closedPeriod'],
    ))->keyBy('group_label');

    expect($regionRecords->get('Алмалинский'))->toMatchArray(['closing_debit' => 4500.0]);
    expect($streetRecords->get('Алмалинский / Абая'))->toMatchArray(['closing_debit' => 4500.0]);
    expect($controllerRecords->get('Контроллер Алматы'))->toMatchArray([
        'clients_count' => 1,
        'closing_debit' => 4500.0,
        'closing_credit' => 0.0,
    ]);

    Livewire::test(ViewReport::class, [
        'report' => 'turnover-balance-sheet',
        'mode' => 'summary',
        'group' => ReportSummaryGroup::City->value,
    ])
        ->assertOk()
        ->assertActionExists('summaryByCity')
        ->assertActionExists('summaryByRegion')
        ->assertActionExists('summaryByStreet')
        ->assertActionExists('summaryByController')
        ->assertSee('Алматы')
        ->assertSee('Астана')
        ->assertSee('Итого');
});

test('turnover balance sheet shows a controller only the subscribers of the assigned zone', function () {
    $fixture = turnoverBalanceSheetFixture();
    $organization = $fixture['organization'];

    $controller = actingAsReportsController($organization);
    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $controller->id,
        'region_id' => $fixture['almalinsky']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(ViewReport::class, ['report' => 'turnover-balance-sheet'])
        ->assertOk()
        ->assertCanSeeTableRecords([$fixture['debtor']])
        ->assertCanNotSeeTableRecords([$fixture['overpaid']]);
});

test('turnover balance sheet excel export repeats the address and controller filters', function () {
    $fixture = turnoverBalanceSheetFixture();
    $organization = $fixture['organization'];

    actingAsReportsTenant($organization);

    $download = Livewire::test(ViewReport::class, ['report' => 'turnover-balance-sheet'])
        ->assertOk()
        ->filterTable('address', ['city_id' => $fixture['city']->id, 'region_id' => null, 'street_ids' => []])
        ->assertCanSeeTableRecords([$fixture['debtor']])
        ->assertCanNotSeeTableRecords([$fixture['overpaid']])
        ->callAction('downloadExcel');

    $rows = downloadedXlsxRows($download->effects['download']);

    expect($rows)->toHaveCount(4);
    expect($rows[2][0])->toBe('800001');
    expect($rows[3][0])->toBe('Итого');
    expect($rows[3][4])->toEqual(20.0);
    expect(array_slice($rows[3], 5, 6))->toEqual([1000.0, 0.0, 5500.0, 2000.0, 4500.0, 0.0]);
});

test('turnover balance sheet keeps the selected billing period across modes', function () {
    $fixture = turnoverBalanceSheetFixture();
    $organization = $fixture['organization'];
    $openPeriod = billingPeriodFor($organization, '202606');

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => 'turnover-balance-sheet'])
        ->assertOk()
        ->assertSee('Расчётный месяц: 05.2026');

    Livewire::test(ViewReport::class, [
        'report' => 'turnover-balance-sheet',
        'period' => (string) $openPeriod->getKey(),
    ])
        ->assertOk()
        ->assertSee('Расчётный месяц: 06.2026')
        ->assertSee('period='.$openPeriod->getKey());

    Livewire::test(ViewReport::class, [
        'report' => 'turnover-balance-sheet',
        'period' => '999999',
    ])
        ->assertOk()
        ->assertSee('Расчётный месяц: 05.2026');
});

test('report summaries add a totals row', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create(['name' => 'Алмалинский']);
    $street = Street::factory()->for($region)->create(['name' => 'Абая']);

    $billingPeriod = billingPeriodFor($organization, '202606');

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '810001',
            'name' => 'Плательщик',
            'region_id' => $region->id,
            'street_id' => $street->id,
        ]);
    Payment::factory()
        ->for($organization)
        ->for($client)
        ->create(['period' => '202606', 'amount' => 2500, 'paid_at' => '2026-06-09']);

    $operator = actingAsReportsTenant($organization);

    $records = collect(app(ReportSummaryService::class)->records(
        'payments',
        ReportSummaryGroup::Region,
        $organization,
        $operator,
        $billingPeriod,
    ));

    expect($records->keys()->last())->toBe('total');
    expect($records->get('total'))->toMatchArray([
        'group_label' => 'Итого',
        'clients_count' => 1,
        'payments_count' => 1,
        'payment_amount' => 2500.0,
    ]);
});

test('no summary report shows the rows column', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $city = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $region = Region::factory()->for($organization)->for($city)->create(['name' => 'Алмалинский']);
    $street = Street::factory()->for($region)->create(['name' => 'Абая']);

    $billingPeriod = billingPeriodFor($organization, '202606');

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '820001',
            'name' => 'Абонент сводки',
            'billing_type' => 'meter',
            'region_id' => $region->id,
            'street_id' => $street->id,
            'created_at' => '2026-06-05 09:00:00',
        ]);

    $readMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'number' => 'MTR-SUMMARY-READ',
            'initial_reading' => 10,
            'installed_on' => '2026-06-03',
        ]);
    MeterReading::factory()
        ->for($readMeter)
        ->create([
            'period' => '202606',
            'previous_reading' => 10,
            'current_reading' => 25,
            'read_at' => '2026-06-08',
        ]);

    // Счётчик без показания оставляет строки в отчёте о пропущенных показаниях.
    Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'number' => 'MTR-SUMMARY-MISSING',
            'initial_reading' => 0,
            'installed_on' => '2026-06-04',
        ]);

    Payment::factory()
        ->for($organization)
        ->for($client)
        ->create(['period' => '202606', 'amount' => 2000, 'paid_at' => '2026-06-09']);

    // Показание уже создало квитанцию месяца: долг и неоплаченный остаток задаются
    // прямым обновлением, чтобы строки остались и в этих двух отчётах.
    Receipt::query()
        ->where('client_id', $client->getKey())
        ->where('billing_period_id', $billingPeriod->getKey())
        ->update([
            'amount' => 6000,
            'paid_amount' => 2000,
            'adjustment_amount' => 0,
            'opening_balance' => 0,
            'closing_balance' => 4000,
        ]);

    $operator = actingAsReportsTenant($organization);
    $summaryService = app(ReportSummaryService::class);

    $reports = [
        'meter-reading-sheet',
        'missing-meter-readings',
        'controller-meter-reading-progress',
        'new-client-accounts',
        'payments',
        'unpaid-receipts',
        'meter-installation-replacement',
        'debts',
        'consumption',
        'turnover-balance-sheet',
    ];

    foreach ($reports as $report) {
        $records = $summaryService->records(
            $report,
            ReportSummaryGroup::City,
            $organization,
            $operator,
            $billingPeriod,
        );

        expect($records)->not->toBeEmpty();

        foreach ($records as $record) {
            expect($record)->not->toHaveKey('records_count');
        }

        $download = Livewire::test(ViewReport::class, [
            'report' => $report,
            'mode' => 'summary',
            'group' => ReportSummaryGroup::City->value,
        ])
            ->assertOk()
            ->assertSee('Абонентов')
            ->assertDontSee('Строк')
            ->callAction('downloadExcel');

        expect(downloadedXlsxRows($download->effects['download'])[0])->not->toContain('Строк');
    }
});

test('turnover balance sheet totals an open billing period on screen', function () {
    $fixture = turnoverBalanceSheetFixture();
    $organization = $fixture['organization'];
    $openPeriod = billingPeriodFor($organization, '202606');

    Receipt::factory()
        ->for($organization)
        ->for($fixture['debtor'])
        ->create([
            'period' => '202606',
            'receipt_number' => '202606-800001',
            'account_number' => '800001',
            'client_name' => 'Должник',
            'amount' => 3000,
            'paid_amount' => 500,
            'issued_at' => '2026-06-05 09:00:00',
        ]);
    Payment::factory()
        ->for($organization)
        ->for($fixture['debtor'])
        ->create(['period' => '202606', 'amount' => 500, 'paid_at' => '2026-06-09']);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, [
        'report' => 'turnover-balance-sheet',
        'period' => (string) $openPeriod->getKey(),
    ])
        ->assertOk()
        ->assertTableColumnSummarySet('opening_debit', 'total', 4500.0)
        ->assertTableColumnSummarySet('opening_credit', 'total', 2000.0)
        ->assertTableColumnSummarySet('turnover_debit', 'total', 3000.0)
        ->assertTableColumnSummarySet('turnover_credit', 'total', 500.0)
        ->assertTableColumnSummarySet('closing_debit', 'total', 7000.0)
        ->assertTableColumnSummarySet('closing_credit', 'total', 2000.0);
});

/**
 * Detail reports that gained the address and controller filters, with the name of their date filters.
 *
 * @return array<string, array{0: string, 1: string|null}>
 */
function reportsWithAddressAndControllerFilters(): array
{
    return [
        'missing meter readings' => ['missing-meter-readings', 'installed_on'],
        'new client accounts' => ['new-client-accounts', 'created_at'],
        'payments' => ['payments', 'paid_at'],
        'unpaid receipts' => ['unpaid-receipts', 'issued_at'],
        'meter installation replacement' => ['meter-installation-replacement', 'installed_on'],
        'debts' => ['debts', null],
        'consumption' => ['consumption', 'read_at'],
    ];
}

/**
 * Creates the one detail row the report shows for the client, dated for the report date filter.
 */
function reportFilterRowFor(string $report, Client $client, string $date): Model
{
    $organization = $client->organization;
    $utilityService = $client->utilityService;

    $meter = fn (): Meter => Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'number' => 'MTR-'.$client->account_number,
            'status' => 'active',
            'installed_on' => $date,
        ]);

    $receipt = fn (float $amount, float $paidAmount): Receipt => Receipt::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
            'receipt_number' => '202606-'.$client->account_number,
            'account_number' => $client->account_number,
            'client_name' => $client->name,
            'billing_type' => 'fixed',
            'amount' => $amount,
            'paid_amount' => $paidAmount,
            'adjustment_amount' => 0,
            'opening_balance' => 0,
            'closing_balance' => $amount - $paidAmount,
            'issued_at' => $date.' 09:00:00',
        ]);

    return match ($report) {
        'missing-meter-readings', 'meter-installation-replacement' => $meter(),
        'new-client-accounts' => tap($client, function (Client $client) use ($date): void {
            Client::query()->whereKey($client->getKey())->update([
                'created_at' => $date.' 10:00:00',
                'updated_at' => $date.' 10:00:00',
            ]);
        })->refresh(),
        'payments' => Payment::factory()
            ->for($organization)
            ->for($client)
            ->create(['period' => '202606', 'amount' => 500, 'paid_at' => $date]),
        'unpaid-receipts' => $receipt(5000, 1000),
        'debts' => tap($client, fn (): Receipt => $receipt(1000, 0)),
        'consumption' => MeterReading::factory()
            ->for($meter())
            ->create([
                'period' => '202606',
                'previous_reading' => 10,
                'current_reading' => 25,
                'read_at' => $date,
            ]),
    };
}

function reportFilterZoneFor(Organization $organization, User $controller, ?Region $region = null, ?Street $street = null): void
{
    if ($region instanceof Region) {
        DB::table('organization_user_regions')->insert([
            'organization_id' => $organization->id,
            'user_id' => $controller->id,
            'region_id' => $region->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    if ($street instanceof Street) {
        DB::table('organization_user_streets')->insert([
            'organization_id' => $organization->id,
            'user_id' => $controller->id,
            'street_id' => $street->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

/**
 * One report row on each of four streets of two cities, three controllers of the organization
 * and a foreign organization with its own address, controller and row.
 *
 * The Almalinsky region controller and the Abay street controller share the Abay row.
 *
 * @return array{
 *     organization: Organization,
 *     city: City,
 *     almalinsky: Region,
 *     abay: Street,
 *     satpaev: Street,
 *     almalinskyController: User,
 *     abayController: User,
 *     satpaevController: User,
 *     abayRow: Model,
 *     gogolRow: Model,
 *     satpaevRow: Model,
 *     esilRow: Model,
 *     foreignCity: City,
 *     foreignStreet: Street,
 *     foreignController: User,
 *     foreignRow: Model,
 * }
 */
function reportFiltersFixture(string $report): array
{
    $foreignOrganization = Organization::factory()->create();
    $foreignUtilityService = UtilityService::factory()->for($foreignOrganization)->create();
    billingPeriodFor($foreignOrganization, '202606');
    $foreignCity = City::factory()->for($foreignOrganization)->create(['name' => 'Алматы']);
    $foreignRegion = Region::factory()->for($foreignOrganization)->for($foreignCity)->create(['name' => 'Алмалинский']);
    $foreignStreet = Street::factory()->for($foreignRegion)->create(['name' => 'Абая']);
    $foreignController = User::factory()->create(['name' => 'Чужой контроллер']);
    $foreignOrganization->users()->attach($foreignController, ['role' => OrganizationMemberRole::Controller->value]);
    reportFilterZoneFor($foreignOrganization, $foreignController, $foreignRegion);

    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    billingPeriodFor($organization, '202606');

    $city = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $otherCity = City::factory()->for($organization)->create(['name' => 'Астана']);
    $almalinsky = Region::factory()->for($organization)->for($city)->create(['name' => 'Алмалинский']);
    $bostandyk = Region::factory()->for($organization)->for($city)->create(['name' => 'Бостандыкский']);
    $esil = Region::factory()->for($organization)->for($otherCity)->create(['name' => 'Есильский']);
    $abay = Street::factory()->for($almalinsky)->create(['name' => 'Абая']);
    $gogol = Street::factory()->for($almalinsky)->create(['name' => 'Гоголя']);
    $satpaev = Street::factory()->for($bostandyk)->create(['name' => 'Сатпаева']);
    $kabanbay = Street::factory()->for($esil)->create(['name' => 'Кабанбай батыра']);

    $controllers = [];

    foreach (['almalinskyController' => 'Контроллер района', 'abayController' => 'Контроллер Абая', 'satpaevController' => 'Контроллер Сатпаева'] as $key => $name) {
        $controllers[$key] = User::factory()->create(['name' => $name]);
        $organization->users()->attach($controllers[$key], ['role' => OrganizationMemberRole::Controller->value]);
    }

    reportFilterZoneFor($organization, $controllers['almalinskyController'], $almalinsky);
    reportFilterZoneFor($organization, $controllers['abayController'], street: $abay);
    reportFilterZoneFor($organization, $controllers['satpaevController'], street: $satpaev);

    $row = function (Organization $organization, UtilityService $utilityService, string $accountNumber, Region $region, Street $street, string $date) use ($report): Model {
        $client = Client::factory()
            ->for($organization)
            ->for($utilityService)
            ->create([
                'account_number' => $accountNumber,
                'name' => 'Абонент '.$accountNumber,
                'billing_type' => 'meter',
                'status' => 'active',
                'region_id' => $region->id,
                'street_id' => $street->id,
            ]);

        return reportFilterRowFor($report, $client, $date);
    };

    return [
        'organization' => $organization,
        'city' => $city,
        'almalinsky' => $almalinsky,
        'abay' => $abay,
        'satpaev' => $satpaev,
        ...$controllers,
        'abayRow' => $row($organization, $utilityService, '770001', $almalinsky, $abay, '2026-06-05'),
        'gogolRow' => $row($organization, $utilityService, '770002', $almalinsky, $gogol, '2026-06-20'),
        'satpaevRow' => $row($organization, $utilityService, '770003', $bostandyk, $satpaev, '2026-06-20'),
        'esilRow' => $row($organization, $utilityService, '770004', $esil, $kabanbay, '2026-06-20'),
        'foreignCity' => $foreignCity,
        'foreignStreet' => $foreignStreet,
        'foreignController' => $foreignController,
        'foreignRow' => $row($foreignOrganization, $foreignUtilityService, '770009', $foreignRegion, $foreignStreet, '2026-06-05'),
    ];
}

test('detail reports filter rows by city, region and streets of the client', function (string $report) {
    $fixture = reportFiltersFixture($report);

    actingAsReportsTenant($fixture['organization']);

    Livewire::test(ViewReport::class, ['report' => $report])
        ->assertOk()
        ->assertCountTableRecords(4)
        ->assertCanSeeTableRecords([$fixture['abayRow'], $fixture['gogolRow'], $fixture['satpaevRow'], $fixture['esilRow']])
        ->filterTable('address', ['city_id' => $fixture['city']->id])
        ->assertCountTableRecords(3)
        ->assertCanSeeTableRecords([$fixture['abayRow'], $fixture['gogolRow'], $fixture['satpaevRow']])
        ->assertCanNotSeeTableRecords([$fixture['esilRow']])
        ->filterTable('address', ['city_id' => $fixture['city']->id, 'region_id' => $fixture['almalinsky']->id])
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$fixture['abayRow'], $fixture['gogolRow']])
        ->assertCanNotSeeTableRecords([$fixture['satpaevRow'], $fixture['esilRow']])
        ->filterTable('address', ['street_ids' => [$fixture['abay']->id, $fixture['satpaev']->id]])
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$fixture['abayRow'], $fixture['satpaevRow']])
        ->assertCanNotSeeTableRecords([$fixture['gogolRow'], $fixture['esilRow']]);
})->with(fn (): array => array_map(fn (array $report): array => [$report[0]], reportsWithAddressAndControllerFilters()));

test('detail reports filter rows by controller zones without duplicating shared clients', function (string $report) {
    $fixture = reportFiltersFixture($report);

    actingAsReportsTenant($fixture['organization']);

    Livewire::test(ViewReport::class, ['report' => $report])
        ->assertOk()
        ->filterTable('controller_ids', [$fixture['almalinskyController']->id])
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$fixture['abayRow'], $fixture['gogolRow']])
        ->assertCanNotSeeTableRecords([$fixture['satpaevRow'], $fixture['esilRow']])
        ->filterTable('controller_ids', [$fixture['satpaevController']->id])
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$fixture['satpaevRow']])
        ->filterTable('controller_ids', [$fixture['almalinskyController']->id, $fixture['abayController']->id])
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$fixture['abayRow'], $fixture['gogolRow']])
        ->filterTable('controller_ids', [$fixture['almalinskyController']->id, $fixture['satpaevController']->id])
        ->assertCountTableRecords(3)
        ->assertCanNotSeeTableRecords([$fixture['esilRow']]);
})->with(fn (): array => array_map(fn (array $report): array => [$report[0]], reportsWithAddressAndControllerFilters()));

test('detail report filters never reach another organization', function (string $report) {
    $fixture = reportFiltersFixture($report);

    actingAsReportsTenant($fixture['organization']);

    Livewire::test(ViewReport::class, ['report' => $report])
        ->assertOk()
        ->assertCanNotSeeTableRecords([$fixture['foreignRow']])
        ->filterTable('address', ['city_id' => $fixture['foreignCity']->id])
        ->assertCountTableRecords(0)
        ->filterTable('address', ['street_ids' => [$fixture['foreignStreet']->id]])
        ->assertCountTableRecords(0)
        ->resetTableFilters()
        ->filterTable('controller_ids', [$fixture['foreignController']->id])
        ->assertCountTableRecords(0)
        ->assertCanNotSeeTableRecords([$fixture['foreignRow']]);
})->with(fn (): array => array_map(fn (array $report): array => [$report[0]], reportsWithAddressAndControllerFilters()));

test('detail report filters do not widen the zone of a controller', function (string $report) {
    $fixture = reportFiltersFixture($report);
    $organization = $fixture['organization'];

    $controller = actingAsReportsController($organization);
    reportFilterZoneFor($organization, $controller, $fixture['almalinsky']);

    Livewire::test(ViewReport::class, ['report' => $report])
        ->assertOk()
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$fixture['abayRow'], $fixture['gogolRow']])
        ->assertCanNotSeeTableRecords([$fixture['satpaevRow'], $fixture['esilRow'], $fixture['foreignRow']])
        ->filterTable('controller_ids', [$fixture['satpaevController']->id])
        ->assertCountTableRecords(0)
        ->filterTable('controller_ids', [$fixture['abayController']->id])
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$fixture['abayRow']])
        ->resetTableFilters()
        ->filterTable('address', ['street_ids' => [$fixture['satpaev']->id]])
        ->assertCountTableRecords(0);
})->with(fn (): array => array_map(fn (array $report): array => [$report[0]], reportsWithAddressAndControllerFilters()));

test('detail report excel download repeats the applied address, controller and date filters', function (string $report, ?string $dateFilter) {
    $fixture = reportFiltersFixture($report);

    actingAsReportsTenant($fixture['organization']);

    $unfiltered = Livewire::test(ViewReport::class, ['report' => $report])
        ->assertOk()
        ->callAction('downloadExcel');

    expect(array_column(array_slice(downloadedXlsxRows($unfiltered->effects['download']), 1), 0))
        ->toEqualCanonicalizing(['770001', '770002', '770003', '770004']);

    $addressAndController = Livewire::test(ViewReport::class, ['report' => $report])
        ->assertOk()
        ->filterTable('address', ['city_id' => $fixture['city']->id])
        ->filterTable('controller_ids', [$fixture['almalinskyController']->id, $fixture['satpaevController']->id])
        ->filterTable('address', ['city_id' => $fixture['city']->id, 'street_ids' => [$fixture['abay']->id, $fixture['satpaev']->id]])
        ->assertCountTableRecords(2)
        ->callAction('downloadExcel');

    expect(array_column(array_slice(downloadedXlsxRows($addressAndController->effects['download']), 1), 0))
        ->toEqualCanonicalizing(['770001', '770003']);

    $pending = Livewire::test(ViewReport::class, ['report' => $report])
        ->assertOk()
        ->set('tableDeferredFilters.address.street_ids', [$fixture['abay']->id])
        ->callAction('downloadExcel');

    expect(downloadedXlsxRows($pending->effects['download']))->toHaveCount(5);

    if ($dateFilter === null) {
        return;
    }

    $dated = Livewire::test(ViewReport::class, ['report' => $report])
        ->assertOk()
        ->filterTable('address', ['region_id' => $fixture['almalinsky']->id])
        ->filterTable($dateFilter, ['start_date' => '2026-06-10', 'end_date' => null])
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$fixture['gogolRow']])
        ->callAction('downloadExcel');

    $rows = downloadedXlsxRows($dated->effects['download']);

    expect($rows)->toHaveCount(2)
        ->and($rows[1][0])->toBe('770002');
})->with(fn (): array => array_values(reportsWithAddressAndControllerFilters()));

test('meter installation replacement excel download repeats both date filters', function () {
    $fixture = reportFiltersFixture('meter-installation-replacement');

    actingAsReportsTenant($fixture['organization']);

    $download = Livewire::test(ViewReport::class, ['report' => 'meter-installation-replacement'])
        ->assertOk()
        ->filterTable('installed_on', ['start_date' => '2026-06-10', 'end_date' => null])
        ->filterTable('removed_on', ['start_date' => '2026-06-01', 'end_date' => null])
        ->assertCountTableRecords(0)
        ->callAction('downloadExcel');

    expect(downloadedXlsxRows($download->effects['download']))->toHaveCount(1);
});

test('report summary modes ignore the detail address and controller filters', function (string $report) {
    $fixture = reportFiltersFixture($report);

    actingAsReportsTenant($fixture['organization']);

    $download = Livewire::withQueryParams(['mode' => 'summary', 'group' => ReportSummaryGroup::City->value])
        ->test(ViewReport::class, ['report' => $report])
        ->assertOk()
        ->set('tableFilters', ['address' => ['street_ids' => [$fixture['abay']->id]]])
        ->callAction('downloadExcel');

    expect(downloadedXlsxRows($download->effects['download']))->toHaveCount(4);
})->with(fn (): array => array_map(fn (array $report): array => [$report[0]], reportsWithAddressAndControllerFilters()));

test('controller filter looks up the zones of any number of selected controllers in a constant number of queries', function () {
    $organization = Organization::factory()->create();
    $region = Region::factory()->for($organization)->create();
    $street = Street::factory()->for($region)->create();

    $controllers = User::factory()->count(12)->create();

    foreach ($controllers as $index => $controller) {
        $organization->users()->attach($controller, ['role' => OrganizationMemberRole::Controller->value]);
        reportFilterZoneFor($organization, $controller, $index % 2 === 0 ? $region : null, $index % 2 === 1 ? $street : null);
    }

    $filter = ControllerZoneFilter::make($organization, null);

    $queriesFor = function (array $controllerIds) use ($filter): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $filter->apply(Client::query(), ['values' => $controllerIds]);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queriesFor($controllers->take(12)->modelKeys()))
        ->toBe($queriesFor($controllers->take(1)->modelKeys()))
        ->toBeLessThanOrEqual(3);
});

test('controller filter keeps the zone rules for controllers without a zone', function (string $report) {
    $fixture = reportFiltersFixture($report);
    $organization = $fixture['organization'];

    $emptyZoneController = User::factory()->create(['name' => 'Контроллер без зоны']);
    $organization->users()->attach($emptyZoneController, ['role' => OrganizationMemberRole::Controller->value]);

    actingAsReportsTenant($organization);

    Livewire::test(ViewReport::class, ['report' => $report])
        ->assertOk()
        ->filterTable('controller_ids', [$emptyZoneController->id])
        ->assertCountTableRecords(0)
        ->filterTable('controller_ids', [$emptyZoneController->id, $fixture['satpaevController']->id])
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$fixture['satpaevRow']])
        ->filterTable('controller_ids', [$emptyZoneController->id, $fixture['foreignController']->id])
        ->assertCountTableRecords(0);
})->with(fn (): array => array_map(fn (array $report): array => [$report[0]], reportsWithAddressAndControllerFilters()));
