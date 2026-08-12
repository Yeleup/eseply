<?php

use App\Filament\Pages\ReceiptTemplatePage;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\ReceiptTemplate;
use App\Models\User;
use App\OrganizationMemberRole;
use App\Support\ReceiptTemplateDefaults;
use App\Support\ReceiptTemplateImageStorage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

test('saving stores sanitized html css and copies per page', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->set('templateHtml', '<p>{{client_name}}</p><script>alert(1)</script>')
        ->set('templateCss', '.mine { color: red; } @import url(https://evil.example/x.css);')
        ->fillForm(['copies_per_page' => 1])
        ->call('save')
        ->assertHasNoErrors();

    $template = ReceiptTemplate::query()->whereBelongsTo($organization)->sole();

    expect($template->html)->toContain('{{client_name}}')
        ->and($template->html)->not->toContain('script')
        ->and($template->css)->toContain('.mine')
        ->and($template->css)->not->toContain('@import')
        ->and($template->copies_per_page)->toBe(1);
});

test('saving rejects templates over the size limit', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->set('templateHtml', '<p>'.str_repeat('а', 70000).'</p>')
        ->call('save')
        ->assertHasErrors(['templateHtml']);

    expect(ReceiptTemplate::query()->whereBelongsTo($organization)->exists())->toBeFalse();
});

test('reset deletes the template and returns the form to defaults', function () {
    $organization = Organization::factory()->create();
    ReceiptTemplate::factory()->for($organization)->create([
        'html' => '<h1>Счёт за воду</h1>',
    ]);
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->call('resetTemplate')
        ->assertHasNoErrors();

    expect(ReceiptTemplate::query()->whereBelongsTo($organization)->exists())->toBeFalse();
});

test('preview reflects unsaved template html', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->set('templateHtml', '<h1>Мой заголовок квитанции</h1>')
        ->assertSee('Мой заголовок квитанции');
});

test('preview neutralizes script and style breakout in unsaved template', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->set('templateHtml', '<p>ok</p><script>alert(1)</script>')
        ->set('templateCss', 'body{color:red}</style><script>alert(2)</script>')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('</style><script>', false)
        ->assertSee('ok');
});

test('page opens with the default template in the editor state', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->assertSet('templateHtml', ReceiptTemplateDefaults::html())
        ->assertSee('Квитанция на оплату коммунальной услуги');
});

test('page exposes the editor container and config', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->assertSee('receipt-template-editor', false)
        ->assertSee('Лицевой счёт')
        ->assertSee('Таблица счётчиков');
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

test('a logo path tampered to point at another organization is rejected and its file survives a later reset', function () {
    Storage::fake('public');

    $victimOrganization = Organization::factory()->create();
    $victimPath = ReceiptTemplateImageStorage::directoryFor($victimOrganization->getKey()).'/logo.png';
    Storage::disk('public')->put($victimPath, 'victim-logo');

    $organization = Organization::factory()->create();
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->fillForm([
            'logo_path' => [(string) Str::uuid() => $victimPath],
        ])
        ->call('save')
        ->assertHasFormErrors(['logo_path'])
        ->call('resetTemplate');

    expect(ReceiptTemplate::query()->whereBelongsTo($organization)->exists())->toBeFalse();
    Storage::disk('public')->assertExists($victimPath);
});

test('re-saving an existing template keeps its own logo path and file untouched', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();
    $directory = ReceiptTemplateImageStorage::directoryFor($organization->getKey());
    Storage::disk('public')->put("{$directory}/logo.png", 'logo');

    ReceiptTemplate::factory()->for($organization)->create([
        'logo_path' => "{$directory}/logo.png",
    ]);

    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->call('save')
        ->assertHasNoFormErrors();

    Storage::disk('public')->assertExists("{$directory}/logo.png");
    expect(ReceiptTemplate::query()->whereBelongsTo($organization)->sole()->logo_path)
        ->toBe("{$directory}/logo.png");
});

test('replacing the logo through save deletes the old file from the tenant directory', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();
    $directory = ReceiptTemplateImageStorage::directoryFor($organization->getKey());
    Storage::disk('public')->put("{$directory}/old-logo.png", 'old');
    Storage::disk('public')->put("{$directory}/new-logo.png", 'new');

    ReceiptTemplate::factory()->for($organization)->create([
        'logo_path' => "{$directory}/old-logo.png",
    ]);

    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->set('data.logo_path', [(string) Str::uuid() => "{$directory}/new-logo.png"])
        ->call('save')
        ->assertHasNoFormErrors();

    Storage::disk('public')->assertMissing("{$directory}/old-logo.png");
    Storage::disk('public')->assertExists("{$directory}/new-logo.png");
    expect(ReceiptTemplate::query()->whereBelongsTo($organization)->sole()->logo_path)
        ->toBe("{$directory}/new-logo.png");
});
