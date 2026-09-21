<?php

use App\Actions\BuildReceiptMeterReadingLines;
use App\Actions\BuildReceiptPrintViewData;
use App\Actions\CloseBillingMonth;
use App\BalanceAdjustmentType;
use App\Filament\Resources\Receipts\Pages\ListReceipts;
use App\Http\Controllers\ReceiptPrintController;
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
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
 * Токен выбора из `window.open(...)`, который bulk-действие «Печатать выбранные»
 * отправляет в браузер; null, если печать не открывалась.
 */
function openedReceiptPrintSelectionToken(Testable $component): ?string
{
    $windowOpenExpressions = collect($component->effects['xjs'] ?? [])
        ->pluck('expression')
        ->filter(fn (string $expression): bool => str_starts_with($expression, 'window.open('));

    if ($windowOpenExpressions->isEmpty()) {
        return null;
    }

    expect($windowOpenExpressions)->toHaveCount(1)
        ->and($windowOpenExpressions->first())->toContain("'_blank'")
        ->and($windowOpenExpressions->first())->not->toContain('receipt_ids');

    preg_match('/[?&]selection=([A-Za-z0-9]{40})/', $windowOpenExpressions->first(), $matches);

    return $matches[1] ?? null;
}

/**
 * Массовая печать отдаётся потоком; ответ пересобирается в обычный,
 * чтобы проверять содержимое стандартными assert-методами.
 */
function bulkPrintResponse(TestResponse $response): TestResponse
{
    if (! $response->baseResponse instanceof StreamedResponse) {
        return $response;
    }

    return TestResponse::fromBaseResponse(new Response(
        $response->streamedContent(),
        $response->getStatusCode(),
        $response->headers->all(),
    ));
}

/**
 * Листы A4 массовой печати: названия экземпляров на каждом листе.
 *
 * @return list<list<string>>
 */
function bulkPrintPages(string $content): array
{
    return collect(explode('<section class="receipt-a4-page">', $content))
        ->skip(1)
        ->map(function (string $page): array {
            preg_match_all('/data-receipt-copy="([^"]+)"/', $page, $matches);

            return $matches[1];
        })
        ->values()
        ->all();
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

test('batched receipt meter lines match per receipt meter lines', function () {
    $organization = Organization::factory()->create();
    $firstReceipt = createReceiptFromMeterReading($organization, ['account_number' => '100080'], [
        'previous_reading' => 100,
        'current_reading' => 130,
    ]);
    $secondReceipt = createReceiptFromMeterReading($organization, ['account_number' => '100081'], [
        'previous_reading' => 40,
        'current_reading' => 45,
    ]);
    $receiptWithoutClient = Receipt::factory()->for($organization)->make([
        'client_id' => null,
        'billing_period_id' => $firstReceipt->billing_period_id,
    ]);
    $receiptWithoutClient->id = 999999;

    $buildReceiptMeterReadingLines = app(BuildReceiptMeterReadingLines::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $batchedLines = $buildReceiptMeterReadingLines->handleMany(collect([$firstReceipt, $secondReceipt, $receiptWithoutClient]));

    $batchedQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($batchedQueryCount)->toBe(2)
        ->and($batchedLines)->toBe([
            $firstReceipt->getKey() => $buildReceiptMeterReadingLines->handle($firstReceipt),
            $secondReceipt->getKey() => $buildReceiptMeterReadingLines->handle($secondReceipt),
            999999 => [],
        ])
        ->and($batchedLines[$firstReceipt->getKey()][0]['consumption'])->toBe('30')
        ->and($batchedLines[$secondReceipt->getKey()][0]['consumption'])->toBe('5');
});

test('batched receipt meter lines load only the readings of each receipt client and period', function () {
    $organization = Organization::factory()->create();
    $firstReceipt = createReceiptFromMeterReading($organization, ['account_number' => '100090']);
    $secondReceipt = createReceiptFromMeterReading($organization, ['account_number' => '100091']);
    $juneBillingPeriodId = DB::table('billing_periods')->insertGetId([
        'organization_id' => $organization->getKey(),
        'starts_on' => '2026-06-01',
        'status' => 'closed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([$firstReceipt, $secondReceipt] as $receipt) {
        $mayReading = (array) DB::table('meter_readings')->where('client_id', $receipt->client_id)->sole();
        unset($mayReading['id']);

        DB::table('meter_readings')->insert(array_replace($mayReading, [
            'billing_period_id' => $juneBillingPeriodId,
            'previous_reading' => $mayReading['current_reading'],
            'current_reading' => $mayReading['current_reading'] + 7,
            'consumption' => 7,
        ]));
    }

    $secondReceiptForJune = $secondReceipt->replicate();
    $secondReceiptForJune->id = 999998;
    $secondReceiptForJune->billing_period_id = $juneBillingPeriodId;

    $loadedMeterReadingsCount = 0;
    MeterReading::retrieved(function () use (&$loadedMeterReadingsCount): void {
        $loadedMeterReadingsCount++;
    });

    $lines = app(BuildReceiptMeterReadingLines::class)->handleMany(collect([$firstReceipt, $secondReceiptForJune]));

    expect($loadedMeterReadingsCount)->toBe(2)
        ->and($lines[$firstReceipt->getKey()])->toHaveCount(1)
        ->and($lines[$firstReceipt->getKey()][0]['consumption'])->toBe('20')
        ->and($lines[999998])->toHaveCount(1)
        ->and($lines[999998][0]['consumption'])->toBe('7');
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

    $response = bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'selection' => ReceiptPrintSelection::store($user, $organization, [
            $secondReceipt->getKey(),
            $firstReceipt->getKey(),
        ]),
    ])));

    $response
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
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

    $response = bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'selection' => ReceiptPrintSelection::store($user, $organization, $receipts->map->getKey()),
    ])));

    $response
        ->assertSuccessful()
        ->assertSeeText('Листов A4: 2, до 8 экземпляров на листе.');

    $pages = collect(bulkPrintPages($response->getContent()));

    expect($pages)->toHaveCount(2)
        ->and($pages->map(fn (array $pageCopies): int => count($pageCopies))->all())->toBe([8, 2])
        ->and($pages->first())->toBe([
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

    $pages = collect(bulkPrintPages(bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'selection' => ReceiptPrintSelection::store($user, $organization, $receipts->map->getKey()),
    ])))->assertSuccessful()->getContent()));

    expect($pages->map(fn (array $pageCopies): int => count($pageCopies))->all())->toBe([8, 1]);
});

