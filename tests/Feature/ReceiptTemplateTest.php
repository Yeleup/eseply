<?php

use App\Models\Organization;
use App\Models\ReceiptTemplate;
use App\Support\ReceiptTemplateDefaults;
use App\Support\ReceiptTemplateImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('receipt template belongs to an organization and stores settings json', function () {
    $template = ReceiptTemplate::factory()->create();

    expect($template->organization)->toBeInstanceOf(Organization::class)
        ->and($template->settings)->toBe(ReceiptTemplateDefaults::settings())
        ->and($template->logo_path)->toBeNull()
        ->and($template->qr_path)->toBeNull();
});

test('an organization has at most one receipt template', function () {
    $organization = Organization::factory()->create();
    ReceiptTemplate::factory()->for($organization)->create();

    expect(fn () => ReceiptTemplate::factory()->for($organization)->create())
        ->toThrow(Illuminate\Database\QueryException::class);

    expect($organization->receiptTemplate)->toBeInstanceOf(ReceiptTemplate::class);
});

test('deleting a receipt template deletes its files', function () {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $directory = ReceiptTemplateImageStorage::directoryFor($organization->getKey());
    Storage::disk('public')->put("{$directory}/logo.png", 'logo');
    Storage::disk('public')->put("{$directory}/qr.png", 'qr');

    $template = ReceiptTemplate::factory()->for($organization)->create([
        'logo_path' => "{$directory}/logo.png",
        'qr_path' => "{$directory}/qr.png",
    ]);

    $template->delete();

    Storage::disk('public')->assertMissing("{$directory}/logo.png");
    Storage::disk('public')->assertMissing("{$directory}/qr.png");
});

test('deleting an organization deletes its receipt template directory', function () {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $directory = ReceiptTemplateImageStorage::directoryFor($organization->getKey());
    Storage::disk('public')->put("{$directory}/logo.png", 'logo');
    ReceiptTemplate::factory()->for($organization)->create([
        'logo_path' => "{$directory}/logo.png",
    ]);

    $organization->delete();

    expect(Storage::disk('public')->allFiles($directory))->toBe([]);
});
