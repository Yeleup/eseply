<?php

use App\Actions\CloseBillingMonth;
use App\BalanceAdjustmentType;
use App\BillingPeriodStatus;
use App\ClientType;
use App\Jobs\PostKaspiOperationJob;
use App\Kaspi\KaspiCommand;
use App\Kaspi\KaspiDeadline;
use App\Kaspi\KaspiOperationState;
use App\Kaspi\PostKaspiOperation;
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
use App\Support\BillingPeriodOperationLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const KASPI_TEST_IP = '194.187.247.152';

// Samples of the task, byte for byte.
const KASPI_SAMPLE_CHECK_FOUND = '{"txn_id":"48213377188","result":0,"sum":4250.00,"comment":"","fields":{"field1":{"@name":"Абонент","#text":"Иванов И. И."},"field2":{"@name":"Долг на 24.11.2026","#text":"4250.00"}}}';
const KASPI_SAMPLE_CHECK_NOT_FOUND = '{"txn_id":"48213301922","result":1,"comment":"Абонент не найден"}';
const KASPI_SAMPLE_ERROR = '{"txn_id":"48213377195","result":5,"comment":"Приём оплат недоступен"}';
const KASPI_SAMPLE_PAY_POSTED = '{"txn_id":"48213377190","prv_txn_id":"2016","result":0,"sum":4250.00,"comment":"OK"}';
const KASPI_SAMPLE_PAY_PENDING = '{"txn_id":"48213350311","result":4,"comment":"Платёж в обработке"}';

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(10, 15, 20));
    config(['kaspi.lock_wait_seconds' => 1, 'kaspi.posting.job_lock_wait_seconds' => 1]);
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
 * @param  array<string, string>  $headers
 */
function kaspiGet(Organization|string $organization, string $query, string $ip = KASPI_TEST_IP, array $headers = [], string $method = 'GET'): TestResponse
{
    $uid = $organization instanceof Organization ? $organization->kaspi_uid : $organization;

    $server = ['REMOTE_ADDR' => $ip];

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

test('HEAD и POST отклоняются и ничего не создают', function (string $method): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    kaspiGet($organization, kaspiPayQuery(), method: $method)->assertStatus(405);

    kaspiAssertNothingCreated();
})->with(['HEAD', 'POST', 'PUT', 'DELETE']);

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

test('запрос не с адреса Kaspi даёт 5, в том числе с подложенным X-Forwarded-For', function (string $remoteAddress, array $headers): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    expect(kaspiGet($organization, kaspiPayQuery('48213377195'), $remoteAddress, $headers)->assertOk()->getContent())
        ->toBe(KASPI_SAMPLE_ERROR);

    kaspiAssertNothingCreated();
})->with([
    'чужой адрес' => ['203.0.113.9', []],
    'подложенный X-Forwarded-For напрямую' => ['203.0.113.9', ['X-Forwarded-For' => KASPI_TEST_IP]],
    'подложенный X-Forwarded-For через прокси' => ['172.18.0.1', ['X-Forwarded-For' => KASPI_TEST_IP.', 203.0.113.9']],
    'подложенный X-Real-IP' => ['203.0.113.9', ['X-Real-IP' => KASPI_TEST_IP]],
]);

test('свой прокси передаёт реальный адрес Kaspi, и запрос принимается', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = kaspiOrganization();
    kaspiClient($organization, $utilityService);

    $response = kaspiGet($organization, kaspiCheckQuery(), '172.18.0.1', [
        'X-Forwarded-For' => KASPI_TEST_IP,
        'X-Forwarded-Proto' => 'https',
    ]);

    expect($response->json('result'))->toBe(0);
});

test('доверенные прокси — только частные сети из конфига, а не любой адрес', function (): void {
    expect(config('trustedproxy.proxies'))->not->toContain('*')
        ->and(explode(',', (string) config('trustedproxy.proxies')))->toContain('127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16')
        ->and(config('kaspi.allowed_ips'))->toBe([KASPI_TEST_IP]);
});

// --- check -----------------------------------------------------------------------

test('check: найден, не найден, неактивный и абонент другой организации с тем же номером', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'billingPeriod' => $billingPeriod] = kaspiOrganization();
    $client = kaspiClient($organization, $utilityService);
    kaspiOpeningBalance($client, $billingPeriod, 4250);
    kaspiClient($organization, $utilityService, ['account_number' => '100523', 'status' => 'inactive', 'iin' => '900101300002', 'phone' => '+77010000003']);

    ['organization' => $other, 'utilityService' => $otherService] = kaspiOrganization();
    kaspiClient($other, $otherService, ['account_number' => '100777', 'name' => 'Ким Ольга', 'iin' => '900101300003', 'phone' => '+77010000004']);

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
    'физлицо без отчества' => [ClientType::Individual, 'Ким Ольга', 'Ким О.'],
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
        ->and($operation->state_reason)->toBe('Не дождались блокировки, проведётся автоматически');

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
