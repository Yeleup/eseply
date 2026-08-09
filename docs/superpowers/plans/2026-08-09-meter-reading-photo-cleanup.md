# Очистка фото показаний при каскадном удалении — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Файлы фото показаний удаляются с диска при удалении счётчика и организации (каскад БД обходит события `MeterReading`), а подмена `photo_path` на фото другого счётчика той же организации отклоняется.

**Architecture:** Знание о диске и каталоге фото выносится в `App\Support\MeterReadingPhotoStorage`; модели `MeterReading`, `Meter`, `Organization` вызывают его из хуков. Счётчик собирает пути в `deleting` и удаляет файлы в `deleted`; организация удаляет свой каталог фото целиком в `deleted`. Проверка `allowFilePathUsing` дополняется условием принадлежности пути тому же счётчику, что и форма.

**Tech Stack:** Laravel 13, Filament v5, Livewire v4, Pest 4, MariaDB, диск `public`.

**Спека:** `docs/superpowers/specs/2026-08-09-meter-reading-photo-cleanup-design.md`.

## Global Constraints

- Новые composer/npm-зависимости запрещены.
- Тесты запускаются в docker: полный прогон `make test`, фильтр `make test test_args="--compact --filter=<имя>"`, файл `make test test_args="--compact tests/Feature/<File>.php"`.
- Artisan-команды через `make artisan artisan_args="..."`.
- После правок PHP: `vendor/bin/pint --dirty --format agent` (если PHP на хосте нет — `docker compose exec -T app vendor/bin/pint --dirty --format agent`).
- Перед правками Filament-кода использовать Boost MCP `search-docs` для проверки актуального API (например `['file upload']`); при расхождении с кодом плана приоритет у документации, отклонение фиксируется в отчёте.
- Все пользовательские строки — на русском.
- Схема БД не меняется: каскады `cascadeOnDelete` остаются как есть.
- Отдельная artisan-команда зачистки осиротевших файлов НЕ создаётся (решение спеки).
- Тесты не удалять; коммиты после каждой задачи; сообщения на русском, в конце `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Существующие публичные точки не ломать: `MeterReading::PHOTO_DISK` и `MeterReading::photoDirectoryFor()` используются в `MeterReadingForm`, `MeterReadingsTable`, `ReadingsRelationManager` и тестах — они остаются, но делегируют в новый класс.

---

### Task 1: Класс `MeterReadingPhotoStorage` и делегирование из `MeterReading`

**Files:**
- Create: `app/Support/MeterReadingPhotoStorage.php`
- Modify: `app/Models/MeterReading.php` (константа `PHOTO_DISK` строка ~37, метод `photoDirectoryFor` строки ~154-157, хуки `updated`/`deleted` строки ~205-217)
- Test: `tests/Feature/MeterReadingPhotoTest.php` (дополнить)

**Interfaces:**
- Consumes: существующие `MeterReading::PHOTO_DISK`, `MeterReading::photoDirectoryFor()`, `MeterReading::factory()`.
- Produces: `App\Support\MeterReadingPhotoStorage` со статическими методами:
  - `disk(): string` → `'public'`
  - `directoryFor(int|string $organizationId): string` → `"meter-reading-photos/{$organizationId}"`
  - `delete(?string $path): void`
  - `deleteMany(array $paths): void`
  - `deleteOrganizationDirectory(int|string $organizationId): void`

  Задачи 2–3 вызывают именно их. `MeterReading::PHOTO_DISK` и `MeterReading::photoDirectoryFor()` сохраняются как делегаты.

- [ ] **Step 1: Написать падающий тест**

Добавить в конец `tests/Feature/MeterReadingPhotoTest.php` (импорт `use App\Support\MeterReadingPhotoStorage;` — к остальным импортам файла):

```php
test('the photo storage helper exposes the disk and directory used by readings', function () {
    Storage::fake('public');
    Storage::disk('public')->put('meter-reading-photos/7/one.jpg', 'one');
    Storage::disk('public')->put('meter-reading-photos/7/two.jpg', 'two');
    Storage::disk('public')->put('meter-reading-photos/8/other.jpg', 'other');

    expect(MeterReadingPhotoStorage::disk())->toBe('public')
        ->and(MeterReadingPhotoStorage::directoryFor(7))->toBe('meter-reading-photos/7')
        ->and(MeterReading::PHOTO_DISK)->toBe(MeterReadingPhotoStorage::disk())
        ->and(MeterReading::photoDirectoryFor(7))->toBe(MeterReadingPhotoStorage::directoryFor(7));

    MeterReadingPhotoStorage::delete(null);
    MeterReadingPhotoStorage::delete('');
    Storage::disk('public')->assertExists('meter-reading-photos/7/one.jpg');

    MeterReadingPhotoStorage::deleteMany([
        'meter-reading-photos/7/one.jpg',
        null,
        '',
        'meter-reading-photos/7/two.jpg',
    ]);

    Storage::disk('public')->assertMissing('meter-reading-photos/7/one.jpg');
    Storage::disk('public')->assertMissing('meter-reading-photos/7/two.jpg');
    Storage::disk('public')->assertExists('meter-reading-photos/8/other.jpg');

    MeterReadingPhotoStorage::deleteOrganizationDirectory(8);

    Storage::disk('public')->assertMissing('meter-reading-photos/8/other.jpg');
});
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `make test test_args="--compact --filter=MeterReadingPhoto"`
Expected: FAIL — `Class "App\Support\MeterReadingPhotoStorage" not found`.

