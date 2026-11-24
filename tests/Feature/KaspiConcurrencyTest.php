<?php

use App\BillingPeriodStatus;
use App\ClientType;
use App\Kaspi\KaspiCommand;
use App\Kaspi\KaspiOperationState;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\KaspiOperation;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Tariff;
use App\Models\UtilityService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/*
 * Concurrent requests on separate database connections: every side runs in its
 * own PHP process (tests/Support/kaspi-concurrency-worker.php). The data is
 * committed, so this file does not use RefreshDatabase: it migrates a fresh
 * database itself and makes the next RefreshDatabase test migrate again.
 *
 * To make the race certain rather than likely, the test holds a row lock the
 * sides must wait for, waits until all of them are blocked, then lets go.
 */

const CONCURRENCY_KASPI_IP = '194.187.247.152';
const CONCURRENCY_PROXY = '172.18.0.1';

beforeEach(function (): void {
    $this->artisan('migrate:fresh');
    RefreshDatabaseState::$migrated = true;

    $this->beforeApplicationDestroyed(function (): void {
        // Never leave a lock behind: the next migrate:fresh would wait for it.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect();
        RefreshDatabaseState::$migrated = false;
    });

    config([
        'trustedproxy.proxies' => [CONCURRENCY_PROXY],
        'trustedproxy.configured' => [CONCURRENCY_PROXY],
    ]);
});

/**
 * @return array{organization: Organization, client: Client, other: Client, billingPeriod: BillingPeriod}
 */
function concurrencyFixture(): array
{
    $organization = Organization::factory()->create();
    $organization->forceFill(['kaspi_enabled' => true])->save();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $billingPeriod = billingPeriodFor($organization, '202611');

    Tariff::factory()->for($organization)->for($utilityService)->create([
        'client_type' => ClientType::Individual,
        'unit_price' => 100,
        'per_person_price' => 500,
        'starts_on' => '2020-01-01',
        'status' => 'active',
    ]);

    $clients = [];

    foreach (['100417', '100418'] as $index => $account) {
        $client = Client::factory()->for($organization)->for($utilityService)->create([
            'account_number' => $account,
            'client_type' => ClientType::Individual,
            'status' => 'active',
            'billing_type' => 'meter',
            'iin' => '90010130010'.$index,
            'phone' => '+7701000002'.$index,
        ]);

        $meter = Meter::factory()->for($organization)->for($client)->for($utilityService)->create([
            'initial_reading' => 0,
            'status' => 'active',
        ]);

        // 100 м³ × 100 = 10 000: the receipt exists before the payments.
        MeterReading::query()->create([
            'meter_id' => $meter->id,
            'billing_period_id' => $billingPeriod->id,
            'previous_reading' => 0,
            'current_reading' => 100,
        ]);

        $clients[] = $client;
    }

    return ['organization' => $organization, 'client' => $clients[0], 'other' => $clients[1], 'billingPeriod' => $billingPeriod];
}

function concurrencyPay(Organization $organization, string $txnId, string $account, string $sum): array
{
    return [
        'type' => 'http',
        'uri' => '/kaspi/'.$organization->kaspi_uid."?command=pay&txn_id={$txnId}&txn_date=20261124101521&account={$account}&sum={$sum}",
        'server' => ['REMOTE_ADDR' => CONCURRENCY_PROXY, 'HTTP_X_FORWARDED_FOR' => CONCURRENCY_KASPI_IP],
    ];
}

function concurrencyCheck(Organization $organization, string $txnId, string $account): array
{
    return [
        'type' => 'http',
        'uri' => '/kaspi/'.$organization->kaspi_uid."?command=check&txn_id={$txnId}&account={$account}&sum=0.00",
        'server' => ['REMOTE_ADDR' => CONCURRENCY_PROXY, 'HTTP_X_FORWARDED_FOR' => CONCURRENCY_KASPI_IP],
    ];
}

/**
 * Start every side, release them at once, and wait until $blocked of them
 * wait for a lock before $whileBlocked runs; then collect their results.
 *
 * @param  list<array<string, mixed>>  $steps
 * @param  array<string, string>  $environment
 * @return list<array<string, mixed>>
 */
