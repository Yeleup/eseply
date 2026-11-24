<?php

namespace App\Kaspi;

use RuntimeException;

/**
 * A Kaspi payment that cannot be posted right now and stays in processing,
 * with the reason the journal shows.
 *
 * A reason of the whole month — it is closing, missing or its lock is taken —
 * holds every operation of the organization; a locked row holds only its own
 * operation and is worth another try within the deadline.
 */
class CannotPostKaspiOperation extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly bool $isMonthWide,
        private readonly bool $isRetryable = false,
    ) {
        parent::__construct($message);
    }

    public static function monthClosing(): self
    {
        return new self('Идёт закрытие расчётного месяца', isMonthWide: true);
    }

    public static function noOpenMonth(): self
    {
        return new self('Нет открытого расчётного месяца', isMonthWide: true);
    }

    public static function monthLockWaitExceeded(): self
    {
        return new self('Не дождались блокировки расчётного месяца, проведётся автоматически', isMonthWide: true);
    }

    public static function lockWaitExceeded(): self
    {
        return new self('Не дождались блокировки, проведётся автоматически', isMonthWide: false, isRetryable: true);
    }

    public function isMonthWide(): bool
    {
        return $this->isMonthWide;
    }

    public function isRetryable(): bool
    {
        return $this->isRetryable;
    }
}
