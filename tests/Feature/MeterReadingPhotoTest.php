<?php

use App\BillingPeriodStatus;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\MetersRelationManager;
use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Filament\Resources\MeterReadings\Pages\EditMeterReading;
use App\Filament\Resources\MeterReadings\Pages\ListMeterReadings;
use App\Filament\Resources\MeterReadings\Schemas\MeterReadingForm;
use App\Filament\Resources\Meters\Pages\EditMeter;
use App\Filament\Resources\Meters\RelationManagers\ReadingsRelationManager;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\Region;
use App\Models\Street;
use App\Models\User;
use App\Models\UtilityService;
use App\OrganizationMemberRole;
use App\Support\MeterReadingPhotoStorage;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

/**
 * A member of the organization with an explicit role, optionally assigned to
 * the region a controller is allowed to work in.
 */
function actingAsReadingResourceMember(Organization $organization, OrganizationMemberRole $role, ?Region $region = null): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, [
        'role' => $role->value,
    ]);

    if ($region instanceof Region) {
        DB::table('organization_user_regions')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'region_id' => $region->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

/**
 * A meter of a region-assigned client with an open month, where the previous
 * reading of the current month is the meter's initial reading.
 *
 * @return array{organization: Organization, region: Region, meter: Meter}
 */
function readingResourceMeterFixture(int $previousReading): array
{
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create();
    $street = Street::factory()->for($region)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($region)
        ->for($street)
        ->create([
            'billing_type' => 'meter',
        ]);
    $meter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'initial_reading' => $previousReading,
        ]);

    billingPeriodFor($organization);

    return compact('organization', 'region', 'meter');
}

/**
 * @return array{organization: Organization, region: Region, client: Client, meter: Meter}
 */
