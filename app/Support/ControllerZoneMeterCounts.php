<?php

namespace App\Support;

use App\Models\BillingPeriod;
use App\Models\Meter;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Counts of the active metered meters in a controller's zone.
 *
 * The query is correlated to the outer `users.id` column, so it can only be used
 * as a sub-select of a query that selects from `users`.
 */
final class ControllerZoneMeterCounts
{
    /**
     * @return Builder<Meter>
     */
    public static function query(Organization $organization, ?BillingPeriod $billingPeriod = null): Builder
    {
        $query = Meter::query()
            ->selectRaw('count(distinct meters.id)')
            ->join('clients', 'clients.id', '=', 'meters.client_id')
            ->where('meters.organization_id', $organization->getKey())
            ->where('meters.status', 'active')
            ->where('clients.status', 'active')
            ->where('clients.billing_type', 'meter')
            ->where(function (Builder $query) use ($organization): void {
                $query
                    ->whereExists(function (QueryBuilder $query) use ($organization): void {
                        $query
                            ->selectRaw('1')
                            ->from('organization_user_regions')
                            ->where('organization_user_regions.organization_id', $organization->getKey())
                            ->whereColumn('organization_user_regions.user_id', 'users.id')
                            ->whereColumn('organization_user_regions.region_id', 'clients.region_id');
                    })
                    ->orWhereExists(function (QueryBuilder $query) use ($organization): void {
                        $query
                            ->selectRaw('1')
                            ->from('organization_user_streets')
                            ->where('organization_user_streets.organization_id', $organization->getKey())
                            ->whereColumn('organization_user_streets.user_id', 'users.id')
                            ->whereColumn('organization_user_streets.street_id', 'clients.street_id');
                    });
            });

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query;
        }

        return $query->whereExists(function (QueryBuilder $query) use ($billingPeriod): void {
            $query
                ->selectRaw('1')
                ->from('meter_readings')
                ->whereColumn('meter_readings.meter_id', 'meters.id')
                ->where('meter_readings.billing_period_id', $billingPeriod->getKey());
        });
    }
}
