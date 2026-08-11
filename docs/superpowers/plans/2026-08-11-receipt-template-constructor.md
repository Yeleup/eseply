# Конструктор шаблона квитанции — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Организация настраивает собственный вид печатной квитанции (блоки, тексты, логотип/QR, внешний вид) на странице-конструкторе в панели Filament с живым предпросмотром; печать применяет шаблон, без записи в БД всё выглядит как сейчас.

**Architecture:** JSON-конфиг в таблице `receipt_templates` (одна запись на организацию) сливается с дефолтами из кода классом `ReceiptTemplateConfig`. Печатный партиал `print-copy` разбивается на блоки-партиалы и рендерит их по конфигу. Filament-страница `ReceiptTemplatePage` (tenant-scoped) редактирует конфиг с живым Livewire-предпросмотром через тот же партиал.

**Tech Stack:** Laravel 13, Filament v5 (Schemas/Forms), Livewire 4, Pest 4, Tailwind 4. Новых зависимостей нет.

**Спека:** `docs/superpowers/specs/2026-08-11-receipt-template-constructor-design.md` — прочитай перед началом.

## Global Constraints

- Все тексты интерфейса, документации и коммитов — на русском языке.
- Тесты запускаются только через `make test` (Docker): `make test test_args="--compact --filter=ИмяТеста"`; полный прогон — `make test`.
- После изменения PHP-файлов запускай `vendor/bin/pint --dirty --format agent` перед коммитом.
- Никакого пользовательского HTML в шаблоне: все тексты выводятся через `{{ }}` (экранирование).
- Не менять зависимости приложения. Не создавать новых корневых директорий.
- Filament v5: формы описываются `Schema`, поля — `Filament\Forms\Components\*`, layout — `Filament\Schemas\Components\*`.
- В моделях fillable задаётся атрибутом `#[Fillable([...])]` (см. `app/Models/Receipt.php`).
- Тема панели `resources/css/filament/admin/theme.css` сканирует только `app/Filament` и `resources/views/filament` — при использовании вьюх из `resources/views/receipts` внутри панели их надо добавить в `@source`.
- Коммиты завершай футером `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

---

### Task 1: Документация модуля

По правилам проекта модульная документация создаётся до реализации.

**Files:**
- Create: `docs/modules/receipt-template.md`

**Interfaces:**
- Produces: договорённости о структуре `settings`, списке блоков и правилах слияния, на которые опираются все последующие задачи.

- [ ] **Step 1: Создать `docs/modules/receipt-template.md`**

````markdown
# Модуль: Шаблон квитанции

## Терминология

Шаблон квитанции в коде называется `ReceiptTemplate`.

Таблица шаблонов называется `receipt_templates`.

## Назначение

Модуль позволяет организации настроить собственный вид печатной квитанции: состав и порядок блоков, тексты и подписи полей, логотип, QR-код и внешний вид. Настройка выполняется на странице «Шаблон квитанции» в панели организации с живым предпросмотром.

У организации не более одной записи шаблона. Если записи нет, печать использует стандартный шаблон, зашитый в код (`App\Support\ReceiptTemplateDefaults`).

## Основные поля

| Поле | Описание |
|---|---|
| Организация | Tenant-организация, поле `organization_id`, уникально |
| Настройки | JSON-конфиг шаблона, поле `settings` |
| Логотип | Путь к файлу логотипа на диске `public`, поле `logo_path` |
| QR-код | Путь к файлу QR-кода на диске `public`, поле `qr_path` |

## Структура настроек

```json
{
    "blocks": [
        {"type": "header", "enabled": true},
        {"type": "client_details", "enabled": true},
        {"type": "organization_details", "enabled": true},
        {"type": "meters_table", "enabled": true},
        {"type": "totals", "enabled": true},
        {"type": "footer_note", "enabled": false}
    ],
    "texts": {
        "title": "Квитанция на оплату коммунальной услуги",
        "footer_note": "",
        "labels": {}
    },
    "appearance": {
        "copies_per_page": 2,
        "font_size": "normal",
        "density": "normal",
        "borders": true,
        "show_logo": true,
        "show_qr": false
    }
}
```

## Блоки

Порядок элементов `blocks` определяет порядок блоков на квитанции.

| Тип | Название | Содержимое |
|---|---|---|
| `header` | Шапка | Название и адрес организации, номер, период и дата квитанции, заголовок из `texts.title`, логотип при `show_logo`. Нельзя выключить |
| `client_details` | Абонент | Лицевой счёт, абонент, адрес, период, услуга, тип расчёта |
| `organization_details` | Реквизиты | Организация, БИН/ИИН, телефон, адрес, банк, IBAN |
| `meters_table` | Счётчики | Таблица показаний со строкой «Итого» (объём и сумма) |
| `totals` | Итоги | Строки «Долг», «Оплачено», «К оплате» |
| `footer_note` | Примечание | Произвольный текст из `texts.footer_note`, QR-код при `show_qr` |

## Тексты

`texts.title` — заголовок квитанции в шапке. `texts.footer_note` — текст блока «Примечание». `texts.labels` — переопределения подписей полей по ключам: `account_number`, `client_name`, `client_address`, `period`, `service`, `billing_type`, `organization`, `bin_iin`, `phone`, `organization_address`, `bank`, `iban`. Отсутствующий ключ означает стандартную подпись. Все тексты выводятся экранированными, HTML недоступен.

## Внешний вид

`font_size` и `density` принимают `compact`, `normal`, `large`. `copies_per_page` принимает `1` (только экземпляр «Для абонента») или `2` («Для организации» и «Для абонента»). `borders: false` убирает рамки. `show_logo` и `show_qr` включают логотип и QR-код, если соответствующий файл загружен.

## Правила слияния

Сохранённые настройки всегда сливаются с дефолтами классом `App\Support\ReceiptTemplateConfig`:

- неизвестные ключи и неизвестные типы блоков отбрасываются;
- отсутствующие ключи берутся из дефолтов;
- блоки, появившиеся в коде после сохранения шаблона, добавляются в конец списка со значением `enabled` из дефолтов;
- значения вне допустимых заменяются дефолтными;
- блок `header` всегда включён.

## Права

Страница «Шаблон квитанции» и сохранение доступны только членам организации с правом управления (`canManageOrganization`). Контроллеры страницу не видят. Запись шаблона всегда ищется и создаётся по текущему tenant.

## Печать

`App\Actions\BuildReceiptPrintViewData` возвращает ключ `template` (`ReceiptTemplateConfig`), одиночная и массовая печать рендерят включённые блоки конфига по порядку. Файлы логотипа и QR хранятся в `receipt-templates/{organization_id}` на диске `public` и удаляются при сбросе шаблона, замене файла и удалении организации.
````

- [ ] **Step 2: Commit**

```bash
git add docs/modules/receipt-template.md
git commit -m "Документация модуля «Шаблон квитанции»

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Дефолты и конфиг шаблона

**Files:**
- Create: `app/Support/ReceiptTemplateDefaults.php`
- Create: `app/Support/ReceiptTemplateConfig.php`
- Test: `tests/Unit/ReceiptTemplateConfigTest.php`

**Interfaces:**
- Produces:
  - `ReceiptTemplateDefaults::settings(): array` — канонический дефолтный конфиг (структура из Task 1);
  - `ReceiptTemplateDefaults::BLOCK_TYPES: list<string>`;
  - `ReceiptTemplateDefaults::blockLabel(string $type): string`;
  - `ReceiptTemplateDefaults::labels(): array<string, string>` — дефолтные подписи полей;
  - `ReceiptTemplateConfig::default(): self`;
  - `ReceiptTemplateConfig::fromSettings(array $settings, ?string $logoUrl = null, ?string $qrUrl = null): self` — слияние с дефолтами и санитизация;
  - методы конфига: `settings(): array`, `enabledBlockTypes(): list<string>`, `title(): string`, `footerNote(): string`, `label(string $key): string`, `copiesPerPage(): int`, `copyTitles(): list<string>`, `copyCssClasses(): string`, `showLogo(): bool`, `showQr(): bool`, `logoUrl(): ?string`, `qrUrl(): ?string`.

- [ ] **Step 1: Написать падающий тест `tests/Unit/ReceiptTemplateConfigTest.php`**

