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
use App\Models\Region;
use App\Models\User;
use App\OrganizationMemberRole;
use App\Reports\TurnoverBalanceValues;
use App\Support\ControllerZoneMeterCounts;
use App\Support\TakenMeterReading;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class DashboardMetrics
{
    /**
     * Seconds a computed block stays cached. The dashboard may lag behind the
     * reports by this much in exchange for not recomputing on every visit.
     */
    public const int CACHE_TTL = 60;

    private const string CACHE_PREFIX = 'dashboard-metrics';

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
        return $this->remember(
            $this->cacheKey('operations', $organization, $billingPeriod, $user),
            fn (): array => $this->computeOperations($organization, $billingPeriod, $user),
        );
    }

    /**
     * @return array{
     *     clients_active:int, clients_total:int, clients_new:int,
     *     meters_active:int, meters_metered:int,
     *     readings_taken:int, readings_expected:int, readings_percent:float,
     *     consumption:int
     * }
     */
    private function computeOperations(Organization $organization, BillingPeriod $billingPeriod, User $user): array
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
                fn (Builder $query): Builder => $query
                    ->where('meter_readings.billing_period_id', $billingPeriod->getKey())
                    ->taken(),
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
     * The debt of a period that is not closed yet is the current closing balance
     * of every active client, see `debtQuery()`.
     *
     * @return array{
     *     charged:float, charged_is_preliminary:bool, charged_documents:int,
     *     debt_is_current:bool,
     *     paid:float, payments_count:int, collection_percent:float,
     *     debt:float, debtors_count:int
     * }
     */
    public function finance(Organization $organization, BillingPeriod $billingPeriod): array
    {
        return $this->remember(
            $this->cacheKey('finance', $organization, $billingPeriod),
            fn (): array => $this->computeFinance($organization, $billingPeriod),
        );
    }

    /**
     * @return array{
     *     charged:float, charged_is_preliminary:bool, charged_documents:int,
     *     debt_is_current:bool,
     *     paid:float, payments_count:int, collection_percent:float,
     *     debt:float, debtors_count:int
     * }
     */
    private function computeFinance(Organization $organization, BillingPeriod $billingPeriod): array
    {
        $chargeRow = $this->chargeQuery($organization, $billingPeriod)
            ->selectRaw('coalesce(sum(amount), 0) as total, count(*) as documents')
            ->first();

        $debtRow = $this->debtQuery($organization, $billingPeriod)
            ->where('closing_debit', '>', 0)
            ->selectRaw('coalesce(sum(closing_debit), 0) as total, count(*) as debtors')
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
            'debt_is_current' => $billingPeriod->status !== BillingPeriodStatus::Closed,
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
        return $this->remember(
            $this->cacheKey("monthly-totals:{$months}", $organization),
            fn (): array => $this->computeMonthlyTotals($organization, $months),
        );
    }

    /**
     * @return list<array{period:string, label:string, charged:float, paid:float}>
     */
    private function computeMonthlyTotals(Organization $organization, int $months): array
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
     * Meter reading progress of every controller of the organization.
     *
     * A controller only ever sees their own row.
     *
     * @return list<array{
     *     controller_id:int, name:string, email:string,
     *     total:int, taken:int, missing:int, percent:float
     * }>
     */
    public function controllerProgress(Organization $organization, BillingPeriod $billingPeriod, User $user): array
    {
        return $this->remember(
            $this->cacheKey('controller-progress', $organization, $billingPeriod, $user),
            fn (): array => $this->computeControllerProgress($organization, $billingPeriod, $user),
        );
    }

    /**
     * @return list<array{
     *     controller_id:int, name:string, email:string,
     *     total:int, taken:int, missing:int, percent:float
     * }>
     */
    private function computeControllerProgress(Organization $organization, BillingPeriod $billingPeriod, User $user): array
    {
        $query = User::query()
            ->select(['users.id', 'users.name', 'users.email'])
            ->join('organization_user', 'organization_user.user_id', '=', 'users.id')
            ->where('organization_user.organization_id', $organization->getKey())
            ->where('organization_user.role', OrganizationMemberRole::Controller->value)
            ->addSelect([
                'zone_meters_total' => ControllerZoneMeterCounts::query($organization),
                'zone_meters_taken' => ControllerZoneMeterCounts::query($organization, $billingPeriod),
            ])
            ->orderBy('users.name')
            ->orderBy('users.id');

        if ($user->isOrganizationController($organization)) {
            $query->where('users.id', $user->getKey());
        }

        return $query->get()
            ->map(function (User $controller): array {
                $total = (int) $controller->getAttribute('zone_meters_total');
                $taken = (int) $controller->getAttribute('zone_meters_taken');

                return [
                    'controller_id' => (int) $controller->getKey(),
                    'name' => (string) $controller->name,
                    'email' => (string) $controller->email,
                    'total' => $total,
                    'taken' => $taken,
                    'missing' => max($total - $taken, 0),
                    'percent' => $this->percent($taken, $total),
                ];
            })
            ->all();
    }

    /**
     * Per region totals of one billing period, biggest debt first.
     *
     * Only operators may see money, so the member is not a parameter.
     *
     * @return list<array{
     *     region_id:int, region:string, city:string, clients:int,
     *     readings_percent:float, charged:float, paid:float, debt:float
     * }>
     */
    public function regionBreakdown(Organization $organization, BillingPeriod $billingPeriod): array
    {
        return $this->remember(
            $this->cacheKey('region-breakdown', $organization, $billingPeriod),
            fn (): array => $this->computeRegionBreakdown($organization, $billingPeriod),
        );
    }

    /**
     * @return list<array{
     *     region_id:int, region:string, city:string, clients:int,
     *     readings_percent:float, charged:float, paid:float, debt:float
     * }>
     */
    private function computeRegionBreakdown(Organization $organization, BillingPeriod $billingPeriod): array
    {
        $chargeTable = $this->chargeTable($billingPeriod);
        $organizationId = (int) $organization->getKey();
        $billingPeriodId = (int) $billingPeriod->getKey();

        $query = Region::query()
            ->select(['regions.id', 'regions.name'])
            ->leftJoin('cities', 'cities.id', '=', 'regions.city_id')
            ->addSelect(['cities.name as city_name'])
            ->where('regions.organization_id', $organizationId)
            ->selectSub($this->regionClientCountQuery(), 'clients_count')
            ->selectSub($this->regionMeterCountQuery($organizationId), 'meters_total')
            ->selectSub($this->regionMeterCountQuery($organizationId, $billingPeriodId), 'meters_taken')
            ->selectSub($this->regionChargeQuery($chargeTable, $organizationId, $billingPeriodId, onlyDebt: false), 'charged')
            ->selectSub($this->regionPaymentQuery($organizationId, $billingPeriodId), 'paid')
            ->orderBy('regions.name');

        if ($chargeTable === 'accruals') {
            $query->selectSub($this->regionChargeQuery($chargeTable, $organizationId, $billingPeriodId, onlyDebt: true), 'debt');
        } else {
            $regionDebts = DB::query()
                ->fromSub($this->openPeriodDebtQuery($organization, $billingPeriod), 'client_debts')
                ->select('client_debts.region_id')
                ->selectRaw('sum(client_debts.closing_debit) as debt')
                ->where('client_debts.closing_debit', '>', 0)
                ->groupBy('client_debts.region_id');

            $query
                ->leftJoinSub($regionDebts, 'region_debts', 'region_debts.region_id', '=', 'regions.id')
                ->selectRaw('coalesce(region_debts.debt, 0) as debt');
        }

        $rows = $query->get();

        return $rows
            ->filter(fn (Region $region): bool => (int) $region->getAttribute('clients_count') > 0)
            ->map(fn (Region $region): array => [
                'region_id' => (int) $region->getKey(),
                'region' => (string) $region->name,
                'city' => (string) ($region->getAttribute('city_name') ?? ''),
                'clients' => (int) $region->getAttribute('clients_count'),
                'readings_percent' => $this->percent(
                    (int) $region->getAttribute('meters_taken'),
                    (int) $region->getAttribute('meters_total'),
                ),
                'charged' => (float) $region->getAttribute('charged'),
                'paid' => (float) $region->getAttribute('paid'),
                'debt' => (float) $region->getAttribute('debt'),
            ])
            ->sortByDesc('debt')
            ->values()
            ->all();
    }

    /**
     * @template TValue of array
     *
     * @param  Closure(): TValue  $compute
     * @return TValue
     */
    private function remember(string $key, Closure $compute): array
    {
        return Cache::remember($key, self::CACHE_TTL, $compute);
    }

    /**
     * Cache key of one dashboard block.
     *
     * The key holds everything the figures depend on: the organization, the
     * billing period with its status (the status switches the source of the
     * charges) and, when a member is given, what that member may see. Every
     * operator sees the whole organization and shares one entry; a controller
     * sees only their zone and gets an entry of their own; anyone else sees
     * nothing and gets a separate empty entry.
     */
    private function cacheKey(string $block, Organization $organization, ?BillingPeriod $billingPeriod = null, ?User $user = null): string
    {
        $key = self::CACHE_PREFIX.":{$block}:organization:{$organization->getKey()}";

        if ($billingPeriod instanceof BillingPeriod) {
            $key .= ":period:{$billingPeriod->getKey()}:{$billingPeriod->status->value}";
        }

        if ($user instanceof User) {
            $key .= ':visibility:'.match (true) {
                $user->isOrganizationOperator($organization) => 'organization',
                $user->isOrganizationController($organization) => "controller:{$user->getKey()}",
                default => 'none',
            };
        }

        return $key;
    }

    private function regionClientCountQuery(): QueryBuilder
    {
        return DB::table('clients')
            ->selectRaw('count(*)')
            ->whereColumn('clients.region_id', 'regions.id')
            ->where('clients.status', 'active');
    }

    private function regionMeterCountQuery(int $organizationId, ?int $billingPeriodId = null): QueryBuilder
    {
        $query = DB::table('meters')
            ->selectRaw('count(*)')
            ->join('clients', 'clients.id', '=', 'meters.client_id')
            ->whereColumn('clients.region_id', 'regions.id')
            ->where('meters.organization_id', $organizationId)
            ->where('meters.status', 'active')
            ->where('clients.status', 'active')
            ->where('clients.billing_type', 'meter');

        if ($billingPeriodId === null) {
            return $query;
        }

        return $query->whereExists(fn (QueryBuilder $query) => TakenMeterReading::applyExists(
            $query,
            $billingPeriodId,
        ));
    }

    /**
     * The table and column names come from this class only, so the raw select is
     * not built from user input.
     */
    private function regionChargeQuery(string $chargeTable, int $organizationId, int $billingPeriodId, bool $onlyDebt): QueryBuilder
    {
        $column = $onlyDebt ? 'closing_balance' : 'amount';

        $query = DB::table($chargeTable)
            ->selectRaw("coalesce(sum({$chargeTable}.{$column}), 0)")
            ->join('clients', 'clients.id', '=', $chargeTable.'.client_id')
            ->whereColumn('clients.region_id', 'regions.id')
            ->where($chargeTable.'.organization_id', $organizationId)
            ->where($chargeTable.'.billing_period_id', $billingPeriodId);

        if ($onlyDebt) {
            $query->where($chargeTable.'.closing_balance', '>', 0);
        }

        return $query;
    }

    private function regionPaymentQuery(int $organizationId, int $billingPeriodId): QueryBuilder
    {
        return DB::table('payments')
            ->selectRaw('coalesce(sum(payments.amount), 0)')
            ->join('clients', 'clients.id', '=', 'payments.client_id')
            ->whereColumn('clients.region_id', 'regions.id')
            ->where('payments.organization_id', $organizationId)
            ->where('payments.billing_period_id', $billingPeriodId);
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
     * One row per client with the positive part of its closing balance as `closing_debit`.
     *
     * A closed period reads the accruals of record. Any other period uses the engine
     * of the payment desk and the turnover balance sheet, so a client without a
     * receipt still carries the debt of the previous periods and the three numbers
     * always agree.
     */
    private function debtQuery(Organization $organization, BillingPeriod $billingPeriod): QueryBuilder
    {
        if ($this->chargeTable($billingPeriod) === 'accruals') {
            return DB::query()->fromSub(
                $this->chargeQuery($organization, $billingPeriod)
                    ->select(['client_id'])
                    ->selectRaw('greatest(closing_balance, 0) as closing_debit'),
                'client_debts',
            );
        }

        return DB::query()->fromSub($this->openPeriodDebtQuery($organization, $billingPeriod), 'client_debts');
    }

    /**
     * Current closing balance of every active client of an open period, computed in
     * SQL with the expressions of `TurnoverBalanceValues`, like the turnover balance
     * sheet does for the same period.
     */
    private function openPeriodDebtQuery(Organization $organization, BillingPeriod $billingPeriod): QueryBuilder
    {
        $values = DB::table('clients')
            ->select(['clients.id as client_id', 'clients.region_id'])
            ->where('clients.organization_id', $organization->getKey())
            ->where('clients.status', 'active')
            ->addSelect(Arr::except(
                TurnoverBalanceValues::openPeriodSubQueries($organization, $billingPeriod),
                [TurnoverBalanceValues::VOLUME],
            ));

        $closingDebit = TurnoverBalanceValues::metricExpressions('turnover_values.')['closing_debit'];

        return DB::query()
            ->fromSub($values, 'turnover_values')
            ->select(['turnover_values.client_id', 'turnover_values.region_id'])
            ->selectRaw("{$closingDebit} as closing_debit");
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
