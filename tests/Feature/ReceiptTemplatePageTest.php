<?php

use App\Filament\Pages\ReceiptTemplatePage;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\ReceiptTemplate;
use App\Models\User;
use App\OrganizationMemberRole;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsTemplatePageAdmin(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization);

    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

test('organization admin can open the receipt template page', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    $this->get('/admin/'.$organization->getKey().'/receipt-template')
        ->assertSuccessful()
        ->assertSeeText('Шаблон квитанции');

    Livewire::test(ReceiptTemplatePage::class)->assertSuccessful();
});

test('controller cannot open the receipt template page', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($organization, [
        'role' => OrganizationMemberRole::Controller->value,
    ]);
    Livewire::actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();
    $this->actingAs($user);

    $this->get('/admin/'.$organization->getKey().'/receipt-template')->assertForbidden();
});

test('saving the form creates a template for the current tenant', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->fillForm([
            'texts.title' => 'Счёт за воду',
            'appearance.copies_per_page' => 1,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $template = ReceiptTemplate::query()->whereBelongsTo($organization)->sole();

    expect($template->settings['texts']['title'])->toBe('Счёт за воду')
        ->and($template->settings['appearance']['copies_per_page'])->toBe(1);
});

test('saving reordered and disabled blocks persists them in order', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->fillForm([
            'blocks' => [
                ['type' => 'header', 'enabled' => true],
                ['type' => 'organization_details', 'enabled' => true],
                ['type' => 'client_details', 'enabled' => true],
                ['type' => 'meters_table', 'enabled' => false],
                ['type' => 'totals', 'enabled' => true],
                ['type' => 'footer_note', 'enabled' => false],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $template = ReceiptTemplate::query()->whereBelongsTo($organization)->sole();

    expect(array_column($template->settings['blocks'], 'type'))->toBe([
        'header',
        'organization_details',
        'client_details',
        'meters_table',
        'totals',
        'footer_note',
    ])
        ->and($template->settings['blocks'][3]['enabled'])->toBeFalse();
});

test('reset deletes the template and returns the form to defaults', function () {
    $organization = Organization::factory()->create();
    ReceiptTemplate::factory()->for($organization)->create([
        'settings' => ['texts' => ['title' => 'Счёт за воду']],
    ]);
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->call('resetTemplate')
        ->assertHasNoErrors();

    expect(ReceiptTemplate::query()->whereBelongsTo($organization)->exists())->toBeFalse();
});

test('preview reflects unsaved form state', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->assertSee('data-receipt-copy')
        ->assertSee('Предпросмотр')
        ->fillForm([
            'texts.title' => 'Счёт за воду',
        ])
        ->assertSee('Счёт за воду');
});

test('preview uses the latest tenant receipt when available', function () {
    $organization = Organization::factory()->create();
    Receipt::factory()->for($organization)->create([
        'client_name' => 'Иванов Иван',
        'account_number' => '100010',
    ]);
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->assertSee('Иванов Иван');
});

test('preview falls back to demo data when the tenant has no receipts', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->assertSee('data-receipt-copy')
        ->assertSee('100001');
});
