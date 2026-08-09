# Фото счётчика при вводе показания — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** К показанию счётчика можно прикрепить одно необязательное фото (контролёр снимает камерой телефона через браузер), с миниатюрами в таблицах показаний и автоматической очисткой файлов.

**Architecture:** Одна nullable-колонка `photo_path` в `meter_readings`; файлы на диске `public` в каталоге `meter-reading-photos/{organization_id}/`; поле — Filament `FileUpload`, собранное одним статическим методом `MeterReadingForm::photoUpload()` и переиспользуемое во всех трёх точках ввода; очистка файлов — хуки `updated`/`deleted` модели `MeterReading`.

**Tech Stack:** Laravel 13, Filament v5, Livewire v4, Pest 4, MariaDB, диск `public` (symlink и docker-volume уже настроены).

**Спека:** `docs/superpowers/specs/2026-08-09-meter-reading-photo-design.md`.

## Global Constraints

- Новые composer/npm-зависимости запрещены (правило проекта: «Do not change the application's dependencies without approval»).
- Тесты запускаются в docker: полный прогон `make test`, фильтрованный `make test test_args="--compact --filter=<имя>"`.
- Artisan-команды выполняются через `make artisan artisan_args="..."` (PHP живёт в docker-контейнере).
- После правок PHP: `vendor/bin/pint --dirty --format agent` (если pint недоступен на хосте — `make shell` и запустить внутри контейнера).
- Перед написанием Filament-кода использовать Boost MCP `search-docs` (например запросы `['file upload', 'image column', 'testing file upload']`) — проверить точные имена методов Filament v5; при расхождении с кодом плана приоритет у документации.
- Все пользовательские строки — на русском («Фото счётчика», «Фото»).
- Octane: не накапливать состояние в статических свойствах.
- Тесты не удалять; коммиты после каждой задачи; сообщения коммитов на русском, в конце `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Лимиты загрузки уже достаточны (PHP 32M в `docker/app/php.ini`, Livewire 12M по умолчанию) — конфиги и `laravel-docker-template` не трогать.

---

### Task 1: Колонка `photo_path`, fillable и очистка файлов в модели

**Files:**
- Create: `database/migrations/2026_08_09_000001_add_photo_path_to_meter_readings_table.php` (имя сгенерирует artisan — timestamp будет своим)
- Modify: `app/Models/MeterReading.php`
- Test: `tests/Feature/MeterReadingPhotoTest.php` (новый файл)

**Interfaces:**
- Consumes: существующие `MeterReading::factory()`, helper `billingPeriodFor()` из `tests/Pest.php`.
- Produces: колонка/атрибут `MeterReading::$photo_path` (string|null); константа `MeterReading::PHOTO_DISK = 'public'`; метод `MeterReading::photoDirectoryFor(int|string $organizationId): string` → `"meter-reading-photos/{$organizationId}"`. Задачи 2–3 используют именно их.

- [ ] **Step 1: Написать падающие тесты**

Создать `tests/Feature/MeterReadingPhotoTest.php`:

```php
<?php

use App\Models\MeterReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

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
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `make test test_args="--compact --filter=MeterReadingPhoto"`
Expected: FAIL (колонки `photo_path` нет — SQL error / photo_path остаётся null, `photoDirectoryFor` не существует).

- [ ] **Step 3: Создать миграцию**

Run: `make artisan artisan_args="make:migration add_photo_path_to_meter_readings_table --table=meter_readings --no-interaction"`

Если созданный контейнером файл принадлежит root и его нельзя редактировать — удалить и создать файл вручную с тем же именем. Содержимое:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->string('photo_path')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->dropColumn('photo_path');
        });
    }
};
```

- [ ] **Step 4: Обновить модель `MeterReading`**

В `app/Models/MeterReading.php`:

1. В атрибут `#[Fillable([...])]` добавить `'photo_path'` после `'note'`.
2. Добавить импорт `use Illuminate\Support\Facades\Storage;`.
3. После `DUPLICATE_BILLING_PERIOD_MESSAGE` добавить константу:

```php
    public const PHOTO_DISK = 'public';
```

4. Рядом со статическими методами (после `existsForMeterBillingPeriod`) добавить:

```php
    public static function photoDirectoryFor(int|string $organizationId): string
    {
        return "meter-reading-photos/{$organizationId}";
    }
```

5. В `booted()` после существующего `static::deleting(...)` добавить два хука:

