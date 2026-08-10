<?php

use App\BillingPeriodStatus;
use App\Dashboard\DashboardMetrics;
use App\Models\Accrual;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Several metrics compare `created_at` with the calendar month of the billing
 * period, so the clock is fixed inside the tested month.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function dashboardOrganization(): Organization
{
    $organization = Organization::factory()->create();

    UtilityService::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Водоснабжение',
        'unit_of_measurement' => 'м³',
    ]);

    return $organization->refresh();
}

function dashboardOperator(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, [
        'role' => OrganizationMemberRole::Operator->value,
    ]);

    return $user;
}

function dashboardController(Organization $organization, ?Region $region = null, ?Street $street = null): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, [
        'role' => OrganizationMemberRole::Controller->value,
    ]);

    if ($region) {
        DB::table('organization_user_regions')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'region_id' => $region->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    if ($street) {
        DB::table('organization_user_streets')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'street_id' => $street->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $user;
}

function dashboardRegion(Organization $organization, string $name): Region
{
    $city = City::query()->firstOrCreate([
        'organization_id' => $organization->id,
        'name' => 'Алматы',
    ]);

    return Region::factory()->create([
        'organization_id' => $organization->id,
        'city_id' => $city->id,
        'name' => $name,
    ]);
}

function dashboardMeteredClient(Organization $organization, Region $region, string $accountNumber): Client
{
    return Client::factory()->create([
        'organization_id' => $organization->id,
        'account_number' => $accountNumber,
        'region_id' => $region->id,
        'status' => 'active',
        'billing_type' => 'meter',
    ]);
}

function dashboardMeter(Organization $organization, Client $client, string $number): Meter
{
    return Meter::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'utility_service_id' => $organization->utilityService?->id,
        'number' => $number,
        'status' => 'active',
    ]);
}

function dashboardReading(Meter $meter, BillingPeriod $billingPeriod, int $consumption): MeterReading
{
    return MeterReading::factory()->create([
        'organization_id' => $meter->organization_id,
        'meter_id' => $meter->id,
        'client_id' => $meter->client_id,
        'billing_period_id' => $billingPeriod->id,
        'period' => null,
        'previous_reading' => 0,
        'current_reading' => $consumption,
        'consumption' => $consumption,
    ]);
}

it('считает абонентов, счётчики, снятие и потребление за расчётный месяц', function (): void {
    $organization = dashboardOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');
    $region = dashboardRegion($organization, 'Алмалинский');

    $firstClient = dashboardMeteredClient($organization, $region, '100001');
    $secondClient = dashboardMeteredClient($organization, $region, '100002');

    Client::factory()->create([
        'organization_id' => $organization->id,
        'account_number' => '100003',
        'region_id' => $region->id,
        'status' => 'inactive',
        'billing_type' => 'meter',
    ]);

    Client::factory()->create([
        'organization_id' => $organization->id,
        'account_number' => '100004',
        'region_id' => $region->id,
        'status' => 'active',
        'billing_type' => 'per_person',
    ]);

    $readMeter = dashboardMeter($organization, $firstClient, 'MTR-001');
    dashboardMeter($organization, $secondClient, 'MTR-002');

    dashboardReading($readMeter, $billingPeriod, 25);

    $metrics = app(DashboardMetrics::class)
        ->operations($organization, $billingPeriod, dashboardOperator($organization));

    expect($metrics['clients_total'])->toBe(4)
        ->and($metrics['clients_active'])->toBe(3)
        ->and($metrics['clients_new'])->toBe(4)
        ->and($metrics['meters_active'])->toBe(2)
        ->and($metrics['meters_metered'])->toBe(2)
        ->and($metrics['readings_expected'])->toBe(2)
        ->and($metrics['readings_taken'])->toBe(1)
        ->and($metrics['readings_percent'])->toBe(50.0)
        ->and($metrics['consumption'])->toBe(25);
});