- [ ] **Step 3: Создать класс хранения**

Создать `app/Support/MeterReadingPhotoStorage.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class MeterReadingPhotoStorage
{
    private const DISK = 'public';

    private const DIRECTORY = 'meter-reading-photos';

    public static function disk(): string
    {
        return self::DISK;
    }

    public static function directoryFor(int|string $organizationId): string
    {
        return self::DIRECTORY."/{$organizationId}";
    }

    public static function delete(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    /**
     * @param  array<int, string|null>  $paths
     */
    public static function deleteMany(array $paths): void
    {
        $paths = array_values(array_filter($paths, fn (?string $path): bool => filled($path)));

        if ($paths === []) {
            return;
        }

        Storage::disk(self::DISK)->delete($paths);
    }

    public static function deleteOrganizationDirectory(int|string $organizationId): void
    {
        Storage::disk(self::DISK)->deleteDirectory(self::directoryFor($organizationId));
    }
}
```

- [ ] **Step 4: Перевести `MeterReading` на класс хранения**

В `app/Models/MeterReading.php`:

1. Импорт `use App\Support\MeterReadingPhotoStorage;`; импорт `use Illuminate\Support\Facades\Storage;` удалить, если после правок он больше не используется в файле.
2. Константа `PHOTO_DISK` не меняется: она остаётся константой со значением `'public'`, потому что её читают в атрибутах компонентов (`->disk(MeterReading::PHOTO_DISK)`), где выражение должно быть константным. Совпадение её значения с `MeterReadingPhotoStorage::disk()` закреплено тестом из шага 1.
3. `photoDirectoryFor` делегирует:

```php
    public static function photoDirectoryFor(int|string $organizationId): string
    {
        return MeterReadingPhotoStorage::directoryFor($organizationId);
    }
```

4. Хуки `updated`/`deleted` в `booted()`:

```php
        static::updated(function (MeterReading $meterReading): void {
            $previousPhotoPath = $meterReading->getOriginal('photo_path');

            if ($meterReading->wasChanged('photo_path')) {
                MeterReadingPhotoStorage::delete($previousPhotoPath);
            }
        });

        static::deleted(function (MeterReading $meterReading): void {
            MeterReadingPhotoStorage::delete($meterReading->photo_path);
        });
```