```php
        static::updated(function (MeterReading $meterReading): void {
            $previousPhotoPath = $meterReading->getOriginal('photo_path');

            if ($meterReading->wasChanged('photo_path') && $previousPhotoPath) {
                Storage::disk(self::PHOTO_DISK)->delete($previousPhotoPath);
            }
        });

        static::deleted(function (MeterReading $meterReading): void {
            if ($meterReading->photo_path) {
                Storage::disk(self::PHOTO_DISK)->delete($meterReading->photo_path);
            }
        });
```

(В событии `updated` Laravel ещё не вызвал `syncOriginal()`, поэтому `getOriginal('photo_path')` возвращает старый путь.)

- [ ] **Step 5: Убедиться, что тесты проходят**

Run: `make test test_args="--compact --filter=MeterReadingPhoto"`
Expected: PASS (5 тестов).

- [ ] **Step 6: Pint и коммит**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations tests/Feature/MeterReadingPhotoTest.php app/Models/MeterReading.php
git commit -m "К показанию счётчика добавлено необязательное фото: колонка photo_path, автоочистка файла при замене и удалении показания.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Поле «Фото счётчика» во всех трёх формах ввода показаний

**Files:**
- Modify: `app/Filament/Resources/MeterReadings/Schemas/MeterReadingForm.php`
- Modify: `app/Filament/Resources/Meters/RelationManagers/ReadingsRelationManager.php` (метод `form`, строки ~39–68)
- Modify: `app/Filament/Resources/Clients/RelationManagers/MetersRelationManager.php` (методы `readingFormComponents` ~246–274, `readingActionFormData` ~291–308, action `addReading` ~177–203)
- Test: `tests/Feature/MeterReadingPhotoTest.php` (дополнить)

**Interfaces:**
- Consumes: `MeterReading::PHOTO_DISK`, `MeterReading::photoDirectoryFor()` из Task 1.
- Produces: `MeterReadingForm::photoUpload(): \Filament\Forms\Components\FileUpload` — статический метод, который вызывают оба relation-менеджера. Состояние поля называется `photo_path`.

- [ ] **Step 1: Написать падающие тесты**

Дополнить `tests/Feature/MeterReadingPhotoTest.php`. К импортам добавить:

```php
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\MetersRelationManager;
use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Models\Client;
use App\Models\Meter;
use App\Models\Organization;
use App\Models\User;
use App\Models\UtilityService;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
```

Добавить helper (имя уникальное, чтобы не конфликтовать с `actingAsMeterTenant` из `MeterTest.php`) и тесты:

```php
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
            'current_reading' => 137.125,
            'photo_path' => UploadedFile::fake()->image('meter.jpg'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $reading = MeterReading::query()->whereBelongsTo($meter)->sole();

    expect($reading->photo_path)->not->toBeNull()
        ->and($reading->photo_path)->toStartWith("meter-reading-photos/{$organization->id}/");
    Storage::disk('public')->assertExists($reading->photo_path);
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
            'current_reading' => 140.75,
            'photo_path' => UploadedFile::fake()->image('meter.jpg'),
        ])
        ->assertHasNoTableActionErrors();

    $reading = MeterReading::query()->whereBelongsTo($meter)->sole();

    expect($reading->photo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($reading->photo_path);
});
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `make test test_args="--compact --filter=MeterReadingPhoto"`
Expected: FAIL — два новых теста: `photo_path` остаётся null (поля в формах нет, данные отбрасываются).

- [ ] **Step 3: Добавить `MeterReadingForm::photoUpload()` и поле в форму ресурса**

В `app/Filament/Resources/MeterReadings/Schemas/MeterReadingForm.php`:

1. Импорт: `use Filament\Forms\Components\FileUpload;`.
2. В `configure()` после `Textarea::make('note')...` добавить элемент схемы `self::photoUpload(),`.
3. Добавить метод (перед `currentBillingPeriodId()`):

```php
    public static function photoUpload(): FileUpload
    {
        return FileUpload::make('photo_path')
            ->label('Фото счётчика')
            ->image()
            ->disk(MeterReading::PHOTO_DISK)
            ->directory(function (): string {
                $tenant = Filament::getTenant();

                return MeterReading::photoDirectoryFor(
                    $tenant instanceof Organization ? $tenant->getKey() : 0,
                );
            })
            ->maxSize(10240)
            ->imageResizeMode('contain')
            ->imageResizeTargetWidth('1920')
            ->imageResizeTargetHeight('1920')
            ->openable()
            ->columnSpanFull();
    }
