<?php

use App\BalanceAdjustmentType;
use App\BillingPeriodStatus;
use App\Filament\Pages\PaymentDesk;
use App\Models\BalanceAdjustment;
use App\Models\BillingPeriod;
use App\Models\Client;
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
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{organization: Organization, utilityService: UtilityService}
 */
function paymentDeskOrganization(): array
{
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    Tariff::factory()->for($organization)->for($utilityService)->create([
        'unit_price' => 100,
        'per_person_price' => 500,
        'starts_on' => '2020-01-01',
        'status' => 'active',
    ]);

    return compact('organization', 'utilityService');
}

function paymentDeskActingAs(User $user, Organization $organization): User
{
    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

function paymentDeskOperator(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, [
        'role' => OrganizationMemberRole::Operator->value,
    ]);

    return paymentDeskActingAs($user, $organization);
}

function paymentDeskController(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, [
        'role' => OrganizationMemberRole::Controller->value,
    ]);

    return paymentDeskActingAs($user, $organization);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function paymentDeskClient(Organization $organization, UtilityService $utilityService, array $attributes = []): Client
{
    return Client::factory()->for($organization)->for($utilityService)->create([
        'status' => 'active',
        'billing_type' => 'per_person',
        ...$attributes,
    ]);
}

/**
 * Начисление в открытом месяце появляется через квитанцию, а квитанция — при
 * вводе показания. Поэтому долг «по счётчику» заводится именно так.
 *
 * @param  array<string, mixed>  $attributes
 */
function paymentDeskDebtFromReading(Organization $organization, UtilityService $utilityService, BillingPeriod $billingPeriod, int $consumption, array $attributes = []): Client
{
    $client = paymentDeskClient($organization, $utilityService, ['billing_type' => 'meter', ...$attributes]);

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

    return $client;
}

function paymentDeskPay(Client $client): TestAction
{
    return TestAction::make('pay')->arguments(['client' => $client->id]);
}

/**
 * Rows of the search list in the order the arrows move over them.
 *
 * @return array<int, string>
 */
function paymentDeskRows(string $html): array
{
    preg_match_all('/<li\b[^>]*\bdata-payment-desk-row="\d+".*?<\/li>/s', $html, $matches);

    return $matches[0];
}

/**
 * The key of the search list: a new key is a new list, and the highlight starts over.
 */
function paymentDeskResultsKey(string $html): ?string
{
    return preg_match('/wire:key="(payment-desk-results-[^"]+)"/', $html, $matches) === 1 ? $matches[1] : null;
}

/**
 * What the «Оплатить» button of a row calls; Enter in the search presses that button.
 */
function paymentDeskPayClick(Client $client): string
{
    return "mountAction('pay', ".Js::from(['client' => $client->id]).')';
}

/**
 * Баланс абонента так, как его видит касса: строкой найденного списка.
 *
 * @return array{opening: float, accrued: float, paid: float, adjustment: float, debt: float, credit: float}
 */
function paymentDeskBalance(Client $client): array
{
    $page = Livewire::test(PaymentDesk::class)
        ->set('search', $client->account_number)
        ->instance();

    return $page->balanceOf($page->searchResults()->firstWhere('id', $client->id));
}

// --- Доступ -----------------------------------------------------------------

test('оператор открывает страницу приёма оплат', function (): void {
    ['organization' => $organization] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $this->get(PaymentDesk::getUrl(tenant: $organization))->assertSuccessful();

    Livewire::test(PaymentDesk::class)->assertOk()->assertSee('Поиск абонента');
});

test('контролёру страница приёма оплат недоступна', function (): void {
    ['organization' => $organization] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskController($organization);

    expect(PaymentDesk::canAccess())->toBeFalse();

    $this->get(PaymentDesk::getUrl(tenant: $organization))->assertForbidden();
});

test('страница чужого тенанта не открывается', function (): void {
    ['organization' => $organization] = paymentDeskOrganization();
    paymentDeskOperator($organization);

    $otherOrganization = Organization::factory()->create();

    $this->get(PaymentDesk::getUrl(tenant: $otherOrganization))->assertNotFound();
});
// --- Поиск ------------------------------------------------------------------

