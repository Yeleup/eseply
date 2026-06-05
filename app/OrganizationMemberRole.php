<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

enum OrganizationMemberRole: string implements HasLabel
{
    case Operator = 'operator';
    case Controller = 'controller';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Operator => 'Оператор',
            self::Controller => 'Контроллер',
        };
    }
}
