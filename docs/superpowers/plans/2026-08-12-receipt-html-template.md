# HTML-шаблон квитанции с переменными (v2) — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Полная замена блочного конструктора квитанции (v1) на свободный HTML-шаблон с плейсхолдерами `{{key}}` и визуальным редактором GrapesJS; без шаблона в БД печать выглядит как сейчас.

**Architecture:** HTML+CSS шаблона хранятся в `receipt_templates` (колонки `html`, `css`, `copies_per_page`; блочный `settings` удаляется). Санитизация `symfony/html-sanitizer` при сохранении и рендере; свой рендерер подставляет скалярные значения с экранированием и готовые фрагменты (`meters_table`, `logo`, `qr`). Страница «Шаблон квитанции» получает GrapesJS-канву с палитрой переменных.

**Tech Stack:** Laravel 13, Filament v5, Livewire 4, Pest 4, symfony/html-sanitizer (v8, уже в vendor как транзитивная зависимость — одобрено), GrapesJS (npm, одобрено), Tailwind 4/Vite.

**Спека:** `docs/superpowers/specs/2026-08-12-receipt-html-template-design.md` — прочитай перед началом.

## Global Constraints

- Все тексты интерфейса, документации и коммитов — на русском языке.
- Тесты только через Docker: `make test test_args="--compact --filter=Имя"`; полный прогон `make test`.
- После изменения PHP: `vendor/bin/pint --dirty --format agent` перед коммитом.
- Коммитить ТОЛЬКО по именам файлов (`git add <файлы>`), НИКОГДА `git add -A`/`git add .` — в рабочем дереве есть постороннее незакоммиченное изменение пользователя (удалённый `docker/db/dump.sql.gz`), его трогать нельзя.
- Никакого исполняемого кода в шаблонах: подстановка только по белому списку с экранированием; в БД — только санитизированный HTML.
- Коммиты завершай футером `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Существующий тест `admin users can open a current tenant receipt print view` в `tests/Feature/ReceiptTest.php` проверяет порядок текстов дефолтной квитанции — он должен остаться зелёным (обновляется только список `assertViewHasAll`).

---

### Task 1: Документация модуля v2

**Files:**
- Modify: `docs/modules/receipt-template.md` (полная замена содержимого)

**Interfaces:**
- Produces: контракт v2 (каталог переменных, правила санитизации), на который опираются последующие задачи.

- [ ] **Step 1: Переписать `docs/modules/receipt-template.md`**

Полностью заменить содержимое файла:

````markdown
# Модуль: Шаблон квитанции

## Терминология

Шаблон квитанции в коде называется `ReceiptTemplate`. Таблица — `receipt_templates`.

## Назначение

Организация задаёт собственный вид печатной квитанции как HTML-шаблон с плейсхолдерами `{{переменная}}`. Шаблон редактируется визуальным конструктором GrapesJS на странице «Шаблон квитанции» в панели организации: перетаскивание элементов, правка текста на холсте, палитра готовых переменных и фрагментов.

Если шаблон не сохранён, печать использует стандартный шаблон из файлов `resources/receipt-templates/default.html` и `default.css` — он воспроизводит стандартный вид квитанции и служит стартовым содержимым редактора.

## Основные поля

| Поле | Описание |
|---|---|
| Организация | `organization_id`, уникально, одна запись на организацию |
| HTML шаблона | `html`, nullable; `null` — шаблон не настроен |
| CSS шаблона | `css`, nullable |
| Экземпляров на листе | `copies_per_page`, 1 или 2 |
| Логотип | `logo_path`, диск `public`, каталог `receipt-templates/{organization_id}` |
| QR-код | `qr_path`, там же |

## Переменные

Каталог переменных — класс `App\Support\ReceiptTemplateVariables` (единственный источник правды: ключ, русское название, извлечение значения из квитанции).

Скалярные переменные (значение экранируется при подстановке): `organization_name`, `organization_address`, `organization_bin`, `organization_phone`, `organization_bank`, `organization_iban`, `account_number`, `client_name`, `client_address`, `period`, `service_name`, `billing_type`, `receipt_number`, `issued_date`, `generated_at`, `volume`, `unit`, `tariff_price`, `amount`, `paid_amount`, `adjustment_amount`, `opening_balance`, `closing_balance`, `amount_due`, `copy_title`.

Составные плейсхолдеры (разворачиваются в готовый HTML-фрагмент, который генерирует только код проекта): `meters_table` — таблица показаний счётчиков со строкой «Итого»; `logo` — `<img>` логотипа организации (пусто, если файл не загружен); `qr` — `<img>` QR-кода.

Плейсхолдеры ставятся в текст шаблона как `{{key}}` (допустимы пробелы внутри скобок). Плейсхолдеры в атрибутах HTML не поддерживаются. Неизвестный ключ заменяется пустой строкой.

## Рендер

`App\Support\ReceiptTemplateRenderer::render(html, values, fragments)` подставляет значения по белому списку: скаляры — с экранированием, фрагменты — как HTML. Никакой Blade/eval в шаблонах не выполняется.

`App\Actions\BuildReceiptPrintViewData` собирает данные печати: берёт HTML/CSS шаблона организации (или дефолт), санитизирует, рендерит по экземпляру на каждую копию (`copy_title` различается) и отдаёт готовые `renderedCopies` и `templateCss` вьюхам печати. Массовая печать применяет шаблон организации ко всем квитанциям.

## Санитизация

`App\Support\ReceiptTemplateHtmlSanitizer` (на базе `symfony/html-sanitizer`) вызывается при сохранении и повторно при рендере печати:

- разрешённые теги: `div, section, header, footer, p, span, h1–h6, strong, em, u, s, small, br, hr, table, thead, tbody, tfoot, tr, td, th, ul, ol, li, dl, dt, dd, img`;
- разрешённые атрибуты: `class`, `style`; у ячеек таблиц — `colspan`, `rowspan`; у `img` — `src`, `alt`, `width`, `height`;
- `img src` — только относительные пути, начинающиеся с `/storage/`; прочие источники вырезаются;
- скрипты, `on*`-атрибуты, `iframe`, `object`, `embed`, `form`, `svg` и прочие опасные конструкции вычищаются.

CSS фильтруется отдельно: удаляются `@import`, `url(...)`, `expression(`, `behavior:`, `javascript:`.

Лимиты: HTML ≤ 64 КБ, CSS ≤ 32 КБ.

## Права

Страница и сохранение доступны только членам организации с правом управления (`canManageOrganization`); контроллеры страницу не видят. Запись всегда ищется/создаётся по текущему tenant. Пути `logo_path`/`qr_path` проверяются на принадлежность каталогу организации на сервере.

## Печать

Один лист A5 содержит один или два экземпляра (`copies_per_page`). CSS шаблона вставляется на печатную страницу один раз. Файлы логотипа и QR удаляются при сбросе шаблона, замене файла и удалении организации.
````

- [ ] **Step 2: Commit**

```bash
git add docs/modules/receipt-template.md
git commit -m "Документация модуля «Шаблон квитанции» переписана под HTML-шаблоны v2

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Санитайзер HTML и CSS-фильтр

**Files:**
- Modify: `composer.json` (закрепить прямую зависимость)
- Create: `app/Support/ReceiptTemplateHtmlSanitizer.php`
- Test: `tests/Unit/ReceiptTemplateHtmlSanitizerTest.php`

**Interfaces:**
- Produces:
  - `ReceiptTemplateHtmlSanitizer::MAX_HTML_BYTES = 65536`, `MAX_CSS_BYTES = 32768`;
  - `ReceiptTemplateHtmlSanitizer::sanitizeHtml(string $html): string`;
  - `ReceiptTemplateHtmlSanitizer::sanitizeCss(string $css): string`.

- [ ] **Step 1: Закрепить зависимость**

`symfony/html-sanitizer` v8.0.12 уже установлен в vendor (транзитивно). Выполни:

```bash
composer require symfony/html-sanitizer --no-interaction
```

Если локальный composer/php недоступен — выполни в Docker: `docker compose exec -T app composer require symfony/html-sanitizer --no-interaction`. Ожидание: в `composer.json` появляется `"symfony/html-sanitizer": "^8.0"`, composer.lock без существенных изменений (пакет уже стоял).

- [ ] **Step 2: Написать падающий тест `tests/Unit/ReceiptTemplateHtmlSanitizerTest.php`**

```php
<?php

use App\Support\ReceiptTemplateHtmlSanitizer;

test('sanitizer keeps allowed markup and placeholders', function () {
    $html = '<div class="a" style="color:red"><h2>Счёт</h2><p>Абонент: {{client_name}}</p>'
        .'<table><tbody><tr><td colspan="2">{{amount}}</td></tr></tbody></table>'
        .'<img src="/storage/receipt-templates/1/logo.png" alt="Логотип" width="40" height="40"><hr></div>';

    $clean = ReceiptTemplateHtmlSanitizer::sanitizeHtml($html);

    expect($clean)->toContain('{{client_name}}')
        ->and($clean)->toContain('{{amount}}')
        ->and($clean)->toContain('class="a"')
        ->and($clean)->toContain('style="color:red"')
        ->and($clean)->toContain('colspan="2"')
        ->and($clean)->toContain('/storage/receipt-templates/1/logo.png')
        ->and($clean)->toContain('<hr');
});

test('sanitizer strips scripts event handlers and dangerous tags', function () {
    $html = '<p onclick="alert(1)">x</p><script>alert(1)</script>'
        .'<iframe src="https://evil.example"></iframe><form><input></form>'
        .'<a href="javascript:alert(1)">link</a><object data="x"></object>';

    $clean = ReceiptTemplateHtmlSanitizer::sanitizeHtml($html);

    expect($clean)->not->toContain('script')
        ->and($clean)->not->toContain('onclick')
        ->and($clean)->not->toContain('iframe')
        ->and($clean)->not->toContain('javascript:')
        ->and($clean)->not->toContain('<form')
        ->and($clean)->not->toContain('<input')
        ->and($clean)->not->toContain('<object')
        ->and($clean)->toContain('x');
});

test('sanitizer removes external and data image sources but keeps storage paths', function () {
    $html = '<img src="https://evil.example/a.png"><img src="data:image/png;base64,AAAA">'
        .'<img src="/storage/receipt-templates/5/qr.png"><img src="/etc/passwd">';

    $clean = ReceiptTemplateHtmlSanitizer::sanitizeHtml($html);

    expect($clean)->not->toContain('evil.example')
        ->and($clean)->not->toContain('data:image')
        ->and($clean)->not->toContain('/etc/passwd')
        ->and($clean)->toContain('/storage/receipt-templates/5/qr.png');
});

test('sanitizer does not truncate large templates under the limit', function () {
    $row = '<tr><td>строка</td><td>{{amount}}</td></tr>';
    $html = '<table><tbody>'.str_repeat($row, 700).'</tbody></table>';

    expect(strlen($html))->toBeGreaterThan(20000);

    $clean = ReceiptTemplateHtmlSanitizer::sanitizeHtml($html);

    expect(substr_count($clean, '{{amount}}'))->toBe(700);
});

test('css filter strips imports urls and expressions but keeps rules', function () {
    $css = "@import url('https://evil.example/x.css');\n"
        .".rt-header { color: #111; border-bottom: 1px solid #000; }\n"
        .".bad { background: url(https://evil.example/b.png); behavior: url(x.htc); }\n"
        .".worse { width: expression(alert(1)); content: 'javascript:alert(1)'; }";

    $clean = ReceiptTemplateHtmlSanitizer::sanitizeCss($css);

    expect($clean)->toContain('.rt-header')
        ->and($clean)->toContain('border-bottom: 1px solid #000')
        ->and($clean)->not->toContain('@import')
        ->and($clean)->not->toContain('url(')
        ->and($clean)->not->toContain('expression(')
        ->and($clean)->not->toContain('behavior:')
        ->and($clean)->not->toContain('javascript:');
});
```

- [ ] **Step 3: Убедиться, что тесты падают**

Run: `make test test_args="--compact --filter=ReceiptTemplateHtmlSanitizerTest"`
Expected: FAIL — класс не существует.

- [ ] **Step 4: Создать `app/Support/ReceiptTemplateHtmlSanitizer.php`**

```php
<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Санитизация HTML/CSS шаблона квитанции. Вызывается при сохранении шаблона
 * и повторно при рендере печати: в БД и в печать попадает только чистый HTML.
 */
final class ReceiptTemplateHtmlSanitizer
{
    public const MAX_HTML_BYTES = 65536;

    public const MAX_CSS_BYTES = 32768;

    private const ALLOWED_ELEMENTS = [
        'div' => ['class', 'style'],
        'section' => ['class', 'style'],
        'header' => ['class', 'style'],
        'footer' => ['class', 'style'],
        'p' => ['class', 'style'],
        'span' => ['class', 'style'],
        'h1' => ['class', 'style'],
        'h2' => ['class', 'style'],
        'h3' => ['class', 'style'],
        'h4' => ['class', 'style'],
        'h5' => ['class', 'style'],
        'h6' => ['class', 'style'],
        'strong' => ['class', 'style'],
        'em' => ['class', 'style'],
        'u' => ['class', 'style'],
        's' => ['class', 'style'],
        'small' => ['class', 'style'],
        'br' => [],
        'hr' => ['class', 'style'],
        'table' => ['class', 'style'],
        'thead' => ['class', 'style'],
        'tbody' => ['class', 'style'],
        'tfoot' => ['class', 'style'],
        'tr' => ['class', 'style'],
        'td' => ['class', 'style', 'colspan', 'rowspan'],
        'th' => ['class', 'style', 'colspan', 'rowspan'],
        'ul' => ['class', 'style'],
        'ol' => ['class', 'style'],
        'li' => ['class', 'style'],
        'dl' => ['class', 'style'],
        'dt' => ['class', 'style'],
        'dd' => ['class', 'style'],
        'img' => ['src', 'alt', 'width', 'height', 'class', 'style'],
    ];

    public static function sanitizeHtml(string $html): string
    {
        $config = (new HtmlSanitizerConfig())
            ->allowRelativeMedias()
            ->withMaxInputLength(self::MAX_HTML_BYTES);

        foreach (self::ALLOWED_ELEMENTS as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }

        $clean = (new HtmlSanitizer($config))->sanitize($html);

        return self::restrictImageSources($clean);
    }

    public static function sanitizeCss(string $css): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $css = (string) preg_replace('/@import[^;]*;?/i', '', $css);
        $css = (string) preg_replace('/url\s*\([^)]*\)/i', 'none', $css);
        $css = (string) preg_replace('/expression\s*\([^)]*\)/i', '', $css);
        $css = (string) preg_replace('/behavior\s*:[^;}]*/i', '', $css);

        return str_ireplace('javascript:', '', $css);
    }

    /**
     * Симфони-санитайзер уже отфильтровал схемы, но абсолютные и data-URL
     * могли выжить в зависимости от конфигурации — оставляем только
     * относительные пути внутри /storage/.
     */
    private static function restrictImageSources(string $html): string
    {
        return (string) preg_replace_callback(
            '/(<img\b[^>]*\bsrc=")([^"]*)(")/iu',
            function (array $matches): string {
                $src = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5);

                if (str_starts_with($src, '/storage/') && ! str_contains($src, '..')) {
                    return $matches[0];
                }

                return $matches[1].$matches[3];
            },
            $html,
        );
    }
}
```

Возможные проблемы (проверяй API по vendor/symfony/html-sanitizer, не гадай):
- `withMaxInputLength` обязателен: дефолтный лимит symfony — 20 000 символов с **тихим обрезанием**; тест на 700 строк это ловит.
- Если `allowRelativeMedias()` не пропускает относительный `src` — посмотри `HtmlSanitizerConfig`/`UrlSanitizer` в vendor; альтернатива — `allowRelativeLinks()` не о том, нужна именно Medias. Пост-фильтр `restrictImageSources` в любом случае держит контракт тестов.
- Санитайзер может переписывать сущности в атрибутах — поэтому пост-фильтр декодирует `src` перед проверкой.

- [ ] **Step 5: Прогнать тесты**

Run: `make test test_args="--compact --filter=ReceiptTemplateHtmlSanitizerTest"`
Expected: PASS (5 тестов).

- [ ] **Step 6: Pint и commit**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock app/Support/ReceiptTemplateHtmlSanitizer.php tests/Unit/ReceiptTemplateHtmlSanitizerTest.php
git commit -m "Санитайзер HTML и CSS-фильтр шаблона квитанции

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Каталог переменных, рендерер и файлы дефолтного шаблона

**Files:**
- Create: `app/Support/ReceiptTemplateVariables.php`
- Create: `app/Support/ReceiptTemplateRenderer.php`
- Create: `resources/receipt-templates/default.html`
- Create: `resources/receipt-templates/default.css`
- Create: `resources/views/receipts/fragments/meters-table.blade.php`
- Test: `tests/Unit/ReceiptTemplateRendererTest.php`, `tests/Feature/ReceiptTemplateVariablesTest.php`

**Interfaces:**
- Consumes: модель `Receipt` (снапшот-поля), `App\Actions\BuildReceiptMeterReadingLines::handle(Receipt): list<array{meter_number,previous_reading,current_reading,consumption,tariff_price,amount}>`, `ReceiptTemplateImageStorage::url()`.
- Produces:
  - `ReceiptTemplateVariables::labels(): array<string, string>` — ключ → русское название (25 скалярных + 3 составных, см. код);
  - `ReceiptTemplateVariables::values(Receipt $receipt, string $copyTitle, \Illuminate\Support\Carbon $generatedAt): array<string, string>`;
  - `ReceiptTemplateVariables::fragments(Receipt $receipt, array $meterReadingLines, \Illuminate\Support\Carbon $generatedAt): array<string, string>` — ключи `meters_table`, `logo`, `qr`;
  - `ReceiptTemplateRenderer::render(string $html, array $values, array $fragments): string`;
  - файлы дефолтного шаблона (используются Task 5).

- [ ] **Step 1: Написать падающий тест рендерера `tests/Unit/ReceiptTemplateRendererTest.php`**

```php
<?php

use App\Support\ReceiptTemplateRenderer;

test('renderer substitutes scalars with escaping and fragments as html', function () {
    $html = '<p>{{client_name}} / {{ amount }}</p><div>{{meters_table}}</div><span>{{unknown_key}}</span>';

    $rendered = ReceiptTemplateRenderer::render(
        $html,
        ['client_name' => 'Иванов <b>Иван</b>', 'amount' => '1 800.00 KZT'],
        ['meters_table' => '<table><tr><td>MTR-1</td></tr></table>'],
    );

    expect($rendered)->toContain('Иванов &lt;b&gt;Иван&lt;/b&gt;')
        ->and($rendered)->not->toContain('<b>Иван</b>')
        ->and($rendered)->toContain('1 800.00 KZT')
        ->and($rendered)->toContain('<table><tr><td>MTR-1</td></tr></table>')
        ->and($rendered)->toContain('<span></span>');
});
```

Run: `make test test_args="--compact --filter=ReceiptTemplateRendererTest"` — FAIL (класса нет).

- [ ] **Step 2: Создать `app/Support/ReceiptTemplateRenderer.php`**

```php
<?php

namespace App\Support;

/**
 * Подстановка плейсхолдеров {{key}} в HTML-шаблон квитанции. Скалярные
 * значения экранируются; фрагменты вставляются как HTML — их генерирует
 * только код проекта. Неизвестные ключи заменяются пустой строкой.
 */
final class ReceiptTemplateRenderer
{
    /**
     * @param  array<string, string>  $values
     * @param  array<string, string>  $fragments
     */
    public static function render(string $html, array $values, array $fragments): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/',
            function (array $matches) use ($values, $fragments): string {
                $key = $matches[1];

                if (array_key_exists($key, $fragments)) {
                    return $fragments[$key];
                }

                if (array_key_exists($key, $values)) {
                    return e($values[$key]);
                }

                return '';
            },
            $html,
        );
    }
}
```

Run: `make test test_args="--compact --filter=ReceiptTemplateRendererTest"` — PASS.

- [ ] **Step 3: Написать падающий тест переменных `tests/Feature/ReceiptTemplateVariablesTest.php`**

```php
<?php

use App\Models\Organization;
use App\Models\Receipt;
use App\Support\ReceiptTemplateVariables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('values map covers every scalar variable with formatted data', function () {
    $organization = Organization::factory()->create([
        'name' => 'ТОО Водоканал',
        'bin_iin' => '123456789012',
        'address' => 'Алматы, Абая 10',
    ]);
    $receipt = Receipt::factory()->for($organization)->create([
        'receipt_number' => '202605-100010',
        'account_number' => '100010',
        'client_name' => 'Иванов Иван',
        'billing_type' => 'fixed',
        'amount' => 1800,
        'paid_amount' => 300,
        'opening_balance' => 0,
        'closing_balance' => 1500,
    ]);

    $generatedAt = Carbon::create(2026, 8, 12, 10, 30);
    $values = ReceiptTemplateVariables::values($receipt->fresh(), 'Для абонента', $generatedAt);

    expect($values['organization_name'])->toBe('ТОО Водоканал')
        ->and($values['organization_bin'])->toBe('123456789012')
        ->and($values['account_number'])->toBe('100010')
        ->and($values['client_name'])->toBe('Иванов Иван')
        ->and($values['billing_type'])->toBe('Фиксированная сумма')
        ->and($values['receipt_number'])->toBe('202605-100010')
        ->and($values['amount'])->toBe('1 800.00 KZT')
        ->and($values['paid_amount'])->toBe('300.00 KZT')
        ->and($values['opening_balance'])->toBe('0.00 KZT')
        ->and($values['amount_due'])->toBe('1 500.00 KZT')
        ->and($values['copy_title'])->toBe('Для абонента')
        ->and($values['generated_at'])->toBe('12.08.2026 10:30');

    $scalarKeys = array_keys(array_diff_key(
        ReceiptTemplateVariables::labels(),
        array_flip(['meters_table', 'logo', 'qr']),
    ));

    expect(array_keys($values))->toEqualCanonicalizing($scalarKeys);
});

test('fragments render meters table and organization images', function () {
    $organization = Organization::factory()->create();
    $receipt = Receipt::factory()->for($organization)->create();

    $fragments = ReceiptTemplateVariables::fragments($receipt->fresh(), [
        [
            'meter_number' => 'MTR-1',
            'previous_reading' => '100',
            'current_reading' => '120',
            'consumption' => '20',
            'tariff_price' => '90.00 KZT',
            'amount' => '1 800.00 KZT',
        ],
    ], now());

    expect($fragments['meters_table'])->toContain('Счётчики')
        ->and($fragments['meters_table'])->toContain('MTR-1')
        ->and($fragments['meters_table'])->toContain('Итого')
        ->and($fragments['logo'])->toBe('')
        ->and($fragments['qr'])->toBe('');
});
```

Run: `make test test_args="--compact --filter=ReceiptTemplateVariablesTest"` — FAIL.

- [ ] **Step 4: Создать `app/Support/ReceiptTemplateVariables.php`**

Хелперы форматирования скопированы из `app/Actions/BuildReceiptPrintViewData.php` (money/decimal/value/clientAddress/billingTypeLabel) — в Task 5 экшен перестанет ими пользоваться.

```php
<?php

namespace App\Support;

use App\Models\Receipt;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Каталог переменных HTML-шаблона квитанции: единственный источник правды
 * о доступных плейсхолдерах, их русских названиях и значениях.
 */
final class ReceiptTemplateVariables
{
    /**
     * Ключ => русское название (для палитры редактора). Последние три —
     * составные фрагменты, остальные — скалярные значения.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'organization_name' => 'Название организации',
            'organization_address' => 'Адрес организации',
            'organization_bin' => 'БИН / ИИН',
            'organization_phone' => 'Телефон организации',
            'organization_bank' => 'Банк',
            'organization_iban' => 'IBAN',
            'account_number' => 'Лицевой счёт',
            'client_name' => 'Абонент',
            'client_address' => 'Адрес абонента',
            'period' => 'Период',
            'service_name' => 'Услуга',
            'billing_type' => 'Тип расчёта',
            'receipt_number' => 'Номер квитанции',
            'issued_date' => 'Дата квитанции',
            'generated_at' => 'Сформирована',
            'volume' => 'Объём',
            'unit' => 'Единица измерения',
            'tariff_price' => 'Тариф',
            'amount' => 'Сумма',
            'paid_amount' => 'Оплачено',
            'adjustment_amount' => 'Корректировка',
            'opening_balance' => 'Долг (начальное сальдо)',
            'closing_balance' => 'Конечное сальдо',
            'amount_due' => 'К оплате',
            'copy_title' => 'Название экземпляра',
            'meters_table' => 'Таблица счётчиков',
            'logo' => 'Логотип',
            'qr' => 'QR-код',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function values(Receipt $receipt, string $copyTitle, Carbon $generatedAt): array
    {
        $organization = $receipt->organization;

        return [
            'organization_name' => self::value($organization?->name),
            'organization_address' => self::value($organization?->address),
            'organization_bin' => self::value($organization?->bin_iin),
            'organization_phone' => self::value($organization?->phone),
            'organization_bank' => self::value($organization?->bank),
            'organization_iban' => self::value($organization?->iban),
            'account_number' => self::value($receipt->account_number),
            'client_name' => self::value($receipt->client_name),
            'client_address' => self::clientAddress($receipt),
            'period' => self::value($receipt->billingPeriod?->label ?? $receipt->period),
            'service_name' => self::value($receipt->utility_service_name),
            'billing_type' => self::billingTypeLabel($receipt->billing_type),
            'receipt_number' => self::value($receipt->receipt_number),
            'issued_date' => self::value($receipt->issued_at?->format('d.m.Y')),
            'generated_at' => $generatedAt->format('d.m.Y H:i'),
            'volume' => self::decimal($receipt->volume),
            'unit' => self::value($organization?->utilityService?->unit_of_measurement),
            'tariff_price' => self::money($receipt->tariff_price),
            'amount' => self::money($receipt->amount),
            'paid_amount' => self::money($receipt->paid_amount),
            'adjustment_amount' => self::money($receipt->adjustment_amount),
            'opening_balance' => self::money($receipt->opening_balance),
            'closing_balance' => self::money($receipt->closing_balance),
            'amount_due' => self::money(max(0, (float) $receipt->closing_balance)),
            'copy_title' => $copyTitle,
        ];
    }

    /**
     * @param  list<array{meter_number: string, previous_reading: string, current_reading: string, consumption: string, tariff_price: string, amount: string}>  $meterReadingLines
     * @return array<string, string>
     */
    public static function fragments(Receipt $receipt, array $meterReadingLines, Carbon $generatedAt): array
    {
        $template = $receipt->organization?->receiptTemplate;

        return [
            'meters_table' => view('receipts.fragments.meters-table', [
                'meterReadingLines' => $meterReadingLines,
                'volume' => self::decimal($receipt->volume),
                'amount' => self::money($receipt->amount),
                'generatedAt' => $generatedAt,
            ])->render(),
            'logo' => self::image(ReceiptTemplateImageStorage::url($template?->logo_path), 'Логотип организации', 'rt-logo'),
            'qr' => self::image(ReceiptTemplateImageStorage::url($template?->qr_path), 'QR-код для оплаты', 'rt-qr'),
        ];
    }

    private static function image(?string $url, string $alt, string $class): string
    {
        if (blank($url)) {
            return '';
        }

        return '<img src="'.e($url).'" alt="'.e($alt).'" class="'.$class.'">';
    }

    private static function clientAddress(Receipt $receipt): string
    {
        $client = $receipt->client;

        if (! $client) {
            return '-';
        }

        $parts = array_filter([
            $client->region?->name,
            $client->street?->name,
            filled($client->house) ? 'д. '.$client->house : null,
            filled($client->apartment) ? 'кв. '.$client->apartment : null,
        ], fn (?string $part): bool => filled($part));

        return $parts === [] ? '-' : implode(', ', $parts);
    }

    private static function billingTypeLabel(?string $billingType): string
    {
        return match ($billingType) {
            'fixed' => 'Фиксированная сумма',
            'meter' => 'По счётчику',
            'per_person' => 'На одного человека',
            default => self::value($billingType),
        };
    }

    private static function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 2, '.', ' ').' KZT';
    }

    /**
     * Объём печатается без незначащих нулей: по счётчику он равен сумме целых
     * расходов, поэтому дробная часть выводится, только если она есть.
     */
    private static function decimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return rtrim(rtrim(number_format((float) $value, 4, '.', ' '), '0'), '.');
    }

    private static function value(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('d.m.Y H:i');
        }

        return (string) $value;
    }
}
```

- [ ] **Step 5: Создать фрагмент `resources/views/receipts/fragments/meters-table.blade.php`**

Содержимое — копия текущего `resources/views/receipts/blocks/meters-table.blade.php`, с тремя правками: убрать верхний `@php`-блок (значения `$volume`/`$amount` теперь приходят параметрами), корневой тег `<section class="rt-meters">` вместо `col-span-2`-класса, все Tailwind-классы заменить на классы `rt-*` из `default.css` (см. Step 6): `rt-meters-head`, `rt-meters-title`, `rt-meters-generated`, `rt-table-wrap`, `rt-table`, строки/ячейки без классов (стилизуются селекторами `.rt-table th/td`), итоговая строка `class="rt-row-total"`. Текстовое содержимое (заголовок «Счётчики», «Сформирована: …», колонки, «Итого», «Нет показаний счётчиков») — без изменений.

```blade
<section class="rt-meters">
    <div class="rt-meters-head">
        <h3 class="rt-meters-title">Счётчики</h3>
        <p class="rt-meters-generated">Сформирована: {{ $generatedAt->format('d.m.Y H:i') }}</p>
    </div>

    <div class="rt-table-wrap">
        <table class="rt-table">
            <thead>
                <tr>
                    <th>№ счётчика</th>
                    <th class="rt-num">Предыдущее</th>
                    <th class="rt-num">Текущее</th>
                    <th class="rt-num">Расход</th>
                    <th class="rt-num">Тариф</th>
                    <th class="rt-num">Сумма</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($meterReadingLines as $line)
                    <tr>
                        <td>{{ $line['meter_number'] }}</td>
                        <td class="rt-num">{{ $line['previous_reading'] }}</td>
                        <td class="rt-num">{{ $line['current_reading'] }}</td>
                        <td class="rt-num">{{ $line['consumption'] }}</td>
                        <td class="rt-num">{{ $line['tariff_price'] }}</td>
                        <td class="rt-num rt-strong">{{ $line['amount'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="rt-empty" colspan="6">Нет показаний счётчиков</td>
                    </tr>
                @endforelse

                <tr class="rt-row-total">
                    <td colspan="3">Итого</td>
                    <td class="rt-num">{{ $volume }}</td>
                    <td></td>
                    <td class="rt-num">{{ $amount }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</section>
```

- [ ] **Step 6: Создать файлы дефолтного шаблона**

`resources/receipt-templates/default.html` — воспроизводит стандартную квитанцию. ВАЖНО: порядок текстов обязан удовлетворять существующему тесту печати (copy_title → заголовок → организация → Номер/Период/Дата → Лицевой счёт… → Реквизиты → Счётчики → Итого → Долг → Оплачено → К оплате):

```html
<div class="rt-copy">
    <header class="rt-header">
        <div class="rt-header-main">
            <p class="rt-copy-title">{{copy_title}}</p>
            <p class="rt-doc-title">Квитанция на оплату коммунальной услуги</p>
            <h2 class="rt-org-name">{{organization_name}}</h2>
            <p class="rt-org-address">{{organization_address}}</p>
        </div>
        <div class="rt-header-aside">
            {{logo}}
            <dl class="rt-meta">
                <dt>Номер</dt>
                <dd>{{receipt_number}}</dd>
                <dt>Период</dt>
                <dd>{{period}}</dd>
                <dt>Дата</dt>
                <dd>{{issued_date}}</dd>
            </dl>
        </div>
    </header>

    <div class="rt-columns">
        <section class="rt-details">
            <h3>Абонент</h3>
            <dl>
                <dt>Лицевой счёт</dt>
                <dd>{{account_number}}</dd>
                <dt>Абонент</dt>
                <dd>{{client_name}}</dd>
                <dt>Адрес</dt>
                <dd>{{client_address}}</dd>
                <dt>Период</dt>
                <dd>{{period}}</dd>
                <dt>Услуга</dt>
                <dd>{{service_name}}</dd>
                <dt>Тип расчёта</dt>
                <dd>{{billing_type}}</dd>
            </dl>
        </section>

        <section class="rt-details">
            <h3>Реквизиты</h3>
            <dl>
                <dt>Организация</dt>
                <dd>{{organization_name}}</dd>
                <dt>БИН / ИИН</dt>
                <dd>{{organization_bin}}</dd>
                <dt>Телефон</dt>
                <dd>{{organization_phone}}</dd>
                <dt>Адрес</dt>
                <dd>{{organization_address}}</dd>
                <dt>Банк</dt>
                <dd>{{organization_bank}}</dd>
                <dt>IBAN</dt>
                <dd>{{organization_iban}}</dd>
            </dl>
        </section>
    </div>

    {{meters_table}}

    <section class="rt-table-wrap rt-totals">
        <table class="rt-table">
            <tbody>
                <tr>
                    <td>Долг</td>
                    <td class="rt-num rt-totals-value">{{opening_balance}}</td>
                </tr>
                <tr>
                    <td>Оплачено</td>
                    <td class="rt-num">{{paid_amount}}</td>
                </tr>
                <tr class="rt-row-total">
                    <td>К оплате</td>
                    <td class="rt-num">{{amount_due}}</td>
                </tr>
            </tbody>
        </table>
    </section>
</div>
```

`resources/receipt-templates/default.css` — обычный CSS без Tailwind (шаблоны пользователей не проходят компиляцию Tailwind, поэтому и дефолт на чистом CSS):

```css
.rt-copy {
    font-size: 10px;
    line-height: 1.3;
    color: #09090b;
}

.rt-header {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px solid #18181b;
    padding-bottom: 8px;
}

.rt-copy-title,
.rt-doc-title {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.14em;
    color: #71717a;
    margin: 0 0 4px;
}

.rt-org-name {
    font-size: 14px;
    font-weight: 700;
    margin: 4px 0 2px;
}

.rt-org-address {
    color: #52525b;
    margin: 0;
}

.rt-header-aside {
    display: flex;
    gap: 8px;
    align-items: flex-start;
}

.rt-logo,
.rt-qr {
    width: 40px;
    height: 40px;
    object-fit: contain;
}

.rt-meta {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 2px 6px;
    margin: 0;
    min-width: 130px;
}

.rt-meta dt,
.rt-details dt {
    color: #71717a;
}

.rt-meta dd,
.rt-details dd {
    font-weight: 600;
    margin: 0;
}

.rt-columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    border-bottom: 1px solid #d4d4d8;
    padding: 8px 0;
}

.rt-details h3,
.rt-meters-title {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin: 0 0 4px;
}

.rt-details dl {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 2px 6px;
    font-size: 9px;
    margin: 0;
}

.rt-meters {
    margin-top: 8px;
}

.rt-meters-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}

.rt-meters-generated {
    font-size: 8px;
    color: #71717a;
    margin: 0;
}

.rt-table-wrap {
    margin-top: 4px;
    border: 1px solid #18181b;
    border-radius: 6px;
    overflow: hidden;
}

.rt-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 9px;
    text-align: left;
}

.rt-table th,
.rt-table td {
    border: 1px solid #18181b;
    padding: 3px 6px;
}

.rt-table thead th {
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #52525b;
    background: #f4f4f5;
}

.rt-num {
    text-align: right;
}

.rt-strong {
    font-weight: 700;
}

.rt-empty {
    text-align: center;
    color: #71717a;
}

.rt-row-total td {
    font-weight: 700;
    background: #fafafa;
}

.rt-totals {
    margin-top: 8px;
}
```

- [ ] **Step 7: Прогнать тесты**

Run: `make test test_args="--compact --filter='ReceiptTemplateRendererTest|ReceiptTemplateVariablesTest'"`
Expected: PASS.

- [ ] **Step 8: Pint и commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/ReceiptTemplateVariables.php app/Support/ReceiptTemplateRenderer.php resources/receipt-templates resources/views/receipts/fragments tests/Unit/ReceiptTemplateRendererTest.php tests/Feature/ReceiptTemplateVariablesTest.php
git commit -m "Каталог переменных, рендерер и дефолтный HTML-шаблон квитанции

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Миграция колонок html/css/copies_per_page

**Files:**
- Create: `database/migrations/<timestamp>_add_html_template_to_receipt_templates_table.php`
- Modify: `app/Models/ReceiptTemplate.php` (Fillable + cast)
- Modify: `database/factories/ReceiptTemplateFactory.php`
- Test: `tests/Feature/ReceiptTemplateTest.php` (добавить тест миграционных полей)

**Interfaces:**
- Produces: колонки `html` (longText nullable), `css` (text nullable), `copies_per_page` (unsignedTinyInteger default 2, cast int); перенос `settings->appearance->copies_per_page` в колонку. Колонка `settings` пока ОСТАЁТСЯ (её удаляет Task 5, когда код перестанет её читать).

- [ ] **Step 1: Добавить падающий тест в `tests/Feature/ReceiptTemplateTest.php`**

```php
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
```

Run: `make test test_args="--compact --filter=ReceiptTemplateTest"` — новый тест FAIL, остальные PASS.

- [ ] **Step 2: Создать миграцию**

```bash
php artisan make:migration add_html_template_to_receipt_templates_table --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_templates', function (Blueprint $table) {
            $table->longText('html')->nullable()->after('settings');
            $table->text('css')->nullable()->after('html');
            $table->unsignedTinyInteger('copies_per_page')->default(2)->after('css');
        });

        foreach (DB::table('receipt_templates')->get(['id', 'settings']) as $row) {
            $settings = json_decode((string) $row->settings, true);
            $copies = $settings['appearance']['copies_per_page'] ?? 2;

            DB::table('receipt_templates')
                ->where('id', $row->id)
                ->update(['copies_per_page' => in_array($copies, [1, 2], true) ? $copies : 2]);
        }
    }

    public function down(): void
    {
        Schema::table('receipt_templates', function (Blueprint $table) {
            $table->dropColumn(['html', 'css', 'copies_per_page']);
        });
    }
};
```

- [ ] **Step 3: Обновить модель и фабрику**

`app/Models/ReceiptTemplate.php`: в `#[Fillable]` добавить `html`, `css`, `copies_per_page`; в `casts()` добавить `'copies_per_page' => 'integer'`.

`database/factories/ReceiptTemplateFactory.php`: добавить `'html' => null, 'css' => null, 'copies_per_page' => 2` (поле `settings` пока оставить).

- [ ] **Step 4: Прогнать тесты**

Run: `make test test_args="--compact --filter=ReceiptTemplateTest"`
Expected: PASS (все, включая новый).

- [ ] **Step 5: Pint и commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/ReceiptTemplate.php database/factories/ReceiptTemplateFactory.php tests/Feature/ReceiptTemplateTest.php
git commit -m "Колонки html, css и copies_per_page в шаблоне квитанции

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Перевод печати и страницы на HTML-шаблон, удаление блочного кода v1

Самая крупная задача: сервер целиком переходит на HTML-шаблоны. Редактор здесь ещё не визуальный — html/css живут в Livewire-свойствах страницы (GrapesJS подключит Task 6), но сохранение/предпросмотр/сброс полностью рабочие и протестированы.

**Files:**
- Modify: `app/Actions/BuildReceiptPrintViewData.php` (полная замена)
- Modify: `app/Support/ReceiptTemplateDefaults.php` (полная замена: `html()`/`css()` из файлов)
- Delete: `app/Support/ReceiptTemplateConfig.php`, `tests/Unit/ReceiptTemplateConfigTest.php`
- Delete: `resources/views/receipts/blocks/` (все 6 файлов; таблица счётчиков уже переехала во fragments в Task 3)
- Modify: `resources/views/receipts/partials/print-copy.blade.php`, `resources/views/receipts/print.blade.php`, `resources/views/receipts/bulk-print.blade.php`
- Modify: `app/Filament/Pages/ReceiptTemplatePage.php` (полная замена), `resources/views/filament/pages/receipt-template-page.blade.php`
- Create: `database/migrations/<timestamp>_drop_settings_from_receipt_templates_table.php`
- Modify: `database/factories/ReceiptTemplateFactory.php` (убрать `settings`)
- Modify: `resources/css/receipt.css` (удалить классы v1), пересборка фронтенда
- Modify: `tests/Feature/ReceiptTemplateTest.php`, `tests/Feature/ReceiptTemplatePageTest.php`, `tests/Feature/ReceiptTest.php`

**Interfaces:**
- Consumes: `ReceiptTemplateHtmlSanitizer` (Task 2), `ReceiptTemplateVariables`/`ReceiptTemplateRenderer`/дефолтные файлы (Task 3), колонки БД (Task 4).
- Produces:
  - `BuildReceiptPrintViewData::handle(Receipt $receipt): array{receipt: Receipt, generatedAt: Carbon, copiesPerPage: int, renderedCopies: array<string, string>, templateCss: string}`;
  - `ReceiptTemplateDefaults::html(): string`, `ReceiptTemplateDefaults::css(): string`;
  - страница: публичные свойства `?string $templateHtml`, `?string $templateCss`, форма только `copies_per_page` + FileUpload'ы, методы `save()`, `resetTemplate()`, `previewHtml(): HtmlString`.

- [ ] **Step 1: Переписать тесты (падающие)**

**`tests/Feature/ReceiptTest.php`** — в тесте `admin users can open a current tenant receipt print view` заменить список `assertViewHasAll([...])` на:

```php
->assertViewHasAll([
    'receipt',
    'generatedAt',
    'copiesPerPage',
    'renderedCopies',
    'templateCss',
])
```

Остальные проверки (порядок текстов, `data-receipt-copy=`, `receipt-sheet`) НЕ менять — дефолтный шаблон обязан их удовлетворять.

**`tests/Feature/ReceiptTemplateTest.php`** — блочные print-тесты v1 заменить на v2 (хелпер `actingAsTemplateTenant` и модельные тесты сохранить; тест `receipt template belongs...` больше не сравнивает `settings`: заменить ожидание на `->and($template->html)->toBeNull()`):

```php
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
```

Удалить блочные тесты v1: `print without a template renders default blocks in default order`, `template controls block order visibility and texts on print`, `single copy template prints only the client copy`, `appearance settings add css classes to receipt copies`, `bulk print applies each organization template`, `logo and qr render on print when enabled and uploaded`.

**`tests/Feature/ReceiptTemplatePageTest.php`** — тесты доступа (админ/контроллер), tamper-тесты путей файлов и тест `reset deletes the template` сохранить как есть; блочные тесты формы заменить на:

```php
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

test('preview reflects unsaved template html', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->set('templateHtml', '<h1>Мой заголовок квитанции</h1>')
        ->assertSee('Мой заголовок квитанции');
});

test('page opens with the default template in the editor state', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->assertSet('templateHtml', App\Support\ReceiptTemplateDefaults::html())
        ->assertSee('Квитанция на оплату коммунальной услуги');
});
```

Удалить тесты v1: `saving the form creates a template for the current tenant`, `saving reordered and disabled blocks persists them in order`, `preview reflects unsaved form state`.

Сохранить и адаптировать два тестa предпросмотра v1:
- `preview uses the latest tenant receipt when available` — остаётся как есть (дефолтный шаблон выводит `{{client_name}}`, поэтому `assertSee('Иванов Иван')` продолжает работать);
- `preview falls back to demo data when the tenant has no receipts` — остаётся как есть (`assertSee('100001')`: демо-квитанция не меняется, дефолтный шаблон выводит `{{account_number}}`).

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `make test test_args="--compact --filter='ReceiptTemplateTest|ReceiptTemplatePageTest|ReceiptTest'"`
Expected: FAIL (новое поведение не реализовано).

- [ ] **Step 3: Переписать `app/Support/ReceiptTemplateDefaults.php`**

```php
<?php

namespace App\Support;

/**
 * Дефолтный HTML-шаблон квитанции: используется печатью при отсутствии
 * сохранённого шаблона, редактором как стартовое содержимое и сбросом.
 */
final class ReceiptTemplateDefaults
{
    public static function html(): string
    {
        return (string) file_get_contents(resource_path('receipt-templates/default.html'));
    }

    public static function css(): string
    {
        return (string) file_get_contents(resource_path('receipt-templates/default.css'));
    }
}
```

- [ ] **Step 4: Переписать `app/Actions/BuildReceiptPrintViewData.php`**

```php
<?php

namespace App\Actions;

use App\Models\Receipt;
use App\Support\ReceiptTemplateDefaults;
use App\Support\ReceiptTemplateHtmlSanitizer;
use App\Support\ReceiptTemplateRenderer;
use App\Support\ReceiptTemplateVariables;
use Illuminate\Support\Carbon;

class BuildReceiptPrintViewData
{
    public function __construct(
        private readonly BuildReceiptMeterReadingLines $buildReceiptMeterReadingLines,
    ) {}

    /**
     * @return array{
     *     receipt: Receipt,
     *     generatedAt: Carbon,
     *     copiesPerPage: int,
     *     renderedCopies: array<string, string>,
     *     templateCss: string
     * }
     */
    public function handle(Receipt $receipt): array
    {
        $receipt->loadMissing([
            'billingPeriod',
            'client.region',
            'client.street',
            'organization.utilityService',
            'organization.receiptTemplate',
        ]);

        $template = $receipt->organization?->receiptTemplate;
        $generatedAt = now();

        $hasCustomTemplate = filled($template?->html);
        $html = ReceiptTemplateHtmlSanitizer::sanitizeHtml(
            $hasCustomTemplate ? (string) $template->html : ReceiptTemplateDefaults::html(),
        );
        $css = ReceiptTemplateHtmlSanitizer::sanitizeCss(
            $hasCustomTemplate ? (string) $template->css : ReceiptTemplateDefaults::css(),
        );

        $copiesPerPage = $template?->copies_per_page === 1 ? 1 : 2;
        $copyTitles = $copiesPerPage === 1
            ? ['Для абонента']
            : ['Для организации', 'Для абонента'];

        $fragments = ReceiptTemplateVariables::fragments(
            $receipt,
            $this->buildReceiptMeterReadingLines->handle($receipt),
            $generatedAt,
        );

        $renderedCopies = [];

        foreach ($copyTitles as $copyTitle) {
            $renderedCopies[$copyTitle] = ReceiptTemplateRenderer::render(
                $html,
                ReceiptTemplateVariables::values($receipt, $copyTitle, $generatedAt),
                $fragments,
            );
        }

        return [
            'receipt' => $receipt,
            'generatedAt' => $generatedAt,
            'copiesPerPage' => $copiesPerPage,
            'renderedCopies' => $renderedCopies,
            'templateCss' => $css,
        ];
    }
}
```

- [ ] **Step 5: Переписать вьюхи печати**

`resources/views/receipts/partials/print-copy.blade.php`:

```blade
<article
    class="receipt-copy rounded-xl border border-zinc-900 bg-white p-4 shadow-sm print:rounded-none print:p-2 print:shadow-none"
    data-receipt-copy="{{ $copyTitle }}"
>
    {!! $renderedCopy !!}
</article>
```

(`$renderedCopy` — уже санитизированный и отрендеренный на сервере HTML.)

`resources/views/receipts/print.blade.php`: в `<head>` после `@vite(...)` добавить `<style>{!! $templateCss !!}</style>`; секцию копий заменить на:

```blade
<section class="receipt-sheet {{ $copiesPerPage === 1 ? 'receipt-sheet-single' : '' }}">
    @foreach ($renderedCopies as $copyTitle => $renderedCopy)
        @include('receipts.partials.print-copy', ['copyTitle' => $copyTitle, 'renderedCopy' => $renderedCopy])
    @endforeach
</section>
```

Поясняющий текст под заголовком: `{{ $copiesPerPage === 1 ? 'На листе A5 печатается один экземпляр для абонента.' : 'На одном листе A5 печатаются два экземпляра: для организации и для абонента.' }}`.

`resources/views/receipts/bulk-print.blade.php`: в `<head>` `<style>{!! ($receiptPrintData[0]['templateCss'] ?? '') !!}</style>` (у всех квитанций одна организация — CSS одинаков); цикл:

```blade
@forelse ($receiptPrintData as $printData)
    <section class="receipt-sheet receipt-sheet-bulk {{ $printData['copiesPerPage'] === 1 ? 'receipt-sheet-single' : '' }}">
        @foreach ($printData['renderedCopies'] as $copyTitle => $renderedCopy)
            @include('receipts.partials.print-copy', ['copyTitle' => $copyTitle, 'renderedCopy' => $renderedCopy])
        @endforeach
    </section>
@empty
```

Удалить каталог `resources/views/receipts/blocks/` целиком (`git rm -r`).

- [ ] **Step 6: Переписать `app/Filament/Pages/ReceiptTemplatePage.php`**

Сохранить без изменений: атрибуты навигации/slug/view, `canAccess()`, `tenantOrFail()`, `getTemplate()`, `previewReceipt()` (демо-квитанцию), `ensureFilePathsBelongToTenant()`, `filePathBelongsToTenant()`, FileUpload-поля с `preventFilePathTampering` и логику удаления заменённых файлов в `save()`, header-action сброса.

Заменить: свойство `$data` теперь хранит только `copies_per_page`, `logo_path`, `qr_path`; добавить публичные свойства и новые методы:

```php
public ?string $templateHtml = null;

public ?string $templateCss = null;
```

`mount()`:

```php
public function mount(): void
{
    $this->fillFromTemplate($this->getTemplate());
}

protected function fillFromTemplate(?ReceiptTemplate $template): void
{
    $this->templateHtml = filled($template?->html) ? $template->html : ReceiptTemplateDefaults::html();
    $this->templateCss = filled($template?->html) ? (string) $template->css : ReceiptTemplateDefaults::css();

    $this->form->fill([
        'copies_per_page' => $template?->copies_per_page === 1 ? 1 : 2,
        'logo_path' => $template?->logo_path,
        'qr_path' => $template?->qr_path,
    ]);
}
```

`form()` — та же структура `Form::make([...])->livewireSubmitHandler('save')->footer(...)` со `statePath('data')`, но компоненты только:

```php
Section::make('Настройки печати')
    ->schema([
        Radio::make('copies_per_page')
            ->label('Экземпляров на листе')
            ->options([
                2 => 'Два: для организации и для абонента',
                1 => 'Один: только для абонента',
            ])
            ->live(),
    ]),
Section::make('Изображения')
    ->description('Вставляются в шаблон плейсхолдерами {{logo}} и {{qr}}.')
    ->schema([
        // FileUpload logo_path и qr_path — БЕЗ изменений против текущего кода
    ]),
```

`save()`:

```php
public function save(): void
{
    $data = $this->form->getState();
    $tenant = $this->tenantOrFail();

    $this->ensureFilePathsBelongToTenant($data, $tenant);

    $rawHtml = (string) $this->templateHtml;
    $rawCss = (string) $this->templateCss;

    $errors = [];

    if (strlen($rawHtml) > ReceiptTemplateHtmlSanitizer::MAX_HTML_BYTES) {
        $errors['templateHtml'] = 'Шаблон слишком большой: HTML не может превышать 64 КБ.';
    }

    if (strlen($rawCss) > ReceiptTemplateHtmlSanitizer::MAX_CSS_BYTES) {
        $errors['templateCss'] = 'Стили слишком большие: CSS не может превышать 32 КБ.';
    }

    if ($errors !== []) {
        throw ValidationException::withMessages($errors);
    }

    $existing = $this->getTemplate();
    $oldLogoPath = $existing?->logo_path;
    $oldQrPath = $existing?->qr_path;

    $template = ReceiptTemplate::query()->updateOrCreate(
        ['organization_id' => $tenant->getKey()],
        [
            'html' => ReceiptTemplateHtmlSanitizer::sanitizeHtml($rawHtml),
            'css' => ReceiptTemplateHtmlSanitizer::sanitizeCss($rawCss),
            'copies_per_page' => ((int) ($data['copies_per_page'] ?? 2)) === 1 ? 1 : 2,
            'logo_path' => $data['logo_path'] ?? null,
            'qr_path' => $data['qr_path'] ?? null,
        ],
    );

    // удаление заменённых файлов и уведомление — как в текущем коде
    // затем: $this->fillFromTemplate($template);
}
```

`resetTemplate()` — как сейчас (delete + refill через `fillFromTemplate(null)` + уведомление).

`previewHtml()`:

```php
public function previewHtml(): HtmlString
{
    $tenant = $this->tenantOrFail();
    $generatedAt = now();
    $receipt = $this->previewReceipt($tenant);

    $html = ReceiptTemplateHtmlSanitizer::sanitizeHtml((string) $this->templateHtml);
    $css = ReceiptTemplateHtmlSanitizer::sanitizeCss((string) $this->templateCss);

    $rendered = ReceiptTemplateRenderer::render(
        $html,
        ReceiptTemplateVariables::values($receipt, 'Предпросмотр', $generatedAt),
        ReceiptTemplateVariables::fragments(
            $receipt,
            app(BuildReceiptMeterReadingLines::class)->handle($receipt),
            $generatedAt,
        ),
    );

    return new HtmlString('<style>'.$css.'</style><article class="receipt-copy" data-receipt-copy="Предпросмотр">'.$rendered.'</article>');
}
```

Импорты обновить (`BuildReceiptMeterReadingLines`, `ReceiptTemplateHtmlSanitizer`, `ReceiptTemplateRenderer`, `ReceiptTemplateVariables`, `ReceiptTemplateDefaults`); убрать неиспользуемые (`Repeater`, `Toggle`, `Hidden`, `Select`, `Textarea`, `TextInput`, `Get`, `ReceiptTemplateConfig`, `BuildReceiptPrintViewData`). Метод `previewReceipt()` больше не должен вызывать `loadMissing(['utilityService', 'receiptTemplate'])` на связи ради v1-конфига — оставить как есть (он безвреден) или упростить; `settingsFromForm()`, `labelInputs()`, `fillFormFromTemplate()` удалить.

- [ ] **Step 7: Обновить вью страницы `resources/views/filament/pages/receipt-template-page.blade.php`**

```blade
<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
        <div>
            {{ $this->form }}
        </div>

        <div class="space-y-6">
            <div
                id="receipt-template-editor"
                wire:ignore
                class="min-h-[24rem] rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900"
            ></div>

            <div>
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

(Контейнер редактора пока пустой — GrapesJS придёт в Task 6.)

- [ ] **Step 8: Удалить блочный код v1 и колонку settings**

- `git rm app/Support/ReceiptTemplateConfig.php tests/Unit/ReceiptTemplateConfigTest.php`
- `git rm -r resources/views/receipts/blocks`
- Из `resources/css/receipt.css` удалить правила `.receipt-copy.receipt-font-compact`, `.receipt-copy.receipt-font-large`, `.receipt-copy.receipt-density-compact ...`, `.receipt-copy.receipt-density-large ...`, `.receipt-no-borders ...` (правило `.receipt-sheet-single` и всё остальное оставить).
- Из `database/factories/ReceiptTemplateFactory.php` убрать поле `settings` (и импорт `ReceiptTemplateDefaults`, если не используется).
- Новая миграция:

```bash
php artisan make:migration drop_settings_from_receipt_templates_table --no-interaction
```

```php
public function up(): void
{
    Schema::table('receipt_templates', function (Blueprint $table) {
        $table->dropColumn('settings');
    });
}

public function down(): void
{
    Schema::table('receipt_templates', function (Blueprint $table) {
        $table->json('settings')->nullable()->after('organization_id');
    });
}
```

- Из модели `ReceiptTemplate` убрать `settings` из `#[Fillable]` и `casts()`.

- [ ] **Step 9: Пересобрать фронтенд и прогнать тесты**

```bash
npm run build
```

Run: `make test test_args="--compact --filter='ReceiptTemplateTest|ReceiptTemplatePageTest|ReceiptTemplateVariablesTest|ReceiptTest'"`
Expected: PASS.

Возможные проблемы:
- Порядок текстов в `ReceiptTest` держится на `default.html` (Task 3): если тест падает на порядке — правь дефолтный шаблон, а не тест.
- `grep -rn "ReceiptTemplateConfig\|receipts.blocks\|settingsFromForm" app resources tests` — не должно остаться ни одного упоминания.

- [ ] **Step 10: Полный регресс, Pint и commit**

Run: `make test` — все зелёные.

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/BuildReceiptPrintViewData.php app/Support/ReceiptTemplateDefaults.php app/Filament/Pages/ReceiptTemplatePage.php resources/views/receipts resources/views/filament/pages/receipt-template-page.blade.php resources/css/receipt.css database/migrations database/factories/ReceiptTemplateFactory.php app/Models/ReceiptTemplate.php tests/Feature/ReceiptTemplateTest.php tests/Feature/ReceiptTemplatePageTest.php tests/Feature/ReceiptTest.php public/build
git rm app/Support/ReceiptTemplateConfig.php tests/Unit/ReceiptTemplateConfigTest.php 2>/dev/null || true
git commit -m "Печать и страница шаблона квитанции переведены на HTML-шаблон с переменными

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

(Если `public/build` в .gitignore — не добавлять его; проверь `git check-ignore public/build`.)

---

### Task 6: Редактор GrapesJS

**Files:**
- Modify: `package.json` / `package-lock.json` (`npm install grapesjs`)
- Modify: `vite.config.js` (новый input)
- Create: `resources/js/receipt-template-editor.js`
- Modify: `resources/views/filament/pages/receipt-template-page.blade.php`
- Modify: `app/Filament/Pages/ReceiptTemplatePage.php` (метод `editorConfig(): array`)
- Test: `tests/Feature/ReceiptTemplatePageTest.php` (один smoke-тест)

**Interfaces:**
- Consumes: свойства страницы `templateHtml`/`templateCss`, `save()`; `ReceiptTemplateVariables::labels()`; `ReceiptTemplateDefaults::html()/css()`.
- Produces: рабочий визуальный редактор; JS вызывает `$wire.set('templateHtml', ...)`, `$wire.set('templateCss', ...)`, `$wire.call('save')`.

- [ ] **Step 1: Установить GrapesJS и подключить input**

```bash
npm install grapesjs
```

В `vite.config.js` в массив `input` добавить `'resources/js/receipt-template-editor.js'`.

- [ ] **Step 2: Smoke-тест (падающий)**

В `tests/Feature/ReceiptTemplatePageTest.php`:

```php
test('page exposes the editor container and config', function () {
    $organization = Organization::factory()->create();
    $user = actingAsTemplatePageAdmin($organization);
    $this->actingAs($user);

    Livewire::test(ReceiptTemplatePage::class)
        ->assertSee('receipt-template-editor', false)
        ->assertSee('Лицевой счёт')
        ->assertSee('Таблица счётчиков');
});
```

Run: `make test test_args="--compact --filter=ReceiptTemplatePageTest"` — новый тест FAIL (нет конфига с названиями переменных).

- [ ] **Step 3: Метод конфига на странице**

В `ReceiptTemplatePage` добавить:

```php
/**
 * Конфигурация редактора для JS: переменные и стартовое содержимое.
 *
 * @return array{variables: list<array{key: string, label: string}>, defaultHtml: string, defaultCss: string}
 */
public function editorConfig(): array
{
    $variables = [];

    foreach (ReceiptTemplateVariables::labels() as $key => $label) {
        $variables[] = ['key' => $key, 'label' => $label];
    }

    return [
        'variables' => $variables,
        'defaultHtml' => ReceiptTemplateDefaults::html(),
        'defaultCss' => ReceiptTemplateDefaults::css(),
    ];
}
```

- [ ] **Step 4: Создать `resources/js/receipt-template-editor.js`**

```js
import grapesjs from 'grapesjs';
import 'grapesjs/dist/css/grapes.min.css';

const FRAGMENT_KEYS = ['meters_table', 'logo', 'qr'];

function chipHtml(key, label) {
    return `<span class="rt-var" title="${label}">{{${key}}}</span>`;
}

window.initReceiptTemplateEditor = function (container, config, getState) {
    const editor = grapesjs.init({
        container,
        height: '640px',
        fromElement: false,
        storageManager: false,
        undoManager: true,
        i18n: {},
        canvas: {
            styles: [],
        },
        blockManager: {
            blocks: buildBlocks(config.variables),
        },
    });

    const state = getState();
    editor.setComponents(state.html || config.defaultHtml);
    editor.setStyle(state.css || config.defaultCss);

    return editor;
};

function buildBlocks(variables) {
    const blocks = [
        {
            id: 'rt-text',
            label: 'Текст',
            category: 'Элементы',
            content: '<p>Введите текст</p>',
        },
        {
            id: 'rt-heading',
            label: 'Заголовок',
            category: 'Элементы',
            content: '<h2>Заголовок</h2>',
        },
        {
            id: 'rt-columns',
            label: 'Две колонки',
            category: 'Элементы',
            content: '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div><p>Колонка 1</p></div><div><p>Колонка 2</p></div></div>',
        },
        {
            id: 'rt-divider',
            label: 'Разделитель',
            category: 'Элементы',
            content: '<hr>',
        },
    ];

    for (const variable of variables) {
        blocks.push({
            id: `rt-var-${variable.key}`,
            label: variable.label,
            category: FRAGMENT_KEYS.includes(variable.key) ? 'Фрагменты' : 'Переменные',
            content: FRAGMENT_KEYS.includes(variable.key)
                ? `<div>{{${variable.key}}}</div>`
                : chipHtml(variable.key, variable.label),
        });
    }

    return blocks;
}
```

- [ ] **Step 5: Подключить редактор во вью страницы**

В `receipt-template-page.blade.php` заменить пустой контейнер редактора на блок с инициализацией и кнопками:

```blade
<div
    wire:ignore
    x-data="{
        editor: null,
        init() {
            this.editor = window.initReceiptTemplateEditor(
                this.$refs.canvas,
                @js($this->editorConfig()),
                () => ({ html: @js($this->templateHtml), css: @js($this->templateCss) }),
            );
        },
        async apply() {
            await this.$wire.set('templateHtml', this.editor.getHtml(), false);
            await this.$wire.set('templateCss', this.editor.getCss(), false);
        },
        async applyAndSave() {
            await this.apply();
            await this.$wire.call('save');
        },
        async applyAndPreview() {
            await this.apply();
            await this.$wire.$refresh();
        },
    }"
>
    <div class="mb-3 flex flex-wrap gap-3">
        <x-filament::button x-on:click="applyAndSave">Сохранить шаблон</x-filament::button>
        <x-filament::button color="gray" x-on:click="applyAndPreview">Обновить предпросмотр</x-filament::button>
    </div>

    <div x-ref="canvas" class="rounded-xl border border-gray-200 dark:border-white/10"></div>
</div>
```

и в конец вью добавить `@vite('resources/js/receipt-template-editor.js')` (или в `<head>` через `@push`, если у страницы есть стек — проверь layout Filament; допустимо просто вывести директиву внутри вью).

- [ ] **Step 6: Сборка, тесты, ручная проверка**

```bash
npm run build
```

Run: `make test test_args="--compact --filter=ReceiptTemplatePageTest"` — PASS.

Ручная проверка в браузере (контролёр сессии выполнит после ревью): страница `/admin/{id}/receipt-template` — канва с дефолтной квитанцией, перетаскивание переменной из палитры, «Обновить предпросмотр», «Сохранить шаблон», печать квитанции с изменённым шаблоном.

Возможные проблемы:
- `$wire.set(..., false)` — третий аргумент отключает мгновенный запрос; проверь сигнатуру Livewire 4 (`$wire.set(name, value, live = true)`).
- GrapesJS CSS может конфликтовать со стилями панели — канва в `wire:ignore`-контейнере, при проблемах ограничь высоту/изоляцию контейнера.
- Если `@js($this->editorConfig())` слишком велик для атрибута — вынеси в `<script type="application/json">` и читай из DOM.

- [ ] **Step 7: Pint и commit**

```bash
vendor/bin/pint --dirty --format agent
git add package.json package-lock.json vite.config.js resources/js/receipt-template-editor.js resources/views/filament/pages/receipt-template-page.blade.php app/Filament/Pages/ReceiptTemplatePage.php tests/Feature/ReceiptTemplatePageTest.php
git commit -m "Визуальный редактор GrapesJS для HTML-шаблона квитанции

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: Документация, changelog, design-preview, полный прогон

**Files:**
- Modify: `docs/modules/receipts.md`
- Modify: `docs/changelog.md`
- Modify: `resources/views/design-preview.blade.php`

- [ ] **Step 1: Обновить `docs/modules/receipts.md`**

Абзац о шаблоне в конце раздела «Правила» заменить на:

```markdown
Печатная форма квитанции строится по HTML-шаблону организации с плейсхолдерами `{{переменная}}` (модуль «Шаблон квитанции», `docs/modules/receipt-template.md`). Шаблон редактируется визуальным конструктором; если он не настроен, используется стандартный шаблон: два экземпляра на листе, шапка, абонент и реквизиты, таблица счётчиков, итоги.
```

- [ ] **Step 2: Добавить запись в `docs/changelog.md`**

После `# Changelog` добавить секцию `## 2026-08-12` (если её нет):

```markdown
## 2026-08-12

### Changed

- Конструктор шаблона квитанции полностью переработан: вместо фиксированных блоков — свободный HTML-шаблон с плейсхолдерами `{{переменная}}` и визуальным редактором (GrapesJS) с перетаскиванием элементов. Палитра содержит все данные квитанции (организация, абонент, суммы), таблицу счётчиков, логотип и QR-код; предпросмотр показывает шаблон на реальных данных. HTML санитизируется на сервере при сохранении и печати (белый список тегов, запрет скриптов и внешних ресурсов), значения подставляются с экранированием. Прежние блочные настройки упразднены; сохранены число экземпляров на листе и загрузка логотипа/QR. Без настроенного шаблона квитанция печатается в стандартном виде, как раньше.
```

- [ ] **Step 3: Обновить секцию в `/design-preview`**

В `resources/views/design-preview.blade.php` найти секцию «Конструктор шаблона квитанции» и заменить тексты её двух карточек (разметку карточек и обёртку секции сохранить):

- карточка 1, заголовок «Стандартный шаблон»: «HTML-шаблон по умолчанию с плейсхолдерами {{переменная}}: шапка, абонент и реквизиты, таблица счётчиков, итоги. Используется, пока организация не сохранила свой шаблон, и служит стартовым содержимым редактора.»
- карточка 2, заголовок «Свой шаблон»: «Визуальный редактор GrapesJS на странице «Шаблон квитанции» (ReceiptTemplatePage): перетаскивание элементов и переменных из палитры, правка текста на холсте, свои стили. Сервер санитизирует HTML и подставляет значения с экранированием; предпросмотр — на последней квитанции организации.»

В описании секции упоминание блоков/тумблеров заменить на описание редактора с перетаскиванием.

- [ ] **Step 4: Полный прогон**

Run: `make test`
Expected: PASS.

- [ ] **Step 5: Pint и финальный commit**

```bash
vendor/bin/pint --dirty --format agent
git add docs/modules/receipts.md docs/changelog.md resources/views/design-preview.blade.php
git commit -m "Документация и design-preview для HTML-шаблона квитанции

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```