test('поиск находит абонента по лицевому счёту, фамилии и телефону', function (string $field): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskClient($organization, $utilityService, [
        'name' => 'Иванов Иван',
        'phone' => '+7 701 000 11 22',
    ]);

    $term = match ($field) {
        'account_number' => $client->account_number,
        'name' => 'Иванов',
        'phone' => '701 000',
    };

    $results = Livewire::test(PaymentDesk::class)
        ->set('search', $term)
        ->instance()
        ->searchResults();

    expect($results->pluck('id')->all())->toBe([$client->id]);
})->with(['account_number', 'name', 'phone']);

test('поиск короче двух символов ничего не возвращает и просит ввести больше', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskOperator($organization);

    paymentDeskClient($organization, $utilityService, ['name' => 'Иванов Иван']);

    $page = Livewire::test(PaymentDesk::class)
        ->set('search', 'И')
        ->assertSee('Введите минимум 2 символа')
        ->assertDontSee('Ничего не найдено');

    expect($page->instance()->searchResults())->toBeEmpty();
});

test('пустой результат поиска показывает «Ничего не найдено»', function (): void {
    ['organization' => $organization] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskOperator($organization);

    Livewire::test(PaymentDesk::class)
        ->set('search', 'Несуществующий')
        ->assertSee('Ничего не найдено')
        ->assertDontSee('Введите минимум 2 символа');
});

test('поиск не показывает абонентов другой организации', function (): void {
    ['organization' => $organization] = paymentDeskOrganization();
    billingPeriodFor($organization);

    // До установки тенанта: иначе фабрика подменит organization_id.
    $otherOrganization = Organization::factory()->create();
    $otherService = UtilityService::factory()->for($otherOrganization)->create();
    paymentDeskClient($otherOrganization, $otherService, ['name' => 'Чужой Абонент']);

    paymentDeskOperator($organization);

    $results = Livewire::test(PaymentDesk::class)
        ->set('search', 'Чужой')
        ->instance()
        ->searchResults();

    expect($results)->toBeEmpty();
});

test('строка поиска не выбирает абонента: единственное действие — «Оплатить»', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30, ['name' => 'Должников Дмитрий']);

    Livewire::test(PaymentDesk::class)
        ->set('search', 'Должников')
        ->assertSee('Должников Дмитрий')
        ->assertDontSeeHtml('selectClient')
        ->assertActionVisible(paymentDeskPay($client));

    expect(method_exists(PaymentDesk::class, 'selectClient'))->toBeFalse()
        ->and(property_exists(PaymentDesk::class, 'selectedClientId'))->toBeFalse();
});

// --- Долг -------------------------------------------------------------------

test('долг считается по начислению и оплатам открытого месяца', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    // 30 * 100 = 3000 начислено.
    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30);

    Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'amount' => 1200,
        'method' => PaymentMethod::Cash->value,
    ]);

    $balance = paymentDeskBalance($client);

    expect($balance['accrued'])->toBe(3000.0)
        ->and($balance['paid'])->toBe(1200.0)
        ->and($balance['debt'])->toBe(1800.0)
        ->and($balance['credit'])->toBe(0.0);
});

test('переплата показывается отдельно от долга', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 10);

    Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'amount' => 1500,
        'method' => PaymentMethod::Cash->value,
    ]);

    $balance = paymentDeskBalance($client);

    expect($balance['debt'])->toBe(0.0)
        ->and($balance['credit'])->toBe(500.0);
});

test('абонент без квитанции в открытом месяце всё равно виден с нулевым сальдо', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskOperator($organization);

    // Расчёт на человека: квитанции в открытом месяце нет вообще.
    $client = paymentDeskClient($organization, $utilityService, ['billing_type' => 'per_person']);

    $balance = paymentDeskBalance($client);

    expect($balance['accrued'])->toBe(0.0)
        ->and($balance['debt'])->toBe(0.0);
});

// --- Кнопка «Оплатить» ------------------------------------------------------

