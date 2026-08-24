<?php

use App\BillingPeriodStatus;
use App\Filament\Pages\MeterReadingEntry;
use App\Models\City;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\Region;
use App\Models\Street;
use App\Models\Tariff;
use App\Models\User;
use App\Models\UtilityService;
use App\OrganizationMemberRole;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{organization: Organization, utilityService: UtilityService, city: City, region: Region, street: Street}
 */
function readingEntryOrganization(): array
{
    $organization = Organization::factory()->create();
    $utilityService = UtilityService::factory()->for($organization)->create();
    $city = City::query()->create(['organization_id' => $organization->id, 'name' => 'Алматы']);
    $region = Region::query()->create(['city_id' => $city->id, 'name' => 'Алмалинский']);
    $street = Street::query()->create(['region_id' => $region->id, 'name' => 'Абая']);

    Tariff::factory()->for($organization)->for($utilityService)->create([
        'unit_price' => 100,
        'starts_on' => '2020-01-01',
        'status' => 'active',
    ]);

    return compact('organization', 'utilityService', 'city', 'region', 'street');
}

function readingEntryOperator(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, [
        'role' => OrganizationMemberRole::Operator->value,
    ]);

    return readingEntryActingAs($user, $organization);
}

function readingEntryController(Organization $organization, ?Region $region = null, ?Street $street = null): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, [
        'role' => OrganizationMemberRole::Controller->value,
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

    if ($street instanceof Street) {
        DB::table('organization_user_streets')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'street_id' => $street->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return readingEntryActingAs($user, $organization);
}

function readingEntryActingAs(User $user, Organization $organization): User
{
    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();

    return $user;
}

/**
 * @param  array<string, mixed>  $clientAttributes
 * @param  array<string, mixed>  $meterAttributes
 */
function readingEntryMeter(
    Organization $organization,
    UtilityService $utilityService,
    array $clientAttributes = [],
    array $meterAttributes = [],
): Meter {
    $client = Client::factory()
        ->for($organization)
        ->for($utilityService)
        ->create([
            'billing_type' => 'meter',
            'status' => 'active',
            ...$clientAttributes,
        ]);

    return Meter::factory()
        ->for($organization)
        ->for($client)
        ->for($utilityService)
        ->create([
            'initial_reading' => 100,
            'status' => 'active',
            ...$meterAttributes,
        ]);
}

function enterReading(Meter $meter, mixed $value): Testable
{
    return Livewire::test(MeterReadingEntry::class)
        ->call('updateTableColumnState', 'current_reading', (string) $meter->getKey(), $value);
}

// --- Доступ -----------------------------------------------------------------

test('контролёр вводит показание по счётчику своей зоны', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'region' => $region, 'street' => $street] = readingEntryOrganization();
    billingPeriodFor($organization);
    readingEntryController($organization, $region);

    $meter = readingEntryMeter($organization, $utilityService, [
        'region_id' => $region->id,
        'street_id' => $street->id,
    ]);

    enterReading($meter, '150');

    $reading = MeterReading::query()->where('meter_id', $meter->id)->first();

    expect($reading)->not->toBeNull()
        ->and((int) $reading->current_reading)->toBe(150)
        ->and((int) $reading->previous_reading)->toBe(100)
        ->and((int) $reading->consumption)->toBe(50)
        ->and($reading->read_at?->toDateString())->toBe(today()->toDateString());
});

test('контролёр не видит и не может записать счётчик чужого района', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'city' => $city, 'region' => $region] = readingEntryOrganization();
    billingPeriodFor($organization);

    $foreignRegion = Region::query()->create(['city_id' => $city->id, 'name' => 'Бостандыкский']);
    $foreignStreet = Street::query()->create(['region_id' => $foreignRegion->id, 'name' => 'Сатпаева']);

    readingEntryController($organization, $region);

    $foreignMeter = readingEntryMeter($organization, $utilityService, [
        'region_id' => $foreignRegion->id,
        'street_id' => $foreignStreet->id,
    ]);

    Livewire::test(MeterReadingEntry::class)->assertCanNotSeeTableRecords([$foreignMeter]);

    enterReading($foreignMeter, '150');

    expect(MeterReading::query()->where('meter_id', $foreignMeter->id)->exists())->toBeFalse();
});

