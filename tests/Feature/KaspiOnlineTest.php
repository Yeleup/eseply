<?php

use App\Actions\CloseBillingMonth;
use App\BalanceAdjustmentType;
use App\BillingPeriodStatus;
use App\ClientType;
use App\Jobs\PostKaspiOperationJob;
use App\Kaspi\CannotPostKaspiOperation;
use App\Kaspi\KaspiActivity;
use App\Kaspi\KaspiCommand;
use App\Kaspi\KaspiDeadline;
use App\Kaspi\KaspiOperationState;
use App\Kaspi\KaspiRetryLog;
use App\Kaspi\PostKaspiOperation;
use App\Kaspi\PostPendingKaspiOperations;
use App\Models\Accrual;
use App\Models\BalanceAdjustment;
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
use App\PaymentMethod;
use App\Providers\AppServiceProvider;
use App\Support\BillingPeriodOperationLock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const KASPI_TEST_IP = '194.187.247.152';

// The web server in front of the application, as the application sees it.
const KASPI_TEST_PROXY = '172.18.0.1';

// Samples of the task, byte for byte.
const KASPI_SAMPLE_CHECK_FOUND = '{"txn_id":"48213377188","result":0,"sum":4250.00,"comment":"","fields":{"field1":{"@name":"Абонент","#text":"Иванов И. И."},"field2":{"@name":"Долг на 24.11.2026","#text":"4250.00"}}}';
const KASPI_SAMPLE_CHECK_NOT_FOUND = '{"txn_id":"48213301922","result":1,"comment":"Абонент не найден"}';
const KASPI_SAMPLE_ERROR = '{"txn_id":"48213377195","result":5,"comment":"Приём оплат недоступен"}';
const KASPI_SAMPLE_PAY_POSTED = '{"txn_id":"48213377190","prv_txn_id":"2016","result":0,"sum":4250.00,"comment":"OK"}';
const KASPI_SAMPLE_PAY_PENDING = '{"txn_id":"48213350311","result":4,"comment":"Платёж в обработке"}';

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(10, 15, 20));
    config([
        'kaspi.lock_wait_seconds' => 1,
        'kaspi.posting.job_lock_wait_seconds' => 1,
        // TRUSTED_PROXIES=172.18.0.1 on the server.
        'trustedproxy.proxies' => [KASPI_TEST_PROXY],
        'trustedproxy.configured' => [KASPI_TEST_PROXY],
    ]);
});

/**
 * @return array{organization: Organization, utilityService: UtilityService, billingPeriod: BillingPeriod}
 */
function kaspiOrganization(bool $enabled = true, string $period = '202611'): array
{
    $organization = Organization::factory()->create();
    $organization->forceFill(['kaspi_enabled' => $enabled])->save();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $billingPeriod = billingPeriodFor($organization, $period);

    return compact('organization', 'utilityService', 'billingPeriod');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function kaspiClient(Organization $organization, UtilityService $utilityService, array $attributes = []): Client
{
    return Client::factory()->for($organization)->for($utilityService)->create([
        'account_number' => '100417',
        'name' => 'Иванов Илья Игоревич',
        'client_type' => ClientType::Individual,
        'status' => 'active',
        'billing_type' => 'fixed',
        'fixed_amount' => 1000,
        ...$attributes,
    ]);
}

function kaspiOpeningBalance(Client $client, BillingPeriod $billingPeriod, float $amount): void
{
    BalanceAdjustment::query()->create([
        'organization_id' => $client->organization_id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'type' => BalanceAdjustmentType::OpeningBalance->value,
        'amount' => $amount,
        'adjusted_at' => '2026-11-01',
    ]);
}

/**
 * A request as the web server passes it on: from the proxy's address, with the
 * client's address in X-Forwarded-For.
 *
 * @param  array<string, string>  $headers
 */
function kaspiGet(Organization|string $organization, string $query, string $ip = KASPI_TEST_IP, array $headers = [], string $method = 'GET', string $remoteAddress = KASPI_TEST_PROXY): TestResponse
{
    $uid = $organization instanceof Organization ? $organization->kaspi_uid : $organization;

    $headers += ['X-Forwarded-For' => $ip];
    $server = ['REMOTE_ADDR' => $remoteAddress];

    // call() takes headers only as server variables.
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call($method, '/kaspi/'.$uid.'?'.$query, server: $server);
}

function kaspiCheckQuery(string $txnId = '48213377188', string $account = '100417'): string
{
    return "command=check&txn_id={$txnId}&account={$account}&sum=0.00";
}

function kaspiPayQuery(string $txnId = '48213377190', string $account = '100417', string $sum = '4250.00', string $txnDate = '20261124101521'): string
{
    return "command=pay&txn_id={$txnId}&txn_date={$txnDate}&account={$account}&sum={$sum}";
}

/**
 * The pay sample with our own payment number in place of 2016.
 */
function kaspiPostedSample(Payment $payment): string
{
    return str_replace('"prv_txn_id":"2016"', '"prv_txn_id":"'.$payment->id.'"', KASPI_SAMPLE_PAY_POSTED);
}

function kaspiAssertNothingCreated(): void
{
    expect(KaspiOperation::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
}

function kaspiMeterDebt(Organization $organization, UtilityService $utilityService, BillingPeriod $billingPeriod, Client $client, int $consumption): void
{
    Tariff::factory()->for($organization)->for($utilityService)->create([
        'client_type' => $client->client_type,
        'unit_price' => 100,
        'per_person_price' => 500,
        'starts_on' => '2020-01-01',
        'status' => 'active',
    ]);

    $meter = Meter::factory()->for($organization)->for($client)->for($utilityService)->create([
        'initial_reading' => 0,
        'status' => 'active',
    ]);

    MeterReading::query()->create([
        'meter_id' => $meter->id,
        'billing_period_id' => $billingPeriod->id,
        'previous_reading' => 0,
        'current_reading' => $consumption,
    ]);
}

afterEach(function (): void {
    Payment::flushEventListeners();
    Model::clearBootedModels();
});

// --- Ответы побайтно -----------------------------------------------------------

test('ответы check 0, 1 и 5 совпадают с образцами побайтно', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();
    $client = kaspiClient($organization, $utilityService);
    kaspiOpeningBalance($client, $billingPeriod, 4250);

    $found = kaspiGet($organization, kaspiCheckQuery())
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json; charset=utf-8');

    expect($found->getContent())->toBe(KASPI_SAMPLE_CHECK_FOUND)
        ->and($found->headers->get('Cache-Control'))->toContain('no-store')
        ->and($found->headers->getCookies())->toBe([]);

    expect(kaspiGet($organization, kaspiCheckQuery('48213301922', '999990'))->getContent())->toBe(KASPI_SAMPLE_CHECK_NOT_FOUND);

    $organization->forceFill(['kaspi_enabled' => false])->save();

    expect(kaspiGet($organization, kaspiCheckQuery('48213377195'))->getContent())->toBe(KASPI_SAMPLE_ERROR);
});

test('ответы pay 0, 4 и 5 совпадают с образцами побайтно', function (): void {
    Queue::fake();
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    $posted = kaspiGet($organization, kaspiPayQuery())->assertOk()->assertHeader('Content-Type', 'application/json; charset=utf-8');
    $payment = Payment::query()->sole();

    expect($posted->getContent())->toBe(kaspiPostedSample($payment))
        ->and($posted->headers->get('Cache-Control'))->toContain('no-store');

    $billingPeriod->forceFill(['status' => BillingPeriodStatus::Processing])->save();

    expect(kaspiGet($organization, kaspiPayQuery('48213350311', sum: '2750.00'))->getContent())->toBe(KASPI_SAMPLE_PAY_PENDING);

    expect(kaspiGet($organization, kaspiPayQuery('48213377195', account: '999990'))->getContent())->toBe(KASPI_SAMPLE_ERROR);
});

// --- Проверка входа -------------------------------------------------------------

test('каждое правило проверки входа даёт 5 и ничего не создаёт', function (string $query, string $echoedTxnId): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    $response = kaspiGet($organization, $query)->assertOk();

    expect($response->getContent())->toBe('{"txn_id":"'.$echoedTxnId.'","result":5,"comment":"Приём оплат недоступен"}');
    kaspiAssertNothingCreated();
})->with([
    'нет command' => ['txn_id=48213377195&account=100417&sum=0.00', '48213377195'],
    'command не check и не pay' => ['command=status&txn_id=48213377195&account=100417', '48213377195'],
    'command в верхнем регистре' => ['command=CHECK&txn_id=48213377195&account=100417', '48213377195'],
    'command с пробелом' => ['command=check%20&txn_id=48213377195&account=100417', '48213377195'],
    'нет txn_id' => ['command=check&account=100417&sum=0.00', ''],
    'пустой txn_id' => ['command=check&txn_id=&account=100417', ''],
    'txn_id из 19 цифр' => ['command=check&txn_id=1234567890123456789&account=100417', ''],
    'txn_id с буквами' => ['command=check&txn_id=4821337719a&account=100417', ''],
    'отрицательный txn_id' => ['command=check&txn_id=-48213377195&account=100417', ''],
    'нет account' => ['command=check&txn_id=48213377195&sum=0.00', '48213377195'],
    'account из 5 цифр' => ['command=check&txn_id=48213377195&account=10024', '48213377195'],
    'account из 11 цифр' => ['command=check&txn_id=48213377195&account=10041710024', '48213377195'],
    'account с буквой' => ['command=check&txn_id=48213377195&account=10024A', '48213377195'],
    'account с пробелом впереди' => ['command=check&txn_id=48213377195&account=%20100417', '48213377195'],
    'account с переводом строки' => ['command=check&txn_id=48213377195&account=100417%0A', '48213377195'],
    'account с плюсом' => ['command=check&txn_id=48213377195&account=100417+', '48213377195'],
    'pay без sum' => ['command=pay&txn_id=48213377195&txn_date=20261124101521&account=100417', '48213377195'],
    'sum без копеек' => ['command=pay&txn_id=48213377195&txn_date=20261124101521&account=100417&sum=4250', '48213377195'],
    'sum с одним знаком' => ['command=pay&txn_id=48213377195&txn_date=20261124101521&account=100417&sum=4250.0', '48213377195'],
    'sum с тремя знаками' => ['command=pay&txn_id=48213377195&txn_date=20261124101521&account=100417&sum=4250.000', '48213377195'],
    'sum с запятой' => ['command=pay&txn_id=48213377195&txn_date=20261124101521&account=100417&sum=4250%2C00', '48213377195'],
    'нулевая sum' => ['command=pay&txn_id=48213377195&txn_date=20261124101521&account=100417&sum=0.00', '48213377195'],
    'отрицательная sum' => ['command=pay&txn_id=48213377195&txn_date=20261124101521&account=100417&sum=-1.00', '48213377195'],
    'sum из 13 цифр' => ['command=pay&txn_id=48213377195&txn_date=20261124101521&account=100417&sum=1234567890123.00', '48213377195'],
    'sum в экспоненте' => ['command=pay&txn_id=48213377195&txn_date=20261124101521&account=100417&sum=1e3.00', '48213377195'],
    'pay без txn_date' => ['command=pay&txn_id=48213377195&account=100417&sum=4250.00', '48213377195'],
    'txn_date из 13 цифр' => ['command=pay&txn_id=48213377195&txn_date=2026112414315&account=100417&sum=4250.00', '48213377195'],
    'txn_date из 15 цифр' => ['command=pay&txn_id=48213377195&txn_date=202611241015210&account=100417&sum=4250.00', '48213377195'],
    'несуществующий день' => ['command=pay&txn_id=48213377195&txn_date=20261131120000&account=100417&sum=4250.00', '48213377195'],
    'несуществующий час' => ['command=pay&txn_id=48213377195&txn_date=20261124250000&account=100417&sum=4250.00', '48213377195'],
    'повтор параметра' => ['command=check&txn_id=48213377195&account=100417&account=100246', '48213377195'],
    'повтор txn_id' => ['command=check&txn_id=48213377195&txn_id=48213377196&account=100417', ''],
    'массив' => ['command=check&txn_id=48213377195&account%5B%5D=100417', '48213377195'],
    'массив в игнорируемом параметре' => ['command=check&txn_id=48213377195&account=100417&data1[]=x', '48213377195'],
]);