test('bulk receipt print loads receipts in chunks without a query per receipt', function () {
    $organization = Organization::factory()->create();
    ReceiptTemplate::factory()->for($organization)->create(['copies_per_page' => 1]);
    $firstReceipt = createReceiptFromMeterReading($organization, ['account_number' => '000001']);
    $billingPeriodId = $firstReceipt->billing_period_id;

    foreach (range(2, 5) as $accountNumber) {
        createReceiptFromMeterReading($organization, ['account_number' => sprintf('%06d', $accountNumber)]);
    }

    $bulkPrintQueryCount = function () use ($organization, $billingPeriodId): array {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $content = bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
            'tenant' => $organization,
            'billing_period_id' => $billingPeriodId,
        ])))->assertSuccessful()->getContent();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queryCount, $content];
    };

    $this->actingAs(actingAsReceiptTenant($organization));

    [$fewReceiptsQueryCount] = $bulkPrintQueryCount();

    $clients = Client::factory()
        ->for($organization)
        ->count(ReceiptPrintController::BULK_PRINT_CHUNK_SIZE)
        ->sequence(fn (Sequence $sequence): array => ['account_number' => sprintf('%06d', $sequence->index + 6)])
        ->create();

    foreach ($clients as $client) {
        Receipt::factory()->for($organization)->for($client)->create([
            'period' => '202605',
            'account_number' => $client->account_number,
            'receipt_number' => "202605-{$client->account_number}",
        ]);
    }

    [$manyReceiptsQueryCount, $content] = $bulkPrintQueryCount();
    $receiptsCount = ReceiptPrintController::BULK_PRINT_CHUNK_SIZE + 5;
    $pages = bulkPrintPages($content);

    preg_match_all('/202605-(\d{6})/', $content, $receiptNumberMatches);
    $printedAccountNumbers = array_values(array_unique($receiptNumberMatches[1]));

    expect($manyReceiptsQueryCount - $fewReceiptsQueryCount)->toBeLessThanOrEqual(10)
        ->and(substr_count($content, 'data-receipt-copy='))->toBe($receiptsCount)
        ->and($printedAccountNumbers)->toBe(collect(range(1, $receiptsCount))->map(fn (int $accountNumber): string => sprintf('%06d', $accountNumber))->all())
        ->and($pages)->toHaveCount((int) ceil($receiptsCount / 8))
        ->and(collect($pages)->map(fn (array $pageCopies): int => count($pageCopies))->unique()->values()->all())->toBe([8, 5])
        ->and($content)->toContain("Квитанций: {$receiptsCount}. Листов A4: ".count($pages).',');
});