it('не считает абонентов, созданных вне выбранного месяца', function (): void {
    $organization = dashboardOrganization();
    $julyPeriod = BillingPeriod::openFor($organization, '202607');
    $julyPeriod->forceFill(['status' => BillingPeriodStatus::Closed, 'closed_at' => now()])->save();
    $augustPeriod = BillingPeriod::openFor($organization, '202608');
    $region = dashboardRegion($organization, 'Алмалинский');

    $oldClient = dashboardMeteredClient($organization, $region, '100001');
    $oldClient->forceFill(['created_at' => '2026-07-15 10:00:00'])->save();

    $newClient = dashboardMeteredClient($organization, $region, '100002');
    $newClient->forceFill(['created_at' => '2026-08-03 10:00:00'])->save();

    $metrics = app(DashboardMetrics::class)
        ->operations($organization, $augustPeriod, dashboardOperator($organization));

    expect($metrics['clients_total'])->toBe(2)
        ->and($metrics['clients_new'])->toBe(1);
});

it('ограничивает операционные метрики зоной контроллера', function (): void {
    $organization = dashboardOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');

    $assignedRegion = dashboardRegion($organization, 'Алмалинский');
    $otherRegion = dashboardRegion($organization, 'Бостандыкский');

    $assignedClient = dashboardMeteredClient($organization, $assignedRegion, '100001');
    $otherClient = dashboardMeteredClient($organization, $otherRegion, '100002');

    dashboardReading(dashboardMeter($organization, $assignedClient, 'MTR-001'), $billingPeriod, 10);
    dashboardReading(dashboardMeter($organization, $otherClient, 'MTR-002'), $billingPeriod, 90);

    $metrics = app(DashboardMetrics::class)->operations(
        $organization,
        $billingPeriod,
        dashboardController($organization, $assignedRegion),
    );

    expect($metrics['clients_total'])->toBe(1)
        ->and($metrics['meters_active'])->toBe(1)
        ->and($metrics['readings_taken'])->toBe(1)
        ->and($metrics['consumption'])->toBe(10);
});

it('отдаёт нули контроллеру без назначенной зоны', function (): void {
    $organization = dashboardOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');
    $region = dashboardRegion($organization, 'Алмалинский');

    dashboardMeter($organization, dashboardMeteredClient($organization, $region, '100001'), 'MTR-001');

    $metrics = app(DashboardMetrics::class)->operations(
        $organization,
        $billingPeriod,
        dashboardController($organization),
    );

    expect($metrics['clients_total'])->toBe(0)
        ->and($metrics['meters_active'])->toBe(0)
        ->and($metrics['readings_percent'])->toBe(0.0)
        ->and($metrics['consumption'])->toBe(0);
});

it('не смешивает данные разных организаций', function (): void {
    $organization = dashboardOrganization();
    $otherOrganization = dashboardOrganization();

    $billingPeriod = BillingPeriod::openFor($organization, '202608');
    $otherBillingPeriod = BillingPeriod::openFor($otherOrganization, '202608');

    $region = dashboardRegion($organization, 'Алмалинский');
    $otherRegion = dashboardRegion($otherOrganization, 'Чужой');

    dashboardReading(
        dashboardMeter($organization, dashboardMeteredClient($organization, $region, '100001'), 'MTR-001'),
        $billingPeriod,
        10,
    );
    dashboardReading(
        dashboardMeter($otherOrganization, dashboardMeteredClient($otherOrganization, $otherRegion, '200001'), 'MTR-002'),
        $otherBillingPeriod,
        99,
    );

    $metrics = app(DashboardMetrics::class)
        ->operations($organization, $billingPeriod, dashboardOperator($organization));

    expect($metrics['clients_total'])->toBe(1)
        ->and($metrics['consumption'])->toBe(10);
});

function dashboardFixedClient(Organization $organization, Region $region, string $accountNumber): Client
{
    return Client::factory()->create([
        'organization_id' => $organization->id,
        'account_number' => $accountNumber,
        'region_id' => $region->id,
        'status' => 'active',
        'billing_type' => 'fixed',
        'fixed_amount' => 1000,
    ]);
}