function concurrencyRun(array $steps, int $blocked, callable $whileBlocked, array $environment = []): array
{
    $directory = sys_get_temp_dir().'/kaspi-concurrency-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $goFile = $directory.'/go';

    /** @var list<InvokedProcess> $processes */
    $processes = [];

    foreach ($steps as $index => $step) {
        $step['ready_file'] = $directory.'/ready-'.$index;
        $step['go_file'] = $goFile;

        $processes[] = Process::env([
            'TRUSTED_PROXIES' => CONCURRENCY_PROXY,
            'KASPI_LOCK_WAIT_SECONDS' => '5',
            'KASPI_POSTING_LOCK_WAIT_SECONDS' => '1',
            ...$environment,
        ])->timeout(60)->start([PHP_BINARY, base_path('tests/Support/kaspi-concurrency-worker.php'), json_encode($step)]);
    }

    try {
        concurrencyWaitFor(fn (): bool => count(glob($directory.'/ready-*')) === count($steps), 'the sides to boot', $processes);

        touch($goFile);

        if ($blocked > 0) {
            concurrencyWaitFor(fn (): bool => (int) DB::scalar(
                "select count(*) from information_schema.processlist where id <> connection_id() and command in ('Query', 'Execute') and info is not null and time_ms > 200",
            ) >= $blocked, "{$blocked} sides to wait for the lock", $processes);
        }

        $whileBlocked();

        return array_map(function (InvokedProcess $process): array {
            $result = $process->wait();

            expect($result->successful())->toBeTrue($result->errorOutput().$result->output());

            return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        }, $processes);
    } finally {
        foreach ($processes as $process) {
            if ($process->running()) {
                $process->signal(SIGKILL);
            }
        }

        array_map('unlink', glob($directory.'/*'));
        rmdir($directory);
    }
}

/**
 * @param  list<InvokedProcess>  $processes
 */
function concurrencyWaitFor(callable $condition, string $what, array $processes): void
{
    $waitUntil = microtime(true) + 20;

    while (! $condition()) {
        foreach ($processes as $process) {
            if (! $process->running()) {
                $result = $process->wait();

                throw new RuntimeException("A side ended while waiting for {$what}: ".$result->errorOutput().$result->output());
            }
        }

        if (microtime(true) > $waitUntil) {
            throw new RuntimeException("Timed out waiting for {$what}.");
        }

        usleep(20_000);
    }
}

test('два одновременных одинаковых pay: одна операция, одна оплата, повтор получает ответ проводки', function (): void {
    ['organization' => $organization, 'client' => $client] = concurrencyFixture();

    DB::beginTransaction();
    DB::select('select id from clients where id = ? for update', [$client->id]);

    $results = concurrencyRun(
        [
            concurrencyPay($organization, '48213379001', '100417', '1000.00'),
            concurrencyPay($organization, '48213379001', '100417', '1000.00'),
        ],
        blocked: 2,
        whileBlocked: fn () => DB::commit(),
    );

    $payment = Payment::query()->where('external_payment_id', '48213379001')->sole();
    $posted = '{"txn_id":"48213379001","prv_txn_id":"'.$payment->id.'","result":0,"sum":1000.00,"comment":"OK"}';
    $pending = '{"txn_id":"48213379001","result":4,"comment":"Платёж в обработке"}';

    expect(KaspiOperation::query()->where('txn_id', '48213379001')->count())->toBe(1)
        ->and(collect($results)->pluck('body')->all())->each->toBeIn([$posted, $pending])
        ->and(collect($results)->pluck('body')->contains($posted))->toBeTrue();

    $repeat = $this->call('GET', '/kaspi/'.$organization->kaspi_uid.'?command=pay&txn_id=48213379001&txn_date=20261124101521&account=100417&sum=1000.00', server: [
        'REMOTE_ADDR' => CONCURRENCY_PROXY,
        'HTTP_X_FORWARDED_FOR' => CONCURRENCY_KASPI_IP,
    ]);

    expect($repeat->getContent())->toBe($posted)
        ->and((float) Receipt::query()->whereBelongsTo($client)->value('paid_amount'))->toBe(1000.0);
});

test('два разных pay одного абонента и оплата на кассе одновременно: сумма в квитанции равна сумме оплат', function (): void {
    ['organization' => $organization, 'client' => $client, 'billingPeriod' => $billingPeriod] = concurrencyFixture();

    DB::beginTransaction();
    DB::select('select id from clients where id = ? for update', [$client->id]);

    $results = concurrencyRun(
        [
            concurrencyPay($organization, '48213379101', '100417', '1000.00'),
            concurrencyPay($organization, '48213379102', '100417', '2500.00'),
            ['type' => 'manual', 'organization_id' => $organization->id, 'client_id' => $client->id, 'amount' => '700.00', 'paid_at' => '2026-11-24'],
        ],
        blocked: 3,
        whileBlocked: fn () => DB::commit(),
    );

    expect($results[0]['body'])->toContain('"result":0')
        ->and($results[1]['body'])->toContain('"result":0')
        ->and($results[2])->toHaveKey('payment_id');

    $payments = Payment::query()->whereBelongsTo($client)->whereBelongsTo($billingPeriod)->get();
    $receipt = Receipt::query()->whereBelongsTo($client)->whereBelongsTo($billingPeriod)->sole();

    expect($payments)->toHaveCount(3)
        ->and(Payment::query()->where('external_payment_id', '48213379101')->count())->toBe(1)
        ->and(Payment::query()->where('external_payment_id', '48213379102')->count())->toBe(1)
        ->and((float) $receipt->paid_amount)->toBe(4200.0)
        ->and((float) $payments->sum('amount'))->toBe(4200.0)
        ->and((float) $receipt->closing_balance)->toBe(10000.0 - 4200.0);
});

