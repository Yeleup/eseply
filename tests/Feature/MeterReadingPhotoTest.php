<?php

use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\MetersRelationManager;
use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Filament\Resources\MeterReadings\Pages\ListMeterReadings;
use App\Filament\Resources\Meters\Pages\EditMeter;
use App\Filament\Resources\Meters\RelationManagers\ReadingsRelationManager;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use App\Models\UtilityService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsReadingPhotoTenant(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

test('meter readings store an optional photo path', function () {
    Storage::fake('public');

    $reading = MeterReading::factory()->create([
        'period' => '202605',
        'photo_path' => 'meter-reading-photos/1/photo.jpg',
    ]);

    expect($reading->refresh()->photo_path)->toBe('meter-reading-photos/1/photo.jpg')
        ->and(MeterReading::photoDirectoryFor(1))->toBe('meter-reading-photos/1');
});

test('replacing the photo deletes the old file from the disk', function () {
    Storage::fake('public');
    Storage::disk('public')->put('meter-reading-photos/1/old.jpg', 'old');
    Storage::disk('public')->put('meter-reading-photos/1/new.jpg', 'new');

    $reading = MeterReading::factory()->create([
        'period' => '202605',
        'photo_path' => 'meter-reading-photos/1/old.jpg',
    ]);

    $reading->update(['photo_path' => 'meter-reading-photos/1/new.jpg']);

    Storage::disk('public')->assertMissing('meter-reading-photos/1/old.jpg');
    Storage::disk('public')->assertExists('meter-reading-photos/1/new.jpg');
});

test('clearing the photo deletes the file from the disk', function () {
    Storage::fake('public');
    Storage::disk('public')->put('meter-reading-photos/1/old.jpg', 'old');

    $reading = MeterReading::factory()->create([
        'period' => '202605',
        'photo_path' => 'meter-reading-photos/1/old.jpg',
    ]);

    $reading->update(['photo_path' => null]);

    Storage::disk('public')->assertMissing('meter-reading-photos/1/old.jpg');
});

test('deleting a meter reading deletes its photo file', function () {
    Storage::fake('public');
    Storage::disk('public')->put('meter-reading-photos/1/photo.jpg', 'photo');

    $reading = MeterReading::factory()->create([
        'period' => '202605',
        'photo_path' => 'meter-reading-photos/1/photo.jpg',
    ]);

    $reading->delete();

    Storage::disk('public')->assertMissing('meter-reading-photos/1/photo.jpg');
});

test('updating a reading without touching the photo keeps the file', function () {
    Storage::fake('public');
    Storage::disk('public')->put('meter-reading-photos/1/photo.jpg', 'photo');

    $reading = MeterReading::factory()->create([
        'period' => '202605',
        'previous_reading' => 10,
        'current_reading' => 20,
        'photo_path' => 'meter-reading-photos/1/photo.jpg',
    ]);

    $reading->update(['current_reading' => 30]);

    Storage::disk('public')->assertExists('meter-reading-photos/1/photo.jpg');
    expect($reading->refresh()->photo_path)->toBe('meter-reading-photos/1/photo.jpg');
});

test('a meter reading can be created with a photo through the resource form', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();
    $meter = Meter::factory()->for($organization)->create([
        'initial_reading' => 100,
    ]);
    billingPeriodFor($organization);

    actingAsReadingPhotoTenant($organization);

    Livewire::test(CreateMeterReading::class)
        ->fillForm([
            'meter_id' => $meter->id,
            'current_reading' => 137.125,
            'photo_path' => UploadedFile::fake()->image('meter.jpg'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $reading = MeterReading::query()->whereBelongsTo($meter)->sole();

    expect($reading->photo_path)->not->toBeNull()
        ->and($reading->photo_path)->toStartWith("meter-reading-photos/{$organization->id}/");
    Storage::disk('public')->assertExists($reading->photo_path);
});

test('the client card reading action saves a photo', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'meter',
        ]);
    $meter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'initial_reading' => 100,
        ]);
    billingPeriodFor($organization);

    actingAsReadingPhotoTenant($organization);

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callTableAction('addReading', $meter, data: [
            'current_reading' => 140.75,
            'photo_path' => UploadedFile::fake()->image('meter.jpg'),
        ])
        ->assertHasNoTableActionErrors();

    $reading = MeterReading::query()->whereBelongsTo($meter)->sole();

    expect($reading->photo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($reading->photo_path);
});

test('reading tables show a photo column', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();
    $meter = Meter::factory()->for($organization)->create();
    $reading = MeterReading::factory()->for($meter)->create([
        'period' => '202605',
        'photo_path' => "meter-reading-photos/{$organization->id}/photo.jpg",
    ]);

    actingAsReadingPhotoTenant($organization);

    Livewire::test(ListMeterReadings::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$reading])
        ->assertTableColumnExists('photo_path');

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$reading])
        ->assertTableColumnExists('photo_path');
});
