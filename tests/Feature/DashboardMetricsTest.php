<?php

use App\BillingPeriodStatus;
use App\Dashboard\DashboardMetrics;
use App\Models\BillingPeriod;
use App\Models\City;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
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
