<?php

namespace App\Kaspi;

use RuntimeException;

/**
 * A Kaspi payment that cannot be posted right now and stays in processing,
 * with the reason the journal shows.
 */
class CannotPostKaspiOperation extends RuntimeException
{
    public static function monthClosing(): self
    {
        return new self('Идёт закрытие расчётного месяца');
    }

    public static function noOpenMonth(): self
    {
        return new self('Нет открытого расчётного месяца');
    }

    public static function lockWaitExceeded(): self
    {
        return new self('Не дождались блокировки, проведётся автоматически');
    }
}
