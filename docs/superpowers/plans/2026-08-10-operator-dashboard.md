# Дашборд организации — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сделать стартовой страницей панели дашборд организации, который за выбранный расчётный месяц показывает абонентов, снятие показаний, потребление, начисления, оплаты, долг, динамику по месяцам, прогресс контроллеров и срез по районам.

**Architecture:** Вся арифметика живёт в одном сервисе `App\Dashboard\DashboardMetrics`, который принимает организацию, расчётный месяц и пользователя и отдаёт готовые массивы. Filament-виджеты только отрисовывают эти массивы и скрываются по роли через статический `canView()`. Страница `App\Filament\Pages\Dashboard` наследует `Filament\Pages\Dashboard`, занимает путь `/` внутри tenant и хранит выбранный месяц в форме фильтров.

**Tech Stack:** PHP 8.4, Laravel 13, Filament 5 (`filament/widgets`: `StatsOverviewWidget`, `ChartWidget`, `TableWidget`), Livewire 4, Pest 4, MariaDB 11.8, Tailwind 4.

Спецификация: `docs/superpowers/specs/2026-08-10-operator-dashboard-design.md`.

## Global Constraints

- Язык интерфейса, документации и сообщений — русский.
- PHP: фигурные скобки всегда, promotion в конструкторе, явные типы параметров и возврата, PHPDoc с array shapes вместо строчных комментариев.
- Новые файлы повторяют структуру и стиль соседних файлов (`app/Reports/*`, `app/Filament/Support/*`).
- После изменения PHP-файлов запускать `vendor/bin/pint --dirty --format agent` (в контейнере, см. ниже).
- Каждая задача заканчивается зелёными тестами и коммитом.
- Документация обновляется в той же задаче, что и поведение (`.ai/project-documentation`).
- Изменения UI отражаются в `resources/views/design-preview.blade.php` (`.ai/ui-design-preview`).
- Роль `controller` не должна получить доступ к оплатам, начислениям и квитанциям — это правило из `docs/business-rules.md`.

### Как запускать тесты в этом worktree

Штатная команда проекта — `make test`. Она выполняет `docker compose exec app`, а работающий контейнер `esepteu` смонтировал **основной репозиторий**, а не worktree, поэтому из worktree она проверит чужой код.

В этом worktree тесты запускаются одноразовым контейнером из того же образа с примонтированным worktree и `vendor/` основного репозитория (в worktree своего `vendor/` нет). Файл `.env` уже скопирован из основного репозитория.

```bash
cd /home/magzhan9292/PhpstormProjects/Projects/noticeup/esepteu/.claude/worktrees/operator-admin-dashboard-2f59d1 && set -a && source .env && set +a && docker run --rm --network esepteu_default -u "$(id -u):$(id -g)" -v "$PWD":/var/www/html -v /home/magzhan9292/PhpstormProjects/Projects/noticeup/esepteu/vendor:/var/www/html/vendor -w /var/www/html -e APP_ENV=testing -e APP_DEBUG=true -e "APP_KEY=$APP_KEY" -e DB_CONNECTION=mysql -e DB_HOST=db -e DB_PORT=3306 -e DB_DATABASE="${MARIADB_TEST_DATABASE:-laravel_app_testing}" -e DB_USERNAME="$DB_USERNAME" -e DB_PASSWORD="$DB_PASSWORD" -e QUEUE_CONNECTION=sync -e CACHE_STORE=array -e SESSION_DRIVER=array esepteu-app php artisan test --compact --filter=DashboardMetricsTest
```

Дальше в плане эта команда обозначается как `<test> --filter=X`. Меняется только хвост после `php artisan test --compact`.

Pint запускается тем же способом:

```bash
cd /home/magzhan9292/PhpstormProjects/Projects/noticeup/esepteu/.claude/worktrees/operator-admin-dashboard-2f59d1 && docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/var/www/html -v /home/magzhan9292/PhpstormProjects/Projects/noticeup/esepteu/vendor:/var/www/html/vendor -w /var/www/html esepteu-app vendor/bin/pint --dirty --format agent
```

Дальше обозначается как `<pint>`.

## Структура файлов

| Файл | Ответственность |
| --- | --- |
| `app/Dashboard/DashboardMetrics.php` (создать) | Все агрегаты дашборда: `operations()`, `finance()`, `monthlyTotals()`, `controllerProgress()`, `regionBreakdown()` |
| `app/Support/ControllerZoneMeterCounts.php` (создать) | Коррелированный подзапрос «счётчики в зоне контроллера», общий для отчёта и дашборда |
| `app/Reports/ControllerMeterReadingProgressReport.php` (изменить) | Делегирует подсчёт счётчиков в `ControllerZoneMeterCounts`, поведение не меняется |
| `app/Filament/Support/DashboardBillingPeriod.php` (создать) | Опции селектора месяца, месяц по умолчанию, безопасное разрешение идентификатора из состояния Livewire |
| `app/Filament/Pages/Dashboard.php` (создать) | Страница, форма фильтров, список виджетов, сетка |
| `app/Filament/Widgets/DashboardStatsWidget.php` (создать) | Плитки абонентов, счётчиков, снятия, потребления |
| `app/Filament/Widgets/DashboardFinanceStatsWidget.php` (создать) | Плитки начислений, оплат, долга; только оператор |
| `app/Filament/Widgets/DashboardChargesChartWidget.php` (создать) | Столбчатый график за 12 месяцев; только оператор |
| `app/Filament/Widgets/DashboardControllerProgressWidget.php` (создать) | Таблица прогресса снятия |
| `app/Filament/Widgets/DashboardRegionBreakdownWidget.php` (создать) | Таблица среза по районам; только оператор |
| `app/Providers/Filament/AdminPanelProvider.php` (изменить) | Убрать редирект с `/`, добавить страницу в scopes плашки месяца |
| `tests/Feature/DashboardMetricsTest.php` (создать) | Тесты сервиса |
| `tests/Feature/DashboardTest.php` (создать) | Тесты страницы и виджетов |
| `docs/modules/dashboard.md` (создать) | Модульная документация |
| `docs/business-rules.md`, `docs/technical-specification.md`, `docs/changelog.md` (изменить) | Бизнес-правила и запись об изменении |
| `resources/views/design-preview.blade.php` (изменить) | Секция дашборда в превью |

---

### Task 1: Операционные метрики дашборда

**Files:**
- Create: `app/Dashboard/DashboardMetrics.php`
- Test: `tests/Feature/DashboardMetricsTest.php`