test('sum и dataN у check игнорируются, ведущие нули лицевого счёта сохраняются', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);
    kaspiClient($organization, $utilityService, ['account_number' => '0100246', 'name' => 'Петров Пётр', 'iin' => '900101300001', 'phone' => '+77010000002']);

    expect(KaspiCommand::Check->value)->toBe('check');

    $response = kaspiGet($organization, 'command=check&txn_id=48213377201&account=100417&sum=abc&data1=x&data2=')->assertOk();

    expect($response->json('result'))->toBe(0);

    // «0100246» — это свой счёт, а не 100246.
    expect(kaspiGet($organization, 'command=check&txn_id=48213377202&account=0100246&sum=0.00')->json('result'))->toBe(0)
        ->and(KaspiOperation::query()->where('txn_id', '48213377202')->value('account'))->toBe('0100246');
});

test('неизвестный uid даёт 5', function (string $uid): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    expect(kaspiGet($uid, kaspiCheckQuery('48213377195'))->assertOk()->getContent())->toBe(KASPI_SAMPLE_ERROR);
    kaspiAssertNothingCreated();
})->with([
    'другой uuid' => ['c3d1a6e2-7f45-4b8e-9a21-5e6f70a8b9c0'],
    'не uuid' => ['1'],
]);

// --- Доступ по IP -----------------------------------------------------------------

test('запрос не от Kaspi через свой веб-сервер даёт 5, какие бы заголовки ни пришли', function (string $remoteAddress, array $headers): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    $server = ['REMOTE_ADDR' => $remoteAddress];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    expect($this->call('GET', '/kaspi/'.$organization->kaspi_uid.'?'.kaspiPayQuery('48213377195'), server: $server)->assertOk()->getContent())
        ->toBe(KASPI_SAMPLE_ERROR);

    kaspiAssertNothingCreated();
})->with([
    'прямое подключение с адреса Kaspi' => [KASPI_TEST_IP, []],
    'чужой публичный адрес' => ['203.0.113.9', []],
    'подложенный X-Forwarded-For напрямую' => ['203.0.113.9', ['X-Forwarded-For' => KASPI_TEST_IP]],
    'соседний частный адрес, не веб-сервер' => ['172.18.0.7', ['X-Forwarded-For' => KASPI_TEST_IP]],
    'шлюз другой сети Docker' => ['172.19.0.1', ['X-Forwarded-For' => KASPI_TEST_IP]],
    'через веб-сервер с подложенным X-Forwarded-For' => [KASPI_TEST_PROXY, ['X-Forwarded-For' => KASPI_TEST_IP.', 203.0.113.9']],
    'через веб-сервер не с адреса Kaspi' => [KASPI_TEST_PROXY, ['X-Forwarded-For' => '203.0.113.9']],
    'подложенный X-Real-IP' => [KASPI_TEST_PROXY, ['X-Forwarded-For' => '203.0.113.9', 'X-Real-IP' => KASPI_TEST_IP]],
    'веб-сервер без X-Forwarded-For' => [KASPI_TEST_PROXY, []],
]);

test('контрольный запрос чек-листа доказывает отказ проверки отправителя: строка лога not_kaspi до поиска организации', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization(enabled: false);
    kaspiClient($organization, $utilityService);

    // Kaspi itself gets 5 from a disabled organization too, without this log line:
    // the code alone does not prove that the sender check rejected the request.
    Log::spy();
    expect(kaspiGet($organization, kaspiCheckQuery('48213377195'))->json('result'))->toBe(5);
    Log::shouldNotHaveReceived('info', [Mockery::on(fn (string $message): bool => $message === 'Kaspi: запрос не принят'), Mockery::any()]);

    // The control request: the client's own X-Forwarded-For, the web server
    // appended the real address of the client.
    Log::spy();
    $organizationQueries = 0;
    DB::listen(function ($query) use (&$organizationQueries): void {
        if (str_contains($query->sql, 'organizations')) {
            $organizationQueries++;
        }
    });

    expect(kaspiGet($organization, kaspiCheckQuery('48213377195'), ip: KASPI_TEST_IP.', 203.0.113.9')->getContent())->toBe(KASPI_SAMPLE_ERROR)
        ->and($organizationQueries)->toBe(0);

    Log::shouldHaveReceived('info')->once()->with('Kaspi: запрос не принят', ['reason' => 'not_kaspi', 'remote_addr' => KASPI_TEST_PROXY]);
    expect(KaspiOperation::query()->count())->toBe(1);
});