- [ ] **Step 5: Убедиться, что тесты проходят**

Run: `make test test_args="--compact --filter=MeterReadingPhoto"`
Expected: PASS (11 тестов — 10 существующих + новый).

- [ ] **Step 6: Pint и коммит**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/MeterReadingPhotoStorage.php app/Models/MeterReading.php tests/Feature/MeterReadingPhotoTest.php
git commit -m "Работа с файлами фото показаний вынесена в App\\Support\\MeterReadingPhotoStorage; модель показания использует его вместо прямых вызовов Storage.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Очистка файлов при удалении счётчика и организации

**Files:**
- Modify: `app/Models/Meter.php` (метод `booted()`, строки ~104-117)
- Modify: `app/Models/Organization.php` (класс не имеет `booted()` — добавить его рядом с `casts()` в конце класса)
- Test: `tests/Feature/MeterReadingPhotoTest.php` (дополнить)

**Interfaces:**
- Consumes: `MeterReadingPhotoStorage::deleteMany()`, `MeterReadingPhotoStorage::deleteOrganizationDirectory()` из Task 1; связь `Meter::readings()` (HasMany, `app/Models/Meter.php:50`).
- Produces: ничего для последующих задач (Task 3 независим).

- [ ] **Step 1: Написать падающие тесты**

Добавить в `tests/Feature/MeterReadingPhotoTest.php`:

```php
test('deleting a meter deletes the photo files of its readings', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();
    $meter = Meter::factory()->for($organization)->create();
    $otherMeter = Meter::factory()->for($organization)->create();

    $firstPhotoPath = "meter-reading-photos/{$organization->id}/first.jpg";
    $secondPhotoPath = "meter-reading-photos/{$organization->id}/second.jpg";
    $keptPhotoPath = "meter-reading-photos/{$organization->id}/kept.jpg";
    Storage::disk('public')->put($firstPhotoPath, 'first');
    Storage::disk('public')->put($secondPhotoPath, 'second');
    Storage::disk('public')->put($keptPhotoPath, 'kept');

    MeterReading::factory()->for($meter)->create([
        'period' => '202604',
        'photo_path' => $firstPhotoPath,
    ]);
    closedBillingPeriodFor($organization, '202604');

    MeterReading::factory()->for($meter)->create([
        'period' => '202605',
        'photo_path' => $secondPhotoPath,
    ]);

    MeterReading::factory()->for($otherMeter)->create([
        'period' => '202605',
        'photo_path' => $keptPhotoPath,
    ]);

    $meter->delete();

    Storage::disk('public')->assertMissing($firstPhotoPath);
    Storage::disk('public')->assertMissing($secondPhotoPath);
    Storage::disk('public')->assertExists($keptPhotoPath);
    expect(MeterReading::query()->whereBelongsTo($meter)->exists())->toBeFalse();
});

test('deleting a meter without photos succeeds', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();
    $meter = Meter::factory()->for($organization)->create();

    MeterReading::factory()->for($meter)->create([
        'period' => '202605',
        'photo_path' => null,
    ]);

    $meter->delete();

    expect(Meter::query()->whereKey($meter->getKey())->exists())->toBeFalse();
});

test('deleting an organization deletes its meter reading photo directory', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();

    $photoPath = "meter-reading-photos/{$organization->id}/photo.jpg";
    $otherPhotoPath = "meter-reading-photos/{$otherOrganization->id}/photo.jpg";
    Storage::disk('public')->put($photoPath, 'photo');
    Storage::disk('public')->put($otherPhotoPath, 'other');

    $organization->delete();

    Storage::disk('public')->assertMissing($photoPath);
    Storage::disk('public')->assertExists($otherPhotoPath);
});
```