```php
<?php

use App\Support\ReceiptTemplateConfig;
use App\Support\ReceiptTemplateDefaults;

test('default config renders all blocks except footer note', function () {
    $config = ReceiptTemplateConfig::default();

    expect($config->enabledBlockTypes())->toBe([
        'header',
        'client_details',
        'organization_details',
        'meters_table',
        'totals',
    ])
        ->and($config->title())->toBe('Квитанция на оплату коммунальной услуги')
        ->and($config->footerNote())->toBe('')
        ->and($config->label('account_number'))->toBe('Лицевой счёт')
        ->and($config->copiesPerPage())->toBe(2)
        ->and($config->copyTitles())->toBe(['Для организации', 'Для абонента'])
        ->and($config->copyCssClasses())->toBe('')
        ->and($config->showLogo())->toBeTrue()
        ->and($config->showQr())->toBeFalse()
        ->and($config->logoUrl())->toBeNull()
        ->and($config->settings())->toBe(ReceiptTemplateDefaults::settings());
});

test('saved settings control block order and visibility', function () {
    $config = ReceiptTemplateConfig::fromSettings([
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
            'footer_note' => 'Оплатите до 25 числа',
            'labels' => ['account_number' => 'Абонентский номер'],
        ],
        'appearance' => [
            'copies_per_page' => 1,
            'font_size' => 'large',
            'density' => 'compact',
            'borders' => false,
            'show_logo' => false,
            'show_qr' => true,
        ],
    ], logoUrl: '/storage/receipt-templates/1/logo.png', qrUrl: '/storage/receipt-templates/1/qr.png');

    expect($config->enabledBlockTypes())->toBe([
        'header',
        'organization_details',
        'client_details',
        'totals',
        'footer_note',
    ])
        ->and($config->title())->toBe('Счёт за воду')
        ->and($config->footerNote())->toBe('Оплатите до 25 числа')
        ->and($config->label('account_number'))->toBe('Абонентский номер')
        ->and($config->label('client_name'))->toBe('Абонент')
        ->and($config->copiesPerPage())->toBe(1)
        ->and($config->copyTitles())->toBe(['Для абонента'])
        ->and($config->copyCssClasses())->toBe('receipt-font-large receipt-density-compact receipt-no-borders')
        ->and($config->showLogo())->toBeFalse()
        ->and($config->showQr())->toBeTrue()
        ->and($config->qrUrl())->toBe('/storage/receipt-templates/1/qr.png');
});

test('settings merge drops unknown values and fills missing keys from defaults', function () {
    $config = ReceiptTemplateConfig::fromSettings([
        'blocks' => [
            ['type' => 'header', 'enabled' => false],
            ['type' => 'banner', 'enabled' => true],
            ['type' => 'meters_table', 'enabled' => true],
            ['type' => 'meters_table', 'enabled' => false],
        ],
        'texts' => [
            'title' => '',
            'labels' => ['account_number' => '', 'unknown_key' => 'X'],
        ],
        'appearance' => [
            'copies_per_page' => 5,
            'font_size' => 'huge',
        ],
        'unknown_section' => ['x' => 1],
    ]);

    $settings = $config->settings();

    // header принудительно включён, banner отброшен, дубль meters_table отброшен,
    // отсутствующие блоки добавлены в конец с дефолтным enabled
    expect(array_column($settings['blocks'], 'type'))->toBe([
        'header',
        'meters_table',
        'client_details',
        'organization_details',
        'totals',
        'footer_note',
    ])
        ->and($settings['blocks'][0]['enabled'])->toBeTrue()
        ->and($settings['blocks'][5]['enabled'])->toBeFalse()
        ->and($config->title())->toBe('Квитанция на оплату коммунальной услуги')
        ->and($config->label('account_number'))->toBe('Лицевой счёт')
        ->and($settings['texts']['labels'])->toBe([])
        ->and($config->copiesPerPage())->toBe(2)
        ->and($settings['appearance']['font_size'])->toBe('normal')
        ->and($settings)->not->toHaveKey('unknown_section');
});
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `make test test_args="--compact --filter=ReceiptTemplateConfigTest"`
Expected: FAIL — `Class "App\Support\ReceiptTemplateDefaults" not found`.

- [ ] **Step 3: Создать `app/Support/ReceiptTemplateDefaults.php`**

```php
<?php

namespace App\Support;

final class ReceiptTemplateDefaults
{
    public const BLOCK_TYPES = [
        'header',
        'client_details',
        'organization_details',
        'meters_table',
        'totals',
        'footer_note',
    ];

    public const FONT_SIZES = ['compact', 'normal', 'large'];

    public const DENSITIES = ['compact', 'normal', 'large'];

    public const COPIES_PER_PAGE = [1, 2];

    /**
     * @return array{
     *     blocks: list<array{type: string, enabled: bool}>,
     *     texts: array{title: string, footer_note: string, labels: array<string, string>},
     *     appearance: array{copies_per_page: int, font_size: string, density: string, borders: bool, show_logo: bool, show_qr: bool}
     * }
     */
    public static function settings(): array
    {
        return [
            'blocks' => [
                ['type' => 'header', 'enabled' => true],
                ['type' => 'client_details', 'enabled' => true],
                ['type' => 'organization_details', 'enabled' => true],
                ['type' => 'meters_table', 'enabled' => true],
                ['type' => 'totals', 'enabled' => true],
                ['type' => 'footer_note', 'enabled' => false],
            ],
            'texts' => [
                'title' => 'Квитанция на оплату коммунальной услуги',
                'footer_note' => '',
                'labels' => [],
            ],
            'appearance' => [
                'copies_per_page' => 2,
                'font_size' => 'normal',
                'density' => 'normal',
                'borders' => true,
                'show_logo' => true,
                'show_qr' => false,
            ],
        ];
    }

    public static function blockLabel(string $type): string
    {
        return match ($type) {
            'header' => 'Шапка',
            'client_details' => 'Абонент',
            'organization_details' => 'Реквизиты',
            'meters_table' => 'Счётчики',
            'totals' => 'Итоги',
            'footer_note' => 'Примечание',
            default => $type,
        };
    }

    /**
     * Дефолтные подписи полей, которые организация может переопределить.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'account_number' => 'Лицевой счёт',
            'client_name' => 'Абонент',
            'client_address' => 'Адрес',
            'period' => 'Период',
            'service' => 'Услуга',
            'billing_type' => 'Тип расчёта',
            'organization' => 'Организация',
            'bin_iin' => 'БИН / ИИН',
            'phone' => 'Телефон',
            'organization_address' => 'Адрес',
            'bank' => 'Банк',
            'iban' => 'IBAN',
        ];
    }
}
```

- [ ] **Step 4: Создать `app/Support/ReceiptTemplateConfig.php`**

```php
<?php

namespace App\Support;

/**
 * Настройки шаблона квитанции, слитые с дефолтами и очищенные от
 * недопустимых значений. Единственный способ создать объект — фабрики,
 * поэтому потребители всегда получают валидный конфиг.
 */
final class ReceiptTemplateConfig
{
    /**
     * @param  array{
     *     blocks: list<array{type: string, enabled: bool}>,
     *     texts: array{title: string, footer_note: string, labels: array<string, string>},
     *     appearance: array{copies_per_page: int, font_size: string, density: string, borders: bool, show_logo: bool, show_qr: bool}
     * }  $settings
     */
    private function __construct(
        private readonly array $settings,
        private readonly ?string $logoUrl,
        private readonly ?string $qrUrl,
    ) {}

