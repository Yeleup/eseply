<?php

use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\MetersRelationManager;
use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Filament\Resources\MeterReadings\Pages\EditMeterReading;
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

test('editing a meter reading keeps its own photo path when resubmitted unchanged', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();
    $meter = Meter::factory()->for($organization)->create([
        'initial_reading' => 100,
    ]);
    billingPeriodFor($organization);

    $photoPath = "meter-reading-photos/{$organization->id}/own.jpg";
    Storage::disk('public')->put($photoPath, 'own');

    $reading = MeterReading::factory()->for($meter)->create([
        'period' => '202605',
        'photo_path' => $photoPath,
    ]);

    actingAsReadingPhotoTenant($organization);

    Livewire::test(EditMeterReading::class, [
        'record' => $reading->getRouteKey(),
    ])
        ->fillForm([
            'photo_path' => [(string) Str::uuid() => $photoPath],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($reading->refresh()->photo_path)->toBe($photoPath);
    Storage::disk('public')->assertExists($photoPath);
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

test('a photo path belonging to another meter of the same organization is rejected', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();

    $otherMeter = Meter::factory()->for($organization)->create();
    $otherPhotoPath = "meter-reading-photos/{$organization->id}/other-meter.jpg";
    Storage::disk('public')->put($otherPhotoPath, 'other');

    $otherReading = MeterReading::factory()->for($otherMeter)->create([
        'period' => '202604',
        'photo_path' => $otherPhotoPath,
    ]);
    closedBillingPeriodFor($organization, '202604');

    $meter = Meter::factory()->for($organization)->create([
        'initial_reading' => 100,
    ]);
    billingPeriodFor($organization);

    actingAsReadingPhotoTenant($organization);

    Livewire::test(CreateMeterReading::class)
        ->fillForm([
            'meter_id' => $meter->id,
            'current_reading' => 137.125,
            'photo_path' => [(string) Str::uuid() => $otherPhotoPath],
        ])
        ->call('create')
        ->assertHasFormErrors(['photo_path']);

    expect(MeterReading::query()->whereBelongsTo($meter)->exists())->toBeFalse();
    Storage::disk('public')->assertExists($otherPhotoPath);
    expect($otherReading->refresh()->photo_path)->toBe($otherPhotoPath);
});

test('the client card reading action rejects an injected meter_id pointing at another meter\'s photo', function () {
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

    $otherMeter = Meter::factory()->for($organization)->create();
    $otherPhotoPath = "meter-reading-photos/{$organization->id}/other-meter-injected.jpg";
    Storage::disk('public')->put($otherPhotoPath, 'other');
    $otherReading = MeterReading::factory()->for($otherMeter)->create([
        'period' => '202604',
        'photo_path' => $otherPhotoPath,
    ]);
    closedBillingPeriodFor($organization, '202604');

    billingPeriodFor($organization);

    actingAsReadingPhotoTenant($organization);

    // The "meter_id" key does not exist in the addReading modal schema (the
    // meter is always the row record), but a crafted Livewire payload can
    // still inject it into the mounted action's raw state. If the photo
    // path validator trusted that injected key over the actual row record,
    // it would validate against the wrong meter while the reading is
    // actually written to $meter.
    Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callTableAction('addReading', $meter, data: [
            'current_reading' => 140.75,
            'meter_id' => $otherMeter->id,
            'photo_path' => [(string) Str::uuid() => $otherPhotoPath],
        ])
        ->assertHasTableActionErrors(['photo_path']);

    expect(MeterReading::query()->whereBelongsTo($meter)->exists())->toBeFalse();
    Storage::disk('public')->assertExists($otherPhotoPath);
    expect($otherReading->refresh()->photo_path)->toBe($otherPhotoPath);
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

test('the meter card readings relation manager saves a photo through the create action', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();
    $meter = Meter::factory()->for($organization)->create([
        'initial_reading' => 100,
    ]);
    billingPeriodFor($organization);

    actingAsReadingPhotoTenant($organization);

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->callTableAction('create', data: [
            'current_reading' => 137.125,
            'photo_path' => UploadedFile::fake()->image('meter.jpg'),
        ])
        ->assertHasNoTableActionErrors();

    $reading = MeterReading::query()->whereBelongsTo($meter)->sole();

    expect($reading->photo_path)->not->toBeNull()
        ->and($reading->photo_path)->toStartWith("meter-reading-photos/{$organization->id}/");
    Storage::disk('public')->assertExists($reading->photo_path);
});

test('the meter card readings relation manager rejects a tampered photo path from another meter', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();

    $otherMeter = Meter::factory()->for($organization)->create();
    $otherPhotoPath = "meter-reading-photos/{$organization->id}/other-meter-modal.jpg";
    Storage::disk('public')->put($otherPhotoPath, 'other');

    $otherReading = MeterReading::factory()->for($otherMeter)->create([
        'period' => '202604',
        'photo_path' => $otherPhotoPath,
    ]);
    closedBillingPeriodFor($organization, '202604');

    $meter = Meter::factory()->for($organization)->create([
        'initial_reading' => 100,
    ]);
    billingPeriodFor($organization);

    actingAsReadingPhotoTenant($organization);

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->callTableAction('create', data: [
            'current_reading' => 137.125,
            'photo_path' => [(string) Str::uuid() => $otherPhotoPath],
        ])
        ->assertHasTableActionErrors(['photo_path']);

    expect(MeterReading::query()->whereBelongsTo($meter)->exists())->toBeFalse();
    Storage::disk('public')->assertExists($otherPhotoPath);
    expect($otherReading->refresh()->photo_path)->toBe($otherPhotoPath);
});

test('the meter card readings relation manager create action rejects an injected meter_id pointing at another meter photo', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();
    $meter = Meter::factory()->for($organization)->create([
        'initial_reading' => 100,
    ]);

    $otherMeter = Meter::factory()->for($organization)->create();
    $otherPhotoPath = "meter-reading-photos/{$organization->id}/other-meter-modal-injected.jpg";
    Storage::disk('public')->put($otherPhotoPath, 'other');
    $otherReading = MeterReading::factory()->for($otherMeter)->create([
        'period' => '202604',
        'photo_path' => $otherPhotoPath,
    ]);
    closedBillingPeriodFor($organization, '202604');

    billingPeriodFor($organization);

    actingAsReadingPhotoTenant($organization);

    // The "create" modal for a meter's readings relation manager has no
    // "meter_id" field either (the owner record IS the meter), but a
    // crafted payload can still inject the key into the mounted action's
    // raw state and try to shift photo path validation to another meter
    // of the same organization while the reading is actually created for
    // $meter (the relation manager's owner record).
    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->callTableAction('create', data: [
            'current_reading' => 137.125,
            'meter_id' => $otherMeter->id,
            'photo_path' => [(string) Str::uuid() => $otherPhotoPath],
        ])
        ->assertHasTableActionErrors(['photo_path']);

    expect(MeterReading::query()->whereBelongsTo($meter)->exists())->toBeFalse();
    Storage::disk('public')->assertExists($otherPhotoPath);
    expect($otherReading->refresh()->photo_path)->toBe($otherPhotoPath);
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

test('deleting a meter deletes the photo files of its readings', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();
    $meter = Meter::factory()->for($organization)->create();
    $otherMeter = Meter::factory()->for($organization)->create();

    $firstPhotoPath = "meter-reading-photos/{$organization->id}/first.jpg";
    $secondPhotoPath = "meter-reading-photos/{$organization->id}/second.jpg";
    $keptPhotoPath = "meter-reading-photos/{$organization->id}/kept.jpg";
    Storage::disk('public')->put($firstPhotoPath, 'first');
    Storage::disk('public')->put($secondPhotoPath, 'second');
    Storage::disk('public')->put($keptPhotoPath, 'kept');

    MeterReading::factory()->for($meter)->create([
        'period' => '202604',
        'photo_path' => $firstPhotoPath,
    ]);
    closedBillingPeriodFor($organization, '202604');

    MeterReading::factory()->for($meter)->create([
        'period' => '202605',
        'photo_path' => $secondPhotoPath,
    ]);

    MeterReading::factory()->for($otherMeter)->create([
        'period' => '202605',
        'photo_path' => $keptPhotoPath,
    ]);

    $meter->delete();

    Storage::disk('public')->assertMissing($firstPhotoPath);
    Storage::disk('public')->assertMissing($secondPhotoPath);
    Storage::disk('public')->assertExists($keptPhotoPath);
    expect(MeterReading::query()->whereBelongsTo($meter)->exists())->toBeFalse();
});

test('deleting a meter without photos succeeds', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();
    $meter = Meter::factory()->for($organization)->create();

    MeterReading::factory()->for($meter)->create([
        'period' => '202605',
        'photo_path' => null,
    ]);

    $meter->delete();

    expect(Meter::query()->whereKey($meter->getKey())->exists())->toBeFalse();
});

test('deleting an organization deletes its meter reading photo directory', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();

    $photoPath = "meter-reading-photos/{$organization->id}/photo.jpg";
    $otherPhotoPath = "meter-reading-photos/{$otherOrganization->id}/photo.jpg";
    Storage::disk('public')->put($photoPath, 'photo');
    Storage::disk('public')->put($otherPhotoPath, 'other');

    $organization->delete();

    Storage::disk('public')->assertMissing($photoPath);
    Storage::disk('public')->assertExists($otherPhotoPath);
});
