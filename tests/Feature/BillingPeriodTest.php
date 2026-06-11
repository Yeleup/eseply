<?php

use App\Actions\CloseBillingMonth;
use App\BalanceAdjustmentType;
use App\BillingPeriodStatus;
use App\Filament\Resources\BillingPeriods\Pages\ListBillingPeriods;
use App\Models\BalanceAdjustment;
use App\Models\BillingPeriod;
use App\Models\BillingPeriodClosureError;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Models\UtilityService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsBillingPeriodTenant(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

test('closed billing period blocks mutable accounting records', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()->for($organization)->for($utilityService)->create();
    $meter = Meter::factory()->for($organization)->for($utilityService)->for($client)->create();
    $billingPeriod = BillingPeriod::factory()
        ->for($organization)
        ->period('202605')
        ->closed()
        ->create();

    expect(fn () => Payment::query()->create([
        'organization_id' => $organization->id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'amount' => 1000,
    ]))
        ->toThrow(ValidationException::class)
        ->and(fn () => BalanceAdjustment::query()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'billing_period_id' => $billingPeriod->id,
            'type' => BalanceAdjustmentType::ManualAdjustment->value,
            'amount' => 1000,
        ]))
        ->toThrow(ValidationException::class)
        ->and(fn () => MeterReading::query()->create([
            'meter_id' => $meter->id,
            'billing_period_id' => $billingPeriod->id,
            'current_reading' => 100,
        ]))
        ->toThrow(ValidationException::class);
});

test('closed billing period cannot be closed again', function () {
    $organization = Organization::factory()->create();
    $billingPeriod = BillingPeriod::factory()
        ->for($organization)
        ->period('202605')
        ->closed()
        ->create();

    expect(fn () => app(CloseBillingMonth::class)->handle($organization, $billingPeriod))
        ->toThrow(InvalidArgumentException::class, 'Расчётный месяц уже закрыт.');
});

test('new billing period cannot be opened until latest period is closed', function () {
    $organization = Organization::factory()->create();

    BillingPeriod::factory()
        ->for($organization)
        ->period('202605')
        ->create();

    expect(fn () => BillingPeriod::openFor($organization, '202606'))
        ->toThrow(ValidationException::class, 'Предыдущий расчётный месяц должен быть закрыт перед открытием нового.');
});

test('new billing period can be opened after latest period is closed', function () {
    $organization = Organization::factory()->create();

    BillingPeriod::factory()
        ->for($organization)
        ->period('202605')
        ->closed()
        ->create();

    $billingPeriod = BillingPeriod::openFor($organization, '202606');

    expect($billingPeriod->code)->toBe('202606')
        ->and($billingPeriod->status)->toBe(BillingPeriodStatus::Open);
});

test('new billing period must follow latest period without gaps', function () {
    $organization = Organization::factory()->create();

    BillingPeriod::factory()
        ->for($organization)
        ->period('202605')
        ->closed()
        ->create();

    expect(fn () => BillingPeriod::openFor($organization, '202607'))
        ->toThrow(ValidationException::class, 'Новый расчётный месяц должен идти сразу после последнего расчётного месяца.');
});

test('new billing period action opens next month after latest period is closed', function () {
    $organization = Organization::factory()->create();
    $user = actingAsBillingPeriodTenant($organization);

    BillingPeriod::factory()
        ->for($organization)
        ->period('202605')
        ->closed()
        ->create();

    Livewire::test(ListBillingPeriods::class)
        ->assertOk()
        ->assertActionExists('openNextBillingPeriod')
        ->callAction('openNextBillingPeriod')
        ->assertNotified();

    $billingPeriod = BillingPeriod::query()
        ->forOrganization($organization)
        ->forCode('202606')
        ->sole();

    expect($billingPeriod->status)->toBe(BillingPeriodStatus::Open)
        ->and($billingPeriod->opened_by_user_id)->toBe($user->id);
});

test('new billing period action does not open next month while latest period is not closed', function () {
    $organization = Organization::factory()->create();
    actingAsBillingPeriodTenant($organization);

    BillingPeriod::factory()
        ->for($organization)
        ->period('202605')
        ->create();

    Livewire::test(ListBillingPeriods::class)
        ->assertOk()
        ->callAction('openNextBillingPeriod')
        ->assertNotified();

    expect(BillingPeriod::query()
        ->forOrganization($organization)
        ->forCode('202606')
        ->exists())->toBeFalse();
});

test('failed billing period can be closed after data is fixed', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'fixed',
            'fixed_amount' => 5000,
        ]);
    $billingPeriod = BillingPeriod::factory()
        ->for($organization)
        ->period('202605')
        ->create([
            'status' => BillingPeriodStatus::Failed,
            'failed_at' => now(),
            'failure_message' => 'Не все активные абоненты были рассчитаны.',
            'failed_clients_count' => 1,
        ]);
    BillingPeriodClosureError::factory()
        ->for($organization)
        ->for($billingPeriod)
        ->for($client)
        ->create([
            'code' => 'missing_fixed_amount',
            'message' => 'Не указана фиксированная сумма.',
        ]);

    $summary = app(CloseBillingMonth::class)->handle($organization, $billingPeriod);

    expect($summary)->toMatchArray([
        'active' => 1,
        'created' => 1,
        'failed' => 0,
    ])
        ->and($billingPeriod->refresh()->status)->toBe(BillingPeriodStatus::Closed)
        ->and($billingPeriod->closed_at)->not->toBeNull()
        ->and($billingPeriod->closureErrors()->count())->toBe(0);
});

test('failed billing period shows closure error report action', function () {
    $organization = Organization::factory()->create();
    actingAsBillingPeriodTenant($organization);

    $billingPeriod = BillingPeriod::factory()
        ->for($organization)
        ->period('202605')
        ->create([
            'status' => BillingPeriodStatus::Failed,
            'failed_at' => now(),
            'failure_message' => 'Не все активные абоненты были рассчитаны.',
            'failed_clients_count' => 1,
        ]);

    BillingPeriodClosureError::factory()
        ->for($organization)
        ->for($billingPeriod)
        ->create([
            'account_number' => '80502',
            'client_name' => 'Без суммы',
            'billing_type' => 'fixed',
            'code' => 'missing_fixed_amount',
            'message' => 'Не указана фиксированная сумма.',
        ]);

    Livewire::test(ListBillingPeriods::class)
        ->assertOk()
        ->assertTableActionVisible('closureErrors', $billingPeriod)
        ->mountTableAction('closureErrors', $billingPeriod)
        ->assertMountedActionModalSee('80502')
        ->assertMountedActionModalSee('Без суммы')
        ->assertMountedActionModalSee('Не указана фиксированная сумма.')
        ->assertMountedActionModalSee('missing_fixed_amount');
});