Примечание для реализатора: тест удаления организации намеренно не создаёт счётчиков и абонентов. `meters.client_id` объявлен `restrictOnDelete()`, а `clients.organization_id` и `meters.organization_id` — `cascadeOnDelete()`, поэтому удаление организации с абонентами и счётчиками зависит от порядка каскадов в MariaDB и к теме этой задачи отношения не имеет. Проверяется именно то, за что отвечает хук: каталог фото организации удаляется целиком, чужой каталог не трогается. Не расширяй этот тест счётчиками — очистка по счётчику проверена отдельным тестом выше.

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `make test test_args="--compact --filter=MeterReadingPhoto"`
Expected: FAIL — три новых теста: файлы фото остаются на диске после удаления счётчика/организации.

- [ ] **Step 3: Хук очистки в `Meter`**

В `app/Models/Meter.php`:

1. Импорты: `use App\Support\MeterReadingPhotoStorage;`.
2. Добавить приватное свойство под собранные пути (объявить рядом с другими свойствами класса, до методов):

```php
    /**
     * @var array<int, string>
     */
    private array $deletedReadingPhotoPaths = [];
```

3. Добавить пару публичных методов на модели — замыкания в `booted()` не привязаны к экземпляру и не могут писать в приватное свойство напрямую:

```php
    /**
     * @param  array<int, string>  $photoPaths
     */
    public function rememberDeletedReadingPhotoPaths(array $photoPaths): void
    {
        $this->deletedReadingPhotoPaths = $photoPaths;
    }

    public function flushDeletedReadingPhotoPaths(): void
    {
        MeterReadingPhotoStorage::deleteMany($this->deletedReadingPhotoPaths);
        $this->deletedReadingPhotoPaths = [];
    }
```

4. В `booted()` после существующего `static::saving(...)` добавить замыкания:

```php
        static::deleting(function (Meter $meter): void {
            $photoPaths = [];

            $meter->readings()
                ->whereNotNull('photo_path')
                ->select(['id', 'photo_path'])
                ->chunkById(500, function ($readings) use (&$photoPaths): void {
                    foreach ($readings as $reading) {
                        $photoPaths[] = $reading->photo_path;
                    }
                });

            $meter->rememberDeletedReadingPhotoPaths($photoPaths);
        });

        static::deleted(function (Meter $meter): void {
            $meter->flushDeletedReadingPhotoPaths();
        });
```

Octane: свойство живёт на экземпляре модели, а не в статике, — накопления между запросами нет.

- [ ] **Step 4: Хук очистки в `Organization`**

В `app/Models/Organization.php`:

1. Импорт `use App\Support\MeterReadingPhotoStorage;`.
2. Перед методом `casts()` добавить:

```php
    protected static function booted(): void
    {
        static::deleted(function (Organization $organization): void {
            MeterReadingPhotoStorage::deleteOrganizationDirectory($organization->getKey());
        });
    }
```

Если в классе уже есть `booted()`, добавь хук в существующий метод, а не второй такой же.

- [ ] **Step 5: Убедиться, что тесты проходят**

Run: `make test test_args="--compact --filter=MeterReadingPhoto"`
Expected: PASS (14 тестов).

- [ ] **Step 6: Регресс существующих тестов счётчиков**

Run: `make test test_args="--compact tests/Feature/MeterTest.php"`
Expected: PASS — удаление счётчиков в существующих сценариях не сломано.

- [ ] **Step 7: Pint и коммит**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Meter.php app/Models/Organization.php tests/Feature/MeterReadingPhotoTest.php
git commit -m "Файлы фото показаний удаляются с диска при удалении счётчика и организации: каскад БД раньше оставлял их осиротевшими.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Проверка пути фото по счётчику формы

**Files:**
- Modify: `app/Filament/Resources/MeterReadings/Schemas/MeterReadingForm.php` (замыкание `allowFilePathUsing`, строки ~102-119)
- Test: `tests/Feature/MeterReadingPhotoTest.php` (дополнить)

**Interfaces:**
- Consumes: `MeterReading::photoDirectoryFor()` (делегат из Task 1), существующий `MeterReadingForm::photoUpload()`.
- Produces: ничего для последующих задач.

