# Город в адресном справочнике организации — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить уровень «Город» в адресный справочник tenant-организации: Организация → Город → Регион → Улица.

**Architecture:** Новая таблица `cities` и модель `City`; в `regions` появляется обязательный `city_id`, а `organization_id` региона синхронизируется из города (тот же приём, что у `Street` с регионом). В Filament справочник в профиле организации переключается с регионов на города (город → регионы → улицы), а карточка абонента получает каскад Город → Регион → Улица, где город не хранится у абонента.

**Tech Stack:** PHP 8.4, Laravel 13, Filament v5, Livewire v4, Pest v4, MariaDB (docker).

**Spec:** `docs/superpowers/specs/2026-08-08-organization-cities-design.md`

## Global Constraints

- Тесты запускаются только через `make test` (фильтр: `make test test_args="--compact --filter=ИмяТеста"`). Приложение и БД живут в docker; host-PHP годится только для генерации файлов (`php artisan make:...`).
- После изменения PHP-файлов перед коммитом запускать `vendor/bin/pint --dirty --format agent`.
- Все подписи в Filament — на русском: «Город», «Города», «Название».
- Модели: атрибут `#[Fillable([...])]`, `public $timestamps = false` для справочников адресов (как у `Region` и `Street`).
- Не удалять существующие тесты; обновлять их под новые правила можно (это часть спеки).
- Документация обновляется в той же задаче (`docs/modules/*.md`, `docs/changelog.md`).
- Коммиты — на русском, в стиле истории репозитория, с трейлером `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

---

### Task 1: Схема данных и модели (cities, city_id у regions, фабрики)

**Files:**
- Create: `database/migrations/2026_08_08_XXXXXX_create_cities_and_add_city_to_regions.php` (через `php artisan make:migration`)
- Create: `app/Models/City.php`
- Create: `database/factories/CityFactory.php`
- Modify: `app/Models/Region.php`
- Modify: `app/Models/Organization.php`
- Modify: `database/factories/RegionFactory.php`
- Test: `tests/Feature/OrganizationTenancyTest.php`

**Interfaces:**
- Produces: модель `App\Models\City` (`organization(): BelongsTo`, `regions(): HasMany`, fillable `organization_id`, `name`); `Organization::cities(): HasMany`; `Region::city(): BelongsTo`; `Region::booted()` синхронизирует `organization_id` из города при сохранении; `Region::factory()` сам создаёт город в той же организации, поэтому существующие вызовы `Region::factory()->for($organization)` продолжают работать; `City::factory()` с `->for($organization)`.
- Уникальность в БД: `cities (organization_id, name)`; `regions (city_id, name)` вместо `(organization_id, name)`.

- [ ] **Step 1: Написать падающие тесты**

В `tests/Feature/OrganizationTenancyTest.php`:

1. Добавить импорт `use App\Models\City;` к остальным импортам.
2. После теста `'regions and streets belong to an organization'` добавить три новых теста:

```php
test('cities belong to an organization and city names are unique inside it', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();

    $city = City::factory()->for($organization)->create(['name' => 'Алматы']);

    expect($city->organization->is($organization))->toBeTrue()
        ->and($organization->cities()->whereKey($city)->exists())->toBeTrue();

    expect(fn () => City::factory()->for($organization)->create(['name' => 'Алматы']))
        ->toThrow(QueryException::class);

    $sameNameInOtherOrganization = City::factory()->for($otherOrganization)->create(['name' => 'Алматы']);

    expect($sameNameInOtherOrganization)->toBeInstanceOf(City::class);
});

test('region belongs to a city and syncs its organization from the city', function () {
    $organization = Organization::factory()->create();
    $city = City::factory()->for($organization)->create();

    $region = Region::factory()->for($organization)->for($city)->create();

    expect($region->city->is($city))->toBeTrue()
        ->and($region->organization->is($organization))->toBeTrue()
        ->and($city->regions()->whereKey($region)->exists())->toBeTrue();
});

