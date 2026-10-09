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
}
