<?php

use App\BillingPeriodStatus;
use App\Filament\Pages\PaymentDesk;
use App\Filament\Pages\Tenancy\EditOrganizationProfile;
use App\Filament\Pages\Tenancy\RegisterOrganization;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\KaspiOperations\Pages\ListKaspiOperations;
use App\Filament\Widgets\KaspiOperationsStatsWidget;
use App\Kaspi\KaspiCommand;
use App\Kaspi\KaspiOperationState;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\KaspiOperation;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Models\UtilityService;
use App\OrganizationMemberRole;
use App\Support\KaspiUidBackfill;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Reader\XLSX\Reader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(15, 0, 0));
});

function kaspiJournalActingAs(Organization $organization, OrganizationMemberRole $role = OrganizationMemberRole::Operator): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => $role->value]);

    Livewire::actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

/**
 * @return array{organization: Organization, client: Client, billingPeriod: BillingPeriod}
 */
function kaspiJournalFixture(): array
{
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create();
    $billingPeriod = billingPeriodFor($organization, '202611');
    $client = Client::factory()->for($organization)->create([
        'account_number' => '100417',
        'name' => 'Иванов Илья Игоревич',
        'status' => 'active',
    ]);

    return compact('organization', 'client', 'billingPeriod');
}

function kaspiJournalPosted(Client $client, BillingPeriod $billingPeriod, string $txnId, float $sum, string $txnDate, string $receivedAt): KaspiOperation
{
    $payment = Payment::query()->create([
        'organization_id' => $client->organization_id,
        'client_id' => $client->id,
        'billing_period_id' => $billingPeriod->id,
        'method' => 'kaspi',
        'external_provider' => Payment::KASPI_PROVIDER,
        'external_payment_id' => $txnId,
        'amount' => $sum,
        'paid_at' => substr($txnDate, 0, 10),
    ]);

    return KaspiOperation::factory()->forClient($client)->create([
        'command' => KaspiCommand::Pay,
        'txn_id' => $txnId,
        'sum' => $sum,
        'txn_date' => $txnDate,
        'received_at' => $receivedAt,
        'state' => KaspiOperationState::Posted,
        'last_result' => 0,
        'payment_id' => $payment->id,
        'posted_at' => $receivedAt,
    ]);
}

/**
 * @return list<list<mixed>>
 */
function kaspiJournalXlsxRows(array $downloadEffect, bool $cells = false): array
{
    $path = tempnam(sys_get_temp_dir(), 'kaspi-reconciliation-');
    file_put_contents($path, base64_decode((string) data_get($downloadEffect, 'content'), true));

    $reader = new Reader;

    try {
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn (Cell $cell): mixed => $cells ? $cell : $cell->getValue(), $row->getCells());
            }

            break;
        }

        return $rows;
    } finally {
        $reader->close();
        @unlink($path);
    }
}

// --- Журнал ---------------------------------------------------------------------

test('журнал показывает операции только своей организации, с состоянием, ответом и расхождением', function (): void {
    ['organization' => $organization, 'client' => $client, 'billingPeriod' => $billingPeriod] = kaspiJournalFixture();

    $other = Organization::factory()->create();
    $foreign = KaspiOperation::factory()->create(['organization_id' => $other->id, 'txn_id' => '48213309999']);

    kaspiJournalActingAs($organization);

    $posted = kaspiJournalPosted($client, $billingPeriod, '48213377190', 4250, '2026-11-24 10:15:21', '2026-11-24 10:15:28');
    $posted->forceFill(['retry_mismatch' => true, 'retries_count' => 1, 'last_retry_at' => '2026-11-24 10:15:43'])->save();

    $found = KaspiOperation::factory()->forClient($client)->create([
        'txn_id' => '48213377188',
        'state' => KaspiOperationState::Found,
        'last_result' => 0,
        'debt' => 4250,
        'received_at' => '2026-11-24 10:15:20',
    ]);
    $pending = KaspiOperation::factory()->forClient($client)->pending(2750)->create(['txn_id' => '48213350311', 'received_at' => '2026-11-24 09:58:40']);
    $notFound = KaspiOperation::factory()->create([
        'organization_id' => $organization->id,
        'txn_id' => '48213301922',
        'account' => '999990',
        'received_at' => '2026-11-24 09:41:12',
    ]);

    Livewire::test(ListKaspiOperations::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$posted, $found, $pending, $notFound], inOrder: true)
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertTableColumnFormattedStateSet('account', 'Иванов Илья Игоревич', $posted)
        ->assertTableColumnHasDescription('account', 'л/с 100417', $posted)
        ->assertTableColumnFormattedStateSet('account', '—', $notFound)
        ->assertTableColumnHasDescription('state', 'ответ: 0 · расхождение в повторе: проведено по первому запросу', $posted)
        ->assertTableColumnHasDescription('state', 'ответ: 0 · долг 4 250,00 ₸', $found)
        ->assertTableColumnHasDescription('state', 'ответ: 4 · идёт закрытие расчётного месяца', $pending)
        ->assertTableColumnFormattedStateSet('payment_id', '№ '.$posted->payment_id, $posted)
        ->assertTableColumnHasDescription('retries_count', '24.11.2026 10:15:43', $posted);
});