**Interfaces:**
- Consumes: `Client::scopeVisibleToOrganizationMember()`, `Meter::scopeVisibleToOrganizationMember()`, `MeterReading::scopeVisibleToOrganizationMember()`.
- Produces: `App\Dashboard\DashboardMetrics::operations(Organization $organization, BillingPeriod $billingPeriod, User $user): array` — ключи `clients_active`, `clients_total`, `clients_new`, `meters_active`, `meters_metered`, `readings_taken`, `readings_expected`, `readings_percent`, `consumption`. Приватные помощники `periodRange()` и `percent()` используются задачами 2–4.

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Feature/DashboardMetricsTest.php`:

```php
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

    return $organization;
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
    $city = City::factory()->create([
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
```

- [ ] **Step 2: Запустить тест и убедиться, что он падает**

Run: `<test> --filter=DashboardMetricsTest`
Expected: FAIL — `Class "App\Dashboard\DashboardMetrics" does not exist`.

- [ ] **Step 3: Написать сервис**

Создать `app/Dashboard/DashboardMetrics.php`:

```php
<?php

namespace App\Dashboard;

use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class DashboardMetrics
{
    /**
     * Operational figures of one billing period, limited to what the member may see.
     *
     * @return array{
     *     clients_active:int, clients_total:int, clients_new:int,
     *     meters_active:int, meters_metered:int,
     *     readings_taken:int, readings_expected:int, readings_percent:float,
     *     consumption:int
     * }
     */
    public function operations(Organization $organization, BillingPeriod $billingPeriod, User $user): array
    {
        [$periodStartsAt, $periodEndsAt] = $this->periodRange($billingPeriod);

        $clientsTotal = $this->visibleClients($organization, $user)->count();
        $clientsActive = $this->visibleClients($organization, $user)
            ->where('clients.status', 'active')
            ->count();
        $clientsNew = $this->visibleClients($organization, $user)
            ->whereBetween('clients.created_at', [$periodStartsAt, $periodEndsAt])
            ->count();

        $metersActive = $this->visibleActiveMeters($organization, $user)->count();
        $metersMetered = $this->visibleActiveMeters($organization, $user)
            ->whereHas('client', fn (Builder $query): Builder => $query->where('clients.billing_type', 'meter'))
            ->count();

        $readingsTaken = $this->visibleActiveMeters($organization, $user)
            ->whereHas('client', fn (Builder $query): Builder => $query->where('clients.billing_type', 'meter'))
            ->whereHas(
                'readings',
                fn (Builder $query): Builder => $query->where('meter_readings.billing_period_id', $billingPeriod->getKey()),
            )
            ->count();

        $consumption = (int) MeterReading::query()
            ->visibleToOrganizationMember($user, $organization)
            ->where('meter_readings.billing_period_id', $billingPeriod->getKey())
            ->sum('meter_readings.consumption');

        return [
            'clients_active' => $clientsActive,
            'clients_total' => $clientsTotal,
            'clients_new' => $clientsNew,
            'meters_active' => $metersActive,
            'meters_metered' => $metersMetered,
            'readings_taken' => $readingsTaken,
            'readings_expected' => $metersMetered,
            'readings_percent' => $this->percent($readingsTaken, $metersMetered),
            'consumption' => $consumption,
        ];
    }

    /**
     * @return Builder<Client>
     */
    private function visibleClients(Organization $organization, User $user): Builder
    {
        return Client::query()->visibleToOrganizationMember($user, $organization);
    }

    /**
     * @return Builder<Meter>
     */
    private function visibleActiveMeters(Organization $organization, User $user): Builder
    {
        return Meter::query()
            ->visibleToOrganizationMember($user, $organization)
            ->where('meters.status', 'active')
            ->whereHas('client', fn (Builder $query): Builder => $query->where('clients.status', 'active'));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function periodRange(BillingPeriod $billingPeriod): array
    {
        $startsOn = CarbonImmutable::instance($billingPeriod->starts_on)->startOfMonth();

        return [$startsOn->startOfDay(), $startsOn->endOfMonth()->endOfDay()];
    }

    private function percent(int $part, int $total): float
    {
        return $total === 0 ? 0.0 : round($part / $total * 100, 1);
    }
}
```

- [ ] **Step 4: Запустить тест и убедиться, что он проходит**

Run: `<test> --filter=DashboardMetricsTest`
Expected: PASS, 5 тестов.

- [ ] **Step 5: Pint и коммит**

```bash
<pint>
git add app/Dashboard/DashboardMetrics.php tests/Feature/DashboardMetricsTest.php
git commit -m "Добавлен сервис метрик дашборда: абоненты, счётчики, снятие показаний и потребление за расчётный месяц с учётом зоны ответственности контроллера."
```

---

### Task 2: Денежные метрики и динамика по месяцам

**Files:**
- Modify: `app/Dashboard/DashboardMetrics.php`
- Test: `tests/Feature/DashboardMetricsTest.php`

**Interfaces:**
- Consumes: `DashboardMetrics::percent()` из задачи 1.
- Produces: `finance(Organization $organization, BillingPeriod $billingPeriod): array` — ключи `charged`, `charged_is_preliminary`, `charged_documents`, `paid`, `payments_count`, `collection_percent`, `debt`, `debtors_count`. `monthlyTotals(Organization $organization, int $months = 12): array` — список `['period' => '202608', 'label' => '08.2026', 'charged' => float, 'paid' => float]`. Приватный `chargeTable(BillingPeriod): string` используется задачей 4.

- [ ] **Step 1: Написать падающие тесты**

Добавить в конец `tests/Feature/DashboardMetricsTest.php`:

```php
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
```

Добавить в начало файла `use App\Models\Accrual;`, `use App\Models\Payment;`, `use App\Models\Receipt;`.

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `<test> --filter=DashboardMetricsTest`
Expected: FAIL — `Call to undefined method App\Dashboard\DashboardMetrics::finance()`.

- [ ] **Step 3: Дописать сервис**

В `app/Dashboard/DashboardMetrics.php` добавить импорты `use App\BillingPeriodStatus;`, `use App\Models\Accrual;`, `use App\Models\Payment;`, `use App\Models\Receipt;` и методы после `operations()`:

```php
    /**
     * Money figures of one billing period. Only operators may see them, so the
     * member is not a parameter: an operator always sees the whole organization.
     *
     * @return array{
     *     charged:float, charged_is_preliminary:bool, charged_documents:int,
     *     paid:float, payments_count:int, collection_percent:float,
     *     debt:float, debtors_count:int
     * }
     */
    public function finance(Organization $organization, BillingPeriod $billingPeriod): array
    {
        $chargeRow = $this->chargeQuery($organization, $billingPeriod)
            ->selectRaw('coalesce(sum(amount), 0) as total, count(*) as documents')
            ->first();

        $debtRow = $this->chargeQuery($organization, $billingPeriod)
            ->where('closing_balance', '>', 0)
            ->selectRaw('coalesce(sum(closing_balance), 0) as total, count(*) as debtors')
            ->first();

        $paymentRow = Payment::query()
            ->toBase()
            ->where('organization_id', $organization->getKey())
            ->where('billing_period_id', $billingPeriod->getKey())
            ->selectRaw('coalesce(sum(amount), 0) as total, count(*) as payments')
            ->first();

        $charged = (float) ($chargeRow->total ?? 0);
        $paid = (float) ($paymentRow->total ?? 0);

        return [
            'charged' => $charged,
            'charged_is_preliminary' => $billingPeriod->status !== BillingPeriodStatus::Closed,
            'charged_documents' => (int) ($chargeRow->documents ?? 0),
            'paid' => $paid,
            'payments_count' => (int) ($paymentRow->payments ?? 0),
            'collection_percent' => $charged <= 0.0 ? 0.0 : round($paid / $charged * 100, 1),
            'debt' => (float) ($debtRow->total ?? 0),
            'debtors_count' => (int) ($debtRow->debtors ?? 0),
        ];
    }

    /**
     * Charged and paid totals of the latest billing periods, oldest first.
     *
     * @return list<array{period:string, label:string, charged:float, paid:float}>
     */
    public function monthlyTotals(Organization $organization, int $months = 12): array
    {
        $billingPeriods = BillingPeriod::query()
            ->forOrganization($organization)
            ->orderByDesc('starts_on')
            ->limit($months)
            ->get()
            ->sortBy('starts_on')
            ->values();

        if ($billingPeriods->isEmpty()) {
            return [];
        }

        $billingPeriodIds = $billingPeriods->modelKeys();

        $accrualTotals = $this->amountTotalsByBillingPeriod('accruals', $organization, $billingPeriodIds);
        $receiptTotals = $this->amountTotalsByBillingPeriod('receipts', $organization, $billingPeriodIds);
        $paymentTotals = $this->amountTotalsByBillingPeriod('payments', $organization, $billingPeriodIds);

        return $billingPeriods
            ->map(function (BillingPeriod $billingPeriod) use ($accrualTotals, $receiptTotals, $paymentTotals): array {
                $chargeTotals = $billingPeriod->status === BillingPeriodStatus::Closed
                    ? $accrualTotals
                    : $receiptTotals;

                return [
                    'period' => $billingPeriod->code,
                    'label' => $billingPeriod->label,
                    'charged' => (float) ($chargeTotals[$billingPeriod->getKey()] ?? 0),
                    'paid' => (float) ($paymentTotals[$billingPeriod->getKey()] ?? 0),
                ];
            })
            ->all();
    }

    /**
     * A closed period is the accrual of record; an open one only has receipts of
     * the clients whose readings are already in.
     */
    private function chargeTable(BillingPeriod $billingPeriod): string
    {
        return $billingPeriod->status === BillingPeriodStatus::Closed ? 'accruals' : 'receipts';
    }

    private function chargeQuery(Organization $organization, BillingPeriod $billingPeriod): QueryBuilder
    {
        $query = $this->chargeTable($billingPeriod) === 'accruals'
            ? Accrual::query()
            : Receipt::query();

        return $query
            ->toBase()
            ->where('organization_id', $organization->getKey())
            ->where('billing_period_id', $billingPeriod->getKey());
    }

    /**
     * @param  list<int>  $billingPeriodIds
     * @return array<int, float>
     */
    private function amountTotalsByBillingPeriod(string $table, Organization $organization, array $billingPeriodIds): array
    {
        return DB::table($table)
            ->where('organization_id', $organization->getKey())
            ->whereIn('billing_period_id', $billingPeriodIds)
            ->groupBy('billing_period_id')
            ->selectRaw('billing_period_id, coalesce(sum(amount), 0) as total')
            ->pluck('total', 'billing_period_id')
            ->map(fn (mixed $total): float => (float) $total)
            ->all();
    }
```

Добавить импорты `use Illuminate\Database\Query\Builder as QueryBuilder;` и `use Illuminate\Support\Facades\DB;`.

- [ ] **Step 4: Запустить тесты**

Run: `<test> --filter=DashboardMetricsTest`
Expected: PASS, 9 тестов.

- [ ] **Step 5: Pint и коммит**

```bash
<pint>
git add app/Dashboard/DashboardMetrics.php tests/Feature/DashboardMetricsTest.php
git commit -m "В сервис метрик дашборда добавлены денежные показатели и динамика по месяцам: начисление закрытого месяца берётся из начислений, открытого — из квитанций."
```

---

### Task 3: Прогресс снятия по контроллерам

Подсчёт «счётчики в зоне контроллера» уже реализован в `App\Reports\ControllerMeterReadingProgressReport::meterCountQuery()`. Дублировать 40 строк коррелированного SQL опасно: правило зоны разъедется между отчётом и дашбордом. Поэтому запрос выносится в общий класс, а отчёт начинает его использовать без изменения поведения — это отклонение от строки спецификации «дашборд строит собственные агрегатные запросы», и его надо отразить в документации задачи 8.

**Files:**
- Create: `app/Support/ControllerZoneMeterCounts.php`
- Modify: `app/Reports/ControllerMeterReadingProgressReport.php` (метод `meterCountQuery()` и импорты)
- Modify: `app/Dashboard/DashboardMetrics.php`
- Test: `tests/Feature/DashboardMetricsTest.php`

**Interfaces:**
- Produces: `App\Support\ControllerZoneMeterCounts::query(Organization $organization, ?BillingPeriod $billingPeriod = null): Builder` — коррелированный подзапрос `count(distinct meters.id)`, связанный с внешней колонкой `users.id`. `DashboardMetrics::controllerProgress(Organization $organization, BillingPeriod $billingPeriod, User $user): array` — список `['controller_id' => int, 'name' => string, 'email' => string, 'total' => int, 'taken' => int, 'missing' => int, 'percent' => float]`.

- [ ] **Step 1: Написать падающие тесты**

Добавить в конец `tests/Feature/DashboardMetricsTest.php`:

```php
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
```

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `<test> --filter=DashboardMetricsTest`
Expected: FAIL — `Call to undefined method App\Dashboard\DashboardMetrics::controllerProgress()`.

- [ ] **Step 3: Вынести общий подзапрос**

Создать `app/Support/ControllerZoneMeterCounts.php`:

```php
<?php

namespace App\Support;

use App\Models\BillingPeriod;
use App\Models\Meter;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Counts of the active metered meters in a controller's zone.
 *
 * The query is correlated to the outer `users.id` column, so it can only be used
 * as a sub-select of a query that selects from `users`.
 */
final class ControllerZoneMeterCounts
{
    /**
     * @return Builder<Meter>
     */
    public static function query(Organization $organization, ?BillingPeriod $billingPeriod = null): Builder
    {
        $query = Meter::query()
            ->selectRaw('count(distinct meters.id)')
            ->join('clients', 'clients.id', '=', 'meters.client_id')
            ->where('meters.organization_id', $organization->getKey())
            ->where('meters.status', 'active')
            ->where('clients.status', 'active')
            ->where('clients.billing_type', 'meter')
            ->where(function (Builder $query) use ($organization): void {
                $query
                    ->whereExists(function (QueryBuilder $query) use ($organization): void {
                        $query
                            ->selectRaw('1')
                            ->from('organization_user_regions')
                            ->where('organization_user_regions.organization_id', $organization->getKey())
                            ->whereColumn('organization_user_regions.user_id', 'users.id')
                            ->whereColumn('organization_user_regions.region_id', 'clients.region_id');
                    })
                    ->orWhereExists(function (QueryBuilder $query) use ($organization): void {
                        $query
                            ->selectRaw('1')
                            ->from('organization_user_streets')
                            ->where('organization_user_streets.organization_id', $organization->getKey())
                            ->whereColumn('organization_user_streets.user_id', 'users.id')
                            ->whereColumn('organization_user_streets.street_id', 'clients.street_id');
                    });
            });

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query;
        }

        return $query->whereExists(function (QueryBuilder $query) use ($billingPeriod): void {
            $query
                ->selectRaw('1')
                ->from('meter_readings')
                ->whereColumn('meter_readings.meter_id', 'meters.id')
                ->where('meter_readings.billing_period_id', $billingPeriod->getKey());
        });
    }
}
```

В `app/Reports/ControllerMeterReadingProgressReport.php` заменить тело метода `meterCountQuery()` на делегирование:

```php
    private function meterCountQuery(Organization $organization, ?BillingPeriod $billingPeriod = null): Builder
    {
        return ControllerZoneMeterCounts::query($organization, $billingPeriod);
    }
```

Добавить `use App\Support\ControllerZoneMeterCounts;` и удалить импорты, которые перестали использоваться в файле (проверить `Meter`, `QueryBuilder`, `DB` — часть из них ещё нужна для `assignedRegionNames()` и `assignedStreetNames()`, поэтому удалять только те, на которые больше нет ссылок).

- [ ] **Step 4: Проверить, что отчёт не сломался**

Run: `<test> --filter=OrganizationReportsTest`
Expected: PASS — все существующие тесты отчётов зелёные.

- [ ] **Step 5: Дописать сервис**

В `app/Dashboard/DashboardMetrics.php` добавить импорты `use App\OrganizationMemberRole;`, `use App\Support\ControllerZoneMeterCounts;` и метод:

```php
    /**
     * Meter reading progress of every controller of the organization.
     *
     * A controller only ever sees their own row.
     *
     * @return list<array{
     *     controller_id:int, name:string, email:string,
     *     total:int, taken:int, missing:int, percent:float
     * }>
     */
    public function controllerProgress(Organization $organization, BillingPeriod $billingPeriod, User $user): array
    {
        $query = User::query()
            ->select(['users.id', 'users.name', 'users.email'])
            ->join('organization_user', 'organization_user.user_id', '=', 'users.id')
            ->where('organization_user.organization_id', $organization->getKey())
            ->where('organization_user.role', OrganizationMemberRole::Controller->value)
            ->addSelect([
                'zone_meters_total' => ControllerZoneMeterCounts::query($organization),
                'zone_meters_taken' => ControllerZoneMeterCounts::query($organization, $billingPeriod),
            ])
            ->orderBy('users.name')
            ->orderBy('users.id');

        if ($user->isOrganizationController($organization)) {
            $query->where('users.id', $user->getKey());
        }

        return $query->get()
            ->map(function (User $controller): array {
                $total = (int) $controller->getAttribute('zone_meters_total');
                $taken = (int) $controller->getAttribute('zone_meters_taken');

                return [
                    'controller_id' => (int) $controller->getKey(),
                    'name' => (string) $controller->name,
                    'email' => (string) $controller->email,
                    'total' => $total,
                    'taken' => $taken,
                    'missing' => max($total - $taken, 0),
                    'percent' => $this->percent($taken, $total),
                ];
            })
            ->all();
    }
```

- [ ] **Step 6: Запустить тесты**

Run: `<test> --filter=DashboardMetricsTest`
Expected: PASS, 12 тестов.

- [ ] **Step 7: Pint и коммит**

```bash
<pint>
git add app/Support/ControllerZoneMeterCounts.php app/Reports/ControllerMeterReadingProgressReport.php app/Dashboard/DashboardMetrics.php tests/Feature/DashboardMetricsTest.php
git commit -m "Подсчёт счётчиков в зоне контроллера вынесен в общий класс и переиспользован в дашборде: отчёт «Процент снятия по контроллерам» и дашборд считают зону одинаково."
```

---

### Task 4: Срез по районам

**Files:**
- Modify: `app/Dashboard/DashboardMetrics.php`
- Test: `tests/Feature/DashboardMetricsTest.php`

**Interfaces:**
- Consumes: `DashboardMetrics::chargeTable()` и `percent()` из задач 1–2.
- Produces: `regionBreakdown(Organization $organization, BillingPeriod $billingPeriod): array` — список `['region_id' => int, 'region' => string, 'city' => string, 'clients' => int, 'readings_percent' => float, 'charged' => float, 'paid' => float, 'debt' => float]`, отсортированный по `debt` по убыванию, затем по названию района.

- [ ] **Step 1: Написать падающие тесты**

Добавить в конец `tests/Feature/DashboardMetricsTest.php`:

```php
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
        'client_id' => $firstClient->id,
        'billing_period_id' => $billingPeriod->id,
        'period' => null,
        'amount' => 100,
        'closing_balance' => 100,
    ]);

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
```

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `<test> --filter=DashboardMetricsTest`
Expected: FAIL — `Call to undefined method App\Dashboard\DashboardMetrics::regionBreakdown()`.

- [ ] **Step 3: Дописать сервис**

В `app/Dashboard/DashboardMetrics.php` добавить импорт `use App\Models\Region;` и методы:

```php
    /**
     * Per region totals of one billing period, biggest debt first.
     *
     * Only operators may see money, so the member is not a parameter.
     *
     * @return list<array{
     *     region_id:int, region:string, city:string, clients:int,
     *     readings_percent:float, charged:float, paid:float, debt:float
     * }>
     */
    public function regionBreakdown(Organization $organization, BillingPeriod $billingPeriod): array
    {
        $chargeTable = $this->chargeTable($billingPeriod);
        $organizationId = (int) $organization->getKey();
        $billingPeriodId = (int) $billingPeriod->getKey();

        $rows = Region::query()
            ->select(['regions.id', 'regions.name'])
            ->leftJoin('cities', 'cities.id', '=', 'regions.city_id')
            ->addSelect(['cities.name as city_name'])
            ->where('regions.organization_id', $organizationId)
            ->selectSub($this->regionClientCountQuery(), 'clients_count')
            ->selectSub($this->regionMeterCountQuery($organizationId), 'meters_total')
            ->selectSub($this->regionMeterCountQuery($organizationId, $billingPeriodId), 'meters_taken')
            ->selectSub($this->regionChargeQuery($chargeTable, $organizationId, $billingPeriodId, onlyDebt: false), 'charged')
            ->selectSub($this->regionChargeQuery($chargeTable, $organizationId, $billingPeriodId, onlyDebt: true), 'debt')
            ->selectSub($this->regionPaymentQuery($organizationId, $billingPeriodId), 'paid')
            ->orderBy('regions.name')
            ->get();

        return $rows
            ->filter(fn (Region $region): bool => (int) $region->getAttribute('clients_count') > 0)
            ->map(fn (Region $region): array => [
                'region_id' => (int) $region->getKey(),
                'region' => (string) $region->name,
                'city' => (string) ($region->getAttribute('city_name') ?? ''),
                'clients' => (int) $region->getAttribute('clients_count'),
                'readings_percent' => $this->percent(
                    (int) $region->getAttribute('meters_taken'),
                    (int) $region->getAttribute('meters_total'),
                ),
                'charged' => (float) $region->getAttribute('charged'),
                'paid' => (float) $region->getAttribute('paid'),
                'debt' => (float) $region->getAttribute('debt'),
            ])
            ->sortByDesc('debt')
            ->values()
            ->all();
    }

    private function regionClientCountQuery(): QueryBuilder
    {
        return DB::table('clients')
            ->selectRaw('count(*)')
            ->whereColumn('clients.region_id', 'regions.id')
            ->where('clients.status', 'active');
    }

    private function regionMeterCountQuery(int $organizationId, ?int $billingPeriodId = null): QueryBuilder
    {
        $query = DB::table('meters')
            ->selectRaw('count(*)')
            ->join('clients', 'clients.id', '=', 'meters.client_id')
            ->whereColumn('clients.region_id', 'regions.id')
            ->where('meters.organization_id', $organizationId)
            ->where('meters.status', 'active')
            ->where('clients.status', 'active')
            ->where('clients.billing_type', 'meter');

        if ($billingPeriodId === null) {
            return $query;
        }

        return $query->whereExists(function (QueryBuilder $query) use ($billingPeriodId): void {
            $query
                ->selectRaw('1')
                ->from('meter_readings')
                ->whereColumn('meter_readings.meter_id', 'meters.id')
                ->where('meter_readings.billing_period_id', $billingPeriodId);
        });
    }

    private function regionChargeQuery(string $chargeTable, int $organizationId, int $billingPeriodId, bool $onlyDebt): QueryBuilder
    {
        $column = $onlyDebt ? 'closing_balance' : 'amount';

        $query = DB::table($chargeTable)
            ->selectRaw("coalesce(sum({$chargeTable}.{$column}), 0)")
            ->join('clients', 'clients.id', '=', $chargeTable.'.client_id')
            ->whereColumn('clients.region_id', 'regions.id')
            ->where($chargeTable.'.organization_id', $organizationId)
            ->where($chargeTable.'.billing_period_id', $billingPeriodId);

        if ($onlyDebt) {
            $query->where($chargeTable.'.closing_balance', '>', 0);
        }

        return $query;
    }

    private function regionPaymentQuery(int $organizationId, int $billingPeriodId): QueryBuilder
    {
        return DB::table('payments')
            ->selectRaw('coalesce(sum(payments.amount), 0)')
            ->join('clients', 'clients.id', '=', 'payments.client_id')
            ->whereColumn('clients.region_id', 'regions.id')
            ->where('payments.organization_id', $organizationId)
            ->where('payments.billing_period_id', $billingPeriodId);
    }
