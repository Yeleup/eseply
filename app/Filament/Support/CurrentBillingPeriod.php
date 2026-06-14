<?php

namespace App\Filament\Support;

use App\Models\BillingPeriod;
use App\Models\Organization;
use Filament\Facades\Filament;

final class CurrentBillingPeriod
{
    public const string MissingTitle = 'Расчётный месяц не открыт';

    public const string MissingDescription = 'Откройте расчётный месяц в разделе «Расчётные месяцы», чтобы вводить оплаты, показания, корректировки и закрывать месяц.';

    public const string MissingTooltip = 'Откройте расчётный месяц, чтобы выполнить действие.';

    public static function get(?Organization $organization = null): ?BillingPeriod
    {
        $organization ??= self::tenant();

        if (! $organization instanceof Organization) {
            return null;
        }

        return BillingPeriod::currentEditableFor($organization);
    }

    public static function exists(?Organization $organization = null): bool
    {
        return self::get($organization) instanceof BillingPeriod;
    }

    public static function missing(?Organization $organization = null): bool
    {
        return ! self::exists($organization);
    }

    public static function missingTooltip(?Organization $organization = null): ?string
    {
        return self::missing($organization) ? self::MissingTooltip : null;
    }

    private static function tenant(): ?Organization
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization ? $tenant : null;
    }
}
