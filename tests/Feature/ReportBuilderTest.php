<?php

use App\ClientType;
use App\Filament\Pages\Reports\BuildReport;
use App\Filament\Pages\Reports\ListReports;
use App\Models\Accrual;
use App\Models\BillingPeriod;
use App\Models\City;
use App\Models\Client;
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
        ->call('selectSource', 'clients')
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