test('фильтры журнала: команда, дата получения, дата Kaspi, состояние и расхождение; поиск по счёту и номеру Kaspi', function (): void {
    ['organization' => $organization, 'client' => $client, 'billingPeriod' => $billingPeriod] = kaspiJournalFixture();
    kaspiJournalActingAs($organization);

    $yesterdayPay = kaspiJournalPosted($client, $billingPeriod, '48213300001', 1000, '2026-11-23 23:59:59', '2026-11-24 00:00:05');
    $todayPay = kaspiJournalPosted($client, $billingPeriod, '48213300002', 2000, '2026-11-24 10:00:00', '2026-11-24 10:00:02');
    $todayPay->forceFill(['retry_mismatch' => true])->save();
    $oldCheck = KaspiOperation::factory()->forClient($client)->create(['txn_id' => '48213300003', 'state' => KaspiOperationState::Found, 'last_result' => 0, 'received_at' => '2026-11-20 09:00:00']);
    $otherAccount = KaspiOperation::factory()->create(['organization_id' => $organization->id, 'txn_id' => '77700000004', 'account' => '555555', 'received_at' => '2026-11-24 11:00:00']);

    Livewire::test(ListKaspiOperations::class)
        ->filterTable('command', KaspiCommand::Check->value)
        ->assertCanSeeTableRecords([$oldCheck, $otherAccount])
        ->assertCanNotSeeTableRecords([$yesterdayPay, $todayPay])
        ->resetTableFilters()
        ->filterTable('received_at', ['from' => '2026-11-24', 'until' => '2026-11-24'])
        ->assertCanSeeTableRecords([$yesterdayPay, $todayPay, $otherAccount])
        ->assertCanNotSeeTableRecords([$oldCheck])
        ->resetTableFilters()
        ->filterTable('txn_date', ['from' => '2026-11-23', 'until' => '2026-11-23'])
        ->assertCanSeeTableRecords([$yesterdayPay])
        ->assertCanNotSeeTableRecords([$todayPay, $oldCheck, $otherAccount])
        ->resetTableFilters()
        ->filterTable('state', KaspiOperationState::Posted->value)
        ->assertCanSeeTableRecords([$yesterdayPay, $todayPay])
        ->assertCanNotSeeTableRecords([$oldCheck, $otherAccount])
        ->resetTableFilters()
        ->filterTable('retry_mismatch', true)
        ->assertCanSeeTableRecords([$todayPay])
        ->assertCanNotSeeTableRecords([$yesterdayPay, $oldCheck, $otherAccount])
        ->resetTableFilters()
        ->searchTable('5555')
        ->assertCanSeeTableRecords([$otherAccount])
        ->assertCanNotSeeTableRecords([$yesterdayPay, $todayPay, $oldCheck])
        ->searchTable('48213300002')
        ->assertCanSeeTableRecords([$todayPay])
        ->assertCanNotSeeTableRecords([$yesterdayPay, $oldCheck, $otherAccount]);
});

