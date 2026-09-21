<?php

use App\Actions\BuildReceiptMeterReadingLines;
use App\Actions\CloseBillingMonth;
use App\BalanceAdjustmentType;
use App\Filament\Resources\Receipts\Pages\ListReceipts;
use App\Models\Accrual;
use App\Models\BalanceAdjustment;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\ReceiptTemplate;
use App\Models\Region;
use App\Models\Street;
use App\Models\Tariff;
use App\Models\User;
use App\Models\UtilityService;
use App\OrganizationMemberRole;
use App\Support\ReceiptPrintSelection;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsReceiptTenant(Organization $organization): User
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
 * @param  array<string, mixed>  $clientAttributes
 * @param  array<string, mixed>  $readingAttributes
 * @param  array<string, mixed>  $utilityServiceAttributes
 * @param  array<string, mixed>  $meterAttributes
 */
function createReceiptFromMeterReading(
    Organization $organization,
    array $clientAttributes = [],
    array $readingAttributes = [],
    array $utilityServiceAttributes = [],
    array $meterAttributes = [],
): Receipt {
    $period = (string) ($readingAttributes['period'] ?? '202605');
    $previousReading = (float) ($readingAttributes['previous_reading'] ?? 100);

    $utilityService = $organization->utilityService()->first();

    if ($utilityService) {
        $utilityService->fill($utilityServiceAttributes)->save();
    } else {
        $utilityService = UtilityService::factory()->for($organization)->create(array_replace([
            'name' => 'Водоснабжение',
            'unit_of_measurement' => 'м3',
        ], $utilityServiceAttributes));
    }

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create(array_replace([
            'account_number' => fake()->unique()->numerify('######'),
            'billing_type' => 'meter',
        ], $clientAttributes));

    Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'client_type' => 'individual',
            'unit_price' => 90,
            'starts_on' => '2026-05-01',
            'status' => 'active',
        ]);

    $meter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($client)
        ->create(array_replace([
            'initial_reading' => $previousReading,
        ], $meterAttributes));

    MeterReading::factory()
        ->for($meter)
        ->create(array_replace([
            'period' => $period,
            'previous_reading' => $previousReading,
            'current_reading' => $previousReading + 20,
        ], $readingAttributes));

    return Receipt::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($client)
        ->forPeriod($period)
        ->sole();
}

test('meter reading creates a receipt for the client billing period without accrual source', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
    ]);
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '10001',
            'name' => 'Иванов Иван',
            'billing_type' => 'meter',
        ]);
    $meter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($client)
        ->create([
            'initial_reading' => 100,
        ]);

    Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'client_type' => 'individual',
            'unit_price' => 90,
            'starts_on' => '2026-05-01',
            'status' => 'active',
        ]);

    Payment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202605',
            'amount' => 300,
        ]);

    BalanceAdjustment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202605',
            'type' => BalanceAdjustmentType::ManualAdjustment->value,
            'amount' => 50,
        ]);

    MeterReading::factory()
        ->for($meter)
        ->create([
            'period' => '202605',
            'previous_reading' => 100,
            'current_reading' => 120,
        ]);

    $receipt = Receipt::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    expect($receipt->organization->is($organization))->toBeTrue()
        ->and($receipt->client->is($client))->toBeTrue()
        ->and($receipt->accrual_id)->toBeNull()
        ->and($receipt->receipt_number)->toBe('202605-10001')
        ->and($receipt->period)->toBe('202605')
        ->and($receipt->account_number)->toBe('10001')
        ->and($receipt->client_name)->toBe('Иванов Иван')
        ->and($receipt->utility_service_name)->toBe('Водоснабжение')
        ->and($receipt->billing_type)->toBe('meter')
        ->and($receipt->volume)->toBe('20.0000')
        ->and($receipt->tariff_price)->toBe('90.00')
        ->and($receipt->amount)->toBe('1800.00')
        ->and($receipt->paid_amount)->toBe('300.00')
        ->and($receipt->adjustment_amount)->toBe('50.00')
        ->and($receipt->opening_balance)->toBe('0.00')
        ->and($receipt->closing_balance)->toBe('1550.00');
});

