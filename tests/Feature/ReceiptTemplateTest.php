<?php

use App\Models\Organization;
use App\Models\Receipt;
use App\Models\ReceiptTemplate;
use App\Models\User;
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
        ->and($template->html)->toBeNull()
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

test('print without a template renders the default html template', function () {
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
        ->assertViewHas('renderedCopies')
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

test('custom html template controls print output with escaped values', function () {
    $organization = Organization::factory()->create();
    $receipt = Receipt::factory()->for($organization)->create([
        'client_name' => 'Иванов <b>Иван</b>',
        'account_number' => '100010',
    ]);
    ReceiptTemplate::factory()->for($organization)->create([
        'html' => '<h1>Счёт за воду</h1><p>{{copy_title}}: {{client_name}} ({{account_number}})</p><div>{{meters_table}}</div>',
        'css' => '.mine { color: #000; }',
        'copies_per_page' => 1,
    ]);

    $user = actingAsTemplateTenant($organization);
    $this->actingAs($user);

    $response = $this->get(route('filament.admin.receipts.print', [
        'tenant' => $organization,
        'receipt' => $receipt,
    ]));

    $content = $response->getContent();

    $response->assertSeeText('Счёт за воду')
        ->assertSeeText('Иванов <b>Иван</b>')
        ->assertDontSeeText('Квитанция на оплату коммунальной услуги')
        ->assertDontSeeText('Реквизиты');

    expect(substr_count($content, 'data-receipt-copy='))->toBe(1)
        ->and($content)->toContain('Для абонента')
        ->and($content)->toContain('receipt-sheet-single')
        ->and($content)->toContain('.mine { color: #000; }')
        ->and($content)->toContain('Счётчики')
        ->and($content)->not->toContain('<b>Иван</b>');
});

test('stored template html is sanitized again at print time', function () {
    $organization = Organization::factory()->create();
    $receipt = Receipt::factory()->for($organization)->create();
    ReceiptTemplate::factory()->for($organization)->create([
        'html' => '<p>ok</p><script>alert(1)</script><img src="https://evil.example/x.png">',
    ]);

    $user = actingAsTemplateTenant($organization);
    $this->actingAs($user);

    $content = $this->get(route('filament.admin.receipts.print', [
        'tenant' => $organization,
        'receipt' => $receipt,
    ]))->getContent();

    expect($content)->not->toContain('<script>alert(1)</script>')
        ->and($content)->not->toContain('evil.example')
        ->and($content)->toContain('ok');
});

test('logo fragment renders in print when uploaded', function () {
    $organization = Organization::factory()->create();
    $receipt = Receipt::factory()->for($organization)->create();
    $directory = 'receipt-templates/'.$organization->getKey();
    ReceiptTemplate::factory()->for($organization)->create([
        'html' => '<div>{{logo}}{{qr}}</div><p>{{client_name}}</p>',
        'logo_path' => "{$directory}/logo.png",
    ]);

    $user = actingAsTemplateTenant($organization);
    $this->actingAs($user);

    $content = $this->get(route('filament.admin.receipts.print', [
        'tenant' => $organization,
        'receipt' => $receipt,
    ]))->getContent();

    expect($content)->toContain("{$directory}/logo.png")
        ->and($content)->not->toContain('rt-qr');
});

test('bulk print applies the organization html template', function () {
    $organization = Organization::factory()->create();
    $billingPeriod = $organization->billingPeriods()->create([
        'starts_on' => '2026-05-01',
        'status' => 'open',
        'opened_at' => now(),
    ]);
    Receipt::factory()->for($organization)->create(['account_number' => '100010', 'period' => '202605']);
    Receipt::factory()->for($organization)->create(['account_number' => '100011', 'period' => '202605']);
    ReceiptTemplate::factory()->for($organization)->create([
        'html' => '<h1>Счёт за воду</h1><p>{{account_number}}</p>',
        'copies_per_page' => 1,
    ]);

    $user = actingAsTemplateTenant($organization);
    $this->actingAs($user);

    $content = $this->get(route('filament.admin.receipts.print-bulk', [
        'tenant' => $organization,
        'billing_period_id' => $billingPeriod->getKey(),
    ]))->getContent();

    expect(substr_count($content, 'data-receipt-copy='))->toBe(2)
        ->and(substr_count($content, 'Счёт за воду'))->toBe(2)
        ->and($content)->toContain('100010')
        ->and($content)->toContain('100011');
});

test('css style breakout is neutralized in print output', function () {
    $organization = Organization::factory()->create();
    $receipt = Receipt::factory()->for($organization)->create();
    ReceiptTemplate::factory()->for($organization)->create([
        'html' => '<p>{{client_name}}</p>',
        'css' => 'body{color:red}</style><script>alert(1)</script>',
    ]);

    $user = actingAsTemplateTenant($organization);
    $this->actingAs($user);

    $content = $this->get(route('filament.admin.receipts.print', [
        'tenant' => $organization,
        'receipt' => $receipt,
    ]))->getContent();

    // внутри <style> не должно быть закрывающего тега и скрипта
    expect($content)->not->toContain('</style><script>')
        ->and($content)->not->toContain('<script>alert(1)</script>');
});

test('receipt template stores html css and copies per page', function () {
    $template = ReceiptTemplate::factory()->create([
        'html' => '<p>{{client_name}}</p>',
        'css' => '.rt-copy { color: #000; }',
        'copies_per_page' => 1,
    ]);

    $template->refresh();

    expect($template->html)->toBe('<p>{{client_name}}</p>')
        ->and($template->css)->toBe('.rt-copy { color: #000; }')
        ->and($template->copies_per_page)->toBe(1);
});
