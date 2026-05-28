<?php

namespace App;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum ClientType: string implements HasLabel
{
    case Individual = 'individual';
    case SoleProprietor = 'sole_proprietor';
    case Llp = 'llp';
    case Commercial = 'commercial';
    case Budget = 'budget';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Individual => 'Физ. лицо',
            self::SoleProprietor => 'ИП',
            self::Llp => 'ТОО',
            self::Commercial => 'Коммерческие объекты',
            self::Budget => 'Бюджет',
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