test('meter reading receipt shows opening balance adjustments as opening balance', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '10009',
            'billing_type' => 'meter',
        ]);
    $meter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($client)
        ->create([
            'initial_reading' => 100,
        ]);

    Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'client_type' => 'individual',
            'unit_price' => 90,
            'starts_on' => '2026-05-01',
            'status' => 'active',
        ]);

    Payment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202605',
            'amount' => 300,
        ]);

    BalanceAdjustment::factory()
        ->count(2)
        ->for($organization)
        ->for($client)
        ->sequence(
            [
                'period' => '202605',
                'type' => BalanceAdjustmentType::OpeningBalance->value,
                'amount' => 50,
            ],
            [
                'period' => '202605',
                'type' => BalanceAdjustmentType::ManualAdjustment->value,
                'amount' => -20,
            ],
        )
        ->create();

    MeterReading::factory()
        ->for($meter)
        ->create([
            'period' => '202605',
            'previous_reading' => 100,
            'current_reading' => 120,
        ]);

    $receipt = Receipt::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    expect($receipt->amount)->toBe('1800.00')
        ->and($receipt->paid_amount)->toBe('300.00')
        ->and($receipt->adjustment_amount)->toBe('-20.00')
        ->and($receipt->opening_balance)->toBe('50.00')
        ->and($receipt->closing_balance)->toBe('1530.00');
});

test('updating a meter reading updates the same receipt', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '10002',
            'billing_type' => 'meter',
        ]);
    $meter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($client)
        ->create([
            'initial_reading' => 100,
        ]);

    Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'client_type' => 'individual',
            'unit_price' => 90,
            'starts_on' => '2026-05-01',
            'status' => 'active',
        ]);

    $reading = MeterReading::factory()
        ->for($meter)
        ->create([
            'period' => '202605',
            'previous_reading' => 100,
            'current_reading' => 120,
        ]);

    $receipt = Receipt::query()
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    $reading->update([
        'current_reading' => 130,
    ]);

    $updatedReceipt = Receipt::query()
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    expect($updatedReceipt->is($receipt))->toBeTrue()
        ->and($updatedReceipt->volume)->toBe('30.0000')
        ->and($updatedReceipt->amount)->toBe('2700.00')
        ->and($updatedReceipt->closing_balance)->toBe('2700.00')
        ->and(Receipt::query()->whereBelongsTo($client)->forPeriod('202605')->count())->toBe(1);
});

test('payment changes refresh an existing current period receipt', function () {
    $organization = Organization::factory()->create();
    $receipt = createReceiptFromMeterReading(
        $organization,
        [
            'account_number' => '10005',
        ],
        [
            'period' => '202605',
            'previous_reading' => 100,
            'current_reading' => 120,
        ],
    );
    $client = $receipt->client;

    $firstPayment = Payment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202605',
            'amount' => 300,
        ]);

    $receipt->refresh();

    expect($receipt->paid_amount)->toBe('300.00')
        ->and($receipt->closing_balance)->toBe('1500.00');

    $secondPayment = Payment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202605',
            'amount' => 200,
        ]);

    $receipt->refresh();

    expect($receipt->paid_amount)->toBe('500.00')
        ->and($receipt->closing_balance)->toBe('1300.00');

    $firstPayment->update([
        'amount' => 450,
    ]);

    $receipt->refresh();

    expect($receipt->paid_amount)->toBe('650.00')
        ->and($receipt->closing_balance)->toBe('1150.00');

    $secondPayment->delete();

    $receipt->refresh();

    expect($receipt->paid_amount)->toBe('450.00')
        ->and($receipt->closing_balance)->toBe('1350.00');
});

test('multiple meter readings for the same client period update one receipt', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '10003',
            'billing_type' => 'meter',
        ]);
    $firstMeter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($client)
        ->create([
            'initial_reading' => 100,
        ]);
    $secondMeter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($client)
        ->create([
            'initial_reading' => 50,
        ]);

    Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'client_type' => 'individual',
            'unit_price' => 90,
            'starts_on' => '2026-05-01',
            'status' => 'active',
        ]);

    MeterReading::factory()
        ->for($firstMeter)
        ->create([
            'period' => '202605',
            'previous_reading' => 100,
            'current_reading' => 120,
        ]);

    $receipt = Receipt::query()
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    MeterReading::factory()
        ->for($secondMeter)
        ->create([
            'period' => '202605',
            'previous_reading' => 50,
            'current_reading' => 55,
        ]);

    $updatedReceipt = Receipt::query()
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    expect($updatedReceipt->is($receipt))->toBeTrue()
        ->and($updatedReceipt->volume)->toBe('25.0000')
        ->and($updatedReceipt->amount)->toBe('2250.00')
        ->and(Receipt::query()->whereBelongsTo($client)->forPeriod('202605')->count())->toBe(1);
});