```

Имена таблиц и колонок в `selectRaw()` приходят только из этого класса — `$chargeTable` принимает значения `accruals` или `receipts`, `$column` — `amount` или `closing_balance`, — поэтому подстановки безопасны.

- [ ] **Step 4: Запустить тесты**

Run: `<test> --filter=DashboardMetricsTest`
Expected: PASS, 14 тестов.

- [ ] **Step 5: Pint и коммит**

```bash
<pint>
git add app/Dashboard/DashboardMetrics.php tests/Feature/DashboardMetricsTest.php
git commit -m "В сервис метрик дашборда добавлен срез по районам: абоненты, процент снятия, начислено, оплачено и долг с сортировкой по долгу."
```

---

### Task 5: Страница дашборда и селектор расчётного месяца

**Files:**
- Create: `app/Filament/Support/DashboardBillingPeriod.php`
- Create: `app/Filament/Pages/Dashboard.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `App\Filament\Support\BillingPeriodOptions`, `App\Filament\Support\FilterIdentifiers::one()`.
- Produces: `DashboardBillingPeriod::options(Organization $organization): array<int, string>`, `DashboardBillingPeriod::default(Organization $organization): ?BillingPeriod`, `DashboardBillingPeriod::resolve(Organization $organization, mixed $billingPeriodId): ?BillingPeriod`. Страница `App\Filament\Pages\Dashboard` с публичным свойством `filters` и методом `getWidgets()`.

