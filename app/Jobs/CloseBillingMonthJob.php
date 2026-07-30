<?php

namespace App\Jobs;

use App\Actions\CloseBillingMonth;
use App\BillingPeriodStatus;
use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CloseBillingMonthJob implements ShouldQueue
{
    use Queueable;

    /**
     * Closing writes the accruals of a whole organization, so a failed run is
     * reported to the operators instead of being repeated automatically.
     */
    public int $tries = 1;

    /**
     * Must stay below the `retry_after` of the queue connection, otherwise the
     * queue would hand the same closing job to a second worker.
     */
    public int $timeout = 1800;

    public function __construct(
        public Organization $organization,
        public BillingPeriod $billingPeriod,
        public ?User $startedBy = null,
    ) {}

    public function handle(CloseBillingMonth $closeBillingMonth): void
    {
        $summary = $closeBillingMonth->run($this->organization, $this->billingPeriod, $this->startedBy);

        $body = "Расчётный месяц: {$this->billingPeriod->label}."
            ." Активных абонентов: {$summary['active']}."
            ." Создано начислений: {$summary['created']}."
            ." Пропущено ранее созданных: {$summary['skipped']}."
            ." Ошибок данных: {$summary['failed']}.";

        if ($summary['failed'] > 0) {
            $this->notifyOperators(
                Notification::make()
                    ->title('Месяц закрыт с ошибками')
                    ->body($body.' Откройте отчёт ошибок в разделе «Расчётные месяцы».')
                    ->warning(),
            );

            return;
        }

        $this->notifyOperators(
            Notification::make()
                ->title('Месяц закрыт')
                ->body($body)
                ->success(),
        );
    }

    public function failed(?Throwable $exception): void
    {
        $this->releaseProcessingBillingPeriod();

        $this->notifyOperators(
            Notification::make()
                ->title('Не удалось закрыть месяц')
                ->body("Расчётный месяц: {$this->billingPeriod->label}. Закрытие прервано технической ошибкой, начисления не созданы. Запустите закрытие месяца заново.")
                ->danger(),
        );
    }

    /**
     * A billing period must never stay in `processing`, otherwise no operator
     * could start closing it again.
     */
    private function releaseProcessingBillingPeriod(): void
    {
        $billingPeriod = BillingPeriod::query()->find($this->billingPeriod->getKey());

        if ($billingPeriod?->status !== BillingPeriodStatus::Processing) {
            return;
        }

        $billingPeriod->markFailed(
            [
                'active' => 0,
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
            ],
            'Закрытие расчётного месяца прервано технической ошибкой.',
        );
    }

    private function notifyOperators(Notification $notification): void
    {
        $operators = $this->organization->operators()->get();

        if ($operators->isEmpty()) {
            return;
        }

        $notification->sendToDatabase($operators);
    }
}
