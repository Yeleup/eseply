<?php

namespace App\Dashboard;

use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class DashboardMetrics
{
    /**
     * Operational figures of one billing period, limited to what the member may see.
     *
     * @return array{
     *     clients_active:int, clients_total:int, clients_new:int,
     *     meters_active:int, meters_metered:int,
     *     readings_taken:int, readings_expected:int, readings_percent:float,
     *     consumption:int
     * }
     */
    public function operations(Organization $organization, BillingPeriod $billingPeriod, User $user): array
    {
        [$periodStartsAt, $periodEndsAt] = $this->periodRange($billingPeriod);

        $clientsTotal = $this->visibleClients($organization, $user)->count();
        $clientsActive = $this->visibleClients($organization, $user)
            ->where('clients.status', 'active')
            ->count();
        $clientsNew = $this->visibleClients($organization, $user)
            ->whereBetween('clients.created_at', [$periodStartsAt, $periodEndsAt])
            ->count();

        $metersActive = $this->visibleActiveMeters($organization, $user)->count();
        $metersMetered = $this->visibleActiveMeters($organization, $user)
            ->whereHas('client', fn (Builder $query): Builder => $query->where('clients.billing_type', 'meter'))
            ->count();

        $readingsTaken = $this->visibleActiveMeters($organization, $user)
            ->whereHas('client', fn (Builder $query): Builder => $query->where('clients.billing_type', 'meter'))
            ->whereHas(
                'readings',
                fn (Builder $query): Builder => $query->where('meter_readings.billing_period_id', $billingPeriod->getKey()),
            )
            ->count();

        $consumption = (int) MeterReading::query()
            ->visibleToOrganizationMember($user, $organization)
            ->where('meter_readings.billing_period_id', $billingPeriod->getKey())
            ->sum('meter_readings.consumption');

        return [
            'clients_active' => $clientsActive,
            'clients_total' => $clientsTotal,
            'clients_new' => $clientsNew,
            'meters_active' => $metersActive,
            'meters_metered' => $metersMetered,
            'readings_taken' => $readingsTaken,
            'readings_expected' => $metersMetered,
            'readings_percent' => $this->percent($readingsTaken, $metersMetered),
            'consumption' => $consumption,
        ];
    }

    /**
     * @return Builder<Client>
     */
    private function visibleClients(Organization $organization, User $user): Builder
    {
        return Client::query()->visibleToOrganizationMember($user, $organization);
    }

    /**
     * @return Builder<Meter>
     */
    private function visibleActiveMeters(Organization $organization, User $user): Builder
    {
        return Meter::query()
            ->visibleToOrganizationMember($user, $organization)
            ->where('meters.status', 'active')
            ->whereHas('client', fn (Builder $query): Builder => $query->where('clients.status', 'active'));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function periodRange(BillingPeriod $billingPeriod): array
    {
        $startsOn = CarbonImmutable::instance($billingPeriod->starts_on)->startOfMonth();

        return [$startsOn->startOfDay(), $startsOn->endOfMonth()->endOfDay()];
    }

    private function percent(int $part, int $total): float
    {
        return $total === 0 ? 0.0 : round($part / $total * 100, 1);
    }
}