test('deleting a city deletes its regions and streets', function () {
    $organization = Organization::factory()->create();
    $city = City::factory()->for($organization)->create();
    $region = Region::factory()->for($organization)->for($city)->create();
    $street = Street::factory()->for($region)->create();

    $city->delete();

    expect(Region::query()->whereKey($region)->exists())->toBeFalse()
        ->and(Street::query()->whereKey($street)->exists())->toBeFalse();
});
```

3. Заменить тест `'region and street names are unique inside their owner'` целиком (уникальность региона теперь внутри города, а не организации):

```php
test('region and street names are unique inside their owner', function () {
    $organization = Organization::factory()->create();
    $city = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $otherCity = City::factory()->for($organization)->create(['name' => 'Астана']);
    $region = Region::factory()
        ->for($organization)
        ->for($city)
        ->create(['name' => 'Центр']);

    expect(fn () => Region::factory()
        ->for($organization)
        ->for($city)
        ->create(['name' => 'Центр']))->toThrow(QueryException::class);

    $sameNameInOtherCity = Region::factory()
        ->for($organization)
        ->for($otherCity)
        ->create(['name' => 'Центр']);

    Street::factory()
        ->for($region)
        ->create(['name' => 'Абая']);

    expect(fn () => Street::factory()
        ->for($region)
        ->create(['name' => 'Абая']))->toThrow(QueryException::class);

    $sameNameInOtherRegion = Street::factory()
        ->for($sameNameInOtherCity)
        ->create(['name' => 'Абая']);

    expect($sameNameInOtherCity)->toBeInstanceOf(Region::class)
        ->and($sameNameInOtherRegion)->toBeInstanceOf(Street::class);
});
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `make test test_args="--compact --filter=OrganizationTenancyTest"`
Expected: FAIL — `Class "App\Models\City" not found` (и/или ошибки схемы).

- [ ] **Step 3: Создать миграцию**

Run: `php artisan make:migration create_cities_and_add_city_to_regions --no-interaction`

Содержимое миграции (важен порядок: сначала индекс под FK `organization_id`, потом снятие старого уникального индекса, который сейчас обслуживает этот FK):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            $table->unique(['organization_id', 'name'], 'cities_org_name_unique');
        });

        Schema::table('regions', function (Blueprint $table) {
            $table->foreignId('city_id')
                ->nullable()
                ->after('organization_id')
                ->constrained()
                ->cascadeOnDelete();
        });

        $organizationIds = DB::table('regions')
            ->whereNull('city_id')
            ->distinct()
            ->pluck('organization_id');

        foreach ($organizationIds as $organizationId) {
            $cityId = DB::table('cities')->insertGetId([
                'organization_id' => $organizationId,
                'name' => 'Город',
            ]);

            DB::table('regions')
                ->where('organization_id', $organizationId)
                ->whereNull('city_id')
                ->update(['city_id' => $cityId]);
        }

        Schema::table('regions', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable(false)->change();
            $table->index('organization_id', 'regions_organization_id_index');
        });

        Schema::table('regions', function (Blueprint $table) {
            $table->dropUnique('regions_org_name_unique');
            $table->unique(['city_id', 'name'], 'regions_city_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->unique(['organization_id', 'name'], 'regions_org_name_unique');
            $table->dropUnique('regions_city_name_unique');
        });

        Schema::table('regions', function (Blueprint $table) {
            $table->dropIndex('regions_organization_id_index');
            $table->dropConstrainedForeignId('city_id');
        });

        Schema::dropIfExists('cities');
    }
};
```

- [ ] **Step 4: Создать модель `City` и фабрику**

`app/Models/City.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'name',
])]
class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    public $timestamps = false;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function regions(): HasMany
    {
        return $this->hasMany(Region::class);
    }
}
```

`database/factories/CityFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->city(),
        ];
    }
}
```

- [ ] **Step 5: Обновить `Region`, `Organization`, `RegionFactory`**

`app/Models/Region.php` — добавить `city_id` в `#[Fillable]`, связь `city()` и синхронизацию организации из города (зеркально `Street::booted()`):

