<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\TariffCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('tariff categories belong to an organization', function () {
    $organization = Organization::factory()->create();
    $tariffCategory = TariffCategory::factory()->for($organization)->create([
        'name' => 'Население',
    ]);

    expect($tariffCategory->organization->is($organization))->toBeTrue()
        ->and($organization->tariffCategories()->whereKey($tariffCategory)->exists())->toBeTrue();
});

test('clients can choose a tariff category', function () {
    $organization = Organization::factory()->create();
    $tariffCategory = TariffCategory::factory()->for($organization)->create([
        'name' => 'Юрлица',
    ]);
    $client = Client::factory()
        ->for($organization)
        ->for($tariffCategory)
        ->create();

    expect($client->tariffCategory->is($tariffCategory))->toBeTrue()
        ->and($tariffCategory->clients()->whereKey($client)->exists())->toBeTrue();
});

test('tariff category names can repeat across organizations', function () {
    TariffCategory::factory()->for(Organization::factory())->create([
        'name' => 'Население',
    ]);

    $tariffCategory = TariffCategory::factory()->for(Organization::factory())->create([
        'name' => 'Население',
    ]);

    expect($tariffCategory)->toBeInstanceOf(TariffCategory::class);
});
