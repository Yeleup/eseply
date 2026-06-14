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

test('iin and phone are unique inside an organization', function () {
    $organization = Organization::factory()->create();

    Client::factory()->for($organization)->create([
        'iin' => '870101300123',
        'phone' => '+7 777 111 22 33',
    ]);

    expect(fn () => Client::factory()->for($organization)->create([
        'iin' => '870101300123',
        'phone' => '+7 777 111 22 34',
    ]))->toThrow(QueryException::class);

    expect(fn () => Client::factory()->for($organization)->create([
        'iin' => '870101300124',
        'phone' => '+7 777 111 22 33',
    ]))->toThrow(QueryException::class);
});

test('iin and phone can repeat across organizations', function () {
    Client::factory()->for(Organization::factory())->create([
        'iin' => '870101300123',
        'phone' => '+7 777 111 22 33',
    ]);

    $client = Client::factory()->for(Organization::factory())->create([
        'iin' => '870101300123',
        'phone' => '+7 777 111 22 33',
    ]);

    expect($client)->toBeInstanceOf(Client::class);
});

test('account number is generated separately inside each organization', function () {
    $firstOrganization = Organization::factory()->create();
    $secondOrganization = Organization::factory()->create();

    $firstClient = Client::factory()->for($firstOrganization)->create();
    $secondClient = Client::factory()->for($firstOrganization)->create();
    $otherOrganizationClient = Client::factory()->for($secondOrganization)->create();

    expect($firstClient->account_number)->toBe('100001')
        ->and($secondClient->account_number)->toBe('100002')
        ->and($otherOrganizationClient->account_number)->toBe('100001');
});

test('existing numeric account number advances the next generated account number', function () {
    $organization = Organization::factory()->create();

    Client::factory()->for($organization)->create([
        'account_number' => '100010',
    ]);

    $client = Client::factory()->for($organization)->create();

    expect($client->account_number)->toBe('100011');
});

test('existing account number cannot be changed after creation', function () {
    $organization = Organization::factory()->create();
    $client = Client::factory()->for($organization)->create([
        'account_number' => '100010',
    ]);

    $client->account_number = '999999';
    $client->save();

    expect($client->refresh()->account_number)->toBe('100010');

    $client->account_number = null;
    $client->save();

    expect($client->refresh()->account_number)->toBe('100010');
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

test('client pages show missing billing period callout at page start', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTenant($organization);
    $client = Client::factory()->for($organization)->create();

    $this->actingAs($user)
        ->get("/admin/{$organization->getKey()}/clients/{$client->getRouteKey()}/edit")
        ->assertSuccessful()
        ->assertSee('Расчётный месяц не открыт')
        ->assertSee('Откройте расчётный месяц в разделе «Расчётные месяцы», чтобы вводить оплаты, показания, корректировки и закрывать месяц.');
});

test('client pages do not show missing billing period callout when current period is open', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTenant($organization);
    $client = Client::factory()->for($organization)->create();

    billingPeriodFor($organization);

    $this->actingAs($user)
        ->get("/admin/{$organization->getKey()}/clients/{$client->getRouteKey()}/edit")
        ->assertSuccessful()
        ->assertDontSee('Расчётный месяц не открыт');
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
            'name' => 'Иванов Иван',
            'iin' => '870101300123',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'residents_count' => 3,
            'fixed_amount' => 0,
            'phone' => '+7 777 111 22 33',
            'contract' => 'Договор №15',
            'technical_conditions' => 'ТУ-2026-15',
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
        ->where('account_number', '100001')
        ->where('name', 'Иванов Иван')
        ->where('iin', '870101300123')
        ->whereBelongsTo($utilityService)
        ->where('client_type', ClientType::Individual->value)
        ->where('billing_type', 'per_person')
        ->where('residents_count', 3)
        ->where('contract', 'Договор №15')
        ->where('technical_conditions', 'ТУ-2026-15')
        ->whereBelongsTo($region)
        ->whereBelongsTo($street)
        ->where('house', '10')
        ->where('apartment', '15')
        ->exists())->toBeTrue();
});

