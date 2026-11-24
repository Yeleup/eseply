<?php

namespace App\Support;

use App\Models\BillingPeriod;
use RuntimeException;
use Throwable;

/**
 * The next billing month could not be opened in the closing transaction, so the
 * closing was rolled back and the month goes to `failed` with this message.
 */
class NextBillingPeriodNotOpened extends RuntimeException
{
    public function __construct(BillingPeriod $closedBillingPeriod, string $reason = '', ?Throwable $previous = null)
    {
        $next = BillingPeriod::periodStart($closedBillingPeriod->starts_on)->addMonth()->format('m.Y');

        parent::__construct(
            "Не удалось открыть следующий расчётный месяц {$next}, поэтому закрытие отменено и начисления не созданы."
                .(filled($reason) ? " Причина: {$reason}" : '')
                .' Запустите закрытие месяца заново.',
            previous: $previous,
        );
    }
}