test('кнопка «Оплатить» есть только у абонента с долгом', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $debtor = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30, ['name' => 'Должников Дмитрий']);
    $settled = paymentDeskClient($organization, $utilityService, ['name' => 'Расчётов Роман']);
    $overpaid = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 10, ['name' => 'Переплатов Пётр']);

    Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $overpaid->id,
        'billing_period_id' => $billingPeriod->id,
        'amount' => 1500,
        'method' => PaymentMethod::Cash->value,
    ]);

    Livewire::test(PaymentDesk::class)
        ->set('search', 'Должников')
        ->assertSee('Долг 3 000,00 ₸')
        ->assertSee('Оплатить')
        ->set('search', 'Расчётов')
        ->assertSee('Долга нет')
        ->assertDontSee('Оплатить')
        ->set('search', 'Переплатов')
        ->assertSee('Переплата 500,00 ₸')
        ->assertDontSee('Оплатить');

    // Одна и та же проверка для строки и для открытия модалки.
    $page = Livewire::test(PaymentDesk::class)->set('search', 'ов');
    $rows = $page->instance()->searchResults()->keyBy('id');

    expect($rows)->toHaveCount(3)
        ->and($page->instance()->offersPayment($rows[$debtor->id]))->toBeTrue()
        ->and($page->instance()->offersPayment($rows[$settled->id]))->toBeFalse()
        ->and($page->instance()->offersPayment($rows[$overpaid->id]))->toBeFalse();
});

test('модалка оплаты не открывается у абонента без долга или с переплатой', function (string $state): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = match ($state) {
        'settled' => paymentDeskClient($organization, $utilityService),
        'overpaid' => paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 10),
    };

    if ($state === 'overpaid') {
        Payment::query()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'billing_period_id' => $billingPeriod->id,
            'amount' => 1500,
            'method' => PaymentMethod::Cash->value,
        ]);
    }

    Livewire::test(PaymentDesk::class)
        ->set('search', $client->account_number)
        ->mountAction(paymentDeskPay($client))
        ->assertActionNotMounted()
        ->assertNotified('Долга нет');
})->with(['settled', 'overpaid']);

test('без открытого расчётного месяца кнопки «Оплатить» нет и модалка не открывается', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30, ['name' => 'Должников Дмитрий']);

    $billingPeriod->forceFill(['status' => BillingPeriodStatus::Closed, 'closed_at' => now()])->save();

    Livewire::test(PaymentDesk::class)
        ->set('search', 'Должников')
        ->assertSee('Должников Дмитрий')
        ->assertDontSee('Оплатить')
        ->assertSee('Расчётный месяц не открыт: приём оплат недоступен.')
        ->mountAction(paymentDeskPay($client))
        ->assertActionNotMounted()
        ->assertNotified('Расчётный месяц не открыт');
});

test('строки поиска не делают запрос на каждого абонента', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    foreach (range(1, 5) as $index) {
        paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 10 * $index, ['name' => 'Должников '.$index]);
    }

    $page = Livewire::test(PaymentDesk::class);

    DB::enableQueryLog();
    $page->set('search', 'Должников 1');
    $singleRowQueries = count(DB::getQueryLog());

    DB::flushQueryLog();
    $page->set('search', 'Должников')->assertSee('Должников 5');
    $fiveRowQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect(substr_count($page->html(), 'Оплатить'))->toBe(5)
        ->and($fiveRowQueries)->toBe($singleRowQueries);
});

// --- Модалка оплаты ---------------------------------------------------------

test('модалка оплаты показывает карточку абонента, сальдо и пустую форму', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30, [
        'name' => 'Должников Дмитрий',
        'phone' => '+7 701 555 44 33',
        'residents_count' => 4,
    ]);

    Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'amount' => 700,
        'method' => PaymentMethod::Cash->value,
        'paid_at' => '2026-05-12',
    ]);

    BalanceAdjustment::factory()->for($organization)->for($client)->create([
        'period' => $billingPeriod->period,
        'type' => BalanceAdjustmentType::ManualAdjustment->value,
        'amount' => 200,
    ]);

    Livewire::test(PaymentDesk::class)
        ->set('search', 'Должников')
        ->mountAction(paymentDeskPay($client))
        ->assertActionMounted(paymentDeskPay($client))
        ->assertMountedActionModalSee([
            $client->account_number.' — Должников Дмитрий',
            'Расчётный месяц: '.$billingPeriod->label,
            'Карточка абонента',
            '+7 701 555 44 33',
            'Проживающих',
            $utilityService->name,
            '12.05.2026 — 700,00 ₸',
            'Сальдо на начало',
            'Начислено',
            '3 000,00 ₸',
            'Оплачено',
            'Корректировки',
            '200,00 ₸',
            'К оплате',
            // 3000 − 700 + 200.
            '2 500,00 ₸',
            'Принять оплату',
            'Отмена',
        ])
        ->assertSchemaStateSet([
            'amount' => null,
            'method' => PaymentMethod::Cash,
            'paid_at' => today()->toDateString(),
            'note' => null,
        ]);
});

