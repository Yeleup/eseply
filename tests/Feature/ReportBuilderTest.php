<?php

use App\BalanceAdjustmentType;
use App\ClientType;
use App\Filament\Pages\Reports\BuildReport;
use App\Filament\Pages\Reports\ListReports;
use App\Models\Accrual;
use App\Models\BalanceAdjustment;
use App\Models\BillingPeriod;
use App\Models\City;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Region;
use App\Models\Street;
use App\Models\User;
use App\Models\UtilityService;
use App\OrganizationMemberRole;
use App\PaymentMethod;
use App\Reports\ReportSummaryGroup;
use App\Reports\ReportSummaryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Reader;

uses(RefreshDatabase::class);

function reportBuilderActingAs(User $user, Organization $organization): User
{
    Livewire::actingAs($user);
    test()->actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

function reportBuilderOperator(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => OrganizationMemberRole::Operator->value]);

    return reportBuilderActingAs($user, $organization);
}

function reportBuilderController(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => OrganizationMemberRole::Controller->value]);

    return reportBuilderActingAs($user, $organization);
}

function reportBuilderZoneController(Organization $organization, string $name, ?Region $region = null, ?Street $street = null): User
{
    $controller = User::factory()->create(['name' => $name]);
    $organization->users()->attach($controller, ['role' => OrganizationMemberRole::Controller->value]);

    if ($region instanceof Region) {
        DB::table('organization_user_regions')->insert([
            'organization_id' => $organization->id,
            'user_id' => $controller->id,
            'region_id' => $region->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    if ($street instanceof Street) {
        DB::table('organization_user_streets')->insert([
            'organization_id' => $organization->id,
            'user_id' => $controller->id,
            'street_id' => $street->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $controller;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function reportBuilderClient(Organization $organization, UtilityService $utilityService, array $attributes): Client
{
    return Client::factory()->for($organization)->for($utilityService)->create([
        'status' => 'active',
        'billing_type' => 'fixed',
        'fixed_amount' => 1000,
        'client_type' => ClientType::Individual->value,
        ...$attributes,
    ]);
}

/**
 * Two cities, three regions, four streets and four clients of one organization.
 *
 * Мая (202605) closed with accruals, June (202606) open with payments:
 *
 * - 100001 Иванов (Алматы, Алмалинский, Абая): accrued 1000, paid 800, opening 100, payments 1000 cash + 500 Kaspi;
 * - 100002 Петров (Алматы, Алмалинский, Гоголя): accrued 0, paid 0 — the collection has no base; payment 2000 cash;
 * - 100003 ТОО «Ромашка» (Алматы, Бостандыкский, Сатпаева, ТОО): accrued 2000, paid 2500, opening 500; payment 3000 Kaspi;
 * - 100004 Сидоров (Астана, Есильский, Кабанбай батыра): accrued 1000, paid 500; payment 4000 cash.
 *
 * Controllers: «Контроллер района» owns Алмалинский, «Контроллер улицы» owns Сатпаева.
 *
 * @return array<string, mixed>
 */
function reportBuilderFixture(): array
{
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    $almaty = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $astana = City::factory()->for($organization)->create(['name' => 'Астана']);
    $almalinsky = Region::factory()->for($organization)->for($almaty)->create(['name' => 'Алмалинский']);
    $bostandyk = Region::factory()->for($organization)->for($almaty)->create(['name' => 'Бостандыкский']);
    $esil = Region::factory()->for($organization)->for($astana)->create(['name' => 'Есильский']);
    $abay = Street::factory()->for($almalinsky)->create(['name' => 'Абая']);
    $gogol = Street::factory()->for($almalinsky)->create(['name' => 'Гоголя']);
    $satpaev = Street::factory()->for($bostandyk)->create(['name' => 'Сатпаева']);
    $kabanbay = Street::factory()->for($esil)->create(['name' => 'Кабанбай батыра']);

    $ivanov = reportBuilderClient($organization, $utilityService, [
        'account_number' => '100001',
        'name' => 'Иванов Иван',
        'region_id' => $almalinsky->id,
        'street_id' => $abay->id,
        'house' => '10',
        'apartment' => '5',
    ]);
    $petrov = reportBuilderClient($organization, $utilityService, [
        'account_number' => '100002',
        'name' => 'Петров Пётр',
        'region_id' => $almalinsky->id,
        'street_id' => $gogol->id,
        'house' => '3',
    ]);
    $romashka = reportBuilderClient($organization, $utilityService, [
        'account_number' => '100003',
        'name' => 'ТОО «Ромашка»',
        'client_type' => ClientType::Llp->value,
        'region_id' => $bostandyk->id,
        'street_id' => $satpaev->id,
        'house' => '25',
    ]);
    $sidorov = reportBuilderClient($organization, $utilityService, [
        'account_number' => '100004',
        'name' => 'Сидоров Сидор',
        'region_id' => $esil->id,
        'street_id' => $kabanbay->id,
        'house' => '7',
    ]);

    $may = closedBillingPeriodFor($organization, '202605');
    $june = billingPeriodFor($organization, '202606');

    $regionController = reportBuilderZoneController($organization, 'Контроллер района', region: $almalinsky);
    $streetController = reportBuilderZoneController($organization, 'Контроллер улицы', street: $satpaev);

    $cashier = User::factory()->create(['name' => 'Кассир Айгуль']);
    $organization->users()->attach($cashier, ['role' => OrganizationMemberRole::Operator->value]);

    $payment = fn (Client $client, PaymentMethod $method, int $amount, string $paidAt, ?User $receivedBy = null): Payment => Payment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
            'method' => $method,
            'amount' => $amount,
            'paid_at' => $paidAt,
            'received_by_user_id' => $receivedBy?->id,
        ]);

    $ivanovCash = $payment($ivanov, PaymentMethod::Cash, 1000, '2026-06-05', $cashier);
    $ivanovKaspi = $payment($ivanov, PaymentMethod::Kaspi, 500, '2026-06-10');
    $petrovCash = $payment($petrov, PaymentMethod::Cash, 2000, '2026-06-12', $cashier);
    $romashkaKaspi = $payment($romashka, PaymentMethod::Kaspi, 3000, '2026-06-20');
    $sidorovCash = $payment($sidorov, PaymentMethod::Cash, 4000, '2026-06-25', $cashier);

    $accrual = fn (Client $client, float $opening, float $amount, float $paid): Accrual => Accrual::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'utility_service_id' => $utilityService->id,
            'period' => '202605',
            'account_number' => $client->account_number,
            'client_name' => $client->name,
            'opening_balance' => $opening,
            'amount' => $amount,
            'paid_amount' => $paid,
            'adjustment_amount' => 0,
            'closing_balance' => $opening + $amount - $paid,
            'closed_at' => '2026-06-01 09:00:00',
        ]);

    $ivanovAccrual = $accrual($ivanov, 100, 1000, 800);
    $petrovAccrual = $accrual($petrov, 0, 0, 0);
    $romashkaAccrual = $accrual($romashka, 500, 2000, 2500);
    $sidorovAccrual = $accrual($sidorov, 0, 1000, 500);

    return compact(
        'organization', 'utilityService',
        'almaty', 'astana', 'almalinsky', 'bostandyk', 'esil', 'abay', 'gogol', 'satpaev', 'kabanbay',
        'ivanov', 'petrov', 'romashka', 'sidorov',
        'may', 'june', 'regionController', 'streetController', 'cashier',
        'ivanovCash', 'ivanovKaspi', 'petrovCash', 'romashkaKaspi', 'sidorovCash',
        'ivanovAccrual', 'petrovAccrual', 'romashkaAccrual', 'sidorovAccrual',
    );
}

/**
 * A client, a payment and an accrual of another organization, created before the
 * tenant is set: the Filament tenant overrides the organization of a factory.
 *
 * @return array{organization: Organization, client: Client, payment: Payment, accrual: Accrual, may: BillingPeriod, june: BillingPeriod}
 */
function reportBuilderForeignFixture(): array
{
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = reportBuilderClient($organization, $utilityService, [
        'account_number' => 'FOREIGN-1',
        'name' => 'Чужой абонент',
    ]);

    $may = closedBillingPeriodFor($organization, '202605');
    $june = billingPeriodFor($organization, '202606');

    $payment = Payment::factory()->for($organization)->for($client)->create([
        'period' => '202606',
        'method' => PaymentMethod::Cash,
        'amount' => 99999,
        'paid_at' => '2026-06-15',
    ]);

    $accrual = Accrual::factory()->for($organization)->for($client)->create([
        'utility_service_id' => $utilityService->id,
        'period' => '202605',
        'opening_balance' => 0,
        'amount' => 99999,
        'paid_amount' => 0,
        'adjustment_amount' => 0,
        'closing_balance' => 99999,
    ]);

    return compact('organization', 'client', 'payment', 'accrual', 'may', 'june');
}

/**
 * Summary records of the page in display order, the «Итого» row last.
 *
 * @return list<array<string, mixed>>
 */
function reportBuilderSummary(Testable $page): array
{
    return array_values(array_map(
        fn (array $record): array => collect($record)->except('__key')->all(),
        $page->instance()->getTableRecords()->all(),
    ));
}

/**
 * @return array<string, array<string, mixed>>
 */
function reportBuilderSummaryByLabel(Testable $page): array
{
    return collect(reportBuilderSummary($page))->keyBy('group_label')->all();
}

/**
 * @return list<string>
 */
function reportBuilderColumnNames(Testable $page): array
{
    return array_keys($page->instance()->getTable()->getColumns());
}

/**
 * @return list<list<mixed>>
 */
function reportBuilderXlsxRows(Testable $download): array
{
    $path = tempnam(sys_get_temp_dir(), 'report-builder-');

    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary XLSX file for assertions.');
    }

    $content = base64_decode((string) data_get($download->effects['download'], 'content'), true);

    if ($content === false || file_put_contents($path, $content) === false) {
        throw new RuntimeException('Unable to write downloaded XLSX content for assertions.');
    }

    $reader = new Reader;

    try {
        $reader->open($path);

        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn (Cell $cell): mixed => $cell->getValue(), $row->getCells());
            }

            break;
        }

        return $rows;
    } finally {
        $reader->close();
        @unlink($path);
    }
}

// --- Доступ -----------------------------------------------------------------

test('оператор открывает конструктор отчётов из навигации и со страницы «Отчёты»', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    expect(BuildReport::canAccess())->toBeTrue();

    $this->get(BuildReport::getUrl(tenant: $fixture['organization']))
        ->assertSuccessful()
        ->assertSee('Конструктор отчётов')
        ->assertSee('1. Источник данных')
        ->assertSee('2. Колонки')
        ->assertSee('3. Группировка')
        ->assertSee('Скачать XLSX')
        ->assertSee('Сбросить');

    $this->get(ListReports::getUrl(tenant: $fixture['organization']))
        ->assertSuccessful()
        ->assertSee(BuildReport::getUrl(tenant: $fixture['organization']), false)
        ->assertSee('Конструктор отчётов');

    Livewire::test(BuildReport::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$fixture['ivanovCash'], $fixture['petrovCash'], $fixture['sidorovCash']])
        ->assertDontSee('@js', false)
        ->assertSeeHtml("wire:click=\"selectSource('accruals')\"")
        ->assertSeeHtml("wire:click=\"toggleField('received_by')\"")
        ->assertSeeHtml("wire:click=\"toggleMetric('average')\"")
        ->assertSeeHtml('wire:change="selectDimension($event.target.value)"');
});

test('контроллер получает 403 и не видит конструктор в навигации', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderController($fixture['organization']);

    expect(BuildReport::canAccess())->toBeFalse();

    $this->get(BuildReport::getUrl(tenant: $fixture['organization']))->assertForbidden();

    $this->get(ListReports::getUrl(tenant: $fixture['organization']))
        ->assertSuccessful()
        ->assertDontSee('Конструктор отчётов')
        ->assertDontSee(BuildReport::getUrl(tenant: $fixture['organization']), false);

    Livewire::test(BuildReport::class)->assertForbidden();
});

// --- Тенант и подмена -------------------------------------------------------