test('admin client form ignores submitted account number', function () {
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create([
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
            'account_number' => '999999',
            'name' => 'Авто Абонент',
            'iin' => '870101300789',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'residents_count' => 1,
            'fixed_amount' => 0,
            'phone' => '+7 777 333 44 55',
            'contract' => 'Договор №18',
            'region_id' => $region->getKey(),
            'street_id' => $street->getKey(),
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    expect(Client::query()
        ->whereBelongsTo($organization)
        ->where('account_number', '100001')
        ->where('name', 'Авто Абонент')
        ->exists())->toBeTrue();
});

test('client iin contract and phone are required client data fields', function () {
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create();
    $street = Street::factory()->for($region)->create();

    actingAsTenant($organization);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'Без реквизитов',
            'iin' => null,
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'residents_count' => 1,
            'fixed_amount' => 0,
            'phone' => null,
            'contract' => null,
            'region_id' => $region->getKey(),
            'street_id' => $street->getKey(),
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasFormErrors([
            'iin' => 'required',
            'phone' => 'required',
            'contract' => 'required',
        ]);
});

test('client iin and phone must be unique inside the current tenant form', function () {
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create();
    $street = Street::factory()->for($region)->create();

    Client::factory()->for($organization)->create([
        'iin' => '870101300123',
        'phone' => '+7 777 111 22 33',
    ]);

    actingAsTenant($organization);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'Дубликат реквизитов',
            'iin' => '870101300123',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'residents_count' => 1,
            'fixed_amount' => 0,
            'phone' => '+7 777 111 22 33',
            'contract' => 'Договор №22',
            'region_id' => $region->getKey(),
            'street_id' => $street->getKey(),
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasFormErrors([
            'iin' => 'unique',
            'phone' => 'unique',
        ]);

    expect(Client::query()
        ->whereBelongsTo($organization)
        ->where('name', 'Дубликат реквизитов')
        ->exists())->toBeFalse();
});

test('client iin and phone can repeat in another tenant form', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create();
    $street = Street::factory()->for($region)->create();

    Client::factory()->for($otherOrganization)->create([
        'iin' => '870101300123',
        'phone' => '+7 777 111 22 33',
    ]);

    actingAsTenant($organization);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'Повтор в другой организации',
            'iin' => '870101300123',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'residents_count' => 1,
            'fixed_amount' => 0,
            'phone' => '+7 777 111 22 33',
            'contract' => 'Договор №23',
            'region_id' => $region->getKey(),
            'street_id' => $street->getKey(),
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    expect(Client::query()
        ->whereBelongsTo($organization)
        ->where('iin', '870101300123')
        ->where('phone', '+7 777 111 22 33')
        ->exists())->toBeTrue();
});

test('admin users can keep client iin and phone when editing', function () {
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create();
    $street = Street::factory()->for($region)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($region)
        ->for($street)
        ->create([
            'iin' => '870101300123',
            'phone' => '+7 777 111 22 33',
            'contract' => 'Договор №24',
        ]);

    actingAsTenant($organization);

    Livewire::test(EditClient::class, [
        'record' => $client->getRouteKey(),
    ])
        ->fillForm([
            'name' => $client->name,
            'iin' => '870101300123',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'residents_count' => 1,
            'fixed_amount' => 0,
            'phone' => '+7 777 111 22 33',
            'contract' => 'Договор №24',
            'region_id' => $region->getKey(),
            'street_id' => $street->getKey(),
            'status' => 'active',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();
});

test('admin users cannot edit a client account number', function () {
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
    ]);
    $region = Region::factory()->for($organization)->create([
        'name' => 'Алмалинский район',
    ]);
    $street = Street::factory()->for($region)->create([
        'name' => 'Абая',
    ]);
    $client = Client::factory()
        ->for($organization)
        ->for($region)
        ->for($street)
        ->create([
            'account_number' => '100010',
            'name' => 'Неизменяемый счёт',
        ]);

    actingAsTenant($organization);

    Livewire::test(EditClient::class, [
        'record' => $client->getRouteKey(),
    ])
        ->fillForm([
            'account_number' => '999999',
            'name' => 'Изменённое имя',
            'iin' => '870101300456',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'residents_count' => 2,
            'fixed_amount' => 0,
            'phone' => '+7 777 333 44 55',
            'contract' => 'Договор №20',
            'technical_conditions' => 'ТУ-2026-20',
            'region_id' => $region->getKey(),
            'street_id' => $street->getKey(),
            'status' => 'active',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $client->refresh();

    expect($client->account_number)->toBe('100010')
        ->and($client->name)->toBe('Изменённое имя')
        ->and($client->iin)->toBe('870101300456')
        ->and($client->contract)->toBe('Договор №20')
        ->and($client->technical_conditions)->toBe('ТУ-2026-20');
});

test('admin client edit page has a card action', function () {
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
    ]);
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'account_number' => '100010',
            'name' => 'Абонент с карточкой',
        ]);

    actingAsTenant($organization);

    $url = route('filament.admin.clients.card', [
        'tenant' => $organization,
        'client' => $client,
    ]);

    Livewire::test(EditClient::class, [
        'record' => $client->getRouteKey(),
    ])
        ->assertActionExists('card')
        ->assertActionHasLabel('card', 'Карточка')
        ->assertActionHasUrl('card', $url)
        ->assertActionShouldOpenUrlInNewTab('card');
});

