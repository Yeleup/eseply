<?php

namespace App\Filament\Support;

use App\BillingPeriodStatus;
use App\Models\BillingPeriod;
use App\Models\Organization;
use Filament\Facades\Filament;

final class CurrentBillingPeriod
{
    public const string MissingTitle = 'Расчётный месяц не открыт';

    public const string MissingDescription = 'Откройте расчётный месяц в разделе «Расчётные месяцы», чтобы вводить оплаты, показания, корректировки и закрывать месяц.';

    public const string MissingTooltip = 'Откройте расчётный месяц, чтобы выполнить действие.';

    public const string ClosingTitle = 'Расчётный месяц закрывается';

    public const string ClosingDescription = 'Идёт расчёт начислений по всем активным абонентам. Уведомление о результате придёт, когда расчёт закончится.';

    public const string ClosingTooltip = 'Расчётный месяц закрывается. Дождитесь результата.';

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

    /**
     * The billing period the organization is currently calculating, if any.
     *
     * A closing period is not editable, so `get()` returns null while it runs
     * and the operator has to be told that a calculation is in progress instead
     * of being asked to open a month.
     */
    public static function closing(?Organization $organization = null): ?BillingPeriod
    {
        $organization ??= self::tenant();

        if (! $organization instanceof Organization) {
            return null;
        }

        return BillingPeriod::query()
            ->forOrganization($organization)
            ->where('status', BillingPeriodStatus::Processing->value)
            ->orderByDesc('starts_on')
            ->first();
    }

    public static function missingTooltip(?Organization $organization = null): ?string
    {
        if (! self::missing($organization)) {
            return null;
        }

        return self::closing($organization) instanceof BillingPeriod
            ? self::ClosingTooltip
            : self::MissingTooltip;
    }

    private static function tenant(): ?Organization
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization ? $tenant : null;
    }
}