- [ ] **Step 1: Написать падающий тест**

Добавить в `tests/Feature/MeterReadingPhotoTest.php` (импорты `Str`, `CreateMeterReading`, `Meter`, `Organization` уже есть в файле):

```php
test('a photo path belonging to another meter of the same organization is rejected', function () {
    Storage::fake('public');

    $organization = Organization::factory()->create();

    $otherMeter = Meter::factory()->for($organization)->create();
    $otherPhotoPath = "meter-reading-photos/{$organization->id}/other-meter.jpg";
    Storage::disk('public')->put($otherPhotoPath, 'other');

    $otherReading = MeterReading::factory()->for($otherMeter)->create([
        'period' => '202604',
        'photo_path' => $otherPhotoPath,
    ]);
    closedBillingPeriodFor($organization, '202604');

    $meter = Meter::factory()->for($organization)->create([
        'initial_reading' => 100,
    ]);
    billingPeriodFor($organization);

    actingAsReadingPhotoTenant($organization);

    Livewire::test(CreateMeterReading::class)
        ->fillForm([
            'meter_id' => $meter->id,
            'current_reading' => 137.125,
            'photo_path' => [(string) Str::uuid() => $otherPhotoPath],
        ])
        ->call('create')
        ->assertHasFormErrors(['photo_path']);

    expect(MeterReading::query()->whereBelongsTo($meter)->exists())->toBeFalse();
    Storage::disk('public')->assertExists($otherPhotoPath);
    expect($otherReading->refresh()->photo_path)->toBe($otherPhotoPath);
});
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `make test test_args="--compact --filter=MeterReadingPhoto"`
Expected: FAIL — форма принимает путь чужого счётчика (нет ошибки `photo_path`), показание создаётся.

- [ ] **Step 3: Проверить доступный контекст замыкания**

Через Boost MCP `search-docs` (`['file upload']`) и в vendored-коде `vendor/filament/forms/src/Components/BaseFileUpload.php` (метод `isFilePathAuthorized`, вызов `evaluate($this->allowFilePathUsing, ...)`) выясни, какие параметры инжектятся в замыкание помимо `file`: доступны ли `$get`, `$record`, `$livewire`, `$component`.

Если инжектится только `file`, получить контекст можно через `$component`/`$livewire`, если они доступны; если недоступно ничего, кроме `file`, — НЕ ослабляй проверку: остановись и сообщи BLOCKED с описанием того, что именно доступно.

- [ ] **Step 4: Реализовать проверку по счётчику**

В `app/Filament/Resources/MeterReadings/Schemas/MeterReadingForm.php` заменить тело `allowFilePathUsing` на проверку из четырёх условий. Ориентировочная реализация (уточни имена инжектируемых параметров по результатам шага 3):

```php
            ->preventFilePathTampering(allowFilePathUsing: function (string $file, Get $get, ?Model $record, Component $component): bool {
                $tenant = Filament::getTenant();

                if (! $tenant instanceof Organization) {
                    return false;
                }

                if (! str_starts_with($file, MeterReading::photoDirectoryFor($tenant->getKey()).'/')) {
                    return false;
                }

                $meterId = self::meterIdForPhotoValidation($get, $record, $component);

                if ($meterId === null) {
                    return false;
                }

                return MeterReading::query()
                    ->where('organization_id', $tenant->getKey())
                    ->where('meter_id', $meterId)
                    ->where('photo_path', $file)
                    ->exists();
            })
```

и добавить приватный помощник в тот же класс:

```php
    private static function meterIdForPhotoValidation(Get $get, ?Model $record, Component $component): ?int
    {
        $meterId = $get('meter_id');

        if (filled($meterId)) {
            return (int) $meterId;
        }

        if ($record instanceof MeterReading) {
            return (int) $record->meter_id;
        }

        if ($record instanceof Meter) {
            return (int) $record->getKey();
        }

        $livewire = $component->getLivewire();

        if ($livewire instanceof RelationManager) {
            $ownerRecord = $livewire->getOwnerRecord();

            if ($ownerRecord instanceof Meter) {
                return (int) $ownerRecord->getKey();
            }
        }

        return null;
    }
