<?php

namespace App\Filament\Pages;

use App\Actions\BuildReceiptMeterReadingLines;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\ReceiptTemplate;
use App\Models\User;
use App\Support\ReceiptTemplateDefaults;
use App\Support\ReceiptTemplateHtmlSanitizer;
use App\Support\ReceiptTemplateImageStorage;
use App\Support\ReceiptTemplateRenderer;
use App\Support\ReceiptTemplateVariables;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
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

    public ?string $templateHtml = null;

    public ?string $templateCss = null;

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

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
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
                            FileUpload::make('logo_path')
                                ->label('Логотип')
                                ->disk(ReceiptTemplateImageStorage::disk())
                                ->directory(fn (): string => ReceiptTemplateImageStorage::directoryFor(Filament::getTenant()?->getKey() ?? 0))
                                ->image()
                                ->maxSize(1024)
                                ->preventFilePathTampering(allowFilePathUsing: fn (string $file): bool => self::filePathBelongsToTenant($file)),
                            FileUpload::make('qr_path')
                                ->label('QR-код для оплаты')
                                ->disk(ReceiptTemplateImageStorage::disk())
                                ->directory(fn (): string => ReceiptTemplateImageStorage::directoryFor(Filament::getTenant()?->getKey() ?? 0))
                                ->image()
                                ->maxSize(1024)
                                ->preventFilePathTampering(allowFilePathUsing: fn (string $file): bool => self::filePathBelongsToTenant($file)),
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

        if ($oldLogoPath && $oldLogoPath !== $template->logo_path) {
            ReceiptTemplateImageStorage::delete($oldLogoPath);
        }

        if ($oldQrPath && $oldQrPath !== $template->qr_path) {
            ReceiptTemplateImageStorage::delete($oldQrPath);
        }

        $this->fillFromTemplate($template);

        Notification::make()
            ->success()
            ->title('Шаблон квитанции сохранён')
            ->send();
    }

    public function resetTemplate(): void
    {
        $this->getTemplate()?->delete();

        $this->fillFromTemplate(null);

        Notification::make()
            ->success()
            ->title('Шаблон сброшен к стандартному')
            ->send();
    }

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

    /**
     * Серверная защита от подмены путей `logo_path`/`qr_path` в состоянии
     * Livewire-формы (public-свойство `data`). Не полагается на валидацию
     * FileUpload — является дополнительным рубежом перед записью в БД.
     *
     * @param  array<string, mixed>  $data
     */
    protected function ensureFilePathsBelongToTenant(array $data, Organization $tenant): void
    {
        $errors = [];

        if (! ReceiptTemplateImageStorage::belongsToOrganization($data['logo_path'] ?? null, $tenant->getKey())) {
            $errors['data.logo_path'] = 'Недопустимый путь к файлу логотипа.';
        }

        if (! ReceiptTemplateImageStorage::belongsToOrganization($data['qr_path'] ?? null, $tenant->getKey())) {
            $errors['data.qr_path'] = 'Недопустимый путь к файлу QR-кода.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    protected static function filePathBelongsToTenant(string $file): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization
            && ReceiptTemplateImageStorage::belongsToOrganization($file, $tenant->getKey());
    }
}
