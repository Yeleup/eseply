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
use App\Models\User;
use App\Models\UtilityService;
use App\OrganizationMemberRole;
use App\PaymentMethod;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/*
 * Concurrent requests on separate database connections: every side runs in its
 * own PHP process (tests/Support/kaspi-concurrency-worker.php). The data is
 * committed, so this file does not use RefreshDatabase: it wipes and migrates
 * the database itself — only after it proved that the database is the test
 * database `make test` chose — and makes the next RefreshDatabase test
 * migrate again.
 *
 * To make the race certain rather than likely, the test holds row locks the
 * sides must meet, waits until every side has reached its statement, then lets
 * go. Kaspi sides do not wait in MariaDB — they retry within their deadline —
 * the desk and the forms do.
 */

const CONCURRENCY_KASPI_IP = '194.187.247.152';
const CONCURRENCY_PROXY = '172.18.0.1';
const CONCURRENCY_SECOND_CONNECTION = 'concurrency_second';

beforeEach(function (): void {
    concurrencyGuardDatabase();

    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    RefreshDatabaseState::$migrated = true;

    $this->beforeApplicationDestroyed(function (): void {
        // Never leave a lock behind: the next migrate:fresh would wait for it.
        foreach (DB::getConnections() as $connection) {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        DB::purge(CONCURRENCY_SECOND_CONNECTION);
        DB::disconnect();
        RefreshDatabaseState::$migrated = false;
    });

    config([
        'trustedproxy.proxies' => [CONCURRENCY_PROXY],
        'trustedproxy.configured' => [CONCURRENCY_PROXY],
    ]);
});

/**
 * The database about to be wiped must be the test database `make test` gave
 * this process: environment `testing`, no DB_URL, and the host, name and user
 * of the connection equal to what the make target exported. Anything else
 * stops the test before a single table is dropped.
 */
function concurrencyGuardDatabase(): void
{
    $connection = config('database.default');
    $settings = (array) config("database.connections.{$connection}");

    $refuse = fn (string $why) => throw new RuntimeException("Concurrency tests refuse to wipe this database: {$why}");

    app()->environment('testing') || $refuse('APP_ENV is not testing');
    blank($settings['url'] ?? null) || $refuse('DB_URL is set');
    in_array($settings['driver'] ?? null, ['mysql', 'mariadb'], true) || $refuse('not MariaDB');

    $database = (string) ($settings['database'] ?? '');

    ($database !== '' && $database === getenv('DB_DATABASE')) || $refuse('the database is not the one `make test` exported');
    (($settings['host'] ?? null) === getenv('DB_HOST')) || $refuse('the host is not the one `make test` exported');
    preg_match('/_test(ing)?\z/', $database) === 1 || $refuse("{$database} is not a test database");

    $actual = DB::selectOne("select database() as name, substring_index(current_user(), '@', 1) as user");

    ($actual->name === $database) || $refuse('the server reports another database');
    ($actual->user === ($settings['username'] ?? null)) || $refuse('the server reports another user');
}

/**
 * The verified connection, handed to the sides as their environment.
 *
 * @return array<string, string>
 */
function concurrencyEnvironment(): array
{
    $settings = (array) config('database.connections.'.config('database.default'));

    return [
        'APP_ENV' => 'testing',
        'DB_URL' => '',
        'DB_CONNECTION' => (string) config('database.default'),
        'DB_HOST' => (string) $settings['host'],
        'DB_PORT' => (string) $settings['port'],
        'DB_DATABASE' => (string) $settings['database'],
        'DB_USERNAME' => (string) $settings['username'],
        'DB_PASSWORD' => (string) $settings['password'],
        // As in production: the queue runs elsewhere, and the month lock is
        // shared by the processes (make test runs the parent with sync and array).
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'database',
        'TRUSTED_PROXIES' => CONCURRENCY_PROXY,
        'KASPI_LOCK_WAIT_SECONDS' => '5',
        'KASPI_POSTING_LOCK_WAIT_SECONDS' => '1',
    ];
}

/**
 * A second connection of the test process to hold another lock at the same time.
 */
function concurrencySecondConnection(): Connection
{
    config(['database.connections.'.CONCURRENCY_SECOND_CONNECTION => config('database.connections.'.config('database.default'))]);

    return DB::connection(CONCURRENCY_SECOND_CONNECTION);
}

/**
 * @return array{organization: Organization, operator: User, client: Client, other: Client, billingPeriod: BillingPeriod}
 */
function concurrencyFixture(): array
{
    $organization = Organization::factory()->create();
    $organization->forceFill(['kaspi_enabled' => true])->save();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $billingPeriod = billingPeriodFor($organization, '202611');

    $operator = User::factory()->create();
    $operator->organizations()->attach($organization, ['role' => OrganizationMemberRole::Operator->value]);

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

    return ['organization' => $organization, 'operator' => $operator, 'client' => $clients[0], 'other' => $clients[1], 'billingPeriod' => $billingPeriod];
}

/**
 * @return array<string, mixed>
 */
function concurrencyPay(Organization $organization, string $txnId, string $account, string $sum, string $waitFor = 'insert into `kaspi_operations`'): array
{
    return [
        'type' => 'http',
        'uri' => '/kaspi/'.$organization->kaspi_uid."?command=pay&txn_id={$txnId}&txn_date=20261124101521&account={$account}&sum={$sum}",
        'server' => ['REMOTE_ADDR' => CONCURRENCY_PROXY, 'HTTP_X_FORWARDED_FOR' => CONCURRENCY_KASPI_IP],
        'wait_for' => $waitFor,
    ];
}

/**
 * @return array<string, mixed>
 */
function concurrencyCheck(Organization $organization, string $txnId, string $account): array
{
    return [
        'type' => 'http',
        'uri' => '/kaspi/'.$organization->kaspi_uid."?command=check&txn_id={$txnId}&account={$account}&sum=0.00",
        'server' => ['REMOTE_ADDR' => CONCURRENCY_PROXY, 'HTTP_X_FORWARDED_FOR' => CONCURRENCY_KASPI_IP],
        'wait_for' => 'insert into `kaspi_operations`',
    ];
}

/**
 * Start every side and release them at once. A side that declares `wait_for`
 * leaves a marker before that statement; once every such side reached it —
 * and a side with `blocks: true` is also seen by its own connection id still
 * running it in MariaDB — $whileBlocked runs; then the results are collected.
 *
 * @param  list<array<string, mixed>>  $steps
 * @param  array<string, string>  $environment
 * @return list<array<string, mixed>>
 */
function concurrencyRun(array $steps, callable $whileBlocked, array $environment = []): array
{
    $directory = sys_get_temp_dir().'/kaspi-concurrency-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $goFile = $directory.'/go';
    $database = (string) DB::scalar('select database()');

    /** @var list<InvokedProcess> $processes */
    $processes = [];

    try {
        foreach ($steps as $index => $step) {
            $step['ready_file'] = $directory.'/ready-'.$index;
            $step['reached_file'] = $directory.'/reached-'.$index;
            $step['go_file'] = $goFile;
            $step['database'] = $database;

            $processes[] = Process::env([...concurrencyEnvironment(), ...$environment])
                ->timeout(60)
                ->start([PHP_BINARY, base_path('tests/Support/kaspi-concurrency-worker.php'), json_encode($step)]);
        }

        concurrencyWaitFor(fn (): bool => count(glob($directory.'/ready-*')) === count($steps), 'the sides to boot', $processes);

        $connectionIds = array_map(fn (int $index): int => (int) file_get_contents($directory.'/ready-'.$index), array_keys($steps));

        touch($goFile);

        foreach ($steps as $index => $step) {
            if (blank($step['wait_for'] ?? null)) {
                continue;
            }

            concurrencyWaitFor(fn (): bool => file_exists($directory.'/reached-'.$index), "side {$index} to reach its statement", $processes);

            if ($step['blocks'] ?? false) {
                concurrencyWaitFor(fn (): bool => DB::selectOne(
                    'select count(*) as waiting from information_schema.processlist where id = ? and lower(info) like ? and time_ms > 150',
                    [$connectionIds[$index], '%'.$step['wait_for'].'%'],
                )->waiting > 0, "side {$index} to wait for the lock", $processes);
            }
        }

        // A Kaspi side retries every 50 ms: let each of them meet the held lock.
        usleep(300_000);

        $whileBlocked();

        return array_map(function (InvokedProcess $process): array {
            $result = $process->wait();

            expect($result->successful())->toBeTrue($result->errorOutput().$result->output());

            $output = json_decode($result->output(), true);

            expect($output)->toBeArray('Not a result: '.mb_substr($result->output(), 0, 2000));

            return $output;
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

        usleep(10_000);
    }
}

test('проверка базы отказывает до миграций, если база не та, что выбрал make test', function (): void {
    $key = 'database.connections.'.config('database.default');
    $database = config("{$key}.database");

    config(["{$key}.database" => 'laravel_app']);

    expect(fn () => concurrencyGuardDatabase())->toThrow(RuntimeException::class, 'refuse to wipe');

    config(["{$key}.database" => $database, "{$key}.url" => 'mysql://other-host/laravel_app']);

    expect(fn () => concurrencyGuardDatabase())->toThrow(RuntimeException::class, 'DB_URL is set');

    config(["{$key}.url" => null]);
});

test('два одновременных одинаковых pay: одна операция, одна оплата, повтор получает ответ проводки', function (): void {
    ['organization' => $organization, 'client' => $client] = concurrencyFixture();

    DB::beginTransaction();
    DB::select('select id from clients where id = ? for update', [$client->id]);

    $results = concurrencyRun(
        [
            concurrencyPay($organization, '48213379001', '100417', '1000.00'),
            concurrencyPay($organization, '48213379001', '100417', '1000.00'),
        ],
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
        ->and(Receipt::query()->whereBelongsTo($client)->value('paid_amount'))->toBe('1000.00');
});

test('два разных pay одного абонента и оплата на кассе одновременно: сумма в квитанции равна сумме оплат', function (): void {
    ['organization' => $organization, 'client' => $client, 'billingPeriod' => $billingPeriod] = concurrencyFixture();

    DB::beginTransaction();
    DB::select('select id from clients where id = ? for update', [$client->id]);

    $results = concurrencyRun(
        [
            concurrencyPay($organization, '48213379101', '100417', '1000.00'),
            concurrencyPay($organization, '48213379102', '100417', '2500.00'),
            ['type' => 'manual', 'organization_id' => $organization->id, 'client_id' => $client->id, 'amount' => '700.00', 'paid_at' => '2026-11-24', 'wait_for' => 'for update', 'blocks' => true],
        ],
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
        ->and($receipt->paid_amount)->toBe('4200.00')
        ->and((float) $payments->sum('amount'))->toBe(4200.0)
        ->and($receipt->closing_balance)->toBe('5800.00');
});

test('формы «Оплаты» и карточки абонента при одновременной проводке Kaspi сохраняют оплату без отказа снимка', function (string $form): void {
    ['organization' => $organization, 'operator' => $operator, 'client' => $client, 'billingPeriod' => $billingPeriod] = concurrencyFixture();

    // A Kaspi posting holds the subscriber and has already saved its payment,
    // which changed the receipt: the form's transaction must not have taken its
    // snapshot before this commit, or MariaDB refuses its locking reads (1020).
    DB::beginTransaction();
    DB::select('select id from clients where id = ? for update', [$client->id]);
    Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'method' => PaymentMethod::Kaspi->value,
        'external_provider' => Payment::KASPI_PROVIDER,
        'external_payment_id' => '48213377190',
        'amount' => '300.00',
        'paid_at' => '2026-11-24',
    ]);

    $results = concurrencyRun(
        [[
            'type' => $form,
            'user_id' => $operator->id,
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'amount' => '700.00',
            'paid_at' => '2026-11-24',
            'wait_for' => 'for update',
            'blocks' => true,
        ]],
        whileBlocked: fn () => DB::commit(),
    );

    expect($results[0]['errors'])->toBe([])
        ->and(Payment::query()->whereBelongsTo($client)->whereBelongsTo($billingPeriod)->orderBy('id')->pluck('amount')->all())->toBe(['300.00', '700.00'])
        ->and(Receipt::query()->whereBelongsTo($client)->value('paid_amount'))->toBe('1000.00');
})->with(['form_payments', 'form_card']);

test('начало закрытия и оплаты одновременно: ни касса, ни Kaspi не пишут в закрывающийся месяц', function (): void {
    ['organization' => $organization, 'other' => $other, 'billingPeriod' => $billingPeriod] = concurrencyFixture();

    // The start of closing holds the month row and moves it to processing.
    DB::beginTransaction();
    DB::select('select id from billing_periods where id = ? for update', [$billingPeriod->id]);

    $results = concurrencyRun(
        [
            ['type' => 'manual', 'organization_id' => $organization->id, 'client_id' => $other->id, 'amount' => '700.00', 'paid_at' => '2026-11-24', 'wait_for' => 'lock in share mode', 'blocks' => true],
            concurrencyPay($organization, '48213379201', '100417', '1000.00', waitFor: 'lock in share mode'),
        ],
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

test('ожидания разных строк в одной проводке вместе не выходят за срок ответа', function (): void {
    ['organization' => $organization, 'client' => $client] = concurrencyFixture();

    $manual = Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'amount' => 100,
        'paid_at' => '2026-11-20',
    ]);

    // One session holds the receipt and lets it go after about 2.5 s; another
    // holds a payment of the subscriber all along: the posting meets them in turn.
    $second = concurrencySecondConnection();
    DB::beginTransaction();
    DB::select('select id from receipts where client_id = ? for update', [$client->id]);
    $second->beginTransaction();
    $second->select('select id from payments where id = ? for update', [$manual->id]);

    $results = concurrencyRun(
        [concurrencyPay($organization, '48213379501', '100417', '1000.00', waitFor: 'from `receipts`')],
        whileBlocked: function (): void {
            usleep(2_200_000);
            DB::commit();
        },
        environment: ['KASPI_LOCK_WAIT_SECONDS' => '4'],
    );

    $second->rollBack();

    expect($results[0]['body'])->toBe('{"txn_id":"48213379501","result":4,"comment":"Платёж в обработке"}')
        ->and($results[0]['seconds'])->toBeLessThan(4.6)
        ->and(KaspiOperation::query()->where('txn_id', '48213379501')->sole()->state)->toBe(KaspiOperationState::Pending)
        ->and(Payment::query()->where('external_payment_id', '48213379501')->exists())->toBeFalse();
});

test('повтор с другой суммой при строке, занятой проводкой: ответ в срок, а повтор и расхождение попадают в журнал', function (): void {
    ['organization' => $organization] = concurrencyFixture();

    $this->call('GET', '/kaspi/'.$organization->kaspi_uid.'?command=pay&txn_id=48213379401&txn_date=20261124101521&account=100417&sum=1000.00', server: [
        'REMOTE_ADDR' => CONCURRENCY_PROXY,
        'HTTP_X_FORWARDED_FOR' => CONCURRENCY_KASPI_IP,
    ]);
    $operation = KaspiOperation::query()->where('txn_id', '48213379401')->sole();

    // A posting holds the operation for a long time.
    DB::beginTransaction();
    DB::select('select id from kaspi_operations where id = ? for update', [$operation->id]);

    $results = concurrencyRun(
        [concurrencyPay($organization, '48213379401', '100417', '9999.00', waitFor: 'insert into `kaspi_operation_retries`')],
        whileBlocked: fn () => null,
    );

    DB::rollBack();

    expect($results[0]['body'])->toBe((string) $operation->response)
        ->and($results[0]['seconds'])->toBeLessThan(1.5)
        ->and($operation->refresh()->retries_count)->toBe(0)
        ->and(DB::table('kaspi_operation_retries')->count())->toBe(1);

    $this->artisan('kaspi:post-pending')->assertSuccessful();

    expect($operation->refresh()->retries_count)->toBe(1)
        ->and($operation->retry_mismatch)->toBeTrue()
        ->and($operation->last_retry_at)->not->toBeNull()
        ->and(DB::table('kaspi_operation_retries')->count())->toBe(0);
});

test('check при занятой строке абонента: без записи в журнал ответ 5, и в срок', function (): void {
    ['organization' => $organization, 'client' => $client] = concurrencyFixture();

    DB::beginTransaction();
    DB::select('select id from clients where id = ? for update', [$client->id]);

    $results = concurrencyRun(
        [concurrencyCheck($organization, '48213379402', '100417')],
        whileBlocked: fn () => null,
        environment: ['KASPI_LOCK_WAIT_SECONDS' => '2'],
    );

    DB::rollBack();

    expect($results[0]['body'])->toBe('{"txn_id":"48213379402","result":5,"comment":"Приём оплат недоступен"}')
        ->and($results[0]['seconds'])->toBeLessThan(2.6)
        ->and(KaspiOperation::query()->where('txn_id', '48213379402')->exists())->toBeFalse()
        ->and(KaspiOperation::query()->where('command', KaspiCommand::Pay->value)->count())->toBe(0);
});

test('соединение запроса оборвалось при занятом абоненте: после переподключения ожидания не дольше срока', function (string $holdClient, array $kill, string $expected): void {
    ['organization' => $organization, 'client' => $client] = concurrencyFixture();

    // Laravel opens a new connection and repeats the statement on it; a new
    // session starts with the server's lock wait timeout, far past the deadline.
    expect((int) DB::scalar('select @@global.innodb_lock_wait_timeout'))->toBeGreaterThan(5);

    // `for update` holds even the insert of the operation (its foreign key reads
    // the subscriber); a shared lock lets the insert through and holds the posting.
    DB::beginTransaction();
    DB::select("select id from clients where id = ? {$holdClient}", [$client->id]);

    $results = concurrencyRun(
        [[...concurrencyPay($organization, '48213379601', '100417', '1000.00', waitFor: $kill['wait_for']), ...$kill]],
        // Let go well after the deadline: a side that waited in MariaDB ends then.
        whileBlocked: function (): void {
            usleep(3_000_000);
            DB::commit();
        },
        environment: ['KASPI_LOCK_WAIT_SECONDS' => '2'],
    );

    $operation = KaspiOperation::query()->where('txn_id', '48213379601')->first();

    expect($results[0]['killed'])->toBe(1)
        ->and($results[0]['reconnected'])->toBeTrue()
        ->and($results[0]['body'])->toBe($expected)
        ->and($results[0]['seconds'])->toBeLessThan(2.6)
        ->and(Payment::query()->where('external_payment_id', '48213379601')->exists())->toBeFalse();

    if (str_contains($expected, '"result":4')) {
        expect($operation?->state)->toBe(KaspiOperationState::Pending);
    } else {
        expect($operation)->toBeNull();
    }
})->with([
    'перед записью операции' => [
        'for update',
        ['wait_for' => 'insert into `kaspi_operations`', 'kill_before' => 'insert into `kaspi_operations`'],
        '{"txn_id":"48213379601","result":5,"comment":"Приём оплат недоступен"}',
    ],
    'перед началом транзакции проводки' => [
        'lock in share mode',
        ['wait_for' => 'from `billing_periods`', 'kill_after' => 'from `billing_periods`'],
        '{"txn_id":"48213379601","result":4,"comment":"Платёж в обработке"}',
    ],
]);

test('подбор «в обработке»: занятый абонент не задерживает оплату другого абонента', function (): void {
    ['client' => $client, 'other' => $other] = concurrencyFixture();

    $received = now()->subMinutes(2);
    $lockedOperation = KaspiOperation::factory()->forClient($client)->pending(1000)->create(['txn_id' => '48213379301', 'received_at' => $received]);
    $freeOperation = KaspiOperation::factory()->forClient($other)->pending(2000)->create(['txn_id' => '48213379302', 'received_at' => $received]);

    DB::beginTransaction();
    DB::select('select id from clients where id = ? for update', [$client->id]);

    concurrencyRun(
        [['type' => 'artisan', 'command' => 'kaspi:post-pending']],
        whileBlocked: fn () => null,
    );

    DB::rollBack();

    expect($freeOperation->refresh()->state)->toBe(KaspiOperationState::Posted)
        ->and($lockedOperation->refresh()->state)->toBe(KaspiOperationState::Pending)
        ->and($lockedOperation->state_reason)->toBe('Не дождались блокировки, проведётся автоматически')
        ->and($lockedOperation->posting_attempted_at)->not->toBeNull();
});