test('receipt meter lines include each meter reading for the period', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '10004',
            'billing_type' => 'meter',
        ]);
    $firstMeter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($client)
        ->create([
            'number' => 'MTR-10004-A',
            'initial_reading' => 100,
        ]);
    $secondMeter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($client)
        ->create([
            'number' => 'MTR-10004-B',
            'initial_reading' => 50,
        ]);

    Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'client_type' => 'individual',
            'unit_price' => 90,
            'starts_on' => '2026-05-01',
            'status' => 'active',
        ]);

    MeterReading::factory()
        ->for($firstMeter)
        ->create([
            'period' => '202605',
            'previous_reading' => 100,
            'current_reading' => 120,
        ]);
    MeterReading::factory()
        ->for($secondMeter)
        ->create([
            'period' => '202605',
            'previous_reading' => 50,
            'current_reading' => 55,
        ]);

    $receipt = Receipt::query()
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    $lines = app(BuildReceiptMeterReadingLines::class)->handle($receipt);

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toMatchArray([
            'meter_number' => 'MTR-10004-A',
            'previous_reading' => '100',
            'current_reading' => '120',
            'consumption' => '20',
            'tariff_price' => '90.00 KZT',
            'amount' => '1 800.00 KZT',
        ])
        ->and($lines[1])->toMatchArray([
            'meter_number' => 'MTR-10004-B',
            'previous_reading' => '50',
            'current_reading' => '55',
            'consumption' => '5',
            'tariff_price' => '90.00 KZT',
            'amount' => '450.00 KZT',
        ]);
});

test('billing month closure creates accruals without creating receipts', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Вывоз мусора',
    ]);
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '20001',
            'name' => 'ТОО Дала',
            'billing_type' => 'fixed',
            'fixed_amount' => 7500,
        ]);

    BalanceAdjustment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202605',
            'type' => BalanceAdjustmentType::OpeningBalance->value,
            'amount' => 500,
        ]);

    $firstSummary = app(CloseBillingMonth::class)->handle($organization, '202605');

    expect(fn () => app(CloseBillingMonth::class)->handle($organization, '202605'))
        ->toThrow(InvalidArgumentException::class, 'Расчётный месяц уже закрыт.');

    $accrual = Accrual::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    expect($firstSummary)->toMatchArray([
        'created' => 1,
        'skipped' => 0,
        'failed' => 0,
    ])
        ->and($accrual->account_number)->toBe('20001')
        ->and($accrual->client_name)->toBe('ТОО Дала')
        ->and($accrual->utility_service_name)->toBe('Вывоз мусора')
        ->and($accrual->amount)->toBe('7500.00')
        ->and($accrual->adjustment_amount)->toBe('0.00')
        ->and($accrual->opening_balance)->toBe('500.00')
        ->and($accrual->closing_balance)->toBe('8000.00')
        ->and(Receipt::query()->whereBelongsTo($client)->forPeriod('202605')->count())->toBe(0);
});