test('данные другой организации не попадают ни в детальный режим, ни в сводку, ни в XLSX', function (): void {
    $foreign = reportBuilderForeignFixture();
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $detail = Livewire::test(BuildReport::class)
        ->assertCanSeeTableRecords([$fixture['ivanovCash'], $fixture['sidorovCash']])
        ->assertCanNotSeeTableRecords([$foreign['payment']]);

    $rows = reportBuilderXlsxRows($detail->callAction('downloadXlsx'));
    expect(collect($rows)->flatten()->contains('FOREIGN-1'))->toBeFalse()
        ->and(collect($rows)->flatten()->contains(99999.0))->toBeFalse();

    $summary = Livewire::withQueryParams(['mode' => 'summary', 'group' => 'city'])->test(BuildReport::class);
    $total = collect(reportBuilderSummary($summary))->last();

    expect($total['group_label'])->toBe('Итого')
        ->and($total['sum'])->toBe(10500.0)
        ->and($total['clients'])->toBe(4);

    $rows = reportBuilderXlsxRows($summary->callAction('downloadXlsx'));
    expect(collect($rows)->last())->toBe(['Итого', 4, 5, 10500, 2100]);

    $accruals = Livewire::withQueryParams(['source' => 'accruals'])->test(BuildReport::class)
        ->assertCanSeeTableRecords([$fixture['ivanov'], $fixture['sidorov']])
        ->assertCanNotSeeTableRecords([$foreign['client']]);

    expect(collect(reportBuilderXlsxRows($accruals->callAction('downloadXlsx')))->flatten()->contains('FOREIGN-1'))->toBeFalse();

    $accrualSummary = Livewire::withQueryParams(['source' => 'accruals', 'mode' => 'summary', 'group' => 'city'])->test(BuildReport::class);

    expect(collect(reportBuilderSummary($accrualSummary))->last()['sum'])->toBe(4000.0);
});

test('чужой расчётный месяц из адреса не открывается: остаётся месяц по умолчанию', function (): void {
    $foreign = reportBuilderForeignFixture();
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::withQueryParams(['period' => (string) $foreign['june']->id])->test(BuildReport::class);

    expect($page->instance()->reportBuild()->billingPeriod?->is($fixture['june']))->toBeTrue()
        ->and($page->get('period'))->toBe('');

    $page->assertCanSeeTableRecords([$fixture['ivanovCash']])
        ->assertCanNotSeeTableRecords([$foreign['payment']]);

    $chosen = Livewire::withQueryParams(['source' => 'accruals', 'period' => (string) $fixture['june']->id])->test(BuildReport::class);

    expect($chosen->instance()->reportBuild()->billingPeriod?->is($fixture['june']))->toBeTrue()
        ->and($chosen->get('period'))->toBe((string) $fixture['june']->id);

    $chosen->call('selectBillingPeriod', (string) $foreign['may']->id);

    expect($chosen->instance()->reportBuild()->billingPeriod?->is($fixture['may']))->toBeTrue()
        ->and($chosen->get('period'))->toBe('');
});

test('неизвестные источник, поля, измерение, показатели и режим из адреса игнорируются', function (array $query): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::withQueryParams($query)->test(BuildReport::class)->assertOk();
    $build = $page->instance()->reportBuild();

    expect($build->source->key())->toBe('payments')
        ->and(array_map(fn ($field): string => $field->key, $build->fields))
        ->toBe(['account_number', 'client_name', 'address', 'paid_at', 'method', 'amount'])
        ->and($build->dimension?->key)->toBe('street')
        ->and(array_map(fn ($metric): string => $metric->key, $build->metrics))
        ->toBe(['clients', 'count', 'sum', 'average'])
        ->and($build->mode)->toBe('detail')
        ->and($build->billingPeriod?->is($fixture['june']))->toBeTrue()
        ->and(reportBuilderColumnNames($page))
        ->toBe(['account_number', 'client_name', 'address', 'paid_at', 'method', 'amount'])
        ->and($page->get('source'))->toBe('')
        ->and($page->get('fields'))->toBe('')
        ->and($page->get('group'))->toBe('')
        ->and($page->get('disabledMetrics'))->toBe('')
        ->and($page->get('mode'))->toBe('detail');

    $page->assertCanSeeTableRecords([$fixture['ivanovCash'], $fixture['sidorovCash']]);
})->with([
    'неизвестные ключи' => [[
        'source' => 'users',
        'fields' => 'password,users.email,1;drop table payments',
        'group' => 'users.password',
        'off' => 'clients) or 1=1 --',
        'mode' => 'raw',
        'period' => '-5',
    ]],
    'массивы вместо строк' => [[
        'source' => ['payments', 'accruals'],
        'fields' => ['amount' => 'x'],
        'group' => ['city'],
        'off' => ['sum'],
        'mode' => ['summary'],
        'period' => ['1'],
    ]],
    'выражения вместо ключей' => [[
        'source' => 'PAYMENTS',
        'fields' => 'payments.amount,sum(amount)',
        'group' => 'report_rows.client_id',
        'off' => 'count(*)',
        'mode' => 'Summary',
        'period' => '1e999',
    ]],
]);

test('подмена состояния Livewire и аргументов действий не меняет каталог', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::test(BuildReport::class)
        ->call('selectSource', 'users')
        ->call('toggleField', 'payments.note')
        ->call('toggleMetric', 'count(*)')
        ->call('selectDimension', 'users.email')
        ->call('selectMode', 'drop')
        ->call('selectBillingPeriod', 'abc');

    $build = $page->instance()->reportBuild();

    expect($build->source->key())->toBe('payments')
        ->and(reportBuilderColumnNames($page))->toBe(['account_number', 'client_name', 'address', 'paid_at', 'method', 'amount'])
        ->and($build->dimension?->key)->toBe('street')
        ->and(count($build->metrics))->toBe(4)
        ->and($build->mode)->toBe('detail');

    $page->set('source', 'evil')
        ->set('fields', 'note,amount')
        ->set('group', 'payments.method')
        ->set('disabledMetrics', 'sum,evil')
        ->set('mode', 'summary');

    $build = $page->instance()->reportBuild();

    expect($build->source->key())->toBe('payments')
        ->and(array_map(fn ($field): string => $field->key, $build->fields))->toBe(['amount'])
        ->and($build->dimension?->key)->toBe('street')
        ->and(array_map(fn ($metric): string => $metric->key, $build->metrics))->toBe(['clients', 'count', 'average'])
        ->and($page->get('source'))->toBe('')
        ->and($page->get('fields'))->toBe('amount')
        ->and($page->get('disabledMetrics'))->toBe('sum');
});

// --- Колонки ----------------------------------------------------------------

test('выбор полей меняет колонки, а порядок колонок — порядок каталога', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::test(BuildReport::class)
        ->assertSee('выбрано 6 из 8')
        ->assertTableColumnDoesNotExist('received_by')
        ->call('toggleField', 'received_by')
        ->call('toggleField', 'billing_period')
        ->call('toggleField', 'address')
        ->assertSee('выбрано 7 из 8')
        ->assertTableColumnExists('received_by')
        ->assertTableColumnDoesNotExist('address');

    expect(reportBuilderColumnNames($page))
        ->toBe(['account_number', 'client_name', 'billing_period', 'paid_at', 'method', 'amount', 'received_by'])
        ->and($page->get('fields'))->toBe('account_number,client_name,billing_period,paid_at,method,amount,received_by');

    $page
        ->assertTableColumnStateSet('received_by', 'Кассир Айгуль', $fixture['ivanovCash'])
        ->assertTableColumnStateSet('received_by', null, $fixture['ivanovKaspi'])
        ->assertTableColumnStateSet('billing_period', '06.2026', $fixture['ivanovCash'])
        ->assertTableColumnStateSet('method', 'Kaspi', $fixture['ivanovKaspi'])
        ->assertTableColumnStateSet('amount', 1000, $fixture['ivanovCash']);

    expect($page->instance()->getTable()->getColumn('received_by')->getPlaceholder())->toBe('—');

    $linked = Livewire::withQueryParams(['fields' => 'received_by,amount,account_number'])->test(BuildReport::class);

    expect(reportBuilderColumnNames($linked))->toBe(['account_number', 'amount', 'received_by']);

    $single = Livewire::withQueryParams(['fields' => 'amount'])->test(BuildReport::class)
        ->call('toggleField', 'amount');

    expect(reportBuilderColumnNames($single))->toBe(['amount']);
});

test('переключение источника сбрасывает колонки, измерение и показатели на значения источника', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::test(BuildReport::class)
        ->call('toggleField', 'received_by')
        ->call('selectDimension', 'method')
        ->call('toggleMetric', 'average')
        ->call('selectSource', 'accruals')
        ->assertSee('выбрано 7 из 10')
        ->assertSee('вычисляемое');

    $build = $page->instance()->reportBuild();

    expect($build->source->key())->toBe('accruals')
        ->and(reportBuilderColumnNames($page))
        ->toBe(['account_number', 'client_name', 'address', 'opening_balance', 'accrued_amount', 'paid_amount', 'closing_balance'])
        ->and($build->dimension?->key)->toBe('street')
        ->and(array_map(fn ($metric): string => $metric->key, $build->metrics))
        ->toBe(['clients', 'count', 'sum', 'average', 'collection_percent'])
        ->and($build->billingPeriod?->is($fixture['may']))->toBeTrue()
        ->and($page->get('source'))->toBe('accruals')
        ->and($page->get('fields'))->toBe('')
        ->and($page->get('group'))->toBe('')
        ->and($page->get('disabledMetrics'))->toBe('');

    $page->assertCanSeeTableRecords([$fixture['ivanov'], $fixture['petrov'], $fixture['romashka'], $fixture['sidorov']], inOrder: true)
        ->assertTableColumnStateSet('address', 'Алмалинский, Абая, д. 10, кв. 5', $fixture['ivanov'])
        ->assertTableColumnStateSet('opening_balance', 100, $fixture['ivanov'])
        ->assertTableColumnStateSet('accrued_amount', 1000, $fixture['ivanov'])
        ->assertTableColumnStateSet('paid_amount', 800, $fixture['ivanov'])
        ->assertTableColumnStateSet('closing_balance', 300, $fixture['ivanov']);
});

test('общий фильтр по адресу переживает переключение источника, а фильтр по дате у источника свой', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::test(BuildReport::class)
        ->filterTable('address', ['city_id' => $fixture['astana']->id])
        ->filterTable('paid_at', ['start_date' => '2026-06-30', 'end_date' => null])
        ->assertCanNotSeeTableRecords([$fixture['sidorovCash']])
        ->call('selectSource', 'accruals')
        ->assertCanSeeTableRecords([$fixture['sidorov']])
        ->assertCanNotSeeTableRecords([$fixture['ivanov'], $fixture['petrov'], $fixture['romashka']]);

    $page->call('selectMode', 'summary')->call('selectDimension', 'region');

    expect(array_column(reportBuilderSummary($page), 'sum', 'group_label'))
        ->toBe(['Есильский' => 1000.0, 'Итого' => 1000.0]);
});

test('дата начисления в незакрытом месяце — дата квитанции месяца', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $receipt = fn (Client $client, float $amount, string $issuedAt) => Receipt::factory()
        ->for($fixture['organization'])
        ->for($client)
        ->create([
            'period' => '202606',
            'account_number' => $client->account_number,
            'client_name' => $client->name,
            'amount' => $amount,
            'issued_at' => $issuedAt,
        ]);

    $receipt($fixture['ivanov'], 1200, '2026-06-15 10:00:00');
    $receipt($fixture['romashka'], 3000, '2026-06-25 10:00:00');

    $page = Livewire::withQueryParams([
        'source' => 'accruals',
        'fields' => 'account_number,accrued_amount,paid_amount,collection_percent',
        'period' => (string) $fixture['june']->id,
    ])->test(BuildReport::class)
        ->assertCanSeeTableRecords([$fixture['ivanov'], $fixture['petrov'], $fixture['romashka'], $fixture['sidorov']])
        ->assertTableColumnStateSet('accrued_amount', 1200.0, $fixture['ivanov'])
        ->assertTableColumnStateSet('collection_percent', 125.0, $fixture['ivanov'])
        ->assertTableColumnStateSet('collection_percent', null, $fixture['petrov'])
        ->filterTable('accrued_at', ['start_date' => '2026-06-10', 'end_date' => '2026-06-20'])
        ->assertCanSeeTableRecords([$fixture['ivanov']])
        ->assertCanNotSeeTableRecords([$fixture['petrov'], $fixture['romashka'], $fixture['sidorov']]);

    $page->call('selectMode', 'summary')->call('selectDimension', 'city');

    expect(array_column(reportBuilderSummary($page), 'collection_percent', 'group_label'))
        ->toBe(['Алматы' => 125.0, 'Итого' => 125.0]);
});

