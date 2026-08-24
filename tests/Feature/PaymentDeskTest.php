<?php

use App\BillingPeriodStatus;
use App\Filament\Pages\PaymentDesk;
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
 */
function paymentDeskDebtFromReading(Organization $organization, UtilityService $utilityService, BillingPeriod $billingPeriod, int $consumption): Client
{
    $client = paymentDeskClient($organization, $utilityService, ['billing_type' => 'meter']);

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

test('поиск короче двух символов ничего не возвращает', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskOperator($organization);

    paymentDeskClient($organization, $utilityService, ['name' => 'Иванов Иван']);

    $results = Livewire::test(PaymentDesk::class)
        ->set('search', 'И')
        ->instance()
        ->searchResults();

    expect($results)->toBeEmpty();
});

test('поиск не показывает абонентов другой организации', function (): void {
    ['organization' => $organization] = paymentDeskOrganization();
    billingPeriodFor($organization);

    // До установки тенанта: иначе фабрика подменит organization_id.
    $otherOrganization = Organization::factory()->create();
    $otherService = UtilityService::factory()->for($otherOrganization)->create();
    $foreignClient = paymentDeskClient($otherOrganization, $otherService, ['name' => 'Чужой Абонент']);

    paymentDeskOperator($organization);

    $results = Livewire::test(PaymentDesk::class)
        ->set('search', 'Чужой')
        ->instance()
        ->searchResults();

    expect($results)->toBeEmpty();

    // И выбрать его напрямую тоже нельзя: selectedClientId приходит от клиента.
    $page = Livewire::test(PaymentDesk::class)->call('selectClient', $foreignClient->id);

    expect($page->instance()->selectedClient())->toBeNull()
        ->and($page->get('selectedClientId'))->toBeNull();
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

    $balance = Livewire::test(PaymentDesk::class)
        ->call('selectClient', $client->id)
        ->instance()
        ->balance();

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

    $balance = Livewire::test(PaymentDesk::class)
        ->call('selectClient', $client->id)
        ->instance()
        ->balance();

    expect($balance['debt'])->toBe(0.0)
        ->and($balance['credit'])->toBe(500.0);
});

test('абонент без квитанции в открытом месяце всё равно виден с нулевым сальдо', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskOperator($organization);

    // Расчёт на человека: квитанции в открытом месяце нет вообще.
    $client = paymentDeskClient($organization, $utilityService, ['billing_type' => 'per_person']);

    $balance = Livewire::test(PaymentDesk::class)
        ->call('selectClient', $client->id)
        ->instance()
        ->balance();

    expect($balance['accrued'])->toBe(0.0)
        ->and($balance['debt'])->toBe(0.0);
});

// --- Приём оплаты -----------------------------------------------------------

test('приём оплаты создаёт запись и пересчитывает квитанцию', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    $operator = paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 30);

    Livewire::test(PaymentDesk::class)
        ->call('selectClient', $client->id)
        ->fillForm(['amount' => 1800, 'method' => PaymentMethod::Cash->value, 'paid_at' => today()->toDateString()])
        ->call('acceptPayment')
        ->assertHasNoFormErrors()
        ->assertNotified('Оплата принята')
        // После приёма страница снова готова к следующему человеку.
        ->assertSet('selectedClientId', null)
        ->assertSet('search', '');

    $payment = Payment::query()->where('client_id', $client->id)->firstOrFail();

    expect((float) $payment->amount)->toBe(1800.0)
        ->and($payment->method)->toBe(PaymentMethod::Cash)
        ->and((int) $payment->received_by_user_id)->toBe($operator->id)
        ->and((int) $payment->billing_period_id)->toBe($billingPeriod->id);

    $receipt = Receipt::query()->where('client_id', $client->id)->firstOrFail();

    expect((float) $receipt->paid_amount)->toBe(1800.0)
        ->and((float) $receipt->closing_balance)->toBe(1200.0);
});

test('кнопка «Вся сумма» подставляет долг', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskDebtFromReading($organization, $utilityService, $billingPeriod, 25);

    Livewire::test(PaymentDesk::class)
        ->call('selectClient', $client->id)
        ->call('fillFullDebt')
        ->assertSet('data.amount', '2500.00');
});

test('сумма обязательна и не может быть нулевой', function (mixed $amount): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskClient($organization, $utilityService);

    Livewire::test(PaymentDesk::class)
        ->call('selectClient', $client->id)
        ->fillForm(['amount' => $amount, 'method' => PaymentMethod::Cash->value, 'paid_at' => today()->toDateString()])
        ->call('acceptPayment')
        ->assertHasFormErrors(['amount']);

    expect(Payment::query()->count())->toBe(0);
})->with([[null], [0]]);

test('без выбранного абонента оплата не принимается', function (): void {
    ['organization' => $organization] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskOperator($organization);

    Livewire::test(PaymentDesk::class)
        ->fillForm(['amount' => 500, 'method' => PaymentMethod::Cash->value, 'paid_at' => today()->toDateString()])
        ->call('acceptPayment')
        ->assertNotified('Абонент не выбран');

    expect(Payment::query()->count())->toBe(0);
});

// --- Расчётный месяц --------------------------------------------------------

test('без открытого расчётного месяца оплата не принимается и приходит ошибка', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    paymentDeskOperator($organization);

    $client = paymentDeskClient($organization, $utilityService);

    Livewire::test(PaymentDesk::class)
        ->call('selectClient', $client->id)
        ->fillForm(['amount' => 500, 'method' => PaymentMethod::Cash->value, 'paid_at' => today()->toDateString()])
        ->call('acceptPayment')
        ->assertNotified('Оплата не принята');

    expect(Payment::query()->count())->toBe(0);
});

test('месяц закрытый после отрисовки страницы не теряет оплату молча', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    $billingPeriod = billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskClient($organization, $utilityService);

    $page = Livewire::test(PaymentDesk::class)->call('selectClient', $client->id);

    $billingPeriod->forceFill(['status' => BillingPeriodStatus::Closed, 'closed_at' => now()])->save();

    $page
        ->fillForm(['amount' => 500, 'method' => PaymentMethod::Cash->value, 'paid_at' => today()->toDateString()])
        ->call('acceptPayment')
        ->assertNotified('Оплата не принята');

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

test('оплату из Kaspi через провайдера исправить нельзя', function (): void {
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
        'external_provider' => 'xpayment',
        'external_payment_id' => 'xp-123',
    ]);

    Livewire::test(PaymentDesk::class)
        ->assertCanSeeTableRecords([$payment])
        ->assertActionHidden(TestAction::make(EditAction::getDefaultName())->table($payment))
        ->assertActionHidden(TestAction::make(DeleteAction::getDefaultName())->table($payment));
});