test('свой веб-сервер передаёт адрес Kaspi, дописанный в конец цепочки, и запрос принимается', function (string $forwardedFor): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    $response = kaspiGet($organization, kaspiCheckQuery(), headers: [
        'X-Forwarded-For' => $forwardedFor,
        'X-Forwarded-Proto' => 'https',
    ]);

    expect($response->json('result'))->toBe(0);
})->with([
    'только адрес Kaspi' => [KASPI_TEST_IP],
    'клиент дописал своё, веб-сервер — настоящий адрес' => ['198.51.100.7, '.KASPI_TEST_IP],
]);

test('пока TRUSTED_PROXIES не задан точными адресами, приём Kaspi закрыт, а сайт доверяет частным сетям', function (array $configured): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    config([
        'trustedproxy.proxies' => $configured === [] ? ['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'] : $configured,
        'trustedproxy.configured' => $configured,
    ]);

    expect(kaspiGet($organization, kaspiCheckQuery('48213377195'))->getContent())->toBe(KASPI_SAMPLE_ERROR);
    kaspiAssertNothingCreated();
})->with([
    'не задан' => [[]],
    'диапазон вместо адреса' => [['172.16.0.0/12']],
    'звёздочка' => [['*']],
]);

test('по умолчанию сайт доверяет частным сетям, а Kaspi не доверяет никому; адрес Kaspi — из конфига', function (): void {
    $default = require base_path('config/trustedproxy.php');

    expect($default['configured'])->toBe([])
        ->and($default['proxies'])->toBe(['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7'])
        ->and($default['proxies'])->not->toContain('*')
        ->and(config('kaspi.allowed_ips'))->toBe([KASPI_TEST_IP]);
});

test('другие методы, включая HEAD и OPTIONS, получают 405 с no-store и ничего не создают', function (string $method): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    $response = kaspiGet($organization, kaspiPayQuery(), method: $method)
        ->assertStatus(405)
        ->assertHeader('Allow', 'GET');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    kaspiAssertNothingCreated();
})->with(['HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE', 'TRACE', 'CONNECT', 'PROPFIND']);

test('после запроса Kaspi соединение получает свой тайм-аут ожидания блокировки обратно', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    DB::statement('set session innodb_lock_wait_timeout = 37');

    kaspiGet($organization, kaspiPayQuery());
    kaspiGet($organization, kaspiPayQuery());
    kaspiGet($organization, kaspiCheckQuery());

    expect((int) DB::scalar('select @@session.innodb_lock_wait_timeout'))->toBe(37)
        ->and(Payment::query()->count())->toBe(1);
});

test('срок проверяется и перед первой попыткой: истёк до записи операции — 5, ничего не создано', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);
    config(['kaspi.lock_wait_seconds' => 0]);

    $tried = false;

    expect(fn () => KaspiDeadline::in(0)->retryWithoutWaiting(function () use (&$tried): void {
        $tried = true;
    }))->toThrow(CannotPostKaspiOperation::class)
        ->and($tried)->toBeFalse();

    expect(kaspiGet($organization, kaspiPayQuery())->getContent())->toBe('{"txn_id":"48213377190","result":5,"comment":"Приём оплат недоступен"}');

    kaspiAssertNothingCreated();
});

test('срок истёк между записью операции и проводкой: операция сохранена, Kaspi получает 4', function (): void {
    Queue::fake();
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);
    config(['kaspi.lock_wait_seconds' => 0.3]);

    // The time runs out right after the operation is saved, before the posting tries.
    KaspiOperation::created(fn () => usleep(400_000));

    expect(kaspiGet($organization, kaspiPayQuery())->getContent())->toBe('{"txn_id":"48213377190","result":4,"comment":"Платёж в обработке"}');

    $operation = KaspiOperation::query()->sole();

    expect($operation->state)->toBe(KaspiOperationState::Pending)
        ->and($operation->state_reason)->toBe('Не дождались блокировки, проведётся автоматически')
        ->and(Payment::query()->count())->toBe(0);

    Queue::assertPushed(PostKaspiOperationJob::class);
});

// --- check -----------------------------------------------------------------------

test('check: найден, не найден, неактивный и абонент другой организации с тем же номером', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();
    $client = kaspiClient($organization, $utilityService);
    kaspiOpeningBalance($client, $billingPeriod, 4250);
    kaspiClient($organization, $utilityService, ['account_number' => '100523', 'status' => 'inactive', 'iin' => '900101300002', 'phone' => '+77010000003']);

    ['organization' => $other, 'utilityService' => $otherService] = kaspiOrganization();
    kaspiClient($other, $otherService, ['account_number' => '100777', 'name' => 'Ахметова Дана', 'iin' => '900101300003', 'phone' => '+77010000004']);

    expect(kaspiGet($organization, kaspiCheckQuery())->getContent())->toBe(KASPI_SAMPLE_CHECK_FOUND)
        ->and(kaspiGet($organization, kaspiCheckQuery('48213377301', '100523'))->json('result'))->toBe(1)
        ->and(kaspiGet($organization, kaspiCheckQuery('48213377302', '100777'))->json('result'))->toBe(1)
        ->and(kaspiGet($other, kaspiCheckQuery('48213377303', '100417'))->json('result'))->toBe(1);

    $found = KaspiOperation::query()->where('txn_id', '48213377188')->sole();

    expect($found->organization_id)->toBe($organization->id)
        ->and($found->command)->toBe(KaspiCommand::Check)
        ->and($found->client_id)->toBe($client->id)
        ->and($found->state)->toBe(KaspiOperationState::Found)
        ->and($found->last_result)->toBe(0)
        ->and($found->debt)->toBe('4250.00')
        ->and(KaspiOperation::query()->where('txn_id', '48213377303')->value('organization_id'))->toBe($other->id)
        ->and(KaspiOperation::query()->where('txn_id', '48213377301')->value('state'))->toBe(KaspiOperationState::NotFound);
});

test('check: юрлицо видит наименование полностью, ИП — фамилию и инициалы', function (ClientType $type, string $name, string $expected): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService, ['client_type' => $type, 'name' => $name]);

    expect(kaspiGet($organization, kaspiCheckQuery())->json('fields.field1.#text'))->toBe($expected);
})->with([
    'ИП' => [ClientType::SoleProprietor, 'ИП Нурланов Ерлан Серикович', 'Нурланов Е. С.'],
    'физлицо без отчества' => [ClientType::Individual, 'Ахметова Дана', 'Ахметова Д.'],
    'ТОО' => [ClientType::Llp, 'ТОО «Пример Сервис»', 'ТОО «Пример Сервис»'],
    'коммерческий объект' => [ClientType::Commercial, 'Магазин «Тест»', 'Магазин «Тест»'],
    'бюджетная организация' => [ClientType::Budget, 'КГУ «Школа № 1»', 'КГУ «Школа № 1»'],
]);

test('check: выключенный приём даёт 5 и пишет в журнал ошибку', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization(enabled: false);
    kaspiClient($organization, $utilityService);

    expect(kaspiGet($organization, kaspiCheckQuery('48213377195'))->getContent())->toBe(KASPI_SAMPLE_ERROR);

    $operation = KaspiOperation::query()->sole();

    expect($operation->state)->toBe(KaspiOperationState::Error)
        ->and($operation->state_reason)->toBe('Приём оплат выключен')
        ->and($operation->last_result)->toBe(5)
        ->and(Payment::query()->count())->toBe(0);
});

