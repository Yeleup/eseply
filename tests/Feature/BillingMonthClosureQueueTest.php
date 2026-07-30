<?php

use App\Actions\CloseBillingMonth;
use App\BillingPeriodStatus;
use App\ClientType;
use App\Filament\Resources\Accruals\Pages\ListAccruals;
use App\Filament\Support\CurrentBillingPeriod;
use App\Jobs\CloseBillingMonthJob;
use App\Models\Accrual;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Meter;
use App\Models\Organization;
use App\Models\Tariff;
use App\Models\User;
use App\Models\UtilityService;
use App\OrganizationMemberRole;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function billingClosureOrganization(): Organization
{
    $organization = Organization::factory()->create();

    UtilityService::factory()->for($organization)->create([
        'name' => 'Вывоз мусора',
    ]);

    return $organization->refresh();
}

function closureOperatorFor(Organization $organization): User
{
    $operator = User::factory()->create();
    $operator->organizations()->attach($organization, ['role' => OrganizationMemberRole::Operator->value]);

    return $operator;
}

function actingAsClosureOperator(Organization $organization): User
{
    $operator = closureOperatorFor($organization);

    Livewire::actingAs($operator);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $operator;
}

/**
 * @return list<string>
 */
function databaseNotificationTitles(User $user): array
{
    return $user->notifications()
        ->get()
        ->map(fn ($notification): string => (string) ($notification->data['title'] ?? ''))
        ->all();
}

test('closing a month is queued instead of calculated inside the request', function () {
    Queue::fake();

    $organization = billingClosureOrganization();

    Client::factory()
        ->for($organization)
        ->for($organization->utilityService)
        ->create([
            'account_number' => '60001',
            'billing_type' => 'fixed',
            'fixed_amount' => 8000,
        ]);

    $billingPeriod = BillingPeriod::openFor($organization, '202605');

    actingAsClosureOperator($organization);

    Livewire::test(ListAccruals::class)
        ->callAction('closeBillingMonth')
        ->assertNotified('Закрытие месяца запущено');

    Queue::assertPushed(
        CloseBillingMonthJob::class,
        fn (CloseBillingMonthJob $job): bool => $job->billingPeriod->is($billingPeriod)
            && $job->organization->is($organization),
    );

    expect($billingPeriod->refresh()->status)->toBe(BillingPeriodStatus::Processing)
        ->and(Accrual::query()->count())->toBe(0);
});

test('the closing job survives the queue serialization round trip', function () {
    $organization = billingClosureOrganization();
    $operator = closureOperatorFor($organization);
    $billingPeriod = BillingPeriod::openFor($organization, '202605');

    $job = unserialize(serialize(new CloseBillingMonthJob($organization, $billingPeriod, $operator)));

    expect($job)->toBeInstanceOf(CloseBillingMonthJob::class)
        ->and($job->organization->is($organization))->toBeTrue()
        ->and($job->billingPeriod->is($billingPeriod))->toBeTrue()
        ->and($job->startedBy?->is($operator))->toBeTrue()
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'))
        ->and($job->timeout)->toBeLessThan(config('queue.connections.database.retry_after'));
});

test('a month already being closed cannot be queued twice', function () {
    Queue::fake();

    $organization = billingClosureOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202605');

    app(CloseBillingMonth::class)->claim($organization, $billingPeriod);

    actingAsClosureOperator($organization);

    Livewire::test(ListAccruals::class)
        ->assertActionDisabled('closeBillingMonth');

    /** A second request that races past the disabled button is refused as well. */
    expect(fn () => app(CloseBillingMonth::class)->claim($organization, $billingPeriod))
        ->toThrow(InvalidArgumentException::class, 'Расчётный месяц уже закрывается.');

    Queue::assertNothingPushed();
});

test('a processing month tells the operator that a calculation is running', function () {
    $organization = billingClosureOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202605');

    actingAsClosureOperator($organization);

    expect(CurrentBillingPeriod::closing($organization))->toBeNull()
        ->and(CurrentBillingPeriod::missingTooltip($organization))->toBeNull();

    app(CloseBillingMonth::class)->claim($organization, $billingPeriod);

    expect(CurrentBillingPeriod::closing($organization)?->is($billingPeriod))->toBeTrue()
        ->and(CurrentBillingPeriod::missingTooltip($organization))->toBe(CurrentBillingPeriod::ClosingTooltip);
});

