<?php

use App\ClientType;
use App\Filament\Resources\BalanceAdjustments\BalanceAdjustmentResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\RelationManagers\AccrualsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\BalanceAdjustmentsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\MetersRelationManager;
use App\Filament\Resources\Clients\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ReceiptsRelationManager;
use App\Filament\Resources\Meters\MeterResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Receipts\ReceiptResource;
use App\Models\Accrual;
use App\Models\BalanceAdjustment;
use App\Models\Client;
use App\Models\Meter;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Region;
use App\Models\Street;
use App\Models\User;
use App\Models\UtilityService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsTenant(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

test('clients belong to an organization', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create();

    expect($client->organization->is($organization))->toBeTrue()
        ->and($organization->clients()->whereKey($client)->exists())->toBeTrue();
});

test('account number is unique inside an organization', function () {
    $organization = Organization::factory()->create();

    Client::factory()->for($organization)->create([
        'account_number' => '10001',
    ]);

    expect(fn () => Client::factory()->for($organization)->create([
        'account_number' => '10001',
    ]))->toThrow(QueryException::class);
});

test('account number can repeat across organizations', function () {
    Client::factory()->for(Organization::factory())->create([
        'account_number' => '10001',
    ]);

    $client = Client::factory()->for(Organization::factory())->create([
        'account_number' => '10001',
    ]);

    expect($client)->toBeInstanceOf(Client::class);
});

test('admin users can list only current tenant clients', function () {
    $organization = Organization::factory()->create();
    $currentTenantClient = Client::factory()->for($organization)->create();
    $otherTenantClient = Client::factory()->for(Organization::factory())->create();

    actingAsTenant($organization);

    Livewire::test(ListClients::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$currentTenantClient])
        ->assertCanNotSeeTableRecords([$otherTenantClient]);
});

test('admin users can create a client for the current tenant', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
    ]);
    $region = Region::factory()->for($organization)->create([
        'name' => 'Алмалинский район',
    ]);
    $street = Street::factory()->for($region)->create([
        'name' => 'Абая',
    ]);

    actingAsTenant($organization);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'account_number' => '20001',
            'name' => 'Иванов Иван',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'residents_count' => 3,
            'fixed_amount' => 0,
            'phone' => '+7 777 111 22 33',
            'region_id' => $region->getKey(),
            'street_id' => $street->getKey(),
            'house' => '10',
            'apartment' => '15',
            'status' => 'active',
            'note' => 'Тестовый клиент',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    expect(Client::query()
        ->whereBelongsTo($organization)
        ->where('account_number', '20001')
        ->where('name', 'Иванов Иван')
        ->whereBelongsTo($utilityService)
        ->where('client_type', ClientType::Individual->value)
        ->where('billing_type', 'per_person')
        ->where('residents_count', 3)
        ->whereBelongsTo($region)
        ->whereBelongsTo($street)
        ->where('house', '10')
        ->where('apartment', '15')
        ->exists())->toBeTrue();
});

test('client street must belong to the selected region', function () {
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create();
    $selectedRegion = Region::factory()->for($organization)->create([
        'name' => 'Алмалинский район',
    ]);
    $otherRegion = Region::factory()->for($organization)->create([
        'name' => 'Бостандыкский район',
    ]);
    $streetFromOtherRegion = Street::factory()->for($otherRegion)->create([
        'name' => 'Сатпаева',
    ]);

    actingAsTenant($organization);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'account_number' => '20002',
            'name' => 'Сидоров Сидор',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'residents_count' => 1,
            'phone' => '+7 777 222 33 44',
            'region_id' => $selectedRegion->getKey(),
            'street_id' => $streetFromOtherRegion->getKey(),
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasFormErrors(['street_id']);

    expect(Client::query()
        ->whereBelongsTo($organization)
        ->where('account_number', '20002')
        ->exists())->toBeFalse();
});

test('clients store billing settings', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();

    $client = Client::factory()
        ->for($organization)
        ->create([
            'client_type' => ClientType::Budget->value,
            'billing_type' => 'fixed',
            'residents_count' => 2,
            'fixed_amount' => 12500,
        ]);

    expect($client->refresh()->utilityService->is($utilityService))->toBeTrue()
        ->and($client->client_type)->toBe(ClientType::Budget)
        ->and($client->billing_type)->toBe('fixed')
        ->and($client->residents_count)->toBe(2)
        ->and($client->fixed_amount)->toBe('12500.00');
});

test('client billing settings fields depend on billing type', function () {
    $organization = Organization::factory()->create();

    actingAsTenant($organization);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'billing_type' => 'per_person',
        ])
        ->assertFormFieldVisible('residents_count')
        ->assertFormFieldHidden('fixed_amount')
        ->assertFormFieldDoesNotExist('area')
        ->assertFormFieldDoesNotExist('starting_balance')
        ->fillForm([
            'billing_type' => 'fixed',
        ])
        ->assertFormFieldHidden('residents_count')
        ->assertFormFieldVisible('fixed_amount')
        ->fillForm([
            'billing_type' => 'meter',
        ])
        ->assertFormFieldHidden('residents_count')
        ->assertFormFieldHidden('fixed_amount');
});

