<?php

use App\BillingPeriodStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Support\DashboardBillingPeriod;
use App\Filament\Widgets\DashboardChargesChartWidget;
use App\Filament\Widgets\DashboardControllerProgressWidget;
use App\Filament\Widgets\DashboardFinanceStatsWidget;
use App\Filament\Widgets\DashboardRegionBreakdownWidget;
use App\Filament\Widgets\DashboardStatsWidget;
use App\Models\BillingPeriod;
use App\Models\City;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\Region;
use App\Models\User;
use App\Models\UtilityService;
use App\OrganizationMemberRole;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function dashboardPageOrganization(): Organization
{
    $organization = Organization::factory()->create();

    UtilityService::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Водоснабжение',
        'unit_of_measurement' => 'м³',
    ]);

    return $organization->refresh();
}

function actingAsDashboardMember(Organization $organization, OrganizationMemberRole $role): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => $role->value]);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

it('открывает дашборд оператору', function (): void {
    $organization = dashboardPageOrganization();
    BillingPeriod::openFor($organization, '202608');

    actingAsDashboardMember($organization, OrganizationMemberRole::Operator);

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('Дашборд');
});

it('выбирает текущий редактируемый месяц по умолчанию', function (): void {
    $organization = dashboardPageOrganization();

    $closedPeriod = BillingPeriod::openFor($organization, '202607');
    $closedPeriod->forceFill(['status' => BillingPeriodStatus::Closed, 'closed_at' => now()])->save();

    $openPeriod = BillingPeriod::openFor($organization, '202608');

    expect(DashboardBillingPeriod::default($organization)?->getKey())->toBe($openPeriod->getKey());
});

it('выбирает последний месяц, когда открытого месяца нет', function (): void {
    $organization = dashboardPageOrganization();

    $firstPeriod = BillingPeriod::openFor($organization, '202607');
    $firstPeriod->forceFill(['status' => BillingPeriodStatus::Closed, 'closed_at' => now()])->save();

    $secondPeriod = BillingPeriod::openFor($organization, '202608');
    $secondPeriod->forceFill(['status' => BillingPeriodStatus::Closed, 'closed_at' => now()])->save();

    expect(DashboardBillingPeriod::default($organization)?->getKey())->toBe($secondPeriod->getKey());
});

it('не применяет расчётный месяц чужой организации', function (): void {
    $organization = dashboardPageOrganization();
    $otherOrganization = dashboardPageOrganization();

    $ownPeriod = BillingPeriod::openFor($organization, '202608');
    $foreignPeriod = BillingPeriod::openFor($otherOrganization, '202608');

    expect(DashboardBillingPeriod::resolve($organization, $foreignPeriod->getKey())?->getKey())
        ->toBe($ownPeriod->getKey())
        ->and(DashboardBillingPeriod::resolve($organization, 'не число')?->getKey())
        ->toBe($ownPeriod->getKey())
        ->and(DashboardBillingPeriod::resolve($organization, $ownPeriod->getKey())?->getKey())
        ->toBe($ownPeriod->getKey());
});

it('подписывает опции селектора месяцем и статусом', function (): void {
    $organization = dashboardPageOrganization();

    $closedPeriod = BillingPeriod::openFor($organization, '202607');
    $closedPeriod->forceFill(['status' => BillingPeriodStatus::Closed, 'closed_at' => now()])->save();

    $openPeriod = BillingPeriod::openFor($organization, '202608');

    expect(DashboardBillingPeriod::options($organization))->toBe([
        $openPeriod->getKey() => '08.2026 — Открыт',
        $closedPeriod->getKey() => '07.2026 — Закрыт',
    ]);
});

it('открывает дашборд организации без расчётных месяцев', function (): void {
    $organization = dashboardPageOrganization();

    actingAsDashboardMember($organization, OrganizationMemberRole::Operator);

    Livewire::test(Dashboard::class)->assertOk();

    expect(DashboardBillingPeriod::default($organization))->toBeNull();
});

it('показывает оператору операционные и денежные плитки', function (): void {
    $organization = dashboardPageOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');

    actingAsDashboardMember($organization, OrganizationMemberRole::Operator);

    expect(DashboardStatsWidget::canView())->toBeTrue()
        ->and(DashboardFinanceStatsWidget::canView())->toBeTrue();

    Livewire::test(DashboardStatsWidget::class, [
        'pageFilters' => ['billing_period_id' => $billingPeriod->getKey()],
    ])
        ->assertOk()
        ->assertSee('Абоненты')
        ->assertSee('Счётчики')
        ->assertSee('Снято показаний')
        ->assertSee('Потребление');

    Livewire::test(DashboardFinanceStatsWidget::class, [
        'pageFilters' => ['billing_period_id' => $billingPeriod->getKey()],
    ])
        ->assertOk()
        ->assertSee('Начислено')
        ->assertSee('Оплачено')
        ->assertSee('Долг на конец месяца');
});