test('плитки: оплат сегодня по дате Kaspi, в обработке и ошибок за сутки по времени получения', function (): void {
    ['organization' => $organization, 'client' => $client, 'billingPeriod' => $billingPeriod] = kaspiJournalFixture();

    // Чужая организация в плитки не попадает; создаётся до выбора тенанта.
    $foreign = KaspiOperation::factory()->create(['organization_id' => Organization::factory()->create()->id, 'state' => KaspiOperationState::Error, 'last_result' => 5]);

    kaspiJournalActingAs($organization);

    expect($foreign->organization_id)->not->toBe($organization->id);

    kaspiJournalPosted($client, $billingPeriod, '48213300001', 1000, '2026-11-23 23:59:59', '2026-11-24 00:00:05');
    kaspiJournalPosted($client, $billingPeriod, '48213300002', 2000.5, '2026-11-24 10:00:00', '2026-11-24 10:00:02');
    kaspiJournalPosted($client, $billingPeriod, '48213300003', 1500, '2026-11-24 12:00:00', '2026-11-24 12:00:02');
    KaspiOperation::factory()->forClient($client)->pending(2750)->create(['txn_id' => '48213300004', 'received_at' => '2026-11-24 09:58:40']);
    KaspiOperation::factory()->create(['organization_id' => $organization->id, 'txn_id' => '48213300005', 'state' => KaspiOperationState::Error, 'last_result' => 5, 'received_at' => '2026-11-24 12:30:00']);
    KaspiOperation::factory()->create(['organization_id' => $organization->id, 'txn_id' => '48213300006', 'state' => KaspiOperationState::Error, 'last_result' => 5, 'received_at' => '2026-11-23 14:00:00']);

    Livewire::test(KaspiOperationsStatsWidget::class)
        ->assertSeeInOrder([
            'Оплат сегодня', '2', 'на 3 500,50 ₸',
            'В обработке', '1', 'проведётся само после закрытия месяца',
            'Ошибок за сутки', '1', 'последний запрос сегодня в 12:30',
        ]);
});

test('выгрузка для сверки: XLSX за день по дате Kaspi, проведённые и «в обработке», номера текстом', function (): void {
    ['organization' => $organization, 'client' => $client, 'billingPeriod' => $billingPeriod] = kaspiJournalFixture();

    $foreign = KaspiOperation::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
        'command' => KaspiCommand::Pay,
        'txn_id' => '48213300005',
        'sum' => 1,
        'txn_date' => '2026-11-23 10:00:00',
        'state' => KaspiOperationState::Pending,
        'last_result' => 4,
    ]);

    kaspiJournalActingAs($organization);

    expect($foreign->organization_id)->not->toBe($organization->id);

    $posted = kaspiJournalPosted($client, $billingPeriod, '48213300001', 4250, '2026-11-23 23:59:59', '2026-11-24 00:00:05');
    KaspiOperation::factory()->forClient($client)->pending(2750)->create(['txn_id' => '48213300002', 'txn_date' => '2026-11-23 08:15:00']);
    kaspiJournalPosted($client, $billingPeriod, '48213300003', 999, '2026-11-24 09:00:00', '2026-11-24 09:00:02');
    KaspiOperation::factory()->forClient($client)->create(['txn_id' => '48213300004', 'state' => KaspiOperationState::Found, 'last_result' => 0]);

    $download = Livewire::test(ListKaspiOperations::class)
        ->assertActionHasLabel('exportReconciliation', 'Выгрузить для сверки')
        ->mountAction('exportReconciliation')
        ->assertSchemaStateSet(['date' => '2026-11-23'])
        ->callMountedAction()
        ->assertFileDownloaded(
            'kaspi-'.$organization->id.'-2026-11-23.xlsx',
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $rows = kaspiJournalXlsxRows($download->effects['download']);

    expect($rows)->toBe([
        ['Номер Kaspi', 'Дата Kaspi', 'Лицевой счёт', 'Абонент', 'Сумма', 'Наш номер оплаты', 'Состояние'],
        ['48213300002', '23.11.2026 08:15:00', '100417', 'Иванов Илья Игоревич', 2750, '', 'В обработке'],
        ['48213300001', '23.11.2026 23:59:59', '100417', 'Иванов Илья Игоревич', 4250, (string) $posted->payment_id, 'Проведена'],
    ]);

    $cells = kaspiJournalXlsxRows($download->effects['download'], cells: true)[1];

    expect($cells[0])->toBeInstanceOf(StringCell::class)
        ->and($cells[2])->toBeInstanceOf(StringCell::class)
        ->and($cells[4])->toBeInstanceOf(NumericCell::class);
});

test('контролёр и чужая организация не видят журнал, выгрузку и раздел Kaspi в профиле', function (): void {
    ['organization' => $organization] = kaspiJournalFixture();
    $controller = kaspiJournalActingAs($organization, OrganizationMemberRole::Controller);

    Livewire::test(ListKaspiOperations::class)->assertForbidden();
    Livewire::test(EditOrganizationProfile::class)->assertNotFound();

    $this->actingAs($controller)
        ->get("/admin/{$organization->id}/kaspi-online")
        ->assertForbidden();

    $outsider = User::factory()->create();
    $outsider->organizations()->attach(Organization::factory()->create(), ['role' => OrganizationMemberRole::Operator->value]);

    $this->actingAs($outsider)
        ->get("/admin/{$organization->id}/kaspi-online")
        ->assertNotFound();

    $this->actingAs($outsider)
        ->get("/admin/{$organization->id}/profile")
        ->assertNotFound();
});