test('receipt resource list page is registered with a bulk print action', function () {
    $organization = Organization::factory()->create();
    $region = Region::factory()->for($organization)->create([
        'name' => 'Север',
    ]);
    $street = Street::factory()
        ->for($organization)
        ->for($region)
        ->create([
            'name' => 'Абая',
        ]);
    $receipt = createReceiptFromMeterReading($organization, [
        'account_number' => '30001',
        'name' => 'Иванов Иван',
        'region_id' => $region->getKey(),
        'street_id' => $street->getKey(),
    ]);
    $otherReceipt = createReceiptFromMeterReading($organization, [
        'account_number' => '30002',
        'name' => 'Петров Петр',
    ]);
    $controller = User::factory()->create([
        'name' => 'Контроллер Север',
        'email' => 'controller@example.com',
    ]);
    $controller->organizations()->attach($organization, [
        'role' => OrganizationMemberRole::Controller->value,
    ]);
    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->getKey(),
        'user_id' => $controller->getKey(),
        'region_id' => $region->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $this->get('/admin/'.$organization->getKey().'/receipts')
        ->assertSuccessful()
        ->assertSeeText('Квитанции')
        ->assertSeeText('30001')
        ->assertSeeText('Иванов Иван');

    $this->get('/admin/'.$organization->getKey().'/receipts/'.$receipt->getRouteKey())->assertNotFound();

    Livewire::test(ListReceipts::class)
        ->assertCanSeeTableRecords([$receipt, $otherReceipt])
        ->assertTableActionHidden('printFiltered')
        ->filterTable('billing_period_id', $receipt->billing_period_id)
        ->assertTableActionVisible('printFiltered')
        ->assertTableActionHasUrl('printFiltered', route('filament.admin.receipts.print-bulk', [
            'tenant' => $organization,
            'billing_period_id' => $receipt->billing_period_id,
        ]))
        ->assertTableActionShouldOpenUrlInNewTab('printFiltered')
        ->resetTableFilters()
        ->filterTable('region_id', $region->getKey())
        ->assertCanSeeTableRecords([$receipt])
        ->assertCanNotSeeTableRecords([$otherReceipt])
        ->assertTableActionVisible('printFiltered')
        ->assertTableActionHasUrl('printFiltered', route('filament.admin.receipts.print-bulk', [
            'tenant' => $organization,
            'region_id' => $region->getKey(),
        ]))
        ->resetTableFilters()
        ->filterTable('street_id', $street->getKey())
        ->assertCanSeeTableRecords([$receipt])
        ->assertCanNotSeeTableRecords([$otherReceipt])
        ->assertTableActionVisible('printFiltered')
        ->assertTableActionHasUrl('printFiltered', route('filament.admin.receipts.print-bulk', [
            'tenant' => $organization,
            'street_id' => $street->getKey(),
        ]))
        ->resetTableFilters()
        ->filterTable('controller_id', $controller->getKey())
        ->assertCanSeeTableRecords([$receipt])
        ->assertCanNotSeeTableRecords([$otherReceipt])
        ->assertTableActionVisible('printFiltered')
        ->assertTableActionHasUrl('printFiltered', route('filament.admin.receipts.print-bulk', [
            'tenant' => $organization,
            'controller_id' => $controller->getKey(),
        ]))
        ->assertTableActionDoesNotExist('view')
        ->assertTableActionHasUrl('print', route('filament.admin.receipts.print', [
            'tenant' => $organization,
            'receipt' => $receipt,
        ]), $receipt)
        ->assertTableActionShouldOpenUrlInNewTab('print', $receipt)
        ->assertTableBulkActionExists('printSelected')
        ->assertTableBulkActionHasLabel('printSelected', 'Печатать выбранные')
        ->assertTableBulkActionHasIcon('printSelected', Heroicon::OutlinedPrinter);
});

test('receipt resource list filters receipts with a positive amount due', function () {
    $organization = Organization::factory()->create();
    $positiveReceipt = createReceiptFromMeterReading($organization, [
        'account_number' => '30003',
        'name' => 'Положительный долг',
    ]);
    $zeroReceipt = createReceiptFromMeterReading($organization, [
        'account_number' => '30004',
        'name' => 'Нулевой долг',
    ]);
    $zeroReceipt->update(['closing_balance' => 0]);
    $negativeReceipt = createReceiptFromMeterReading($organization, [
        'account_number' => '30005',
        'name' => 'Переплата',
    ]);
    $negativeReceipt->update(['closing_balance' => -100]);

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    Livewire::test(ListReceipts::class)
        ->assertTableFilterExists('amount_due_positive')
        ->assertCanSeeTableRecords([$positiveReceipt, $zeroReceipt, $negativeReceipt])
        ->assertTableActionHidden('printFiltered')
        ->filterTable('amount_due_positive')
        ->assertCanSeeTableRecords([$positiveReceipt])
        ->assertCanNotSeeTableRecords([$zeroReceipt, $negativeReceipt])
        ->assertTableActionHidden('printFiltered')
        ->filterTable('billing_period_id', $positiveReceipt->billing_period_id)
        ->assertTableActionVisible('printFiltered')
        ->assertTableActionHasUrl('printFiltered', route('filament.admin.receipts.print-bulk', [
            'tenant' => $organization,
            'billing_period_id' => $positiveReceipt->billing_period_id,
            'amount_due_positive' => 1,
        ]));
});