- [ ] **Step 1: Написать падающие тесты**

Создать `tests/Feature/DashboardTest.php`:

```php
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

    return $organization;
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
```

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `<test> --filter=DashboardTest`
Expected: FAIL — `Class "App\Filament\Pages\Dashboard" does not exist`.

- [ ] **Step 3: Написать резолвер месяца**

Создать `app/Filament/Support/DashboardBillingPeriod.php`:

```php
<?php

namespace App\Filament\Support;

use App\Models\BillingPeriod;
use App\Models\Organization;

/**
 * The billing period the dashboard is showing.
 *
 * The selected identifier comes from Livewire state and can be tampered with, so
 * only a period of the current organization is ever accepted; anything else falls
 * back to the default period.
 */
final class DashboardBillingPeriod
{
    /**
     * @return array<int, string>
     */
    public static function options(Organization $organization): array
    {
        return $organization->billingPeriods()
            ->orderByDesc('starts_on')
            ->get()
            ->mapWithKeys(fn (BillingPeriod $billingPeriod): array => [
                $billingPeriod->getKey() => $billingPeriod->label.' — '.$billingPeriod->status->getLabel(),
            ])
            ->all();
    }

    public static function default(Organization $organization): ?BillingPeriod
    {
        return BillingPeriod::currentEditableFor($organization)
            ?? $organization->billingPeriods()
                ->orderByDesc('starts_on')
                ->first();
    }

    public static function resolve(Organization $organization, mixed $billingPeriodId): ?BillingPeriod
    {
        $identifier = FilterIdentifiers::one($billingPeriodId);

        if ($identifier === null) {
            return self::default($organization);
        }

        return $organization->billingPeriods()
            ->whereKey($identifier)
            ->first()
            ?? self::default($organization);
    }
}
```

