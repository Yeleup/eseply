<?php

namespace App;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum PaymentMethod: string implements HasLabel
{
    case Cash = 'cash';
    case Kaspi = 'kaspi';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Cash => 'Наличные',
            self::Kaspi => 'Kaspi',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Cash => 'gray',
            self::Kaspi => 'danger',
        };
    }

    public static function labelFor(self|string|null $value): ?string
    {
        if ($value instanceof self) {
            return $value->getLabel();
        }

        return self::tryFrom((string) $value)?->getLabel();
    }

    public static function colorFor(self|string|null $value): string
    {
        if ($value instanceof self) {
            return $value->color();
        }

        return self::tryFrom((string) $value)?->color() ?? 'gray';
    }
}