test('check: долг — обычный, при закрытии месяца, у нового абонента с квитанцией, только корректировка и переплата', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();

    // Новый абонент с квитанцией: 30 м³ × 100 = 3000, прошлых начислений нет.
    $withReceipt = kaspiClient($organization, $utilityService, ['billing_type' => 'meter']);
    kaspiMeterDebt($organization, $utilityService, $billingPeriod, $withReceipt, 30);
    Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $withReceipt->id,
        'billing_period_id' => $billingPeriod->id,
        'amount' => 500,
        'paid_at' => '2026-11-10',
    ]);

    $onlyAdjustment = kaspiClient($organization, $utilityService, ['account_number' => '100246', 'iin' => '900101300004', 'phone' => '+77010000005']);
    BalanceAdjustment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $onlyAdjustment->id,
        'billing_period_id' => $billingPeriod->id,
        'type' => BalanceAdjustmentType::ManualAdjustment->value,
        'amount' => 1250.5,
        'adjusted_at' => '2026-11-02',
    ]);

    $overpaid = kaspiClient($organization, $utilityService, ['account_number' => '100247', 'iin' => '900101300005', 'phone' => '+77010000006']);
    kaspiOpeningBalance($overpaid, $billingPeriod, -700);

    expect(kaspiGet($organization, kaspiCheckQuery('48213377401'))->json('fields.field2.#text'))->toBe('2500.00')
        ->and(kaspiGet($organization, kaspiCheckQuery('48213377402', '100246'))->json('fields.field2.#text'))->toBe('1250.50')
        ->and(kaspiGet($organization, kaspiCheckQuery('48213377403', '100247'))->getContent())
        ->toBe('{"txn_id":"48213377403","result":0,"sum":0.00,"comment":"","fields":{"field1":{"@name":"Абонент","#text":"Иванов И. И."},"field2":{"@name":"Долг на 24.11.2026","#text":"0.00"}}}');

    // Во время закрытия долг считается за закрываемый месяц.
    $billingPeriod->forceFill(['status' => BillingPeriodStatus::Processing])->save();

    expect(kaspiGet($organization, kaspiCheckQuery('48213377404'))->json('sum'))->toBe(2500.0);
    expect(kaspiGet($organization, kaspiCheckQuery('48213377405'))->getContent())->toContain('"sum":2500.00,');
});

test('check: обычный долг — сальдо закрытого месяца, начисление по квитанции и оплата текущего', function (): void {
    $organization = Organization::factory()->create();
    $organization->forceFill(['kaspi_enabled' => true])->save();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = kaspiClient($organization, $utilityService, ['billing_type' => 'meter']);

    Accrual::factory()->for($organization)->for($client)->for($utilityService)->create([
        'period' => '202610',
        'account_number' => $client->account_number,
        'client_name' => $client->name,
        'billing_type' => 'meter',
        'amount' => 1200,
        'opening_balance' => 0,
        'closing_balance' => 1200,
    ]);

    $billingPeriod = billingPeriodFor($organization, '202611');
    kaspiMeterDebt($organization, $utilityService, $billingPeriod, $client, 30);
    Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'amount' => 700,
        'paid_at' => '2026-11-05',
    ]);

    // 1200 сальдо октября + 3000 по квитанции ноября − 700 оплачено.
    expect(kaspiGet($organization, kaspiCheckQuery('48213377601'))->getContent())
        ->toBe('{"txn_id":"48213377601","result":0,"sum":3500.00,"comment":"","fields":{"field1":{"@name":"Абонент","#text":"Иванов И. И."},"field2":{"@name":"Долг на 24.11.2026","#text":"3500.00"}}}');
});

test('check: повтор txn_id отдаёт первый ответ, даже если долг изменился', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();
    $client = kaspiClient($organization, $utilityService);
    kaspiOpeningBalance($client, $billingPeriod, 4250);

    $first = kaspiGet($organization, kaspiCheckQuery())->getContent();

    Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'amount' => 1000,
    ]);

    expect(kaspiGet($organization, kaspiCheckQuery())->getContent())->toBe($first)
        ->and(KaspiOperation::query()->sole()->retries_count)->toBe(1);
});

// --- pay ---------------------------------------------------------------------------

test('pay проводит оплату Kaspi и пересчитывает квитанцию', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();
    $client = kaspiClient($organization, $utilityService, ['billing_type' => 'meter']);
    kaspiMeterDebt($organization, $utilityService, $billingPeriod, $client, 50);

    expect((float) Receipt::query()->whereBelongsTo($client)->value('paid_amount'))->toBe(0.0);

    $response = kaspiGet($organization, kaspiPayQuery());

    $payment = Payment::query()->sole();
    $operation = KaspiOperation::query()->sole();

    expect($response->getContent())->toBe(kaspiPostedSample($payment))
        ->and($payment->organization_id)->toBe($organization->id)
        ->and($payment->client_id)->toBe($client->id)
        ->and($payment->billing_period_id)->toBe($billingPeriod->id)
        ->and($payment->method)->toBe(PaymentMethod::Kaspi)
        ->and($payment->external_provider)->toBe('kaspi')
        ->and($payment->external_payment_id)->toBe('48213377190')
        ->and($payment->received_by_user_id)->toBeNull()
        ->and($payment->amount)->toBe('4250.00')
        ->and($payment->paid_at->toDateString())->toBe('2026-11-24')
        ->and($operation->command)->toBe(KaspiCommand::Pay)
        ->and($operation->state)->toBe(KaspiOperationState::Posted)
        ->and($operation->payment_id)->toBe($payment->id)
        ->and($operation->last_result)->toBe(0)
        ->and($operation->sum)->toBe('4250.00')
        ->and($operation->txn_date->format('YmdHis'))->toBe('20261124101521')
        ->and($operation->received_at->format('Y-m-d H:i:s'))->toBe('2026-11-24 10:15:20');

    $receipt = Receipt::query()->whereBelongsTo($client)->sole();

    expect((float) $receipt->paid_amount)->toBe(4250.0)
        ->and((float) $receipt->closing_balance)->toBe(750.0);
});

test('pay: paid_at — дата из txn_date как пришла, без пересчёта часового пояса', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    kaspiGet($organization, kaspiPayQuery(txnDate: '20261123235959'));

    expect(Payment::query()->sole()->paid_at->toDateString())->toBe('2026-11-23')
        ->and(KaspiOperation::query()->sole()->txn_date->format('Y-m-d H:i:s'))->toBe('2026-11-23 23:59:59');
});

test('pay: аванс при нулевом долге проводится, сумма с ведущими нулями хранится канонически', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    $response = kaspiGet($organization, kaspiPayQuery(sum: '0012.50'));

    expect($response->json('result'))->toBe(0)
        ->and($response->getContent())->toContain('"sum":12.50,')
        ->and(Payment::query()->sole()->amount)->toBe('12.50');
});

test('pay: повтор возвращает тот же ответ и не создаёт вторую оплату', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    $first = kaspiGet($organization, kaspiPayQuery())->getContent();
    $second = kaspiGet($organization, kaspiPayQuery())->getContent();
    $third = kaspiGet($organization, kaspiPayQuery())->getContent();

    $operation = KaspiOperation::query()->sole();

    expect($second)->toBe($first)
        ->and($third)->toBe($first)
        ->and(Payment::query()->count())->toBe(1)
        ->and($operation->retries_count)->toBe(2)
        ->and($operation->last_retry_at)->not->toBeNull()
        ->and($operation->retry_mismatch)->toBeFalse();
});

test('pay: повтор с другими данными до и после проводки — проводка по первой сумме и отметка расхождения', function (): void {
    Queue::fake();
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    // До проводки: месяц закрывается.
    $billingPeriod->forceFill(['status' => BillingPeriodStatus::Processing])->save();

    expect(kaspiGet($organization, kaspiPayQuery('48213350311', sum: '2750.00'))->getContent())->toBe(KASPI_SAMPLE_PAY_PENDING)
        ->and(kaspiGet($organization, kaspiPayQuery('48213350311', sum: '9999.00'))->getContent())->toBe(KASPI_SAMPLE_PAY_PENDING);

    $pending = KaspiOperation::query()->sole();

    expect($pending->retry_mismatch)->toBeTrue()
        ->and($pending->sum)->toBe('2750.00')
        ->and($pending->state)->toBe(KaspiOperationState::Pending);

    $billingPeriod->forceFill(['status' => BillingPeriodStatus::Failed])->save();
    app(PostKaspiOperation::class)->handle($pending, KaspiDeadline::in(1));

    expect(Payment::query()->sole()->amount)->toBe('2750.00');

    // После проводки: другой счёт и дата, ответ тот же.
    $posted = kaspiGet($organization, kaspiPayQuery('48213350311', account: '100246', sum: '2750.00', txnDate: '20261125000000'));

    expect($posted->json('result'))->toBe(0)
        ->and($posted->json('prv_txn_id'))->toBe((string) Payment::query()->sole()->id)
        ->and($posted->json('sum'))->toBe(2750.0)
        ->and(Payment::query()->count())->toBe(1)
        ->and($pending->refresh()->retries_count)->toBe(2)
        ->and($pending->last_result)->toBe(0);

    // Отметка остаётся и у проведённой операции, даже после точного повтора.
    kaspiGet($organization, kaspiPayQuery('48213350311', sum: '2750.00'));

    expect($pending->refresh()->retry_mismatch)->toBeTrue();
});