test('без корректировок плитки «Корректировки» в модалке нет', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30);

    Livewire::test(PaymentDesk::class)
        ->set('search', $client->account_number)
        ->mountAction(paymentDeskPay($client))
        ->assertMountedActionModalSee(['К оплате', '3 000,00 ₸', 'Оплат ещё не было'])
        ->assertMountedActionModalDontSee('Корректировки');
});

test('кнопка «Вся сумма», F4 и Enter на пустой сумме подставляют один и тот же долг', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 25);

    // F4 и Enter на пустой сумме подставляют долг в браузере, из атрибута поля суммы.
    Livewire::test(PaymentDesk::class)
        ->set('search', $client->account_number)
        ->mountAction(paymentDeskPay($client))
        ->assertMountedActionModalSeeHtml('data-payment-desk-full-debt="2500.00"')
        ->callAction(TestAction::make('fillFullDebt')->schemaComponent('amount'))
        ->assertActionMounted(paymentDeskPay($client))
        ->assertSchemaStateSet(['amount' => '2500.00']);
});

// --- Приём оплаты -----------------------------------------------------------

test('приём оплаты через модалку создаёт запись и пересчитывает квитанцию', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    $operator = paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30);

    Livewire::test(PaymentDesk::class)
        ->set('search', $client->account_number)
        ->callAction(paymentDeskPay($client), [
            'amount' => 1800,
            'method' => PaymentMethod::Cash->value,
            'paid_at' => today()->toDateString(),
            'note' => 'Оплата у окна',
        ])
        ->assertHasNoFormErrors()
        ->assertActionNotMounted()
        ->assertNotified('Оплата принята')
        ->assertDispatched('payment-desk-reset');

    $payment = Payment::query()->where('client_id', $client->id)->firstOrFail();

    expect((float) $payment->amount)->toBe(1800.0)
        ->and($payment->method)->toBe(PaymentMethod::Cash)
        ->and($payment->note)->toBe('Оплата у окна')
        ->and((int) $payment->organization_id)->toBe($organization->id)
        ->and((int) $payment->received_by_user_id)->toBe($operator->id)
        ->and((int) $payment->billing_period_id)->toBe($billingPeriod->id);

    $receipt = Receipt::query()->where('client_id', $client->id)->firstOrFail();

    expect((float) $receipt->paid_amount)->toBe(1800.0)
        ->and((float) $receipt->closing_balance)->toBe(1200.0);
});

test('после приёма оплаты поиск и список остаются, а строка показывает новый долг', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30, ['name' => 'Должников Дмитрий']);
    $neighbour = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 20, ['name' => 'Должникова Дарья']);

    $page = Livewire::test(PaymentDesk::class)
        ->set('search', 'Должников')
        ->callAction(paymentDeskPay($client), [
            'amount' => 1000,
            'method' => PaymentMethod::Cash->value,
            'paid_at' => today()->toDateString(),
        ])
        ->assertHasNoFormErrors()
        ->assertSet('search', 'Должников')
        ->assertSee('Долг 2 000,00 ₸')
        ->assertSee('Должникова Дарья')
        ->assertActionVisible(paymentDeskPay($client));

    expect($page->instance()->searchResults()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$client->id, $neighbour->id])->sort()->values()->all());

    $payment = Payment::query()->where('client_id', $client->id)->firstOrFail();

    $page
        ->assertCanSeeTableRecords([$payment])
        ->assertSee('Оплат за сегодня')
        ->assertSee('1 000,00 ₸');

    expect($page->instance()->todayTotals())->toBe(['count' => 1, 'amount' => 1000.0]);
});