    public static function default(): self
    {
        return new self(ReceiptTemplateDefaults::settings(), null, null);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromSettings(array $settings, ?string $logoUrl = null, ?string $qrUrl = null): self
    {
        $defaults = ReceiptTemplateDefaults::settings();

        return new self([
            'blocks' => self::mergeBlocks($settings['blocks'] ?? null, $defaults['blocks']),
            'texts' => self::mergeTexts($settings['texts'] ?? null, $defaults['texts']),
            'appearance' => self::mergeAppearance($settings['appearance'] ?? null, $defaults['appearance']),
        ], $logoUrl, $qrUrl);
    }

    /**
     * @return array{
     *     blocks: list<array{type: string, enabled: bool}>,
     *     texts: array{title: string, footer_note: string, labels: array<string, string>},
     *     appearance: array{copies_per_page: int, font_size: string, density: string, borders: bool, show_logo: bool, show_qr: bool}
     * }
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * @return list<string>
     */
    public function enabledBlockTypes(): array
    {
        return array_values(array_column(
            array_filter($this->settings['blocks'], fn (array $block): bool => $block['enabled']),
            'type',
        ));
    }

    public function title(): string
    {
        return $this->settings['texts']['title'];
    }

    public function footerNote(): string
    {
        return $this->settings['texts']['footer_note'];
    }

    public function label(string $key): string
    {
        return $this->settings['texts']['labels'][$key]
            ?? ReceiptTemplateDefaults::labels()[$key]
            ?? $key;
    }

    public function copiesPerPage(): int
    {
        return $this->settings['appearance']['copies_per_page'];
    }

    /**
     * @return list<string>
     */
    public function copyTitles(): array
    {
        return $this->copiesPerPage() === 1
            ? ['Для абонента']
            : ['Для организации', 'Для абонента'];
    }

    public function copyCssClasses(): string
    {
        $appearance = $this->settings['appearance'];
        $classes = [];

        if ($appearance['font_size'] !== 'normal') {
            $classes[] = "receipt-font-{$appearance['font_size']}";
        }

        if ($appearance['density'] !== 'normal') {
            $classes[] = "receipt-density-{$appearance['density']}";
        }

        if (! $appearance['borders']) {
            $classes[] = 'receipt-no-borders';
        }

        return implode(' ', $classes);
    }

    public function showLogo(): bool
    {
        return $this->settings['appearance']['show_logo'];
    }

    public function showQr(): bool
    {
        return $this->settings['appearance']['show_qr'];
    }

    public function logoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function qrUrl(): ?string
    {
        return $this->qrUrl;
    }

    /**
     * @param  list<array{type: string, enabled: bool}>  $defaults
     * @return list<array{type: string, enabled: bool}>
     */
    private static function mergeBlocks(mixed $blocks, array $defaults): array
    {
        $defaultEnabled = array_column($defaults, 'enabled', 'type');
        $merged = [];

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;

            if (! is_string($type) || ! array_key_exists($type, $defaultEnabled) || array_key_exists($type, $merged)) {
                continue;
            }

            $merged[$type] = [
                'type' => $type,
                'enabled' => $type === 'header' || (bool) ($block['enabled'] ?? false),
            ];
        }

        foreach ($defaults as $default) {
            if (! array_key_exists($default['type'], $merged)) {
                $merged[$default['type']] = $default;
            }
        }

        return array_values($merged);
    }

    /**
     * @param  array{title: string, footer_note: string, labels: array<string, string>}  $defaults
     * @return array{title: string, footer_note: string, labels: array<string, string>}
     */
    private static function mergeTexts(mixed $texts, array $defaults): array
    {
        $texts = is_array($texts) ? $texts : [];
        $labels = [];

        foreach (is_array($texts['labels'] ?? null) ? $texts['labels'] : [] as $key => $value) {
            if (array_key_exists($key, ReceiptTemplateDefaults::labels()) && is_string($value) && trim($value) !== '') {
                $labels[$key] = trim($value);
            }
        }

        $title = $texts['title'] ?? null;
        $footerNote = $texts['footer_note'] ?? null;

        return [
            'title' => is_string($title) && trim($title) !== '' ? trim($title) : $defaults['title'],
            'footer_note' => is_string($footerNote) ? trim($footerNote) : $defaults['footer_note'],
            'labels' => $labels,
        ];
    }

    /**
     * @param  array{copies_per_page: int, font_size: string, density: string, borders: bool, show_logo: bool, show_qr: bool}  $defaults
     * @return array{copies_per_page: int, font_size: string, density: string, borders: bool, show_logo: bool, show_qr: bool}
     */
    private static function mergeAppearance(mixed $appearance, array $defaults): array
    {
        $appearance = is_array($appearance) ? $appearance : [];

        $copiesPerPage = $appearance['copies_per_page'] ?? null;
        $fontSize = $appearance['font_size'] ?? null;
        $density = $appearance['density'] ?? null;

        return [
            'copies_per_page' => in_array($copiesPerPage, ReceiptTemplateDefaults::COPIES_PER_PAGE, true)
                ? $copiesPerPage
                : $defaults['copies_per_page'],
            'font_size' => in_array($fontSize, ReceiptTemplateDefaults::FONT_SIZES, true)
                ? $fontSize
                : $defaults['font_size'],
            'density' => in_array($density, ReceiptTemplateDefaults::DENSITIES, true)
                ? $density
                : $defaults['density'],
            'borders' => (bool) ($appearance['borders'] ?? $defaults['borders']),
            'show_logo' => (bool) ($appearance['show_logo'] ?? $defaults['show_logo']),
            'show_qr' => (bool) ($appearance['show_qr'] ?? $defaults['show_qr']),
        ];
    }
}
```

- [ ] **Step 5: Убедиться, что тест проходит**

Run: `make test test_args="--compact --filter=ReceiptTemplateConfigTest"`
Expected: PASS (3 теста).

Примечание: в третьем тесте `copies_per_page => 5` передан как int, `'huge'` — недопустимый размер; проверь, что оба заменились дефолтами. Если падает на строгом `in_array(..., true)` из-за строкового `'5'` — это правильно: строки тоже должны отбрасываться.

- [ ] **Step 6: Pint и commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/ReceiptTemplateDefaults.php app/Support/ReceiptTemplateConfig.php tests/Unit/ReceiptTemplateConfigTest.php
git commit -m "Дефолты и конфиг шаблона квитанции со слиянием настроек

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Таблица, модель, фабрика и хранилище файлов

**Files:**
- Create: `database/migrations/2026_08_11_000000_create_receipt_templates_table.php` (реальную дату/время сгенерирует artisan)
- Create: `app/Models/ReceiptTemplate.php`
- Create: `database/factories/ReceiptTemplateFactory.php`
- Create: `app/Support/ReceiptTemplateImageStorage.php`
- Modify: `app/Models/Organization.php` (связь `receiptTemplate`, удаление файлов при удалении организации)
- Test: `tests/Feature/ReceiptTemplateTest.php` (новый файл)

**Interfaces:**
- Consumes: `ReceiptTemplateDefaults::settings()` из Task 2.
- Produces:
  - модель `App\Models\ReceiptTemplate` (`organization_id`, `settings` cast array, `logo_path`, `qr_path`), связь `organization()`;
  - `Organization::receiptTemplate(): HasOne`;
  - `ReceiptTemplateImageStorage::disk(): string`, `directoryFor(int|string $organizationId): string`, `delete(?string $path): void`, `deleteOrganizationDirectory(int|string $organizationId): void`;
  - событие `deleted` модели удаляет `logo_path` и `qr_path` с диска.

- [ ] **Step 1: Сгенерировать миграцию, модель и фабрику**

```bash
php artisan make:model ReceiptTemplate --migration --factory --no-interaction
```

Если локальный PHP недоступен, создай файлы вручную с теми же путями (имя миграции — текущая дата/время).

- [ ] **Step 2: Написать падающие тесты в `tests/Feature/ReceiptTemplateTest.php`**

```php
<?php

use App\Models\Organization;
use App\Models\ReceiptTemplate;
use App\Support\ReceiptTemplateDefaults;
use App\Support\ReceiptTemplateImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

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
        ->toThrow(Illuminate\Database\QueryException::class);

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
```

- [ ] **Step 3: Убедиться, что тесты падают**

Run: `make test test_args="--compact --filter=ReceiptTemplateTest"`
Expected: FAIL (нет таблицы/фабрики/хранилища).

- [ ] **Step 4: Заполнить миграцию**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('settings');
            $table->string('logo_path')->nullable();
            $table->string('qr_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_templates');
    }
};
```

- [ ] **Step 5: Создать `app/Support/ReceiptTemplateImageStorage.php`** (по образцу `MeterReadingPhotoStorage`)

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class ReceiptTemplateImageStorage
{
    private const DISK = 'public';

    private const DIRECTORY = 'receipt-templates';

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

    public static function url(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->url($path);
    }

    public static function deleteOrganizationDirectory(int|string $organizationId): void
    {
        Storage::disk(self::DISK)->deleteDirectory(self::directoryFor($organizationId));
    }
}
```

- [ ] **Step 6: Заполнить модель `app/Models/ReceiptTemplate.php`**

```php
<?php

namespace App\Models;

use App\Support\ReceiptTemplateImageStorage;
use Database\Factories\ReceiptTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'settings',
    'logo_path',
    'qr_path',
])]
class ReceiptTemplate extends Model
{
    /** @use HasFactory<ReceiptTemplateFactory> */
    use HasFactory;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (ReceiptTemplate $template): void {
            ReceiptTemplateImageStorage::delete($template->logo_path);
            ReceiptTemplateImageStorage::delete($template->qr_path);
        });
    }
}
```

- [ ] **Step 7: Заполнить фабрику `database/factories/ReceiptTemplateFactory.php`**

```php
<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\ReceiptTemplate;
use App\Support\ReceiptTemplateDefaults;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceiptTemplate>
 */
class ReceiptTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'settings' => ReceiptTemplateDefaults::settings(),
            'logo_path' => null,
            'qr_path' => null,
        ];
    }
}
```

- [ ] **Step 8: Дополнить `app/Models/Organization.php`**

Добавить импорт `App\Support\ReceiptTemplateImageStorage` уже есть похожий блок — добавить связь рядом с `receipts()`:

```php
public function receiptTemplate(): HasOne
{
    return $this->hasOne(ReceiptTemplate::class);
}
```

И в `booted()` в существующий колбэк `static::deleted(...)` добавить строку (удаление каталога файлов шаблона; сама запись удаляется каскадом БД, поэтому её событие `deleted` не сработает):

```php
static::deleted(function (Organization $organization): void {
    MeterReadingPhotoStorage::deleteOrganizationDirectory($organization->getKey());
    ReceiptTemplateImageStorage::deleteOrganizationDirectory($organization->getKey());
});
```

- [ ] **Step 9: Убедиться, что тесты проходят**

Run: `make test test_args="--compact --filter=ReceiptTemplateTest"`
Expected: PASS (4 теста).

- [ ] **Step 10: Pint и commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "Таблица, модель и хранилище файлов шаблона квитанции

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Разбиение печати на блоки и применение конфига

**Files:**
- Modify: `app/Actions/BuildReceiptPrintViewData.php`
- Modify: `resources/views/receipts/partials/print-copy.blade.php`
- Create: `resources/views/receipts/blocks/header.blade.php`
- Create: `resources/views/receipts/blocks/client-details.blade.php`
- Create: `resources/views/receipts/blocks/organization-details.blade.php`
- Create: `resources/views/receipts/blocks/meters-table.blade.php`
- Create: `resources/views/receipts/blocks/totals.blade.php`
- Create: `resources/views/receipts/blocks/footer-note.blade.php`
- Modify: `resources/views/receipts/print.blade.php`
- Modify: `resources/views/receipts/bulk-print.blade.php`
- Modify: `tests/Feature/ReceiptTest.php` (одна строка — ключ `template`)
- Test: `tests/Feature/ReceiptTemplateTest.php` (дополнить)

**Interfaces:**
- Consumes: `ReceiptTemplateConfig` (Task 2), `ReceiptTemplate`/`ReceiptTemplateImageStorage` (Task 3).
- Produces:
  - `BuildReceiptPrintViewData::handle(Receipt $receipt, ?ReceiptTemplateConfig $template = null): array` — в массиве появляется ключ `template` (`ReceiptTemplateConfig`); связи грузятся через `loadMissing` (нужно Task 7 для предпросмотра на несохранённой модели);
  - все блоки-партиалы получают переменные из области видимости `print-copy` (`$template`, `$receipt`, `$clientDetails`, `$organizationDetails`, `$meterReadingLines`, `$calculationDetails`, `$balanceDetails`, `$paymentDue`, `$generatedAt`, `$copyTitle`).

- [ ] **Step 1: Дополнить `tests/Feature/ReceiptTemplateTest.php` падающими тестами печати**

Вверху файла добавить импорты `App\Models\Receipt`, `App\Models\User`, `Filament\Facades\Filament`, `Livewire\Livewire` и хелперы (по образцу `ReceiptTest.php` — функции с другими именами, чтобы не конфликтовать в одном процессе Pest):

```php
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
```

Для создания квитанции с показаниями используй существующий хелпер `createReceiptFromMeterReading()` — он объявлен в `tests/Feature/ReceiptTest.php`, а функции Pest глобальны в рамках прогона. Если фильтрованный прогон падает с «undefined function», перенеси квитанцию на фабрику: `Receipt::factory()->for($organization)->create([...])` — для этих тестов достаточно квитанции без показаний. Тесты:

```php
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
```

Замечание к `Receipt::factory()`: `billing_period_id` разрешается из `period` в `booted()` модели, поэтому в bulk-тесте расчётный месяц `202605` создан заранее и обе квитанции получают его `period`. В тесте логотипа сами файлы не нужны — `ReceiptTemplateImageStorage::url()` строит URL без обращения к файлу.

- [ ] **Step 2: Убедиться, что новые тесты падают**

Run: `make test test_args="--compact --filter=ReceiptTemplateTest"`
Expected: FAIL — нет ключа `template`, нет классов/поведения.

- [ ] **Step 3: Изменить `app/Actions/BuildReceiptPrintViewData.php`**

- Добавить в конструктор ничего не нужно; изменить сигнатуру и загрузку:

```php
public function handle(Receipt $receipt, ?ReceiptTemplateConfig $template = null): array
{
    $receipt->loadMissing([
        'billingPeriod',
        'client.region',
        'client.street',
        'organization.utilityService',
        'organization.receiptTemplate',
    ]);

    $template ??= $this->templateFor($receipt);
    // ... остальной массив без изменений, плюс:
    return [
        'receipt' => $receipt,
        'template' => $template,
        // ...
    ];
}

private function templateFor(Receipt $receipt): ReceiptTemplateConfig
{
    $model = $receipt->organization?->receiptTemplate;

    if (! $model) {
        return ReceiptTemplateConfig::default();
    }

    return ReceiptTemplateConfig::fromSettings(
        is_array($model->settings) ? $model->settings : [],
        ReceiptTemplateImageStorage::url($model->logo_path),
        ReceiptTemplateImageStorage::url($model->qr_path),
    );
}
```

- Подписи полей взять из конфига (`$template` должен быть вычислен до массивов):

```php
'clientDetails' => $this->details([
    $template->label('account_number') => $receipt->account_number,
    $template->label('client_name') => $receipt->client_name,
    $template->label('client_address') => $this->clientAddress($receipt),
    $template->label('period') => $receipt->billingPeriod?->label ?? $receipt->period,
    $template->label('service') => $receipt->utility_service_name,
    $template->label('billing_type') => $this->billingTypeLabel($receipt->billing_type),
]),
'organizationDetails' => $this->details([
    $template->label('organization') => $receipt->organization?->name,
    $template->label('bin_iin') => $receipt->organization?->bin_iin,
    $template->label('phone') => $receipt->organization?->phone,
    $template->label('organization_address') => $receipt->organization?->address,
    $template->label('bank') => $receipt->organization?->bank,
    $template->label('iban') => $receipt->organization?->iban,
]),
```

Внимание: ключи `calculationDetails`/`balanceDetails` (`Объём`, `Сумма`, `Начальное сальдо`, `Оплачено`) не переименовывать — по ним ищут значения `print-copy` и блоки. Обновить PHPDoc `@return`, добавив `template: ReceiptTemplateConfig`.

- [ ] **Step 4: Разбить `print-copy.blade.php` на блоки**

`resources/views/receipts/partials/print-copy.blade.php` целиком заменить на:

```blade
<article
    class="receipt-copy {{ $template->copyCssClasses() }} flex flex-col rounded-xl border border-zinc-900 bg-white p-4 text-[10px] leading-tight text-zinc-950 shadow-sm print:rounded-none print:p-2 print:shadow-none"
    data-receipt-copy="{{ $copyTitle }}"