test('admin users can open a current tenant receipt print view', function () {
    $organization = Organization::factory()->create([
        'name' => 'ТОО Водоканал',
        'bin_iin' => '123456789012',
        'address' => 'Алматы, Абая 10',
    ]);
    $receipt = createReceiptFromMeterReading(
        $organization,
        [
            'account_number' => '100010',
            'name' => 'Иванов Иван',
        ],
        [
            'period' => '202605',
            'previous_reading' => 100,
            'current_reading' => 120,
        ],
        [
            'name' => 'Водоснабжение',
            'unit_of_measurement' => 'м3',
        ],
        [
            'number' => 'MTR-100010',
        ],
    );

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $response = $this->get(route('filament.admin.receipts.print', [
        'tenant' => $organization,
        'receipt' => $receipt,
    ]));

    $response
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Content-Disposition')
        ->assertViewIs('receipts.print')
        ->assertViewHasAll([
            'receipt',
            'generatedAt',
            'copiesPerPage',
            'renderedCopies',
            'templateCss',
        ])
        ->assertSeeTextInOrder([
            'Для организации',
            'Квитанция на оплату коммунальной услуги',
            'ТОО Водоканал',
            'Номер',
            '202605-100010',
            'Лицевой счёт',
            '100010',
            'Иванов Иван',
            'Водоснабжение',
            'Счётчики',
            '№ счётчика',
            'MTR-100010',
            '100',
            '120',
            '20',
            '90.00 KZT',
            '1 800.00 KZT',
            'Итого',
            '20',
            '1 800.00 KZT',
            'Долг',
            '0.00 KZT',
            'Оплачено',
            '0.00 KZT',
            'К оплате',
            '1 800.00 KZT',
            'Для абонента',
            'Квитанция на оплату коммунальной услуги',
            'ТОО Водоканал',
            'Номер',
            '202605-100010',
            'Лицевой счёт',
            '100010',
            'Иванов Иван',
            'Водоснабжение',
            'Счётчики',
            '№ счётчика',
            'MTR-100010',
            '100',
            '120',
            '20',
            '90.00 KZT',
            '1 800.00 KZT',
            'Итого',
            '20',
            '1 800.00 KZT',
            'Долг',
            '0.00 KZT',
            'Оплачено',
            '0.00 KZT',
            'К оплате',
            '1 800.00 KZT',
        ])
        ->assertDontSeeText('Расчёт')
        ->assertDontSeeText('Начислено')
        ->assertDontSeeText('Подпись');

    $content = $response->getContent();

    expect(str_starts_with($content, '%PDF'))->toBeFalse()
        ->and(substr_count($content, 'data-receipt-copy='))->toBe(2)
        ->and($content)->toContain('receipt-sheet');
});

test('admin users can open a current tenant bulk receipt print view for selected receipts', function () {
    $organization = Organization::factory()->create([
        'name' => 'ТОО Водоканал',
    ]);
    $firstReceipt = createReceiptFromMeterReading($organization, [
        'account_number' => '100010',
        'name' => 'Иванов Иван',
    ]);
    $secondReceipt = createReceiptFromMeterReading($organization, [
        'account_number' => '100011',
        'name' => 'Петров Петр',
    ]);
    createReceiptFromMeterReading(Organization::factory()->create(), [
        'account_number' => '900010',
        'name' => 'Чужой абонент',
    ]);

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $response = $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'selection' => ReceiptPrintSelection::store($user, $organization, [
            $secondReceipt->getKey(),
            $firstReceipt->getKey(),
        ]),
    ]));

    $response
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertViewIs('receipts.bulk-print')
        ->assertViewHasAll([
            'periodLabel',
            'receiptPrintData',
        ])
        ->assertSeeTextInOrder([
            'Массовая печать квитанций',
            'Квитанций: 2',
            '202605-100010',
            'Иванов Иван',
            '202605-100011',
            'Петров Петр',
        ])
        ->assertDontSeeText('Чужой абонент');

    $content = $response->getContent();

    expect(substr_count($content, 'data-receipt-copy='))->toBe(4)
        ->and(substr_count($content, 'class="receipt-a4-page"'))->toBe(1)
        ->and(substr_count($content, 'class="receipt-a4-cell"'))->toBe(4)
        ->and($secondReceipt->billing_period_id)->toBe($firstReceipt->billing_period_id);
});

