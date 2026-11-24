<?php

use App\Filament\Pages\PaymentDesk;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Payments\Pages\CreatePayment;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Models\UtilityService;
use App\OrganizationMemberRole;
use App\PaymentMethod;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{organization: Organization, client: Client, billingPeriod: BillingPeriod, operator: User}
 */
function integrationLockFixture(): array
{
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create();
    $billingPeriod = billingPeriodFor($organization, '202611');
    $client = Client::factory()->for($organization)->create(['account_number' => '100417', 'status' => 'active']);

    $operator = User::factory()->create();
    $operator->organizations()->attach($organization, ['role' => OrganizationMemberRole::Operator->value]);

    Livewire::actingAs($operator);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return compact('organization', 'client', 'billingPeriod', 'operator');
}

function integrationKaspiPayment(Client $client, BillingPeriod $billingPeriod, string $txnId = '48213377190', float $amount = 4250): Payment
{
    return Payment::query()->create([
        'organization_id' => $client->organization_id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'method' => PaymentMethod::Kaspi->value,
        'external_provider' => Payment::KASPI_PROVIDER,
        'external_payment_id' => $txnId,
        'amount' => $amount,
        'paid_at' => '2026-11-24',
    ]);
}

function integrationManualPayment(Client $client, BillingPeriod $billingPeriod, float $amount = 1600): Payment
{
    return Payment::query()->create([
        'organization_id' => $client->organization_id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'amount' => $amount,
        'paid_at' => '2026-11-06',
    ]);
}

test('модель не даёт изменить или удалить оплату из интеграции, в том числе сняв её признаки', function (): void {
    ['client' => $client, 'billingPeriod' => $billingPeriod] = integrationLockFixture();
    $payment = integrationKaspiPayment($client, $billingPeriod);

    expect(fn () => $payment->update(['amount' => 1]))->toThrow(ValidationException::class);
    expect(fn () => $payment->refresh()->update(['note' => 'Исправлено']))->toThrow(ValidationException::class);
    expect(fn () => $payment->refresh()->update([
        'external_provider' => null,
        'external_payment_id' => null,
        'amount' => 1,
    ]))->toThrow(ValidationException::class);
    expect(fn () => $payment->refresh()->delete())->toThrow(ValidationException::class);

    $stored = Payment::query()->findOrFail($payment->id);

    expect((float) $stored->amount)->toBe(4250.0)
        ->and($stored->note)->toBeNull()
        ->and($stored->external_provider)->toBe('kaspi')
        ->and($stored->external_payment_id)->toBe('48213377190');
});

test('ручную оплату нельзя выдать за оплату из интеграции правкой', function (): void {
    ['client' => $client, 'billingPeriod' => $billingPeriod] = integrationLockFixture();
    $payment = integrationManualPayment($client, $billingPeriod);

    expect(fn () => $payment->update(['external_provider' => 'kaspi', 'external_payment_id' => '1']))
        ->toThrow(ValidationException::class);

    expect($payment->refresh()->isFromIntegration())->toBeFalse();
});

test('в карточке абонента у оплаты Kaspi номер Kaspi и нет действий, а ручная оплата правится', function (): void {
    ['client' => $client, 'billingPeriod' => $billingPeriod] = integrationLockFixture();
    $kaspi = integrationKaspiPayment($client, $billingPeriod);
    $manual = integrationManualPayment($client, $billingPeriod);

    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class])
        ->assertCanSeeTableRecords([$kaspi, $manual])
        ->assertTableColumnHasDescription('method', '№ Kaspi 48213377190', $kaspi)
        ->assertTableActionHidden(EditAction::getDefaultName(), $kaspi)
        ->assertTableActionHidden(DeleteAction::getDefaultName(), $kaspi)
        ->assertTableActionVisible(EditAction::getDefaultName(), $manual)
        ->assertTableActionVisible(DeleteAction::getDefaultName(), $manual);
});

