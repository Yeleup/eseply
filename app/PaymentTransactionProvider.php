<?php

namespace App;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum PaymentTransactionProvider: string implements HasLabel
{
    case XPayment = 'xpayment';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::XPayment => 'XPayment / Kaspi',
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