test('журнал: повтор получил 4 во время проводки, а первый запрос — 0: последний код в журнале 0', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    // While the first request posts, a repeat reads the operation in processing,
    // answers 4 and writes itself down; the first request answers 0 afterwards.
    Payment::created(function (Payment $payment): void {
        KaspiRetryLog::record(KaspiOperation::query()->where('txn_id', $payment->external_payment_id)->sole(), false, 4, now());
    });

    $response = kaspiGet($organization, kaspiPayQuery());
    $operation = KaspiOperation::query()->sole();

    expect($response->json('result'))->toBe(0)
        ->and($operation->retries_count)->toBe(1)
        ->and($operation->last_result)->toBe(0)
        ->and(DB::table('kaspi_operation_retries')->count())->toBe(0);
});

test('журнал: операция проведена заданием, повтор во время проводки получил 4 — последний код 4, следующий повтор даёт 0', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    $client = kaspiClient($organization, $utilityService);
    $operation = KaspiOperation::factory()->forClient($client)->pending(4250)->create(['txn_id' => '48213377190', 'txn_date' => '2026-11-24 10:15:21']);

    KaspiRetryLog::record($operation, false, 4, now());
    (new PostKaspiOperationJob($operation->id))->handle(app(PostKaspiOperation::class));

    // The job answers nobody: Kaspi has heard only 4 so far.
    expect($operation->refresh()->state)->toBe(KaspiOperationState::Posted)
        ->and($operation->retries_count)->toBe(1)
        ->and($operation->last_result)->toBe(4);

    expect(kaspiGet($organization, kaspiPayQuery())->json('result'))->toBe(0)
        ->and($operation->refresh()->retries_count)->toBe(2)
        ->and($operation->last_result)->toBe(0);
});

test('журнал: в одной порции повторов 0 и записанный позже 4 дают последний код 0', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    $client = kaspiClient($organization, $utilityService);
    $operation = KaspiOperation::factory()->forClient($client)->pending(4250)->create(['txn_id' => '48213377190', 'txn_date' => '2026-11-24 10:15:21']);

    // Posted by the job: the stored code is still 4.
    app(PostKaspiOperation::class)->handle($operation, KaspiDeadline::in(1));

    // The repeat that answered 4 read the operation before the commit, but wrote
    // itself down after the one that answered 0.
    KaspiRetryLog::record($operation, false, 0, now());
    KaspiRetryLog::record($operation, false, 4, now());
    KaspiRetryLog::fold($operation->id);

    expect($operation->refresh()->retries_count)->toBe(2)
        ->and($operation->last_result)->toBe(0);
});

test('журнал: время последнего повтора не уменьшается, если раньше полученный повтор перенесён позже', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    $client = kaspiClient($organization, $utilityService);
    $operation = KaspiOperation::factory()->forClient($client)->pending(4250)->create([
        'txn_id' => '48213377190',
        'retries_count' => 3,
        'last_retry_at' => now(),
    ]);

    KaspiRetryLog::record($operation, false, 4, now()->subSeconds(10));
    KaspiRetryLog::fold($operation->id);

    expect($operation->refresh()->retries_count)->toBe(4)
        ->and($operation->last_retry_at->equalTo(now()))->toBeTrue();
});

test('pay: повтор известного txn_id отвечает по операции, даже если абонент стал неактивным и приём выключен', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    $client = kaspiClient($organization, $utilityService);

    $first = kaspiGet($organization, kaspiPayQuery())->getContent();

    $client->forceFill(['status' => 'inactive'])->save();
    $organization->forceFill(['kaspi_enabled' => false])->save();

    expect(kaspiGet($organization, kaspiPayQuery())->getContent())->toBe($first)
        ->and(kaspiGet($organization, kaspiPayQuery('48213377195'))->getContent())->toBe(KASPI_SAMPLE_ERROR)
        ->and(Payment::query()->count())->toBe(1);
});

test('pay: новая операция для неактивного, чужого или несуществующего абонента даёт 5 и ничего не создаёт', function (string $account): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService, ['status' => 'inactive']);

    ['organization' => $other, 'utilityService' => $otherService] = kaspiOrganization();
    kaspiClient($other, $otherService, ['account_number' => '100777', 'iin' => '900101300006', 'phone' => '+77010000007']);

    expect(kaspiGet($organization, kaspiPayQuery('48213377195', $account))->getContent())->toBe(KASPI_SAMPLE_ERROR);

    kaspiAssertNothingCreated();
})->with([
    'неактивный' => ['100417'],
    'другой организации' => ['100777'],
    'нет такого' => ['999990'],
]);

test('pay: при выключенном приёме новая оплата даёт 5 и ничего не создаёт', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization(enabled: false);
    kaspiClient($organization, $utilityService);

    expect(kaspiGet($organization, kaspiPayQuery('48213377195'))->getContent())->toBe(KASPI_SAMPLE_ERROR);

    kaspiAssertNothingCreated();
});

test('pay: ошибка проводки откатывает транзакцию целиком, а Kaspi получает 4', function (): void {
    Queue::fake();
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();
    $client = kaspiClient($organization, $utilityService, ['billing_type' => 'meter']);
    kaspiMeterDebt($organization, $utilityService, $billingPeriod, $client, 50);

    Payment::created(function (): void {
        throw new RuntimeException('Сбой после создания оплаты.');
    });

    expect(kaspiGet($organization, kaspiPayQuery())->getContent())
        ->toBe('{"txn_id":"48213377190","result":4,"comment":"Платёж в обработке"}');

    $operation = KaspiOperation::query()->sole();

    expect(Payment::query()->count())->toBe(0)
        ->and((float) Receipt::query()->whereBelongsTo($client)->value('paid_amount'))->toBe(0.0)
        ->and($operation->state)->toBe(KaspiOperationState::Pending)
        ->and($operation->payment_id)->toBeNull()
        ->and($operation->state_reason)->toBe('Ошибка проводки, повтор автоматически');

    Queue::assertPushed(PostKaspiOperationJob::class, fn (PostKaspiOperationJob $job): bool => $job->operationId === $operation->id);
});