```php
#[Fillable([
    'organization_id',
    'city_id',
    'name',
])]
```

```php
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Region $region): void {
            if (! $region->city_id) {
                return;
            }

            $region->organization_id = City::query()
                ->whereKey($region->city_id)
                ->value('organization_id');
        });
    }
```

`app/Models/Organization.php` — добавить связь рядом с `regions()`:

```php
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }
```

`database/factories/RegionFactory.php` — город создаётся в той же организации, что и регион; порядок ключей важен (`organization_id` резолвится до замыкания `city_id`, как в `StreetFactory`):

```php
<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Organization;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Region>
 */
class RegionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'city_id' => fn (array $attributes): int => City::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->getKey(),
            'name' => fake()->unique()->city(),
        ];
    }
}
```

- [ ] **Step 6: Прогнать тесты задачи**

Run: `make test test_args="--compact --filter=OrganizationTenancyTest"`
Expected: PASS, кроме теста `'tenant profile manages organization regions'` — он завязан на профильный `RegionsRelationManager` и будет переделан в Task 2. Если падает только он — это ожидаемо, двигаться дальше. Если падает что-то ещё — чинить здесь.

Затем прогнать соседние наборы, которые массово используют `Region::factory()` / `Street::factory()`:

Run: `make test test_args="--compact --filter=ClientTest"`
Run: `make test test_args="--compact --filter=OrganizationMemberAccessTest"`
Expected: PASS (фабрика сама создаёт город; формы абонента меняются только в Task 3).

- [ ] **Step 7: Pint и коммит**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "Добавлены города: таблица cities, обязательный city_id у регионов, синхронизация организации региона из города.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Справочник городов в Filament (профиль → города → регионы → улицы)

**Files:**
- Create: `app/Filament/Resources/Cities/CityResource.php`
- Create: `app/Filament/Resources/Cities/Schemas/CityForm.php`
- Create: `app/Filament/Resources/Cities/Tables/CitiesTable.php`
- Create: `app/Filament/Resources/Cities/Pages/ListCities.php`
- Create: `app/Filament/Resources/Cities/Pages/CreateCity.php`
- Create: `app/Filament/Resources/Cities/Pages/EditCity.php`
- Create: `app/Filament/Resources/Cities/RelationManagers/RegionsRelationManager.php`
- Create: `app/Filament/Pages/Tenancy/RelationManagers/CitiesRelationManager.php`
- Delete: `app/Filament/Pages/Tenancy/RelationManagers/RegionsRelationManager.php`
- Modify: `app/Filament/Pages/Tenancy/EditOrganizationProfile.php`
- Modify: `app/Filament/Resources/Regions/Schemas/RegionForm.php`
- Test: `tests/Feature/OrganizationTenancyTest.php`

**Interfaces:**
- Consumes: `App\Models\City`, `Organization::cities()`, `Region::city()` из Task 1.
- Produces: `App\Filament\Resources\Cities\CityResource` (страницы `index`/`create`/`edit`, доступ через `OrganizationMemberAccess::canManageTenant()`); `App\Filament\Pages\Tenancy\RelationManagers\CitiesRelationManager` (в профиле организации, ключ Livewire `organization-cities`); `App\Filament\Resources\Cities\RelationManagers\RegionsRelationManager` (в карточке города). Ресурсы Filament обнаруживаются автоматически (`discoverResources`), регистрация не нужна.

- [ ] **Step 1: Написать падающие тесты**

В `tests/Feature/OrganizationTenancyTest.php`:

1. Обновить импорты: удалить `use App\Filament\Pages\Tenancy\RelationManagers\RegionsRelationManager;`, добавить:

```php
use App\Filament\Pages\Tenancy\RelationManagers\CitiesRelationManager;
use App\Filament\Resources\Cities\Pages\EditCity;
use App\Filament\Resources\Cities\RelationManagers\RegionsRelationManager as CityRegionsRelationManager;
```