test('admin users can open a current tenant client card as a blade view', function () {
    $organization = Organization::factory()->create([
        'name' => 'ТОО Водоканал',
    ]);
    $utilityService = UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
        'unit_of_measurement' => 'м3',
    ]);
    $region = Region::factory()->for($organization)->create([
        'name' => 'Алмалинский район',
    ]);
    $street = Street::factory()->for($region)->create([
        'name' => 'Абая',
    ]);
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($region)
        ->for($street)
        ->create([
            'account_number' => '100010',
            'name' => 'Иванов Иван',
            'iin' => '870101300123',
            'phone' => '+7 777 111 22 33',
            'contract' => 'Договор №15',
            'technical_conditions' => 'ТУ-2026-15',
            'billing_type' => 'meter',
            'residents_count' => 3,
            'house' => '10',
            'apartment' => '15',
        ]);

    Meter::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($client)
        ->create([
            'number' => 'MTR-100010',
            'initial_reading' => 15.25,
        ]);

    Payment::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202605',
            'amount' => 2500,
            'paid_at' => '2026-05-26',
            'note' => 'Оплата через кассу',
        ]);

    $user = actingAsTenant($organization);
    $this->actingAs($user);

    $response = $this->get(route('filament.admin.clients.card', [
        'tenant' => $organization,
        'client' => $client,
    ]));

    $response
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Content-Disposition')
        ->assertViewIs('clients.card')
        ->assertViewHasAll([
            'client',
            'generatedAt',
            'clientDetails',
            'addressDetails',
            'billingDetails',
            'meters',
            'payments',
        ])
        ->assertSeeTextInOrder([
            'Карточка абонента',
            'Лицевой счёт',
            '100010',
            'Иванов Иван',
            'Счётчики',
            'MTR-100010',
            'Оплаты',
            '202605',
            '2 500.00 KZT',
        ]);

    expect(str_starts_with($response->getContent(), '%PDF'))->toBeFalse();
});

