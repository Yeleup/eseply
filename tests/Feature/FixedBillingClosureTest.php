<?php

use App\Actions\CloseBillingMonth;
use App\Filament\Resources\Accruals\Pages\CloseBillingMonth as CloseBillingMonthPage;
use App\Filament\Resources\Accruals\Pages\ListAccruals;
use App\Models\Accrual;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Normative;
use App\Models\Organization;
use App\Models\Tariff;
use App\Models\TariffCategory;
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

test('fixed billing month closure creates accruals only for active fixed clients with service', function () {
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
            'billing_type' => 'fixed',
            'fixed_amount' => 12500,
            'starting_balance' => 1500,
            'status' => 'active',
        ]);

    Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'normative',
            'fixed_amount' => 9000,
            'status' => 'active',
        ]);

    Client::factory()
        ->for($organization)
        ->create([
            'billing_type' => 'fixed',
            'fixed_amount' => 7000,
            'status' => 'active',
        ]);

    Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'fixed',
            'fixed_amount' => 5000,
            'status' => 'inactive',
        ]);

    $summary = app(CloseBillingMonth::class)->handle($organization, '202605');

    expect($summary)->toMatchArray([
        'active' => 3,
        'created' => 1,
        'skipped' => 0,
        'failed' => 2,
    ]);

    expect(array_column($summary['errors'], 'message'))->toBe([
        'Не выбрана категория тарифа.',
        'Не выбрана услуга клиента.',
    ]);

    $accrual = Accrual::query()->sole();

    expect($accrual->organization->is($organization))->toBeTrue()
        ->and($accrual->client->is($fixedClient))->toBeTrue()
        ->and($accrual->utilityService->is($utilityService))->toBeTrue()
        ->and($accrual->period)->toBe('202605')
        ->and($accrual->account_number)->toBe('30001')
        ->and($accrual->client_name)->toBe('ТОО Асыл')
        ->and($accrual->utility_service_name)->toBe('Вывоз мусора')
        ->and($accrual->billing_type)->toBe('fixed')
        ->and($accrual->amount)->toBe('12500.00')
        ->and($accrual->paid_amount)->toBe('0.00')
        ->and($accrual->opening_balance)->toBe('1500.00')
        ->and($accrual->closing_balance)->toBe('14000.00');
});

test('fixed billing month closure uses previous closing balance and does not duplicate accruals', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'fixed',
            'fixed_amount' => 2500,
            'starting_balance' => 100,
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
    $secondSummary = app(CloseBillingMonth::class)->handle($organization, '202605');

    $accrual = Accrual::query()
        ->whereBelongsTo($client)
        ->where('period', '202605')
        ->sole();

    expect($firstSummary)->toBe([
        'active' => 1,
        'created' => 1,
        'skipped' => 0,
        'failed' => 0,
        'errors' => [],
    ])
        ->and($secondSummary)->toBe([
            'active' => 1,
            'created' => 0,
            'skipped' => 1,
            'failed' => 0,
            'errors' => [],
        ])
        ->and($accrual->opening_balance)->toBe('5000.00')
        ->and($accrual->closing_balance)->toBe('7500.00')
        ->and(Accrual::query()->whereBelongsTo($client)->where('period', '202605')->count())->toBe(1);
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
            'starting_balance' => 2000,
        ]);

    $otherTenantAccrual = Accrual::factory()->for(Organization::factory())->create([
        'period' => '202605',
    ]);

    actingAsBillingTenant($organization);

    Livewire::test(CloseBillingMonthPage::class)
        ->assertOk()
        ->fillForm([
            'period' => '202605',
        ])
        ->call('close')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertSet('result.created', 1)
        ->assertSet('result.failed', 0);

    $accrual = Accrual::query()
        ->whereBelongsTo($organization)
        ->where('account_number', '40001')
        ->where('period', '202605')
        ->sole();

    expect($accrual->amount)->toBe('8000.00')
        ->and($accrual->closing_balance)->toBe('10000.00');

    Livewire::test(ListAccruals::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$accrual])
        ->assertCanNotSeeTableRecords([$otherTenantAccrual]);
});