test('bulk receipt print lays out up to eight copies per A4 page without splitting a receipt', function () {
    $organization = Organization::factory()->create();
    $receipts = collect(range(0, 4))->map(fn (int $index): Receipt => createReceiptFromMeterReading($organization, [
        'account_number' => (string) (100020 + $index),
        'name' => "Абонент {$index}",
    ]));

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $response = $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'selection' => ReceiptPrintSelection::store($user, $organization, $receipts->map->getKey()),
    ]));

    $response
        ->assertSuccessful()
        ->assertSeeText('Листов A4: 2, до 8 экземпляров на листе.');

    $pages = collect($response->viewData('printPages'));

    expect($pages)->toHaveCount(2)
        ->and($pages->map(fn (array $pageCopies): int => count($pageCopies))->all())->toBe([8, 2])
        ->and(collect($pages->first())->pluck('copyTitle')->all())->toBe([
            'Для организации', 'Для абонента',
            'Для организации', 'Для абонента',
            'Для организации', 'Для абонента',
            'Для организации', 'Для абонента',
        ])
        ->and(substr_count($response->getContent(), 'class="receipt-a4-page"'))->toBe(2)
        ->and(substr_count($response->getContent(), 'data-receipt-copy='))->toBe(10);
});

test('bulk receipt print fits eight single-copy receipts on one A4 page', function () {
    $organization = Organization::factory()->create();
    ReceiptTemplate::factory()->for($organization)->create(['copies_per_page' => 1]);
    $receipts = collect(range(0, 8))->map(fn (int $index): Receipt => createReceiptFromMeterReading($organization, [
        'account_number' => (string) (100040 + $index),
    ]));

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $pages = collect($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'selection' => ReceiptPrintSelection::store($user, $organization, $receipts->map->getKey()),
    ]))->assertSuccessful()->viewData('printPages'));

    expect($pages->map(fn (array $pageCopies): int => count($pageCopies))->all())->toBe([8, 1]);
});

test('admin users can open a current tenant bulk receipt print view for a billing period', function () {
    $organization = Organization::factory()->create([
        'name' => 'ТОО Водоканал',
    ]);
    $firstReceipt = createReceiptFromMeterReading($organization, [
        'account_number' => '100010',
        'name' => 'Иванов Иван',
    ]);
    createReceiptFromMeterReading($organization, [
        'account_number' => '100011',
        'name' => 'Петров Петр',
    ]);

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $firstReceipt->billing_period_id,
    ]))
        ->assertSuccessful()
        ->assertViewIs('receipts.bulk-print')
        ->assertViewHasAll([
            'periodLabel',
            'receiptPrintData',
        ])
        ->assertSeeText('Квитанций: 2');
});

test('bulk receipt print filters receipts with a positive amount due', function () {
    $organization = Organization::factory()->create();
    $positiveReceipt = createReceiptFromMeterReading($organization, [
        'account_number' => '100012',
        'name' => 'Положительный долг',
    ]);
    $zeroReceipt = createReceiptFromMeterReading($organization, [
        'account_number' => '100013',
        'name' => 'Нулевой долг',
    ]);
    $zeroReceipt->update(['closing_balance' => 0]);
    $negativeReceipt = createReceiptFromMeterReading($organization, [
        'account_number' => '100014',
        'name' => 'Переплата',
    ]);
    $negativeReceipt->update(['closing_balance' => -100]);

    // Фикстуры чужой организации создаются до установки тенанта: после
    // Filament::setTenant() фабрики принудительно проставляют его organization_id.
    $otherOrganization = Organization::factory()->create();
    $foreignReceipt = createReceiptFromMeterReading($otherOrganization, [
        'account_number' => '90002',
        'name' => 'Чужой положительный долг',
    ]);

    $this->actingAs(actingAsReceiptTenant($organization));

    $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $positiveReceipt->billing_period_id,
        'amount_due_positive' => 1,
    ]))
        ->assertSuccessful()
        ->assertSeeText('Квитанций: 1')
        ->assertSeeText('Положительный долг')
        ->assertDontSeeText('Нулевой долг')
        ->assertDontSeeText('Переплата')
        ->assertDontSeeText($foreignReceipt->client_name);
});