test('сброс фильтров не открывает контролёру чужую зону', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'city' => $city, 'region' => $region] = readingEntryOrganization();
    billingPeriodFor($organization);

    $foreignRegion = Region::query()->create(['city_id' => $city->id, 'name' => 'Бостандыкский']);
    readingEntryController($organization, $region);

    $foreignMeter = readingEntryMeter($organization, $utilityService, ['region_id' => $foreignRegion->id]);

    Livewire::test(MeterReadingEntry::class)
        ->set('tableFilters', null)
        ->call('updateTableColumnState', 'current_reading', (string) $foreignMeter->getKey(), '150');

    expect(MeterReading::query()->where('meter_id', $foreignMeter->id)->exists())->toBeFalse();
});

test('счётчик другой организации недоступен', function (): void {
    ['organization' => $organization] = readingEntryOrganization();
    billingPeriodFor($organization);

    // Фикстуры чужой организации создаются до установки тенанта: после
    // Filament::setTenant() фабрики принудительно проставляют его organization_id.
    $otherOrganization = Organization::factory()->create();
    $otherService = UtilityService::factory()->for($otherOrganization)->create();
    $otherMeter = readingEntryMeter($otherOrganization, $otherService);

    expect((int) $otherMeter->organization_id)->toBe((int) $otherOrganization->id);

    readingEntryOperator($organization);

    enterReading($otherMeter, '150');

    expect(MeterReading::query()->where('meter_id', $otherMeter->id)->exists())->toBeFalse();
});

test('участник без роли не открывает страницу', function (): void {
    ['organization' => $organization] = readingEntryOrganization();

    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => '']);
    readingEntryActingAs($user, $organization);

    expect(MeterReadingEntry::canAccess())->toBeFalse();
});

test('оператор открывает страницу и вводит показание по любому счётчику организации', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = readingEntryOrganization();
    billingPeriodFor($organization);
    readingEntryOperator($organization);

    $meter = readingEntryMeter($organization, $utilityService);

    $this->get(MeterReadingEntry::getUrl(tenant: $organization))->assertSuccessful();

    enterReading($meter, '180');

    expect((int) MeterReading::query()->where('meter_id', $meter->id)->value('current_reading'))->toBe(180);
});

test('страница чужого тенанта не открывается', function (): void {
    ['organization' => $organization] = readingEntryOrganization();
    readingEntryOperator($organization);

    $otherOrganization = Organization::factory()->create();

    $this->get(MeterReadingEntry::getUrl(tenant: $otherOrganization))->assertNotFound();
});

test('страница показывает прогресс, инлайн-поле и адаптивную таблицу', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = readingEntryOrganization();
    billingPeriodFor($organization);
    readingEntryOperator($organization);

    $meter = readingEntryMeter($organization, $utilityService);

    enterReading($meter, '150');

    Livewire::test(MeterReadingEntry::class)
        ->assertOk()
        ->assertSee('Обход участка')
        ->assertSee('Текущее показание')
        ->assertSee('Только не снятые')
        // Инлайн-редактирование и адаптивная вёрстка держатся на этих классах.
        ->assertSeeHtml('fi-readings-entry')
        ->assertSeeHtml('fi-ta-table-stacked-on-mobile');
});

// --- Расчётный месяц --------------------------------------------------------

test('без открытого расчётного месяца показание не сохраняется и приходит ошибка', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = readingEntryOrganization();
    readingEntryOperator($organization);

    $meter = readingEntryMeter($organization, $utilityService);

    enterReading($meter, '150')->assertNotified('Показание не сохранено');

    expect(MeterReading::query()->count())->toBe(0);
});

test('в закрытом расчётном месяце показание не сохраняется и приходит ошибка', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = readingEntryOrganization();
    closedBillingPeriodFor($organization, '202605');
    readingEntryOperator($organization);

    $meter = readingEntryMeter($organization, $utilityService);

    enterReading($meter, '150')->assertNotified('Показание не сохранено');

    expect(MeterReading::query()->count())->toBe(0);
});

test('месяц в статусе processing блокирует ввод', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = readingEntryOrganization();
    billingPeriodFor($organization)->markProcessing();
    readingEntryOperator($organization);

    $meter = readingEntryMeter($organization, $utilityService);

    enterReading($meter, '150')->assertNotified('Показание не сохранено');

    expect(MeterReading::query()->count())->toBe(0);
});