function dashboardCloseBillingPeriod(BillingPeriod $billingPeriod): BillingPeriod
{
    $billingPeriod->forceFill([
        'status' => BillingPeriodStatus::Closed,
        'closed_at' => now(),
    ])->save();

    return $billingPeriod->refresh();
}

it('берёт начисление и долг открытого месяца из квитанций', function (): void {
    $organization = dashboardOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');
    $region = dashboardRegion($organization, 'Алмалинский');

    $firstClient = dashboardFixedClient($organization, $region, '100001');
    $secondClient = dashboardFixedClient($organization, $region, '100002');

    Receipt::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $firstClient->id,
        'billing_period_id' => $billingPeriod->id,
        'period' => null,
        'amount' => 600,
        'paid_amount' => 0,
        'adjustment_amount' => 0,
        'opening_balance' => 0,
        'closing_balance' => 600,
    ]);

    Receipt::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $secondClient->id,
        'billing_period_id' => $billingPeriod->id,
        'period' => null,
        'amount' => 400,
        'paid_amount' => 400,
        'adjustment_amount' => 0,
        'opening_balance' => 0,
        'closing_balance' => 0,
    ]);

    Payment::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $secondClient->id,
        'billing_period_id' => $billingPeriod->id,
        'period' => null,
        'amount' => 400,
    ]);

    $finance = app(DashboardMetrics::class)->finance($organization, $billingPeriod);

    expect($finance['charged'])->toBe(1000.0)
        ->and($finance['charged_is_preliminary'])->toBeTrue()
        ->and($finance['charged_documents'])->toBe(2)
        ->and($finance['paid'])->toBe(400.0)
        ->and($finance['payments_count'])->toBe(1)
        ->and($finance['collection_percent'])->toBe(40.0)
        ->and($finance['debt'])->toBe(600.0)
        ->and($finance['debtors_count'])->toBe(1);
});

it('берёт начисление и долг закрытого месяца из начислений', function (): void {
    $organization = dashboardOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');
    $region = dashboardRegion($organization, 'Алмалинский');

    $client = dashboardFixedClient($organization, $region, '100001');

    Receipt::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'period' => null,
        'amount' => 111,
        'closing_balance' => 111,
    ]);

    Payment::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'period' => null,
        'amount' => 250,
    ]);

    dashboardCloseBillingPeriod($billingPeriod);

    Accrual::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'period' => null,
        'amount' => 1000,
        'paid_amount' => 250,
        'adjustment_amount' => 0,
        'opening_balance' => 0,
        'closing_balance' => 750,
    ]);

    $finance = app(DashboardMetrics::class)->finance($organization, $billingPeriod->refresh());

    expect($finance['charged'])->toBe(1000.0)
        ->and($finance['charged_is_preliminary'])->toBeFalse()
        ->and($finance['charged_documents'])->toBe(1)
        ->and($finance['paid'])->toBe(250.0)
        ->and($finance['collection_percent'])->toBe(25.0)
        ->and($finance['debt'])->toBe(750.0)
        ->and($finance['debtors_count'])->toBe(1);
});

it('отдаёт нулевой процент сбора при нулевом начислении', function (): void {
    $organization = dashboardOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');

    $finance = app(DashboardMetrics::class)->finance($organization, $billingPeriod);

    expect($finance['charged'])->toBe(0.0)
        ->and($finance['paid'])->toBe(0.0)
        ->and($finance['collection_percent'])->toBe(0.0)
        ->and($finance['debt'])->toBe(0.0)
        ->and($finance['debtors_count'])->toBe(0);
});