```

Перед кодированием проверить через Boost `search-docs` (`['file upload']`), что в Filament v5 актуальны имена `imageResizeMode` / `imageResizeTargetWidth` / `openable`; при расхождении использовать документированные.

- [ ] **Step 4: Подключить поле в оба relation-менеджера**

1. `app/Filament/Resources/Meters/RelationManagers/ReadingsRelationManager.php` — импорт `use App\Filament\Resources\MeterReadings\Schemas\MeterReadingForm;`; в `form()` после `Textarea::make('note')...` добавить `MeterReadingForm::photoUpload(),`.
2. `app/Filament/Resources/Clients/RelationManagers/MetersRelationManager.php`:
   - импорт `use App\Filament\Resources\MeterReadings\Schemas\MeterReadingForm;`;
   - в `readingFormComponents()` после `Textarea::make('note')...` добавить `MeterReadingForm::photoUpload(),`;
   - в action `addReading` в массив `$readingData` добавить строку `'photo_path' => $data['photo_path'] ?? null,` после `'note' => ...`;
   - в `readingActionFormData()` в ветке существующего показания добавить `'photo_path' => $meterReading->photo_path,` после `'note' => ...`.

- [ ] **Step 5: Убедиться, что тесты проходят**

Run: `make test test_args="--compact --filter=MeterReadingPhoto"`
Expected: PASS (7 тестов). Если FileUpload в тесте не сохраняет файл через `fillForm`, свериться с Boost `search-docs` (`['testing file upload']`) — возможно, потребуется обёртка значения в массив.

- [ ] **Step 6: Регресс существующих тестов показаний**

Run: `make test test_args="--compact tests/Feature/MeterTest.php"`
Expected: PASS — существующие сценарии (включая `assertTableActionDataSet`) не сломаны.

- [ ] **Step 7: Pint и коммит**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament tests/Feature/MeterReadingPhotoTest.php
git commit -m "Поле «Фото счётчика» добавлено во все формы ввода показаний: ресурс показаний, карточка счётчика, быстрое действие в карточке абонента. На телефоне поле открывает камеру/галерею, фото ужимается до 1920px.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Миниатюры фото в таблицах показаний

**Files:**
- Modify: `app/Filament/Resources/MeterReadings/Tables/MeterReadingsTable.php` (колонки, ~строки 44–83)
- Modify: `app/Filament/Resources/Meters/RelationManagers/ReadingsRelationManager.php` (колонки `table()`, ~строки 85–106)
- Test: `tests/Feature/MeterReadingPhotoTest.php` (дополнить)

**Interfaces:**
- Consumes: `MeterReading::PHOTO_DISK`, атрибут `photo_path` из Task 1.
- Produces: колонка таблицы с именем `photo_path` в обеих таблицах (важно для тестов `assertTableColumnExists('photo_path')`).

- [ ] **Step 1: Написать падающие тесты**

Дополнить `tests/Feature/MeterReadingPhotoTest.php`. К импортам добавить:

```php
use App\Filament\Resources\MeterReadings\Pages\ListMeterReadings;
use App\Filament\Resources\Meters\Pages\EditMeter;
use App\Filament\Resources\Meters\RelationManagers\ReadingsRelationManager;
```

Тест:

```php
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
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `make test test_args="--compact --filter=MeterReadingPhoto"`
Expected: FAIL — `assertTableColumnExists('photo_path')` (колонки нет).

- [ ] **Step 3: Добавить `ImageColumn` в обе таблицы**

В `app/Filament/Resources/MeterReadings/Tables/MeterReadingsTable.php`:

1. Импорты: `use Filament\Tables\Columns\ImageColumn;` и `use Illuminate\Support\Facades\Storage;`.
2. После колонки `read_at` добавить:

```php
                ImageColumn::make('photo_path')
                    ->label('Фото')
                    ->disk(MeterReading::PHOTO_DISK)
                    ->imageHeight(40)
                    ->url(fn (MeterReading $record): ?string => $record->photo_path
                        ? Storage::disk(MeterReading::PHOTO_DISK)->url($record->photo_path)
                        : null)
                    ->openUrlInNewTab()
                    ->toggleable(),
```

В `app/Filament/Resources/Meters/RelationManagers/ReadingsRelationManager.php` — те же импорты, та же колонка после `read_at` в `table()`.

