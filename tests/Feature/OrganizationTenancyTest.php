<?php

use App\Filament\Pages\Tenancy\RegisterOrganization;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
        ])
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect();

    $organization = Organization::query()->where('bin_iin', '123456789012')->firstOrFail();

    expect($organization->name)
        ->toBe('ТОО Коммунальные услуги')
        ->and($user->organizations()->whereKey($organization)->exists())->toBeTrue();
});