test('pay: одновременный повтор — вторая операция и вторая оплата невозможны', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    kaspiGet($organization, kaspiPayQuery());
    $operation = KaspiOperation::query()->sole();
    $payment = Payment::query()->sole();

    // Проводка уже проведённой операции ничего не делает.
    app(PostKaspiOperation::class)->handle($operation, KaspiDeadline::in(1));

    expect(Payment::query()->count())->toBe(1);

    expect(fn () => DB::transaction(fn () => KaspiOperation::query()->create([
        'organization_id' => $organization->id,
        'command' => KaspiCommand::Pay,
        'txn_id' => '48213377190',
        'account' => '100417',
        'received_at' => now(),
        'state' => KaspiOperationState::Pending,
        'last_result' => 4,
    ])))->toThrow(UniqueConstraintViolationException::class);

    expect(fn () => DB::transaction(fn () => Payment::query()->insert([
        'organization_id' => $organization->id,
        'client_id' => $payment->client_id,
        'billing_period_id' => $payment->billing_period_id,
        'method' => 'kaspi',
        'external_provider' => 'kaspi',
        'external_payment_id' => '48213377190',
        'amount' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);
});

test('один и тот же номер Kaspi в двух организациях — две разные оплаты', function (): void {
    ['organization' => $first, 'utilityService' => $firstService] = kaspiOrganization();
    kaspiClient($first, $firstService);
    ['organization' => $second, 'utilityService' => $secondService] = kaspiOrganization();
    kaspiClient($second, $secondService, ['iin' => '900101300007', 'phone' => '+77010000008']);

    expect(kaspiGet($first, kaspiPayQuery())->json('result'))->toBe(0)
        ->and(kaspiGet($second, kaspiPayQuery())->json('result'))->toBe(0)
        ->and(Payment::query()->where('external_payment_id', '48213377190')->count())->toBe(2);
});

test('два платежа Kaspi одного абонента и оплата на кассе — сумма в квитанции верная, строка абонента блокируется первой', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();
    $client = kaspiClient($organization, $utilityService, ['billing_type' => 'meter']);
    kaspiMeterDebt($organization, $utilityService, $billingPeriod, $client, 100);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    kaspiGet($organization, kaspiPayQuery('48213377501', sum: '1000.00'));
    kaspiGet($organization, kaspiPayQuery('48213377502', sum: '2500.00'));

    $clientLock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from `clients`') && str_contains($sql, 'for update'));
    $paymentInsert = collect($queries)->search(fn (string $sql): bool => str_starts_with($sql, 'insert into `payments`'));

    expect($clientLock)->not->toBeFalse()
        ->and($paymentInsert)->not->toBeFalse()
        ->and($clientLock)->toBeLessThan($paymentInsert);

    Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'amount' => 700,
    ]);

    expect((float) Receipt::query()->whereBelongsTo($client)->value('paid_amount'))->toBe(4200.0)
        ->and(Payment::query()->whereBelongsTo($client)->sum('amount'))->toEqual(4200);
});

// --- Доводка «в обработке» -------------------------------------------------------

test('во время закрытия pay получает 4, а после успешного закрытия оплата проводится в новый месяц без повтора Kaspi', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    $claimed = app(CloseBillingMonth::class)->claim($organization, $billingPeriod);

    expect(kaspiGet($organization, kaspiPayQuery('48213350311', sum: '2750.00'))->getContent())->toBe(KASPI_SAMPLE_PAY_PENDING);

    $operation = KaspiOperation::query()->sole();

    expect($operation->state)->toBe(KaspiOperationState::Pending)
        ->and($operation->state_reason)->toBe('Идёт закрытие расчётного месяца')
        ->and(Payment::query()->count())->toBe(0);

    app(CloseBillingMonth::class)->run($organization, $claimed);

    $next = BillingPeriod::query()->forOrganization($organization)->forCode('202612')->sole();
    $payment = Payment::query()->sole();

    expect($billingPeriod->refresh()->status)->toBe(BillingPeriodStatus::Closed)
        ->and($payment->billing_period_id)->toBe($next->id)
        ->and($payment->amount)->toBe('2750.00')
        ->and($operation->refresh()->state)->toBe(KaspiOperationState::Posted)
        ->and($operation->retries_count)->toBe(0);

    // Kaspi ещё помнит «4»; следующий повтор получает «0».
    expect($operation->last_result)->toBe(4)
        ->and(kaspiGet($organization, kaspiPayQuery('48213350311', sum: '2750.00'))->json('result'))->toBe(0);
});

test('закрытие с ошибками данных и технический сбой — оплата проводится в тот же месяц', function (bool $technicalFailure): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();
    kaspiClient($organization, $utilityService, ['fixed_amount' => $technicalFailure ? 1000 : 0]);

    $claimed = app(CloseBillingMonth::class)->claim($organization, $billingPeriod);

    kaspiGet($organization, kaspiPayQuery('48213350311', sum: '2750.00'));

    if ($technicalFailure) {
        BillingPeriod::creating(function (): void {
            throw new RuntimeException('Сбой в тесте.');
        });

        expect(fn () => app(CloseBillingMonth::class)->run($organization, $claimed))->toThrow(RuntimeException::class);
    } else {
        app(CloseBillingMonth::class)->run($organization, $claimed);
    }

    expect($billingPeriod->refresh()->status)->toBe(BillingPeriodStatus::Failed)
        ->and(Payment::query()->sole()->billing_period_id)->toBe($billingPeriod->id)
        ->and(KaspiOperation::query()->sole()->state)->toBe(KaspiOperationState::Posted);

    BillingPeriod::flushEventListeners();
})->with([
    'ошибки данных' => [false],
    'технический сбой' => [true],
]);

test('потерянное задание: планировщик проводит операции «в обработке» старше минуты, открыв следующий месяц', function (): void {
    Queue::fake();
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    $billingPeriod->forceFill(['status' => BillingPeriodStatus::Processing])->save();
    kaspiGet($organization, kaspiPayQuery('48213350311', sum: '2750.00'));

    // Месяц закрылся, а задание проводки потерялось, и следующий месяц не открыт.
    $billingPeriod->forceFill(['status' => BillingPeriodStatus::Closed, 'closed_at' => now()])->save();

    $this->artisan('kaspi:post-pending')->assertSuccessful();

    expect(Payment::query()->count())->toBe(0);

    $this->travel(2)->minutes();

    $this->artisan('kaspi:post-pending')->assertSuccessful();

    $next = BillingPeriod::query()->forOrganization($organization)->forCode('202612')->sole();

    expect($next->status)->toBe(BillingPeriodStatus::Open)
        ->and(Payment::query()->sole()->billing_period_id)->toBe($next->id)
        ->and(KaspiOperation::query()->sole()->state)->toBe(KaspiOperationState::Posted);
});

test('планировщик зарегистрирован каждую минуту', function (): void {
    $this->artisan('schedule:list')
        ->assertSuccessful()
        ->expectsOutputToContain('kaspi:post-pending');
});

test('принятая операция проводится, даже если абонент стал неактивным или приём выключили', function (): void {
    Queue::fake();
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();
    $client = kaspiClient($organization, $utilityService);

    $billingPeriod->forceFill(['status' => BillingPeriodStatus::Processing])->save();
    kaspiGet($organization, kaspiPayQuery('48213350311', sum: '2750.00'));

    $client->forceFill(['status' => 'inactive'])->save();
    $organization->forceFill(['kaspi_enabled' => false])->save();
    $billingPeriod->forceFill(['status' => BillingPeriodStatus::Failed])->save();

    (new PostKaspiOperationJob(KaspiOperation::query()->sole()->id))->handle(app(PostKaspiOperation::class));

    expect(Payment::query()->sole()->client_id)->toBe($client->id)
        ->and(KaspiOperation::query()->sole()->state)->toBe(KaspiOperationState::Posted)
        // Повтор уже проведённой — сохранённый ответ, хотя приём выключен.
        ->and(kaspiGet($organization, kaspiPayQuery('48213350311', sum: '2750.00'))->json('result'))->toBe(0);
});

test('не дождались блокировки месяца — операция сохранена, Kaspi получает 4, задание проводки поставлено', function (): void {
    Queue::fake();
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    $lock = BillingPeriodOperationLock::acquire($organization, $billingPeriod);

    try {
        $startedAt = microtime(true);
        $body = kaspiGet($organization, kaspiPayQuery('48213350311', sum: '2750.00'))->getContent();
        $elapsed = microtime(true) - $startedAt;
    } finally {
        $lock->release();
    }

    $operation = KaspiOperation::query()->sole();

    expect($body)->toBe(KASPI_SAMPLE_PAY_PENDING)
        ->and($elapsed)->toBeLessThan(3.0)
        ->and(Payment::query()->count())->toBe(0)
        ->and($operation->state)->toBe(KaspiOperationState::Pending)
        ->and($operation->state_reason)->toBe('Не дождались блокировки расчётного месяца, проведётся автоматически');

    Queue::assertPushed(PostKaspiOperationJob::class);

    (new PostKaspiOperationJob($operation->id))->handle(app(PostKaspiOperation::class));

    expect($operation->refresh()->state)->toBe(KaspiOperationState::Posted);
});