// --- Фильтры ----------------------------------------------------------------

test('фильтры оплат по адресу, контроллерам, дате и способу действуют в детальном режиме и в сводке', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $all = [$fixture['ivanovCash'], $fixture['ivanovKaspi'], $fixture['petrovCash'], $fixture['romashkaKaspi'], $fixture['sidorovCash']];

    Livewire::test(BuildReport::class)
        ->assertCanSeeTableRecords($all)
        ->filterTable('address', ['city_id' => $fixture['astana']->id])
        ->assertCanSeeTableRecords([$fixture['sidorovCash']])
        ->assertCanNotSeeTableRecords([$fixture['ivanovCash'], $fixture['romashkaKaspi']])
        ->removeTableFilters()
        ->filterTable('controller_ids', [$fixture['regionController']->id, $fixture['streetController']->id])
        ->assertCanSeeTableRecords([$fixture['ivanovCash'], $fixture['ivanovKaspi'], $fixture['petrovCash'], $fixture['romashkaKaspi']])
        ->assertCanNotSeeTableRecords([$fixture['sidorovCash']])
        ->removeTableFilters()
        ->filterTable('paid_at', ['start_date' => '2026-06-08', 'end_date' => '2026-06-20'])
        ->assertCanSeeTableRecords([$fixture['ivanovKaspi'], $fixture['petrovCash'], $fixture['romashkaKaspi']])
        ->assertCanNotSeeTableRecords([$fixture['ivanovCash'], $fixture['sidorovCash']])
        ->removeTableFilters()
        ->filterTable('method', PaymentMethod::Kaspi->value)
        ->assertCanSeeTableRecords([$fixture['ivanovKaspi'], $fixture['romashkaKaspi']])
        ->assertCanNotSeeTableRecords([$fixture['ivanovCash'], $fixture['petrovCash'], $fixture['sidorovCash']])
        ->filterTable('method', 'bitcoin')
        ->assertCanSeeTableRecords($all);

    $summary = Livewire::withQueryParams(['mode' => 'summary', 'group' => 'region'])->test(BuildReport::class);

    expect(array_column(reportBuilderSummary($summary), 'group_label'))
        ->toBe(['Алмалинский', 'Бостандыкский', 'Есильский', 'Итого']);

    $summary->filterTable('address', ['city_id' => $fixture['astana']->id]);
    expect(reportBuilderSummary($summary))->toBe([
        ['group_label' => 'Есильский', 'is_total' => false, 'clients' => 1, 'count' => 1, 'sum' => 4000.0, 'average' => 4000.0],
        ['group_label' => 'Итого', 'is_total' => true, 'clients' => 1, 'count' => 1, 'sum' => 4000.0, 'average' => 4000.0],
    ]);

    $summary->removeTableFilters()->filterTable('controller_ids', [$fixture['streetController']->id]);
    expect(array_column(reportBuilderSummary($summary), 'sum', 'group_label'))
        ->toBe(['Бостандыкский' => 3000.0, 'Итого' => 3000.0]);

    $summary->removeTableFilters()->filterTable('paid_at', ['start_date' => '2026-06-08', 'end_date' => '2026-06-20']);
    expect(array_column(reportBuilderSummary($summary), 'sum', 'group_label'))
        ->toBe(['Алмалинский' => 2500.0, 'Бостандыкский' => 3000.0, 'Итого' => 5500.0]);

    $summary->removeTableFilters()->filterTable('method', PaymentMethod::Cash->value);
    expect(array_column(reportBuilderSummary($summary), 'count', 'group_label'))
        ->toBe(['Алмалинский' => 2, 'Есильский' => 1, 'Итого' => 3]);
});

test('фильтры начислений по адресу, контроллерам, дате и типу абонента действуют в детальном режиме и в сводке', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $clients = [$fixture['ivanov'], $fixture['petrov'], $fixture['romashka'], $fixture['sidorov']];

    Livewire::withQueryParams(['source' => 'accruals'])->test(BuildReport::class)
        ->assertCanSeeTableRecords($clients)
        ->filterTable('address', ['region_id' => $fixture['almalinsky']->id])
        ->assertCanSeeTableRecords([$fixture['ivanov'], $fixture['petrov']])
        ->assertCanNotSeeTableRecords([$fixture['romashka'], $fixture['sidorov']])
        ->removeTableFilters()
        ->filterTable('controller_ids', [$fixture['streetController']->id])
        ->assertCanSeeTableRecords([$fixture['romashka']])
        ->assertCanNotSeeTableRecords([$fixture['ivanov'], $fixture['petrov'], $fixture['sidorov']])
        ->removeTableFilters()
        ->filterTable('accrued_at', ['start_date' => '2026-06-02', 'end_date' => null])
        ->assertCanNotSeeTableRecords($clients)
        ->filterTable('accrued_at', ['start_date' => '2026-06-01', 'end_date' => '2026-06-01'])
        ->assertCanSeeTableRecords($clients)
        ->removeTableFilters()
        ->filterTable('client_type', ClientType::Llp->value)
        ->assertCanSeeTableRecords([$fixture['romashka']])
        ->assertCanNotSeeTableRecords([$fixture['ivanov'], $fixture['petrov'], $fixture['sidorov']]);

    $summary = Livewire::withQueryParams(['source' => 'accruals', 'mode' => 'summary', 'group' => 'city'])->test(BuildReport::class);

    $summary->filterTable('address', ['region_id' => $fixture['almalinsky']->id]);
    expect(array_column(reportBuilderSummary($summary), 'clients', 'group_label'))->toBe(['Алматы' => 2, 'Итого' => 2]);

    $summary->removeTableFilters()->filterTable('controller_ids', [$fixture['streetController']->id]);
    expect(array_column(reportBuilderSummary($summary), 'sum', 'group_label'))->toBe(['Алматы' => 2000.0, 'Итого' => 2000.0]);

    $summary->removeTableFilters()->filterTable('accrued_at', ['start_date' => '2026-06-02', 'end_date' => null]);
    expect(reportBuilderSummary($summary))->toBe([]);

    $summary->removeTableFilters()->filterTable('client_type', ClientType::Individual->value);
    expect(array_column(reportBuilderSummary($summary), 'sum', 'group_label'))->toBe(['Алматы' => 1000.0, 'Астана' => 1000.0, 'Итого' => 2000.0]);
});

// --- Сводка -----------------------------------------------------------------

test('сводка оплат группирует по каждому измерению и считает уникальных абонентов', function (string $group, array $expected): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::test(BuildReport::class)
        ->call('selectMode', 'summary')
        ->call('selectDimension', $group);

    expect(array_map(
        fn (array $record): array => [$record['group_label'], $record['clients'], $record['count'], $record['sum'], round($record['average'], 2)],
        reportBuilderSummary($page),
    ))->toBe($expected);
})->with([
    'по городам' => ['city', [
        ['Алматы', 3, 4, 6500.0, 1625.0],
        ['Астана', 1, 1, 4000.0, 4000.0],
        ['Итого', 4, 5, 10500.0, 2100.0],
    ]],
    'по районам' => ['region', [
        ['Алмалинский', 2, 3, 3500.0, 1166.67],
        ['Бостандыкский', 1, 1, 3000.0, 3000.0],
        ['Есильский', 1, 1, 4000.0, 4000.0],
        ['Итого', 4, 5, 10500.0, 2100.0],
    ]],
    'по улицам' => ['street', [
        ['Алмалинский / Абая', 1, 2, 1500.0, 750.0],
        ['Алмалинский / Гоголя', 1, 1, 2000.0, 2000.0],
        ['Бостандыкский / Сатпаева', 1, 1, 3000.0, 3000.0],
        ['Есильский / Кабанбай батыра', 1, 1, 4000.0, 4000.0],
        ['Итого', 4, 5, 10500.0, 2100.0],
    ]],
    'по контроллерам' => ['controller', [
        ['Контроллер района', 2, 3, 3500.0, 1166.67],
        ['Контроллер улицы', 1, 1, 3000.0, 3000.0],
        ['Итого', 3, 4, 6500.0, 1625.0],
    ]],
    'по способам оплаты' => ['method', [
        ['Наличные', 3, 3, 7000.0, 2333.33],
        ['Kaspi', 2, 2, 3500.0, 1750.0],
        ['Итого', 4, 5, 10500.0, 2100.0],
    ]],
]);

test('сводка начислений пересчитывает собираемость от итогов группы и строки «Итого»', function (string $group, array $expected): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::withQueryParams(['source' => 'accruals', 'mode' => 'summary', 'group' => $group])->test(BuildReport::class);

    expect(array_map(
        fn (array $record): array => [$record['group_label'], $record['clients'], $record['count'], $record['sum'], round($record['average'], 2), $record['collection_percent']],
        reportBuilderSummary($page),
    ))->toBe($expected);
})->with([
    'по районам' => ['region', [
        ['Алмалинский', 2, 2, 1000.0, 500.0, 80.0],
        ['Бостандыкский', 1, 1, 2000.0, 2000.0, 125.0],
        ['Есильский', 1, 1, 1000.0, 1000.0, 50.0],
        ['Итого', 4, 4, 4000.0, 1000.0, 95.0],
    ]],
    'по типам абонентов' => ['client_type', [
        ['Физ. лицо', 3, 3, 2000.0, 666.67, 65.0],
        ['ТОО', 1, 1, 2000.0, 2000.0, 125.0],
        ['Итого', 4, 4, 4000.0, 1000.0, 95.0],
    ]],
    'по контроллерам' => ['controller', [
        ['Контроллер района', 2, 2, 1000.0, 500.0, 80.0],
        ['Контроллер улицы', 1, 1, 2000.0, 2000.0, 125.0],
        ['Итого', 3, 3, 3000.0, 1000.0, 110.0],
    ]],
]);

test('собираемость группы с нулевым начислением — прочерк, а не ноль и не ошибка', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::withQueryParams(['source' => 'accruals', 'mode' => 'summary', 'group' => 'street'])->test(BuildReport::class)
        ->filterTable('address', ['street_ids' => [$fixture['gogol']->id]]);

    expect(reportBuilderSummary($page))->toBe([
        ['group_label' => 'Алмалинский / Гоголя', 'is_total' => false, 'clients' => 1, 'count' => 1, 'sum' => 0.0, 'average' => 0.0, 'collection_percent' => null],
        ['group_label' => 'Итого', 'is_total' => true, 'clients' => 1, 'count' => 1, 'sum' => 0.0, 'average' => 0.0, 'collection_percent' => null],
    ]);

    $page->assertSee('—');
});

test('выключенные показатели пропадают из сводки, а без измерения сводка просит его выбрать', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::withQueryParams(['mode' => 'summary', 'group' => 'city', 'off' => 'average,clients'])->test(BuildReport::class);

    expect(reportBuilderColumnNames($page))->toBe(['group_label', 'count', 'sum'])
        ->and(array_keys(reportBuilderSummary($page)[0]))->toBe(['group_label', 'is_total', 'count', 'sum']);

    $page->call('toggleMetric', 'clients');

    expect(reportBuilderColumnNames($page))->toBe(['group_label', 'clients', 'count', 'sum'])
        ->and($page->get('disabledMetrics'))->toBe('average');

    $locked = Livewire::withQueryParams(['mode' => 'summary', 'group' => 'none'])->test(BuildReport::class)
        ->assertSee('Выберите измерение в «Группировка», чтобы собрать сводку.')
        ->assertCanSeeTableRecords([$fixture['ivanovCash']]);

    expect($locked->instance()->reportBuild()->isSummary())->toBeFalse();

    $locked->call('selectDimension', 'region')
        ->assertDontSee('Выберите измерение в «Группировка», чтобы собрать сводку.');

    expect(array_column(reportBuilderSummary($locked), 'group_label'))->toBe(['Алмалинский', 'Бостандыкский', 'Есильский', 'Итого']);

    $locked->call('selectDimension', 'none');

    expect($locked->get('mode'))->toBe('detail');
});