Перед кодированием свериться через Boost `search-docs` (`['image column']`): имя метода высоты (`imageHeight`) и способ задать URL клика в Filament v5; при расхождении использовать документированные методы.

- [ ] **Step 4: Убедиться, что тесты проходят**

Run: `make test test_args="--compact --filter=MeterReadingPhoto"`
Expected: PASS (8 тестов).

- [ ] **Step 5: Pint и коммит**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament tests/Feature/MeterReadingPhotoTest.php
git commit -m "В таблицы показаний (ресурс показаний и карточка счётчика) добавлена колонка-миниатюра фото; клик открывает фото в полный размер.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Документация, design-preview, полный прогон

**Files:**
- Modify: `docs/modules/meters.md` (поля показания ~строки 33–46, правила ~72–120, админ-панель ~150–154)
- Modify: `docs/business-rules.md` (раздел «Показания счётчиков», строки 351–379)
- Modify: `docs/changelog.md` (секция `## 2026-08-09`)
- Modify: `resources/views/design-preview.blade.php` (таблица «Счётчики и показания», строки ~856–874)

**Interfaces:**
- Consumes: терминологию из Task 1–3 (поле `photo_path`, диск `public`, каталог `meter-reading-photos/{organization_id}`).
- Produces: ничего для кода; документация — источник истины бизнес-логики.

- [ ] **Step 1: `docs/modules/meters.md`**

1. В таблицу «Основные поля показания» после строки `| Примечание | Дополнительная информация, поле `note` |` добавить:

```markdown
| Фото | Необязательное фото счётчика, поле `photo_path` (путь к файлу на диске `public`) |
```

2. В раздел «Правила» после абзаца «Удаление показаний доступно только оператору.» (строка ~100) добавить абзацы:

```markdown
К показанию можно прикрепить одно необязательное фото счётчика.

Фото загружается через поле формы показания; в браузере телефона поле предлагает камеру или галерею.

Файлы фото хранятся на диске `public` в каталоге `meter-reading-photos/{organization_id}`.

При загрузке нового фото прежний файл удаляется с диска.

При удалении показания файл фото удаляется с диска.
```

3. В разделе «Административная панель»:
   - абзац «Форма показания внутри счётчика должна позволять указать текущее показание, дату ввода и примечание.» дополнить: `...дату ввода, примечание и фото счётчика.`;
   - абзац «В связанной таблице показаний должны отображаться период, предыдущее показание, текущее показание, расход и дата ввода.» дополнить: `...дата ввода и миниатюра фото; клик по миниатюре открывает фото в полный размер.`

- [ ] **Step 2: `docs/business-rules.md`**

В раздел «Показания счётчиков» после абзаца про быстрое действие «Изменить показание» добавить:

```markdown
К показанию можно прикрепить одно необязательное фото счётчика; поле доступно оператору и контроллеру во всех формах ввода показаний.

При замене фото и при удалении показания файл фото удаляется с диска.
```

- [ ] **Step 3: `docs/changelog.md`**

В существующую секцию `## 2026-08-09` в `### Added` добавить пункт:

```markdown
- К показанию счётчика можно прикрепить одно необязательное фото (поле «Фото счётчика» во всех формах ввода показаний; в браузере телефона открывает камеру/галерею). Фото ужимается до 1920px, хранится на диске `public`, в таблицах показаний отображается миниатюрой; при замене фото и удалении показания файл удаляется.
```

- [ ] **Step 4: design-preview**

В `resources/views/design-preview.blade.php` в таблице «Счётчики и показания» (строки ~856–874):

1. В `<thead>` после `<th ...>Расход</th>` — сделать колонку «Расход» с правой границей (`border-r`) и добавить новую последнюю колонку:

```html
<th class="border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">Фото</th>
```

2. В строке данных после ячейки расхода — аналогично добавить `border-r` к ячейке «Расход» и новую ячейку с плейсхолдером миниатюры:

```html
<td class="px-3 py-2">
    <span class="inline-flex h-8 w-10 items-center justify-center rounded border border-zinc-300 bg-zinc-100 text-[10px] font-medium text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">Фото</span>
</td>
```

- [ ] **Step 5: Полный прогон тестов**

Run: `make test`
Expected: PASS, ноль упавших.

- [ ] **Step 6: Финальный коммит**

```bash
git add docs resources/views/design-preview.blade.php
git commit -m "Документация и design-preview обновлены: фото счётчика в показаниях (meters.md, business-rules.md, changelog, превью таблицы показаний).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```
