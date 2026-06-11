<?php

use App\Actions\CloseBillingMonth;
use App\BalanceAdjustmentType;
use App\Filament\Resources\Payments\Pages\CreatePayment;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Accrual;
use App\Models\BalanceAdjustment;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Models\UtilityService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsPaymentTenant(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

test('payments belong to an organization and client', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create();

    $payment = Payment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202605',
            'amount' => 1500,
            'paid_at' => '2026-05-26',
        ]);

    expect($payment->organization->is($organization))->toBeTrue()
        ->and($payment->client->is($client))->toBeTrue()
        ->and($payment->period)->toBe('202605')
        ->and($payment->amount)->toBe('1500.00')
        ->and($payment->paid_at->toDateString())->toBe('2026-05-26');
});

test('multiple payments are allowed for the same client and period', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create();

    Payment::factory()
        ->count(2)
        ->for($organization)
        ->for($client)
        ->sequence(
            ['period' => '202605', 'amount' => 1200],
            ['period' => '202605', 'amount' => 800],
        )
        ->create();

    expect(Payment::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sum('amount'))->toBe('2000.00');
});

test('billing month closure subtracts all payments from closing balance', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'fixed',
            'fixed_amount' => 5000,
        ]);

    Payment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202604',
            'amount' => 700,
        ]);

    closedBillingPeriodFor($organization, '202604');

    BalanceAdjustment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202605',
            'type' => BalanceAdjustmentType::OpeningBalance->value,
            'amount' => 1000,
        ]);

    Payment::factory()
        ->count(2)
        ->for($organization)
        ->for($client)
        ->sequence(
            ['period' => '202605', 'amount' => 1200],
            ['period' => '202605', 'amount' => 800],
        )
        ->create();

    app(CloseBillingMonth::class)->handle($organization, '202605');

    $accrual = Accrual::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    expect($accrual->opening_balance)->toBe('0.00')
        ->and($accrual->amount)->toBe('5000.00')
        ->and($accrual->paid_amount)->toBe('2000.00')
        ->and($accrual->adjustment_amount)->toBe('1000.00')
        ->and($accrual->closing_balance)->toBe('4000.00');
});

test('admin users can create and list payments for the current tenant', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create([
        'account_number' => '80001',
        'name' => 'Петров Пётр',
    ]);
    $otherTenantPayment = Payment::factory()->for(Organization::factory())->create([
        'period' => '202605',
    ]);
    billingPeriodFor($organization);

    actingAsPaymentTenant($organization);

    Livewire::test(CreatePayment::class)
        ->fillForm([
            'client_id' => $client->id,
            'amount' => 2500,
            'paid_at' => '2026-05-26',
            'note' => 'Оплата через кассу',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $payment = Payment::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    expect($payment->amount)->toBe('2500.00');

    Livewire::test(ListPayments::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$payment])
        ->assertCanNotSeeTableRecords([$otherTenantPayment]);
});
