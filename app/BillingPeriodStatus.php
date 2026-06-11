<?php

namespace App;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum BillingPeriodStatus: string implements HasLabel
{
    case Open = 'open';
    case Processing = 'processing';
    case Closed = 'closed';
    case Failed = 'failed';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Open => 'Открыт',
            self::Processing => 'Закрывается',
            self::Closed => 'Закрыт',
            self::Failed => 'Ошибка закрытия',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Processing => 'warning',
            self::Closed => 'gray',
            self::Failed => 'danger',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Open, self::Failed], true);
    }

    public function canStartClosing(): bool
    {
        return $this->isEditable();
    }

    public static function labelFor(self|string|null $value): ?string
    {
        if ($value instanceof self) {
            return $value->getLabel();
        }

        return self::tryFrom((string) $value)?->getLabel();
    }
}