test('начало закрытия и оплаты одновременно: ни касса, ни Kaspi не пишут в закрывающийся месяц', function (): void {
    ['organization' => $organization, 'client' => $client, 'other' => $other, 'billingPeriod' => $billingPeriod] = concurrencyFixture();

    // The start of closing holds the month row and moves it to processing.
    DB::beginTransaction();
    DB::select('select id from billing_periods where id = ? for update', [$billingPeriod->id]);

    $results = concurrencyRun(
        [
            ['type' => 'manual', 'organization_id' => $organization->id, 'client_id' => $other->id, 'amount' => '700.00', 'paid_at' => '2026-11-24'],
            concurrencyPay($organization, '48213379201', '100417', '1000.00'),
        ],
        blocked: 2,
        whileBlocked: function () use ($billingPeriod): void {
            DB::update('update billing_periods set status = ?, processing_started_at = now() where id = ?', [
                BillingPeriodStatus::Processing->value,
                $billingPeriod->id,
            ]);
            DB::commit();
        },
    );

    $operation = KaspiOperation::query()->where('txn_id', '48213379201')->sole();

    expect($results[0]['error'] ?? null)->toContain('Расчётный месяц закрывается')
        ->and($results[1]['body'])->toBe('{"txn_id":"48213379201","result":4,"comment":"Платёж в обработке"}')
        ->and($operation->state)->toBe(KaspiOperationState::Pending)
        ->and($operation->state_reason)->toBe('Идёт закрытие расчётного месяца')
        ->and(Payment::query()->whereBelongsTo($billingPeriod)->count())->toBe(0);
});

test('подбор «в обработке»: занятый абонент не задерживает оплату другого абонента', function (): void {
    ['organization' => $organization, 'client' => $client, 'other' => $other] = concurrencyFixture();

    $received = now()->subMinutes(2);
    $lockedOperation = KaspiOperation::factory()->forClient($client)->pending(1000)->create(['txn_id' => '48213379301', 'received_at' => $received]);
    $freeOperation = KaspiOperation::factory()->forClient($other)->pending(2000)->create(['txn_id' => '48213379302', 'received_at' => $received]);

    DB::beginTransaction();
    DB::select('select id from clients where id = ? for update', [$client->id]);

    concurrencyRun(
        [['type' => 'artisan', 'command' => 'kaspi:post-pending']],
        blocked: 0,
        whileBlocked: fn () => null,
    );

    DB::rollBack();

    expect($freeOperation->refresh()->state)->toBe(KaspiOperationState::Posted)
        ->and($lockedOperation->refresh()->state)->toBe(KaspiOperationState::Pending)
        ->and($lockedOperation->state_reason)->toBe('Не дождались блокировки, проведётся автоматически');
});

test('ожидания блокировок укладываются в срок: повтор не ждёт занятую строку, а check без записи получает 5', function (): void {
    ['organization' => $organization, 'client' => $client, 'billingPeriod' => $billingPeriod] = concurrencyFixture();

    $this->call('GET', '/kaspi/'.$organization->kaspi_uid.'?command=pay&txn_id=48213379401&txn_date=20261124101521&account=100417&sum=1000.00', server: [
        'REMOTE_ADDR' => CONCURRENCY_PROXY,
        'HTTP_X_FORWARDED_FOR' => CONCURRENCY_KASPI_IP,
    ]);
    $operation = KaspiOperation::query()->where('txn_id', '48213379401')->sole();

    // A posting holds the operation and the subscriber; the repeat and a new
    // check of the same subscriber come in meanwhile.
    DB::beginTransaction();
    DB::select('select id from kaspi_operations where id = ? for update', [$operation->id]);
    DB::select('select id from clients where id = ? for update', [$client->id]);

    $results = concurrencyRun(
        [
            concurrencyPay($organization, '48213379401', '100417', '1000.00'),
            concurrencyCheck($organization, '48213379402', '100417'),
        ],
        blocked: 0,
        whileBlocked: fn () => null,
        environment: ['KASPI_LOCK_WAIT_SECONDS' => '2'],
    );

    DB::rollBack();

    expect($results[0]['body'])->toBe((string) $operation->response)
        ->and($results[0]['seconds'])->toBeLessThan(2.5)
        ->and($results[1]['body'])->toBe('{"txn_id":"48213379402","result":5,"comment":"Приём оплат недоступен"}')
        ->and($results[1]['seconds'])->toBeLessThan(3.5)
        ->and(KaspiOperation::query()->where('txn_id', '48213379402')->exists())->toBeFalse()
        ->and(KaspiOperation::query()->where('command', KaspiCommand::Pay->value)->count())->toBe(1);
});
