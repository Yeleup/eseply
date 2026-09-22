<?php

use App\Actions\BuildClientCardViewData;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Region;
use App\Models\Street;
use App\Models\User;
use App\OrganizationMemberRole;
use App\Support\ClientControllers;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A member of the organization with the given role and responsibility zone.
 *
 * @param  list<Region>  $regions
 * @param  list<Street>  $streets
 */
function clientControllersMember(
    Organization $organization,
    string $name,
    array $regions = [],
    array $streets = [],
    OrganizationMemberRole $role = OrganizationMemberRole::Controller,
    ?User $user = null,
): User {
    $user ??= User::factory()->create(['name' => $name]);
    $user->organizations()->attach($organization, ['role' => $role->value]);

    foreach ($regions as $region) {
        DB::table('organization_user_regions')->insert([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'region_id' => $region->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    foreach ($streets as $street) {
        DB::table('organization_user_streets')->insert([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'street_id' => $street->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $user;
}

function clientControllersActingAsOperator(Organization $organization): User
{
    $user = clientControllersMember($organization, 'Оператор', role: OrganizationMemberRole::Operator);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

/**
 * @return array{organization: Organization, region: Region, street: Street, otherStreet: Street, client: Client}
 */
function clientControllersFixture(): array
{
    $organization = Organization::factory()->create();
    $region = Region::factory()->for($organization)->create(['name' => 'Северный']);
    $street = Street::factory()->for($region)->create(['name' => 'Абая']);
    $otherStreet = Street::factory()->for($region)->create(['name' => 'Сатпаева']);
    $client = Client::factory()
        ->for($organization)
        ->for($region)
        ->for($street)
        ->create(['name' => 'Абонент с контроллерами']);

    return compact('organization', 'region', 'street', 'otherStreet', 'client');
}

test('client controllers are the organization controllers whose region or street covers the client', function () {
    [
        'organization' => $organization,
        'region' => $region,
        'street' => $street,
        'otherStreet' => $otherStreet,
    ] = clientControllersFixture();
    $otherRegion = Region::factory()->for($organization)->create();
    $otherRegionStreet = Street::factory()->for($otherRegion)->create();

    clientControllersMember($organization, 'Ержан', regions: [$region]);
    clientControllersMember($organization, 'Айгуль', streets: [$street]);
    clientControllersMember($organization, 'Болат', regions: [$region], streets: [$street]);
    clientControllersMember($organization, 'Вне зоны', regions: [$otherRegion], streets: [$otherStreet]);
    clientControllersMember($organization, 'Оператор района', regions: [$region], role: OrganizationMemberRole::Operator);

    $otherOrganization = Organization::factory()->create();
    $sameUserElsewhere = clientControllersMember($organization, 'Гульнар', streets: [$otherStreet]);
    clientControllersMember($otherOrganization, 'Гульнар', regions: [$region], streets: [$street], user: $sameUserElsewhere);

    $clientByRegion = Client::factory()->for($organization)->for($region)->for($otherStreet)->create();
    $clientWithoutControllers = Client::factory()->for($organization)->for($otherRegion)->for($otherRegionStreet)->create();
    $client = Client::query()->where('street_id', $street->getKey())->sole();

    $clientControllers = ClientControllers::forOrganization($organization);

    expect($clientControllers->namesFor($client))->toBe(['Айгуль', 'Болат', 'Ержан'])
        ->and($clientControllers->labelFor($client))->toBe('Айгуль, Болат, Ержан')
        ->and($clientControllers->namesFor($clientByRegion))->toBe(['Болат', 'Вне зоны', 'Гульнар', 'Ержан'])
        ->and($clientControllers->namesFor($clientWithoutControllers))->toBe(['Вне зоны'])
        ->and(ClientControllers::forOrganization($otherOrganization)->labelFor($client))->toBe('Гульнар');

    DB::table('organization_user_regions')->where('region_id', $otherRegion->getKey())->delete();

    expect(ClientControllers::forOrganization($organization)->labelFor($clientWithoutControllers))->toBe('-');
});

test('client controllers are sorted in russian alphabetical order', function () {
    [
        'organization' => $organization,
        'region' => $region,
        'street' => $street,
        'client' => $client,
    ] = clientControllersFixture();

    clientControllersMember($organization, 'Яна', regions: [$region]);
    clientControllersMember($organization, 'Ёлка', streets: [$street]);
    clientControllersMember($organization, 'Андрей', regions: [$region]);
    clientControllersMember($organization, 'ерлан', streets: [$street]);

    expect(ClientControllers::forOrganization($organization)->labelFor($client))->toBe('Андрей, Ёлка, ерлан, Яна');
});

test('clients list shows the client controllers in a toggleable column', function () {
    [
        'organization' => $organization,
        'region' => $region,
        'street' => $street,
        'otherStreet' => $otherStreet,
        'client' => $client,
    ] = clientControllersFixture();
    $clientWithoutControllers = Client::factory()->for($organization)->for(
        $otherRegion = Region::factory()->for($organization)->create(),
    )->for(Street::factory()->for($otherRegion))->create();

    clientControllersMember($organization, 'Ержан', regions: [$region]);
    clientControllersMember($organization, 'Айгуль', regions: [$region], streets: [$street]);
    clientControllersMember($organization, 'Болат', streets: [$otherStreet]);

    clientControllersActingAsOperator($organization);

    Livewire::test(ListClients::class)
        ->assertTableColumnExists('controllers', fn (TextColumn $column): bool => $column->getLabel() === 'Контроллеры'
            && $column->isToggleable()
            && ! $column->isToggledHiddenByDefault())
        ->assertCanRenderTableColumn('controllers')
        ->assertTableColumnStateSet('controllers', 'Айгуль, Ержан', $client)
        ->assertTableColumnStateSet('controllers', '-', $clientWithoutControllers)
        ->toggleAllTableColumns(false)
        ->assertCanNotRenderTableColumn('controllers');
});

test('clients list loads controller zones without a query per row', function () {
    [
        'organization' => $organization,
        'region' => $region,
        'street' => $street,
    ] = clientControllersFixture();

    clientControllersMember($organization, 'Ержан', regions: [$region]);
    clientControllersMember($organization, 'Айгуль', streets: [$street]);

    clientControllersActingAsOperator($organization);

    /*
     * Only the queries of the controllers column are counted: the other columns and the
     * record actions are not part of this feature.
     */
    $countZoneQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(ListClients::class)->assertSee('Айгуль, Ержан');

        $queryCount = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'organization_user_regions')
                || str_contains($query['query'], 'organization_user_streets'))
            ->count();

        DB::disableQueryLog();

        return $queryCount;
    };

    $queriesForOneRow = $countZoneQueries();

    Client::factory()->count(8)->for($organization)->for($region)->for($street)->create();

    expect(Client::query()->count())->toBe(9)
        ->and($queriesForOneRow)->toBe(2)
        ->and($countZoneQueries())->toBe(2);
});

test('client page shows the client controllers next to the address as read only', function () {
    [
        'organization' => $organization,
        'region' => $region,
        'street' => $street,
        'client' => $client,
    ] = clientControllersFixture();

    clientControllersMember($organization, 'Ержан', regions: [$region]);
    clientControllersMember($organization, 'Айгуль', streets: [$street]);

    clientControllersActingAsOperator($organization);

    Livewire::test(EditClient::class, [
        'record' => $client->getRouteKey(),
    ])
        ->assertSeeInOrder(['Улица', 'Контроллеры', 'Айгуль, Ержан', 'Дом'])
        ->assertFormFieldDoesNotExist('controllers')
        ->call('save')
        ->assertHasNoFormErrors();
});

test('controller sees the client controllers of a client in the zone', function () {
    [
        'organization' => $organization,
        'region' => $region,
        'street' => $street,
        'client' => $client,
    ] = clientControllersFixture();

    clientControllersMember($organization, 'Айгуль', streets: [$street]);
    $controller = clientControllersMember($organization, 'Ержан', regions: [$region]);

    Livewire::actingAs($controller);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    Livewire::test(ListClients::class)
        ->assertCanSeeTableRecords([$client])
        ->assertTableColumnStateSet('controllers', 'Айгуль, Ержан', $client);
});

test('printable client card shows the client controllers in the address block', function () {
    [
        'organization' => $organization,
        'region' => $region,
        'street' => $street,
        'client' => $client,
    ] = clientControllersFixture();

    clientControllersMember($organization, 'Ержан', regions: [$region]);
    clientControllersMember($organization, 'Айгуль', regions: [$region], streets: [$street]);

    expect(app(BuildClientCardViewData::class)->handle($client)['addressDetails'])
        ->toContain(['label' => 'Контроллеры', 'value' => 'Айгуль, Ержан']);

    $user = clientControllersActingAsOperator($organization);

    $this->actingAs($user)
        ->get(route('filament.admin.clients.card', [
            'tenant' => $organization,
            'client' => $client,
        ]))
        ->assertSuccessful()
        ->assertSeeTextInOrder(['Адрес', 'Улица', 'Абая', 'Контроллеры', 'Айгуль, Ержан', 'Настройки начисления']);
});