// --- Профиль организации ----------------------------------------------------------

test('профиль показывает оператору раздел Kaspi: адрес, формат счёта, формат ответа и строку о запросах', function (): void {
    ['organization' => $organization, 'client' => $client] = kaspiJournalFixture();
    kaspiJournalActingAs($organization);

    Livewire::test(EditOrganizationProfile::class)
        ->assertOk()
        ->assertSee('Kaspi онлайн')
        ->assertSee('Принимать оплаты Kaspi онлайн')
        ->assertSee(url('/kaspi/'.$organization->kaspi_uid))
        ->assertSee('^[0-9]{6,10}$')
        ->assertSee('JSON, UTF-8')
        ->assertSee('194.187.247.152')
        ->assertSee('Запросов от Kaspi ещё не было.')
        ->assertDontSee('сменить адрес');

    KaspiOperation::factory()->forClient($client)->create(['received_at' => '2026-11-24 10:15:00', 'state' => KaspiOperationState::Found, 'last_result' => 0]);

    Livewire::test(EditOrganizationProfile::class)
        ->assertSee('Последний запрос от Kaspi: сегодня в 10:15. Ошибок за сутки нет.');

    KaspiOperation::factory()->create(['organization_id' => $organization->id, 'received_at' => '2026-11-24 10:40:00', 'state' => KaspiOperationState::Error, 'last_result' => 5]);

    Livewire::test(EditOrganizationProfile::class)
        ->assertSee('Последний запрос от Kaspi: сегодня в 10:40. Ошибок за сутки: 1 — откройте журнал «Kaspi онлайн».');
});

/**
 * @return array<string, mixed>
 */
function kaspiProfileForm(Organization $organization, bool $enabled): array
{
    return [
        'name' => $organization->name,
        'bin_iin' => $organization->bin_iin,
        'phone' => $organization->phone,
        'address' => $organization->address,
        'bank' => $organization->bank,
        'iban' => $organization->iban,
        'note' => $organization->note,
        'utility_service_name' => 'Вода',
        'utility_service_unit_of_measurement' => 'м3',
        'kaspi_enabled' => $enabled,
    ];
}

test('приём включается только при месяце «Открыт» или «Ошибка закрытия», выключается всегда', function (BillingPeriodStatus $status, bool $canEnable): void {
    ['organization' => $organization, 'billingPeriod' => $billingPeriod] = kaspiJournalFixture();
    $billingPeriod->forceFill(['status' => $status])->save();
    kaspiJournalActingAs($organization);

    $page = Livewire::test(EditOrganizationProfile::class)
        ->fillForm(kaspiProfileForm($organization, true))
        ->call('save');

    if ($canEnable) {
        $page->assertHasNoFormErrors();
    } else {
        $page->assertHasFormErrors(['kaspi_enabled']);
    }

    expect($organization->refresh()->kaspi_enabled)->toBe($canEnable);

    $organization->forceFill(['kaspi_enabled' => true])->save();

    Livewire::test(EditOrganizationProfile::class)
        ->fillForm(kaspiProfileForm($organization, false))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($organization->refresh()->kaspi_enabled)->toBeFalse();
})->with([
    'открыт' => [BillingPeriodStatus::Open, true],
    'ошибка закрытия' => [BillingPeriodStatus::Failed, true],
    'закрывается' => [BillingPeriodStatus::Processing, false],
    'закрыт' => [BillingPeriodStatus::Closed, false],
]);

test('uid создаётся у новой организации и не меняется ни при сохранении профиля, ни при подмене', function (): void {
    ['organization' => $organization] = kaspiJournalFixture();
    $uid = $organization->kaspi_uid;

    expect(Str::isUuid($uid))->toBeTrue();

    kaspiJournalActingAs($organization);

    Livewire::test(EditOrganizationProfile::class)
        ->fillForm(kaspiProfileForm($organization, true))
        ->set('data.kaspi_uid', (string) Str::uuid())
        ->call('save')
        ->assertHasNoFormErrors();

    $organization->update(['kaspi_uid' => (string) Str::uuid()]);
    $organization->forceFill(['kaspi_uid' => (string) Str::uuid()])->save();

    expect($organization->refresh()->kaspi_uid)->toBe($uid)
        ->and($organization->kaspiAddress())->toBe(url('/kaspi/'.$uid));
});