test('массовое удаление в карточке абонента со смешанной выборкой не удаляет ничего и говорит почему', function (): void {
    ['client' => $client, 'billingPeriod' => $billingPeriod] = integrationLockFixture();
    $kaspi = integrationKaspiPayment($client, $billingPeriod);
    $manual = integrationManualPayment($client, $billingPeriod);

    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class])
        ->callTableBulkAction('delete', [$kaspi, $manual])
        ->assertNotified('Оплаты не удалены');

    expect(Payment::query()->whereKey([$kaspi->id, $manual->id])->count())->toBe(2);

    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class])
        ->callTableBulkAction('delete', [$manual])
        ->assertNotNotified('Оплаты не удалены');

    expect(Payment::query()->whereKey($manual->id)->exists())->toBeFalse()
        ->and(Payment::query()->whereKey($kaspi->id)->exists())->toBeTrue();
});

test('в разделе «Оплаты» оплату Kaspi нельзя изменить и удалить, в том числе массово', function (): void {
    ['client' => $client, 'billingPeriod' => $billingPeriod, 'operator' => $operator, 'organization' => $organization] = integrationLockFixture();
    $kaspi = integrationKaspiPayment($client, $billingPeriod);
    $manual = integrationManualPayment($client, $billingPeriod);

    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$kaspi, $manual])
        ->assertTableColumnHasDescription('method', '№ Kaspi 48213377190', $kaspi)
        ->assertTableActionHidden(EditAction::getDefaultName(), $kaspi)
        ->assertTableActionVisible(EditAction::getDefaultName(), $manual)
        ->callTableBulkAction('delete', [$manual, $kaspi])
        ->assertNotified('Оплаты не удалены');

    expect(Payment::query()->whereKey([$kaspi->id, $manual->id])->count())->toBe(2);

    $this->actingAs($operator)
        ->get("/admin/{$organization->getKey()}/payments/{$kaspi->getKey()}/edit")
        ->assertForbidden();

    $this->actingAs($operator)
        ->get("/admin/{$organization->getKey()}/payments/{$manual->getKey()}/edit")
        ->assertSuccessful()
        ->assertDontSee('Способ оплаты');
});

test('раздел «Оплаты» создаёт ручную оплату только наличными, даже с подложенным способом', function (): void {
    ['client' => $client] = integrationLockFixture();

    Livewire::test(CreatePayment::class)
        ->assertDontSee('Способ оплаты')
        ->set('data.method', PaymentMethod::Kaspi->value)
        ->set('data.external_provider', Payment::KASPI_PROVIDER)
        ->set('data.external_payment_id', '48213377190')
        ->fillForm([
            'client_id' => $client->id,
            'amount' => 900,
            'paid_at' => '2026-11-20',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $payment = Payment::query()->whereBelongsTo($client)->sole();

    expect($payment->method)->toBe(PaymentMethod::Cash)
        ->and($payment->isFromIntegration())->toBeFalse();
});

test('карточка абонента создаёт ручную оплату только наличными и без способа в форме', function (): void {
    ['client' => $client] = integrationLockFixture();

    $page = Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class])
        ->mountTableAction('create');

    expect($page->getMountedActionModalHtml())->not->toContain('Способ оплаты');

    $page->set('mountedActions.0.data.external_payment_id', '48213377190')
        ->set('mountedActions.0.data.method', PaymentMethod::Kaspi->value)
        ->fillForm(['amount' => 500, 'paid_at' => '2026-11-20'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $payment = Payment::query()->whereBelongsTo($client)->sole();

    expect($payment->method)->toBe(PaymentMethod::Cash)
        ->and($payment->external_payment_id)->toBeNull();
});

test('в ленте кассы оплату Kaspi «принял» Kaspi, а исправить и удалить её нельзя', function (): void {
    ['client' => $client, 'billingPeriod' => $billingPeriod] = integrationLockFixture();
    $kaspi = integrationKaspiPayment($client, $billingPeriod);

    Livewire::test(PaymentDesk::class)
        ->assertCanSeeTableRecords([$kaspi])
        ->assertTableColumnStateSet('received_by', 'Kaspi', $kaspi)
        ->assertTableColumnHasDescription('method', '№ Kaspi 48213377190', $kaspi)
        ->assertActionHidden(TestAction::make(EditAction::getDefaultName())->table($kaspi))
        ->assertActionHidden(TestAction::make(DeleteAction::getDefaultName())->table($kaspi));
});
