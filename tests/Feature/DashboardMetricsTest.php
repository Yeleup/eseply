<?php

use App\BalanceAdjustmentType;
use App\BillingPeriodStatus;
use App\Dashboard\DashboardMetrics;
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

/**
 * July is closed with the accruals of record, August is open: only one client
 * has an August receipt, the others carry their balance from July.
 *
 * @return array{organization: Organization, august: BillingPeriod, north: Region, south: Region}
 */
function dashboardOpenPeriodWithCarriedDebt(): array
{
    $organization = dashboardOrganization();
    $north = dashboardRegion($organization, 'Северный');
    $south = dashboardRegion($organization, 'Южный');

    $withReceipt = dashboardFixedClient($organization, $north, '100001');
    $withoutReceipt = dashboardFixedClient($organization, $north, '100002');
    $overpaid = dashboardFixedClient($organization, $south, '100003');
    $withOpeningBalance = dashboardFixedClient($organization, $south, '100004');
    $inactive = dashboardFixedClient($organization, $south, '100005');
    $inactive->forceFill(['status' => 'inactive'])->save();

    $july = BillingPeriod::openFor($organization, '202607');
    dashboardCloseBillingPeriod($july);

    foreach ([[$withReceipt, 500], [$withoutReceipt, 300], [$overpaid, -100], [$inactive, 1000]] as [$client, $closingBalance]) {
        Accrual::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'billing_period_id' => $july->id,
            'period' => null,
            'amount' => 1000,
            'closing_balance' => $closingBalance,
        ]);
    }

    $august = BillingPeriod::openFor($organization, '202608');

    Receipt::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $withReceipt->id,
        'billing_period_id' => $august->id,
        'period' => null,
        'amount' => 200,
        'paid_amount' => 0,
        'adjustment_amount' => 0,
        'opening_balance' => 500,
        'closing_balance' => 700,
    ]);

    Payment::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $withReceipt->id,
        'billing_period_id' => $august->id,
        'period' => null,
        'amount' => 100,
    ]);

    BalanceAdjustment::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $withOpeningBalance->id,
        'billing_period_id' => $august->id,
        'period' => null,
        'type' => BalanceAdjustmentType::OpeningBalance->value,
        'amount' => 50,
    ]);

    $otherOrganization = dashboardOrganization();
    $otherClient = dashboardFixedClient($otherOrganization, dashboardRegion($otherOrganization, 'Чужой'), '900001');
    $otherJuly = dashboardCloseBillingPeriod(BillingPeriod::openFor($otherOrganization, '202607'));
    Accrual::factory()->create([
        'organization_id' => $otherOrganization->id,
        'client_id' => $otherClient->id,
        'billing_period_id' => $otherJuly->id,
        'period' => null,
        'closing_balance' => 5000,
    ]);

    return ['organization' => $organization, 'august' => $august, 'north' => $north, 'south' => $south];
}

it('считает долг открытого месяца по всем абонентам, включая абонентов без квитанции', function (): void {
    ['organization' => $organization, 'august' => $august] = dashboardOpenPeriodWithCarriedDebt();

    $finance = app(DashboardMetrics::class)->finance($organization, $august);

    /** 500 + 200 − 100 with a receipt, 300 carried without one, 50 opening balance; the overpaid and inactive clients are no debtors. */
    expect($finance['debt'])->toBe(950.0)
        ->and($finance['debtors_count'])->toBe(3)
        ->and($finance['debt_is_current'])->toBeTrue()
        ->and($finance['charged'])->toBe(200.0)
        ->and($finance['charged_documents'])->toBe(1);
});

it('считает текущий долг по всем абонентам и в незакрытом месяце со статусом', function (BillingPeriodStatus $status): void {
    ['organization' => $organization, 'august' => $august] = dashboardOpenPeriodWithCarriedDebt();
    $august->forceFill(['status' => $status])->save();

    $finance = app(DashboardMetrics::class)->finance($organization, $august->refresh());
    $breakdown = app(DashboardMetrics::class)->regionBreakdown($organization, $august);

    expect($finance['debt'])->toBe(950.0)
        ->and($finance['debtors_count'])->toBe(3)
        ->and($finance['debt_is_current'])->toBeTrue()
        ->and(array_sum(array_column($breakdown, 'debt')))->toBe(950.0);
})->with([
    'processing' => [BillingPeriodStatus::Processing],
    'failed' => [BillingPeriodStatus::Failed],
]);

