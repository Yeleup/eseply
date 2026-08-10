<?php

use App\BillingPeriodStatus;
use App\Filament\Resources\BillingPeriods\Pages\ListBillingPeriodClosureErrors;
use App\Filament\Resources\BillingPeriods\Tables\BillingPeriodClosureErrorsTable;
use App\Models\BillingPeriod;
use App\Models\BillingPeriodClosureError;
use App\Models\Organization;
use App\Models\User;
use App\OrganizationMemberRole;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Reader;

uses(RefreshDatabase::class);

function actingAsClosureErrorReportTenant(Organization $organization, ?OrganizationMemberRole $role = null): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, $role instanceof OrganizationMemberRole
        ? ['role' => $role->value]
        : []);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

function failedBillingPeriodFor(Organization $organization, string $period = '202605', int $failedClients = 1): BillingPeriod
{
    return BillingPeriod::factory()
        ->for($organization)
        ->period($period)
        ->create([
            'status' => BillingPeriodStatus::Failed,
            'failed_at' => now(),
            'failure_message' => 'Не все активные абоненты были рассчитаны.',
            'failed_clients_count' => $failedClients,
        ]);
}

/**
 * @return list<list<mixed>>
 */
function closureErrorReportXlsxRows(array $downloadEffect): array
{
    $path = tempnam(sys_get_temp_dir(), 'billing-period-closure-errors-');

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

test('closure error report shows only errors of the requested billing period and tenant', function () {
    $organization = Organization::factory()->create();

    /**
     * Filament associates every record created while a tenant is set with that
     * tenant, so the data of the other organization is prepared beforehand.
     */
    $otherOrganizationBillingPeriod = failedBillingPeriodFor(Organization::factory()->create());
    $otherTenantError = BillingPeriodClosureError::factory()
        ->for($otherOrganizationBillingPeriod->organization)
        ->for($otherOrganizationBillingPeriod)
        ->create(['account_number' => '80504']);

    actingAsClosureErrorReportTenant($organization);

    $otherBillingPeriod = BillingPeriod::factory()
        ->for($organization)
        ->period('202604')
        ->create([
            'status' => BillingPeriodStatus::Closed,
            'closed_at' => now(),
        ]);
    $billingPeriod = failedBillingPeriodFor($organization);

    $reportedError = BillingPeriodClosureError::factory()
        ->for($organization)
        ->for($billingPeriod)
        ->create([
            'account_number' => '80502',
            'client_name' => 'Без суммы',
            'billing_type' => 'fixed',
            'code' => 'missing_fixed_amount',
            'message' => 'Не указана фиксированная сумма.',
        ]);

    $otherPeriodError = BillingPeriodClosureError::factory()
        ->for($organization)
        ->for($otherBillingPeriod)
        ->create(['account_number' => '80503']);

    Livewire::test(ListBillingPeriodClosureErrors::class, ['record' => $billingPeriod->getKey()])
        ->assertOk()
        ->assertSee('Не все активные абоненты были рассчитаны.')
        ->assertCanSeeTableRecords([$reportedError])
        ->assertCanNotSeeTableRecords([$otherPeriodError, $otherTenantError])
        ->assertTableColumnStateSet('account_number', '80502', $reportedError)
        ->assertTableColumnStateSet('message', 'Не указана фиксированная сумма.', $reportedError);
});

test('closure error report paginates instead of rendering every error at once', function () {
    $organization = Organization::factory()->create();
    actingAsClosureErrorReportTenant($organization);

    $billingPeriod = failedBillingPeriodFor($organization, failedClients: 60);

    $errors = collect(range(1, 60))->map(fn (int $index): BillingPeriodClosureError => BillingPeriodClosureError::factory()
        ->for($organization)
        ->for($billingPeriod)
        ->create([
            'account_number' => str_pad((string) $index, 6, '0', STR_PAD_LEFT),
        ]));

    $pageSize = BillingPeriodClosureErrorsTable::DEFAULT_PAGE_SIZE;

    Livewire::test(ListBillingPeriodClosureErrors::class, ['record' => $billingPeriod->getKey()])
        ->assertOk()
        ->assertCanSeeTableRecords($errors->take($pageSize))
        ->assertCanNotSeeTableRecords($errors->slice($pageSize));
});

test('closure error report searches by account number and filters by error code', function () {
    $organization = Organization::factory()->create();
    actingAsClosureErrorReportTenant($organization);

    $billingPeriod = failedBillingPeriodFor($organization, failedClients: 2);

    $missingAmountError = BillingPeriodClosureError::factory()
        ->for($organization)
        ->for($billingPeriod)
        ->create([
            'account_number' => '700001',
            'client_name' => 'Иванов Иван',
            'code' => 'missing_fixed_amount',
            'message' => 'Не указана фиксированная сумма.',
        ]);

    $missingMeterError = BillingPeriodClosureError::factory()
        ->for($organization)
        ->for($billingPeriod)
        ->create([
            'account_number' => '700002',
            'client_name' => 'Петров Пётр',
            'billing_type' => 'meter',
            'code' => 'missing_active_meters',
            'message' => 'Не найдены активные счётчики по услуге организации.',
        ]);

    Livewire::test(ListBillingPeriodClosureErrors::class, ['record' => $billingPeriod->getKey()])
        ->assertOk()
        ->searchTable('700002')
        ->assertCanSeeTableRecords([$missingMeterError])
        ->assertCanNotSeeTableRecords([$missingAmountError])
        ->searchTable(null)
        ->filterTable('code', 'missing_fixed_amount')
        ->assertCanSeeTableRecords([$missingAmountError])
        ->assertCanNotSeeTableRecords([$missingMeterError]);
});

test('closure error report groups errors by stable code', function () {
    $organization = Organization::factory()->create();
    actingAsClosureErrorReportTenant($organization);

    $billingPeriod = failedBillingPeriodFor($organization, failedClients: 3);

    BillingPeriodClosureError::factory()
        ->count(2)
        ->for($organization)
        ->for($billingPeriod)
        ->create([
            'billing_type' => 'meter',
            'code' => 'missing_active_meters',
            'message' => 'Не найдены активные счётчики по услуге организации.',
        ]);

    BillingPeriodClosureError::factory()
        ->for($organization)
        ->for($billingPeriod)
        ->create([
            'code' => 'missing_fixed_amount',
            'message' => 'Не указана фиксированная сумма.',
        ]);

    $summary = Livewire::test(ListBillingPeriodClosureErrors::class, ['record' => $billingPeriod->getKey()])
        ->assertOk()
        ->assertSee('Причины ошибок')
        ->instance()
        ->getCodeSummary()
        ->all();

    expect($summary)->toBe([
        [
            'code' => 'missing_active_meters',
            'label' => 'Не найдены активные счётчики по услуге организации.',
            'total' => 2,
        ],
        [
            'code' => 'missing_fixed_amount',
            'label' => 'Не указана фиксированная сумма.',
            'total' => 1,
        ],
    ]);
});

test('closure error report downloads every error as xlsx', function () {
    $organization = Organization::factory()->create();
    actingAsClosureErrorReportTenant($organization);

    $billingPeriod = failedBillingPeriodFor($organization, failedClients: 2);

    BillingPeriodClosureError::factory()
        ->for($organization)
        ->for($billingPeriod)
        ->create([
            'account_number' => '700001',
            'client_name' => 'Иванов Иван',
            'billing_type' => 'meter',
            'code' => 'missing_meter_reading',
            'message' => 'Нет показания счётчика MTR-1 за период.',
            'context' => ['meter_id' => 42, 'meter_number' => 'MTR-1'],
        ]);

    BillingPeriodClosureError::factory()
        ->for($organization)
        ->for($billingPeriod)
        ->create([
            'account_number' => '700002',
            'client_name' => 'Петров Пётр',
            'billing_type' => 'fixed',
            'code' => 'missing_fixed_amount',
            'message' => 'Не указана фиксированная сумма.',
            'context' => null,
        ]);

    $download = Livewire::test(ListBillingPeriodClosureErrors::class, ['record' => $billingPeriod->getKey()])
        ->assertOk()
        ->assertActionExists('downloadExcel')
        ->assertActionHasLabel('downloadExcel', 'Скачать Excel')
        ->callAction('downloadExcel')
        ->assertFileDownloaded(
            'billing-period-closure-errors-'.$organization->getKey().'-202605-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $rows = closureErrorReportXlsxRows($download->effects['download']);

    expect($rows[0])->toBe([
        'Лицевой счёт',
        'ФИО',
        'Тип начисления',
        'Код ошибки',
        'Причина',
        'Контекст',
    ])
        ->and($rows[1])->toBe([
            '700001',
            'Иванов Иван',
            'По счётчику',
            'missing_meter_reading',
            'Нет показания счётчика MTR-1 за период.',
            'meter_id: 42; meter_number: MTR-1',
        ])
        ->and($rows[2])->toBe([
            '700002',
            'Петров Пётр',
            'Фиксированная сумма',
            'missing_fixed_amount',
            'Не указана фиксированная сумма.',
            '',
        ]);
});

test('closure error report is closed for organization members without management rights', function () {
    $organization = Organization::factory()->create();
    actingAsClosureErrorReportTenant($organization, OrganizationMemberRole::Controller);

    $billingPeriod = failedBillingPeriodFor($organization);

    BillingPeriodClosureError::factory()
        ->for($organization)
        ->for($billingPeriod)
        ->create();

    Livewire::test(ListBillingPeriodClosureErrors::class, ['record' => $billingPeriod->getKey()])
        ->assertForbidden();
});