test('bulk receipt print refuses a filter selection larger than the print limit', function () {
    config(['receipts.print_selection_limit' => 2]);

    $organization = Organization::factory()->create();
    $receipts = collect(range(0, 2))->map(fn (int $index): Receipt => createReceiptFromMeterReading($organization, [
        'account_number' => (string) (100060 + $index),
        'name' => "Абонент {$index}",
    ]));

    $this->actingAs(actingAsReceiptTenant($organization));

    $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $receipts->first()->billing_period_id,
    ]))
        ->assertUnprocessable()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSeeText('Слишком много квитанций для одной печати')
        ->assertSeeText('Выбрано 3 квитанций, а за один раз можно напечатать не больше 2.')
        ->assertSeeText('Сузьте фильтры')
        ->assertDontSeeText('Абонент 0')
        ->assertDontSee('class="receipt-a4-page"', false)
        ->assertDontSee('window.print()', false);

    config(['receipts.print_selection_limit' => 3]);

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $receipts->first()->billing_period_id,
    ])))
        ->assertSuccessful()
        ->assertSeeText('Квитанций: 3')
        ->assertDontSeeText('Слишком много квитанций');
});

test('bulk receipt print refuses a selection larger than the print limit', function () {
    config(['receipts.print_selection_limit' => 2]);

    $organization = Organization::factory()->create();
    $receipts = collect(range(0, 2))->map(fn (int $index): Receipt => createReceiptFromMeterReading($organization, [
        'account_number' => (string) (100070 + $index),
        'name' => "Абонент {$index}",
    ]));

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'selection' => ReceiptPrintSelection::store($user, $organization, $receipts->map->getKey()),
    ]))
        ->assertUnprocessable()
        ->assertSeeText('Слишком много квитанций для одной печати')
        ->assertSeeText('Выбрано 3 квитанций, а за один раз можно напечатать не больше 2.')
        ->assertDontSeeText('Абонент 0')
        ->assertDontSee('window.print()', false);

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'selection' => ReceiptPrintSelection::store($user, $organization, $receipts->take(2)->map->getKey()),
    ])))
        ->assertSuccessful()
        ->assertSeeText('Квитанций: 2')
        ->assertSeeText('Абонент 0')
        ->assertDontSeeText('Абонент 2');
});

test('bulk receipt print orders receipts with the id as the last sort key', function () {
    $organization = Organization::factory()->create();
    $receipt = createReceiptFromMeterReading($organization, ['account_number' => '100095']);

    $this->actingAs(actingAsReceiptTenant($organization));

    DB::flushQueryLog();
    DB::enableQueryLog();

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $receipt->billing_period_id,
    ])))->assertSuccessful();

    $orderedReceiptQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_contains($query, 'from `receipts`') && str_contains($query, 'order by'));

    DB::disableQueryLog();

    expect($orderedReceiptQueries)->toHaveCount(2)
        ->and($orderedReceiptQueries->every(fn (string $query): bool => str_ends_with(
            $query,
            'order by `account_number` asc, `receipt_number` asc, `receipts`.`id` asc',
        )))->toBeTrue();
});

test('bulk receipt print closes the page with an error state when a chunk fails while streaming', function () {
    $organization = Organization::factory()->create();
    $receipt = createReceiptFromMeterReading($organization, [
        'account_number' => '100096',
        'name' => 'Абонент печати',
    ]);

    $this->actingAs(actingAsReceiptTenant($organization));

    Exceptions::fake();

    $this->app->bind(BuildReceiptPrintViewData::class, fn (): BuildReceiptPrintViewData => new class(app(BuildReceiptMeterReadingLines::class)) extends BuildReceiptPrintViewData
    {
        public function handleMany(Collection $receipts): array
        {
            throw new RuntimeException('Сбой отрисовки квитанций.');
        }
    });

    $content = bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $receipt->billing_period_id,
    ])))
        ->assertSuccessful()
        ->assertSeeText('Печать прервана')
        ->assertSeeText('листы скрыты и не печатаются')
        ->assertDontSeeText('Абонент печати')
        ->getContent();

    expect($content)->not->toContain("window.addEventListener('load'")
        ->and($content)->toContain('.receipt-bulk-print-button')
        ->and($content)->toMatch('/<section class="receipt-bulk-print-error[^"]*\\bprint:hidden\\b[^"]*">/')
        ->and(trim($content))->toEndWith('</html>');

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Сбой отрисовки квитанций.');
});