test('после полной оплаты строка показывает «Долга нет», а кнопка «Оплатить» исчезает', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30, ['name' => 'Должников Дмитрий']);

    Livewire::test(PaymentDesk::class)
        ->set('search', 'Должников')
        ->assertSee('Оплатить')
        ->callAction(paymentDeskPay($client), [
            'amount' => 3000,
            'method' => PaymentMethod::Cash->value,
            'paid_at' => today()->toDateString(),
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Оплата принята')
        ->assertSet('search', 'Должников')
        ->assertSee('Должников Дмитрий')
        ->assertSee('Долга нет')
        ->assertDontSee('Оплатить');
});

test('новая оплата первой появляется в ленте за сегодня', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30);

    $earlier = Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'amount' => 100,
        'method' => PaymentMethod::Cash->value,
    ]);

    $page = Livewire::test(PaymentDesk::class)
        ->set('search', $client->account_number)
        ->callAction(paymentDeskPay($client), [
            'amount' => 500,
            'method' => PaymentMethod::Kaspi->value,
            'paid_at' => today()->toDateString(),
        ])
        ->assertHasNoFormErrors();

    $latest = Payment::query()->where('client_id', $client->id)->latest('id')->firstOrFail();

    $page->assertCanSeeTableRecords([$latest, $earlier], inOrder: true);

    expect($page->instance()->todayTotals())->toBe(['count' => 2, 'amount' => 600.0]);
});

test('кассир принимает оплату способом Kaspi вручную и может её исправить', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30);

    Livewire::test(PaymentDesk::class)
        ->set('search', $client->account_number)
        ->callAction(paymentDeskPay($client), [
            'amount' => 1500,
            'method' => PaymentMethod::Kaspi->value,
            'paid_at' => today()->toDateString(),
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Оплата принята');

    $payment = Payment::query()->where('client_id', $client->id)->firstOrFail();

    expect($payment->method)->toBe(PaymentMethod::Kaspi)
        ->and($payment->method->getLabel())->toBe('Kaspi')
        ->and((float) Receipt::query()->where('client_id', $client->id)->value('paid_amount'))->toBe(1500.0);

    Livewire::test(PaymentDesk::class)
        ->assertCanSeeTableRecords([$payment])
        ->assertActionVisible(TestAction::make(EditAction::getDefaultName())->table($payment))
        ->assertActionVisible(TestAction::make(DeleteAction::getDefaultName())->table($payment));
});

test('сумма обязательна и не может быть нулевой: модалка остаётся открытой', function (mixed $amount): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30);

    Livewire::test(PaymentDesk::class)
        ->set('search', $client->account_number)
        ->callAction(paymentDeskPay($client), [
            'amount' => $amount,
            'method' => PaymentMethod::Cash->value,
            'paid_at' => today()->toDateString(),
        ])
        ->assertHasFormErrors(['amount'])
        ->assertActionMounted(paymentDeskPay($client))
        ->assertNotNotified('Оплата принята')
        ->assertSet('search', $client->account_number);

    expect(Payment::query()->count())->toBe(0);
})->with([[null], [0]]);

test('абонента другой организации нельзя оплатить через действие', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);

    // До установки тенанта: иначе фабрика подменит organization_id.
    $otherOrganization = Organization::factory()->create();
    $otherService = UtilityService::factory()->for($otherOrganization)->create();
    $otherPeriod = billingPeriodFor($otherOrganization);
    $foreignClient = paymentDeskDebtFromReading($otherOrganization, $otherService, $otherPeriod, 30);

    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30);

    Livewire::test(PaymentDesk::class)
        ->assertActionHidden(paymentDeskPay($foreignClient))
        ->mountAction(paymentDeskPay($foreignClient))
        ->assertActionNotMounted();

    // Идентификатор абонента приходит из браузера и может быть подменён
    // уже в открытой модалке.
    Livewire::test(PaymentDesk::class)
        ->set('search', $client->account_number)
        ->mountAction(paymentDeskPay($client))
        ->set('mountedActions.0.arguments.client', $foreignClient->id)
        ->fillForm([
            'amount' => 500,
            'method' => PaymentMethod::Cash->value,
            'paid_at' => today()->toDateString(),
        ])
        ->callMountedAction()
        ->assertNotNotified('Оплата принята');

    expect(Payment::query()->count())->toBe(0);
});

// --- Расчётный месяц --------------------------------------------------------