test('bulk receipt print requires a scope filter when filtering by a positive amount due', function () {
    $organization = Organization::factory()->create();
    createReceiptFromMeterReading($organization, [
        'account_number' => '100015',
        'name' => 'Положительный долг',
    ]);

    $this->actingAs(actingAsReceiptTenant($organization));

    $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'amount_due_positive' => 1,
    ]))->assertNotFound();
});

test('bulk receipt print includes every receipt when the positive amount due filter is omitted', function () {
    $organization = Organization::factory()->create();
    $positiveReceipt = createReceiptFromMeterReading($organization, [
        'account_number' => '100016',
        'name' => 'Положительный долг',
    ]);
    $zeroReceipt = createReceiptFromMeterReading($organization, [
        'account_number' => '100017',
        'name' => 'Нулевой долг',
    ]);
    $zeroReceipt->update(['closing_balance' => 0]);
    $negativeReceipt = createReceiptFromMeterReading($organization, [
        'account_number' => '100018',
        'name' => 'Переплата',
    ]);
    $negativeReceipt->update(['closing_balance' => -100]);

    $this->actingAs(actingAsReceiptTenant($organization));

    $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $positiveReceipt->billing_period_id,
    ]))
        ->assertSuccessful()
        ->assertSeeText('Квитанций: 3')
        ->assertSeeText('Положительный долг')
        ->assertSeeText('Нулевой долг')
        ->assertSeeText('Переплата');
});

test('admin users can open a current tenant bulk receipt print view for address and controller filters', function () {
    $organization = Organization::factory()->create([
        'name' => 'ТОО Водоканал',
    ]);
    $assignedRegion = Region::factory()->for($organization)->create([
        'name' => 'Север',
    ]);
    $assignedStreet = Street::factory()
        ->for($organization)
        ->for($assignedRegion)
        ->create([
            'name' => 'Абая',
        ]);
    $otherRegion = Region::factory()->for($organization)->create([
        'name' => 'Юг',
    ]);
    $otherStreet = Street::factory()
        ->for($organization)
        ->for($otherRegion)
        ->create([
            'name' => 'Сатпаева',
        ]);

    createReceiptFromMeterReading($organization, [
        'account_number' => '100010',
        'name' => 'Иванов Иван',
        'region_id' => $assignedRegion->getKey(),
        'street_id' => $assignedStreet->getKey(),
    ]);
    createReceiptFromMeterReading($organization, [
        'account_number' => '100011',
        'name' => 'Петров Петр',
        'region_id' => $otherRegion->getKey(),
        'street_id' => $otherStreet->getKey(),
    ]);

    $controller = User::factory()->create();
    $controller->organizations()->attach($organization, [
        'role' => OrganizationMemberRole::Controller->value,
    ]);
    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->getKey(),
        'user_id' => $controller->getKey(),
        'region_id' => $assignedRegion->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'region_id' => $assignedRegion->getKey(),
    ]))
        ->assertSuccessful()
        ->assertSeeText('Квитанций: 1')
        ->assertSeeText('Иванов Иван')
        ->assertDontSeeText('Петров Петр');

    $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'street_id' => $assignedStreet->getKey(),
    ]))
        ->assertSuccessful()
        ->assertSeeText('Квитанций: 1')
        ->assertSeeText('Иванов Иван')
        ->assertDontSeeText('Петров Петр');

    $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'controller_id' => $controller->getKey(),
    ]))
        ->assertSuccessful()
        ->assertSeeText('Квитанций: 1')
        ->assertSeeText('Иванов Иван')
        ->assertDontSeeText('Петров Петр');
});

test('admin users see an empty bulk receipt print view when a tenant period has no receipts', function () {
    $organization = Organization::factory()->create();
    $billingPeriod = $organization->billingPeriods()->create([
        'starts_on' => '2026-05-01',
        'status' => 'open',
        'opened_at' => now(),
    ]);

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $billingPeriod,
    ]))
        ->assertSuccessful()
        ->assertViewIs('receipts.bulk-print')
        ->assertSeeText('Нет квитанций для печати')
        ->assertDontSee('window.print()');
});