2. Заменить тест `'tenant profile manages organization regions'` на два новых:

```php
test('tenant profile manages organization cities', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $currentCity = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $otherCity = City::factory()->for($otherOrganization)->create(['name' => 'Астана']);

    actingAsOrganizationTenant($organization);

    Livewire::test(CitiesRelationManager::class, [
        'ownerRecord' => $organization,
        'pageClass' => EditOrganizationProfile::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$currentCity])
        ->assertCanNotSeeTableRecords([$otherCity])
        ->callTableAction('create', data: [
            'name' => 'Шымкент',
        ])
        ->assertHasNoTableActionErrors();

    expect($organization->cities()->where('name', 'Шымкент')->exists())->toBeTrue();
});

test('city card manages regions of the city', function () {
    $organization = Organization::factory()->create();
    $city = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $otherCity = City::factory()->for($organization)->create(['name' => 'Астана']);
    $cityRegion = Region::factory()->for($organization)->for($city)->create(['name' => 'Алмалинский']);
    $otherCityRegion = Region::factory()->for($organization)->for($otherCity)->create(['name' => 'Есильский']);

    actingAsOrganizationTenant($organization);

    Livewire::test(CityRegionsRelationManager::class, [
        'ownerRecord' => $city,
        'pageClass' => EditCity::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$cityRegion])
        ->assertCanNotSeeTableRecords([$otherCityRegion])
        ->callTableAction('create', data: [
            'name' => 'Бостандыкский',
        ])
        ->assertHasNoTableActionErrors();

    expect($city->regions()->where('name', 'Бостандыкский')->whereBelongsTo($organization)->exists())->toBeTrue();
});
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `make test test_args="--compact --filter=OrganizationTenancyTest"`
Expected: FAIL — `Class "App\Filament\Pages\Tenancy\RelationManagers\CitiesRelationManager" not found`.

- [ ] **Step 3: Создать `CityResource` со схемой, таблицей и страницами**

`app/Filament/Resources/Cities/CityResource.php` (зеркало `RegionResource`):

```php
<?php

namespace App\Filament\Resources\Cities;

use App\Filament\Resources\Cities\Pages\CreateCity;
use App\Filament\Resources\Cities\Pages\EditCity;
use App\Filament\Resources\Cities\Pages\ListCities;
use App\Filament\Resources\Cities\RelationManagers\RegionsRelationManager;
use App\Filament\Resources\Cities\Schemas\CityForm;
use App\Filament\Resources\Cities\Tables\CitiesTable;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\City;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CityResource extends Resource
{
    protected static ?string $model = City::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'город';

    protected static ?string $pluralModelLabel = 'города';

    protected static ?string $navigationLabel = 'Города';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CitiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RegionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCities::route('/'),
            'create' => CreateCity::route('/create'),
            'edit' => EditCity::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    public static function canViewAny(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    public static function canCreate(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    public static function canEdit(Model $record): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    public static function canDelete(Model $record): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    public static function canDeleteAny(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }
}
```

`app/Filament/Resources/Cities/Schemas/CityForm.php`:

```php
<?php

namespace App\Filament\Resources\Cities\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class CityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: 'cities',
                        column: 'name',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule
                            ->where('organization_id', Filament::getTenant()?->getKey()),
                    ),
            ]);
    }
}
```

`app/Filament/Resources/Cities/Tables/CitiesTable.php` (зеркало `RegionsTable`, счётчик регионов):

```php
<?php

namespace App\Filament\Resources\Cities\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('regions')
                ->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('regions_count')
                    ->label('Регионы')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

`app/Filament/Resources/Cities/Pages/ListCities.php`:

```php
<?php

namespace App\Filament\Resources\Cities\Pages;

use App\Filament\Resources\Cities\CityResource;
use App\Filament\Support\OrganizationMemberAccess;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCities extends ListRecords
{
    protected static string $resource = CityResource::class;

    public function mount(): void
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
```