test('без открытого расчётного месяца оплата не принимается и приходит ошибка', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    paymentDeskOperator($organization);

    $client = paymentDeskClient($organization, $utilityService);

    Livewire::test(PaymentDesk::class)
        ->set('search', $client->account_number)
        ->assertDontSee('Оплатить')
        ->mountAction(paymentDeskPay($client))
        ->assertActionNotMounted()
        ->assertNotified('Расчётный месяц не открыт');

    // Даже подставленная в состояние модалка не сохраняет оплату без месяца.
    Livewire::test(PaymentDesk::class)
        ->set('mountedActions', [[
            'name' => 'pay',
            'arguments' => ['client' => $client->id],
            'context' => [],
            'data' => [
                'amount' => 500,
                'method' => PaymentMethod::Cash->value,
                'paid_at' => today()->toDateString(),
                'note' => null,
            ],
        ]])
        ->callMountedAction()
        ->assertNotified('Оплата не принята');

    expect(Payment::query()->count())->toBe(0);
});

test('месяц закрытый после открытия модалки не теряет оплату молча', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30);

    $page = Livewire::test(PaymentDesk::class)
        ->set('search', $client->account_number)
        ->mountAction(paymentDeskPay($client));

    $billingPeriod->forceFill(['status' => BillingPeriodStatus::Closed, 'closed_at' => now()])->save();

    $page
        ->fillForm([
            'amount' => 500,
            'method' => PaymentMethod::Cash->value,
            'paid_at' => today()->toDateString(),
        ])
        ->callMountedAction()
        ->assertNotified('Оплата не принята')
        // Деньги уже в руках, поэтому модалка с введённой суммой остаётся открытой.
        ->assertActionMounted(paymentDeskPay($client))
        ->assertSchemaStateSet(['amount' => 500]);

    expect(Payment::query()->count())->toBe(0);
});

// --- Лента за сегодня -------------------------------------------------------

test('лента показывает оплаты за сегодня и не показывает вчерашние', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskClient($organization, $utilityService);

    $today = Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'amount' => 700,
        'method' => PaymentMethod::Cash->value,
    ]);

    $yesterday = Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'amount' => 900,
        'method' => PaymentMethod::Cash->value,
    ]);
    $yesterday->forceFill(['created_at' => now()->subDay()])->saveQuietly();

    Livewire::test(PaymentDesk::class)
        ->assertCanSeeTableRecords([$today])
        ->assertCanNotSeeTableRecords([$yesterday]);

    $totals = Livewire::test(PaymentDesk::class)->instance()->todayTotals();

    expect($totals['count'])->toBe(1)
        ->and($totals['amount'])->toBe(700.0);
});

test('ручную оплату можно исправить и удалить', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30);

    $payment = Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'amount' => 500,
        'method' => PaymentMethod::Cash->value,
    ]);

    Livewire::test(PaymentDesk::class)
        ->callAction(TestAction::make(EditAction::getDefaultName())->table($payment), [
            'amount' => 800,
            'method' => PaymentMethod::Cash->value,
            'paid_at' => today()->toDateString(),
        ]);

    expect((float) $payment->refresh()->amount)->toBe(800.0)
        ->and((float) Receipt::query()->where('client_id', $client->id)->value('paid_amount'))->toBe(800.0);

    Livewire::test(PaymentDesk::class)
        ->callAction(TestAction::make(DeleteAction::getDefaultName())->table($payment));

    expect(Payment::query()->whereKey($payment->id)->exists())->toBeFalse()
        ->and((float) Receipt::query()->where('client_id', $client->id)->value('paid_amount'))->toBe(0.0);
});

test('оплату, записанную внешним провайдером, исправить нельзя', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskClient($organization, $utilityService);

    $payment = Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'amount' => 1000,
        'method' => PaymentMethod::Kaspi->value,
        'external_provider' => 'legacy-provider',
        'external_payment_id' => 'ext-123',
    ]);

    Livewire::test(PaymentDesk::class)
        ->assertCanSeeTableRecords([$payment])
        ->assertActionHidden(TestAction::make(EditAction::getDefaultName())->table($payment))
        ->assertActionHidden(TestAction::make(DeleteAction::getDefaultName())->table($payment));
});

