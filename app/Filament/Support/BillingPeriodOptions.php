<?php

namespace App\Filament\Support;

use App\BillingPeriodStatus;
use App\Models\BillingPeriod;
use App\Models\Organization;
use Filament\Facades\Filament;

final class BillingPeriodOptions
{
    /**
     * @return array<int, string>
     */
    public static function all(?Organization $organization = null): array
    {
        $organization ??= self::tenant();

        if (! $organization) {
            return [];
        }

        return $organization->billingPeriods()
            ->orderByDesc('starts_on')
            ->get()
            ->mapWithKeys(fn (BillingPeriod $billingPeriod): array => [
                $billingPeriod->id => $billingPeriod->label,
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function editable(?Organization $organization = null): array
    {
        $organization ??= self::tenant();

        if (! $organization) {
            return [];
        }

        return $organization->billingPeriods()
            ->whereIn('status', [
                BillingPeriodStatus::Open->value,
                BillingPeriodStatus::Failed->value,
            ])
            ->orderByDesc('starts_on')
            ->get()
            ->mapWithKeys(fn (BillingPeriod $billingPeriod): array => [
                $billingPeriod->id => $billingPeriod->label,
            ])
            ->all();
    }

    private static function tenant(): ?Organization
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization ? $tenant : null;
    }
}
