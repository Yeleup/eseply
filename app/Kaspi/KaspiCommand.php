<?php

namespace App\Kaspi;

use Filament\Support\Contracts\HasLabel;

enum KaspiCommand: string implements HasLabel
{
    case Check = 'check';
    case Pay = 'pay';

    public function getLabel(): string
    {
        return $this->value;
    }
}
