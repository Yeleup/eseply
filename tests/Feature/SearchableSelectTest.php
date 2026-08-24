<?php

use App\Filament\Resources\BalanceAdjustments\Pages\CreateBalanceAdjustment;
use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Filament\Resources\Payments\Pages\CreatePayment;
use App\Models\City;
use App\Models\Client;
use App\Models\Meter;
use App\Models\Organization;
use App\Models\Region;
use App\Models\Street;
use App\Models\UtilityService;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Селекты абонента и счётчика раньше материализовали в память весь справочник
 * организации на каждой отрисовке формы. Тесты держат границу: список вариантов
 * ограничен, поиск идёт в базу, а сохранённое значение остаётся подписанным.
 */
function selectField(string $page, string $field): Select
{
    $component = Livewire::test($page)->instance()->form->getFlatFields()[$field] ?? null;

    expect($component)->toBeInstanceOf(Select::class);

    return $component;
}

test('селект абонента находит по лицевому счёту и по фамилии', function (string $term): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskClient($organization, $utilityService, ['name' => 'Иванов Иван']);
    paymentDeskClient($organization, $utilityService, ['name' => 'Петров Пётр']);

    $needle = $term === 'account' ? $client->account_number : 'Иванов';
    $results = selectField(CreatePayment::class, 'client_id')->getSearchResults($needle);

    expect($results)->toBe([$client->id => "{$client->account_number} - Иванов Иван"]);
})->with(['account', 'name']);

test('селект абонента не показывает абонентов другой организации', function (): void {
    ['organization' => $organization] = paymentDeskOrganization();
    billingPeriodFor($organization);

    $otherOrganization = Organization::factory()->create();
    $otherService = UtilityService::factory()->for($otherOrganization)->create();
    paymentDeskClient($otherOrganization, $otherService, ['name' => 'Чужой Абонент']);

    paymentDeskOperator($organization);

    expect(selectField(CreatePayment::class, 'client_id')->getSearchResults('Чужой'))->toBe([]);
});

test('селект абонента не отдаёт весь справочник разом', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskOperator($organization);

    Client::factory()->count(60)->for($organization)->for($utilityService)->create([
        'status' => 'active',
        'billing_type' => 'per_person',
    ]);

    $options = selectField(CreatePayment::class, 'client_id')->getOptions();

    expect(Client::query()->count())->toBe(60)
        ->and(count($options))->toBe(50);
});

test('селект абонента подписывает сохранённое значение вне первой страницы', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskOperator($organization);

    Client::factory()->count(60)->for($organization)->for($utilityService)->create([
        'status' => 'active',
        'billing_type' => 'per_person',
    ]);

    $last = Client::query()->orderByDesc('account_number')->firstOrFail();

    $page = Livewire::test(CreatePayment::class)->fillForm(['client_id' => $last->id]);
    $field = $page->instance()->form->getFlatFields()['client_id'];

    expect(array_key_exists($last->id, $field->getOptions()))->toBeFalse()
        ->and($field->getOptionLabel())->toBe("{$last->account_number} - {$last->name}");
});

test('селект абонента в корректировке сальдо ведёт себя так же', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskClient($organization, $utilityService, ['name' => 'Иванов Иван']);

    expect(selectField(CreateBalanceAdjustment::class, 'client_id')->getSearchResults('Иванов'))
        ->toBe([$client->id => "{$client->account_number} - Иванов Иван"]);
});

test('селект счётчика ищет по номеру и по лицевому счёту', function (string $term): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    billingPeriodFor($organization);
    paymentDeskOperator($organization);

    $client = paymentDeskClient($organization, $utilityService, ['billing_type' => 'meter']);
    $meter = Meter::factory()->for($organization)->for($client)->for($utilityService)
        ->create(['number' => 'MTR-777', 'status' => 'active']);

    Meter::factory()->for($organization)->for($utilityService)->create(['number' => 'MTR-888', 'status' => 'active']);

    $needle = $term === 'number' ? 'MTR-777' : $client->account_number;
    $results = selectField(CreateMeterReading::class, 'meter_id')->getSearchResults($needle);

    expect($results)->toBe([$meter->id => "MTR-777 - {$client->account_number}"]);
})->with(['number', 'account']);

test('селект счётчика у контролёра ограничен его зоной', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = paymentDeskOrganization();
    billingPeriodFor($organization);

    $city = City::query()->create(['organization_id' => $organization->id, 'name' => 'Алматы']);
    $region = Region::query()->create(['city_id' => $city->id, 'name' => 'Алмалинский']);
    $foreignRegion = Region::query()->create(['city_id' => $city->id, 'name' => 'Бостандыкский']);
    Street::query()->create(['region_id' => $region->id, 'name' => 'Абая']);

    paymentDeskController($organization);
    DB::table('organization_user_regions')->insert([
        'organization_id' => $organization->id,
        'user_id' => auth()->id(),
        'region_id' => $region->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ownClient = paymentDeskClient($organization, $utilityService, ['billing_type' => 'meter', 'region_id' => $region->id]);
    $ownMeter = Meter::factory()->for($organization)->for($ownClient)->for($utilityService)
        ->create(['number' => 'MTR-OWN', 'status' => 'active']);

    $foreignClient = paymentDeskClient($organization, $utilityService, ['billing_type' => 'meter', 'region_id' => $foreignRegion->id]);
    Meter::factory()->for($organization)->for($foreignClient)->for($utilityService)
        ->create(['number' => 'MTR-FOREIGN', 'status' => 'active']);

    $field = selectField(CreateMeterReading::class, 'meter_id');

    expect(array_keys($field->getOptions()))->toBe([$ownMeter->id])
        ->and($field->getSearchResults('MTR-FOREIGN'))->toBe([]);
});
