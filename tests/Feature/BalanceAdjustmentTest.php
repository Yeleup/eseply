<?php

use App\Actions\CloseBillingMonth;
use App\BalanceAdjustmentType;
use App\Filament\Resources\BalanceAdjustments\Pages\CreateBalanceAdjustment;
use App\Filament\Resources\BalanceAdjustments\Pages\ListBalanceAdjustments;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\BalanceAdjustmentsRelationManager;
use App\Models\Accrual;
use App\Models\BalanceAdjustment;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Models\UtilityService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsBalanceAdjustmentTenant(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

test('balance adjustments belong to an organization and client', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create();

    $balanceAdjustment = BalanceAdjustment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202605',
            'type' => BalanceAdjustmentType::OpeningBalance->value,
            'amount' => 1500,
            'adjusted_at' => '2026-05-29',
        ]);

    expect($balanceAdjustment->organization->is($organization))->toBeTrue()
        ->and($balanceAdjustment->client->is($client))->toBeTrue()
        ->and($balanceAdjustment->period)->toBe('202605')
        ->and($balanceAdjustment->type)->toBe(BalanceAdjustmentType::OpeningBalance)
        ->and($balanceAdjustment->amount)->toBe('1500.00')
        ->and($balanceAdjustment->adjusted_at->toDateString())->toBe('2026-05-29');
});

test('billing month closure moves opening balance adjustments into the opening balance', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'fixed',
            'fixed_amount' => 5000,
        ]);

    BalanceAdjustment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202604',
            'amount' => 900,
        ]);

    closedBillingPeriodFor($organization, '202604');

    BalanceAdjustment::factory()
        ->count(2)
        ->for($organization)
        ->for($client)
        ->sequence(
            [
                'period' => '202605',
                'type' => BalanceAdjustmentType::OpeningBalance->value,
                'amount' => 1000,
            ],
            [
                'period' => '202605',
                'type' => BalanceAdjustmentType::WriteOff->value,
                'amount' => -250,
            ],
        )
        ->create();

    app(CloseBillingMonth::class)->handle($organization, '202605');

    $accrual = Accrual::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    expect($accrual->opening_balance)->toBe('1000.00')
        ->and($accrual->amount)->toBe('5000.00')
        ->and($accrual->paid_amount)->toBe('0.00')
        ->and($accrual->adjustment_amount)->toBe('-250.00')
        ->and($accrual->closing_balance)->toBe('5750.00');
});

test('an opening balance adjustment is rejected once the client has an accrual', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create();

    closedBillingPeriodFor($organization, '202605');
    Accrual::factory()
        ->for($organization)
        ->for($client)
        ->create(['period' => '202605']);
    billingPeriodFor($organization, '202606');

    expect(fn (): BalanceAdjustment => BalanceAdjustment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
            'type' => BalanceAdjustmentType::OpeningBalance->value,
            'amount' => 500,
        ]))->toThrow(ValidationException::class);

    $manualAdjustment = BalanceAdjustment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
            'amount' => -200,
        ]);

    expect($manualAdjustment->exists)->toBeTrue()
        ->and($manualAdjustment->type)->toBe(BalanceAdjustmentType::ManualAdjustment);
});

test('the balance adjustment form shows the opening balance error for a client with accruals', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create();

    closedBillingPeriodFor($organization, '202605');
    Accrual::factory()
        ->for($organization)
        ->for($client)
        ->create(['period' => '202605']);
    billingPeriodFor($organization, '202606');

    actingAsBalanceAdjustmentTenant($organization);

    Livewire::test(CreateBalanceAdjustment::class)
        ->fillForm([
            'client_id' => $client->id,
            'type' => BalanceAdjustmentType::OpeningBalance->value,
            'amount' => 500,
        ])
        ->call('create')
        ->assertHasFormErrors(['type']);

    expect(BalanceAdjustment::query()->count())->toBe(0);
});

test('the client card adjustment modal shows the opening balance error for a client with accruals', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create();

    closedBillingPeriodFor($organization, '202605');
    Accrual::factory()
        ->for($organization)
        ->for($client)
        ->create(['period' => '202605']);
    billingPeriodFor($organization, '202606');

    actingAsBalanceAdjustmentTenant($organization);

    Livewire::test(BalanceAdjustmentsRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callAction(TestAction::make('create')->table(), [
            'type' => BalanceAdjustmentType::OpeningBalance->value,
            'amount' => 500,
        ])
        ->assertHasActionErrors(['type']);

    expect(BalanceAdjustment::query()->count())->toBe(0);
});

test('a legacy opening balance adjustment stays editable after the client gets accruals', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create();

    closedBillingPeriodFor($organization, '202605');
    Accrual::factory()
        ->for($organization)
        ->for($client)
        ->create(['period' => '202605']);
    $openPeriod = billingPeriodFor($organization, '202606');

    $legacyAdjustment = BalanceAdjustment::withoutEvents(fn (): BalanceAdjustment => BalanceAdjustment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'billing_period_id' => $openPeriod->getKey(),
            'type' => BalanceAdjustmentType::OpeningBalance->value,
            'amount' => 500,
        ]))->fresh();

    $legacyAdjustment->update(['amount' => 750]);

    expect($legacyAdjustment->refresh()->amount)->toBe('750.00');

    $manualAdjustment = BalanceAdjustment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202606',
            'amount' => -200,
        ]);

    expect(fn (): bool => $manualAdjustment->update(['type' => BalanceAdjustmentType::OpeningBalance->value]))
        ->toThrow(ValidationException::class);
});

test('admin users can create and list balance adjustments for the current tenant', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create([
        'account_number' => '81001',
        'name' => 'Иванов Иван',
    ]);
    $otherTenantBalanceAdjustment = BalanceAdjustment::factory()->for(Organization::factory())->create([
        'period' => '202605',
    ]);
    billingPeriodFor($organization);

    actingAsBalanceAdjustmentTenant($organization);

    Livewire::test(CreateBalanceAdjustment::class)
        ->fillForm([
            'client_id' => $client->id,
            'type' => BalanceAdjustmentType::ManualAdjustment->value,
            'amount' => -500,
            'adjusted_at' => '2026-05-29',
            'note' => 'Списание по акту',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $balanceAdjustment = BalanceAdjustment::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    expect($balanceAdjustment->amount)->toBe('-500.00');

    Livewire::test(ListBalanceAdjustments::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$balanceAdjustment])
        ->assertCanNotSeeTableRecords([$otherTenantBalanceAdjustment]);
});

test('balance adjustment pages show billing period error and disable creation without an open month', function () {
    $organization = Organization::factory()->create();
    Client::factory()->for($organization)->create();
    $user = actingAsBalanceAdjustmentTenant($organization);

    $this->actingAs($user)
        ->get("/admin/{$organization->getKey()}/balance-adjustments/create")
        ->assertSuccessful()
        ->assertSee('Расчётный месяц не открыт');

    Livewire::test(ListBalanceAdjustments::class)
        ->assertActionDisabled('create');
});