test('сводка и «Итого» считаются одним запросом при любом числе групп', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $queryCounts = [];

    foreach (['city' => 2, 'street' => 4, 'controller' => 2, 'method' => 2] as $group => $groupsCount) {
        $page = Livewire::withQueryParams(['mode' => 'summary', 'group' => $group])->test(BuildReport::class);
        $page->instance()->flushCachedTableRecords();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $records = $page->instance()->getTableRecords();

        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $summaryQueries = array_values(array_filter($queries, fn (string $query): bool => str_contains($query, 'report_rows')));

        expect($records)->toHaveCount($groupsCount + 1)
            ->and($summaryQueries)->toHaveCount(1)
            ->and($summaryQueries[0])->toContain('union all');

        $queryCounts[$group] = count($queries);
    }

    expect(array_unique($queryCounts))->toHaveCount(1);
});

test('детальный режим загружает адрес и связи без запроса на строку', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $queriesForPage = function () use ($fixture): int {
        $page = Livewire::withQueryParams(['fields' => 'account_number,client_name,address,billing_period,method,amount,received_by'])
            ->test(BuildReport::class);
        $page->instance()->flushCachedTableRecords();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $records = $page->instance()->getTableRecords();

        foreach ($records as $record) {
            foreach ($page->instance()->reportBuild()->fields as $field) {
                $field->valueOf($record, $fixture['june']);
            }
        }

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $before = $queriesForPage();

    foreach (range(1, 10) as $day) {
        $client = reportBuilderClient($fixture['organization'], $fixture['utilityService'], [
            'account_number' => (string) (200000 + $day),
            'region_id' => $fixture['esil']->id,
            'street_id' => $fixture['kabanbay']->id,
        ]);

        Payment::factory()->for($fixture['organization'])->for($client)->create([
            'period' => '202606',
            'amount' => 100,
            'paid_at' => '2026-06-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT),
        ]);
    }

    expect($queriesForPage())->toBe($before);
});

// --- Вычисляемые колонки ----------------------------------------------------

test('вычисляемые колонки считает база, они сортируются, а нулевой знаменатель даёт прочерк', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::withQueryParams(['source' => 'accruals', 'fields' => 'account_number,collection_percent,debt_growth'])
        ->test(BuildReport::class)
        ->assertTableColumnStateSet('collection_percent', 80.0, $fixture['ivanov'])
        ->assertTableColumnStateSet('collection_percent', null, $fixture['petrov'])
        ->assertTableColumnStateSet('collection_percent', 125.0, $fixture['romashka'])
        ->assertTableColumnStateSet('collection_percent', 50.0, $fixture['sidorov'])
        ->assertTableColumnStateSet('debt_growth', 200.0, $fixture['ivanov'])
        ->assertTableColumnStateSet('debt_growth', 0.0, $fixture['petrov'])
        ->assertTableColumnStateSet('debt_growth', -500.0, $fixture['romashka'])
        ->assertTableColumnStateSet('debt_growth', 500.0, $fixture['sidorov'])
        ->assertTableColumnFormattedStateSet('collection_percent', '80.00%', $fixture['ivanov'])
        ->assertSee('—');

    expect($page->instance()->getTable()->getColumn('collection_percent')->getPlaceholder())->toBe('—');

    $page->sortTable('collection_percent')
        ->assertCanSeeTableRecords([$fixture['petrov'], $fixture['sidorov'], $fixture['ivanov'], $fixture['romashka']], inOrder: true)
        ->sortTable('collection_percent', 'desc')
        ->assertCanSeeTableRecords([$fixture['romashka'], $fixture['ivanov'], $fixture['sidorov'], $fixture['petrov']], inOrder: true)
        ->sortTable('debt_growth')
        ->assertCanSeeTableRecords([$fixture['romashka'], $fixture['petrov'], $fixture['ivanov'], $fixture['sidorov']], inOrder: true);

    expect(reportBuilderXlsxRows($page->callAction('downloadXlsx')))->toBe([
        ['Лицевой счёт', 'Собираемость, %', 'Прирост долга'],
        ['100003', 125, -500],
        ['100002', '—', 0],
        ['100001', 80, 200],
        ['100004', 50, 500],
    ]);
});

test('начисления конструктора совпадают с оборотно-сальдовой ведомостью в закрытом и открытом месяце', function (): void {
    $fixture = reportBuilderFixture();
    $user = reportBuilderOperator($fixture['organization']);

    foreach ([$fixture['may'], $fixture['june']] as $billingPeriod) {
        $turnover = app(ReportSummaryService::class)->records(
            'turnover-balance-sheet',
            ReportSummaryGroup::City,
            $fixture['organization'],
            $user,
            $billingPeriod,
        )['total'];

        $page = Livewire::withQueryParams([
            'source' => 'accruals',
            'mode' => 'summary',
            'group' => 'city',
            'period' => (string) $billingPeriod->id,
        ])->test(BuildReport::class);

        $total = collect(reportBuilderSummary($page))->last();

        expect($total['sum'])->toEqual($turnover['accrued_amount']);

        $detail = Livewire::withQueryParams([
            'source' => 'accruals',
            'fields' => 'account_number,opening_balance,accrued_amount,paid_amount,closing_balance',
            'period' => (string) $billingPeriod->id,
        ])->test(BuildReport::class);

        $rows = collect(reportBuilderXlsxRows($detail->callAction('downloadXlsx')))->slice(1);

        expect($rows->sum(fn (array $row): float => (float) $row[3]))->toEqual($turnover['paid_amount'])
            ->and($rows->sum(fn (array $row): float => max((float) $row[4], 0)))->toEqual($turnover['closing_debit'])
            ->and($rows->sum(fn (array $row): float => max(-(float) $row[4], 0)))->toEqual($turnover['closing_credit']);
    }

    $june = Livewire::withQueryParams(['source' => 'accruals', 'period' => (string) $fixture['june']->id])->test(BuildReport::class)
        ->assertTableColumnStateSet('opening_balance', 300.0, $fixture['ivanov'])
        ->assertTableColumnStateSet('paid_amount', 1500.0, $fixture['ivanov'])
        ->assertTableColumnStateSet('closing_balance', -1200.0, $fixture['ivanov']);

    expect($june->instance()->reportBuild()->billingPeriod?->is($fixture['june']))->toBeTrue();
});

// --- XLSX -------------------------------------------------------------------

test('XLSX детального режима повторяет колонки, фильтры и сортировку экрана, но не поиск', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::test(BuildReport::class)
        ->call('toggleField', 'address')
        ->call('toggleField', 'received_by')
        ->filterTable('method', PaymentMethod::Cash->value)
        ->searchTable('100001')
        ->assertCanSeeTableRecords([$fixture['ivanovCash']])
        ->assertCanNotSeeTableRecords([$fixture['petrovCash']]);

    $download = $page->callAction('downloadXlsx')
        ->assertFileDownloaded(
            'report-builder-payments-detail-'.$fixture['organization']->id.'-202606-'.today()->format('Y-m-d').'.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    expect(reportBuilderXlsxRows($download))->toBe([
        ['Лицевой счёт', 'Абонент', 'Дата оплаты', 'Способ оплаты', 'Сумма', 'Принял'],
        ['100001', 'Иванов Иван', '05.06.2026', 'Наличные', 1000, 'Кассир Айгуль'],
        ['100002', 'Петров Пётр', '12.06.2026', 'Наличные', 2000, 'Кассир Айгуль'],
        ['100004', 'Сидоров Сидор', '25.06.2026', 'Наличные', 4000, 'Кассир Айгуль'],
    ]);

    $sorted = $page->searchTable('')->sortTable('amount', 'desc');

    expect(array_column(array_slice(reportBuilderXlsxRows($sorted->callAction('downloadXlsx')), 1), 4))->toBe([4000, 2000, 1000]);
});

test('XLSX сводного режима повторяет сводку с «Итого» и применёнными фильтрами', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::withQueryParams(['source' => 'accruals', 'mode' => 'summary', 'group' => 'region', 'off' => 'clients'])
        ->test(BuildReport::class)
        ->filterTable('address', ['city_id' => $fixture['almaty']->id]);

    $download = $page->callAction('downloadXlsx')
        ->assertFileDownloaded('report-builder-accruals-summary-region-'.$fixture['organization']->id.'-202605-'.today()->format('Y-m-d').'.xlsx');

    expect(reportBuilderXlsxRows($download))->toBe([
        ['Район', 'Начислений', 'Начислено', 'Среднее начисление', 'Собираемость, %'],
        ['Алмалинский', 2, 1000, 500, 80],
        ['Бостандыкский', 1, 2000, 2000, 125],
        ['Итого', 3, 3000, 1000, 110],
    ]);

    $zero = Livewire::withQueryParams(['source' => 'accruals', 'mode' => 'summary', 'group' => 'region'])
        ->test(BuildReport::class)
        ->filterTable('address', ['street_ids' => [$fixture['gogol']->id]]);

    expect(reportBuilderXlsxRows($zero->callAction('downloadXlsx'))[2])->toBe(['Итого', 1, 1, 0, 0, '—']);
});

// --- Ревью: граничные случаи --------------------------------------------------

test('без единого показателя сводка остаётся группами и одной строкой «Итого», в том числе в SQL', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::withQueryParams(['mode' => 'summary', 'group' => 'city', 'off' => 'clients,count,sum,average'])
        ->test(BuildReport::class);

    expect($page->instance()->reportBuild()->metrics)->toBe([])
        ->and(reportBuilderColumnNames($page))->toBe(['group_label'])
        ->and(reportBuilderSummary($page))->toBe([
            ['group_label' => 'Алматы', 'is_total' => false],
            ['group_label' => 'Астана', 'is_total' => false],
            ['group_label' => 'Итого', 'is_total' => true],
        ]);

    $page->instance()->flushCachedTableRecords();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $page->instance()->getTableRecords();
    $summaryQuery = collect(DB::getQueryLog())->first(fn (array $query): bool => str_contains($query['query'], 'union all'));
    DB::disableQueryLog();

    $rows = collect(DB::select($summaryQuery['query'], $summaryQuery['bindings']));

    expect($rows->where('is_total', 1))->toHaveCount(1)
        ->and($rows->where('is_total', 0))->toHaveCount(2);

    expect(reportBuilderXlsxRows($page->callAction('downloadXlsx')))->toBe([['Город'], ['Алматы'], ['Астана'], ['Итого']]);

    $empty = Livewire::withQueryParams([
        'mode' => 'summary',
        'group' => 'city',
        'off' => 'clients,count,sum,average',
        'period' => (string) $fixture['may']->id,
    ])->test(BuildReport::class);
    $empty->instance()->flushCachedTableRecords();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $records = $empty->instance()->getTableRecords();
    $summaryQuery = collect(DB::getQueryLog())->first(fn (array $query): bool => str_contains($query['query'], 'union all'));
    DB::disableQueryLog();

    expect($records)->toHaveCount(0)
        ->and(DB::select($summaryQuery['query'], $summaryQuery['bindings']))->toHaveCount(1);
});

test('расчётный месяц из адреса и состояния — только положительное целое из цифр', function (string $period): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $value = str_replace('{id}', (string) $fixture['may']->id, $period);

    $fromAddress = Livewire::withQueryParams(['period' => $value])->test(BuildReport::class);

    expect($fromAddress->instance()->reportBuild()->billingPeriod?->is($fixture['june']))->toBeTrue()
        ->and($fromAddress->get('period'))->toBe('');

    $fromState = Livewire::test(BuildReport::class)->set('period', $value);

    expect($fromState->instance()->reportBuild()->billingPeriod?->is($fixture['june']))->toBeTrue()
        ->and($fromState->get('period'))->toBe('');

    $fromAction = Livewire::test(BuildReport::class)->call('selectBillingPeriod', $value);

    expect($fromAction->instance()->reportBuild()->billingPeriod?->is($fixture['june']))->toBeTrue()
        ->and($fromAction->get('period'))->toBe('');

    $chosen = Livewire::withQueryParams(['period' => (string) $fixture['may']->id])->test(BuildReport::class);

    expect($chosen->instance()->reportBuild()->billingPeriod?->is($fixture['may']))->toBeTrue()
        ->and($chosen->get('period'))->toBe((string) $fixture['may']->id);
})->with([
    'дробное' => ['{id}.9'],
    'дробное с нулём' => ['{id}.0'],
    'экспонента' => ['{id}e0'],
    'со знаком' => ['+{id}'],
    'с пробелом' => [' {id}'],
    'отрицательное' => ['-{id}'],
    'ноль' => ['0'],
]);