it('считает долг открытого месяца в срезе по районам по всем абонентам', function (): void {
    ['organization' => $organization, 'august' => $august] = dashboardOpenPeriodWithCarriedDebt();

    $breakdown = app(DashboardMetrics::class)->regionBreakdown($organization, $august);

    expect($breakdown)->toHaveCount(2)
        ->and($breakdown[0]['region'])->toBe('Северный')
        ->and($breakdown[0]['debt'])->toBe(900.0)
        ->and($breakdown[0]['charged'])->toBe(200.0)
        ->and($breakdown[0]['paid'])->toBe(100.0)
        ->and($breakdown[1]['region'])->toBe('Южный')
        ->and($breakdown[1]['debt'])->toBe(50.0)
        ->and($breakdown[1]['charged'])->toBe(0.0);
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
        ->and($finance['debtors_count'])->toBe(1)
        ->and($finance['debt_is_current'])->toBeFalse();
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
        ->and($breakdown[1]['debt'])->toBe(60.0)
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

it('кэширует показатели дашборда на 60 секунд', function (): void {
    $organization = dashboardOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');
    $region = dashboardRegion($organization, 'Алмалинский');
    $operator = dashboardOperator($organization);
    $metrics = app(DashboardMetrics::class);

    dashboardMeteredClient($organization, $region, '100001');

    expect($metrics->operations($organization, $billingPeriod, $operator)['clients_total'])->toBe(1);

    dashboardMeteredClient($organization, $region, '100002');

    Carbon::setTestNow(now()->addSeconds(DashboardMetrics::CACHE_TTL - 1));

    expect($metrics->operations($organization, $billingPeriod, $operator)['clients_total'])->toBe(1);

    Carbon::setTestNow(now()->addSeconds(2));

    expect($metrics->operations($organization, $billingPeriod, $operator)['clients_total'])->toBe(2);
});

it('не отдаёт контроллеру кэш оператора и оператору кэш контроллера', function (bool $controllerFirst): void {
    $organization = dashboardOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');

    $assignedRegion = dashboardRegion($organization, 'Алмалинский');
    $otherRegion = dashboardRegion($organization, 'Бостандыкский');

    dashboardReading(dashboardMeter($organization, dashboardMeteredClient($organization, $assignedRegion, '100001'), 'MTR-001'), $billingPeriod, 10);
    dashboardReading(dashboardMeter($organization, dashboardMeteredClient($organization, $otherRegion, '100002'), 'MTR-002'), $billingPeriod, 90);

    $operator = dashboardOperator($organization);
    $controller = dashboardController($organization, $assignedRegion);
    $otherController = dashboardController($organization, $otherRegion);
    $metrics = app(DashboardMetrics::class);

    $members = $controllerFirst
        ? [$controller, $otherController, $operator]
        : [$operator, $controller, $otherController];

    $operations = [];
    $progress = [];

    foreach ($members as $member) {
        $operations[$member->id] = $metrics->operations($organization, $billingPeriod, $member);
        $progress[$member->id] = $metrics->controllerProgress($organization, $billingPeriod, $member);
    }

    expect($operations[$operator->id]['clients_total'])->toBe(2)
        ->and($operations[$operator->id]['consumption'])->toBe(100)
        ->and($operations[$controller->id]['clients_total'])->toBe(1)
        ->and($operations[$controller->id]['consumption'])->toBe(10)
        ->and($operations[$otherController->id]['clients_total'])->toBe(1)
        ->and($operations[$otherController->id]['consumption'])->toBe(90)
        ->and(array_column($progress[$operator->id], 'controller_id'))->toEqualCanonicalizing([$controller->id, $otherController->id])
        ->and(array_column($progress[$controller->id], 'controller_id'))->toBe([$controller->id])
        ->and(array_column($progress[$otherController->id], 'controller_id'))->toBe([$otherController->id]);
})->with([
    'сначала оператор' => false,
    'сначала контроллер' => true,
]);

it('не смешивает кэш разных организаций и расчётных месяцев', function (): void {
    $organization = dashboardOrganization();
    $otherOrganization = dashboardOrganization();

    $region = dashboardRegion($organization, 'Алмалинский');
    $otherRegion = dashboardRegion($otherOrganization, 'Чужой');
    $meter = dashboardMeter($organization, dashboardMeteredClient($organization, $region, '100001'), 'MTR-001');

    $julyPeriod = BillingPeriod::openFor($organization, '202607');
    dashboardReading($meter, $julyPeriod, 7);
    $julyPeriod = dashboardCloseBillingPeriod($julyPeriod);

    $augustPeriod = BillingPeriod::openFor($organization, '202608');
    $otherAugustPeriod = BillingPeriod::openFor($otherOrganization, '202608');
    dashboardReading($meter, $augustPeriod, 10);
    dashboardReading(
        dashboardMeter($otherOrganization, dashboardMeteredClient($otherOrganization, $otherRegion, '200001'), 'MTR-002'),
        $otherAugustPeriod,
        99,
    );

    Payment::factory()->create([
        'organization_id' => $otherOrganization->id,
        'client_id' => Client::query()->where('organization_id', $otherOrganization->id)->value('id'),
        'billing_period_id' => $otherAugustPeriod->id,
        'period' => null,
        'amount' => 500,
    ]);

    $metrics = app(DashboardMetrics::class);
    $operator = dashboardOperator($organization);
    $otherOperator = dashboardOperator($otherOrganization);

    expect($metrics->operations($organization, $augustPeriod, $operator)['consumption'])->toBe(10)
        ->and($metrics->operations($organization, $julyPeriod, $operator)['consumption'])->toBe(7)
        ->and($metrics->operations($otherOrganization, $otherAugustPeriod, $otherOperator)['consumption'])->toBe(99)
        ->and($metrics->finance($organization, $augustPeriod)['paid'])->toBe(0.0)
        ->and($metrics->finance($otherOrganization, $otherAugustPeriod)['paid'])->toBe(500.0)
        ->and(array_column($metrics->monthlyTotals($organization), 'period'))->toHaveCount(2)
        ->and(array_column($metrics->monthlyTotals($otherOrganization), 'paid'))->toBe([500.0])
        ->and(array_column($metrics->regionBreakdown($organization, $augustPeriod), 'region'))->toBe(['Алмалинский'])
        ->and(array_column($metrics->regionBreakdown($otherOrganization, $otherAugustPeriod), 'region'))->toBe(['Чужой']);
});