test('bulk receipt print does not print a receipt moved to another organization while streaming', function () {
    $otherOrganization = Organization::factory()->create();
    $organization = Organization::factory()->create();
    $receipt = createReceiptFromMeterReading($organization, [
        'account_number' => '100097',
        'name' => 'Перенесённый абонент',
    ]);

    $this->actingAs(actingAsReceiptTenant($organization));

    Exceptions::fake();

    $receiptMoved = false;
    DB::listen(function (QueryExecuted $query) use (&$receiptMoved, $receipt, $otherOrganization): void {
        if ($receiptMoved || ! str_starts_with($query->sql, 'select `receipts`.`id` from `receipts`')) {
            return;
        }

        $receiptMoved = true;
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('receipts')->where('id', $receipt->getKey())->update(['organization_id' => $otherOrganization->getKey()]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    });

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $receipt->billing_period_id,
    ])))
        ->assertSuccessful()
        ->assertSeeText('Печать прервана')
        ->assertDontSeeText('Перенесённый абонент')
        ->assertDontSee('data-receipt-copy=', false);

    expect($receiptMoved)->toBeTrue();

    Exceptions::assertReported(RuntimeException::class);
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

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $firstReceipt->billing_period_id,
    ])))
        ->assertSuccessful()
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

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $positiveReceipt->billing_period_id,
        'amount_due_positive' => 1,
    ])))
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

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'amount_due_positive' => 1,
    ])))->assertNotFound();
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

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $positiveReceipt->billing_period_id,
    ])))
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

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'region_id' => $assignedRegion->getKey(),
    ])))
        ->assertSuccessful()
        ->assertSeeText('Квитанций: 1')
        ->assertSeeText('Иванов Иван')
        ->assertDontSeeText('Петров Петр');

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'street_id' => $assignedStreet->getKey(),
    ])))
        ->assertSuccessful()
        ->assertSeeText('Квитанций: 1')
        ->assertSeeText('Иванов Иван')
        ->assertDontSeeText('Петров Петр');

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'controller_id' => $controller->getKey(),
    ])))
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

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $billingPeriod,
    ])))
        ->assertSuccessful()
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

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $receipt->billing_period_id,
    ])))->assertNotFound();

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'selection' => ReceiptPrintSelection::store($user, $organization, [$receipt->getKey()]),
    ])))->assertNotFound();
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
        ->assertNotified('Печать выбранных квитанций открыта в новой вкладке')
        ->assertDispatched('deselectAllTableRecords');

    $token = openedReceiptPrintSelectionToken($component);

    expect($token)->not->toBeNull()
        ->and(ReceiptPrintSelection::resolve($token, $user, $organization)?->sort()->values()->all())
        ->toBe($selectedReceipts->map->getKey()->sort()->values()->all());

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'selection' => $token,
    ])))
        ->assertSuccessful()
        ->assertSeeText('Квитанций: 10')
        ->assertSeeText('Абонент 9')
        ->assertDontSeeText('Абонент 10');
});

test('print selected bulk action prints every receipt of select all across table pages except the deselected ones', function () {
    $organization = Organization::factory()->create();
    $receipts = collect(range(0, 11))->map(function (int $index) use ($organization): Receipt {
        $receipt = createReceiptFromMeterReading($organization, [
            'account_number' => (string) (100080 + $index),
            'name' => "Абонент {$index}",
        ]);
        $receipt->update(['issued_at' => now()->subMinutes($index)]);

        return $receipt;
    });
    $deselectedReceipts = $receipts->only([1, 10]);
    $remainingReceipts = $receipts->except([1, 10]);

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $component = Livewire::test(ListReceipts::class)
        ->set('tableRecordsPerPage', 5)
        ->assertCanSeeTableRecords($receipts->slice(0, 5))
        ->assertCanNotSeeTableRecords($receipts->slice(5))
        ->set('isTrackingDeselectedTableRecords', true)
        ->set('deselectedTableRecords', [(string) $receipts[1]->getKey()])
        ->call('gotoPage', 3)
        ->assertCanSeeTableRecords($receipts->slice(10))
        ->assertCanNotSeeTableRecords($receipts->slice(0, 10))
        ->set('deselectedTableRecords', $deselectedReceipts->map(fn (Receipt $receipt): string => (string) $receipt->getKey())->values()->all())
        ->callTableBulkAction('printSelected', [])
        ->assertHasNoTableBulkActionErrors()
        ->assertDispatched('deselectAllTableRecords');

    $token = openedReceiptPrintSelectionToken($component);

    expect($token)->not->toBeNull()
        ->and(ReceiptPrintSelection::resolve($token, $user, $organization)?->sort()->values()->all())
        ->toBe($remainingReceipts->map->getKey()->sort()->values()->all());

    bulkPrintResponse($this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'selection' => $token,
    ])))
        ->assertSuccessful()
        ->assertSeeText('Квитанций: 10')
        ->assertSeeText('100080')
        ->assertSeeText('100091')
        ->assertDontSeeText('100081')
        ->assertDontSeeText('100090');
});