>
    <div class="grid grid-cols-2 gap-x-3">
        @foreach ($template->enabledBlockTypes() as $blockType)
            @include('receipts.blocks.'.str_replace('_', '-', $blockType))
        @endforeach
    </div>
</article>
```

`resources/views/receipts/blocks/header.blade.php` (шапка на всю ширину, логотип рядом с реквизитами номера):

```blade
<header class="col-span-2 grid grid-cols-[minmax(0,1fr)_auto] gap-3 border-b border-zinc-900 pb-2 print:gap-2">
    <div>
        <p class="text-[9px] font-bold uppercase tracking-[0.18em] text-zinc-500 print:text-[7px]">
            {{ $copyTitle }}
        </p>
        <p class="mt-1 text-[9px] font-semibold uppercase tracking-[0.12em] text-zinc-500 print:text-[7px]">
            {{ $template->title() }}
        </p>
        <h2 class="mt-1 text-base font-bold tracking-tight print:text-[11px]">
            {{ $receipt->organization?->name ?? 'Организация' }}
        </h2>
        <p class="mt-0.5 text-[10px] text-zinc-600 print:text-[8px]">
            {{ $receipt->organization?->address ?? '-' }}
        </p>
    </div>

    <div class="flex items-start gap-2">
        @if ($template->showLogo() && $template->logoUrl())
            <img
                src="{{ $template->logoUrl() }}"
                alt="Логотип организации"
                class="h-10 w-10 shrink-0 object-contain print:h-8 print:w-8"
            >
        @endif

        <dl class="grid w-[10.5rem] grid-cols-[3.4rem_1fr] gap-x-1 gap-y-1 text-[10px] print:w-[8.8rem] print:grid-cols-[2.6rem_1fr] print:text-[8px]">
            <dt class="text-zinc-500">Номер</dt>
            <dd class="font-semibold">{{ $receipt->receipt_number }}</dd>
            <dt class="text-zinc-500">Период</dt>
            <dd class="font-semibold">{{ $receipt->billingPeriod?->label ?? $receipt->period }}</dd>
            <dt class="text-zinc-500">Дата</dt>
            <dd class="font-semibold">{{ $receipt->issued_at?->format('d.m.Y') ?? '-' }}</dd>
        </dl>
    </div>
</header>
```

`resources/views/receipts/blocks/client-details.blade.php` (полширины — соседствует с реквизитами, как сейчас):

```blade
<section class="border-b border-zinc-300 py-2 print:py-1.5">
    <h3 class="text-[10px] font-bold uppercase tracking-wide print:text-[8px]">{{ $template->label('client_name') }}</h3>
    <dl class="mt-1 grid grid-cols-[4.8rem_1fr] gap-x-1 gap-y-0.5 text-[9px] print:grid-cols-[3.8rem_1fr] print:text-[7.5px]">
        @foreach ($clientDetails as $detail)
            <dt class="text-zinc-500">{{ $detail['label'] }}</dt>
            <dd class="font-semibold">{{ $detail['value'] }}</dd>
        @endforeach
    </dl>
</section>
```

`resources/views/receipts/blocks/organization-details.blade.php`:

```blade
<section class="border-b border-zinc-300 py-2 print:py-1.5">
    <h3 class="text-[10px] font-bold uppercase tracking-wide print:text-[8px]">Реквизиты</h3>
    <dl class="mt-1 grid grid-cols-[3.6rem_1fr] gap-x-1 gap-y-0.5 text-[9px] print:grid-cols-[2.9rem_1fr] print:text-[7.5px]">
        @foreach ($organizationDetails as $detail)
            <dt class="text-zinc-500">{{ $detail['label'] }}</dt>
            <dd class="font-semibold">{{ $detail['value'] }}</dd>
        @endforeach
    </dl>
</section>
```

`resources/views/receipts/blocks/meters-table.blade.php` — секция «Счётчики» из старого `print-copy` со строкой «Итого», но без строк «Долг», «Оплачено», «К оплате» (они уходят в блок `totals`):

```blade
@php
    $volume = collect($calculationDetails)->firstWhere('label', 'Объём')['value'] ?? '-';
    $amount = collect($calculationDetails)->firstWhere('label', 'Сумма')['value'] ?? '-';
@endphp