test('admin users cannot open another tenant client card', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $client = Client::factory()->for($otherOrganization)->create([
        'account_number' => '200010',
    ]);

    $user = actingAsTenant($organization);
    $this->actingAs($user);

    $this->get(route('filament.admin.clients.card', [
        'tenant' => $organization,
        'client' => $client,
    ]))->assertNotFound();
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
            'name' => 'Сидоров Сидор',
            'iin' => '870101300321',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'residents_count' => 1,
            'phone' => '+7 777 222 33 44',
            'contract' => 'Договор №16',
            'region_id' => $selectedRegion->getKey(),
            'street_id' => $streetFromOtherRegion->getKey(),
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasFormErrors(['street_id']);

    expect(Client::query()
        ->whereBelongsTo($organization)
        ->where('name', 'Сидоров Сидор')
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

test('client residents count is a required client data field', function () {
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create();
    $street = Street::factory()->for($region)->create();

    actingAsTenant($organization);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'Без количества',
            'iin' => '870101300654',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'residents_count' => null,
            'fixed_amount' => 0,
            'phone' => '+7 777 444 55 66',
            'contract' => 'Договор №17',
            'region_id' => $region->getKey(),
            'street_id' => $street->getKey(),
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasFormErrors(['residents_count']);
});

test('client residents count defaults to one', function () {
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create();
    $street = Street::factory()->for($region)->create();

    actingAsTenant($organization);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'Один проживающий',
            'iin' => '870101300987',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'fixed_amount' => 0,
            'phone' => '+7 777 555 66 77',
            'contract' => 'Договор №19',
            'region_id' => $region->getKey(),
            'street_id' => $street->getKey(),
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    expect(Client::query()
        ->whereBelongsTo($organization)
        ->where('name', 'Один проживающий')
        ->sole()
        ->residents_count)->toBe(1);
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
        ->assertFormFieldVisible('residents_count')
        ->assertFormFieldVisible('fixed_amount')
        ->fillForm([
            'billing_type' => 'meter',
        ])
        ->assertFormFieldVisible('residents_count')
        ->assertFormFieldHidden('fixed_amount');
});

test('admin client form ignores duplicate submitted account number', function () {
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create([
        'name' => 'Алмалинский район',
    ]);
    $street = Street::factory()->for($region)->create([
        'name' => 'Абая',
    ]);

    Client::factory()->for($organization)->create([
        'account_number' => '100001',
    ]);

    actingAsTenant($organization);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'account_number' => '100001',
            'name' => 'Петров Пётр',
            'iin' => '870101300147',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'residents_count' => 1,
            'fixed_amount' => 0,
            'phone' => '+7 777 666 77 88',
            'contract' => 'Договор №21',
            'region_id' => $region->getKey(),
            'street_id' => $street->getKey(),
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    expect($organization->clients()->where('account_number', '100001')->count())->toBe(1)
        ->and($organization->clients()
            ->where('account_number', '100002')
            ->where('name', 'Петров Пётр')
            ->exists())->toBeTrue();
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
        ->and(ReceiptResource::shouldRegisterNavigation())->toBeTrue();
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

    $receipt = Receipt::factory()
        ->for($organization)
        ->for($client)
        ->create([
            'period' => '202605',
            'receipt_number' => '202605-30001',
            'account_number' => '30001',
            'client_name' => 'Основной абонент',
            'utility_service_name' => 'Водоснабжение',
        ]);
    $otherReceipt = Receipt::factory()
        ->for($organization)
        ->for($otherClient)
        ->create([
            'period' => '202605',
            'receipt_number' => '202605-30002',
            'account_number' => '30002',
            'client_name' => 'Другой абонент',
            'utility_service_name' => 'Водоснабжение',
        ]);

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
        ->assertCanNotSeeTableRecords([$otherReceipt])
        ->assertTableActionHasUrl('open', route('filament.admin.receipts.print', [
            'tenant' => $organization,
            'receipt' => $receipt,
        ]), $receipt);
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
        ->mountTableAction('create')
        ->assertTableActionDataSet([
            'installed_on' => today()->toDateString(),
        ]);

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

    billingPeriodFor($organization);

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callTableAction('create', data: [
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
            ->forPeriod('202605')
            ->where('amount', 3500)
            ->exists())->toBeTrue()
        ->and(BalanceAdjustment::query()
            ->whereBelongsTo($organization)
            ->whereBelongsTo($client)
            ->forPeriod('202605')
            ->where('amount', 1500)
            ->exists())->toBeTrue();
});
