<?php

namespace App\Kaspi;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Posting state of a Kaspi operation, kept apart from the code Kaspi last got.
 */
enum KaspiOperationState: string implements HasColor, HasLabel
{
    /** `check`: an active subscriber was found. */
    case Found = 'found';

    /** `check`: no active subscriber with this account in the organization. */
    case NotFound = 'not_found';

    /** `pay`: the payment is posted. */
    case Posted = 'posted';

    /** `pay`: accepted and saved, posted as soon as a month can take it. */
    case Pending = 'pending';

    /** `check` answered with an error, for example while acceptance is off. */
    case Error = 'error';

    public function getLabel(): string
    {
        return match ($this) {
            self::Found => 'Найден',
            self::NotFound => 'Не найден',
            self::Posted => 'Проведена',
            self::Pending => 'В обработке',
            self::Error => 'Ошибка',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Found, self::Posted => 'success',
            self::NotFound => 'gray',
            self::Pending => 'warning',
            self::Error => 'danger',
        };
    }
}
