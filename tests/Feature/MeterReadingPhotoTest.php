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
use App\Support\MeterReadingPhotoStorage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

test('creating a reading with a tampered foreign photo path is rejected', function () {
    Storage::fake('public');

    $victimOrganization = Organization::factory()->create();
    $victimMeter = Meter::factory()->for($victimOrganization)->create();
    $victimPhotoPath = "meter-reading-photos/{$victimOrganization->id}/victim.jpg";
    Storage::disk('public')->put($victimPhotoPath, 'victim');

    $victimReading = MeterReading::factory()->for($victimMeter)->create([
        'period' => '202605',
        'photo_path' => $victimPhotoPath,
    ]);

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
            'photo_path' => [(string) Str::uuid() => $victimPhotoPath],
        ])
        ->call('create')
        ->assertHasFormErrors(['photo_path']);

    expect(MeterReading::query()->whereBelongsTo($meter)->exists())->toBeFalse();
    Storage::disk('public')->assertExists($victimPhotoPath);
    expect($victimReading->refresh()->photo_path)->toBe($victimPhotoPath);
});

test('the client card reading action keeps an existing photo path when resubmitted unchanged', function () {
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

    $photoPath = "meter-reading-photos/{$organization->id}/existing.jpg";
    Storage::disk('public')->put($photoPath, 'existing');

    $reading = MeterReading::factory()->for($meter)->create([
        'period' => '202605',
        'photo_path' => $photoPath,
    ]);

    actingAsReadingPhotoTenant($organization);

    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callTableAction('addReading', $meter, data: [
            'current_reading' => 141.5,
            'photo_path' => [(string) Str::uuid() => $photoPath],
        ])
        ->assertHasNoTableActionErrors();

    expect($reading->refresh()->photo_path)->toBe($photoPath);
    Storage::disk('public')->assertExists($photoPath);
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

test('the photo storage helper exposes the disk and directory used by readings', function () {
    Storage::fake('public');
    Storage::disk('public')->put('meter-reading-photos/7/one.jpg', 'one');
    Storage::disk('public')->put('meter-reading-photos/7/two.jpg', 'two');
    Storage::disk('public')->put('meter-reading-photos/8/other.jpg', 'other');

    expect(MeterReadingPhotoStorage::disk())->toBe('public')
        ->and(MeterReadingPhotoStorage::directoryFor(7))->toBe('meter-reading-photos/7')
        ->and(MeterReading::PHOTO_DISK)->toBe(MeterReadingPhotoStorage::disk())
        ->and(MeterReading::photoDirectoryFor(7))->toBe(MeterReadingPhotoStorage::directoryFor(7));

    MeterReadingPhotoStorage::delete(null);
    MeterReadingPhotoStorage::delete('');
    Storage::disk('public')->assertExists('meter-reading-photos/7/one.jpg');

    MeterReadingPhotoStorage::deleteMany([
        'meter-reading-photos/7/one.jpg',
        null,
        '',
        'meter-reading-photos/7/two.jpg',
    ]);

    Storage::disk('public')->assertMissing('meter-reading-photos/7/one.jpg');
    Storage::disk('public')->assertMissing('meter-reading-photos/7/two.jpg');
    Storage::disk('public')->assertExists('meter-reading-photos/8/other.jpg');

    MeterReadingPhotoStorage::deleteOrganizationDirectory(8);

    Storage::disk('public')->assertMissing('meter-reading-photos/8/other.jpg');
});
