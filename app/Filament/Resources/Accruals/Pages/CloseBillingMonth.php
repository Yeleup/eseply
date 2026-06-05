<?php

namespace App\Filament\Resources\Accruals\Pages;

use App\Actions\CloseBillingMonth as CloseBillingMonthAction;
use App\Filament\Resources\Accruals\AccrualResource;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
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

        $this->form->fill([
            'period' => now()->format('Ym'),
        ]);
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
                TextInput::make('period')
                    ->label('Период')
                    ->placeholder('202605')
                    ->helperText('Формат: ГГГГММ')
                    ->required()
                    ->length(6)
                    ->regex('/^\d{6}$/')
                    ->rules(['date_format:Ym']),
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
            $this->result = app(CloseBillingMonthAction::class)->handle($tenant, $data['period']);
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