`app/Filament/Resources/Cities/Pages/CreateCity.php`:

```php
<?php

namespace App\Filament\Resources\Cities\Pages;

use App\Filament\Resources\Cities\CityResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateCity extends CreateRecord
{
    protected static string $resource = CityResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organization_id'] = Filament::getTenant()?->getKey();

        return $data;
    }
}
```

`app/Filament/Resources/Cities/Pages/EditCity.php`:

```php
<?php

namespace App\Filament\Resources\Cities\Pages;

use App\Filament\Resources\Cities\CityResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCity extends EditRecord
{
    protected static string $resource = CityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
```

- [ ] **Step 4: Создать `RegionsRelationManager` в карточке города**

`app/Filament/Resources/Cities/RelationManagers/RegionsRelationManager.php` — зеркало старого профильного `RegionsRelationManager`, но владелец — город; уникальность имени — внутри города; «Открыть» ведёт в карточку региона с улицами:

```php
<?php

namespace App\Filament\Resources\Cities\RelationManagers;

use App\Filament\Resources\Regions\RegionResource;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Region;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

class RegionsRelationManager extends RelationManager
{
    protected static string $relationship = 'regions';

    protected static ?string $title = 'Регионы';

    protected static ?string $modelLabel = 'регион';

    protected static ?string $pluralModelLabel = 'регионы';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return OrganizationMemberAccess::canManageTenant()
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->ownerRecord, $this->pageClass ?? static::class), 403);

        parent::mount();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: 'regions',
                        column: 'name',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule
                            ->where('city_id', $this->ownerRecord->getKey()),
                    ),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('streets')
                ->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('streets_count')
                    ->label('Улицы')
                    ->numeric()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        $data['organization_id'] = $this->ownerRecord->organization_id;

                        return $data;
                    }),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Открыть')
                    ->url(fn (Region $record): string => RegionResource::getUrl('edit', ['record' => $record])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

- [ ] **Step 5: Создать профильный `CitiesRelationManager` и подключить его в профиль**

`app/Filament/Pages/Tenancy/RelationManagers/CitiesRelationManager.php`:

```php
<?php

namespace App\Filament\Pages\Tenancy\RelationManagers;

use App\Filament\Resources\Cities\CityResource;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\City;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

class CitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'cities';

    protected static ?string $title = 'Города';

    protected static ?string $modelLabel = 'город';

    protected static ?string $pluralModelLabel = 'города';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return OrganizationMemberAccess::canManageTenant()
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->ownerRecord, $this->pageClass ?? static::class), 403);

        parent::mount();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: 'cities',
                        column: 'name',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule
                            ->where('organization_id', $this->ownerRecord->getKey()),
                    ),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('regions')
                ->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('regions_count')
                    ->label('Регионы')
                    ->numeric()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        $data['organization_id'] = $this->ownerRecord->getKey();

                        return $data;
                    }),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Открыть')
                    ->url(fn (City $record): string => CityResource::getUrl('edit', ['record' => $record])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

В `app/Filament/Pages/Tenancy/EditOrganizationProfile.php`:

1. Импорт `use App\Filament\Pages\Tenancy\RelationManagers\RegionsRelationManager;` заменить на `use App\Filament\Pages\Tenancy\RelationManagers\CitiesRelationManager;`.
2. В `content()` заменить блок:

```php
                Livewire::make(RegionsRelationManager::class, fn (): array => [
                    'ownerRecord' => $this->tenant,
                    'pageClass' => static::class,
                ])
                    ->key('organization-regions')
                    ->columnSpanFull(),
```

на:

```php
                Livewire::make(CitiesRelationManager::class, fn (): array => [
                    'ownerRecord' => $this->tenant,
                    'pageClass' => static::class,
                ])
                    ->key('organization-cities')
                    ->columnSpanFull(),
```

3. Удалить файл `app/Filament/Pages/Tenancy/RelationManagers/RegionsRelationManager.php` (`git rm`).

- [ ] **Step 6: Обновить `RegionForm` (город региона + уникальность внутри города)**

Регион теперь требует город, поэтому standalone-страницы создания/редактирования региона должны позволять выбрать его. Заменить `app/Filament/Resources/Regions/Schemas/RegionForm.php` целиком:

```php
<?php

namespace App\Filament\Resources\Regions\Schemas;

use App\Models\City;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class RegionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('city_id')
                    ->label('Город')
                    ->options(fn (): array => Filament::getTenant()
                        ?->cities()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all() ?? [])
                    ->searchable()
                    ->preload()
                    ->required()
                    ->scopedExists(City::class, 'id')
                    ->live()
                    ->native(false),
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: 'regions',
                        column: 'name',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                            ->where('city_id', $get('city_id')),
                    ),
            ]);
    }
}
```

`CreateRegion` и `EditRegion` менять не нужно: `organization_id` заполняется из tenant при создании и в любом случае пересинхронизируется из города моделью.

- [ ] **Step 7: Прогнать тесты**

Run: `make test test_args="--compact --filter=OrganizationTenancyTest"`
Expected: PASS (все, включая два новых теста).

Run: `make test test_args="--compact --filter=OrganizationMemberAccessTest"`
Expected: PASS (доступ контроллёра к профилю не менялся).

- [ ] **Step 8: Pint и коммит**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "Справочник адресов в профиле организации переведён на города: профиль показывает города, регионы управляются в карточке города, улицы — в карточке региона.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Каскад Город → Регион → Улица в карточке абонента

**Files:**
- Modify: `app/Filament/Resources/Clients/Schemas/ClientForm.php`
- Test: `tests/Feature/ClientTest.php`

**Interfaces:**
- Consumes: `App\Models\City`, `Region::city()` из Task 1.
- Produces: в форме абонента поле `city_id` — только фильтр: `saved(false)`, в таблицу `clients` не пишется; `region_id` валидируется правилом `exists` с учётом `city_id`. В Livewire-тестах формы абонента теперь обязательно заполнять `city_id` (иначе `required` и заблокированный `region_id`).

- [ ] **Step 1: Написать падающие тесты**

В `tests/Feature/ClientTest.php` добавить импорт `use App\Models\City;` и два теста (после теста `'admin users can create a client for the current tenant'`):

```php
test('client region must belong to the selected city', function () {
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create();
    $city = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $otherCity = City::factory()->for($organization)->create(['name' => 'Астана']);
    $region = Region::factory()->for($organization)->for($otherCity)->create();
    $street = Street::factory()->for($region)->create();

    actingAsTenant($organization);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'Каскад Абонент',
            'iin' => '870101300456',
            'client_type' => ClientType::Individual->value,
            'billing_type' => 'per_person',
            'residents_count' => 1,
            'fixed_amount' => 0,
            'phone' => '+7 777 555 66 77',
            'contract' => 'Договор №20',
            'city_id' => $city->getKey(),
            'region_id' => $region->getKey(),
            'street_id' => $street->getKey(),
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasFormErrors(['region_id']);
});

test('client card hydrates city from the region on edit', function () {
    $organization = Organization::factory()->create();
    UtilityService::factory()->for($organization)->create();
    $city = City::factory()->for($organization)->create(['name' => 'Алматы']);
    $region = Region::factory()->for($organization)->for($city)->create();
    $street = Street::factory()->for($region)->create();
    $client = Client::factory()
        ->for($organization)
        ->for($region)
        ->for($street)
        ->create();

    actingAsTenant($organization);

    Livewire::test(EditClient::class, [
        'record' => $client->getKey(),
    ])
        ->assertFormSet([
            'city_id' => $city->getKey(),
        ]);
});
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `make test test_args="--compact --filter=ClientTest"`
Expected: FAIL — первый тест проходит создание без ошибок (`assertHasFormErrors` не находит ошибку `region_id`), второй — `city_id` отсутствует в форме.

- [ ] **Step 3: Обновить `ClientForm`**

В `app/Filament/Resources/Clients/Schemas/ClientForm.php`:

1. Добавить импорты:

```php
use App\Models\City;
use App\Models\Client;
```

2. Перед `Select::make('region_id')` вставить поле города:

```php
                        Select::make('city_id')
                            ->label('Город')
                            ->options(fn (): array => Filament::getTenant()
                                ?->cities()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all() ?? [])
                            ->searchable()
                            ->preload()
                            ->required()
                            ->scopedExists(City::class, 'id')
                            ->live()
                            ->saved(false)
                            ->afterStateHydrated(function (Set $set, ?Client $record): void {
                                if ($record?->region?->city_id !== null) {
                                    $set('city_id', $record->region->city_id);
                                }
                            })
                            ->afterStateUpdated(function (Set $set): void {
                                $set('region_id', null);
                                $set('street_id', null);
                            })
                            ->native(false),
```

3. Заменить существующий `Select::make('region_id')` целиком (опции фильтруются по городу, `scopedExists` заменяется явным правилом с `city_id`, поле блокируется без города — зеркально текущему полю улицы):

```php
                        Select::make('region_id')
                            ->label('Регион')
                            ->options(fn (Get $get): array => Filament::getTenant()
                                ?->regions()
                                ->where('city_id', $get('city_id'))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all() ?? [])
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabled(fn (Get $get): bool => blank($get('city_id')))
                            ->rules(fn (Get $get): array => [
                                Rule::exists('regions', 'id')
                                    ->where('organization_id', Filament::getTenant()?->getKey())
                                    ->where('city_id', $get('city_id')),
                            ])
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('street_id', null))
                            ->native(false),
