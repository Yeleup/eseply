<?php

namespace App;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum BalanceAdjustmentType: string implements HasLabel
{
    case OpeningBalance = 'opening_balance';
    case ManualAdjustment = 'manual_adjustment';
    case WriteOff = 'write_off';
    case Recalculation = 'recalculation';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::OpeningBalance => 'Входящий остаток',
            self::ManualAdjustment => 'Ручная корректировка',
            self::WriteOff => 'Списание',
            self::Recalculation => 'Перерасчёт',
        };
    }

    public static function labelFor(self|string|null $value): ?string
    {
        if ($value instanceof self) {
            return $value->getLabel();
        }

        return self::tryFrom((string) $value)?->getLabel();
    }
}