it('скрывает денежные плитки от контроллера', function (): void {
    $organization = dashboardPageOrganization();
    BillingPeriod::openFor($organization, '202608');

    actingAsDashboardMember($organization, OrganizationMemberRole::Controller);

    expect(DashboardStatsWidget::canView())->toBeTrue()
        ->and(DashboardFinanceStatsWidget::canView())->toBeFalse();
});

it('показывает в плитках цифры выбранного месяца', function (): void {
    $organization = dashboardPageOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');

    $city = City::factory()->create(['organization_id' => $organization->id, 'name' => 'Алматы']);
    $region = Region::factory()->create([
        'organization_id' => $organization->id,
        'city_id' => $city->id,
        'name' => 'Алмалинский',
    ]);

    $client = Client::factory()->create([
        'organization_id' => $organization->id,
        'account_number' => '100001',
        'region_id' => $region->id,
        'status' => 'active',
        'billing_type' => 'meter',
    ]);

    $meter = Meter::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'utility_service_id' => $organization->utilityService?->id,
        'number' => 'MTR-001',
        'status' => 'active',
    ]);

    MeterReading::factory()->create([
        'organization_id' => $organization->id,
        'meter_id' => $meter->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'period' => null,
        'previous_reading' => 0,
        'current_reading' => 42,
        'consumption' => 42,
    ]);

    actingAsDashboardMember($organization, OrganizationMemberRole::Operator);

    Livewire::test(DashboardStatsWidget::class, [
        'pageFilters' => ['billing_period_id' => $billingPeriod->getKey()],
    ])
        ->assertOk()
        ->assertSee('42')
        ->assertSee('100 %')
        ->assertSee('м³');
});

it('не падает без расчётного месяца', function (): void {
    $organization = dashboardPageOrganization();

    actingAsDashboardMember($organization, OrganizationMemberRole::Operator);

    Livewire::test(DashboardStatsWidget::class, ['pageFilters' => []])
        ->assertOk();
});

it('скрывает график и срез по районам от контроллера', function (): void {
    $organization = dashboardPageOrganization();
    BillingPeriod::openFor($organization, '202608');

    actingAsDashboardMember($organization, OrganizationMemberRole::Controller);

    expect(DashboardChargesChartWidget::canView())->toBeFalse()
        ->and(DashboardRegionBreakdownWidget::canView())->toBeFalse()
        ->and(DashboardControllerProgressWidget::canView())->toBeTrue();
});

it('показывает оператору график, прогресс контроллеров и срез по районам', function (): void {
    $organization = dashboardPageOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');

    $city = City::factory()->create(['organization_id' => $organization->id, 'name' => 'Алматы']);
    $region = Region::factory()->create([
        'organization_id' => $organization->id,
        'city_id' => $city->id,
        'name' => 'Алмалинский',
    ]);

    $controller = User::factory()->create(['name' => 'Абаев Абай']);
    $controller->organizations()->attach($organization, [
        'role' => OrganizationMemberRole::Controller->value,
    ]);
    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $controller->id,
        'region_id' => $region->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $client = Client::factory()->create([
        'organization_id' => $organization->id,
        'account_number' => '100001',
        'region_id' => $region->id,
        'status' => 'active',
        'billing_type' => 'meter',
    ]);

    Meter::factory()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'utility_service_id' => $organization->utilityService?->id,
        'number' => 'MTR-001',
        'status' => 'active',
    ]);

    actingAsDashboardMember($organization, OrganizationMemberRole::Operator);

    $pageFilters = ['pageFilters' => ['billing_period_id' => $billingPeriod->getKey()]];

    Livewire::test(DashboardChargesChartWidget::class, $pageFilters)
        ->assertOk()
        ->assertSee('Начисления и оплаты по месяцам');

    Livewire::test(DashboardControllerProgressWidget::class, $pageFilters)
        ->assertOk()
        ->assertSee('Абаев Абай');

    Livewire::test(DashboardRegionBreakdownWidget::class, $pageFilters)
        ->assertOk()
        ->assertSee('Алмалинский');
});

it('показывает контроллеру в таблице прогресса только его строку', function (): void {
    $organization = dashboardPageOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202608');

    $city = City::factory()->create(['organization_id' => $organization->id, 'name' => 'Алматы']);
    $region = Region::factory()->create([
        'organization_id' => $organization->id,
        'city_id' => $city->id,
        'name' => 'Алмалинский',
    ]);

    $otherController = User::factory()->create(['name' => 'Букеев Букей']);
    $otherController->organizations()->attach($organization, [
        'role' => OrganizationMemberRole::Controller->value,
    ]);

    $controller = actingAsDashboardMember($organization, OrganizationMemberRole::Controller);
    $controller->forceFill(['name' => 'Абаев Абай'])->save();

    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => $controller->id,
        'region_id' => $region->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(DashboardControllerProgressWidget::class, [
        'pageFilters' => ['billing_period_id' => $billingPeriod->getKey()],
    ])
        ->assertOk()
        ->assertSee('Абаев Абай')
        ->assertDontSee('Букеев Букей');
});
