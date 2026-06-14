<?php

use App\Actions\CloseBillingMonth;
use App\BalanceAdjustmentType;
use App\Filament\Resources\Receipts\Pages\ListReceipts;
use App\Filament\Resources\Receipts\Pages\ViewReceipt;
use App\Models\Accrual;
use App\Models\BalanceAdjustment;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\User;
use App\Models\UtilityService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsReceiptTenant(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

test('receipts are snapshots built from saved accruals', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
    ]);
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '10001',
            'name' => 'Исходное имя',
        ]);

    $accrual = Accrual::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'period' => '202605',
            'account_number' => '10001',
            'client_name' => 'Сохранённый абонент',
            'utility_service_name' => 'Сохранённая услуга',
            'billing_type' => 'fixed',
            'amount' => 5000,
            'paid_amount' => 1200,
            'adjustment_amount' => 300,
            'opening_balance' => 300,
            'closing_balance' => 4400,
        ]);

    $client->update([
        'name' => 'Изменённое имя',
        'fixed_amount' => 9000,
    ]);

    $receipt = Receipt::fromAccrual($accrual);

    expect($receipt->organization->is($organization))->toBeTrue()
        ->and($receipt->client->is($client))->toBeTrue()
        ->and($receipt->accrual->is($accrual))->toBeTrue()
        ->and($receipt->receipt_number)->toBe('202605-10001')
        ->and($receipt->period)->toBe('202605')
        ->and($receipt->account_number)->toBe('10001')
        ->and($receipt->client_name)->toBe('Сохранённый абонент')
        ->and($receipt->utility_service_name)->toBe('Сохранённая услуга')
        ->and($receipt->amount)->toBe('5000.00')
        ->and($receipt->paid_amount)->toBe('1200.00')
        ->and($receipt->adjustment_amount)->toBe('300.00')
        ->and($receipt->opening_balance)->toBe('300.00')
        ->and($receipt->closing_balance)->toBe('4400.00');
});

test('billing month closure creates receipts from created accruals without duplicates', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Вывоз мусора',
    ]);
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '20001',
            'name' => 'ТОО Дала',
            'billing_type' => 'fixed',
            'fixed_amount' => 7500,
        ]);

    BalanceAdjustment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202605',
            'type' => BalanceAdjustmentType::OpeningBalance->value,
            'amount' => 500,
        ]);

    $firstSummary = app(CloseBillingMonth::class)->handle($organization, '202605');

    expect(fn () => app(CloseBillingMonth::class)->handle($organization, '202605'))
        ->toThrow(InvalidArgumentException::class, 'Расчётный месяц уже закрыт.');

    $accrual = Accrual::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    $receipt = Receipt::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($client)
        ->forPeriod('202605')
        ->sole();

    expect($firstSummary)->toMatchArray([
        'created' => 1,
        'skipped' => 0,
        'failed' => 0,
    ])
        ->and($receipt->accrual->is($accrual))->toBeTrue()
        ->and($receipt->receipt_number)->toBe('202605-20001')
        ->and($receipt->client_name)->toBe('ТОО Дала')
        ->and($receipt->utility_service_name)->toBe('Вывоз мусора')
        ->and($receipt->amount)->toBe($accrual->amount)
        ->and($receipt->adjustment_amount)->toBe($accrual->adjustment_amount)
        ->and($receipt->closing_balance)->toBe($accrual->closing_balance)
        ->and(Receipt::query()->whereBelongsTo($client)->forPeriod('202605')->count())->toBe(1);
});

test('admin users can list receipts for the current tenant', function () {
    $organization = Organization::factory()->create();
    $receipt = Receipt::fromAccrual(Accrual::factory()->for($organization)->create([
        'period' => '202605',
        'account_number' => '30001',
        'client_name' => 'Иванов Иван',
    ]));
    $otherTenantReceipt = Receipt::fromAccrual(Accrual::factory()->for(Organization::factory())->create([
        'period' => '202605',
        'account_number' => '90001',
    ]));

    actingAsReceiptTenant($organization);

    Livewire::test(ListReceipts::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$receipt])
        ->assertCanNotSeeTableRecords([$otherTenantReceipt]);
});

test('admin receipt view page has a print pdf action', function () {
    $organization = Organization::factory()->create();
    $receipt = Receipt::fromAccrual(Accrual::factory()->for($organization)->create([
        'period' => '202605',
        'account_number' => '30001',
        'client_name' => 'Иванов Иван',
    ]));

    actingAsReceiptTenant($organization);

    $url = route('filament.admin.receipts.print', [
        'tenant' => $organization,
        'receipt' => $receipt,
    ]);

    Livewire::test(ViewReceipt::class, [
        'record' => $receipt->getRouteKey(),
    ])
        ->assertActionExists('printPdf')
        ->assertActionHasLabel('printPdf', 'Печатать PDF')
        ->assertActionHasUrl('printPdf', $url)
        ->assertActionShouldOpenUrlInNewTab('printPdf');
});

test('admin users can open a current tenant receipt print view', function () {
    $organization = Organization::factory()->create([
        'name' => 'ТОО Водоканал',
        'bin_iin' => '123456789012',
        'address' => 'Алматы, Абая 10',
    ]);
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
        'unit_of_measurement' => 'м3',
    ]);
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '100010',
            'name' => 'Иванов Иван',
        ]);
    $receipt = Receipt::fromAccrual(Accrual::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'period' => '202605',
            'account_number' => '100010',
            'client_name' => 'Иванов Иван',
            'utility_service_name' => 'Водоснабжение',
            'billing_type' => 'meter',
            'volume' => 20,
            'tariff_price' => 90,
            'amount' => 1800,
            'paid_amount' => 0,
            'adjustment_amount' => 0,
            'opening_balance' => 0,
            'closing_balance' => 1800,
        ]));

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $response = $this->get(route('filament.admin.receipts.print', [
        'tenant' => $organization,
        'receipt' => $receipt,
    ]));

    $response
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Content-Disposition')
        ->assertViewIs('receipts.print')
        ->assertViewHasAll([
            'receipt',
            'generatedAt',
            'organizationDetails',
            'clientDetails',
            'calculationDetails',
            'balanceDetails',
            'paymentDue',
            'clientAddress',
        ])
        ->assertSeeTextInOrder([
            'Квитанция на оплату коммунальной услуги',
            'ТОО Водоканал',
            'Номер',
            '202605-100010',
            'Лицевой счёт',
            '100010',
            'Иванов Иван',
            'Водоснабжение',
            '20.0000',
            '90.00 KZT',
            '1 800.00 KZT',
            'К оплате',
            '1 800.00 KZT',
        ]);

    expect(str_starts_with($response->getContent(), '%PDF'))->toBeFalse();
});

test('admin users cannot open another tenant receipt print view', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $receipt = Receipt::fromAccrual(Accrual::factory()->for($otherOrganization)->create([
        'period' => '202605',
        'account_number' => '90001',
    ]));

    $user = actingAsReceiptTenant($organization);
    $this->actingAs($user);

    $this->get(route('filament.admin.receipts.print', [
        'tenant' => $organization,
        'receipt' => $receipt,
    ]))->assertNotFound();
});
