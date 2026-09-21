<?php

use App\BillingPeriodStatus;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\UtilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{organization: Organization, meter: Meter, billingPeriodId: int}
 */
function confirmationMeterWithHistory(array $consumptions = [10, 20, 30]): array
{
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create(['billing_type' => 'meter']);
    $meter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['initial_reading' => 100]);

    $previousReading = 100;

    foreach ($consumptions as $index => $consumption) {
        $billingPeriod = billingPeriodFor($organization, sprintf('20260%d', $index + 2));
        $currentReading = $previousReading + $consumption;

        MeterReading::query()->create([
            'meter_id' => $meter->id,
            'billing_period_id' => $billingPeriod->id,
            'previous_reading' => $previousReading,
            'current_reading' => $currentReading,
        ]);

        $billingPeriod->forceFill([
            'status' => BillingPeriodStatus::Closed,
            'closed_at' => now(),
        ])->save();

        $previousReading = $currentReading;
    }

    $currentBillingPeriod = billingPeriodFor($organization, '202605');

    return [
        'organization' => $organization,
        'meter' => $meter,
        'billingPeriodId' => $currentBillingPeriod->id,
    ];
}

test('large consumption is confirmed only when it is strictly above three prior-month averages', function (): void {
    ['meter' => $meter, 'billingPeriodId' => $billingPeriodId] = confirmationMeterWithHistory();

    expect(MeterReading::largeConsumptionConfirmationFor(
        meterId: $meter->id,
        billingPeriodId: $billingPeriodId,
        previousReading: 160,
        currentReading: 220,
    ))->toBeNull();

    expect(MeterReading::largeConsumptionConfirmationFor(
        meterId: $meter->id,
        billingPeriodId: $billingPeriodId,
        previousReading: 160,
        currentReading: 221,
    ))->toMatchArray([
        'current_reading' => 221,
        'consumption' => 61,
        'average_consumption' => 20.0,
    ]);
});

test('large consumption averages the available non-negative history when fewer than three months exist', function (): void {
    ['meter' => $meter, 'billingPeriodId' => $billingPeriodId] = confirmationMeterWithHistory([20, -10, 20]);

    expect(MeterReading::largeConsumptionConfirmationFor(
        meterId: $meter->id,
        billingPeriodId: $billingPeriodId,
        previousReading: 130,
        currentReading: 191,
    ))->toMatchArray([
        'consumption' => 61,
        'average_consumption' => 20.0,
    ]);
});

test('large consumption ignores readings older than the three immediately preceding billing periods', function (): void {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create(['billing_type' => 'meter']);
    $meter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['initial_reading' => 100]);

    $oldBillingPeriod = billingPeriodFor($organization, '202501');
    MeterReading::query()->create([
        'meter_id' => $meter->id,
        'billing_period_id' => $oldBillingPeriod->id,
        'previous_reading' => 100,
        'current_reading' => 120,
    ]);
    $oldBillingPeriod->forceFill([
        'status' => BillingPeriodStatus::Closed,
        'closed_at' => now(),
    ])->save();

    foreach ([
        '202502', '202503', '202504', '202505', '202506', '202507',
        '202508', '202509', '202510', '202511', '202512', '202601',
        '202602', '202603', '202604',
    ] as $period) {
        closedBillingPeriodFor($organization, $period);
    }

    $currentBillingPeriod = billingPeriodFor($organization, '202605');

    expect(MeterReading::largeConsumptionConfirmationFor(
        meterId: $meter->id,
        billingPeriodId: $currentBillingPeriod->id,
        previousReading: 120,
        currentReading: 99999,
    ))->toBeNull();
});