// --- Клавиатура -------------------------------------------------------------

test('Enter в поиске нажимает «Оплатить» выделенной строки: тот же payAction с тем же абонентом', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $first = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30, [
        'name' => 'Должников Дмитрий',
        'account_number' => '7018340',
    ]);
    $second = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 20, [
        'name' => 'Должникова Дарья',
        'account_number' => '7018341',
    ]);

    $page = Livewire::test(PaymentDesk::class)->set('search', 'Должников');
    $rows = paymentDeskRows($page->html());

    // Стрелки ходят по строкам в этом порядке; номер строки — то, что выделяет Alpine.
    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toContain('data-payment-desk-row="0"')
        ->and($rows[0])->toContain('data-payment-desk-account="7018340"')
        ->and($rows[0])->toContain('data-payment-desk-pay-button')
        ->and($rows[0])->toContain(paymentDeskPayClick($first))
        ->and($rows[0])->not->toContain(paymentDeskPayClick($second))
        ->and($rows[1])->toContain('data-payment-desk-row="1"')
        ->and($rows[1])->toContain('data-payment-desk-account="7018341"')
        ->and($rows[1])->toContain(paymentDeskPayClick($second));

    // Нажатая клавишей кнопка открывает ту же модалку, что и мышью.
    $page
        ->mountAction(paymentDeskPay($second))
        ->assertActionMounted(paymentDeskPay($second))
        ->assertMountedActionModalSee('7018341 — Должникова Дарья');
});

test('у абонента без долга Enter не открывает приём, а подсказка объясняет почему', function (string $state): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = match ($state) {
        'settled' => paymentDeskClient($organization, $utilityService, ['name' => 'Расчётов Роман']),
        'overpaid' => paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 10, ['name' => 'Расчётов Роман']),
    };

    if ($state === 'overpaid') {
        Payment::query()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'billing_period_id' => $billingPeriod->id,
            'amount' => 1500,
            'method' => PaymentMethod::Cash->value,
        ]);
    }

    $page = Livewire::test(PaymentDesk::class)->set('search', 'Расчётов');
    $rows = paymentDeskRows($page->html());

    // Enter нажимает кнопку строки, а у такой строки кнопки нет.
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toContain('data-payment-desk-account="'.$client->account_number.'"')
        ->and($rows[0])->not->toContain('data-payment-desk-pay-button');

    $page
        ->assertSeeHtml('data-payment-desk-billing-open="1"')
        ->assertSee('долга нет, приём оплаты не требуется.')
        ->mountAction(paymentDeskPay($client))
        ->assertActionNotMounted()
        ->assertNotified('Долга нет');
})->with(['settled', 'overpaid']);

test('без открытого расчётного месяца Enter не принимает оплату, и подсказки говорят почему', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30, ['name' => 'Должников Дмитрий']);

    $billingPeriod->forceFill(['status' => BillingPeriodStatus::Closed, 'closed_at' => now()])->save();

    $page = Livewire::test(PaymentDesk::class)->set('search', 'Должников');
    $rows = paymentDeskRows($page->html());

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->not->toContain('data-payment-desk-pay-button');

    $page
        ->assertSeeHtml('data-payment-desk-billing-open="0"')
        ->assertSee('не принимает оплату: расчётный месяц не открыт')
        ->assertSee('Расчётный месяц не открыт: приём оплат недоступен.')
        ->mountAction(paymentDeskPay($client))
        ->assertActionNotMounted();
});

test('после приёма оплаты список тот же: выделение остаётся, а странице передаётся счёт для подсказки', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30, [
        'name' => 'Должников Дмитрий',
        'account_number' => '7018340',
    ]);
    $second = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 20, [
        'name' => 'Должникова Дарья',
        'account_number' => '7018341',
    ]);

    $page = Livewire::test(PaymentDesk::class)->set('search', 'Должников');
    $listBefore = paymentDeskResultsKey($page->html());

    $page
        ->callAction(paymentDeskPay($second), [
            'amount' => 500,
            'method' => PaymentMethod::Cash->value,
            'paid_at' => today()->toDateString(),
        ])
        ->assertHasNoFormErrors()
        ->assertDispatched('payment-desk-reset', account: '7018341')
        ->assertSet('search', 'Должников')
        ->assertSee('Долг 1 500,00 ₸');

    $rows = paymentDeskRows($page->html());

    expect($listBefore)->not->toBeNull()
        ->and(paymentDeskResultsKey($page->html()))->toBe($listBefore)
        ->and($rows[1])->toContain('data-payment-desk-account="7018341"')
        ->and($rows[1])->toContain(paymentDeskPayClick($second));
});