test('в сводке по контроллерам с пересекающимися зонами «Итого» складывает суммы, а абонентов считает один раз', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderZoneController($fixture['organization'], 'Контроллер Абая', street: $fixture['abay']);
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::withQueryParams(['mode' => 'summary', 'group' => 'controller'])->test(BuildReport::class);

    expect(array_map(
        fn (array $record): array => [$record['group_label'], $record['clients'], $record['count'], $record['sum'], round($record['average'], 2)],
        reportBuilderSummary($page),
    ))->toBe([
        ['Контроллер Абая', 1, 2, 1500.0, 750.0],
        ['Контроллер района', 2, 3, 3500.0, 1166.67],
        ['Контроллер улицы', 1, 1, 3000.0, 3000.0],
        ['Итого', 3, 6, 8000.0, 1333.33],
    ]);

    $page->filterTable('controller_ids', [$fixture['regionController']->id]);

    expect(array_column(reportBuilderSummary($page), 'clients', 'group_label'))
        ->toBe(['Контроллер Абая' => 1, 'Контроллер района' => 2, 'Итого' => 2]);
});

test('подделанные идентификаторы адреса и контроллеров в состоянии фильтров только сужают или игнорируются', function (): void {
    $foreignOrganization = Organization::factory()->create();
    $foreignCity = City::factory()->for($foreignOrganization)->create(['name' => 'Чужой город']);
    $foreignRegion = Region::factory()->for($foreignOrganization)->for($foreignCity)->create(['name' => 'Чужой район']);
    $foreignStreet = Street::factory()->for($foreignRegion)->create(['name' => 'Чужая улица']);
    $foreignController = reportBuilderZoneController($foreignOrganization, 'Чужой контроллер', region: $foreignRegion);
    $foreign = reportBuilderForeignFixture();

    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $own = [$fixture['ivanovCash'], $fixture['ivanovKaspi'], $fixture['petrovCash'], $fixture['romashkaKaspi'], $fixture['sidorovCash']];

    $detail = Livewire::test(BuildReport::class)
        ->filterTable('address', ['city_id' => 'abc', 'region_id' => '1.5', 'street_ids' => ['x', -1, '2.5']])
        ->assertCanSeeTableRecords($own)
        ->assertCanNotSeeTableRecords([$foreign['payment']])
        ->removeTableFilters()
        ->filterTable('address', ['city_id' => $foreignCity->id])
        ->assertCanNotSeeTableRecords([...$own, $foreign['payment']])
        ->removeTableFilters()
        ->filterTable('address', ['street_ids' => [$foreignStreet->id]])
        ->assertCanNotSeeTableRecords([...$own, $foreign['payment']])
        ->removeTableFilters()
        ->filterTable('controller_ids', [$foreignController->id])
        ->assertCanNotSeeTableRecords([...$own, $foreign['payment']])
        ->removeTableFilters()
        ->filterTable('controller_ids', ['abc', '0'])
        ->assertCanSeeTableRecords($own);

    $summary = Livewire::withQueryParams(['mode' => 'summary', 'group' => 'city'])->test(BuildReport::class);

    $summary->filterTable('address', ['region_id' => $foreignRegion->id]);
    expect(reportBuilderSummary($summary))->toBe([]);

    $summary->removeTableFilters()->filterTable('controller_ids', [$foreignController->id]);
    expect(reportBuilderSummary($summary))->toBe([]);

    $summary->removeTableFilters()->filterTable('address', ['city_id' => 'abc']);
    expect(collect(reportBuilderSummary($summary))->last()['sum'])->toBe(10500.0);

    $detail->filterTable('address', ['city_id' => $foreignCity->id]);
    expect(collect(reportBuilderXlsxRows($detail->callAction('downloadXlsx')))->flatten()->contains('FOREIGN-1'))->toBeFalse();
});

// --- Показания счётчиков ----------------------------------------------------

/**
 * Meters and June readings of the clients of reportBuilderFixture(); June has 30 days.
 *
 * - Иванов: М-1 (active) 100 → 130 on 10.06, М-2 (removed) 50 → 65 on 20.06;
 * - Петров: М-3 (active) 200 → 290 on 15.06;
 * - «Ромашка»: М-4 (active) 500 → 500, an accepted reading without a date;
 * - Сидоров: М-5 (active) 10 → 70 on 25.06 and М-6 (active), visited without a reading.
 *
 * М-1 also has a May reading, which never shows up in June.
 *
 * @param  array<string, mixed>  $fixture
 * @return array<string, MeterReading>
 */
function reportBuilderReadingsFixture(array $fixture): array
{
    $meter = fn (Client $client, string $number, string $status = 'active'): Meter => Meter::factory()
        ->for($fixture['organization'])
        ->for($client)
        ->create([
            'utility_service_id' => $fixture['utilityService']->id,
            'number' => $number,
            'initial_reading' => 0,
            'status' => $status,
            'removed_on' => $status === 'removed' ? '2026-06-21' : null,
        ]);

    $reading = fn (Meter $meter, int $previous, ?int $current, ?string $readAt): MeterReading => MeterReading::factory()
        ->for($fixture['organization'])
        ->for($meter)
        ->create([
            'period' => '202606',
            'previous_reading' => $previous,
            'current_reading' => $current,
            'read_at' => $readAt,
        ]);

    $ivanovFirst = $meter($fixture['ivanov'], 'М-1');
    $ivanovRemoved = $meter($fixture['ivanov'], 'М-2', 'removed');
    $petrovMeter = $meter($fixture['petrov'], 'М-3');
    $romashkaMeter = $meter($fixture['romashka'], 'М-4');
    $sidorovMeter = $meter($fixture['sidorov'], 'М-5');
    $sidorovVisited = $meter($fixture['sidorov'], 'М-6');

    $may = MeterReading::factory()
        ->for($fixture['organization'])
        ->for($ivanovFirst)
        ->createQuietly([
            'period' => '202605',
            'billing_period_id' => $fixture['may']->id,
            'previous_reading' => 70,
            'current_reading' => 100,
            'consumption' => 30,
            'read_at' => '2026-05-20',
        ]);

    return [
        'ivanovFirst' => $reading($ivanovFirst, 100, 130, '2026-06-10'),
        'ivanovRemoved' => $reading($ivanovRemoved, 50, 65, '2026-06-20'),
        'petrov' => $reading($petrovMeter, 200, 290, '2026-06-15'),
        'romashka' => $reading($romashkaMeter, 500, 500, null),
        'sidorov' => $reading($sidorovMeter, 10, 70, '2026-06-25'),
        'visit' => $reading($sidorovVisited, 40, null, '2026-06-26'),
        'may' => $may,
    ];
}

/**
 * A June reading of the client of another organization, created before the tenant is set.
 *
 * @param  array{organization: Organization, client: Client}  $foreign
 */
function reportBuilderForeignReading(array $foreign): MeterReading
{
    $meter = Meter::factory()->for($foreign['organization'])->for($foreign['client'])->create([
        'utility_service_id' => $foreign['client']->utility_service_id,
        'number' => 'FOREIGN-M',
        'initial_reading' => 0,
    ]);

    return MeterReading::factory()->for($foreign['organization'])->for($meter)->create([
        'period' => '202606',
        'previous_reading' => 0,
        'current_reading' => 99999,
        'read_at' => '2026-06-15',
    ]);
}

test('источник «Показания счётчиков»: показания месяца, колонки каталога и их типы', function (): void {
    $fixture = reportBuilderFixture();
    $readings = reportBuilderReadingsFixture($fixture);
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::withQueryParams(['source' => 'readings'])->test(BuildReport::class)
        ->assertSee('Строка — показание по счётчику.')
        ->assertSee('выбрано 7 из 10')
        ->assertSeeHtml("wire:click=\"selectSource('clients')\"");

    $build = $page->instance()->reportBuild();

    expect(array_column($page->instance()->sourceOptions(), 'label', 'key'))->toBe([
        'payments' => 'Оплаты',
        'accruals' => 'Начисления',
        'readings' => 'Показания счётчиков',
        'clients' => 'Абоненты',
    ])
        ->and(array_column($page->instance()->fieldOptions(), 'kind', 'key'))->toBe([
            'account_number' => 'текст',
            'client_name' => 'текст',
            'address' => 'текст',
            'meter_number' => 'текст',
            'read_at' => 'дата',
            'previous_reading' => 'число',
            'current_reading' => 'число',
            'consumption' => 'число',
            'controller' => 'текст',
            'daily_consumption' => 'вычисляемое',
        ])
        ->and(reportBuilderColumnNames($page))
        ->toBe(['account_number', 'client_name', 'address', 'meter_number', 'read_at', 'current_reading', 'consumption'])
        ->and(array_map(fn ($dimension): string => $dimension->key, $build->source->dimensions()))
        ->toBe(['city', 'region', 'street', 'controller'])
        ->and(array_column($page->instance()->metricOptions(), 'label', 'key'))->toBe([
            'clients' => 'Абонентов',
            'count' => 'Показаний',
            'sum' => 'Потребление',
            'average' => 'Среднее потребление',
        ])
        ->and($build->billingPeriod?->is($fixture['june']))->toBeTrue();

    $page
        ->assertCanSeeTableRecords([$readings['ivanovFirst'], $readings['ivanovRemoved'], $readings['petrov'], $readings['romashka'], $readings['sidorov']], inOrder: true)
        ->assertCanNotSeeTableRecords([$readings['visit'], $readings['may']])
        ->assertTableColumnStateSet('meter_number', 'М-1', $readings['ivanovFirst'])
        ->assertTableColumnStateSet('address', 'Алмалинский, Абая, д. 10, кв. 5', $readings['ivanovFirst'])
        ->assertTableColumnStateSet('current_reading', 130, $readings['ivanovFirst'])
        ->assertTableColumnStateSet('consumption', 30, $readings['ivanovFirst'])
        ->assertTableColumnFormattedStateSet('read_at', '10.06.2026', $readings['ivanovFirst'])
        ->assertTableColumnStateSet('read_at', null, $readings['romashka']);

    $may = Livewire::withQueryParams(['source' => 'readings', 'period' => (string) $fixture['may']->id])->test(BuildReport::class)
        ->assertCanSeeTableRecords([$readings['may']])
        ->assertCanNotSeeTableRecords([$readings['ivanovFirst'], $readings['petrov']]);

    expect($may->instance()->reportBuild()->billingPeriod?->is($fixture['may']))->toBeTrue();
});

test('показания: среднесуточное считает база, показания целые, пустые значения — «—» на экране и в XLSX', function (): void {
    $fixture = reportBuilderFixture();
    $readings = reportBuilderReadingsFixture($fixture);
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::withQueryParams([
        'source' => 'readings',
        'fields' => 'account_number,meter_number,read_at,previous_reading,current_reading,consumption,controller,daily_consumption',
    ])->test(BuildReport::class)
        ->assertTableColumnStateSet('daily_consumption', 1.0, $readings['ivanovFirst'])
        ->assertTableColumnStateSet('daily_consumption', 0.5, $readings['ivanovRemoved'])
        ->assertTableColumnStateSet('daily_consumption', 3.0, $readings['petrov'])
        ->assertTableColumnStateSet('daily_consumption', 0.0, $readings['romashka'])
        ->assertTableColumnStateSet('daily_consumption', 2.0, $readings['sidorov'])
        ->assertTableColumnStateSet('previous_reading', 100, $readings['ivanovFirst'])
        ->assertTableColumnStateSet('controller', 'Контроллер района', $readings['petrov'])
        ->assertTableColumnStateSet('controller', 'Контроллер улицы', $readings['romashka'])
        ->assertTableColumnStateSet('controller', null, $readings['sidorov'])
        ->assertSee('—');

    expect($page->instance()->getTable()->getColumn('controller')->getPlaceholder())->toBe('—')
        ->and(reportBuilderXlsxRows($page->callAction('downloadXlsx')))->toBe([
            ['Лицевой счёт', 'Счётчик', 'Дата снятия', 'Предыдущее', 'Текущее', 'Потребление', 'Контроллер', 'Среднесуточное, м³'],
            ['100001', 'М-1', '10.06.2026', 100, 130, 30, 'Контроллер района', 1],
            ['100001', 'М-2', '20.06.2026', 50, 65, 15, 'Контроллер района', 0.5],
            ['100002', 'М-3', '15.06.2026', 200, 290, 90, 'Контроллер района', 3],
            ['100003', 'М-4', '—', 500, 500, 0, 'Контроллер улицы', 0],
            ['100004', 'М-5', '25.06.2026', 10, 70, 60, '—', 2],
        ]);

    $page->sortTable('daily_consumption', 'desc')
        ->assertCanSeeTableRecords([$readings['petrov'], $readings['sidorov'], $readings['ivanovFirst'], $readings['ivanovRemoved'], $readings['romashka']], inOrder: true);

    expect(array_column(array_slice(reportBuilderXlsxRows($page->callAction('downloadXlsx')), 1), 1))
        ->toBe(['М-3', 'М-5', 'М-1', 'М-2', 'М-4']);
});