test('print selected bulk action rejects a selection above the limit and keeps the selection', function () {
    config(['receipts.print_selection_limit' => 2]);

    $organization = Organization::factory()->create();
    $receipts = collect(range(0, 2))->map(fn (int $index): Receipt => createReceiptFromMeterReading($organization, [
        'account_number' => (string) (100090 + $index),
    ]));

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $component = Livewire::test(ListReceipts::class)
        ->callTableBulkAction('printSelected', $receipts)
        ->assertNotified('Выбрано больше 2 квитанций')
        ->assertNotDispatched('deselectAllTableRecords')
        ->assertSet('selectedTableRecords', $receipts->map(fn (Receipt $receipt): string => (string) $receipt->getKey())->all());

    expect(openedReceiptPrintSelectionToken($component))->toBeNull();

    $component = Livewire::test(ListReceipts::class)
        ->set('isTrackingDeselectedTableRecords', true)
        ->callTableBulkAction('printSelected', [])
        ->assertNotified('Выбрано больше 2 квитанций')
        ->assertNotDispatched('deselectAllTableRecords');

    expect(openedReceiptPrintSelectionToken($component))->toBeNull();

    $component = Livewire::test(ListReceipts::class)
        ->callTableBulkAction('printSelected', $receipts->take(2))
        ->assertNotified('Печать выбранных квитанций открыта в новой вкладке')
        ->assertDispatched('deselectAllTableRecords');

    expect(openedReceiptPrintSelectionToken($component))->not->toBeNull();
});

test('bulk receipt print selection token can be reopened by its owner until it expires', function () {
    $organization = Organization::factory()->create();
    $receipt = createReceiptFromMeterReading($organization, [
        'account_number' => '100095',
    ]);

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $printUrl = route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'selection' => ReceiptPrintSelection::store($user, $organization, [$receipt->getKey()]),
    ]);

    bulkPrintResponse($this->get($printUrl))->assertSuccessful()->assertSeeText('Квитанций: 1');
    bulkPrintResponse($this->get($printUrl))->assertSuccessful()->assertSeeText('Квитанций: 1');

    $this->travel(ReceiptPrintSelection::LIFETIME + 1)->seconds();

    $this->get($printUrl)->assertNotFound();
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

    bulkPrintResponse($this->actingAs($user)
        ->get(route('filament.admin.receipts.print-bulk', [
            'tenant' => $organization,
            'selection' => $token,
        ])))
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

test('bulk receipt print returns 404 for array query parameters', function (string $parameter) {
    $organization = Organization::factory()->create();
    $receipt = createReceiptFromMeterReading($organization, [
        'account_number' => '100071',
    ]);

    $user = actingAsReceiptTenant($organization);
    $value = $parameter === 'selection'
        ? ReceiptPrintSelection::store($user, $organization, [$receipt->getKey()])
        : (string) $receipt->billing_period_id;

    $printUrl = route('filament.admin.receipts.print-bulk', ['tenant' => $organization]);

    $this->actingAs($user)
        ->get($printUrl.'?'.$parameter.'[]='.$value)
        ->assertNotFound();

    $this->actingAs($user)
        ->get($printUrl.'?'.$parameter.'[a]='.$value)
        ->assertNotFound();
})->with([
    'selection',
    'billing_period_id',
    'region_id',
    'street_id',
    'controller_id',
    'amount_due_positive',
]);

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
