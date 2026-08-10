<?php

namespace App\Filament\Support;

use App\Models\BillingPeriod;
use App\Models\Organization;

/**
 * The billing period the dashboard is showing.
 *
 * The selected identifier comes from Livewire state and can be tampered with, so
 * only a period of the current organization is ever accepted; anything else falls
 * back to the default period.
 */
final class DashboardBillingPeriod
{
    /**
     * @return array<int, string>
     */
    public static function options(Organization $organization): array
    {
        return $organization->billingPeriods()
            ->orderByDesc('starts_on')
            ->get()
            ->mapWithKeys(fn (BillingPeriod $billingPeriod): array => [
                $billingPeriod->getKey() => $billingPeriod->label.' — '.$billingPeriod->status->getLabel(),
            ])
            ->all();
    }

    public static function default(Organization $organization): ?BillingPeriod
    {
        return BillingPeriod::currentEditableFor($organization)
            ?? $organization->billingPeriods()
                ->orderByDesc('starts_on')
                ->first();
    }

    public static function resolve(Organization $organization, mixed $billingPeriodId): ?BillingPeriod
    {
        $identifier = FilterIdentifiers::one($billingPeriodId);

        if ($identifier === null) {
            return self::default($organization);
        }

        return $organization->billingPeriods()
            ->whereKey($identifier)
            ->first()
            ?? self::default($organization);
    }
}
