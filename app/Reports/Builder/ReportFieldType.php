<?php

namespace App\Reports\Builder;

/**
 * Value type of a report builder field or metric.
 *
 * The type decides how a value is shown on the screen and written to the XLSX file,
 * so a column looks the same in both places.
 */
enum ReportFieldType: string
{
    case Text = 'text';
    case Date = 'date';
    case Int = 'int';
    case Money = 'money';
    case Percent = 'percent';
    case Float = 'float';

    /**
     * Caption shown next to the field in the column list.
     */
    public function label(): string
    {
        return match ($this) {
            self::Text => 'текст',
            self::Date => 'дата',
            self::Int => 'число',
            self::Money => 'сумма',
            self::Percent => 'процент',
            self::Float => 'дробное',
        };
    }

    public function isNumeric(): bool
    {
        return in_array($this, [self::Int, self::Money, self::Percent, self::Float], true);
    }

    /**
     * A numeric value as the report shows it, on the screen and in the XLSX file alike:
     * a whole number for `int`, an amount of money or a fraction rounded half up to two
     * decimals. A percentage keeps its value: the screen and the file already round it
     * the same way, half up to two decimals.
     *
     * The rounding happens here, before any formatter sees the value, because the screen
     * formats money and fractions with ICU, which rounds a tie half to even (0.125 to 0.12),
     * while the file would round it half up (0.13). A value that already has two decimals
     * reaches both places unchanged, so they always show the same number.
     */
    public function numberOf(mixed $value): int|float
    {
        return match ($this) {
            self::Int => (int) $value,
            self::Money, self::Float => round((float) $value, 2),
            default => (float) $value,
        };
    }
}
