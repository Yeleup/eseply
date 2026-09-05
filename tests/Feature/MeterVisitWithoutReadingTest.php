<?php

use App\Actions\CloseBillingMonth;
use App\BillingPeriodStatus;
use App\Dashboard\DashboardMetrics;
use App\Filament\Pages\Reports\ViewReport;
use App\Models\BillingPeriod;
use App\Models\BillingPeriodClosureError;
use App\Models\City;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\Region;
use App\Models\Street;
use App\Models\Tariff;
use App\Models\User;
use App\Models\UtilityService;
use App\OrganizationMemberRole;
use App\Reports\ReportSummaryGroup;
use App\Reports\ReportSummaryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A `meter_readings` row without a `current_reading` records a visit where the
 * meter could not be read. These tests pin the one rule that makes such a row
 * safe: it is never a reading. Every surface that answers "was this meter read"
 * has its own copy of that question, so every copy is checked here.
 */

/**
 * @return array{organization: Organization, utilityService: UtilityService, city: City, region: Region, street: Street}
 */
function visitOrganization(): array
{
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $city = City::query()->create(['organization_id' => $organization->id, 'name' => 'Алматы']);
    $region = Region::query()->create(['city_id' => $city->id, 'name' => 'Алмалинский']);
    $street = Street::query()->create(['region_id' => $region->id, 'name' => 'Абая']);

    Tariff::factory()->for($organization)->for($utilityService)->create([
        'unit_price' => 100,
        'starts_on' => '2020-01-01',
        'status' => 'active',
    ]);

    return compact('organization', 'utilityService', 'city', 'region', 'street');
}

/**
 * @param  array<string, mixed>  $clientAttributes
 */
function visitMeter(
    Organization $organization,
    UtilityService $utilityService,
    array $clientAttributes = [],
): Meter {
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'meter',
            'status' => 'active',
            ...$clientAttributes,
        ]);

    return Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['initial_reading' => 100, 'status' => 'active']);
}

/**
 * The row a controller leaves behind after reaching a meter they could not read.
 */
function visitWithoutReading(Meter $meter, BillingPeriod $billingPeriod, string $note = 'Счётчик заварен'): MeterReading
{
    return $meter->readings()->create([
        'billing_period_id' => $billingPeriod->getKey(),
        'current_reading' => null,
        'note' => $note,
    ]);
}

function visitActingAs(Organization $organization, OrganizationMemberRole $role = OrganizationMemberRole::Operator): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => $role->value]);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

// --- Модель -----------------------------------------------------------------

test('визит без показания сохраняется с нулевым расходом', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = visitOrganization();
    $billingPeriod = billingPeriodFor($organization);
    visitActingAs($organization);

    $meter = visitMeter($organization, $utilityService);
    $reading = visitWithoutReading($meter, $billingPeriod);

    expect($reading->current_reading)->toBeNull()
        ->and((int) $reading->consumption)->toBe(0)
        ->and((int) $reading->previous_reading)->toBe(100)
        ->and($reading->isTaken())->toBeFalse();
});

test('область видимости taken отсекает визиты без показания', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = visitOrganization();
    $billingPeriod = billingPeriodFor($organization);
    visitActingAs($organization);

    $unread = visitMeter($organization, $utilityService);
    visitWithoutReading($unread, $billingPeriod);

    $read = visitMeter($organization, $utilityService);
    $read->readings()->create([
        'billing_period_id' => $billingPeriod->getKey(),
        'current_reading' => 150,
    ]);

    expect(MeterReading::query()->count())->toBe(2)
        ->and(MeterReading::query()->taken()->count())->toBe(1);
});

// --- Закрытие месяца --------------------------------------------------------

test('месяц не закрывается по счётчику с визитом без показания', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = visitOrganization();
    $billingPeriod = billingPeriodFor($organization);
    visitActingAs($organization);

    $meter = visitMeter($organization, $utilityService);
    visitWithoutReading($meter, $billingPeriod, 'Нет доступа, собака во дворе');

    app(CloseBillingMonth::class)->handle($organization, $billingPeriod);

    expect($billingPeriod->refresh()->status)->toBe(BillingPeriodStatus::Failed);

    $error = BillingPeriodClosureError::query()
        ->where('billing_period_id', $billingPeriod->id)
        ->firstOrFail();

    // Оператор должен увидеть не только «нет показания», но и почему.
    expect($error->code)->toBe('missing_meter_reading')
        ->and($error->message)->toContain('Нет доступа, собака во дворе');
});

test('месяц закрывается, когда показание вписано поверх визита', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = visitOrganization();
    $billingPeriod = billingPeriodFor($organization);
    visitActingAs($organization);

    $meter = visitMeter($organization, $utilityService);
    $reading = visitWithoutReading($meter, $billingPeriod);

    $reading->update(['current_reading' => 150]);

    app(CloseBillingMonth::class)->handle($organization, $billingPeriod);

    expect($billingPeriod->refresh()->status)->toBe(BillingPeriodStatus::Closed)
        ->and((int) $reading->refresh()->consumption)->toBe(50);
});

// --- Квитанции --------------------------------------------------------------