it('строит динамику по месяцам от старого месяца к новому', function (): void {
    $organization = dashboardOrganization();
    $region = dashboardRegion($organization, 'Алмалинский');
    $client = dashboardFixedClient($organization, $region, '100001');

    $julyPeriod = BillingPeriod::openFor($organization, '202607');

    Payment::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $julyPeriod->id,
        'period' => null,
        'amount' => 300,
    ]);

    dashboardCloseBillingPeriod($julyPeriod);

    Accrual::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $julyPeriod->id,
        'period' => null,
        'amount' => 500,
        'closing_balance' => 200,
    ]);

    $augustPeriod = BillingPeriod::openFor($organization, '202608');

    Receipt::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $augustPeriod->id,
        'period' => null,
        'amount' => 700,
        'closing_balance' => 700,
    ]);

    $totals = app(DashboardMetrics::class)->monthlyTotals($organization);

    expect($totals)->toHaveCount(2)
        ->and($totals[0]['period'])->toBe('202607')
        ->and($totals[0]['label'])->toBe('07.2026')
        ->and($totals[0]['charged'])->toBe(500.0)
        ->and($totals[0]['paid'])->toBe(300.0)
        ->and($totals[1]['period'])->toBe('202608')
        ->and($totals[1]['charged'])->toBe(700.0)
        ->and($totals[1]['paid'])->toBe(0.0);
});

it('считает прогресс снятия по каждому контроллеру организации', function (): void {
    $organization = dashboardOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');

    $firstRegion = dashboardRegion($organization, 'Алмалинский');
    $secondRegion = dashboardRegion($organization, 'Бостандыкский');

    $firstController = dashboardController($organization, $firstRegion);
    $firstController->forceFill(['name' => 'Абаев Абай'])->save();

    $secondController = dashboardController($organization, $secondRegion);
    $secondController->forceFill(['name' => 'Букеев Букей'])->save();

    $firstClient = dashboardMeteredClient($organization, $firstRegion, '100001');
    $secondClient = dashboardMeteredClient($organization, $firstRegion, '100002');
    $thirdClient = dashboardMeteredClient($organization, $secondRegion, '100003');

    dashboardReading(dashboardMeter($organization, $firstClient, 'MTR-001'), $billingPeriod, 10);
    dashboardMeter($organization, $secondClient, 'MTR-002');
    dashboardReading(dashboardMeter($organization, $thirdClient, 'MTR-003'), $billingPeriod, 20);

    $progress = app(DashboardMetrics::class)
        ->controllerProgress($organization, $billingPeriod, dashboardOperator($organization));

    expect($progress)->toHaveCount(2)
        ->and($progress[0]['name'])->toBe('Абаев Абай')
        ->and($progress[0]['total'])->toBe(2)
        ->and($progress[0]['taken'])->toBe(1)
        ->and($progress[0]['missing'])->toBe(1)
        ->and($progress[0]['percent'])->toBe(50.0)
        ->and($progress[1]['name'])->toBe('Букеев Букей')
        ->and($progress[1]['percent'])->toBe(100.0);
});

it('показывает контроллеру только его собственную строку прогресса', function (): void {
    $organization = dashboardOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');

    $ownRegion = dashboardRegion($organization, 'Алмалинский');
    $otherRegion = dashboardRegion($organization, 'Бостандыкский');

    $controller = dashboardController($organization, $ownRegion);
    dashboardController($organization, $otherRegion);

    dashboardMeter($organization, dashboardMeteredClient($organization, $ownRegion, '100001'), 'MTR-001');

    $progress = app(DashboardMetrics::class)
        ->controllerProgress($organization, $billingPeriod, $controller);

    expect($progress)->toHaveCount(1)
        ->and($progress[0]['controller_id'])->toBe($controller->id)
        ->and($progress[0]['total'])->toBe(1)
        ->and($progress[0]['taken'])->toBe(0);
});

