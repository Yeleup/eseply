<?php

namespace App\Filament\Resources\Accruals\Pages;

use App\Actions\CloseBillingMonth as CloseBillingMonthAction;
use App\BillingPeriodStatus;
use App\Filament\Resources\Accruals\AccrualResource;
use App\Filament\Support\CurrentBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Jobs\CloseBillingMonthJob;
use App\Models\BillingPeriod;
use App\Models\Organization;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Throwable;

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
                ->disabled(fn (): bool => CurrentBillingPeriod::missing())
                ->tooltip(fn (): ?string => CurrentBillingPeriod::missingTooltip())
                ->action(fn (): null => $this->closeCurrentBillingMonth()),
        ];
    }

    /**
     * Closing recalculates every active abonent of the organization, so the
     * request only reserves the month and the calculation runs on the queue.
     * The result is delivered as a database notification.
     */
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

        $startedBy = OrganizationMemberAccess::user();

        try {
            $billingPeriod = app(CloseBillingMonthAction::class)->claim($tenant, $billingPeriod, $startedBy);
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }

        try {
            CloseBillingMonthJob::dispatch($tenant, $billingPeriod, $startedBy);
        } catch (Throwable $exception) {
            /** A month that never reached the queue must not stay reserved for closing. */
            $this->releaseClaimedBillingPeriod($billingPeriod);

            throw $exception;
        }

        Notification::make()
            ->title('Закрытие месяца запущено')
            ->body("Расчётный месяц: {$billingPeriod->label}. Уведомление с результатом придёт, когда расчёт закончится.")
            ->info()
            ->send();

        return null;
    }

    private function releaseClaimedBillingPeriod(BillingPeriod $billingPeriod): void
    {
        if ($billingPeriod->refresh()->status !== BillingPeriodStatus::Processing) {
            return;
        }

        $billingPeriod->markFailed(
            [
                'active' => 0,
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
            ],
            'Не удалось поставить закрытие расчётного месяца в очередь.',
        );
    }
}