```

Поле `street_id` не меняется.

- [ ] **Step 4: Обновить существующие тесты формы абонента**

Run: `grep -n "'region_id' =>" tests/Feature/ClientTest.php`

В каждом `fillForm([...])` для `CreateClient`/`EditClient`, где заполняется `'region_id' => $X->getKey()`, добавить строкой выше `'city_id' => $X->city_id,` (той же переменной, чей ключ идёт в `region_id`). Пример:

```php
        ->fillForm([
            // ...
            'city_id' => $region->city_id,
            'region_id' => $region->getKey(),
            'street_id' => $street->getKey(),
            // ...
        ])
```

Вхождения `'region_id' =>` в фабриках (`Client::factory()->create([...])`), в DB-вставках и в фильтрах отчётов не трогать. В `tests/Feature/OrganizationMemberAccessTest.php` и `tests/Feature/OrganizationReportsTest.php` `region_id` встречается только в таких контекстах — эти файлы менять не нужно.

- [ ] **Step 5: Прогнать тесты**

Run: `make test test_args="--compact --filter=ClientTest"`
Expected: PASS.

Run: `make test test_args="--compact --filter=OrganizationMemberAccessTest"`
Run: `make test test_args="--compact --filter=OrganizationReportsTest"`
Expected: PASS.

- [ ] **Step 6: Pint и коммит**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "В карточке абонента адрес выбирается каскадом Город → Регион → Улица; город определяется через регион и отдельно не хранится.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Документация, полный прогон, ручная проверка

**Files:**
- Modify: `docs/modules/locations.md`
- Modify: `docs/modules/organizations.md`
- Modify: `docs/modules/clients.md`
- Modify: `docs/changelog.md`
- Modify: `docs/superpowers/specs/2026-08-08-organization-cities-design.md`

**Interfaces:**
- Consumes: фактическое поведение из Task 1–3.
- Produces: документация — источник истины по бизнес-логике (правило проекта).

- [ ] **Step 1: Обновить `docs/modules/locations.md`**

Переписать разделы с учётом городов. Обязательное содержание:

- Терминология: город в коде называется `City`, таблица — `cities` (плюс существующие Region/Street).
- Назначение: города — верхний уровень адресного справочника; регионы принадлежат городу; улицы — региону.
- Таблица «Основные поля города»: Организация (`organization_id`), Название (`name`).
- В полях региона добавить строку: Город (`city_id`).
- Правила: город относится к tenant-организации; название города уникально внутри tenant-организации; регион относится к городу; `organization_id` региона должен совпадать с `organization_id` города; название региона уникально внутри одного города (заменяет уникальность внутри организации); при удалении организации удаляются её города, регионы и улицы; при удалении города удаляются его регионы и улицы; в карточке абонента город выбирается из справочника текущей tenant-организации, после выбора города список регионов показывает только регионы выбранного города, после выбора региона — только улицы выбранного региона; город абонента отдельно не хранится и определяется через регион; нельзя сохранить абонента с регионом из другого города.
- Административная панель: города управляются в профиле организации как связанный справочник; регионы — внутри карточки города; улицы — внутри карточки региона; отдельные разделы городов и регионов в навигации не используются; в standalone-карточке региона можно выбрать город региона.
- Правила про зоны ответственности контроллёров оставить как есть (регионы и улицы, город не участвует).

- [ ] **Step 2: Обновить `docs/modules/organizations.md`**

- В разделе «Связи» заменить «организация имеет много регионов» на «организация имеет много городов; организация имеет много регионов через города» и оставить строку про улицы.
- В разделе «Профиль организации» заменить «Регионы организации управляются в профиле tenant-организации как связанный справочник. Улицы управляются внутри карточки региона.» на «Города организации управляются в профиле tenant-организации как связанный справочник. Регионы управляются внутри карточки города. Улицы управляются внутри карточки региона.»

- [ ] **Step 3: Обновить `docs/modules/clients.md`**

- В таблице полей перед строкой «Регион» добавить строку: «Город | Город из справочника tenant-организации; в карточке служит фильтром регионов и отдельно не хранится».
- Строку про `region_id` дополнить: «Поле `region_id` обязательно и выбирается из регионов выбранного города текущей tenant-организации.»
- В правилах адреса добавить: «Город абонента определяется через регион и отдельным полем не хранится.»

- [ ] **Step 4: Обновить `docs/changelog.md`**

Добавить сверху раздел (формат — как у существующих записей):

```markdown
## 2026-08-08