test('сводка показаний группирует по каждому измерению, а среднее потребление пересчитывается от итогов', function (string $group, array $expected): void {
    $fixture = reportBuilderFixture();
    reportBuilderReadingsFixture($fixture);
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::withQueryParams(['source' => 'readings', 'mode' => 'summary', 'group' => $group])->test(BuildReport::class);

    expect(array_map(
        fn (array $record): array => [$record['group_label'], $record['clients'], $record['count'], $record['sum'], round($record['average'], 2)],
        reportBuilderSummary($page),
    ))->toBe($expected);
})->with([
    'по городам' => ['city', [
        ['Алматы', 3, 4, 135, 33.75],
        ['Астана', 1, 1, 60, 60.0],
        ['Итого', 4, 5, 195, 39.0],
    ]],
    'по районам' => ['region', [
        ['Алмалинский', 2, 3, 135, 45.0],
        ['Бостандыкский', 1, 1, 0, 0.0],
        ['Есильский', 1, 1, 60, 60.0],
        ['Итого', 4, 5, 195, 39.0],
    ]],
    'по улицам' => ['street', [
        ['Алмалинский / Абая', 1, 2, 45, 22.5],
        ['Алмалинский / Гоголя', 1, 1, 90, 90.0],
        ['Бостандыкский / Сатпаева', 1, 1, 0, 0.0],
        ['Есильский / Кабанбай батыра', 1, 1, 60, 60.0],
        ['Итого', 4, 5, 195, 39.0],
    ]],
    'по контроллерам' => ['controller', [
        ['Контроллер района', 2, 3, 135, 45.0],
        ['Контроллер улицы', 1, 1, 0, 0.0],
        ['Итого', 3, 4, 135, 33.75],
    ]],
]);

test('потребление сводки показаний совпадает с отчётом по потреблениям и выгружается целым числом', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderReadingsFixture($fixture);
    $user = reportBuilderOperator($fixture['organization']);

    $consumption = app(ReportSummaryService::class)->records(
        'consumption',
        ReportSummaryGroup::City,
        $fixture['organization'],
        $user,
        $fixture['june'],
    )['total'];

    $page = Livewire::withQueryParams(['source' => 'readings', 'mode' => 'summary', 'group' => 'city'])->test(BuildReport::class);
    $total = collect(reportBuilderSummary($page))->last();

    expect($total['sum'])->toBe(195)
        ->and($total['sum'])->toEqual($consumption['consumption'])
        ->and($total['count'])->toEqual($consumption['readings_count']);

    $download = $page->callAction('downloadXlsx')
        ->assertFileDownloaded('report-builder-readings-summary-city-'.$fixture['organization']->id.'-202606-'.today()->format('Y-m-d').'.xlsx');

    expect(reportBuilderXlsxRows($download))->toBe([
        ['Город', 'Абонентов', 'Показаний', 'Потребление', 'Среднее потребление'],
        ['Алматы', 3, 4, 135, 33.75],
        ['Астана', 1, 1, 60, 60],
        ['Итого', 4, 5, 195, 39],
    ]);
});

test('фильтры показаний по адресу, контроллерам, дате снятия и статусу счётчика действуют в детальном режиме и в сводке', function (): void {
    $fixture = reportBuilderFixture();
    $readings = reportBuilderReadingsFixture($fixture);
    reportBuilderOperator($fixture['organization']);

    $all = [$readings['ivanovFirst'], $readings['ivanovRemoved'], $readings['petrov'], $readings['romashka'], $readings['sidorov']];

    Livewire::withQueryParams(['source' => 'readings'])->test(BuildReport::class)
        ->assertCanSeeTableRecords($all)
        ->filterTable('address', ['region_id' => $fixture['almalinsky']->id])
        ->assertCanSeeTableRecords([$readings['ivanovFirst'], $readings['ivanovRemoved'], $readings['petrov']])
        ->assertCanNotSeeTableRecords([$readings['romashka'], $readings['sidorov']])
        ->removeTableFilters()
        ->filterTable('controller_ids', [$fixture['streetController']->id])
        ->assertCanSeeTableRecords([$readings['romashka']])
        ->assertCanNotSeeTableRecords([$readings['ivanovFirst'], $readings['petrov'], $readings['sidorov']])
        ->removeTableFilters()
        ->filterTable('read_at', ['start_date' => '2026-06-12', 'end_date' => '2026-06-22'])
        ->assertCanSeeTableRecords([$readings['ivanovRemoved'], $readings['petrov']])
        ->assertCanNotSeeTableRecords([$readings['ivanovFirst'], $readings['romashka'], $readings['sidorov']])
        ->removeTableFilters()
        ->filterTable('meter_status', 'removed')
        ->assertCanSeeTableRecords([$readings['ivanovRemoved']])
        ->assertCanNotSeeTableRecords([$readings['ivanovFirst'], $readings['petrov'], $readings['romashka'], $readings['sidorov']])
        ->filterTable('meter_status', 'active')
        ->assertCanSeeTableRecords([$readings['ivanovFirst'], $readings['petrov'], $readings['romashka'], $readings['sidorov']])
        ->assertCanNotSeeTableRecords([$readings['ivanovRemoved']])
        ->filterTable('meter_status', 'broken')
        ->assertCanSeeTableRecords($all);

    $summary = Livewire::withQueryParams(['source' => 'readings', 'mode' => 'summary', 'group' => 'city'])->test(BuildReport::class);

    $summary->filterTable('address', ['region_id' => $fixture['almalinsky']->id]);
    expect(array_column(reportBuilderSummary($summary), 'sum', 'group_label'))->toBe(['Алматы' => 135, 'Итого' => 135]);

    $summary->removeTableFilters()->filterTable('controller_ids', [$fixture['regionController']->id]);
    expect(array_column(reportBuilderSummary($summary), 'count', 'group_label'))->toBe(['Алматы' => 3, 'Итого' => 3]);

    $summary->removeTableFilters()->filterTable('read_at', ['start_date' => '2026-06-12', 'end_date' => '2026-06-22']);
    expect(array_column(reportBuilderSummary($summary), 'sum', 'group_label'))->toBe(['Алматы' => 105, 'Итого' => 105]);

    $summary->removeTableFilters()->filterTable('meter_status', 'removed');
    expect(reportBuilderSummary($summary))->toBe([
        ['group_label' => 'Алматы', 'is_total' => false, 'clients' => 1, 'count' => 1, 'sum' => 15, 'average' => 15.0],
        ['group_label' => 'Итого', 'is_total' => true, 'clients' => 1, 'count' => 1, 'sum' => 15, 'average' => 15.0],
    ]);
});

test('показания другой организации не попадают ни в детальный режим, ни в сводку, ни в XLSX', function (): void {
    $foreign = reportBuilderForeignFixture();
    $foreignReading = reportBuilderForeignReading($foreign);
    $fixture = reportBuilderFixture();
    $readings = reportBuilderReadingsFixture($fixture);
    reportBuilderOperator($fixture['organization']);

    $detail = Livewire::withQueryParams(['source' => 'readings', 'fields' => 'account_number,meter_number,consumption'])->test(BuildReport::class)
        ->assertCanSeeTableRecords([$readings['ivanovFirst'], $readings['sidorov']])
        ->assertCanNotSeeTableRecords([$foreignReading]);

    $rows = collect(reportBuilderXlsxRows($detail->callAction('downloadXlsx')))->flatten();

    expect($rows->contains('FOREIGN-1'))->toBeFalse()
        ->and($rows->contains('FOREIGN-M'))->toBeFalse()
        ->and($rows->contains(99999))->toBeFalse();

    $summary = Livewire::withQueryParams(['source' => 'readings', 'mode' => 'summary', 'group' => 'city'])->test(BuildReport::class);

    expect(collect(reportBuilderSummary($summary))->last())->toBe(
        ['group_label' => 'Итого', 'is_total' => true, 'clients' => 4, 'count' => 5, 'sum' => 195, 'average' => 39.0],
    )
        ->and(collect(reportBuilderXlsxRows($summary->callAction('downloadXlsx')))->last())->toBe(['Итого', 4, 5, 195, 39]);
});

// --- Абоненты ---------------------------------------------------------------

/**
 * Debts of the clients of reportBuilderFixture() and of the inactive Касымов.
 *
 * June is open and is the default month of the source: receipts of 2000 for Иванов,
 * 3500 for «Ромашка» and 5000 for Сидоров give the debts Иванов 300 + 2000 − 1500 = 800,
 * Петров 0 − 2000 (an overpayment, so the debt is 0), «Ромашка» 0 + 3500 − 3000 = 500,
 * Сидоров 500 + 5000 − 4000 = 1500 and the inactive Касымов 700, his incoming balance of June.
 *
 * May is closed: Иванов 300, Петров 0, «Ромашка» 0, Сидоров 500; Касымов has no May accrual.
 *
 * Residents: Иванов 2, Петров 1, «Ромашка» 0, Сидоров 3, Касымов 1.
 * Created: Иванов 10.01.2026, Петров 15.02.2026, «Ромашка» 20.03.2026, Сидоров 25.04.2026, Касымов 05.05.2026.
 *
 * @param  array<string, mixed>  $fixture
 */
function reportBuilderClientsFixture(array $fixture): Client
{
    $kasymov = reportBuilderClient($fixture['organization'], $fixture['utilityService'], [
        'account_number' => '100005',
        'name' => 'Касымов Ержан',
        'status' => 'inactive',
        'region_id' => $fixture['esil']->id,
        'street_id' => $fixture['kabanbay']->id,
        'house' => '9',
    ]);

    BalanceAdjustment::factory()->for($fixture['organization'])->for($kasymov)->create([
        'period' => '202606',
        'type' => BalanceAdjustmentType::OpeningBalance->value,
        'amount' => 700,
        'adjusted_at' => '2026-06-02',
    ]);

    foreach (['ivanov' => 2000, 'romashka' => 3500, 'sidorov' => 5000] as $client => $amount) {
        Receipt::factory()->for($fixture['organization'])->for($fixture[$client])->create([
            'period' => '202606',
            'account_number' => $fixture[$client]->account_number,
            'client_name' => $fixture[$client]->name,
            'amount' => $amount,
            'issued_at' => '2026-06-15 10:00:00',
        ]);
    }

    $details = [
        'ivanov' => [2, '2026-01-10 10:00:00'],
        'petrov' => [1, '2026-02-15 10:00:00'],
        'romashka' => [0, '2026-03-20 10:00:00'],
        'sidorov' => [3, '2026-04-25 10:00:00'],
    ];

    foreach ($details as $client => [$residents, $createdAt]) {
        DB::table('clients')->where('id', $fixture[$client]->id)->update(['residents_count' => $residents, 'created_at' => $createdAt]);
    }

    DB::table('clients')->where('id', $kasymov->id)->update(['residents_count' => 1, 'created_at' => '2026-05-05 10:00:00']);

    return $kasymov;
}

