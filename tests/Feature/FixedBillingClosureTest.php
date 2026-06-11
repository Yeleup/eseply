<?php

use App\Actions\CloseBillingMonth;
use App\BalanceAdjustmentType;
use App\BillingPeriodStatus;
use App\ClientType;
use App\Filament\Resources\Accruals\Pages\ListAccruals;
use App\Models\Accrual;
use App\Models\BalanceAdjustment;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\Tariff;
use App\Models\User;
use App\Models\UtilityService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsBillingTenant(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

test('billing month closure creates fixed and per person accruals with organization service', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Вывоз мусора',
    ]);

    $fixedClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '30001',
            'name' => 'ТОО Асыл',
            'client_type' => ClientType::Llp->value,
            'billing_type' => 'fixed',
            'fixed_amount' => 12500,
            'status' => 'active',
        ]);

    $perPersonClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '30002',
            'name' => 'Иванов Иван',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'residents_count' => 2,
            'status' => 'active',
        ]);

    BalanceAdjustment::factory()
        ->for($organization)
        ->for($fixedClient)
        ->create([
            'period' => '202605',
            'type' => BalanceAdjustmentType::OpeningBalance->value,
            'amount' => 1500,
        ]);

    Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'fixed',
            'fixed_amount' => 5000,
            'status' => 'inactive',
        ]);

    Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'client_type' => ClientType::Individual->value,
            'per_person_price' => 600,
            'starts_on' => '2026-01-01',
            'status' => 'active',
        ]);

    $summary = app(CloseBillingMonth::class)->handle($organization, '202605');

    expect($summary)->toBe([
        'active' => 2,
        'created' => 2,
        'skipped' => 0,
        'failed' => 0,
        'errors' => [],
    ]);

    $fixedAccrual = Accrual::query()
        ->whereBelongsTo($fixedClient)
        ->sole();

    $perPersonAccrual = Accrual::query()
        ->whereBelongsTo($perPersonClient)
        ->sole();

    expect($fixedAccrual->organization->is($organization))->toBeTrue()
        ->and($fixedAccrual->client->is($fixedClient))->toBeTrue()
        ->and($fixedAccrual->utilityService->is($utilityService))->toBeTrue()
        ->and($fixedAccrual->period)->toBe('202605')
        ->and($fixedAccrual->account_number)->toBe('30001')
        ->and($fixedAccrual->client_name)->toBe('ТОО Асыл')
        ->and($fixedAccrual->utility_service_name)->toBe('Вывоз мусора')
        ->and($fixedAccrual->billing_type)->toBe('fixed')
        ->and($fixedAccrual->amount)->toBe('12500.00')
        ->and($fixedAccrual->paid_amount)->toBe('0.00')
        ->and($fixedAccrual->adjustment_amount)->toBe('1500.00')
        ->and($fixedAccrual->opening_balance)->toBe('0.00')
        ->and($fixedAccrual->closing_balance)->toBe('14000.00')
        ->and($perPersonAccrual->billing_type)->toBe('per_person')
        ->and($perPersonAccrual->volume)->toBe('2.0000')
        ->and($perPersonAccrual->tariff_price)->toBe('600.00')
        ->and($perPersonAccrual->amount)->toBe('1200.00')
        ->and(Accrual::query()->count())->toBe(2);
});

test('billing month closure uses previous closing balance and does not duplicate accruals', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'fixed',
            'fixed_amount' => 2500,
        ]);

    Accrual::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'period' => '202604',
            'account_number' => $client->account_number,
            'client_name' => $client->name,
            'utility_service_name' => $utilityService->name,
            'billing_type' => 'fixed',
            'amount' => 4000,
            'opening_balance' => 1000,
            'closing_balance' => 5000,
        ]);

    $firstSummary = app(CloseBillingMonth::class)->handle($organization, '202605');

    expect(fn () => app(CloseBillingMonth::class)->handle($organization, '202605'))
        ->toThrow(InvalidArgumentException::class, 'Расчётный месяц уже закрыт.');

    $accrual = Accrual::query()
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    expect($firstSummary)->toBe([
        'active' => 1,
        'created' => 1,
        'skipped' => 0,
        'failed' => 0,
        'errors' => [],
    ])
        ->and($accrual->opening_balance)->toBe('5000.00')
        ->and($accrual->closing_balance)->toBe('7500.00')
        ->and(Accrual::query()->whereBelongsTo($client)->forPeriod('202605')->count())->toBe(1);
});