test('в месяце со статусом failed показание сохраняется', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = readingEntryOrganization();
    $billingPeriod = billingPeriodFor($organization);
    $billingPeriod->markProcessing();
    $billingPeriod->markFailed(
        ['active' => 1, 'created' => 0, 'skipped' => 0, 'failed' => 1],
        'Не все абоненты рассчитаны.',
    );
    readingEntryOperator($organization);

    $meter = readingEntryMeter($organization, $utilityService);

    enterReading($meter, '150');

    expect((int) MeterReading::query()->where('meter_id', $meter->id)->value('current_reading'))->toBe(150);
});

test('месяц закрытый после отрисовки страницы не теряет показание молча', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = readingEntryOrganization();
    $billingPeriod = billingPeriodFor($organization);
    readingEntryOperator($organization);

    $meter = readingEntryMeter($organization, $utilityService);

    $page = Livewire::test(MeterReadingEntry::class);

    $billingPeriod->forceFill(['status' => BillingPeriodStatus::Closed, 'closed_at' => now()])->save();

    $page->call('updateTableColumnState', 'current_reading', (string) $meter->getKey(), '150')
        ->assertNotified('Показание не сохранено');

    expect(MeterReading::query()->count())->toBe(0);
});

// --- Состав списка ----------------------------------------------------------

test('абоненты с расчётом не по счётчику в список не попадают', function (string $billingType): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = readingEntryOrganization();
    billingPeriodFor($organization);
    readingEntryOperator($organization);

    $meter = readingEntryMeter($organization, $utilityService, ['billing_type' => $billingType]);

    Livewire::test(MeterReadingEntry::class)->assertCanNotSeeTableRecords([$meter]);

    enterReading($meter, '150');

    expect(MeterReading::query()->count())->toBe(0);
})->with(['per_person', 'fixed']);

test('архивный счётчик и неактивный абонент в список не попадают', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = readingEntryOrganization();
    billingPeriodFor($organization);
    readingEntryOperator($organization);

    $archivedMeter = readingEntryMeter($organization, $utilityService, [], ['status' => 'removed']);
    $inactiveClientMeter = readingEntryMeter($organization, $utilityService, ['status' => 'inactive']);
    $visibleMeter = readingEntryMeter($organization, $utilityService);

    Livewire::test(MeterReadingEntry::class)
        ->assertCanSeeTableRecords([$visibleMeter])
        ->assertCanNotSeeTableRecords([$archivedMeter, $inactiveClientMeter]);

    enterReading($archivedMeter, '150');

    expect(MeterReading::query()->where('meter_id', $archivedMeter->id)->exists())->toBeFalse();
});

// --- Данные -----------------------------------------------------------------

test('предыдущее показание берётся из более раннего периода, а не из текущего', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = readingEntryOrganization();
    $previousPeriod = billingPeriodFor($organization, '202604');
    readingEntryOperator($organization);

    $meter = readingEntryMeter($organization, $utilityService);

    MeterReading::query()->create([
        'meter_id' => $meter->id,
        'billing_period_id' => $previousPeriod->id,
        'previous_reading' => 100,
        'current_reading' => 120,
    ]);

    $previousPeriod->forceFill(['status' => BillingPeriodStatus::Closed, 'closed_at' => now()])->save();
    billingPeriodFor($organization, '202605');

    enterReading($meter, '135');

    $reading = MeterReading::query()->where('meter_id', $meter->id)->orderByDesc('id')->first();

    expect((int) $reading->previous_reading)->toBe(120)
        ->and((int) $reading->consumption)->toBe(15);

    // Колонка «Предыдущее» обязана остаться на 120, иначе контролёр решит,
    // что ввёл значение дважды.
    Livewire::test(MeterReadingEntry::class)
        ->assertTableColumnStateSet('previous_reading_for_entry', 120, $meter);
});

test('повторный ввод обновляет показание и не сдвигает предыдущее', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = readingEntryOrganization();
    billingPeriodFor($organization);
    readingEntryOperator($organization);

    $meter = readingEntryMeter($organization, $utilityService);

    enterReading($meter, '150');
    enterReading($meter, '160');

    $readings = MeterReading::query()->where('meter_id', $meter->id)->get();

    expect($readings)->toHaveCount(1)
        ->and((int) $readings->first()->current_reading)->toBe(160)
        ->and((int) $readings->first()->previous_reading)->toBe(100)
        ->and((int) $readings->first()->consumption)->toBe(60);
});

