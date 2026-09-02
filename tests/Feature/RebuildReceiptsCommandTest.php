<?php

use App\BalanceAdjustmentType;
use App\BillingPeriodStatus;
use App\Models\BalanceAdjustment;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Meter;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Tariff;
use App\Models\UtilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * An organization ready to bill one subscriber by meter at 90 per unit.
 *
 * @return array{
 *     organization:Organization, utilityService:UtilityService,
 *     client:Client, meter:Meter, billingPeriod:BillingPeriod
 * }
 */
function meterBillingFixture(string $period = '202608'): array
{
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
        'unit_of_measurement' => 'м3',
    ]);

    Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'client_type' => 'individual',
            'unit_price' => 90,
            'starts_on' => '2026-06-01',
            'status' => 'active',
        ]);

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '7000001',
            'name' => 'Иванов Иван',
            'billing_type' => 'meter',
        ]);

    $meter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($client)
        ->create(['initial_reading' => 100]);

    return [
        'organization' => $organization,
        'utilityService' => $utilityService,
        'client' => $client,
        'meter' => $meter,
        'billingPeriod' => billingPeriodFor($organization, $period),
    ];
}

/**
 * Writes a reading the way a bulk import does: straight into the table, so the
 * `saved` hook of the model never runs and no receipt is created.
 */
function importMeterReading(Meter $meter, BillingPeriod $billingPeriod, float $previousReading, float $currentReading): void
{
    DB::table('meter_readings')->insert([
        'organization_id' => $meter->organization_id,
        'meter_id' => $meter->getKey(),
        'client_id' => $meter->client_id,
        'utility_service_id' => $meter->utility_service_id,
        'billing_period_id' => $billingPeriod->getKey(),
        'previous_reading' => $previousReading,
        'current_reading' => $currentReading,
        'consumption' => $currentReading - $previousReading,
        'read_at' => $billingPeriod->starts_on->format('Y-m-d'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('an imported meter reading leaves the organization without a receipt', function () {
    $fixture = meterBillingFixture();

    importMeterReading($fixture['meter'], $fixture['billingPeriod'], 100, 120);

    expect(Receipt::query()->count())->toBe(0);
});

test('command rebuilds the receipt of an imported meter reading', function () {
    $fixture = meterBillingFixture();

    importMeterReading($fixture['meter'], $fixture['billingPeriod'], 100, 120);

    $this->artisan('receipts:rebuild', ['--organization' => $fixture['organization']->getKey()])
        ->assertSuccessful();

    $receipt = Receipt::query()->sole();

    expect((float) $receipt->volume)->toBe(20.0)
        ->and((float) $receipt->tariff_price)->toBe(90.0)
        ->and((float) $receipt->amount)->toBe(1800.0)
        ->and($receipt->receipt_number)->toBe('202608-7000001')
        ->and($receipt->utility_service_name)->toBe('Водоснабжение');
});

test('rebuilt receipt carries the payments and the opening balance of the period', function () {
    $fixture = meterBillingFixture();

    importMeterReading($fixture['meter'], $fixture['billingPeriod'], 100, 120);

    BalanceAdjustment::factory()
        ->for($fixture['organization'])
        ->for($fixture['client'])
        ->create([
            'period' => '202608',
            'type' => BalanceAdjustmentType::OpeningBalance->value,
            'amount' => 900,
        ]);

    Payment::factory()
        ->for($fixture['organization'])
        ->for($fixture['client'])
        ->create([
            'period' => '202608',
            'amount' => 500,
        ]);

    $this->artisan('receipts:rebuild', ['--organization' => $fixture['organization']->getKey()])
        ->assertSuccessful();

    $receipt = Receipt::query()->sole();

    expect((float) $receipt->opening_balance)->toBe(900.0)
        ->and((float) $receipt->paid_amount)->toBe(500.0)
        ->and((float) $receipt->closing_balance)->toBe(2200.0);
});

test('command writes one receipt for a subscriber with several meters', function () {
    $fixture = meterBillingFixture();

    $secondMeter = Meter::factory()
        ->for($fixture['organization'])
        ->for($fixture['utilityService'])
        ->for($fixture['client'])
        ->create(['initial_reading' => 0]);

    importMeterReading($fixture['meter'], $fixture['billingPeriod'], 100, 120);
    importMeterReading($secondMeter, $fixture['billingPeriod'], 0, 5);

    $this->artisan('receipts:rebuild', ['--organization' => $fixture['organization']->getKey()])
        ->assertSuccessful();

    $receipt = Receipt::query()->sole();

    expect((float) $receipt->volume)->toBe(25.0)
        ->and((float) $receipt->amount)->toBe(2250.0);
});

test('command rebuilds only the requested billing period', function () {
    $fixture = meterBillingFixture('202607');

    $fixture['billingPeriod']->forceFill([
        'status' => BillingPeriodStatus::Closed,
        'closed_at' => now(),
    ])->save();

    $laterBillingPeriod = billingPeriodFor($fixture['organization'], '202608');

    importMeterReading($fixture['meter'], $fixture['billingPeriod'], 100, 120);
    importMeterReading($fixture['meter'], $laterBillingPeriod, 120, 130);

    $this->artisan('receipts:rebuild', [
        '--organization' => $fixture['organization']->getKey(),
        '--period' => '202608',
    ])->assertSuccessful();

    $receipt = Receipt::query()->sole();

    expect($receipt->billing_period_id)->toBe($laterBillingPeriod->getKey())
        ->and((float) $receipt->volume)->toBe(10.0);
});

test('command leaves the receipts of other organizations untouched', function () {
    $fixture = meterBillingFixture();
    $otherFixture = meterBillingFixture();

    importMeterReading($fixture['meter'], $fixture['billingPeriod'], 100, 120);
    importMeterReading($otherFixture['meter'], $otherFixture['billingPeriod'], 100, 150);

    $this->artisan('receipts:rebuild', ['--organization' => $fixture['organization']->getKey()])
        ->assertSuccessful();

    expect(Receipt::query()->count())->toBe(1)
        ->and(Receipt::query()->sole()->organization_id)->toBe($fixture['organization']->getKey());
});

test('command refuses an organization that does not exist', function () {
    $this->artisan('receipts:rebuild', ['--organization' => 999])->assertFailed();
});

test('command refuses a billing period code that does not exist', function () {
    $fixture = meterBillingFixture();

    $this->artisan('receipts:rebuild', [
        '--organization' => $fixture['organization']->getKey(),
        '--period' => '202612',
    ])->assertFailed();
});

test('command refuses a malformed billing period code', function () {
    $this->artisan('receipts:rebuild', ['--period' => 'август'])->assertFailed();
});