test('admin users can close a fixed billing month and see the accrual', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Обслуживание',
    ]);

    Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '40001',
            'name' => 'Иванов Иван',
            'billing_type' => 'fixed',
            'fixed_amount' => 8000,
        ]);

    BalanceAdjustment::factory()
        ->for($organization)
        ->for($organization->clients()->where('account_number', '40001')->sole())
        ->create([
            'period' => '202605',
            'type' => BalanceAdjustmentType::OpeningBalance->value,
            'amount' => 2000,
        ]);

    $otherTenantAccrual = Accrual::factory()->for(Organization::factory())->create([
        'period' => '202605',
    ]);
    $billingPeriod = billingPeriodFor($organization);

    actingAsBillingTenant($organization);

    Livewire::test(ListAccruals::class)
        ->assertOk()
        ->assertActionExists('closeBillingMonth')
        ->callAction('closeBillingMonth')
        ->assertNotified();

    $accrual = Accrual::query()
        ->whereBelongsTo($organization)
        ->where('account_number', '40001')
        ->forPeriod('202605')
        ->sole();

    expect($accrual->amount)->toBe('8000.00')
        ->and($billingPeriod->refresh()->status)->toBe(BillingPeriodStatus::Closed)
        ->and($accrual->closing_balance)->toBe('10000.00');

    Livewire::test(ListAccruals::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$accrual])
        ->assertCanNotSeeTableRecords([$otherTenantAccrual]);
});

test('billing month closure calculates per person accruals by client type tariff', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Вывоз мусора',
    ]);

    $cases = [
        [
            'account_number' => '50001',
            'client_type' => ClientType::Individual,
            'residents_count' => 3,
            'per_person_price' => 500,
            'expected_amount' => '1500.00',
        ],
        [
            'account_number' => '50002',
            'client_type' => ClientType::Budget,
            'residents_count' => 2,
            'per_person_price' => 1200,
            'expected_amount' => '2400.00',
        ],
    ];

    foreach ($cases as $case) {
        Client::factory()
            ->for($organization)
            ->for($utilityService)
            ->create([
                'account_number' => $case['account_number'],
                'client_type' => $case['client_type']->value,
                'billing_type' => 'per_person',
                'residents_count' => $case['residents_count'],
            ]);

        Tariff::factory()
            ->for($organization)
            ->for($utilityService)
            ->create([
                'client_type' => $case['client_type']->value,
                'per_person_price' => $case['per_person_price'],
                'starts_on' => '2026-01-01',
                'status' => 'active',
            ]);
    }

    $summary = app(CloseBillingMonth::class)->handle($organization, '202605');

    expect($summary)->toBe([
        'active' => 2,
        'created' => 2,
        'skipped' => 0,
        'failed' => 0,
        'errors' => [],
    ]);

    foreach ($cases as $case) {
        $accrual = Accrual::query()
            ->whereBelongsTo($organization)
            ->where('account_number', $case['account_number'])
            ->forPeriod('202605')
            ->sole();

        expect($accrual->billing_type)->toBe('per_person')
            ->and($accrual->volume)->toBe(number_format($case['residents_count'], 4, '.', ''))
            ->and($accrual->tariff_price)->toBe(number_format($case['per_person_price'], 2, '.', ''))
            ->and($accrual->amount)->toBe($case['expected_amount'])
            ->and($accrual->opening_balance)->toBe('0.00')
            ->and($accrual->adjustment_amount)->toBe('0.00')
            ->and($accrual->closing_balance)->toBe((string) number_format((float) $case['expected_amount'], 2, '.', ''));
    }
});

test('billing month closure reports active clients without required billing data', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    $withoutFixedAmount = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '80002',
            'name' => 'Нет суммы',
            'billing_type' => 'fixed',
            'fixed_amount' => 0,
        ]);

    $withoutResidents = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '80003',
            'name' => 'Нет проживающих',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'residents_count' => 0,
        ]);

    $withoutTariff = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '80004',
            'name' => 'Нет тарифа',
            'client_type' => ClientType::Budget->value,
            'billing_type' => 'per_person',
            'residents_count' => 2,
        ]);

    $withoutMeter = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '80005',
            'name' => 'Нет счётчика',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'meter',
        ]);

    Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'client_type' => ClientType::Individual->value,
            'unit_price' => 25,
            'per_person_price' => 500,
            'starts_on' => '2026-01-01',
        ]);

    $summary = app(CloseBillingMonth::class)->handle($organization, '202605');

    expect($summary)->toMatchArray([
        'active' => 4,
        'created' => 0,
        'skipped' => 0,
        'failed' => 4,
    ])
        ->and($summary['errors'])->toBe([
            [
                'client_id' => $withoutFixedAmount->id,
                'account_number' => '80002',
                'client_name' => 'Нет суммы',
                'message' => 'Не указана фиксированная сумма.',
            ],
            [
                'client_id' => $withoutResidents->id,
                'account_number' => '80003',
                'client_name' => 'Нет проживающих',
                'message' => 'Не указано количество проживающих.',
            ],
            [
                'client_id' => $withoutTariff->id,
                'account_number' => '80004',
                'client_name' => 'Нет тарифа',
                'message' => 'Не найден активный тариф на начало периода.',
            ],
            [
                'client_id' => $withoutMeter->id,
                'account_number' => '80005',
                'client_name' => 'Нет счётчика',
                'message' => 'Не найдены активные счётчики по услуге организации.',
            ],
        ])
        ->and(Accrual::query()->count())->toBe(0);
});