test('дробное значение отклоняется валидацией и ничего не пишет', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = readingEntryOrganization();
    billingPeriodFor($organization);
    readingEntryOperator($organization);

    $meter = readingEntryMeter($organization, $utilityService);

    enterReading($meter, '12.7')
        ->assertReturned(fn (mixed $value): bool => is_array($value) && array_key_exists('error', $value));

    expect(MeterReading::query()->count())->toBe(0);
});

test('пустое значение ничего не удаляет', function (mixed $emptyValue): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = readingEntryOrganization();
    billingPeriodFor($organization);
    readingEntryOperator($organization);

    $meter = readingEntryMeter($organization, $utilityService);

    enterReading($meter, '150');

    $reading = MeterReading::query()->where('meter_id', $meter->id)->firstOrFail();
    $reading->forceFill(['photo_path' => 'meter-reading-photos/1/photo.jpg'])->save();

    enterReading($meter, $emptyValue);

    $reading->refresh();

    expect((int) $reading->current_reading)->toBe(150)
        ->and($reading->photo_path)->toBe('meter-reading-photos/1/photo.jpg');
})->with([[''], [null]]);

test('отрицательный расход сохраняется, предупреждает и попадает в счётчик проблемных', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = readingEntryOrganization();
    billingPeriodFor($organization);
    readingEntryOperator($organization);

    $meter = readingEntryMeter($organization, $utilityService, [], ['initial_reading' => 500]);

    enterReading($meter, '400')->assertNotified('Отрицательный расход');

    $reading = MeterReading::query()->where('meter_id', $meter->id)->firstOrFail();

    expect((int) $reading->consumption)->toBe(-100);

    $progress = Livewire::test(MeterReadingEntry::class)->instance()->readingProgress();

    expect($progress['problem'])->toBe(1)
        ->and($progress['taken'])->toBe(1)
        ->and($progress['total'])->toBe(1)
        ->and($progress['percent'])->toBe(100);
});

test('строки идут в порядке обхода: дом 2 раньше дома 10', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'region' => $region, 'street' => $street] = readingEntryOrganization();
    billingPeriodFor($organization);
    readingEntryOperator($organization);

    $tenth = readingEntryMeter($organization, $utilityService, [
        'region_id' => $region->id,
        'street_id' => $street->id,
        'house' => '10',
    ]);

    $second = readingEntryMeter($organization, $utilityService, [
        'region_id' => $region->id,
        'street_id' => $street->id,
        'house' => '2',
    ]);

    Livewire::test(MeterReadingEntry::class)
        ->assertCanSeeTableRecords([$second, $tenth], inOrder: true);
});

// --- Квитанции --------------------------------------------------------------

test('сохранение показания формирует квитанцию с суммарным объёмом по абоненту', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService] = readingEntryOrganization();
    $billingPeriod = billingPeriodFor($organization);
    readingEntryOperator($organization);

    $firstMeter = readingEntryMeter($organization, $utilityService);
    $secondMeter = Meter::factory()
        ->for($organization)
        ->for($firstMeter->client)
        ->for($utilityService)
        ->create(['initial_reading' => 200, 'status' => 'active']);

    enterReading($firstMeter, '150');
    enterReading($secondMeter, '230');

    $receipt = Receipt::query()
        ->where('client_id', $firstMeter->client_id)
        ->where('billing_period_id', $billingPeriod->id)
        ->firstOrFail();

    expect((float) $receipt->volume)->toBe(80.0)
        ->and((float) $receipt->amount)->toBe(8000.0);
});

// --- Производительность -----------------------------------------------------

test('число запросов списка не растёт вместе с числом счётчиков', function (): void {
    ['organization' => $organization, 'utilityService' => $utilityService, 'region' => $region, 'street' => $street] = readingEntryOrganization();
    billingPeriodFor($organization);
    readingEntryOperator($organization);

    $renderQueryCount = function (int $meters) use ($organization, $utilityService, $region, $street): int {
        while (Meter::query()->count() < $meters) {
            readingEntryMeter($organization, $utilityService, [
                'region_id' => $region->id,
                'street_id' => $street->id,
                'house' => (string) (Meter::query()->count() + 1),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(MeterReadingEntry::class)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $withFew = $renderQueryCount(3);
    $withMany = $renderQueryCount(30);

    expect($withMany)->toBe($withFew);
});
