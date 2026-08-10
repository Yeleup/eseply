<?php

namespace App\Dashboard;

use App\BillingPeriodStatus;
use App\Models\Accrual;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

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
     * Money figures of one billing period. Only operators may see them, so the
     * member is not a parameter: an operator always sees the whole organization.
     *
     * @return array{
     *     charged:float, charged_is_preliminary:bool, charged_documents:int,
     *     paid:float, payments_count:int, collection_percent:float,
     *     debt:float, debtors_count:int
     * }
     */
    public function finance(Organization $organization, BillingPeriod $billingPeriod): array
    {
        $chargeRow = $this->chargeQuery($organization, $billingPeriod)
            ->selectRaw('coalesce(sum(amount), 0) as total, count(*) as documents')
            ->first();

        $debtRow = $this->chargeQuery($organization, $billingPeriod)
            ->where('closing_balance', '>', 0)
            ->selectRaw('coalesce(sum(closing_balance), 0) as total, count(*) as debtors')
            ->first();

        $paymentRow = Payment::query()
            ->toBase()
            ->where('organization_id', $organization->getKey())
            ->where('billing_period_id', $billingPeriod->getKey())
            ->selectRaw('coalesce(sum(amount), 0) as total, count(*) as payments')
            ->first();

        $charged = (float) ($chargeRow->total ?? 0);
        $paid = (float) ($paymentRow->total ?? 0);

        return [
            'charged' => $charged,
            'charged_is_preliminary' => $billingPeriod->status !== BillingPeriodStatus::Closed,
            'charged_documents' => (int) ($chargeRow->documents ?? 0),
            'paid' => $paid,
            'payments_count' => (int) ($paymentRow->payments ?? 0),
            'collection_percent' => $charged <= 0.0 ? 0.0 : round($paid / $charged * 100, 1),
            'debt' => (float) ($debtRow->total ?? 0),
            'debtors_count' => (int) ($debtRow->debtors ?? 0),
        ];
    }

    /**
     * Charged and paid totals of the latest billing periods, oldest first.
     *
     * @return list<array{period:string, label:string, charged:float, paid:float}>
     */
    public function monthlyTotals(Organization $organization, int $months = 12): array
    {
        $billingPeriods = BillingPeriod::query()
            ->forOrganization($organization)
            ->orderByDesc('starts_on')
            ->limit($months)
            ->get()
            ->sortBy('starts_on')
            ->values();

        if ($billingPeriods->isEmpty()) {
            return [];
        }

        $billingPeriodIds = $billingPeriods->modelKeys();

        $accrualTotals = $this->amountTotalsByBillingPeriod('accruals', $organization, $billingPeriodIds);
        $receiptTotals = $this->amountTotalsByBillingPeriod('receipts', $organization, $billingPeriodIds);
        $paymentTotals = $this->amountTotalsByBillingPeriod('payments', $organization, $billingPeriodIds);

        return $billingPeriods
            ->map(function (BillingPeriod $billingPeriod) use ($accrualTotals, $receiptTotals, $paymentTotals): array {
                $chargeTotals = $billingPeriod->status === BillingPeriodStatus::Closed
                    ? $accrualTotals
                    : $receiptTotals;

                return [
                    'period' => $billingPeriod->code,
                    'label' => $billingPeriod->label,
                    'charged' => (float) ($chargeTotals[$billingPeriod->getKey()] ?? 0),
                    'paid' => (float) ($paymentTotals[$billingPeriod->getKey()] ?? 0),
                ];
            })
            ->all();
    }

    /**
     * A closed period is the accrual of record; an open one only has receipts of
     * the clients whose readings are already in.
     */
    private function chargeTable(BillingPeriod $billingPeriod): string
    {
        return $billingPeriod->status === BillingPeriodStatus::Closed ? 'accruals' : 'receipts';
    }

    private function chargeQuery(Organization $organization, BillingPeriod $billingPeriod): QueryBuilder
    {
        $query = $this->chargeTable($billingPeriod) === 'accruals'
            ? Accrual::query()
            : Receipt::query();

        return $query
            ->toBase()
            ->where('organization_id', $organization->getKey())
            ->where('billing_period_id', $billingPeriod->getKey());
    }

    /**
     * @param  list<int>  $billingPeriodIds
     * @return array<int, float>
     */
    private function amountTotalsByBillingPeriod(string $table, Organization $organization, array $billingPeriodIds): array
    {
        return DB::table($table)
            ->where('organization_id', $organization->getKey())
            ->whereIn('billing_period_id', $billingPeriodIds)
            ->groupBy('billing_period_id')
            ->selectRaw('billing_period_id, coalesce(sum(amount), 0) as total')
            ->pluck('total', 'billing_period_id')
            ->map(fn (mixed $total): float => (float) $total)
            ->all();
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