- [ ] **Step 4: Написать страницу**

Создать `app/Filament/Pages/Dashboard.php`:

```php
<?php

namespace App\Filament\Pages;

use App\Filament\Support\DashboardBillingPeriod;
use App\Models\Organization;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $title = 'Дашборд';

    protected static ?string $navigationLabel = 'Дашборд';

    protected static ?int $navigationSort = -100;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('billing_period_id')
                ->label('Расчётный месяц')
                ->options(fn (): array => $this->billingPeriodOptions())
                ->default(fn (): ?int => $this->defaultBillingPeriodId())
                ->selectablePlaceholder(false)
                ->native(false),
        ]);
    }

    /**
     * @return int|array<string, ?int>
     */
    public function getColumns(): int|array
    {
        return 4;
    }

    /**
     * @return array<int, string>
     */
    private function billingPeriodOptions(): array
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization
            ? DashboardBillingPeriod::options($tenant)
            : [];
    }

    private function defaultBillingPeriodId(): ?int
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization) {
            return null;
        }

        $billingPeriod = DashboardBillingPeriod::default($tenant);

        return $billingPeriod === null ? null : (int) $billingPeriod->getKey();
    }
}
```

Виджеты добавляются в задачах 6–7, поэтому `getWidgets()` пока не переопределяется: базовый `Filament\Pages\Dashboard::getWidgets()` вернёт пустой список, потому что панель не регистрирует виджеты.

- [ ] **Step 5: Освободить корневой маршрут**

В `app/Providers/Filament/AdminPanelProvider.php`:

1. Добавить импорт `use App\Filament\Pages\Dashboard;`.
2. Удалить импорты `use App\Filament\Resources\Clients\ClientResource;` и `use Illuminate\Http\RedirectResponse;`.
3. В `authenticatedTenantRoutes()` удалить блок:

```php
                Route::get('/', function (): RedirectResponse {
                    return redirect()->to(ClientResource::getUrl(panel: 'admin', tenant: Filament::getTenant()));
                })->name('home');
```

4. В массив `scopes` рендер-хука `PanelsRenderHook::PAGE_START` добавить `Dashboard::class` между `CreatePayment::class` и `EditClient::class`.

- [ ] **Step 6: Запустить тесты**

Run: `<test> --filter=DashboardTest`
Expected: PASS, 6 тестов.

- [ ] **Step 7: Проверить, что панель не сломалась**

Run: `<test> --filter=FilamentCrudTest`
Expected: PASS.

Run: `<test> --filter=OrganizationTenancyTest`
Expected: PASS.

- [ ] **Step 8: Pint и коммит**

```bash
<pint>
git add app/Filament/Support/DashboardBillingPeriod.php app/Filament/Pages/Dashboard.php app/Providers/Filament/AdminPanelProvider.php tests/Feature/DashboardTest.php
git commit -m "Дашборд стал стартовой страницей панели: корневой маршрут организации больше не перенаправляет на список абонентов, добавлен селектор расчётного месяца с защитой от подмены идентификатора."
```

---

### Task 6: Плитки дашборда

**Files:**
- Create: `app/Filament/Widgets/DashboardStatsWidget.php`
- Create: `app/Filament/Widgets/DashboardFinanceStatsWidget.php`
- Modify: `app/Filament/Pages/Dashboard.php` (добавить `getWidgets()`)
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `DashboardMetrics::operations()`, `DashboardMetrics::finance()`, `DashboardBillingPeriod::resolve()`.
- Produces: `DashboardStatsWidget::canView(): bool` (любой участник), `DashboardFinanceStatsWidget::canView(): bool` (только оператор). Оба виджета читают `$this->pageFilters['billing_period_id']`.

- [ ] **Step 1: Написать падающие тесты**

Добавить в конец `tests/Feature/DashboardTest.php`:

```php
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
```

Добавить в начало файла импорты `use App\Filament\Widgets\DashboardFinanceStatsWidget;`, `use App\Filament\Widgets\DashboardStatsWidget;`, `use App\Models\City;`, `use App\Models\Client;`, `use App\Models\Meter;`, `use App\Models\MeterReading;`, `use App\Models\Region;`.

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `<test> --filter=DashboardTest`
Expected: FAIL — `Class "App\Filament\Widgets\DashboardStatsWidget" does not exist`.

- [ ] **Step 3: Написать операционные плитки**

Создать `app/Filament/Widgets/DashboardStatsWidget.php`:

```php
<?php

namespace App\Filament\Widgets;

use App\Dashboard\DashboardMetrics;
use App\Filament\Support\DashboardBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Абоненты и снятие показаний';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return OrganizationMemberAccess::canAccessTenant();
    }

    /**
     * @return int|array<string, ?int>
     */
    protected function getColumns(): int|array
    {
        return 4;
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $organization = Filament::getTenant();
        $user = auth()->user();

        if (! $organization instanceof Organization || ! $user instanceof User) {
            return [];
        }

        $billingPeriod = DashboardBillingPeriod::resolve(
            $organization,
            $this->pageFilters['billing_period_id'] ?? null,
        );

        if (! $billingPeriod instanceof BillingPeriod) {
            return [];
        }

        $metrics = app(DashboardMetrics::class)->operations($organization, $billingPeriod, $user);
        $unit = $organization->utilityService?->unit_of_measurement;

        return [
            Stat::make('Абоненты', (string) $metrics['clients_active'])
                ->description("всего {$metrics['clients_total']} · новых за месяц {$metrics['clients_new']}")
                ->descriptionIcon(Heroicon::OutlinedUsers)
                ->color('primary'),
            Stat::make('Счётчики', (string) $metrics['meters_active'])
                ->description("по счётчику {$metrics['meters_metered']}")
                ->descriptionIcon(Heroicon::OutlinedCpuChip)
                ->color('primary'),
            Stat::make('Снято показаний', $this->formatPercent($metrics['readings_percent']))
                ->description("{$metrics['readings_taken']} из {$metrics['readings_expected']}")
                ->descriptionIcon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color($this->readingsColor($metrics['readings_percent'])),
            Stat::make('Потребление', (string) $metrics['consumption'])
                ->description($unit === null ? 'за выбранный месяц' : "за выбранный месяц, {$unit}")
                ->descriptionIcon(Heroicon::OutlinedChartBar)
                ->color('primary'),
        ];
    }

    private function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').' %';
    }

    private function readingsColor(float $percent): string
    {
        return match (true) {
            $percent < 70.0 => 'danger',
            $percent < 95.0 => 'warning',
            default => 'success',
        };
    }
}
```