test('новый текст поиска — новый список, и выделение снова на первой строке', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30, ['name' => 'Должников Дмитрий']);
    paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 20, ['name' => 'Должникова Дарья']);

    $page = Livewire::test(PaymentDesk::class)->set('search', 'Должников');
    $wideList = paymentDeskResultsKey($page->html());

    $page->set('search', 'Должникова');
    $narrowList = paymentDeskResultsKey($page->html());

    $page->set('search', 'Должников');

    expect($wideList)->not->toBeNull()
        ->and($narrowList)->not->toBeNull()
        ->and($narrowList)->not->toBe($wideList)
        ->and(paymentDeskResultsKey($page->html()))->toBe($wideList)
        ->and($page->html())->toContain('x-init="activeRow = 0"');
});

test('страница показывает строку клавиш, панель «Горячие клавиши» и место для подсказок', function (): void {
    ['organization' => $organization] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $page = Livewire::test(PaymentDesk::class)
        ->assertActionVisible('hotkeys')
        ->assertSeeHtml('aria-controls="payment-desk-hotkeys"')
        ->assertSeeHtml('aria-describedby="payment-desk-search-keys"')
        ->assertSeeInOrder(['выбрать', 'оплатить', 'стереть выделенный счёт', 'очистить', 'все клавиши'])
        ->assertSeeInOrder([
            'Горячие клавиши',
            'В поиске',
            'следующий, предыдущий абонент',
            'оплатить выделенного абонента',
            'очистить поиск',
            'показать, скрыть эту справку',
            'В окне приёма',
            'пустая сумма — подставить весь долг, иначе принять оплату',
            'вся сумма долга',
            'сумма, способ оплаты, дата, примечание',
            'принять из примечания',
            'отмена, курсор снова в поиске',
        ])
        ->assertSeeHtml('aria-live="polite"')
        ->assertSee('выделен целиком:')
        ->assertSee('стирает всё одним нажатием, или просто набирайте следующий счёт поверх.');

    $html = $page->html();

    // Панель — обычная секция с заголовком, а клавиши — чипы <kbd>.
    expect($html)->toMatch('/<section[^>]*id="payment-desk-hotkeys"/')
        ->and($html)->toMatch('/<kbd[^>]*>F1<\/kbd>/u')
        ->and($html)->toMatch('/<kbd[^>]*>Esc<\/kbd>/u')
        ->and($html)->toMatch('/<kbd[^>]*>↓<\/kbd>/u');
});

test('модалка приёма показывает клавиши и принимает Enter, F4 и Ctrl+Enter в своих полях', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30);

    $page = Livewire::test(PaymentDesk::class)
        ->set('search', $client->account_number)
        ->mountAction(paymentDeskPay($client))
        ->assertMountedActionModalSee(['подставить весь долг', 'с суммой — принять оплату', 'принять из примечания', 'отмена', 'поля'])
        ->assertMountedActionModalSeeHtml([
            'data-payment-desk-pay="data-payment-desk-pay" x-on:keydown="onPayModalKeydown($event)"',
            'data-payment-desk-amount="data-payment-desk-amount"',
            'placeholder="Необязательно. Ctrl+Enter — принять оплату"',
        ]);

    $html = $page->getMountedActionModalHtml();

    // Способ и дата — поля браузера: Enter в них принимает оплату, а не раскрывает список.
    expect($html)->toMatch('/<select[^>]*wire:model="mountedActions\.0\.data\.method"/')
        ->and($html)->toMatch('/<input[^>]*type="date"[^>]*wire:model="mountedActions\.0\.data\.paid_at"/')
        ->and($html)->toMatch('/Принять оплату\s*<kbd[^>]*>Enter<\/kbd>/u')
        ->and($html)->toMatch('/Вся сумма\s*<kbd[^>]*>F4<\/kbd>/u');
});