test('задание проводки при закрывающемся месяце откладывает себя с паузой', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    $client = kaspiClient($organization, $utilityService);

    $operation = KaspiOperation::factory()->forClient($client)->pending(2750)->create(['txn_id' => '48213350311']);
    BillingPeriod::query()->forOrganization($organization)->update(['status' => BillingPeriodStatus::Processing->value]);

    $job = (new PostKaspiOperationJob($operation->id))->withFakeQueueInteractions();
    $job->handle(app(PostKaspiOperation::class));

    $job->assertReleased(delay: 10);

    expect($job->tries)->toBe(6)
        ->and($job->backoff())->toBe([10, 30, 60, 120, 300])
        ->and($operation->refresh()->state_reason)->toBe('Идёт закрытие расчётного месяца');
});

// --- Раунд ревью 1 -----------------------------------------------------------------

test('txn_date: реальная дата до 2000 года принимается, непредставимая и несуществующая — нет', function (string $txnDate, int $result): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    expect(kaspiGet($organization, kaspiPayQuery('48213377701', txnDate: $txnDate))->json('result'))->toBe($result);
})->with([
    'конец 1999 года' => ['19991231235959', 0],
    'год 0999' => ['09991231235959', 5],
    '29 февраля не високосного' => ['19990229120000', 5],
]);

test('check не отвечает 0 или 1, если операция не записалась: Kaspi получает 5', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    KaspiOperation::creating(function (): void {
        throw new RuntimeException('База недоступна в тесте.');
    });

    expect(kaspiGet($organization, kaspiCheckQuery('48213377195'))->getContent())->toBe(KASPI_SAMPLE_ERROR);

    KaspiOperation::flushEventListeners();
    Model::clearBootedModels();

    expect(KaspiOperation::query()->count())->toBe(0);
});

test('сбой базы при поиске организации — ответ 5 в формате протокола', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    Organization::retrieved(function (): void {
        throw new RuntimeException('База недоступна в тесте.');
    });

    $response = kaspiGet($organization, kaspiCheckQuery('48213377195'))->assertOk();

    Organization::flushEventListeners();
    Model::clearBootedModels();

    expect($response->getContent())->toBe(KASPI_SAMPLE_ERROR);
});

test('любая допустимая сумма проводится: итоги месяца и сальдо считаются точно и помещаются, в том числе для уже принятой «4»', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();
    $client = kaspiClient($organization, $utilityService, ['billing_type' => 'meter']);
    kaspiMeterDebt($organization, $utilityService, $billingPeriod, $client, 10);

    // Two payments at the top of the range of §2 already exceed 14 digits together.
    expect(kaspiGet($organization, kaspiPayQuery('48213377701', sum: '999999999999.99'))->json('result'))->toBe(0)
        ->and(kaspiGet($organization, kaspiPayQuery('48213377702', sum: '999999999999.99'))->json('result'))->toBe(0);

    // The third one comes while the month closes and is posted afterwards.
    $claimed = app(CloseBillingMonth::class)->claim($organization, $billingPeriod);

    expect(kaspiGet($organization, kaspiPayQuery('48213377703', sum: '999999999999.97'))->json('result'))->toBe(4);

    app(CloseBillingMonth::class)->run($organization, $claimed);

    $receipt = Receipt::query()->whereBelongsTo($client)->whereBelongsTo($billingPeriod)->sole();
    $accrual = Accrual::query()->whereBelongsTo($client)->whereBelongsTo($billingPeriod)->sole();
    $next = BillingPeriod::query()->forOrganization($organization)->forCode('202612')->sole();

    expect($receipt->paid_amount)->toBe('1999999999999.98')
        // 1 000 accrued by the meter reading, to the tiyn.
        ->and($receipt->closing_balance)->toBe('-1999999998999.98')
        ->and($billingPeriod->refresh()->status)->toBe(BillingPeriodStatus::Closed)
        ->and($accrual->paid_amount)->toBe('1999999999999.98')
        ->and($accrual->closing_balance)->toBe('-1999999998999.98')
        ->and(KaspiOperation::query()->where('state', KaspiOperationState::Posted->value)->count())->toBe(3)
        ->and(Payment::query()->where('external_payment_id', '48213377703')->sole()->billing_period_id)->toBe($next->id);

    // The next month starts from the carried balance, also beyond 14 digits.
    expect(kaspiGet($organization, kaspiCheckQuery('48213377704'))->json('fields.field2.#text'))->toBe('0.00');

    kaspiGet($organization, kaspiPayQuery('48213377705', sum: '999999999999.99'));

    expect(Receipt::closingBalance('-1999999998999.98', '0', '1999999999999.96', '0'))->toBe('-3999999998999.94');
});

test('итоги денег считаются без потери тиынов там, где float уже ошибается', function (): void {
    expect(Money::sum('9999999999999999.99', '-0.01'))->toBe('9999999999999999.98')
        ->and(Receipt::closingBalance('0.10', '0.20', '0.30', '0'))->toBe('0.00')
        ->and(Money::sum('1.005', '-1.005'))->toBe('0.00');
});

test('сбой освобождения блокировки месяца после коммита не превращает проведённую оплату в «4»', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    Cache::extend('kaspi-failing-release', fn () => Cache::repository(new class extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null)
        {
            return new class($this, $name, $seconds, $owner) extends ArrayLock
            {
                public function release()
                {
                    throw new RuntimeException('Redis недоступен в тесте.');
                }
            };
        }
    }));
    config(['cache.stores.kaspi-failing-release' => ['driver' => 'kaspi-failing-release'], 'cache.default' => 'kaspi-failing-release']);
    Cache::forgetDriver('kaspi-failing-release');

    $body = kaspiGet($organization, kaspiPayQuery())->getContent();

    expect($body)->toBe(kaspiPostedSample(Payment::query()->sole()))
        ->and(KaspiOperation::query()->sole()->state)->toBe(KaspiOperationState::Posted);
});

test('последний запрос и ошибки за сутки учитывают повторы и отклонённые pay, но не создают операций', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService, ['status' => 'inactive']);

    expect(KaspiActivity::lastRequestAt($organization))->toBeNull();

    kaspiGet($organization, kaspiPayQuery('48213377801'));
    kaspiGet($organization, 'command=pay&txn_id=48213377802&account=1');

    expect(KaspiOperation::query()->count())->toBe(0)
        ->and(KaspiActivity::lastRequestAt($organization)?->format('Y-m-d H:i:s'))->toBe('2026-11-24 10:15:20')
        ->and(KaspiActivity::errorsInLastDay($organization))->toBe(2);

    $this->travel(3)->hours();
    kaspiClient($organization, $utilityService, ['account_number' => '100600', 'iin' => '900101300009', 'phone' => '+77010000010']);
    kaspiGet($organization, kaspiCheckQuery('48213377803', '100600'));
    $this->travel(1)->hours();
    kaspiGet($organization, kaspiCheckQuery('48213377803', '100600'));

    expect(KaspiActivity::lastRequestAt($organization)?->format('Y-m-d H:i:s'))->toBe('2026-11-24 14:15:20')
        ->and(KaspiActivity::errorsInLastDay($organization))->toBe(2);

    $this->travel(1)->days();

    expect(KaspiActivity::errorsInLastDay($organization))->toBe(0);
});

test('ошибки за сутки — ровно 24 часа по времени получения, по обе стороны границы внутри одного часа', function (): void {
    ['organization' => $organization] = kaspiOrganization();

    // Now is 10:15:20: 10:15:19 yesterday is out, 10:15:21 yesterday is in.
    foreach (['2026-11-23 10:05:00', '2026-11-23 10:15:19', '2026-11-23 10:15:21', '2026-11-23 10:59:00', '2026-11-24 09:00:00'] as $moment) {
        KaspiActivity::record($organization, 5, CarbonImmutable::parse($moment), KaspiDeadline::in(1));
    }

    KaspiActivity::record($organization, 0, CarbonImmutable::parse('2026-11-24 10:00:00'), KaspiDeadline::in(1));

    expect(KaspiActivity::errorsInLastDay($organization))->toBe(3)
        ->and(KaspiActivity::lastRequestAt($organization)?->format('Y-m-d H:i:s'))->toBe('2026-11-24 10:00:00');
});