test('источник «Абоненты»: все абоненты организации, колонки каталога и их типы, без счётчика строк', function (): void {
    $fixture = reportBuilderFixture();
    $kasymov = reportBuilderClientsFixture($fixture);
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::withQueryParams(['source' => 'clients'])->test(BuildReport::class)
        ->assertSee('Строка — абонент организации.')
        ->assertSee('выбрано 6 из 10');

    $build = $page->instance()->reportBuild();

    expect(array_column($page->instance()->fieldOptions(), 'kind', 'key'))->toBe([
        'account_number' => 'текст',
        'client_name' => 'текст',
        'address' => 'текст',
        'client_type' => 'текст',
        'status' => 'текст',
        'utility_service' => 'текст',
        'residents_count' => 'число',
        'controller' => 'текст',
        'debt' => 'сумма',
        'debt_per_resident' => 'вычисляемое',
    ])
        ->and(reportBuilderColumnNames($page))
        ->toBe(['account_number', 'client_name', 'address', 'client_type', 'residents_count', 'debt'])
        ->and(array_values($page->instance()->dimensionOptions()))
        ->toBe(['Без группировки', 'По городам', 'По районам', 'По улицам', 'По контроллерам', 'По типам абонентов', 'По статусам'])
        ->and(array_column($page->instance()->metricOptions(), 'label', 'key'))->toBe([
            'clients' => 'Абонентов',
            'sum' => 'Долг',
            'average' => 'Средний долг',
        ])
        ->and($build->billingPeriod?->is($fixture['june']))->toBeTrue();

    $page
        ->assertCanSeeTableRecords([$fixture['ivanov'], $fixture['petrov'], $fixture['romashka'], $fixture['sidorov'], $kasymov], inOrder: true)
        ->assertTableColumnStateSet('client_type', 'ТОО', $fixture['romashka'])
        ->assertTableColumnStateSet('residents_count', 2, $fixture['ivanov'])
        ->assertTableColumnStateSet('debt', 800.0, $fixture['ivanov'])
        ->assertTableColumnStateSet('debt', 0.0, $fixture['petrov'])
        ->assertTableColumnStateSet('debt', 700.0, $kasymov);
});

test('долг абонента на проживающего считает база, а без проживающих — «—» на экране и в XLSX', function (): void {
    $fixture = reportBuilderFixture();
    $kasymov = reportBuilderClientsFixture($fixture);
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::withQueryParams([
        'source' => 'clients',
        'fields' => 'account_number,client_type,status,utility_service,residents_count,controller,debt,debt_per_resident',
    ])->test(BuildReport::class)
        ->assertTableColumnStateSet('debt_per_resident', 400.0, $fixture['ivanov'])
        ->assertTableColumnStateSet('debt_per_resident', 0.0, $fixture['petrov'])
        ->assertTableColumnStateSet('debt_per_resident', null, $fixture['romashka'])
        ->assertTableColumnStateSet('debt_per_resident', 500.0, $fixture['sidorov'])
        ->assertTableColumnStateSet('status', 'Неактивный', $kasymov)
        ->assertTableColumnStateSet('utility_service', $fixture['utilityService']->name, $kasymov)
        ->assertTableColumnStateSet('controller', 'Контроллер района', $fixture['petrov'])
        ->assertTableColumnStateSet('controller', null, $kasymov)
        ->assertSee('—');

    expect($page->instance()->getTable()->getColumn('debt_per_resident')->getPlaceholder())->toBe('—');

    $service = $fixture['utilityService']->name;

    expect(reportBuilderXlsxRows($page->callAction('downloadXlsx')))->toBe([
        ['Лицевой счёт', 'Тип абонента', 'Статус', 'Услуги', 'Проживающих', 'Контроллер', 'Долг', 'Долг на проживающего'],
        ['100001', 'Физ. лицо', 'Активный', $service, 2, 'Контроллер района', 800, 400],
        ['100002', 'Физ. лицо', 'Активный', $service, 1, 'Контроллер района', 0, 0],
        ['100003', 'ТОО', 'Активный', $service, 0, 'Контроллер улицы', 500, '—'],
        ['100004', 'Физ. лицо', 'Активный', $service, 3, '—', 1500, 500],
        ['100005', 'Физ. лицо', 'Неактивный', $service, 1, '—', 700, 700],
    ]);

    $page->sortTable('debt_per_resident', 'desc')
        ->assertCanSeeTableRecords([$kasymov, $fixture['sidorov'], $fixture['ivanov'], $fixture['petrov'], $fixture['romashka']], inOrder: true);

    $may = Livewire::withQueryParams([
        'source' => 'clients',
        'fields' => 'account_number,debt,debt_per_resident',
        'period' => (string) $fixture['may']->id,
    ])->test(BuildReport::class);

    expect(reportBuilderXlsxRows($may->callAction('downloadXlsx')))->toBe([
        ['Лицевой счёт', 'Долг', 'Долг на проживающего'],
        ['100001', 300, 150],
        ['100002', 0, 0],
        ['100003', 0, '—'],
        ['100004', 500, 166.67],
        ['100005', '—', '—'],
    ]);
});

test('сводка абонентов группирует по каждому измерению, а средний долг пересчитывается от итогов', function (string $group, array $expected): void {
    $fixture = reportBuilderFixture();
    reportBuilderClientsFixture($fixture);
    reportBuilderOperator($fixture['organization']);

    $page = Livewire::withQueryParams(['source' => 'clients', 'mode' => 'summary', 'group' => $group])->test(BuildReport::class);

    expect(array_map(
        fn (array $record): array => [$record['group_label'], $record['clients'], $record['sum'], round($record['average'], 2)],
        reportBuilderSummary($page),
    ))->toBe($expected);
})->with([
    'по городам' => ['city', [
        ['Алматы', 3, 1300.0, 433.33],
        ['Астана', 2, 2200.0, 1100.0],
        ['Итого', 5, 3500.0, 700.0],
    ]],
    'по районам' => ['region', [
        ['Алмалинский', 2, 800.0, 400.0],
        ['Бостандыкский', 1, 500.0, 500.0],
        ['Есильский', 2, 2200.0, 1100.0],
        ['Итого', 5, 3500.0, 700.0],
    ]],
    'по улицам' => ['street', [
        ['Алмалинский / Абая', 1, 800.0, 800.0],
        ['Алмалинский / Гоголя', 1, 0.0, 0.0],
        ['Бостандыкский / Сатпаева', 1, 500.0, 500.0],
        ['Есильский / Кабанбай батыра', 2, 2200.0, 1100.0],
        ['Итого', 5, 3500.0, 700.0],
    ]],
    'по контроллерам' => ['controller', [
        ['Контроллер района', 2, 800.0, 400.0],
        ['Контроллер улицы', 1, 500.0, 500.0],
        ['Итого', 3, 1300.0, 433.33],
    ]],
    'по типам абонентов' => ['client_type', [
        ['Физ. лицо', 4, 3000.0, 750.0],
        ['ТОО', 1, 500.0, 500.0],
        ['Итого', 5, 3500.0, 700.0],
    ]],
    'по статусам' => ['status', [
        ['Активный', 4, 2800.0, 700.0],
        ['Неактивный', 1, 700.0, 700.0],
        ['Итого', 5, 3500.0, 700.0],
    ]],
]);

test('долг абонентов совпадает с отчётом по долгам в открытом месяце и с ведомостью в закрытом', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderClientsFixture($fixture);
    $user = reportBuilderOperator($fixture['organization']);

    $debts = app(ReportSummaryService::class)->records(
        'debts',
        ReportSummaryGroup::City,
        $fixture['organization'],
        $user,
        $fixture['june'],
    )['total'];

    $active = Livewire::withQueryParams(['source' => 'clients', 'mode' => 'summary', 'group' => 'city'])->test(BuildReport::class)
        ->filterTable('client_status', 'active');

    expect(collect(reportBuilderSummary($active))->last()['sum'])->toBe(2800.0)
        ->and(collect(reportBuilderSummary($active))->last()['sum'])->toEqual($debts['debt_amount']);

    $turnover = app(ReportSummaryService::class)->records(
        'turnover-balance-sheet',
        ReportSummaryGroup::City,
        $fixture['organization'],
        $user,
        $fixture['may'],
    )['total'];

    $may = Livewire::withQueryParams([
        'source' => 'clients',
        'mode' => 'summary',
        'group' => 'status',
        'period' => (string) $fixture['may']->id,
    ])->test(BuildReport::class);

    expect(reportBuilderSummary($may))->toBe([
        ['group_label' => 'Активный', 'is_total' => false, 'clients' => 4, 'sum' => 800.0, 'average' => 200.0],
        ['group_label' => 'Неактивный', 'is_total' => false, 'clients' => 1, 'sum' => 0.0, 'average' => null],
        ['group_label' => 'Итого', 'is_total' => true, 'clients' => 5, 'sum' => 800.0, 'average' => 160.0],
    ])
        ->and(collect(reportBuilderSummary($may))->last()['sum'])->toEqual($turnover['closing_debit']);

    expect(reportBuilderXlsxRows($may->callAction('downloadXlsx')))->toBe([
        ['Статус', 'Абонентов', 'Долг', 'Средний долг'],
        ['Активный', 4, 800, 200],
        ['Неактивный', 1, 0, '—'],
        ['Итого', 5, 800, 160],
    ]);
});

test('фильтры абонентов по адресу, контроллерам, дате создания и статусу действуют в детальном режиме и в сводке', function (): void {
    $fixture = reportBuilderFixture();
    $kasymov = reportBuilderClientsFixture($fixture);
    reportBuilderOperator($fixture['organization']);

    $all = [$fixture['ivanov'], $fixture['petrov'], $fixture['romashka'], $fixture['sidorov'], $kasymov];

    Livewire::withQueryParams(['source' => 'clients'])->test(BuildReport::class)
        ->assertCanSeeTableRecords($all)
        ->filterTable('address', ['city_id' => $fixture['astana']->id])
        ->assertCanSeeTableRecords([$fixture['sidorov'], $kasymov])
        ->assertCanNotSeeTableRecords([$fixture['ivanov'], $fixture['petrov'], $fixture['romashka']])
        ->removeTableFilters()
        ->filterTable('controller_ids', [$fixture['streetController']->id])
        ->assertCanSeeTableRecords([$fixture['romashka']])
        ->assertCanNotSeeTableRecords([$fixture['ivanov'], $fixture['petrov'], $fixture['sidorov'], $kasymov])
        ->removeTableFilters()
        ->filterTable('created_at', ['start_date' => '2026-03-01', 'end_date' => '2026-04-30'])
        ->assertCanSeeTableRecords([$fixture['romashka'], $fixture['sidorov']])
        ->assertCanNotSeeTableRecords([$fixture['ivanov'], $fixture['petrov'], $kasymov])
        ->removeTableFilters()
        ->filterTable('client_status', 'inactive')
        ->assertCanSeeTableRecords([$kasymov])
        ->assertCanNotSeeTableRecords([$fixture['ivanov'], $fixture['petrov'], $fixture['romashka'], $fixture['sidorov']])
        ->filterTable('client_status', 'deleted')
        ->assertCanSeeTableRecords($all);

    $summary = Livewire::withQueryParams(['source' => 'clients', 'mode' => 'summary', 'group' => 'city'])->test(BuildReport::class);

    $summary->filterTable('address', ['city_id' => $fixture['astana']->id]);
    expect(array_column(reportBuilderSummary($summary), 'sum', 'group_label'))->toBe(['Астана' => 2200.0, 'Итого' => 2200.0]);

    $summary->removeTableFilters()->filterTable('controller_ids', [$fixture['regionController']->id]);
    expect(array_column(reportBuilderSummary($summary), 'clients', 'group_label'))->toBe(['Алматы' => 2, 'Итого' => 2]);

    $summary->removeTableFilters()->filterTable('created_at', ['start_date' => '2026-03-01', 'end_date' => '2026-04-30']);
    expect(array_column(reportBuilderSummary($summary), 'sum', 'group_label'))
        ->toBe(['Алматы' => 500.0, 'Астана' => 1500.0, 'Итого' => 2000.0]);

    $summary->removeTableFilters()->filterTable('client_status', 'inactive');
    expect(reportBuilderSummary($summary))->toBe([
        ['group_label' => 'Астана', 'is_total' => false, 'clients' => 1, 'sum' => 700.0, 'average' => 700.0],
        ['group_label' => 'Итого', 'is_total' => true, 'clients' => 1, 'sum' => 700.0, 'average' => 700.0],
    ]);
});