- [ ] **Step 4: Написать денежные плитки**

Создать `app/Filament/Widgets/DashboardFinanceStatsWidget.php`:

```php
<?php

namespace App\Filament\Widgets;

use App\Dashboard\DashboardMetrics;
use App\Filament\Support\DashboardBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class DashboardFinanceStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Начисления и оплаты';

    protected static ?int $sort = 2;

    /**
     * Payments, accruals and receipts are operator-only data.
     */
    public static function canView(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    /**
     * @return int|array<string, ?int>
     */
    protected function getColumns(): int|array
    {
        return 4;
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $organization = Filament::getTenant();

        if (! $organization instanceof Organization) {
            return [];
        }

        $billingPeriod = DashboardBillingPeriod::resolve(
            $organization,
            $this->pageFilters['billing_period_id'] ?? null,
        );

        if (! $billingPeriod instanceof BillingPeriod) {
            return [];
        }

        $metrics = app(DashboardMetrics::class)->finance($organization, $billingPeriod);

        return [
            Stat::make('Начислено', $this->formatMoney($metrics['charged']))
                ->description($this->chargedDescription($metrics['charged_is_preliminary'], $metrics['charged_documents']))
                ->descriptionIcon(Heroicon::OutlinedDocumentText)
                ->color($metrics['charged_is_preliminary'] ? 'warning' : 'primary'),
            Stat::make('Оплачено', $this->formatMoney($metrics['paid']))
                ->description("{$metrics['payments_count']} оплат · сбор {$this->formatPercent($metrics['collection_percent'])}")
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('success'),
            Stat::make('Долг на конец месяца', $this->formatMoney($metrics['debt']))
                ->description("{$metrics['debtors_count']} абонентов")
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($metrics['debt'] > 0.0 ? 'danger' : 'success'),
        ];
    }

    private function chargedDescription(bool $isPreliminary, int $documents): string
    {
        return $isPreliminary
            ? "предварительно, по {$documents} квитанциям"
            : "по {$documents} начислениям";
    }

    private function formatMoney(float $amount): string
    {
        return Number::currency($amount, 'KZT', 'ru');
    }

    private function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').' %';
    }
}
```

- [ ] **Step 5: Подключить виджеты к странице**

В `app/Filament/Pages/Dashboard.php` добавить импорты `use App\Filament\Widgets\DashboardFinanceStatsWidget;`, `use App\Filament\Widgets\DashboardStatsWidget;`, `use Filament\Widgets\Widget;`, `use Filament\Widgets\WidgetConfiguration;` и метод перед `getColumns()`:

```php
    /**
     * @return array<class-string<Widget>|WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            DashboardStatsWidget::class,
            DashboardFinanceStatsWidget::class,
        ];
    }
```

- [ ] **Step 6: Запустить тесты**

Run: `<test> --filter=DashboardTest`
Expected: PASS, 10 тестов.

Если падает утверждение `assertSee('100 %')`, проверить `formatPercent()`: при `100.0` он должен вернуть `100 %`, при `50.0` — `50 %`, при `33.3` — `33.3 %`.

- [ ] **Step 7: Pint и коммит**

```bash
<pint>
git add app/Filament/Widgets/DashboardStatsWidget.php app/Filament/Widgets/DashboardFinanceStatsWidget.php app/Filament/Pages/Dashboard.php tests/Feature/DashboardTest.php
git commit -m "На дашборд добавлены плитки: абоненты, счётчики, процент снятия и потребление доступны всем участникам, начисления, оплаты и долг — только оператору."
```

---

### Task 7: График динамики и таблицы дашборда

**Files:**
- Create: `app/Filament/Widgets/DashboardChargesChartWidget.php`
- Create: `app/Filament/Widgets/DashboardControllerProgressWidget.php`
- Create: `app/Filament/Widgets/DashboardRegionBreakdownWidget.php`
- Modify: `app/Filament/Pages/Dashboard.php` (`getWidgets()`)
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `DashboardMetrics::monthlyTotals()`, `DashboardMetrics::controllerProgress()`, `DashboardMetrics::regionBreakdown()`.
- Produces: три виджета со статическим `canView()`; таблицы отдают записи через `Table::records()` массивом, ключ записи — идентификатор контроллера или района.

- [ ] **Step 1: Написать падающие тесты**

Добавить в конец `tests/Feature/DashboardTest.php`:

```php
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
    ]);

    Livewire::test(DashboardControllerProgressWidget::class, [
        'pageFilters' => ['billing_period_id' => $billingPeriod->getKey()],
    ])
        ->assertOk()
        ->assertSee('Абаев Абай')
        ->assertDontSee('Букеев Букей');
});
```

Добавить в начало файла импорты `use App\Filament\Widgets\DashboardChargesChartWidget;`, `use App\Filament\Widgets\DashboardControllerProgressWidget;`, `use App\Filament\Widgets\DashboardRegionBreakdownWidget;`, `use Illuminate\Support\Facades\DB;`.

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `<test> --filter=DashboardTest`
Expected: FAIL — `Class "App\Filament\Widgets\DashboardChargesChartWidget" does not exist`.

- [ ] **Step 3: Написать график**

Создать `app/Filament/Widgets/DashboardChargesChartWidget.php`:

```php
<?php

namespace App\Filament\Widgets;

use App\Dashboard\DashboardMetrics;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class DashboardChargesChartWidget extends ChartWidget
{
    protected ?string $heading = 'Начисления и оплаты по месяцам';

    protected ?string $description = 'Последние 12 расчётных месяцев организации.';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

    /**
     * Accruals, receipts and payments are operator-only data.
     */
    public static function canView(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $organization = Filament::getTenant();

        if (! $organization instanceof Organization) {
            return ['datasets' => [], 'labels' => []];
        }

        $totals = app(DashboardMetrics::class)->monthlyTotals($organization);

        return [
            'datasets' => [
                [
                    'label' => 'Начислено',
                    'data' => array_map(fn (array $total): float => $total['charged'], $totals),
                    'backgroundColor' => '#f59e0b',
                ],
                [
                    'label' => 'Оплачено',
                    'data' => array_map(fn (array $total): float => $total['paid'], $totals),
                    'backgroundColor' => '#10b981',
                ],
            ],
            'labels' => array_map(fn (array $total): string => $total['label'], $totals),
        ];
    }
}
```

- [ ] **Step 4: Написать таблицу прогресса контроллеров**

Создать `app/Filament/Widgets/DashboardControllerProgressWidget.php`:

```php
<?php

namespace App\Filament\Widgets;

use App\Dashboard\DashboardMetrics;
use App\Filament\Support\DashboardBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

class DashboardControllerProgressWidget extends TableWidget
{
    use InteractsWithPageFilters;

    /**
     * Rows shown on the dashboard; the full list lives in the report.
     */
    private const int ROW_LIMIT = 10;

    protected int|string|array $columnSpan = 2;

    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        return OrganizationMemberAccess::canAccessTenant();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Прогресс снятия по контроллерам')
            ->records(fn (): array => $this->records())
            ->columns([
                TextColumn::make('name')
                    ->label('Контроллер')
                    ->wrap(),
                TextColumn::make('total')
                    ->label('Всего счётчиков')
                    ->numeric(),
                TextColumn::make('taken')
                    ->label('Снято')
                    ->numeric(),
                TextColumn::make('missing')
                    ->label('Не снято')
                    ->numeric(),
                TextColumn::make('percent_label')
                    ->label('Процент снятия')
                    ->badge()
                    /** Array records reach the closure as plain arrays, so the parameter stays untyped. */
                    ->color(fn ($record): string => (string) $record['percent_color']),
            ])
            ->recordUrl(null)
            ->paginated(false)
            ->emptyStateHeading('Нет контроллеров')
            ->emptyStateDescription('В организации нет пользователей с ролью контроллера.')
            ->striped();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function records(): array
    {
        $organization = Filament::getTenant();
        $user = auth()->user();

        if (! $organization instanceof Organization || ! $user instanceof User) {
            return [];
        }

        $billingPeriod = DashboardBillingPeriod::resolve(
            $organization,
            $this->pageFilters['billing_period_id'] ?? null,
        );

        if (! $billingPeriod instanceof BillingPeriod) {
            return [];
        }

        $progress = app(DashboardMetrics::class)
            ->controllerProgress($organization, $billingPeriod, $user);

        usort($progress, fn (array $first, array $second): int => $first['percent'] <=> $second['percent']);

        $records = [];

        foreach (array_slice($progress, 0, self::ROW_LIMIT) as $row) {
            $records[$row['controller_id']] = [
                ...$row,
                'percent_label' => $this->formatPercent((float) $row['percent']),
                'percent_color' => $this->percentColor((float) $row['percent']),
            ];
        }

        return $records;
    }

    private function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').' %';
    }

    private function percentColor(float $percent): string
    {
        return match (true) {
            $percent < 70.0 => 'danger',
            $percent < 95.0 => 'warning',
            default => 'success',
        };
    }
}
```

- [ ] **Step 5: Написать таблицу среза по районам**

Создать `app/Filament/Widgets/DashboardRegionBreakdownWidget.php`:

```php
<?php

namespace App\Filament\Widgets;

use App\Dashboard\DashboardMetrics;
use App\Filament\Support\DashboardBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

class DashboardRegionBreakdownWidget extends TableWidget
{
    use InteractsWithPageFilters;

    /**
     * Rows shown on the dashboard; the full list lives in the debts report.
     */
    private const int ROW_LIMIT = 10;

    protected int|string|array $columnSpan = 2;

    protected static ?int $sort = 5;

    /**
     * Accruals, receipts and payments are operator-only data.
     */
    public static function canView(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Срез по районам')
            ->records(fn (): array => $this->records())
            ->columns([
                TextColumn::make('region_label')
                    ->label('Район')
                    ->wrap(),
                TextColumn::make('clients')
                    ->label('Абонентов')
                    ->numeric(),
                TextColumn::make('readings_percent_label')
                    ->label('Снято'),
                TextColumn::make('charged')
                    ->label('Начислено')
                    ->money('KZT'),
                TextColumn::make('paid')
                    ->label('Оплачено')
                    ->money('KZT'),
                TextColumn::make('debt')
                    ->label('Долг')
                    ->money('KZT')
                    ->color('danger'),
            ])
            ->recordUrl(null)
            ->paginated(false)
            ->emptyStateHeading('Нет данных по районам')
            ->emptyStateDescription('В районах организации ещё нет активных абонентов.')
            ->striped();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function records(): array
    {
        $organization = Filament::getTenant();

        if (! $organization instanceof Organization) {
            return [];
        }

        $billingPeriod = DashboardBillingPeriod::resolve(
            $organization,
            $this->pageFilters['billing_period_id'] ?? null,
        );

        if (! $billingPeriod instanceof BillingPeriod) {
            return [];
        }

        $breakdown = app(DashboardMetrics::class)->regionBreakdown($organization, $billingPeriod);

        $records = [];

        foreach (array_slice($breakdown, 0, self::ROW_LIMIT) as $row) {
            $records[$row['region_id']] = [
                ...$row,
                'region_label' => $row['city'] === '' ? $row['region'] : "{$row['city']} / {$row['region']}",
                'readings_percent_label' => rtrim(rtrim(number_format($row['readings_percent'], 1, '.', ''), '0'), '.').' %',
            ];
        }

        return $records;
    }
}
```

- [ ] **Step 6: Подключить виджеты к странице**

В `app/Filament/Pages/Dashboard.php` дополнить `getWidgets()` и импорты:

```php
    /**
     * @return array<class-string<Widget>|WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            DashboardStatsWidget::class,
            DashboardFinanceStatsWidget::class,
            DashboardChargesChartWidget::class,
            DashboardControllerProgressWidget::class,
            DashboardRegionBreakdownWidget::class,
        ];
    }
```

- [ ] **Step 7: Запустить тесты**

Run: `<test> --filter=DashboardTest`
Expected: PASS, 13 тестов.

- [ ] **Step 8: Pint и коммит**

```bash
<pint>
git add app/Filament/Widgets app/Filament/Pages/Dashboard.php tests/Feature/DashboardTest.php
git commit -m "На дашборд добавлены график начислений и оплат за 12 месяцев, таблица прогресса снятия по контроллерам и срез по районам."
```

---

### Task 8: Документация, превью дизайна и полный прогон

**Files:**
- Create: `docs/modules/dashboard.md`
- Modify: `docs/business-rules.md`
- Modify: `docs/technical-specification.md`
- Modify: `docs/changelog.md`
- Modify: `docs/superpowers/specs/2026-08-10-operator-dashboard-design.md`
- Modify: `resources/views/design-preview.blade.php`

**Interfaces:**
- Consumes: поведение, реализованное в задачах 1–7.
- Produces: документацию, соответствующую коду.

- [ ] **Step 1: Написать документацию модуля**

Создать `docs/modules/dashboard.md` со следующими разделами и содержанием:

- **Назначение** — дашборд показывает картину организации за один расчётный месяц и является стартовой страницей панели: корневой маршрут tenant-организации открывает его вместо списка абонентов.
- **Выбор расчётного месяца** — селектор со всеми месяцами организации в виде `08.2026 — Открыт`; по умолчанию текущий открытый или ошибочный месяц, а если такого нет — последний месяц по дате начала; идентификатор из состояния Livewire принимается, только если месяц принадлежит текущей организации, иначе применяется месяц по умолчанию; если у организации нет ни одного месяца, дашборд открывается пустым.
- **Источник начислений** — для месяца в статусе `closed` начисление и долг берутся из начислений, для `open`, `processing` и `failed` — из квитанций и помечаются как предварительные; оплаты всегда берутся из оплат месяца; процент сбора равен оплачено / начислено и равен нулю при нулевом начислении.
- **Плитки** — «Абоненты» (активных, всего, новых за месяц), «Счётчики» (активных, из них по счётчику), «Снято показаний» (процент и «N из M», цвет: меньше 70 % — красный, меньше 95 % — жёлтый, иначе зелёный), «Потребление» (сумма расхода за месяц в единице измерения услуги организации), «Начислено», «Оплачено» (количество оплат и процент сбора), «Долг на конец месяца» (сумма и количество абонентов).
- **График** — столбчатая диаграмма начислений и оплат за последние 12 расчётных месяцев организации, от старого месяца к новому.
- **Прогресс снятия по контроллерам** — таблица со столбцами контроллер, всего счётчиков, снято, не снято, процент снятия; отстающие сверху; не больше 10 строк; зона считается так же, как в отчёте «Процент снятия по контроллерам», счётчик в пересечении района и улицы учитывается один раз.
- **Срез по районам** — таблица со столбцами район, абонентов, снято, начислено, оплачено, долг; районы без активных абонентов не показываются; сортировка по долгу по убыванию; не больше 10 строк.
- **Доступ** — оператор видит все блоки по всей организации; контроллер видит только плитки абонентов, счётчиков, снятия и потребления по своей зоне и свою строку в прогрессе снятия; денежные плитки, график и срез по районам контроллеру не показываются и не рассчитываются; контроллер без назначенной зоны видит нули.
- **Пустые состояния** — нет расчётных месяцев, нет данных за месяц, нет контроллеров, нет районов с абонентами.