test('время запроса берётся при получении, а не после обработки', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    // Processing takes a minute: the request and its operation keep the moment it came in.
    Payment::creating(function (): void {
        test()->travel(1)->minutes();
    });

    kaspiGet($organization, kaspiPayQuery('48213377811'));

    expect(KaspiActivity::lastRequestAt($organization)?->format('H:i:s'))->toBe('10:15:20')
        ->and(KaspiOperation::query()->sole()->received_at->format('H:i:s'))->toBe('10:15:20');
});

test('журнал запросов хранит 30 дней, но последний запрос организации — всегда', function (): void {
    ['organization' => $organization] = kaspiOrganization();
    ['organization' => $quiet] = kaspiOrganization();

    KaspiActivity::record($organization, 0, CarbonImmutable::parse('2026-09-01 10:00:00'), KaspiDeadline::in(1));
    KaspiActivity::record($organization, 0, CarbonImmutable::parse('2026-11-20 10:00:00'), KaspiDeadline::in(1));
    KaspiActivity::record($quiet, 0, CarbonImmutable::parse('2026-08-01 10:00:00'), KaspiDeadline::in(1));
    KaspiActivity::record($quiet, 5, CarbonImmutable::parse('2026-08-02 10:00:00'), KaspiDeadline::in(1));

    expect(KaspiActivity::prune())->toBe(2)
        ->and(KaspiActivity::lastRequestAt($organization)?->format('Y-m-d'))->toBe('2026-11-20')
        ->and(KaspiActivity::lastRequestAt($quiet)?->format('Y-m-d'))->toBe('2026-08-02');
});

test('организацию с операциями Kaspi удалить нельзя, даже если в журнале только check без абонента', function (): void {
    ['organization' => $organization] = kaspiOrganization();
    kaspiGet($organization, kaspiCheckQuery('48213377901', '999990'));

    expect(KaspiOperation::query()->sole()->client_id)->toBeNull();

    expect(fn () => DB::transaction(fn () => $organization->delete()))->toThrow(QueryException::class);

    expect(Organization::query()->whereKey($organization->id)->exists())->toBeTrue()
        ->and(KaspiOperation::query()->count())->toBe(1);
});

test('подбор идёт по очереди попыток: после лимита застрявших операций доступная проводится в следующем проходе', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    $stuck = kaspiClient($organization, $utilityService);
    $free = kaspiClient($organization, $utilityService, ['account_number' => '100418', 'iin' => '900101300011', 'phone' => '+77010000012']);
    config(['kaspi.posting.pickup_per_organization' => 2]);

    $received = now()->subMinutes(5);
    KaspiOperation::factory()->forClient($stuck)->pending(100)->create(['txn_id' => '48213378001', 'received_at' => $received]);
    KaspiOperation::factory()->forClient($stuck)->pending(100)->create(['txn_id' => '48213378002', 'received_at' => $received]);
    $available = KaspiOperation::factory()->forClient($free)->pending(300)->create(['txn_id' => '48213378003', 'received_at' => $received]);

    // Payments of the first subscriber always fail.
    Payment::creating(function (Payment $payment) use ($stuck): void {
        if ($payment->client_id === $stuck->id) {
            throw new RuntimeException('Проводка не удалась в тесте.');
        }
    });

    $this->artisan('kaspi:post-pending')->assertSuccessful();

    expect($available->refresh()->state)->toBe(KaspiOperationState::Pending);

    $this->travel(1)->minutes();
    $this->artisan('kaspi:post-pending')->assertSuccessful();

    expect($available->refresh()->state)->toBe(KaspiOperationState::Posted)
        ->and(KaspiOperation::query()->where('client_id', $stuck->id)->whereNotNull('posting_attempted_at')->count())->toBe(2);
});

test('медленная организация не задерживает следующую: у каждой свой бюджет времени в проходе', function (): void {
    ['organization' => $slow, 'utilityService' => $slowService] = kaspiOrganization();
    ['organization' => $next, 'utilityService' => $nextService] = kaspiOrganization();
    $slowClient = kaspiClient($slow, $slowService);
    $nextClient = kaspiClient($next, $nextService, ['iin' => '900101300012', 'phone' => '+77010000013']);
    config(['kaspi.posting.pickup_organization_seconds' => 0.5]);

    $received = now()->subMinutes(5);

    foreach (range(1, 5) as $number) {
        KaspiOperation::factory()->forClient($slowClient)->pending(100)->create(['txn_id' => '4821337810'.$number, 'received_at' => $received]);
    }

    $waiting = KaspiOperation::factory()->forClient($nextClient)->pending(300)->create(['txn_id' => '48213378201', 'received_at' => $received]);

    // Each posting of the slow organization takes 0.3 s and fails.
    Payment::creating(function (Payment $payment) use ($slow): void {
        if ($payment->organization_id === $slow->id) {
            usleep(300_000);

            throw new RuntimeException('Медленная проводка в тесте.');
        }
    });

    $startedAt = microtime(true);
    $this->artisan('kaspi:post-pending')->assertSuccessful();

    expect($waiting->refresh()->state)->toBe(KaspiOperationState::Posted)
        ->and(KaspiOperation::query()->where('organization_id', $slow->id)->whereNotNull('posting_attempted_at')->count())->toBeLessThan(5)
        ->and(microtime(true) - $startedAt)->toBeLessThan(3.0);
});

test('подбор обходит организации по кругу: проход кончился на первой — следующий проводит вторую и третью', function (): void {
    $received = now()->subMinutes(5);
    $organizations = [];
    $operations = [];

    foreach (range(1, 3) as $number) {
        ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
        $client = kaspiClient($organization, $utilityService, ['iin' => '90010130002'.$number, 'phone' => '+7701000003'.$number]);

        $organizations[$number] = $organization;
        $operations[$number] = collect(range(1, 2))->map(fn (int $index) => KaspiOperation::factory()->forClient($client)->pending(100)
            ->create(['txn_id' => "482133783{$number}{$index}", 'received_at' => $received]));
    }

    config(['kaspi.posting.pickup_seconds' => 0.5]);

    // Every posting of the first organization takes longer than the whole round,
    // and it always has an operation that was never tried.
    Payment::created(function (Payment $payment) use ($organizations): void {
        if ($payment->organization_id === $organizations[1]->id) {
            usleep(600_000);
        }
    });

    $pendingOf = fn (int $number): int => KaspiOperation::query()
        ->where('organization_id', $organizations[$number]->id)
        ->where('state', KaspiOperationState::Pending->value)
        ->count();

    $this->artisan('kaspi:post-pending')->assertSuccessful();

    expect($pendingOf(1))->toBe(1)
        ->and($pendingOf(2))->toBe(2)
        ->and($pendingOf(3))->toBe(2);

    $this->artisan('kaspi:post-pending')->assertSuccessful();

    expect($pendingOf(2))->toBe(0)
        ->and($pendingOf(3))->toBe(0)
        ->and(Cache::get(PostPendingKaspiOperations::LAST_ORGANIZATION_KEY))->toBeIn([$organizations[1]->id, $organizations[3]->id]);
});

test('ссылки сайта: через доверенный HTTPS-прокси — https, прямой HTTP-вход — http, даже при APP_URL на https', function (): void {
    config(['app.url' => 'https://pay.example.test', 'trustedproxy.proxies' => [KASPI_TEST_PROXY]]);
    // Boot as production would with this APP_URL: nothing forces the scheme.
    (new AppServiceProvider(app()))->boot();
    Route::get('/scheme-probe', fn () => url('/x'));

    $viaProxy = $this->call('GET', 'http://pay.example.test/scheme-probe', server: [
        'REMOTE_ADDR' => KASPI_TEST_PROXY,
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_PORT' => '443',
    ]);
    $direct = $this->call('GET', 'http://pay.example.test:8500/scheme-probe', server: [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);

    expect($viaProxy->getContent())->toBe('https://pay.example.test/x')
        ->and($direct->getContent())->toBe('http://pay.example.test:8500/x');
});

test('адрес для Kaspi — канонический адрес платформы из APP_URL, как бы ни открыли профиль', function (): void {
    ['organization' => $organization] = kaspiOrganization();
    config(['app.url' => 'https://pay.example.test/']);

    expect($organization->kaspiAddress())->toBe('https://pay.example.test/kaspi/'.$organization->kaspi_uid);
});
