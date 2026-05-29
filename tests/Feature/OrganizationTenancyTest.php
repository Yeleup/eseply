<?php

use App\Filament\Pages\Tenancy\EditOrganizationProfile;
use App\Filament\Pages\Tenancy\RegisterOrganization;
use App\Filament\Pages\Tenancy\RelationManagers\RegionsRelationManager;
use App\Filament\Resources\Regions\Pages\EditRegion;
use App\Filament\Resources\Regions\RegionResource;
use App\Filament\Resources\Regions\RelationManagers\StreetsRelationManager;
use App\Models\Organization;
use App\Models\Region;
use App\Models\Street;
use App\Models\User;
use App\Models\UtilityService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsOrganizationTenant(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

test('users can access only attached organization tenants', function () {
    $user = User::factory()->create();
    $ownedOrganization = Organization::factory()->create(['name' => 'A Organization']);
    $otherOrganization = Organization::factory()->create(['name' => 'B Organization']);

    $user->organizations()->attach($ownedOrganization);

    expect($user->getTenants(Filament::getPanel('admin'))->pluck('id')->all())
        ->toBe([$ownedOrganization->id])
        ->and($user->canAccessTenant($ownedOrganization))->toBeTrue()
        ->and($user->canAccessTenant($otherOrganization))->toBeFalse();
});

test('tenant registration creates an organization and attaches the current user', function () {
    $user = User::factory()->create();

    Filament::setCurrentPanel('admin');

    Livewire::actingAs($user)
        ->test(RegisterOrganization::class)
        ->set('data', [
            'name' => 'ТОО Коммунальные услуги',
            'bin_iin' => '123456789012',
            'phone' => '+7 777 000 00 00',
            'address' => 'Алматы, Абая 10',
            'bank' => 'Kaspi Bank',
            'iban' => 'KZ86125KZT5004100100',
            'note' => 'Основная организация',
            'utility_service_name' => 'Водоснабжение',
            'utility_service_unit_of_measurement' => 'м3',
        ])
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect();

    $organization = Organization::query()->where('bin_iin', '123456789012')->firstOrFail();

    expect($organization->name)
        ->toBe('ТОО Коммунальные услуги')
        ->and($user->organizations()->whereKey($organization)->exists())->toBeTrue()
        ->and($organization->utilityService->name)->toBe('Водоснабжение')
        ->and($organization->utilityService->unit_of_measurement)->toBe('м3');
});

test('tenant profile updates organization utility service', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create([
        'name' => 'Водоснабжение',
        'unit_of_measurement' => 'м3',
    ]);

    $user->organizations()->attach($organization);

    Livewire::actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    Livewire::test(EditOrganizationProfile::class)
        ->fillForm([
            'name' => 'ТОО Тазалык',
            'bin_iin' => $organization->bin_iin,
            'phone' => '+7 777 000 11 22',
            'address' => $organization->address,
            'bank' => $organization->bank,
            'iban' => $organization->iban,
            'note' => $organization->note,
            'utility_service_name' => 'Вывоз мусора',
            'utility_service_unit_of_measurement' => 'месяц',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $organization->refresh();

    expect($organization->name)
        ->toBe('ТОО Тазалык')
        ->and($organization->utilityService->name)->toBe('Вывоз мусора')
        ->and($organization->utilityService->unit_of_measurement)->toBe('месяц')
        ->and($organization->utilityService()->count())->toBe(1);
});

test('regions and streets belong to an organization', function () {
    $organization = Organization::factory()->create();
    $region = Region::factory()
        ->for($organization)
        ->create([
            'name' => 'Алмалинский район',
        ]);

    $street = Street::factory()
        ->for($region)
        ->create([
            'name' => 'Абая',
        ]);

    expect($region->organization->is($organization))->toBeTrue()
        ->and($organization->regions()->whereKey($region)->exists())->toBeTrue()
        ->and($street->region->is($region))->toBeTrue()
        ->and($street->organization->is($organization))->toBeTrue()
        ->and($organization->streets()->whereKey($street)->exists())->toBeTrue();
});

test('region and street names are unique inside their owner', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $region = Region::factory()
        ->for($organization)
        ->create(['name' => 'Центр']);

    expect(fn () => Region::factory()
        ->for($organization)
        ->create(['name' => 'Центр']))->toThrow(QueryException::class);

    $sameNameInOtherOrganization = Region::factory()
        ->for($otherOrganization)
        ->create(['name' => 'Центр']);

    Street::factory()
        ->for($region)
        ->create(['name' => 'Абая']);

    expect(fn () => Street::factory()
        ->for($region)
        ->create(['name' => 'Абая']))->toThrow(QueryException::class);

    $sameNameInOtherRegion = Street::factory()
        ->for($sameNameInOtherOrganization)
        ->create(['name' => 'Абая']);

    expect($sameNameInOtherOrganization)->toBeInstanceOf(Region::class)
        ->and($sameNameInOtherRegion)->toBeInstanceOf(Street::class);
});

test('tenant profile manages organization regions', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $currentRegion = Region::factory()
        ->for($organization)
        ->create(['name' => 'Алмалинский']);
    $otherRegion = Region::factory()
        ->for($otherOrganization)
        ->create(['name' => 'Медеуский']);

    actingAsOrganizationTenant($organization);

    Livewire::test(RegionsRelationManager::class, [
        'ownerRecord' => $organization,
        'pageClass' => EditOrganizationProfile::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$currentRegion])
        ->assertCanNotSeeTableRecords([$otherRegion])
        ->callTableAction('create', data: [
            'name' => 'Бостандыкский',
        ])
        ->assertHasNoTableActionErrors();

    expect($organization->regions()->where('name', 'Бостандыкский')->exists())->toBeTrue();
});

test('region resource manages streets as a related table', function () {
    $organization = Organization::factory()->create();
    $region = Region::factory()
        ->for($organization)
        ->create(['name' => 'Алмалинский']);
    $street = Street::factory()
        ->for($region)
        ->create(['name' => 'Абая']);
    $otherStreet = Street::factory()
        ->for(Region::factory()->for($organization))
        ->create(['name' => 'Сейфуллина']);

    actingAsOrganizationTenant($organization);

    expect(RegionResource::shouldRegisterNavigation())->toBeFalse()
        ->and(RegionResource::getRelations())->toContain(StreetsRelationManager::class);

    Livewire::test(StreetsRelationManager::class, [
        'ownerRecord' => $region,
        'pageClass' => EditRegion::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$street])
        ->assertCanNotSeeTableRecords([$otherStreet])
        ->callTableAction('create', data: [
            'name' => 'Толе би',
        ])
        ->assertHasNoTableActionErrors();

    $createdStreet = $region->streets()->where('name', 'Толе би')->sole();

    expect($createdStreet->organization->is($organization))->toBeTrue();
});
