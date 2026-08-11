<?php

namespace App\Filament\Pages;

use App\Actions\BuildReceiptPrintViewData;
use App\Models\Organization;
use App\Models\Receipt;
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
use Illuminate\Support\HtmlString;
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
     * @return array<int, TextInput>
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