test('визит без показания не влияет на объём квитанции по второму счётчику', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = visitOrganization();
    $billingPeriod = billingPeriodFor($organization);
    visitActingAs($organization);

    $readMeter = visitMeter($organization, $utilityService);
    $unreadMeter = Meter::factory()
        ->for($organization)
        ->for($readMeter->client)
        ->for($utilityService)
        ->create(['initial_reading' => 500, 'status' => 'active']);

    $readMeter->readings()->create([
        'billing_period_id' => $billingPeriod->getKey(),
        'current_reading' => 180,
    ]);

    visitWithoutReading($unreadMeter, $billingPeriod);

    $receipt = $readMeter->client->receipts()
        ->where('billing_period_id', $billingPeriod->id)
        ->firstOrFail();

    expect((float) $receipt->volume)->toBe(80.0)
        ->and((float) $receipt->amount)->toBe(8000.0);
});

// --- Отчёты -----------------------------------------------------------------

test('счётчик с визитом без показания остаётся в отчёте о не снятых', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = visitOrganization();
    $billingPeriod = billingPeriodFor($organization);
    $operator = visitActingAs($organization);

    $meter = visitMeter($organization, $utilityService);
    visitWithoutReading($meter, $billingPeriod);

    Livewire::test(ViewReport::class, ['report' => 'missing-meter-readings'])
        ->assertOk()
        ->assertCanSeeTableRecords([$meter]);

    $records = collect(app(ReportSummaryService::class)->records(
        'missing-meter-readings',
        ReportSummaryGroup::City,
        $organization,
        $operator,
        $billingPeriod,
    ))->keyBy('group_label');

    expect($records->get('Итого')['missing_meters_count'] ?? null)->toBe(1);
});

test('визит без показания не попадает в отчёт о расходе', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = visitOrganization();
    $billingPeriod = billingPeriodFor($organization);
    $operator = visitActingAs($organization);

    $visit = visitWithoutReading(visitMeter($organization, $utilityService), $billingPeriod);

    // Рядом — реально снятый счётчик, чтобы итог доказывал исключение визита,
    // а не просто пустой отчёт.
    $readMeter = visitMeter($organization, $utilityService);
    $reading = $readMeter->readings()->create([
        'billing_period_id' => $billingPeriod->getKey(),
        'current_reading' => 150,
    ]);

    Livewire::test(ViewReport::class, ['report' => 'consumption'])
        ->assertOk()
        ->assertCanSeeTableRecords([$reading])
        ->assertCanNotSeeTableRecords([$visit]);

    $records = collect(app(ReportSummaryService::class)->records(
        'consumption',
        ReportSummaryGroup::City,
        $organization,
        $operator,
        $billingPeriod,
    ))->keyBy('group_label');

    expect($records->get('Итого')['readings_count'])->toBe(1)
        ->and((int) $records->get('Итого')['consumption'])->toBe(50);
});

test('визит без показания не поднимает процент снятия у контроллера', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'region' => $region] = visitOrganization();
    $billingPeriod = billingPeriodFor($organization);

    $meter = visitMeter($organization, $utilityService, ['region_id' => $region->id]);
    visitWithoutReading($meter, $billingPeriod);

    $operator = visitActingAs($organization);

    $controller = User::factory()->create();
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

    $progress = collect(app(DashboardMetrics::class)
        ->controllerProgress($organization, $billingPeriod, $operator))
        ->firstWhere('controller_id', $controller->id);

    expect($progress['total'])->toBe(1)
        ->and($progress['taken'])->toBe(0)
        ->and($progress['missing'])->toBe(1);
});

test('предыдущее показание в ведомости снятия не берётся из визита без показания', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = visitOrganization();
    $may = billingPeriodFor($organization, '202605');
    visitActingAs($organization);

    $meter = visitMeter($organization, $utilityService);
    $meter->readings()->create([
        'billing_period_id' => $may->getKey(),
        'current_reading' => 150,
    ]);

    closedBillingPeriodFor($organization, '202605');
    $june = billingPeriodFor($organization, '202606');
    visitWithoutReading($meter, $june);

    Livewire::test(ViewReport::class, ['report' => 'meter-reading-sheet'])
        ->assertOk()
        ->assertTableColumnStateSet('previous_reading_for_report', 150, $meter);
});

// --- Дашборд ----------------------------------------------------------------

test('визит без показания не считается снятым на дашборде', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'region' => $region] = visitOrganization();
    $billingPeriod = billingPeriodFor($organization);

    $meter = visitMeter($organization, $utilityService, ['region_id' => $region->id]);
    visitWithoutReading($meter, $billingPeriod);

    $operator = visitActingAs($organization);

    $metrics = app(DashboardMetrics::class)->operations($organization, $billingPeriod, $operator);

    expect($metrics['meters_metered'])->toBe(1)
        ->and($metrics['readings_taken'])->toBe(0)
        ->and($metrics['consumption'])->toBe(0);

    $breakdown = collect(app(DashboardMetrics::class)->regionBreakdown($organization, $billingPeriod))
        ->firstWhere('region_id', $region->id);

    expect($breakdown['readings_percent'])->toBe(0.0);
});
