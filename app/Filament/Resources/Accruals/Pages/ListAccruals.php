<?php

namespace App\Filament\Resources\Accruals\Pages;

use App\Actions\CloseBillingMonth as CloseBillingMonthAction;
use App\Filament\Resources\Accruals\AccrualResource;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Organization;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

class ListAccruals extends ListRecords
{
    protected static string $resource = AccrualResource::class;

    public function mount(): void
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('closeBillingMonth')
                ->label('Закрыть месяц')
                ->icon(Heroicon::OutlinedCalculator)
                ->action(fn (): null => $this->closeCurrentBillingMonth()),
        ];
    }

    private function closeCurrentBillingMonth(): null
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization) {
            return null;
        }

        $billingPeriod = BillingPeriod::currentEditableFor($tenant);

        if (! $billingPeriod) {
            Notification::make()
                ->title('Нет открытого расчётного месяца')
                ->body('Откройте месяц в разделе «Расчётные месяцы».')
                ->danger()
                ->send();

            return null;
        }

        try {
            $result = app(CloseBillingMonthAction::class)->handle($tenant, $billingPeriod);
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }

        $notification = Notification::make()
            ->title($result['failed'] > 0 ? 'Месяц закрыт с ошибками' : 'Месяц закрыт')
            ->body("Расчётный месяц: {$billingPeriod->label}. Активных абонентов: {$result['active']}. Создано начислений: {$result['created']}. Пропущено ранее созданных: {$result['skipped']}. Ошибок данных: {$result['failed']}.");

        if ($result['failed'] > 0) {
            $notification->warning()->send();

            return null;
        }

        $notification->success()->send();

        return null;
    }
}