### Added

- В адресный справочник организации добавлен уровень «Город»: Организация → Город → Регион → Улица. Города управляются в профиле организации, регионы — в карточке города, улицы — в карточке региона.

### Changed

- Название региона теперь уникально внутри города, а не внутри организации.
- В карточке абонента адрес выбирается каскадом Город → Регион → Улица; город отдельно у абонента не хранится и определяется через регион.
- Существующие регионы при миграции привязаны к автоматически созданному городу «Город» своей организации (название можно изменить в профиле).
```

- [ ] **Step 5: Синхронизировать спеку с реализацией**

В `docs/superpowers/specs/2026-08-08-organization-cities-design.md`, раздел «RegionResource», заменить строку «Карточка региона с улицами не меняется, кроме правила уникальности названия…» на: «В карточке региона добавляется выбор города (регион обязан принадлежать городу, standalone-страница создания региона без этого неработоспособна); правило уникальности названия — внутри города вместо организации.»

- [ ] **Step 6: Полный прогон тестов**

Run: `make test`
Expected: PASS полностью. Любое падение чинится до перехода дальше.

- [ ] **Step 7: Ручная проверка UI (затронутые страницы)**

Прогнать миграцию на dev-базе и глазами проверить затронутые Filament-страницы:

```bash
make artisan artisan_args="migrate --no-interaction"
```

Проверить в браузере (dev-панель): профиль организации показывает справочник «Города» с существующим городом «Город» и счётчиком регионов; карточка города открывается и показывает регион; карточка региона показывает улицы; карточка абонента показывает каскад с предзаполненным городом.

- [ ] **Step 8: Коммит**

```bash
git add -A
git commit -m "Документация адресного справочника обновлена под иерархию Организация → Город → Регион → Улица.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```