test('billing month closure reports active clients when organization service is missing', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()
        ->for($organization)
        ->create([
            'account_number' => '81001',
            'name' => 'Нет услуги организации',
            'billing_type' => 'fixed',
            'fixed_amount' => 5000,
        ]);

    $summary = app(CloseBillingMonth::class)->handle($organization, '202605');

    expect($summary)->toMatchArray([
        'active' => 1,
        'created' => 0,
        'skipped' => 0,
        'failed' => 1,
    ])
        ->and($summary['errors'])->toBe([
            [
                'client_id' => $client->id,
                'account_number' => '81001',
                'client_name' => 'Нет услуги организации',
                'message' => 'Не задана услуга организации.',
            ],
        ])
        ->and(Accrual::query()->count())->toBe(0);
});

test('billing month closure calculates meter accruals from all active meter readings', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Электроэнергия',
    ]);
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '90001',
            'client_type' => ClientType::Commercial->value,
            'billing_type' => 'meter',
        ]);

    Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'client_type' => ClientType::Commercial->value,
            'unit_price' => 25,
            'starts_on' => '2026-01-01',
            'status' => 'active',
        ]);

    $firstMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'status' => 'active',
        ]);

    $secondMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'status' => 'active',
        ]);

    MeterReading::factory()
        ->for($firstMeter)
        ->create([
            'period' => '202605',
            'previous_reading' => 100,
            'current_reading' => 110.5,
        ]);

    MeterReading::factory()
        ->for($secondMeter)
        ->create([
            'period' => '202605',
            'previous_reading' => 50,
            'current_reading' => 52.25,
        ]);

    $summary = app(CloseBillingMonth::class)->handle($organization, '202605');

    expect($summary)->toBe([
        'active' => 1,
        'created' => 1,
        'skipped' => 0,
        'failed' => 0,
        'errors' => [],
    ]);

    $accrual = Accrual::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    expect($accrual->billing_type)->toBe('meter')
        ->and($accrual->volume)->toBe('12.7500')
        ->and($accrual->tariff_price)->toBe('25.00')
        ->and($accrual->amount)->toBe('318.75')
        ->and($accrual->opening_balance)->toBe('0.00')
        ->and($accrual->adjustment_amount)->toBe('0.00')
        ->and($accrual->closing_balance)->toBe('318.75');
});

test('billing month closure reports meter clients when any active meter has no reading', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Электроэнергия',
    ]);
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '90002',
            'client_type' => ClientType::Commercial->value,
            'billing_type' => 'meter',
        ]);

    Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'client_type' => ClientType::Commercial->value,
            'unit_price' => 25,
            'starts_on' => '2026-01-01',
            'status' => 'active',
        ]);

    $firstMeter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'number' => 'MTR-90002-1',
            'status' => 'active',
        ]);

    Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'number' => 'MTR-90002-2',
            'status' => 'active',
        ]);

    MeterReading::factory()
        ->for($firstMeter)
        ->create([
            'period' => '202605',
            'previous_reading' => 100,
            'current_reading' => 110.5,
        ]);

    $summary = app(CloseBillingMonth::class)->handle($organization, '202605');

    expect($summary)->toMatchArray([
        'active' => 1,
        'created' => 0,
        'skipped' => 0,
        'failed' => 1,
    ])
        ->and($summary['errors'])->toBe([
            [
                'client_id' => $client->id,
                'account_number' => '90002',
                'client_name' => $client->name,
                'message' => 'Нет показания счётчика MTR-90002-2 за период.',
            ],
        ])
        ->and(Accrual::query()->count())->toBe(0);
});