test('billing month closure calculates normative accruals by calculation type', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
    ]);

    $cases = [
        [
            'account_number' => '50001',
            'category_name' => 'На человека',
            'calculation_type' => 'per_person',
            'normative' => 2.5,
            'residents_count' => 3,
            'area' => 0,
            'expected_volume' => '7.5000',
            'expected_amount' => '750.00',
        ],
        [
            'account_number' => '50002',
            'category_name' => 'По площади',
            'calculation_type' => 'per_area',
            'normative' => 0.2,
            'residents_count' => 0,
            'area' => 42.25,
            'expected_volume' => '8.4500',
            'expected_amount' => '845.00',
        ],
        [
            'account_number' => '50003',
            'category_name' => 'На объект',
            'calculation_type' => 'per_object',
            'normative' => 1.75,
            'residents_count' => 0,
            'area' => 0,
            'expected_volume' => '1.7500',
            'expected_amount' => '175.00',
        ],
    ];

    foreach ($cases as $case) {
        $tariffCategory = TariffCategory::factory()->for($organization)->create([
            'name' => $case['category_name'],
        ]);

        Client::factory()
            ->for($organization)
            ->for($utilityService)
            ->for($tariffCategory)
            ->create([
                'account_number' => $case['account_number'],
                'billing_type' => 'normative',
                'residents_count' => $case['residents_count'],
                'area' => $case['area'],
                'starting_balance' => 100,
            ]);

        Tariff::factory()
            ->for($organization)
            ->for($utilityService)
            ->for($tariffCategory)
            ->create([
                'price' => 100,
                'starts_on' => '2026-01-01',
                'status' => 'active',
            ]);

        Normative::factory()
            ->for($organization)
            ->for($utilityService)
            ->for($tariffCategory)
            ->create([
                'value' => $case['normative'],
                'calculation_type' => $case['calculation_type'],
                'starts_on' => '2026-01-01',
                'status' => 'active',
            ]);
    }

    $summary = app(CloseBillingMonth::class)->handle($organization, '202605');

    expect($summary)->toBe([
        'active' => 3,
        'created' => 3,
        'skipped' => 0,
        'failed' => 0,
        'errors' => [],
    ]);

    foreach ($cases as $case) {
        $accrual = Accrual::query()
            ->whereBelongsTo($organization)
            ->where('account_number', $case['account_number'])
            ->where('period', '202605')
            ->sole();

        expect($accrual->billing_type)->toBe('normative')
            ->and($accrual->volume)->toBe($case['expected_volume'])
            ->and($accrual->tariff_price)->toBe('100.00')
            ->and($accrual->amount)->toBe($case['expected_amount'])
            ->and($accrual->opening_balance)->toBe('100.00')
            ->and($accrual->closing_balance)->toBe((string) number_format(100 + (float) $case['expected_amount'], 2, '.', ''));
    }
});

test('normative clients without active tariff or normative are reported as failed', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $tariffCategory = TariffCategory::factory()->for($organization)->create();

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($tariffCategory)
        ->create([
            'billing_type' => 'normative',
        ]);

    $summary = app(CloseBillingMonth::class)->handle($organization, '202605');

    expect($summary)->toMatchArray([
        'active' => 1,
        'created' => 0,
        'skipped' => 0,
        'failed' => 1,
    ])
        ->and($summary['errors'])->toHaveCount(1)
        ->and($summary['errors'][0]['account_number'])->toBe($client->account_number)
        ->and($summary['errors'][0]['message'])->toBe('Не найден активный тариф на начало периода.')
        ->and(Accrual::query()->count())->toBe(0);
});

test('billing month closure reports active clients without required billing data', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $tariffCategory = TariffCategory::factory()->for($organization)->create();

    $withoutService = Client::factory()
        ->for($organization)
        ->create([
            'account_number' => '80001',
            'name' => 'Нет услуги',
            'billing_type' => 'fixed',
            'fixed_amount' => 5000,
        ]);

    $withoutFixedAmount = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '80002',
            'name' => 'Нет суммы',
            'billing_type' => 'fixed',
            'fixed_amount' => 0,
        ]);

    $withoutCategory = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '80003',
            'name' => 'Нет категории',
            'billing_type' => 'normative',
        ]);

    $withoutMeter = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($tariffCategory)
        ->create([
            'account_number' => '80004',
            'name' => 'Нет счётчика',
            'billing_type' => 'meter',
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
                'client_id' => $withoutService->id,
                'account_number' => '80001',
                'client_name' => 'Нет услуги',
                'message' => 'Не выбрана услуга клиента.',
            ],
            [
                'client_id' => $withoutFixedAmount->id,
                'account_number' => '80002',
                'client_name' => 'Нет суммы',
                'message' => 'Не указана фиксированная сумма.',
            ],
            [
                'client_id' => $withoutCategory->id,
                'account_number' => '80003',
                'client_name' => 'Нет категории',
                'message' => 'Не выбрана категория тарифа.',
            ],
            [
                'client_id' => $withoutMeter->id,
                'account_number' => '80004',
                'client_name' => 'Нет счётчика',
                'message' => 'Не найден активный счётчик по услуге клиента.',
            ],
        ])
        ->and(Accrual::query()->count())->toBe(0);
});

test('billing month closure calculates meter accruals from period readings', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Электроэнергия',
    ]);
    $tariffCategory = TariffCategory::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($tariffCategory)
        ->create([
            'account_number' => '90001',
            'billing_type' => 'meter',
            'starting_balance' => 100,
        ]);

    Tariff::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($tariffCategory)
        ->create([
            'price' => 25,
            'starts_on' => '2026-01-01',
            'status' => 'active',
        ]);

    $meter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'status' => 'active',
        ]);

    MeterReading::factory()
        ->for($meter)
        ->create([
            'period' => '202605',
            'previous_reading' => 100,
            'current_reading' => 110.5,
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
        ->where('period', '202605')
        ->sole();

    expect($accrual->billing_type)->toBe('meter')
        ->and($accrual->volume)->toBe('10.5000')
        ->and($accrual->tariff_price)->toBe('25.00')
        ->and($accrual->amount)->toBe('262.50')
        ->and($accrual->opening_balance)->toBe('100.00')
        ->and($accrual->closing_balance)->toBe('362.50');
});