function readingResourceMeterWithConsumptionHistory(): array
{
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->create();
    $street = Street::factory()->for($region)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->for($region)
        ->for($street)
        ->create(['billing_type' => 'meter']);
    $meter = Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create(['initial_reading' => 100]);
    $previousReading = 100;

    foreach ([10, 20, 30] as $index => $consumption) {
        $billingPeriod = billingPeriodFor($organization, sprintf('20260%d', $index + 2));
        $currentReading = $previousReading + $consumption;

        MeterReading::query()->create([
            'meter_id' => $meter->id,
            'billing_period_id' => $billingPeriod->id,
            'previous_reading' => $previousReading,
            'current_reading' => $currentReading,
        ]);

        $billingPeriod->forceFill([
            'status' => BillingPeriodStatus::Closed,
            'closed_at' => now(),
        ])->save();

        $previousReading = $currentReading;
    }

    billingPeriodFor($organization, '202605');

    return compact('organization', 'region', 'client', 'meter');
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
            'current_reading' => 137,
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
            'current_reading' => 137,
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
            'current_reading' => 137,
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
            'current_reading' => 140,
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
            'current_reading' => 141,
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
            'current_reading' => 140,
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
            'current_reading' => 137,
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
            'current_reading' => 137,
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
            'current_reading' => 137,
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

test('a controller cannot create a reading below the previous one', function () {
    ['organization' => $organization, 'region' => $region, 'meter' => $meter] = readingResourceMeterFixture(100);

    actingAsReadingResourceMember($organization, OrganizationMemberRole::Controller, $region);

    Livewire::test(CreateMeterReading::class)
        ->fillForm([
            'meter_id' => $meter->id,
            'current_reading' => 90,
        ])
        ->call('create')
        ->assertHasFormErrors(['current_reading']);

    expect(MeterReading::query()->whereBelongsTo($meter)->exists())->toBeFalse();
});

test('a controller can create a reading equal to the previous one', function () {
    ['organization' => $organization, 'region' => $region, 'meter' => $meter] = readingResourceMeterFixture(100);

    actingAsReadingResourceMember($organization, OrganizationMemberRole::Controller, $region);

    Livewire::test(CreateMeterReading::class)
        ->fillForm([
            'meter_id' => $meter->id,
            'current_reading' => 100,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $reading = MeterReading::query()->whereBelongsTo($meter)->sole();

    expect($reading->previous_reading)->toBe(100)
        ->and($reading->current_reading)->toBe(100)
        ->and($reading->consumption)->toBe(0);
});

test('a controller confirms large consumption in the meter reading resource form', function (): void {
    ['organization' => $organization, 'region' => $region, 'meter' => $meter] = readingResourceMeterWithConsumptionHistory();
    actingAsReadingResourceMember($organization, OrganizationMemberRole::Controller, $region);

    $page = Livewire::test(CreateMeterReading::class)
        ->fillForm([
            'meter_id' => $meter->id,
            'current_reading' => 221,
        ])
        ->call('create')
        ->assertActionMounted('confirmLargeConsumption');

    expect(MeterReading::query()->whereBelongsTo($meter)->forPeriod('202605')->exists())->toBeFalse();

    $page
        ->unmountAction()
        ->assertActionNotMounted()
        ->call('create')
        ->assertActionMounted('confirmLargeConsumption')
        ->callMountedAction();

    expect(MeterReading::query()->whereBelongsTo($meter)->forPeriod('202605')->value('current_reading'))->toBe(221);
});

test('a controller confirms large consumption when editing through the meter reading resource form', function (): void {
    ['organization' => $organization, 'region' => $region, 'meter' => $meter] = readingResourceMeterWithConsumptionHistory();
    $reading = MeterReading::query()->create([
        'meter_id' => $meter->id,
        'billing_period_id' => BillingPeriod::currentEditableFor($organization)?->id,
        'previous_reading' => 160,
        'current_reading' => 200,
    ]);
    actingAsReadingResourceMember($organization, OrganizationMemberRole::Controller, $region);

    $page = Livewire::test(EditMeterReading::class, ['record' => $reading->getRouteKey()])
        ->fillForm(['current_reading' => 221])
        ->call('save')
        ->assertActionMounted('confirmLargeConsumption');

    expect($reading->refresh()->current_reading)->toBe(200);

    $page
        ->unmountAction()
        ->assertActionNotMounted()
        ->call('save')
        ->assertActionMounted('confirmLargeConsumption')
        ->callMountedAction()
        ->assertActionNotMounted();

    expect($reading->refresh()->current_reading)->toBe(221);
});

test('a controller confirms large consumption in the meter card readings table', function (): void {
    ['organization' => $organization, 'region' => $region, 'meter' => $meter] = readingResourceMeterWithConsumptionHistory();
    actingAsReadingResourceMember($organization, OrganizationMemberRole::Controller, $region);

    $page = Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->callTableAction('create', data: ['current_reading' => 221])
        ->assertActionMounted([
            TestAction::make('create')->table(),
            'confirmLargeConsumption',
        ]);

    expect(MeterReading::query()->whereBelongsTo($meter)->forPeriod('202605')->exists())->toBeFalse();

    $page
        ->unmountAction()
        ->assertActionMounted(TestAction::make('create')->table())
        ->callMountedAction()
        ->assertActionMounted([
            TestAction::make('create')->table(),
            'confirmLargeConsumption',
        ])
        ->callMountedAction()
        ->assertActionNotMounted();

    expect(MeterReading::query()->whereBelongsTo($meter)->forPeriod('202605')->value('current_reading'))->toBe(221);
});

test('a controller confirms large consumption when editing the meter card readings table', function (): void {
    ['organization' => $organization, 'region' => $region, 'meter' => $meter] = readingResourceMeterWithConsumptionHistory();
    $reading = MeterReading::query()->create([
        'meter_id' => $meter->id,
        'billing_period_id' => BillingPeriod::currentEditableFor($organization)?->id,
        'previous_reading' => 160,
        'current_reading' => 200,
    ]);
    actingAsReadingResourceMember($organization, OrganizationMemberRole::Controller, $region);

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditMeter::class,
    ])
        ->callTableAction('edit', $reading, data: ['current_reading' => 221])
        ->assertActionMounted([
            TestAction::make('edit')->table($reading),
            'confirmLargeConsumption',
        ])
        ->callMountedAction()
        ->assertActionNotMounted();

    expect($reading->refresh()->current_reading)->toBe(221);
});

test('a controller confirms large consumption in the client card meter action', function (): void {
    ['organization' => $organization, 'region' => $region, 'client' => $client, 'meter' => $meter] = readingResourceMeterWithConsumptionHistory();
    actingAsReadingResourceMember($organization, OrganizationMemberRole::Controller, $region);

    $page = Livewire::test(MetersRelationManager::class, [
        'ownerRecord' => $client,
        'pageClass' => EditClient::class,
    ])
        ->callTableAction('addReading', $meter, data: ['current_reading' => 221])
        ->assertActionMounted([
            TestAction::make('addReading')->table($meter),
            'confirmLargeConsumption',
        ]);

    expect(MeterReading::query()->whereBelongsTo($meter)->forPeriod('202605')->exists())->toBeFalse();

    $page
        ->callMountedAction()
        ->assertActionNotMounted();

    expect(MeterReading::query()->whereBelongsTo($meter)->forPeriod('202605')->value('current_reading'))->toBe(221);
});

test('an operator can create a reading below the previous one', function () {
    ['organization' => $organization, 'meter' => $meter] = readingResourceMeterFixture(100);

    actingAsReadingResourceMember($organization, OrganizationMemberRole::Operator);

    Livewire::test(CreateMeterReading::class)
        ->fillForm([
            'meter_id' => $meter->id,
            'current_reading' => 90,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $reading = MeterReading::query()->whereBelongsTo($meter)->sole();

    expect($reading->previous_reading)->toBe(100)
        ->and($reading->current_reading)->toBe(90)
        ->and($reading->consumption)->toBe(-10);
});

test('a controller cannot lower an existing reading below the previous one', function () {
    ['organization' => $organization, 'region' => $region, 'meter' => $meter] = readingResourceMeterFixture(100);

    $reading = MeterReading::factory()->for($meter)->create([
        'period' => '202605',
        'previous_reading' => 100,
        'current_reading' => 130,
    ]);

    actingAsReadingResourceMember($organization, OrganizationMemberRole::Controller, $region);

    Livewire::test(EditMeterReading::class, [
        'record' => $reading->getRouteKey(),
    ])
        ->fillForm([
            'current_reading' => 90,
        ])
        ->call('save')
        ->assertHasFormErrors(['current_reading']);

    expect($reading->refresh()->current_reading)->toBe(130);

    Livewire::test(EditMeterReading::class, [
        'record' => $reading->getRouteKey(),
    ])
        ->fillForm([
            'current_reading' => 100,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($reading->refresh()->current_reading)->toBe(100)
        ->and($reading->consumption)->toBe(0);
});

test('the photo field wires up the camera capture buttons', function (): void {
    $photoUpload = MeterReadingForm::photoUpload();

    // The buttons themselves are built in the browser by
    // `resources/js/meter-photo-capture.js`; what has to hold on the PHP side
    // is that the field still asks for them, on every form that uses it.
    expect($photoUpload->getExtraAlpineAttributes())
        ->toBe(['x-init' => 'window.initMeterPhotoCapture?.($el, $data)'])
        // FilePond checks the size of the picked file before it downscales it,
        // so the ceiling has to clear a raw phone camera shot.
        ->and($photoUpload->getMaxSize())->toBe(25600)
        ->and($photoUpload->getAcceptedFileTypes())->toBe(['image/*']);
});
