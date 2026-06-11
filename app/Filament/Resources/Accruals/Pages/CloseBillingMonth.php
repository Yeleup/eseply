<?php

namespace App\Filament\Resources\Accruals\Pages;

use App\Actions\CloseBillingMonth as CloseBillingMonthAction;
use App\Filament\Resources\Accruals\AccrualResource;
use App\Filament\Support\BillingPeriodOptions;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;

/**
 * @property-read Schema $form
 */
class CloseBillingMonth extends Page
{
    protected static string $resource = AccrualResource::class;

    protected string $view = 'filament.resources.accruals.pages.close-billing-month';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * @var array{active:int, created:int, skipped:int, failed:int, errors:list<array{client_id:int, account_number:string, client_name:string, message:string}>}|null
     */
    public ?array $result = null;

    public function mount(): void
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Закрытие месяца';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('billing_period_id')
                    ->label('Расчётный месяц')
                    ->helperText('Выберите открытый месяц. Новый месяц сначала откройте в разделе «Расчётные месяцы».')
                    ->options(fn (): array => BillingPeriodOptions::editable())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->scopedExists(BillingPeriod::class, 'id')
                    ->native(false),
            ]);
    }

    public function close(): void
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization) {
            return;
        }

        $data = $this->form->getState();

        try {
            $billingPeriod = BillingPeriod::query()->findOrFail($data['billing_period_id']);

            $this->result = app(CloseBillingMonthAction::class)->handle($tenant, $billingPeriod);
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $notification = Notification::make()
            ->title($this->result['failed'] > 0 ? 'Месяц закрыт с ошибками' : 'Месяц закрыт')
            ->body("Активных абонентов: {$this->result['active']}. Создано начислений: {$this->result['created']}. Пропущено ранее созданных: {$this->result['skipped']}. Ошибок данных: {$this->result['failed']}.");

        if ($this->result['failed'] > 0) {
            $notification->warning()->send();

            return;
        }

        $notification->success()->send();
    }
}