test('admin client form validates account number uniqueness inside current tenant', function () {
    $organization = Organization::factory()->create();

    Client::factory()->for($organization)->create([
        'account_number' => '20001',
    ]);

    actingAsTenant($organization);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'account_number' => '20001',
            'name' => 'Петров Пётр',
            'client_type' => ClientType::Individual->value,
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasFormErrors(['account_number']);

    expect(Client::query()
        ->whereBelongsTo($organization)
        ->where('account_number', '20001')
        ->count())->toBe(1);
});

test('client resource shows accounting records as related tables', function () {
    expect(ClientResource::getRelations())->toBe([
        MetersRelationManager::class,
        PaymentsRelationManager::class,
        BalanceAdjustmentsRelationManager::class,
        AccrualsRelationManager::class,
        ReceiptsRelationManager::class,
    ])
        ->and(MeterResource::shouldRegisterNavigation())->toBeFalse()
        ->and(PaymentResource::shouldRegisterNavigation())->toBeFalse()
        ->and(BalanceAdjustmentResource::shouldRegisterNavigation())->toBeFalse()
        ->and(ReceiptResource::shouldRegisterNavigation())->toBeFalse();
});

test('client related tables list only the selected client records', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
    ]);

    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '30001',
            'name' => 'Основной абонент',
        ]);

    $otherClient = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '30002',
            'name' => 'Другой абонент',
        ]);

    $meter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($client)
        ->create(['number' => 'MTR-30001']);
    $otherMeter = Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($otherClient)
        ->create(['number' => 'MTR-30002']);

    $payment = Payment::factory()
        ->for($organization)
        ->for($client)
        ->create(['period' => '202605']);
    $otherPayment = Payment::factory()
        ->for($organization)
        ->for($otherClient)
        ->create(['period' => '202605']);

    $balanceAdjustment = BalanceAdjustment::factory()
        ->for($organization)
        ->for($client)
        ->create(['period' => '202605']);
    $otherBalanceAdjustment = BalanceAdjustment::factory()
        ->for($organization)
        ->for($otherClient)
        ->create(['period' => '202605']);

    $accrual = Accrual::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($client)
        ->create([
            'period' => '202605',
            'account_number' => '30001',
            'client_name' => 'Основной абонент',
            'utility_service_name' => 'Водоснабжение',
        ]);
    $otherAccrual = Accrual::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($otherClient)
        ->create([
            'period' => '202605',
            'account_number' => '30002',
            'client_name' => 'Другой абонент',
            'utility_service_name' => 'Водоснабжение',
        ]);

    $receipt = Receipt::fromAccrual($accrual);
    $otherReceipt = Receipt::fromAccrual($otherAccrual);

    actingAsTenant($organization);

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$meter])
        ->assertCanNotSeeTableRecords([$otherMeter]);

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$payment])
        ->assertCanNotSeeTableRecords([$otherPayment]);

    Livewire::test(BalanceAdjustmentsRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$balanceAdjustment])
        ->assertCanNotSeeTableRecords([$otherBalanceAdjustment]);

    Livewire::test(AccrualsRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$accrual])
        ->assertCanNotSeeTableRecords([$otherAccrual]);

    Livewire::test(ReceiptsRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$receipt])
        ->assertCanNotSeeTableRecords([$otherReceipt]);
});

test('client related tables can create meters, payments and balance adjustments for the selected client', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'meter',
        ]);

    actingAsTenant($organization);

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callTableAction('create', data: [
            'number' => 'MTR-REL-1',
            'initial_reading' => 12.5,
            'installed_on' => '2026-05-01',
        ])
        ->assertHasNoTableActionErrors();

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callTableAction('create', data: [
            'period' => '202605',
            'amount' => 3500,
            'paid_at' => '2026-05-29',
            'note' => 'Оплата из карточки абонента',
        ])
        ->assertHasNoTableActionErrors();

    Livewire::test(BalanceAdjustmentsRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callTableAction('create', data: [
            'period' => '202605',
            'type' => 'opening_balance',
            'amount' => 1500,
            'adjusted_at' => '2026-05-29',
            'note' => 'Входящий остаток из карточки абонента',
        ])
        ->assertHasNoTableActionErrors();

    expect(Meter::query()
        ->whereBelongsTo($organization)
        ->whereBelongsTo($utilityService)
        ->whereBelongsTo($client)
        ->where('number', 'MTR-REL-1')
        ->exists())->toBeTrue()
        ->and(Payment::query()
            ->whereBelongsTo($organization)
            ->whereBelongsTo($client)
            ->where('period', '202605')
            ->where('amount', 3500)
            ->exists())->toBeTrue()
        ->and(BalanceAdjustment::query()
            ->whereBelongsTo($organization)
            ->whereBelongsTo($client)
            ->where('period', '202605')
            ->where('amount', 1500)
            ->exists())->toBeTrue();
});