test('admin users cannot open another tenant bulk receipt print view', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $receipt = createReceiptFromMeterReading($otherOrganization, [
        'account_number' => '90001',
    ]);

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $receipt->billing_period_id,
    ]))->assertNotFound();

    $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'selection' => ReceiptPrintSelection::store($user, $organization, [$receipt->getKey()]),
    ]))->assertNotFound();
});

test('print selected bulk action opens bulk print of every selected receipt through a selection token', function () {
    $organization = Organization::factory()->create();
    $receipts = collect(range(0, 10))->map(fn (int $index): Receipt => createReceiptFromMeterReading($organization, [
        'account_number' => (string) (100060 + $index),
        'name' => "Абонент {$index}",
    ]));
    $selectedReceipts = $receipts->take(10);

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $component = Livewire::test(ListReceipts::class)
        ->callTableBulkAction('printSelected', $selectedReceipts)
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('Печать выбранных квитанций открыта в новой вкладке');

    $windowOpenExpressions = collect($component->effects['xjs'] ?? [])
        ->pluck('expression')
        ->filter(fn (string $expression): bool => str_starts_with($expression, 'window.open('));

    expect($windowOpenExpressions)->toHaveCount(1)
        ->and($windowOpenExpressions->first())->toContain("'_blank'")
        ->and($windowOpenExpressions->first())->not->toContain('receipt_ids');

    preg_match('/[?&]selection=([A-Za-z0-9]{40})/', $windowOpenExpressions->first(), $matches);
    $token = $matches[1] ?? null;

    expect($token)->not->toBeNull()
        ->and(ReceiptPrintSelection::resolve($token, $user, $organization)?->sort()->values()->all())
        ->toBe($selectedReceipts->map->getKey()->sort()->values()->all());

    $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'selection' => $token,
    ]))
        ->assertSuccessful()
        ->assertSeeText('Квитанций: 10')
        ->assertSeeText('Абонент 9')
        ->assertDontSeeText('Абонент 10');
});

test('bulk receipt print rejects a selection token of another user or another organization', function () {
    // Фикстуры чужой организации создаются до установки тенанта: после
    // Filament::setTenant() фабрики принудительно проставляют его organization_id.
    $otherOrganization = Organization::factory()->create();
    $foreignReceipt = createReceiptFromMeterReading($otherOrganization, [
        'account_number' => '90003',
    ]);
    $organization = Organization::factory()->create();
    $receipt = createReceiptFromMeterReading($organization, [
        'account_number' => '100070',
    ]);
    $otherUser = User::factory()->create();
    $otherUser->organizations()->attach($organization);

    $user = actingAsReceiptTenant($organization);
    $user->organizations()->attach($otherOrganization);
    $token = ReceiptPrintSelection::store($user, $organization, [$receipt->getKey()]);
    $foreignToken = ReceiptPrintSelection::store($user, $otherOrganization, [$foreignReceipt->getKey()]);

    $this->actingAs($user)
        ->get(route('filament.admin.receipts.print-bulk', [
            'tenant' => $organization,
            'selection' => $token,
        ]))
        ->assertSuccessful()
        ->assertSeeText('Квитанций: 1');

    $this->actingAs($user)
        ->get(route('filament.admin.receipts.print-bulk', [
            'tenant' => $otherOrganization,
            'selection' => $token,
        ]))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('filament.admin.receipts.print-bulk', [
            'tenant' => $organization,
            'selection' => $foreignToken,
        ]))
        ->assertNotFound();

    $this->actingAs($otherUser)
        ->get(route('filament.admin.receipts.print-bulk', [
            'tenant' => $organization,
            'selection' => $token,
        ]))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('filament.admin.receipts.print-bulk', [
            'tenant' => $organization,
            'selection' => str_repeat('a', 40),
        ]))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('filament.admin.receipts.print-bulk', [
            'tenant' => $organization,
            'receipt_ids' => [$receipt->getKey()],
        ]))
        ->assertNotFound();
});

test('admin users cannot open another tenant receipt print view', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $receipt = createReceiptFromMeterReading($otherOrganization, [
        'account_number' => '90001',
    ]);

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $this->get(route('filament.admin.receipts.print', [
        'tenant' => $organization,
        'receipt' => $receipt,
    ]))->assertNotFound();
});