it('учитывает счётчик один раз, когда абонент попадает к контроллеру и по району, и по улице', function (): void {
    $organization = dashboardOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');

    $region = dashboardRegion($organization, 'Алмалинский');
    $street = Street::factory()->create([
        'organization_id' => $organization->id,
        'region_id' => $region->id,
        'name' => 'Абая',
    ]);

    $controller = dashboardController($organization, $region, $street);

    $client = dashboardMeteredClient($organization, $region, '100001');
    $client->forceFill(['street_id' => $street->id])->save();

    dashboardMeter($organization, $client, 'MTR-001');

    $progress = app(DashboardMetrics::class)
        ->controllerProgress($organization, $billingPeriod, $controller);

    expect($progress[0]['total'])->toBe(1);
});

it('строит срез по районам и сортирует его по долгу по убыванию', function (): void {
    $organization = dashboardOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');

    $smallDebtRegion = dashboardRegion($organization, 'Алмалинский');
    $bigDebtRegion = dashboardRegion($organization, 'Бостандыкский');
    dashboardRegion($organization, 'Пустой');

    $firstClient = dashboardMeteredClient($organization, $smallDebtRegion, '100001');
    $secondClient = dashboardMeteredClient($organization, $bigDebtRegion, '100002');
    $thirdClient = dashboardMeteredClient($organization, $bigDebtRegion, '100003');

    dashboardReading(dashboardMeter($organization, $firstClient, 'MTR-001'), $billingPeriod, 5);
    dashboardMeter($organization, $secondClient, 'MTR-002');
    dashboardMeter($organization, $thirdClient, 'MTR-003');

    Receipt::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $secondClient->id,
        'billing_period_id' => $billingPeriod->id,
        'period' => null,
        'amount' => 900,
        'closing_balance' => 900,
    ]);

    Payment::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $firstClient->id,
        'billing_period_id' => $billingPeriod->id,
        'period' => null,
        'amount' => 40,
    ]);

    /** Saving a meter reading already issues a receipt, so this one is updated, not created. */
    Receipt::query()
        ->where('client_id', $firstClient->id)
        ->where('billing_period_id', $billingPeriod->id)
        ->update([
            'amount' => 100,
            'paid_amount' => 40,
            'adjustment_amount' => 0,
            'opening_balance' => 0,
            'closing_balance' => 100,
        ]);

    $breakdown = app(DashboardMetrics::class)->regionBreakdown($organization, $billingPeriod);

    expect($breakdown)->toHaveCount(2)
        ->and($breakdown[0]['region'])->toBe('Бостандыкский')
        ->and($breakdown[0]['city'])->toBe('Алматы')
        ->and($breakdown[0]['clients'])->toBe(2)
        ->and($breakdown[0]['charged'])->toBe(900.0)
        ->and($breakdown[0]['paid'])->toBe(0.0)
        ->and($breakdown[0]['debt'])->toBe(900.0)
        ->and($breakdown[0]['readings_percent'])->toBe(0.0)
        ->and($breakdown[1]['region'])->toBe('Алмалинский')
        ->and($breakdown[1]['clients'])->toBe(1)
        ->and($breakdown[1]['charged'])->toBe(100.0)
        ->and($breakdown[1]['paid'])->toBe(40.0)
        ->and($breakdown[1]['debt'])->toBe(100.0)
        ->and($breakdown[1]['readings_percent'])->toBe(100.0);
});

it('берёт суммы среза по районам из начислений закрытого месяца', function (): void {
    $organization = dashboardOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');
    $region = dashboardRegion($organization, 'Алмалинский');
    $client = dashboardFixedClient($organization, $region, '100001');

    Receipt::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'period' => null,
        'amount' => 111,
        'closing_balance' => 111,
    ]);

    dashboardCloseBillingPeriod($billingPeriod);

    Accrual::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'period' => null,
        'amount' => 555,
        'closing_balance' => 555,
    ]);

    $breakdown = app(DashboardMetrics::class)->regionBreakdown($organization, $billingPeriod->refresh());

    expect($breakdown)->toHaveCount(1)
        ->and($breakdown[0]['charged'])->toBe(555.0)
        ->and($breakdown[0]['debt'])->toBe(555.0);
});
