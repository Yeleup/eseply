<?php

use App\Models\Organization;
use App\Models\Receipt;
use App\Models\ReceiptTemplate;
use App\Models\User;
use App\Support\ReceiptTemplateDefaults;
use App\Support\ReceiptTemplateImageStorage;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsTemplateTenant(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

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
        ->toThrow(QueryException::class);

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

test('print without a template renders default blocks in default order', function () {
    $organization = Organization::factory()->create(['name' => 'ТОО Водоканал']);
    $receipt = Receipt::factory()->for($organization)->create([
        'account_number' => '100010',
        'client_name' => 'Иванов Иван',
    ]);

    $user = actingAsTemplateTenant($organization);
    $this->actingAs($user);

    $this->get(route('filament.admin.receipts.print', [
        'tenant' => $organization,
        'receipt' => $receipt,
    ]))
        ->assertSuccessful()
        ->assertViewHas('template')
        ->assertSeeTextInOrder([
            'Для организации',
            'Квитанция на оплату коммунальной услуги',
            'Лицевой счёт',
            'Реквизиты',
            'Счётчики',
            'Долг',
            'Оплачено',
            'К оплате',
            'Для абонента',
        ]);
});

test('template controls block order visibility and texts on print', function () {
    $organization = Organization::factory()->create();
    $receipt = Receipt::factory()->for($organization)->create();
    ReceiptTemplate::factory()->for($organization)->create([
        'settings' => [
            'blocks' => [
                ['type' => 'header', 'enabled' => true],
                ['type' => 'organization_details', 'enabled' => true],
                ['type' => 'client_details', 'enabled' => true],
                ['type' => 'meters_table', 'enabled' => false],
                ['type' => 'totals', 'enabled' => true],
                ['type' => 'footer_note', 'enabled' => true],
            ],
            'texts' => [
                'title' => 'Счёт за воду',
                'footer_note' => 'Оплатите до 25 числа <b>без пени</b>',
                'labels' => ['account_number' => 'Абонентский номер'],
            ],
        ],
    ]);

    $user = actingAsTemplateTenant($organization);
    $this->actingAs($user);

    $response = $this->get(route('filament.admin.receipts.print', [
        'tenant' => $organization,
        'receipt' => $receipt,
    ]));

    $response
        ->assertSuccessful()
        ->assertSeeTextInOrder([
            'Счёт за воду',
            'Реквизиты',
            'Абонентский номер',
            'К оплате',
            'Оплатите до 25 числа',
        ])
        ->assertDontSeeText('Счётчики')
        ->assertDontSeeText('Квитанция на оплату коммунальной услуги')
        ->assertSeeText('Оплатите до 25 числа <b>без пени</b>');

    expect($response->getContent())->not->toContain('<b>без пени</b>');
});

test('single copy template prints only the client copy', function () {
    $organization = Organization::factory()->create();
    $receipt = Receipt::factory()->for($organization)->create();
    ReceiptTemplate::factory()->for($organization)->create([
        'settings' => [
            'appearance' => ['copies_per_page' => 1],
        ],
    ]);

    $user = actingAsTemplateTenant($organization);
    $this->actingAs($user);

    $response = $this->get(route('filament.admin.receipts.print', [
        'tenant' => $organization,
        'receipt' => $receipt,
    ]));

    $content = $response->getContent();

    expect(substr_count($content, 'data-receipt-copy='))->toBe(1)
        ->and($content)->toContain('Для абонента')
        ->and($content)->toContain('receipt-sheet-single')
        ->and($content)->not->toContain('Для организации');
});

test('appearance settings add css classes to receipt copies', function () {
    $organization = Organization::factory()->create();
    $receipt = Receipt::factory()->for($organization)->create();
    ReceiptTemplate::factory()->for($organization)->create([
        'settings' => [
            'appearance' => ['font_size' => 'large', 'density' => 'compact', 'borders' => false],
        ],
    ]);

    $user = actingAsTemplateTenant($organization);
    $this->actingAs($user);

    $content = $this->get(route('filament.admin.receipts.print', [
        'tenant' => $organization,
        'receipt' => $receipt,
    ]))->getContent();

    expect($content)->toContain('receipt-font-large')
        ->and($content)->toContain('receipt-density-compact')
        ->and($content)->toContain('receipt-no-borders');
});

test('bulk print applies each organization template', function () {
    $organization = Organization::factory()->create();
    $billingPeriod = $organization->billingPeriods()->create([
        'starts_on' => '2026-05-01',
        'status' => 'open',
        'opened_at' => now(),
    ]);
    Receipt::factory()->for($organization)->create([
        'account_number' => '100010',
        'period' => '202605',
    ]);
    Receipt::factory()->for($organization)->create([
        'account_number' => '100011',
        'period' => '202605',
    ]);
    ReceiptTemplate::factory()->for($organization)->create([
        'settings' => [
            'texts' => ['title' => 'Счёт за воду'],
            'appearance' => ['copies_per_page' => 1],
        ],
    ]);

    $user = actingAsTemplateTenant($organization);
    $this->actingAs($user);

    $response = $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $billingPeriod->getKey(),
    ]));

    $content = $response->getContent();

    expect(substr_count($content, 'data-receipt-copy='))->toBe(2)
        ->and(substr_count($content, 'Счёт за воду'))->toBe(2);
});

test('logo and qr render on print when enabled and uploaded', function () {
    $organization = Organization::factory()->create();
    $receipt = Receipt::factory()->for($organization)->create();
    $directory = 'receipt-templates/'.$organization->getKey();
    ReceiptTemplate::factory()->for($organization)->create([
        'logo_path' => "{$directory}/logo.png",
        'qr_path' => "{$directory}/qr.png",
        'settings' => [
            'blocks' => [
                ['type' => 'header', 'enabled' => true],
                ['type' => 'footer_note', 'enabled' => true],
            ],
            'appearance' => ['show_logo' => true, 'show_qr' => true],
        ],
    ]);

    $user = actingAsTemplateTenant($organization);
    $this->actingAs($user);

    $content = $this->get(route('filament.admin.receipts.print', [
        'tenant' => $organization,
        'receipt' => $receipt,
    ]))->getContent();

    expect($content)->toContain("{$directory}/logo.png")
        ->and($content)->toContain("{$directory}/qr.png");
});