test('the queued job creates the accruals and notifies the organization operators', function () {
    $organization = billingClosureOrganization();

    Client::factory()
        ->for($organization)
        ->for($organization->utilityService)
        ->create([
            'account_number' => '60002',
            'billing_type' => 'fixed',
            'fixed_amount' => 8000,
        ]);

    $operator = closureOperatorFor($organization);

    $controller = User::factory()->create();
    $controller->organizations()->attach($organization, ['role' => OrganizationMemberRole::Controller->value]);

    $billingPeriod = BillingPeriod::openFor($organization, '202605');

    app(CloseBillingMonth::class)->claim($organization, $billingPeriod, $operator);

    CloseBillingMonthJob::dispatchSync($organization, $billingPeriod, $operator);

    expect($billingPeriod->refresh()->status)->toBe(BillingPeriodStatus::Closed)
        ->and($billingPeriod->created_accruals_count)->toBe(1)
        ->and($billingPeriod->closed_by_user_id)->toBe($operator->id)
        ->and(Accrual::query()->where('account_number', '60002')->sole()->amount)->toBe('8000.00')
        ->and(databaseNotificationTitles($operator))->toBe(['Месяц закрыт'])
        ->and(databaseNotificationTitles($controller))->toBe([]);
});

test('the queued job reports data errors to the organization operators', function () {
    $organization = billingClosureOrganization();

    $client = Client::factory()
        ->for($organization)
        ->for($organization->utilityService)
        ->create([
            'account_number' => '60003',
            'billing_type' => 'meter',
        ]);

    Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($organization->utilityService)
        ->create([
            'number' => 'M-777',
            'status' => 'active',
        ]);

    $operator = closureOperatorFor($organization);

    $billingPeriod = BillingPeriod::openFor($organization, '202605');

    app(CloseBillingMonth::class)->claim($organization, $billingPeriod, $operator);

    CloseBillingMonthJob::dispatchSync($organization, $billingPeriod, $operator);

    expect($billingPeriod->refresh()->status)->toBe(BillingPeriodStatus::Failed)
        ->and($billingPeriod->failed_clients_count)->toBe(1)
        ->and(Accrual::query()->count())->toBe(0)
        ->and($billingPeriod->closureErrors()->pluck('code')->all())->toBe(['missing_meter_reading'])
        ->and(databaseNotificationTitles($operator))->toBe(['Месяц закрыт с ошибками']);
});

test('a failed closing job releases the billing period and warns the operators', function () {
    $organization = billingClosureOrganization();
    $operator = closureOperatorFor($organization);

    $billingPeriod = BillingPeriod::openFor($organization, '202605');

    app(CloseBillingMonth::class)->claim($organization, $billingPeriod, $operator);

    (new CloseBillingMonthJob($organization, $billingPeriod, $operator))
        ->failed(new RuntimeException('Соединение с базой данных потеряно.'));

    expect($billingPeriod->refresh()->status)->toBe(BillingPeriodStatus::Failed)
        ->and($billingPeriod->failure_message)->toBe('Закрытие расчётного месяца прервано технической ошибкой.')
        ->and(databaseNotificationTitles($operator))->toBe(['Не удалось закрыть месяц']);
});

test('a technical failure never leaves the billing period in processing', function () {
    $organization = billingClosureOrganization();
    $billingPeriod = BillingPeriod::openFor($organization, '202605');

    app(CloseBillingMonth::class)->claim($organization, $billingPeriod);

    Client::factory()
        ->for($organization)
        ->for($organization->utilityService)
        ->create([
            'billing_type' => 'fixed',
            'fixed_amount' => 8000,
        ]);

    /** Renaming the accruals table makes the calculation fail with a database error. */
    DB::statement('alter table accruals rename to accruals_renamed');

    try {
        expect(fn () => app(CloseBillingMonth::class)->run($organization, $billingPeriod))
            ->toThrow(QueryException::class);
    } finally {
        DB::statement('alter table accruals_renamed rename to accruals');
    }

    expect($billingPeriod->refresh()->status)->toBe(BillingPeriodStatus::Failed)
        ->and($billingPeriod->failure_message)->toBe('Закрытие расчётного месяца прервано технической ошибкой.');
});

test('closing a month keeps the query count flat as abonents are added', function () {
    $queryCountFor = function (int $clientCount): int {
        $organization = billingClosureOrganization();

        Tariff::factory()
            ->for($organization)
            ->for($organization->utilityService)
            ->create([
                'client_type' => ClientType::Individual->value,
                'per_person_price' => 500,
                'starts_on' => '2026-01-01',
                'status' => 'active',
            ]);

        for ($index = 0; $index < $clientCount; $index++) {
            Client::factory()
                ->for($organization)
                ->for($organization->utilityService)
                ->create([
                    'client_type' => ClientType::Individual->value,
                    'billing_type' => 'per_person',
                    'residents_count' => 2,
                ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $summary = app(CloseBillingMonth::class)->handle($organization, '202605');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($summary['created'])->toBe($clientCount)
            ->and($summary['failed'])->toBe(0);

        return $queryCount;
    };

    $smallRun = $queryCountFor(5);
    $largeRun = $queryCountFor(60);

    /**
     * Each batch of abonents costs a fixed number of queries, so twelve times
     * more abonents cost no extra queries at all while they fit in one batch.
     * The removed per abonent lookups made this grow by roughly 7 per abonent.
     */
    expect($largeRun)->toBe($smallRun)
        ->and($largeRun)->toBeLessThan(30);
});
