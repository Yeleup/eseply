<?php

namespace App\Jobs;

use App\Actions\AcceptBillingClosureMeterReadings;
use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\User;
use App\Support\BillingPeriodOperationLock;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class AcceptBillingClosureMeterReadingsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Must remain below the queue connection's retry_after and reservation lifetime.
     */
    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(
        public Organization $organization,
        public BillingPeriod $billingPeriod,
        public User $operator,
        public string $code,
        public string $lockOwner,
        public int $reservedUntil,
    ) {}

    public function handle(AcceptBillingClosureMeterReadings $acceptReadings): void
    {
        $lock = BillingPeriodOperationLock::restore($this->organization, $this->billingPeriod, $this->lockOwner);

        try {
            if (! $lock->isOwnedByCurrentProcess() || time() + $this->timeout >= $this->reservedUntil) {
                throw new RuntimeException('Срок ожидания принятия показаний истёк. Запустите операцию повторно.');
            }

            $count = $acceptReadings->handle(
                $this->organization,
                $this->billingPeriod,
                $this->operator,
                $this->code,
                lockOwner: $this->lockOwner,
            );
        } finally {
            $lock->release();
        }

        Notification::make()->title("Принято показаний: {$count}")
            ->body("Расчётный месяц: {$this->billingPeriod->label}. Запустите закрытие месяца для повторной проверки всех данных.")
            ->success()->sendToDatabase($this->operator);
    }

    public function failed(?Throwable $exception): void
    {
        BillingPeriodOperationLock::restore($this->organization, $this->billingPeriod, $this->lockOwner)->release();

        Notification::make()->title('Не удалось принять показания')
            ->body("Расчётный месяц: {$this->billingPeriod->label}. Проверьте отчёт ошибок и повторите операцию.")
            ->danger()->sendToDatabase($this->operator);
    }
}