test('организация, созданная через регистрацию, сразу получает свой uid', function (): void {
    $user = User::factory()->create();
    Livewire::actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    Livewire::test(RegisterOrganization::class)
        ->fillForm([
            'name' => 'ТОО Новая',
            'utility_service_name' => 'Вода',
            'utility_service_unit_of_measurement' => 'м3',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $organization = Organization::query()->where('name', 'ТОО Новая')->sole();

    expect(Str::isUuid($organization->kaspi_uid))->toBeTrue()
        ->and($organization->kaspi_enabled)->toBeFalse();
});

test('выкладка даёт uid каждой существующей организации без него и не трогает имеющиеся', function (): void {
    DB::statement('create temporary table kaspi_uid_backfill_organizations (id bigint unsigned primary key, kaspi_uid char(36) null)');
    DB::table('kaspi_uid_backfill_organizations')->insert([
        ['id' => 1, 'kaspi_uid' => null],
        ['id' => 2, 'kaspi_uid' => 'c3d1a6e2-7f45-4b8e-9a21-5e6f70a8b9c0'],
        ['id' => 3, 'kaspi_uid' => null],
    ]);

    expect(KaspiUidBackfill::run('kaspi_uid_backfill_organizations'))->toBe(2)
        ->and(KaspiUidBackfill::run('kaspi_uid_backfill_organizations'))->toBe(0);

    $uids = DB::table('kaspi_uid_backfill_organizations')->orderBy('id')->pluck('kaspi_uid')->all();

    expect($uids[1])->toBe('c3d1a6e2-7f45-4b8e-9a21-5e6f70a8b9c0')
        ->and(Str::isUuid((string) $uids[0]))->toBeTrue()
        ->and(Str::isUuid((string) $uids[2]))->toBeTrue()
        ->and($uids[0])->not->toBe($uids[2]);

    DB::statement('drop temporary table kaspi_uid_backfill_organizations');
});

// --- Удаление абонента -------------------------------------------------------------

test('абонента с операциями Kaspi, в том числе «в обработке», нельзя удалить ни по одному, ни массово', function (string $operation): void {
    ['organization' => $organization, 'client' => $client] = kaspiJournalFixture();
    $free = Client::factory()->for($organization)->create(['account_number' => '100300']);
    kaspiJournalActingAs($organization);

    $factory = KaspiOperation::factory()->forClient($client);
    ($operation === 'pending' ? $factory->pending() : $factory->state(['state' => KaspiOperationState::Found, 'last_result' => 0]))->create();

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->callAction(DeleteAction::getDefaultName())
        ->assertNotified('Абонент не удалён');

    Livewire::test(ListClients::class)
        ->callTableBulkAction('delete', [$client, $free])
        ->assertNotified('Абоненты не удалены');

    expect(Client::query()->whereKey([$client->id, $free->id])->count())->toBe(2);

    expect(fn () => $client->delete())->toThrow(ValidationException::class, Client::KASPI_OPERATIONS_DELETE_MESSAGE);
    expect(fn () => DB::transaction(fn () => DB::table('clients')->where('id', $client->id)->delete()))->toThrow(QueryException::class);

    Livewire::test(ListClients::class)
        ->callTableBulkAction('delete', [$free])
        ->assertNotNotified('Абоненты не удалены');

    expect(Client::query()->whereKey($free->id)->exists())->toBeFalse()
        ->and(Client::query()->whereKey($client->id)->exists())->toBeTrue();
})->with(['pending', 'check']);

// --- Касса блокирует строку абонента ------------------------------------------------

test('касса при приёме оплаты блокирует строку абонента до вставки оплаты', function (): void {
    ['organization' => $organization, 'client' => $client] = kaspiJournalFixture();
    $client->forceFill(['billing_type' => 'fixed', 'fixed_amount' => 1000])->save();
    kaspiJournalActingAs($organization);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    // Без долга кнопки «Оплатить» нет, поэтому принимаем через само действие приёма.
    $page = Livewire::test(PaymentDesk::class)->set('search', '100417');
    $method = new ReflectionMethod(PaymentDesk::class, 'acceptPayment');
    $method->invoke($page->instance(), ['client' => $client->id], ['amount' => 500, 'paid_at' => '2026-11-24'], $page->instance()->payAction());

    $clientLock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from `clients`') && str_contains($sql, 'for update'));
    $paymentInsert = collect($queries)->search(fn (string $sql): bool => str_starts_with($sql, 'insert into `payments`'));

    expect($clientLock)->not->toBeFalse()
        ->and($paymentInsert)->not->toBeFalse()
        ->and($clientLock)->toBeLessThan($paymentInsert)
        ->and(Payment::query()->sole()->amount)->toBe('500.00');
});