test('абоненты другой организации не попадают ни в детальный режим, ни в сводку, ни в XLSX', function (): void {
    $foreign = reportBuilderForeignFixture();
    $fixture = reportBuilderFixture();
    $kasymov = reportBuilderClientsFixture($fixture);
    reportBuilderOperator($fixture['organization']);

    $detail = Livewire::withQueryParams(['source' => 'clients'])->test(BuildReport::class)
        ->assertCanSeeTableRecords([$fixture['ivanov'], $kasymov])
        ->assertCanNotSeeTableRecords([$foreign['client']]);

    $rows = collect(reportBuilderXlsxRows($detail->callAction('downloadXlsx')))->flatten();

    expect($rows->contains('FOREIGN-1'))->toBeFalse()
        ->and($rows->contains('Чужой абонент'))->toBeFalse();

    $summary = Livewire::withQueryParams(['source' => 'clients', 'mode' => 'summary', 'group' => 'status'])->test(BuildReport::class);

    expect(collect(reportBuilderSummary($summary))->last())->toBe(
        ['group_label' => 'Итого', 'is_total' => true, 'clients' => 5, 'sum' => 3500.0, 'average' => 700.0],
    )
        ->and(collect(reportBuilderXlsxRows($summary->callAction('downloadXlsx')))->last())->toBe(['Итого', 5, 3500, 700]);
});

// --- Новые источники: запросы ---------------------------------------------

test('сводка показаний и абонентов считается одним запросом при любом числе групп', function (string $source, array $groups): void {
    $fixture = reportBuilderFixture();
    $source === 'readings' ? reportBuilderReadingsFixture($fixture) : reportBuilderClientsFixture($fixture);
    reportBuilderOperator($fixture['organization']);

    foreach ($groups as $group => $groupsCount) {
        $page = Livewire::withQueryParams(['source' => $source, 'mode' => 'summary', 'group' => $group])->test(BuildReport::class);
        $page->instance()->flushCachedTableRecords();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $records = $page->instance()->getTableRecords();

        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $summaryQueries = array_values(array_filter($queries, fn (string $query): bool => str_contains($query, 'report_rows')));

        expect($records)->toHaveCount($groupsCount + 1)
            ->and($summaryQueries)->toHaveCount(1)
            ->and($summaryQueries[0])->toContain('union all');
    }
})->with([
    'показания' => ['readings', ['city' => 2, 'street' => 4, 'controller' => 2]],
    'абоненты' => ['clients', ['city' => 2, 'street' => 4, 'controller' => 2, 'client_type' => 2, 'status' => 2]],
]);

test('детальный режим показаний и абонентов загружает адрес и контроллеров без запроса на строку', function (string $source, string $fields): void {
    $fixture = reportBuilderFixture();
    $source === 'readings' ? reportBuilderReadingsFixture($fixture) : reportBuilderClientsFixture($fixture);
    reportBuilderOperator($fixture['organization']);

    $queriesForPage = function () use ($fixture, $source, $fields): int {
        $page = Livewire::withQueryParams(['source' => $source, 'fields' => $fields])->test(BuildReport::class);
        $page->instance()->flushCachedTableRecords();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $records = $page->instance()->getTableRecords();

        foreach ($records as $record) {
            foreach ($page->instance()->reportBuild()->fields as $field) {
                $field->valueOf($record, $fixture['june']);
            }
        }

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $before = $queriesForPage();

    foreach (range(1, 10) as $number) {
        $client = reportBuilderClient($fixture['organization'], $fixture['utilityService'], [
            'account_number' => (string) (200000 + $number),
            'region_id' => $number % 2 === 0 ? $fixture['almalinsky']->id : $fixture['esil']->id,
            'street_id' => $number % 2 === 0 ? $fixture['abay']->id : $fixture['kabanbay']->id,
        ]);

        $meter = Meter::factory()->for($fixture['organization'])->for($client)->create([
            'utility_service_id' => $fixture['utilityService']->id,
            'number' => 'N-'.$number,
            'initial_reading' => 0,
        ]);

        MeterReading::factory()->for($fixture['organization'])->for($meter)->create([
            'period' => '202606',
            'previous_reading' => 0,
            'current_reading' => $number,
            'read_at' => '2026-06-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
        ]);
    }

    expect($queriesForPage())->toBe($before);
})->with([
    'показания' => ['readings', 'account_number,address,meter_number,controller,daily_consumption'],
    'абоненты' => ['clients', 'account_number,address,controller,debt,debt_per_resident'],
]);

// --- Округление: экран и XLSX ------------------------------------------------

test('долг на проживающего и средний долг на половине копейки одинаковы на экране и в XLSX', function (): void {
    $fixture = reportBuilderFixture();
    $street = Street::factory()->for($fixture['esil'])->create(['name' => 'Тестовая']);

    $client = function (string $accountNumber, int $residents, float $receipt, Street $street) use ($fixture): Client {
        $client = reportBuilderClient($fixture['organization'], $fixture['utilityService'], [
            'account_number' => $accountNumber,
            'name' => 'Абонент '.$accountNumber,
            'region_id' => $fixture['esil']->id,
            'street_id' => $street->id,
            'residents_count' => $residents,
        ]);

        Receipt::factory()->for($fixture['organization'])->for($client)->create([
            'period' => '202606',
            'account_number' => $accountNumber,
            'client_name' => $client->name,
            'amount' => $receipt,
            'issued_at' => '2026-06-15 10:00:00',
        ]);

        return $client;
    };

    // 1.00 / 8 = 0.125, (1.00 + 0.25) / 2 = 0.625 and 1.08 / 8 = 0.135: ICU rounds the first
    // two ties half to even, PHP rounds them half up.
    $eighth = $client('300001', 8, 1.00, $street);
    $quarter = $client('300002', 1, 0.25, $street);
    $odd = $client('300003', 8, 1.08, $fixture['kabanbay']);
    reportBuilderOperator($fixture['organization']);

    $money = fn (float $amount): string => Number::currency($amount, 'KZT', config('app.locale'));

    $page = Livewire::withQueryParams(['source' => 'clients', 'fields' => 'account_number,debt,debt_per_resident'])
        ->test(BuildReport::class)
        ->filterTable('address', ['street_ids' => [$street->id, $fixture['kabanbay']->id]])
        ->assertTableColumnStateSet('debt_per_resident', 0.13, $eighth)
        ->assertTableColumnFormattedStateSet('debt_per_resident', $money(0.13), $eighth)
        ->assertTableColumnFormattedStateSet('debt_per_resident', $money(0.25), $quarter)
        ->assertTableColumnFormattedStateSet('debt_per_resident', $money(0.14), $odd);

    expect(reportBuilderXlsxRows($page->callAction('downloadXlsx')))->toBe([
        ['Лицевой счёт', 'Долг', 'Долг на проживающего'],
        ['100004', 0, 0],
        ['300001', 1, 0.13],
        ['300002', 0.25, 0.25],
        ['300003', 1.08, 0.14],
    ]);

    $summary = Livewire::withQueryParams(['source' => 'clients', 'mode' => 'summary', 'group' => 'street'])
        ->test(BuildReport::class)
        ->filterTable('address', ['street_ids' => [$street->id]]);

    expect(reportBuilderSummary($summary))->toBe([
        ['group_label' => 'Есильский / Тестовая', 'is_total' => false, 'clients' => 2, 'sum' => 1.25, 'average' => 0.63],
        ['group_label' => 'Итого', 'is_total' => true, 'clients' => 2, 'sum' => 1.25, 'average' => 0.63],
    ]);

    $summary
        ->assertTableColumnFormattedStateSet('average', $money(0.63), 'group:'.$street->id)
        ->assertTableColumnFormattedStateSet('average', $money(0.63), 'total');

    expect(reportBuilderXlsxRows($summary->callAction('downloadXlsx')))->toBe([
        ['Улица', 'Абонентов', 'Долг', 'Средний долг'],
        ['Есильский / Тестовая', 2, 1.25, 0.63],
        ['Итого', 2, 1.25, 0.63],
    ]);
});

test('среднее потребление на половине сотой одинаково на экране и в XLSX', function (): void {
    $fixture = reportBuilderFixture();
    $street = Street::factory()->for($fixture['esil'])->create(['name' => 'Тестовая']);
    $client = reportBuilderClient($fixture['organization'], $fixture['utilityService'], [
        'account_number' => '300001',
        'region_id' => $fixture['esil']->id,
        'street_id' => $street->id,
    ]);

    // Five readings of 1 m³ and three of 0: 5 / 8 = 0.625, which ICU rounds half to even.
    foreach ([1, 1, 1, 1, 1, 0, 0, 0] as $index => $consumption) {
        $meter = Meter::factory()->for($fixture['organization'])->for($client)->create([
            'utility_service_id' => $fixture['utilityService']->id,
            'number' => 'T-'.$index,
            'initial_reading' => 0,
        ]);

        MeterReading::factory()->for($fixture['organization'])->for($meter)->create([
            'period' => '202606',
            'previous_reading' => 10,
            'current_reading' => 10 + $consumption,
            'read_at' => '2026-06-10',
        ]);
    }

    reportBuilderOperator($fixture['organization']);

    $fraction = fn (float $value): string => Number::format($value, 2, locale: config('app.locale'));

    $summary = Livewire::withQueryParams(['source' => 'readings', 'mode' => 'summary', 'group' => 'street'])
        ->test(BuildReport::class)
        ->filterTable('address', ['street_ids' => [$street->id]]);

    expect(reportBuilderSummary($summary))->toBe([
        ['group_label' => 'Есильский / Тестовая', 'is_total' => false, 'clients' => 1, 'count' => 8, 'sum' => 5, 'average' => 0.63],
        ['group_label' => 'Итого', 'is_total' => true, 'clients' => 1, 'count' => 8, 'sum' => 5, 'average' => 0.63],
    ]);

    $summary
        ->assertTableColumnFormattedStateSet('average', $fraction(0.63), 'group:'.$street->id)
        ->assertTableColumnFormattedStateSet('average', $fraction(0.63), 'total');

    expect(reportBuilderXlsxRows($summary->callAction('downloadXlsx')))->toBe([
        ['Улица', 'Абонентов', 'Показаний', 'Потребление', 'Среднее потребление'],
        ['Есильский / Тестовая', 1, 8, 5, 0.63],
        ['Итого', 1, 8, 5, 0.63],
    ]);

    $detail = Livewire::withQueryParams(['source' => 'readings', 'fields' => 'meter_number,consumption,daily_consumption'])
        ->test(BuildReport::class)
        ->filterTable('address', ['street_ids' => [$street->id]]);

    // 1 / 30 = 0.0333…: the screen and the file both show 0.03.
    $reading = MeterReading::query()->whereHas('meter', fn ($query) => $query->where('number', 'T-0'))->firstOrFail();

    $detail->assertTableColumnFormattedStateSet('daily_consumption', $fraction(0.03), $reading);

    expect(reportBuilderXlsxRows($detail->callAction('downloadXlsx'))[1])->toBe(['T-0', 1, 0.03]);
});

test('денежные и процентные ячейки оплат и начислений по-прежнему совпадают на экране и в XLSX', function (): void {
    $fixture = reportBuilderFixture();
    reportBuilderOperator($fixture['organization']);

    $money = fn (float $amount): string => Number::currency($amount, 'KZT', config('app.locale'));

    $payments = Livewire::withQueryParams(['mode' => 'summary', 'group' => 'region'])->test(BuildReport::class)
        ->assertTableColumnFormattedStateSet('average', $money(1166.67), 'group:'.$fixture['almalinsky']->id)
        ->assertTableColumnFormattedStateSet('sum', $money(3500), 'group:'.$fixture['almalinsky']->id);

    expect(reportBuilderXlsxRows($payments->callAction('downloadXlsx'))[1])->toBe(['Алмалинский', 2, 3, 3500, 1166.67]);

    $accruals = Livewire::withQueryParams(['source' => 'accruals', 'mode' => 'summary', 'group' => 'region'])->test(BuildReport::class)
        ->assertTableColumnFormattedStateSet('collection_percent', '80.00%', 'group:'.$fixture['almalinsky']->id)
        ->assertTableColumnFormattedStateSet('collection_percent', '95.00%', 'total')
        ->assertTableColumnFormattedStateSet('average', $money(1000), 'total');

    expect(collect(reportBuilderXlsxRows($accruals->callAction('downloadXlsx')))->last())->toBe(['Итого', 4, 4, 4000, 1000, 95]);
});
