<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact sums of money in tiyn (hundredths of a tenge), without floats.
 *
 * The running totals of a subscriber — payments, adjustments, opening and
 * closing balance of a receipt or an accrual — are stored as `DECIMAL(18,2)`:
 * up to 9 999 999 999 999 999,99 ₸, that is ten thousand payments of the
 * largest sum Kaspi may send (`^\d{1,12}\.\d{2}$`). A float loses tiyn long
 * before that, so these totals are added here as integers: an int of PHP
 * holds the whole range in tiyn.
 */
final class Money
{
    /**
     * Largest total of a money total column (`DECIMAL(18,2)`), in tiyn.
     */
    public const int TOTAL_LIMIT_CENTS = 999_999_999_999_999_999;

    public static function cents(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        if (is_int($amount)) {
            return $amount * 100;
        }

        if (is_float($amount)) {
            $amount = sprintf('%.2f', round($amount, 2));
        }

        if (preg_match('/\A(-)?(\d*)(?:\.(\d*))?\z/', trim($amount), $matches) !== 1 || ($matches[2] === '' && ($matches[3] ?? '') === '')) {
            throw new InvalidArgumentException("Сумма «{$amount}» не число.");
        }

        $fraction = str_pad(substr($matches[3] ?? '', 0, 3), 3, '0');
        $cents = (int) ($matches[2] === '' ? '0' : $matches[2]) * 100 + intdiv((int) $fraction, 10);

        // Half up on the third decimal, as a decimal(…,2) column rounds.
        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return $matches[1] === '-' ? -$cents : $cents;
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The sum of the amounts, each with its sign, as a decimal string.
     */
    public static function sum(string|int|float|null ...$amounts): string
    {
        return self::format(array_sum(array_map(self::cents(...), $amounts)));
    }
}