<section class="col-span-2 mt-2 print:mt-1.5">
    <div class="flex items-center justify-between gap-2">
        <h3 class="text-[10px] font-bold uppercase tracking-wide print:text-[8px]">Счётчики</h3>
        <p class="text-[8px] text-zinc-500 print:text-[7px]">
            Сформирована: {{ $generatedAt->format('d.m.Y H:i') }}
        </p>
    </div>

    <div class="mt-1 overflow-hidden rounded-lg border border-zinc-900 print:rounded-none">
        <table class="w-full text-left text-[9px] print:text-[7.5px]">
            <thead class="bg-zinc-100 uppercase tracking-wide text-zinc-600 print:bg-white print:text-[6.8px]">
                <tr>
                    <th class="border-b border-r border-zinc-900 px-2 py-1.5 print:px-1 print:py-0.5">№ счётчика</th>
                    <th class="border-b border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">Предыдущее</th>
                    <th class="border-b border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">Текущее</th>
                    <th class="border-b border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">Расход</th>
                    <th class="border-b border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">Тариф</th>
                    <th class="border-b border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">Сумма</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($meterReadingLines as $line)
                    <tr>
                        <td class="border-r border-zinc-900 px-2 py-1.5 font-semibold print:px-1 print:py-0.5">
                            {{ $line['meter_number'] }}
                        </td>
                        <td class="border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">
                            {{ $line['previous_reading'] }}
                        </td>
                        <td class="border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">
                            {{ $line['current_reading'] }}
                        </td>
                        <td class="border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">
                            {{ $line['consumption'] }}
                        </td>
                        <td class="border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">
                            {{ $line['tariff_price'] }}
                        </td>
                        <td class="px-2 py-1.5 text-right font-bold print:px-1 print:py-0.5">
                            {{ $line['amount'] }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-2 py-1.5 text-center text-zinc-500 print:px-1 print:py-0.5" colspan="6">
                            Нет показаний счётчиков
                        </td>
                    </tr>
                @endforelse

                <tr class="bg-zinc-50 font-bold print:bg-white">
                    <td class="border-t border-r border-zinc-900 px-2 py-1.5 print:px-1 print:py-0.5" colspan="3">
                        Итого
                    </td>
                    <td class="border-t border-r border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">
                        {{ $volume }}
                    </td>
                    <td class="border-t border-r border-zinc-900 px-2 py-1.5 print:px-1 print:py-0.5"></td>
                    <td class="border-t border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">
                        {{ $amount }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</section>
```

`resources/views/receipts/blocks/totals.blade.php`:

```blade
@php
    $balanceDetailsByLabel = collect($balanceDetails);
    $debt = $balanceDetailsByLabel->firstWhere('label', 'Начальное сальдо')['value'] ?? '-';
    $paid = $balanceDetailsByLabel->firstWhere('label', 'Оплачено')['value'] ?? '-';
@endphp

<section class="col-span-2 mt-2 overflow-hidden rounded-lg border border-zinc-900 print:mt-1.5 print:rounded-none">
    <table class="w-full text-left text-[9px] print:text-[7.5px]">
        <tbody>
            <tr>
                <td class="border-r border-zinc-900 px-2 py-1 font-semibold print:px-1 print:py-0.5">Долг</td>
                <td class="w-28 px-2 py-1 text-right font-semibold print:px-1 print:py-0.5">{{ $debt }}</td>
            </tr>
            <tr>
                <td class="border-t border-r border-zinc-900 px-2 py-1 font-semibold print:px-1 print:py-0.5">Оплачено</td>
                <td class="border-t border-zinc-900 px-2 py-1 text-right font-semibold print:px-1 print:py-0.5">{{ $paid }}</td>
            </tr>
            <tr class="bg-zinc-50 font-bold print:bg-white">
                <td class="border-t border-r border-zinc-900 px-2 py-1.5 print:px-1 print:py-0.5">К оплате</td>
                <td class="border-t border-zinc-900 px-2 py-1.5 text-right print:px-1 print:py-0.5">{{ $paymentDue }}</td>
            </tr>
        </tbody>
    </table>
</section>
```

`resources/views/receipts/blocks/footer-note.blade.php`:

```blade
<section class="col-span-2 mt-2 flex items-start justify-between gap-3 print:mt-1.5">
    <p class="text-[9px] text-zinc-700 print:text-[7.5px]">{{ $template->footerNote() }}</p>

    @if ($template->showQr() && $template->qrUrl())
        <img
            src="{{ $template->qrUrl() }}"
            alt="QR-код для оплаты"
            class="h-16 w-16 shrink-0 object-contain print:h-14 print:w-14"
        >
    @endif
</section>
```

- [ ] **Step 5: Применить число копий в `print.blade.php` и `bulk-print.blade.php`**

В `print.blade.php` заменить секцию копий на:

```blade
<section class="receipt-sheet {{ $template->copiesPerPage() === 1 ? 'receipt-sheet-single' : '' }}">
    @foreach ($template->copyTitles() as $copyTitle)
        @include('receipts.partials.print-copy', ['copyTitle' => $copyTitle])
    @endforeach
</section>
```

И поясняющий текст под заголовком сделать зависимым от копий:

```blade
<p class="mt-1 text-sm text-zinc-600">
    {{ $template->copiesPerPage() === 1 ? 'На листе A5 печатается один экземпляр для абонента.' : 'На одном листе A5 печатаются два экземпляра: для организации и для абонента.' }}
</p>
```

В `bulk-print.blade.php` заменить цикл:

```blade
@forelse ($receiptPrintData as $printData)
    <section class="receipt-sheet receipt-sheet-bulk {{ $printData['template']->copiesPerPage() === 1 ? 'receipt-sheet-single' : '' }}">
        @foreach ($printData['template']->copyTitles() as $copyTitle)
            @include('receipts.partials.print-copy', array_merge($printData, ['copyTitle' => $copyTitle]))
        @endforeach
    </section>
@empty
```

- [ ] **Step 6: Обновить существующий тест**

В `tests/Feature/ReceiptTest.php` в тесте `admin users can open a current tenant receipt print view` добавить `'template'` в список `assertViewHasAll([...])` (после `'receipt'`).

- [ ] **Step 7: Прогнать печать целиком**

Run: `make test test_args="--compact --filter=ReceiptTest"`
Expected: PASS — существующее поведение по умолчанию не изменилось (порядок текстов в тесте сохраняется).

Run: `make test test_args="--compact --filter=ReceiptTemplateTest"`
Expected: PASS. Тест `appearance settings add css classes...` проверяет только наличие классов в HTML — CSS-правила для этих классов появятся в Task 5, на тест это не влияет.

- [ ] **Step 8: Pint и commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "Печать квитанции разбита на блоки и управляется шаблоном организации

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: CSS внешнего вида и общий файл стилей квитанции

**Files:**
- Create: `resources/css/receipt.css`
- Modify: `resources/css/app.css` (перенос `.receipt-*`-правил, `@import`)
- Modify: `resources/css/filament/admin/theme.css` (`@import` + `@source` для вьюх квитанций)

**Interfaces:**
- Consumes: классы `receipt-font-*`, `receipt-density-*`, `receipt-no-borders`, `receipt-sheet-single` из `ReceiptTemplateConfig::copyCssClasses()` (Task 2) и вьюх (Task 4).
- Produces: стили квитанции доступны и на печатных страницах (`app.css`), и внутри панели Filament (тема) — нужно Task 6/7 для предпросмотра.

- [ ] **Step 1: Создать `resources/css/receipt.css`**

Перенести из `app.css` все правила, начинающиеся с `.receipt-` (блоки `.receipt-sheet`, `.receipt-sheet-bulk`, `@media screen`, `@media print` — целиком), и добавить правила внешнего вида:

```css
.receipt-copy.receipt-font-compact {
    zoom: 0.85;
}

.receipt-copy.receipt-font-large {
    zoom: 1.15;
}

.receipt-copy.receipt-density-compact :is(th, td) {
    padding: 2px 4px;
}

.receipt-copy.receipt-density-large :is(th, td) {
    padding: 8px 10px;
}

.receipt-copy.receipt-no-borders,
.receipt-copy.receipt-no-borders :is(th, td, header, section, div, table, thead, tbody, tr) {
    border-color: transparent;
}
```

В `@media print` добавить (после правила `.receipt-sheet`):

```css
.receipt-sheet-single {
    grid-template-rows: minmax(0, 1fr);
}
```

- [ ] **Step 2: Подключить файл в оба бандла**

В `resources/css/app.css` вместо перенесённых правил добавить (после `@source`-строк):

```css
@import './receipt.css';
```

В `resources/css/filament/admin/theme.css` добавить в конец:

```css
@source '../../../../resources/views/receipts/**/*';

@import '../../receipt.css';
```

`@source` нужен, чтобы Tailwind-утилиты из блоков квитанции попали в тему панели — без этого предпросмотр в конструкторе (Task 7) будет без стилей.

- [ ] **Step 3: Собрать фронтенд и проверить**

Run: `npm run build`
Expected: сборка без ошибок; в собранных css присутствует `receipt-font-large` (проверь `grep -rl "receipt-font-large" public/build/assets | head -1`).

- [ ] **Step 4: Прогнать тесты печати (регресс)**

Run: `make test test_args="--compact --filter=ReceiptTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Общий CSS квитанции и классы внешнего вида шаблона

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Страница «Шаблон квитанции» — форма, сохранение, сброс

**Files:**
- Create: `app/Filament/Pages/ReceiptTemplatePage.php`
- Create: `resources/views/filament/pages/receipt-template-page.blade.php`
- Test: `tests/Feature/ReceiptTemplatePageTest.php`

**Interfaces:**
- Consumes: `ReceiptTemplate`, `ReceiptTemplateConfig`, `ReceiptTemplateDefaults`, `ReceiptTemplateImageStorage`.
- Produces:
  - страница панели `admin` со slug `receipt-template`, tenant-scoped, доступна по `canManageOrganization`;
  - публичные методы Livewire: `save(): void`, `resetTemplate(): void`;
  - `settingsFromForm(array $state): array` (protected) — маппинг состояния формы в структуру `settings`; используется предпросмотром (Task 7).

- [ ] **Step 1: Написать падающие тесты `tests/Feature/ReceiptTemplatePageTest.php`**

```php
<?php

use App\Filament\Pages\ReceiptTemplatePage;
use App\Models\Organization;
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
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `make test test_args="--compact --filter=ReceiptTemplatePageTest"`
Expected: FAIL — класс страницы не существует.

- [ ] **Step 3: Создать `app/Filament/Pages/ReceiptTemplatePage.php`**

По образцу «singular resource» из документации Filament (страница с формой и `statePath('data')`):

```php
<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Models\ReceiptTemplate;
use App\Models\User;
use App\Support\ReceiptTemplateConfig;
use App\Support\ReceiptTemplateDefaults;
use App\Support\ReceiptTemplateImageStorage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class ReceiptTemplatePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static ?string $slug = 'receipt-template';

    protected static ?string $title = 'Шаблон квитанции';

    protected static ?string $navigationLabel = 'Шаблон квитанции';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.pages.receipt-template-page';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->canManageOrganization($tenant);
    }

    public function mount(): void
    {
        $this->fillFormFromTemplate($this->getTemplate());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Блоки квитанции')
                        ->description('Перетащите блоки, чтобы поменять порядок. Шапку выключить нельзя.')
                        ->schema([
                            Repeater::make('blocks')
                                ->hiddenLabel()
                                ->schema([
                                    Hidden::make('type'),
                                    Toggle::make('enabled')
                                        ->label('Показывать')
                                        ->disabled(fn (Get $get): bool => $get('type') === 'header')
                                        ->dehydrated()
                                        ->live(),
                                ])
                                ->itemLabel(fn (array $state): string => ReceiptTemplateDefaults::blockLabel((string) ($state['type'] ?? '')))
                                ->reorderable()
                                ->addable(false)
                                ->deletable(false)
                                ->live(),
                        ]),
                    Section::make('Тексты')
                        ->collapsible()
                        ->schema([
                            TextInput::make('texts.title')
                                ->label('Заголовок квитанции')
                                ->maxLength(255)
                                ->live(onBlur: true),
                            Textarea::make('texts.footer_note')
                                ->label('Примечание внизу квитанции')
                                ->helperText('Выводится в блоке «Примечание», если он включён.')
                                ->maxLength(1000)
                                ->live(onBlur: true),
                            Section::make('Подписи полей')
                                ->collapsed()
                                ->schema($this->labelInputs()),
                        ]),
                    Section::make('Изображения')
                        ->collapsible()
                        ->schema([
                            FileUpload::make('logo_path')
                                ->label('Логотип')
                                ->disk(ReceiptTemplateImageStorage::disk())
                                ->directory(fn (): string => ReceiptTemplateImageStorage::directoryFor(Filament::getTenant()?->getKey() ?? 0))
                                ->image()
                                ->maxSize(1024),
                            FileUpload::make('qr_path')
                                ->label('QR-код для оплаты')
                                ->disk(ReceiptTemplateImageStorage::disk())
                                ->directory(fn (): string => ReceiptTemplateImageStorage::directoryFor(Filament::getTenant()?->getKey() ?? 0))
                                ->image()
                                ->maxSize(1024),
                        ]),
                    Section::make('Внешний вид')
                        ->collapsible()
                        ->columns(2)
                        ->schema([
                            Select::make('appearance.font_size')
                                ->label('Размер шрифта')
                                ->options([
                                    'compact' => 'Компактный',
                                    'normal' => 'Обычный',
                                    'large' => 'Крупный',
                                ])
                                ->selectablePlaceholder(false)
                                ->live(),
                            Select::make('appearance.density')
                                ->label('Плотность')
                                ->options([
                                    'compact' => 'Компактная',
                                    'normal' => 'Обычная',
                                    'large' => 'Просторная',
                                ])
                                ->selectablePlaceholder(false)
                                ->live(),
                            Radio::make('appearance.copies_per_page')
                                ->label('Экземпляров на листе')
                                ->options([
                                    2 => 'Два: для организации и для абонента',
                                    1 => 'Один: только для абонента',
                                ])
                                ->live(),
                            Toggle::make('appearance.borders')
                                ->label('Рамки')
                                ->live(),
                            Toggle::make('appearance.show_logo')
                                ->label('Показывать логотип')
                                ->live(),
                            Toggle::make('appearance.show_qr')
                                ->label('Показывать QR-код')
                                ->live(),
                        ]),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Сохранить')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int, \Filament\Forms\Components\TextInput>
     */
    protected function labelInputs(): array
    {
        $inputs = [];

        foreach (ReceiptTemplateDefaults::labels() as $key => $label) {
            $inputs[] = TextInput::make("texts.labels.{$key}")
                ->label($label)
                ->placeholder($label)
                ->maxLength(100)
                ->live(onBlur: true);
        }

        return $inputs;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetTemplate')
                ->label('Сбросить к стандартному')
                ->color('danger')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->requiresConfirmation()
                ->modalHeading('Сбросить шаблон?')
                ->modalDescription('Настройки, логотип и QR-код будут удалены, квитанция вернётся к стандартному виду.')
                ->action(fn () => $this->resetTemplate()),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $tenant = $this->tenantOrFail();

        $settings = ReceiptTemplateConfig::fromSettings($this->settingsFromForm($data))->settings();

        $existing = $this->getTemplate();
        $oldLogoPath = $existing?->logo_path;
        $oldQrPath = $existing?->qr_path;

        $template = ReceiptTemplate::query()->updateOrCreate(
            ['organization_id' => $tenant->getKey()],
            [
                'settings' => $settings,
                'logo_path' => $data['logo_path'] ?? null,
                'qr_path' => $data['qr_path'] ?? null,
            ],
        );

        if ($oldLogoPath && $oldLogoPath !== $template->logo_path) {
            ReceiptTemplateImageStorage::delete($oldLogoPath);
        }

        if ($oldQrPath && $oldQrPath !== $template->qr_path) {
            ReceiptTemplateImageStorage::delete($oldQrPath);
        }

        $this->fillFormFromTemplate($template);

        Notification::make()
            ->success()
            ->title('Шаблон квитанции сохранён')
            ->send();
    }

    public function resetTemplate(): void
    {
        $this->getTemplate()?->delete();

        $this->fillFormFromTemplate(null);

        Notification::make()
            ->success()
            ->title('Шаблон сброшен к стандартному')
            ->send();
    }

    /**
     * Преобразует состояние формы в структуру settings. Терпит сырое
     * состояние Livewire (используется и предпросмотром до валидации).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function settingsFromForm(array $state): array
    {
        $blocks = [];

        foreach (is_array($state['blocks'] ?? null) ? $state['blocks'] : [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            $blocks[] = [
                'type' => (string) ($block['type'] ?? ''),
                'enabled' => (bool) ($block['enabled'] ?? false),
            ];
        }

        $texts = is_array($state['texts'] ?? null) ? $state['texts'] : [];
        $appearance = is_array($state['appearance'] ?? null) ? $state['appearance'] : [];
        $copiesPerPage = $appearance['copies_per_page'] ?? null;

        return [
            'blocks' => $blocks,
            'texts' => [
                'title' => (string) ($texts['title'] ?? ''),
                'footer_note' => (string) ($texts['footer_note'] ?? ''),
                'labels' => is_array($texts['labels'] ?? null) ? $texts['labels'] : [],
            ],
            'appearance' => [
                'copies_per_page' => is_numeric($copiesPerPage) ? (int) $copiesPerPage : null,
                'font_size' => $appearance['font_size'] ?? null,
                'density' => $appearance['density'] ?? null,
                'borders' => (bool) ($appearance['borders'] ?? true),
                'show_logo' => (bool) ($appearance['show_logo'] ?? true),
                'show_qr' => (bool) ($appearance['show_qr'] ?? false),
            ],
        ];
    }

    protected function fillFormFromTemplate(?ReceiptTemplate $template): void
    {
        $settings = ReceiptTemplateConfig::fromSettings($template->settings ?? [])->settings();

        $this->form->fill([
            'blocks' => $settings['blocks'],
            'texts' => $settings['texts'],
            'appearance' => $settings['appearance'],
            'logo_path' => $template?->logo_path,
            'qr_path' => $template?->qr_path,
        ]);
    }

    protected function getTemplate(): ?ReceiptTemplate
    {
        return ReceiptTemplate::query()
            ->whereBelongsTo($this->tenantOrFail())
            ->first();
    }

    protected function tenantOrFail(): Organization
    {
        $tenant = Filament::getTenant();

        abort_unless($tenant instanceof Organization, 404);

        return $tenant;
    }
}
```

- [ ] **Step 4: Создать вью `resources/views/filament/pages/receipt-template-page.blade.php`** (пока без предпросмотра — он в Task 7)

```blade
<x-filament-panels::page>
    {{ $this->form }}
</x-filament-panels::page>
```

- [ ] **Step 5: Убедиться, что тесты проходят**

Run: `make test test_args="--compact --filter=ReceiptTemplatePageTest"`
Expected: PASS (5 тестов).

Возможные проблемы:
- `fillForm(['texts.title' => ...])` в Filament v5 принимает точечную нотацию; если падает — используй вложенный массив `['texts' => ['title' => ...]]`.
- Если `Heroicon::OutlinedPaintBrush` не существует — проверь список: `grep -r "OutlinedPaintBrush" vendor/filament/support/src/Icons/Heroicon.php`; запасной вариант `Heroicon::OutlinedAdjustmentsHorizontal`.
- Если `Form::make` не найден в `Filament\Schemas\Components` — проверь неймспейс: `grep -rn "class Form" vendor/filament/schemas/src/Components/`.

- [ ] **Step 6: Pint и commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "Страница «Шаблон квитанции» с формой, сохранением и сбросом

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: Живой предпросмотр на странице конструктора

**Files:**
- Modify: `app/Filament/Pages/ReceiptTemplatePage.php` (метод `previewHtml()`, демо-квитанция)
- Modify: `resources/views/filament/pages/receipt-template-page.blade.php` (двухколоночный макет)
- Test: `tests/Feature/ReceiptTemplatePageTest.php` (дополнить)

**Interfaces:**
- Consumes: `BuildReceiptPrintViewData::handle(Receipt, ?ReceiptTemplateConfig)` (Task 4), `settingsFromForm()` (Task 6).
- Produces: `previewHtml(): Illuminate\Support\HtmlString` — HTML одного экземпляра квитанции по текущему (несохранённому) состоянию формы.

- [ ] **Step 1: Дополнить тесты**

В `tests/Feature/ReceiptTemplatePageTest.php`:

```php
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
    App\Models\Receipt::factory()->for($organization)->create([
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
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `make test test_args="--compact --filter=ReceiptTemplatePageTest"`
Expected: FAIL — предпросмотра нет во вью.

- [ ] **Step 3: Добавить предпросмотр в страницу**

В `ReceiptTemplatePage` добавить импорты `App\Actions\BuildReceiptPrintViewData`, `App\Models\Receipt`, `Illuminate\Support\HtmlString` и методы:

```php
public function previewHtml(): HtmlString
{
    $tenant = $this->tenantOrFail();
    $saved = $this->getTemplate();

    $config = ReceiptTemplateConfig::fromSettings(
        $this->settingsFromForm(is_array($this->data) ? $this->data : []),
        ReceiptTemplateImageStorage::url($saved?->logo_path),
        ReceiptTemplateImageStorage::url($saved?->qr_path),
    );

    $viewData = app(BuildReceiptPrintViewData::class)->handle($this->previewReceipt($tenant), $config);

    return new HtmlString(
        view('receipts.partials.print-copy', array_merge($viewData, ['copyTitle' => 'Предпросмотр']))->render(),
    );
}

/**
 * Последняя квитанция организации; если квитанций ещё нет — несохранённая
 * демонстрационная модель, чтобы предпросмотр работал у новой организации.
 */
protected function previewReceipt(Organization $tenant): Receipt
{
    $receipt = Receipt::query()
        ->whereBelongsTo($tenant)
        ->latest('id')
        ->first();

    if ($receipt) {
        return $receipt;
    }

    $receipt = new Receipt([
        'receipt_number' => '202608-100001',
        'account_number' => '100001',
        'client_name' => 'Иванов Иван',
        'utility_service_name' => $tenant->utilityService?->name ?? 'Коммунальная услуга',
        'billing_type' => 'fixed',
        'volume' => 20,
        'tariff_price' => 90,
        'amount' => 1800,
        'paid_amount' => 0,
        'adjustment_amount' => 0,
        'opening_balance' => 0,
        'closing_balance' => 1800,
        'issued_at' => now(),
        'period' => now()->format('Ym'),
    ]);

    $receipt->setRelation('organization', $tenant->loadMissing(['utilityService', 'receiptTemplate']));
    $receipt->setRelation('billingPeriod', null);
    $receipt->setRelation('client', null);

    return $receipt;
}
```

Важно: `previewReceipt` возвращает несохранённую модель — поэтому в Task 4 `BuildReceiptPrintViewData` переведён с `load` на `loadMissing` (иначе `load` перезапишет подставленные связи результатами пустых запросов). Демо-модель нельзя сохранять (`save()` не вызывается нигде).

Проверь, что `Receipt::booted saving` не срабатывает (мы не сохраняем) и `BuildReceiptMeterReadingLines` на квитанции без `client_id` возвращает пустой список (выведется «Нет показаний счётчиков» — это нормально для демо).

- [ ] **Step 4: Обновить вью страницы**

```blade
<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-[minmax(0,28rem)_minmax(0,1fr)]">
        <div>
            {{ $this->form }}
        </div>

        <div>
            <div class="sticky top-20">
                <h2 class="mb-3 text-sm font-semibold text-gray-500 dark:text-gray-400">
                    Предпросмотр — как квитанция будет выглядеть при печати
                </h2>

                <div class="receipt-sheet max-w-2xl overflow-x-auto rounded-xl bg-stone-100 p-4 dark:bg-white/5">
                    {!! $this->previewHtml() !!}
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
```

`{!! !!}` здесь безопасен: `previewHtml()` — это отрендеренный Blade-партиал, все пользовательские значения внутри него уже экранированы `{{ }}`.

- [ ] **Step 5: Убедиться, что тесты проходят**

Run: `make test test_args="--compact --filter=ReceiptTemplatePageTest"`
Expected: PASS (8 тестов).

- [ ] **Step 6: Прогнать всю фичу и посмотреть глазами**

Run: `make test test_args="--compact --filter='ReceiptTemplate|ReceiptTest'"`
Expected: PASS.

Затем открой в браузере страницу `/admin/{id}/receipt-template` (Boost: `get-absolute-url`), проверь: перетаскивание блоков, тумблеры, мгновенное обновление предпросмотра, сохранение, сброс. Если стили предпросмотра не применяются — пересобери фронтенд (`npm run build`) и убедись, что `@source`-строка из Task 5 присутствует в теме.

- [ ] **Step 7: Pint и commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "Живой предпросмотр квитанции на странице конструктора

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: Документация, changelog, design-preview и полный прогон

**Files:**
- Modify: `docs/modules/receipts.md`
- Modify: `docs/changelog.md`
- Modify: `resources/views/design-preview.blade.php`

**Interfaces:**
- Consumes: всё реализованное выше.

- [ ] **Step 1: Обновить `docs/modules/receipts.md`**

В конец раздела «Правила» добавить:

```markdown
Печатная форма квитанции строится по шаблону организации (модуль «Шаблон квитанции», `docs/modules/receipt-template.md`): состав и порядок блоков, тексты, подписи полей, логотип, QR-код и внешний вид берутся из настроек шаблона. Если шаблон не настроен, используется стандартный вид: два экземпляра на листе, все блоки, кроме примечания.
```

- [ ] **Step 2: Добавить запись в `docs/changelog.md`**

Вверху файла после `# Changelog` добавить секцию (если секция `## 2026-08-11` уже есть — дописать в её `### Added`):

```markdown
## 2026-08-11

### Added

- Конструктор шаблона квитанции: страница «Шаблон квитанции» в панели организации с живым предпросмотром. Организация включает/выключает и переставляет блоки (шапка, абонент, реквизиты, счётчики, итоги, примечание), меняет заголовок, примечание и подписи полей, загружает логотип и QR-код, настраивает размер шрифта, плотность, рамки и число экземпляров на листе (один или два). Одиночная и массовая печать применяют шаблон; без настроек квитанция выглядит как раньше. Настройки хранятся в JSON и сливаются с дефолтами, поэтому будущие блоки появляются у существующих шаблонов автоматически.
```

- [ ] **Step 3: Добавить секцию в `/design-preview`**

В `resources/views/design-preview.blade.php` найти существующую секцию квитанции (поиск по тексту «Один лист A5 содержит два компактных экземпляра», около строки 1290) и после закрытия этой секции добавить новую секцию по тому же образцу вёрстки (те же классы карточек, что у соседних секций):

```blade
<section class="mt-10">
    <h2 class="text-xl font-semibold tracking-tight">Конструктор шаблона квитанции</h2>
    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
        Страница «Шаблон квитанции» в панели организации: слева блоки с перетаскиванием и тумблерами,
        тексты, логотип и QR-код, внешний вид; справа живой предпросмотр. Печать применяет шаблон:
        свой порядок блоков, выключенные блоки, свои тексты, один или два экземпляра на листе.
    </p>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Стандартный шаблон</p>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Все блоки в стандартном порядке: шапка, абонент и реквизиты, счётчики, итоги.
                Два экземпляра на листе. Используется, пока организация не настроила свой шаблон.
            </p>
        </div>
        <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Настроенный шаблон</p>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Пример настроек: реквизиты выше абонента, таблица счётчиков выключена, свой заголовок
                «Счёт за воду», примечание «Оплатите до 25 числа», логотип в шапке, QR-код в примечании,
                один экземпляр на листе, крупный шрифт без рамок.
            </p>
        </div>
    </div>
</section>
```

Перед добавлением сверь классы с соседними секциями файла и используй их стиль (если в файле карточки оформлены иначе — повтори их разметку, а не эту дословно).

- [ ] **Step 4: Полный прогон тестов**

Run: `make test`
Expected: PASS — весь набор зелёный.

- [ ] **Step 5: Pint и финальный commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "Документация, changelog и design-preview для конструктора шаблона квитанции

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```
