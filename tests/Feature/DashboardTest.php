<?php

use App\BillingPeriodStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Support\DashboardBillingPeriod;
use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\User;
use App\Models\UtilityService;
use App\OrganizationMemberRole;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