```

Импорты, которые понадобятся: `use App\Models\Meter;`, `use Filament\Resources\RelationManagers\RelationManager;`, `use Filament\Schemas\Components\Component;` (проверь точное пространство имён компонента в vendored-коде), `use Illuminate\Database\Eloquent\Model;`. `Get` уже импортирован в файле.

Важно: в быстром действии «Добавить/Изменить показание» карточки абонента record схемы — это `Meter`, там срабатывает ветка `$record instanceof Meter`. В модалке показаний карточки счётчика при создании record отсутствует — там срабатывает ветка ownerRecord relation-менеджера.

- [ ] **Step 5: Убедиться, что тесты проходят**

Run: `make test test_args="--compact --filter=MeterReadingPhoto"`
Expected: PASS (15 тестов). Ключевые для этой задачи существующие тесты, которые обязаны остаться зелёными: «the client card reading action keeps an existing photo path when resubmitted unchanged» (легитимный префилл того же счётчика) и «creating a reading with a tampered foreign photo path is rejected» (чужая организация).

- [ ] **Step 6: Pint и коммит**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Resources/MeterReadings/Schemas/MeterReadingForm.php tests/Feature/MeterReadingPhotoTest.php
git commit -m "Путь фото в форме показания принимается, только если он принадлежит показанию того же счётчика: подмена на фото другого счётчика своей организации отклоняется.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Документация и полный прогон

**Files:**
- Modify: `docs/modules/meters.md` (раздел «Правила», абзацы про фото — после «При удалении показания файл фото удаляется с диска.»)
- Modify: `docs/modules/organizations.md` (раздел «Правила доступа» / общие правила организации)
- Modify: `docs/changelog.md` (секция `## 2026-08-09`)
- Test: полный прогон `make test`

**Interfaces:**
- Consumes: терминологию задач 1–3 (`photo_path`, диск `public`, каталог `meter-reading-photos/{organization_id}`).
- Produces: ничего для кода.

- [ ] **Step 1: `docs/modules/meters.md`**

После абзаца «При удалении показания файл фото удаляется с диска.» добавить:

```markdown
При удалении счётчика файлы фото всех его показаний удаляются с диска.

При удалении организации каталог фото показаний этой организации удаляется целиком.

В форме показания принимается только путь фото, принадлежащий показанию того же счётчика; путь фото другого счётчика отклоняется.
```

- [ ] **Step 2: `docs/modules/organizations.md`**

В раздел с правилами организации (перед разделом «Профиль организации») добавить абзац:

```markdown
При удалении организации вместе с её данными удаляется каталог фото показаний счётчиков `meter-reading-photos/{organization_id}` на диске `public`.
```

- [ ] **Step 3: `docs/changelog.md`**

В секцию `## 2026-08-09` добавить блок `### Fixed` (если его ещё нет) с пунктами:

```markdown
### Fixed

- Файлы фото показаний больше не остаются на диске при удалении счётчика или организации: раньше показания удалялись каскадом на уровне БД, минуя очистку файлов.
- В форме показания путь фото принимается, только если он принадлежит показанию того же счётчика; раньше участник организации мог подставить путь фото другого счётчика своей организации, и два показания ссылались на один файл.
```

- [ ] **Step 4: Полный прогон тестов**

Run: `make test`
Expected: PASS, ноль упавших. Записать точные числа в отчёт.

- [ ] **Step 5: Коммит**

```bash
git add docs
git commit -m "Документация обновлена: очистка фото показаний при удалении счётчика и организации, правило проверки пути фото по счётчику.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```