- [ ] **Step 2: Обновить бизнес-правила**

В `docs/business-rules.md` добавить раздел «Дашборд» после раздела «Организация» со следующими правилами:

- Дашборд является стартовой страницей панели выбранной tenant-организации.
- Дашборд строится за один расчётный месяц, который оператор выбирает в селекторе.
- По умолчанию выбирается текущий открытый или ошибочный расчётный месяц, а если такого нет — последний расчётный месяц организации.
- Начисление и долг закрытого месяца берутся из начислений, незакрытого — из квитанций и считаются предварительными.
- Оператор видит на дашборде все показатели организации.
- Контроллер видит на дашборде только количество абонентов, счётчиков, процент снятия и потребление по своей зоне ответственности и собственную строку прогресса снятия.
- Начисления, оплаты, долг и срез по районам контроллеру на дашборде недоступны.

- [ ] **Step 3: Обновить техническую спецификацию**

В `docs/technical-specification.md` в описании панели администратора добавить страницу дашборда: класс `App\Filament\Pages\Dashboard`, путь `/` внутри tenant-организации, сервис метрик `App\Dashboard\DashboardMetrics`, виджеты `App\Filament\Widgets\Dashboard*`, общий подзапрос зоны контроллера `App\Support\ControllerZoneMeterCounts`.

Найти подходящий раздел командой:

```bash
grep -n "Filament\|панел" docs/technical-specification.md | head -40
```

- [ ] **Step 4: Обновить changelog**

В `docs/changelog.md` в разделе `## 2026-08-10` → `### Added` добавить запись:

```markdown
- Появился дашборд организации — стартовая страница панели с селектором расчётного месяца. Он показывает количество абонентов и новых лицевых счетов, активные счётчики, процент снятия показаний, потребление, начисления, оплаты и процент сбора, долг на конец месяца, столбчатый график начислений и оплат за 12 месяцев, прогресс снятия по контроллерам и срез по районам. Начисление и долг закрытого месяца берутся из начислений, незакрытого — из квитанций и помечаются как предварительные. Контроллер видит только абонентов, счётчики, процент снятия и потребление по своей зоне и собственную строку прогресса снятия.
```

В раздел `### Changed` добавить:

```markdown
- Вход в панель организации больше не перенаправляет на список абонентов: корневой маршрут tenant-организации открывает дашборд.
- Подсчёт активных счётчиков в зоне контроллера вынесен в общий класс `App\Support\ControllerZoneMeterCounts`; отчёт «Процент снятия по контроллерам» и дашборд считают зону одним и тем же запросом. Поведение отчёта не изменилось.
```

- [ ] **Step 5: Отметить отклонение в спецификации**

В `docs/superpowers/specs/2026-08-10-operator-dashboard-design.md` в разделе «Сервис `App\Dashboard\DashboardMetrics`» заменить предложение

> Существующие отчёты не меняются: дашборд строит собственные агрегатные запросы, чтобы изменения в нём не задевали отчёты и их XLSX-выгрузки.

на

> Дашборд строит собственные агрегатные запросы, чтобы изменения в нём не задевали отчёты и их XLSX-выгрузки. Единственное исключение — подсчёт активных счётчиков в зоне контроллера: он вынесен в общий класс `App\Support\ControllerZoneMeterCounts`, которым пользуются и отчёт «Процент снятия по контроллерам», и дашборд, чтобы правило зоны не разъехалось между ними. Поведение отчёта при этом не меняется.

В разделе «Тесты» заменить одну строку про `tests/Feature/DashboardTest.php` на две: `tests/Feature/DashboardMetricsTest.php` — тесты сервиса, `tests/Feature/DashboardTest.php` — тесты страницы, селектора месяца и виджетов.

- [ ] **Step 6: Добавить секцию дашборда в превью дизайна**

В `resources/views/design-preview.blade.php` добавить новую секцию перед секцией «Отчёты учёта» (строка ~327). Секция повторяет стиль соседних секций: контейнер `rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900`, заголовок `<h2 class="text-base font-semibold">Дашборд</h2>`.

Секция содержит:

1. Строку селектора расчётного месяца — `<select>` с опциями `08.2026 — Открыт`, `07.2026 — Закрыт`.
2. Сетку из 4 плиток `grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4` с подписью, значением и описанием: «Абоненты» / `1 240` / «всего 1 310 · новых за месяц 24»; «Счётчики» / `1 118` / «по счётчику 1 118»; «Снято показаний» / `86.4 %` / «966 из 1 118» жёлтым; «Потребление» / `18 402` / «за выбранный месяц, м³».
3. Сетку из 3 денежных плиток: «Начислено» / `12 480 000 ₸` / «предварительно, по 966 квитанциям»; «Оплачено» / `9 120 000 ₸` / «412 оплат · сбор 73.1 %»; «Долг на конец месяца» / `3 360 000 ₸` / «318 абонентов» красным.
4. Блок графика — заголовок «Начисления и оплаты по месяцам» и заглушку области графика высотой `h-64` с легендой из двух цветов (`#f59e0b` — «Начислено», `#10b981` — «Оплачено»).
5. Две таблицы рядом (`grid grid-cols-1 gap-4 lg:grid-cols-2`): «Прогресс снятия по контроллерам» (контроллер, всего счётчиков, снято, не снято, процент снятия с цветным бейджем) и «Срез по районам» (район, абонентов, снято, начислено, оплачено, долг).
6. Вариант для контроллера — те же 4 операционные плитки и одна строка прогресса, с подписью «Контроллер: денежные блоки скрыты».
7. Пустое состояние — карточку «Расчётный месяц не открыт» с описанием «Откройте расчётный месяц в разделе «Расчётные месяцы», чтобы увидеть показатели».

Все блоки должны иметь тёмную тему (`dark:` варианты) и быть адаптивными, как соседние секции файла.

- [ ] **Step 7: Проверить превью**

```bash
grep -n "Дашборд" resources/views/design-preview.blade.php
```

Expected: секция найдена.

- [ ] **Step 8: Полный прогон тестов**

Run: `<test>` (без `--filter`)
Expected: PASS — весь набор зелёный.

Если какой-то существующий тест падает из-за удалённого редиректа с `/`, найти его и обновить ожидание: корневой маршрут теперь отдаёт дашборд, а не редирект на список абонентов.

- [ ] **Step 9: Pint и коммит**

```bash
<pint>
git add docs resources/views/design-preview.blade.php
git commit -m "Документация и превью дизайна дополнены дашбордом: новый модуль docs/modules/dashboard.md, правила видимости по ролям, запись в changelog и секция дашборда в /design-preview."
```

---

## Самопроверка плана

**Покрытие спецификации:**

| Раздел спецификации | Задача |
| --- | --- |
| Размещение и маршрут | 5 |
| Селектор расчётного месяца | 5 |
| Источники цифр | 2 |
| `operations()` | 1 |
| `finance()`, `monthlyTotals()` | 2 |
| `controllerProgress()` | 3 |
| `regionBreakdown()` | 4 |
| Плитки | 6 |
| График | 7 |
| Прогресс снятия по контроллерам | 7 |
| Срез по районам | 7 |
| Доступ по ролям | 6, 7 |
| Пустые состояния | 5, 6, 7 |
| Производительность | 1–4 (агрегатные запросы, без запросов в цикле) |
| Тесты | 1–7 |
| Документация | 8 |

**Отклонения от спецификации, зафиксированные в задаче 8:**

1. Подсчёт зоны контроллера вынесен в общий класс и переиспользован отчётом.
2. Тесты разделены на два файла: сервис и UI.
