<?php

namespace App\Reports;

use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\User;
use App\OrganizationMemberRole;
use App\Reports\Concerns\FormatsReportValues;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportSummaryService
{
    use FormatsReportValues;

    /**
     * @var list<string>
     */
    private const SUPPORTED_REPORTS = [
        'meter-reading-sheet',
        'missing-meter-readings',
        'controller-meter-reading-progress',
        'new-client-accounts',
        'payments',
        'unpaid-receipts',
        'meter-installation-replacement',
        'debts',
        'consumption',
    ];

    public function supports(string $reportSlug): bool
    {
        return in_array($reportSlug, self::SUPPORTED_REPORTS, true);
    }

    public function table(
        Table $table,
        string $reportSlug,
        ReportSummaryGroup $group,
        Organization $organization,
        User $user,
    ): Table {
        $billingPeriod = BillingPeriod::currentEditableFor($organization);

        return $table
            ->records(fn (): array => $this->records($reportSlug, $group, $organization, $user, $billingPeriod))
            ->columns($this->columns($reportSlug, $group))
            ->recordUrl(null)
            ->paginated(false)
            ->emptyStateHeading($this->emptyStateHeading($reportSlug, $billingPeriod))
            ->emptyStateDescription($this->emptyStateDescription($reportSlug, $billingPeriod))
            ->striped();
    }

    public function downloadExcel(
        string $reportSlug,
        ReportSummaryGroup $group,
        Organization $organization,
        User $user,
    ): StreamedResponse {
        $billingPeriod = BillingPeriod::currentEditableFor($organization);

        return $this->downloadXlsx(
            $this->excelFileName($reportSlug, $group, $organization, $billingPeriod),
            $this->excelOptions($reportSlug),
            $this->excelHeadings($reportSlug, $group),
            fn (): iterable => array_map(
                fn (array $record): object => (object) $record,
                array_values($this->records($reportSlug, $group, $organization, $user, $billingPeriod)),
            ),
            fn (object $record): array => $this->excelCells($record, $reportSlug),
        );
    }

    /**
     * @return array<string, array<string, float|int|string>>
     */
    public function records(
        string $reportSlug,
        ReportSummaryGroup $group,
        Organization $organization,
        User $user,
        ?BillingPeriod $billingPeriod = null,
    ): array {
        $rows = $this->summaryQuery($reportSlug, $group, $organization, $user, $billingPeriod)
            ->get();

        $records = [];

        foreach ($rows as $row) {
            $records[$this->recordKey($group, $row)] = $this->recordFromRow($reportSlug, $group, $row);
        }

        return $records;
    }

    /**
     * @return list<TextColumn>
     */
    private function columns(string $reportSlug, ReportSummaryGroup $group): array
    {
        $columns = [
            TextColumn::make('group_label')
                ->label($group->heading())
                ->wrap(),
            TextColumn::make('clients_count')
                ->label('Абонентов')
                ->numeric(),
            TextColumn::make('records_count')
                ->label('Строк')
                ->numeric(),
        ];

        foreach ($this->metricDefinitions($reportSlug) as $metric) {
            $columns[] = $this->metricColumn($metric);
        }

        return $columns;
    }

    /**
     * @param  array{key: string, label: string, type: string, aggregate?: string}  $metric
     */
    private function metricColumn(array $metric): TextColumn
    {
        $column = TextColumn::make($metric['key'])
            ->label($metric['label']);

        return match ($metric['type']) {
            'money' => $column->money('KZT'),
            'decimal' => $column->numeric(4),
            'percent' => $column->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2, '.', ' ').'%'),
            default => $column->numeric(),
        };
    }

    private function summaryQuery(
        string $reportSlug,
        ReportSummaryGroup $group,
        Organization $organization,
        User $user,
        ?BillingPeriod $billingPeriod,
    ): QueryBuilder {
        $query = DB::query()
            ->fromSub(
                $this->baseRowsQuery($reportSlug, $organization, $user, $billingPeriod),
                'report_rows',
            );

        $this->applyGrouping($query, $group, $organization, $user);

        $query
            ->selectRaw('count(distinct report_rows.client_id) as clients_count')
            ->selectRaw('count(distinct report_rows.row_key) as records_count');

        foreach ($this->sumMetricDefinitions($reportSlug) as $metric) {
            $key = $metric['key'];

            $query->selectRaw("coalesce(sum(report_rows.{$key}), 0) as {$key}");
        }

        return $query;
    }

    private function applyGrouping(
        QueryBuilder $query,
        ReportSummaryGroup $group,
        Organization $organization,
        User $user,
    ): void {
        match ($group) {
            ReportSummaryGroup::Controller => $this->applyControllerGrouping($query, $organization, $user),
            ReportSummaryGroup::Region => $this->applyRegionGrouping($query),
            ReportSummaryGroup::Street => $this->applyStreetGrouping($query),
        };
    }

    private function applyControllerGrouping(QueryBuilder $query, Organization $organization, User $user): void
    {
        $query
            ->join('organization_user', function (JoinClause $join) use ($organization): void {
                $join
                    ->where('organization_user.organization_id', '=', $organization->getKey())
                    ->where('organization_user.role', '=', OrganizationMemberRole::Controller->value);
            })
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where(function (QueryBuilder $query) use ($organization): void {
                $query
                    ->whereExists(function (QueryBuilder $query) use ($organization): void {
                        $query
                            ->selectRaw('1')
                            ->from('organization_user_regions')
                            ->where('organization_user_regions.organization_id', $organization->getKey())
                            ->whereColumn('organization_user_regions.user_id', 'users.id')
                            ->whereColumn('organization_user_regions.region_id', 'report_rows.region_id');
                    })
                    ->orWhereExists(function (QueryBuilder $query) use ($organization): void {
                        $query
                            ->selectRaw('1')
                            ->from('organization_user_streets')
                            ->where('organization_user_streets.organization_id', $organization->getKey())
                            ->whereColumn('organization_user_streets.user_id', 'users.id')
                            ->whereColumn('organization_user_streets.street_id', 'report_rows.street_id');
                    });
            })
            ->select([
                'users.id as group_id',
                'users.name as controller_name',
                'users.email as controller_email',
            ])
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderBy('users.name')
            ->orderBy('users.id');

        if ($user->isOrganizationController($organization)) {
            $query->where('users.id', $user->getKey());
        }
    }

    private function applyRegionGrouping(QueryBuilder $query): void
    {
        $query
            ->leftJoin('regions', 'regions.id', '=', 'report_rows.region_id')
            ->select([
                'regions.id as group_id',
                'regions.name as region_name',
            ])
            ->groupBy('regions.id', 'regions.name')
            ->orderBy('regions.name');
    }

    private function applyStreetGrouping(QueryBuilder $query): void
    {
        $query
            ->leftJoin('streets', 'streets.id', '=', 'report_rows.street_id')
            ->leftJoin('regions', 'regions.id', '=', 'streets.region_id')
            ->select([
                'streets.id as group_id',
                'streets.name as street_name',
                'regions.name as region_name',
            ])
            ->groupBy('streets.id', 'streets.name', 'regions.name')
            ->orderBy('regions.name')
            ->orderBy('streets.name');
    }

    private function baseRowsQuery(
        string $reportSlug,
        Organization $organization,
        User $user,
        ?BillingPeriod $billingPeriod,
    ): QueryBuilder {
        return match ($reportSlug) {
            'meter-reading-sheet' => $this->meterReadingSheetRows($organization, $user),
            'missing-meter-readings' => $this->missingMeterReadingRows($organization, $user, $billingPeriod),
            'controller-meter-reading-progress' => $this->controllerMeterReadingProgressRows($organization, $user, $billingPeriod),
            'new-client-accounts' => $this->newClientAccountRows($organization, $user, $billingPeriod),
            'payments' => $this->paymentRows($organization, $user, $billingPeriod),
            'unpaid-receipts' => $this->unpaidReceiptRows($organization, $user, $billingPeriod),
            'meter-installation-replacement' => $this->meterInstallationReplacementRows($organization, $user, $billingPeriod),
            'debts' => $this->debtRows($organization, $user, $billingPeriod),
            'consumption' => $this->consumptionRows($organization, $user, $billingPeriod),
            default => throw new InvalidArgumentException("Unsupported summary report [{$reportSlug}]."),
        };
    }

    private function meterReadingSheetRows(Organization $organization, User $user): QueryBuilder
    {
        $query = DB::table('meters')
            ->join('clients', 'clients.id', '=', 'meters.client_id')
            ->selectRaw('meters.id as row_key')
            ->selectRaw('clients.id as client_id')
            ->selectRaw('clients.region_id as region_id')
            ->selectRaw('clients.street_id as street_id')
            ->selectRaw('1 as meters_count')
            ->where('meters.organization_id', $organization->getKey())
            ->where('clients.status', 'active')
            ->where('meters.status', 'active');

        return $this->applyClientVisibility($query, $user, $organization);
    }

    private function missingMeterReadingRows(
        Organization $organization,
        User $user,
        ?BillingPeriod $billingPeriod,
    ): QueryBuilder {
        $query = DB::table('meters')
            ->join('clients', 'clients.id', '=', 'meters.client_id')
            ->selectRaw('meters.id as row_key')
            ->selectRaw('clients.id as client_id')
            ->selectRaw('clients.region_id as region_id')
            ->selectRaw('clients.street_id as street_id')
            ->selectRaw('1 as missing_meters_count')
            ->where('meters.organization_id', $organization->getKey())
            ->where('clients.status', 'active')
            ->where('clients.billing_type', 'meter')
            ->where('meters.status', 'active');

        $this->applyClientVisibility($query, $user, $organization);

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereNotExists(function (QueryBuilder $query) use ($billingPeriod): void {
            $query
                ->selectRaw('1')
                ->from('meter_readings')
                ->whereColumn('meter_readings.meter_id', 'meters.id')
                ->where('meter_readings.billing_period_id', $billingPeriod->getKey());
        });
    }

    private function controllerMeterReadingProgressRows(
        Organization $organization,
        User $user,
        ?BillingPeriod $billingPeriod,
    ): QueryBuilder {
        $query = DB::table('meters')
            ->join('clients', 'clients.id', '=', 'meters.client_id')
            ->selectRaw('meters.id as row_key')
            ->selectRaw('clients.id as client_id')
            ->selectRaw('clients.region_id as region_id')
            ->selectRaw('clients.street_id as street_id')
            ->selectRaw('1 as total_meters')
            ->where('meters.organization_id', $organization->getKey())
            ->where('meters.status', 'active')
            ->where('clients.status', 'active')
            ->where('clients.billing_type', 'meter');

        $this->applyClientVisibility($query, $user, $organization);

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query
                ->selectRaw('0 as read_meters')
                ->whereRaw('1 = 0');
        }

        return $query->selectRaw(
            'case when exists (
                select 1
                from meter_readings
                where meter_readings.meter_id = meters.id
                  and meter_readings.billing_period_id = ?
            ) then 1 else 0 end as read_meters',
            [$billingPeriod->getKey()],
        );
    }

    private function newClientAccountRows(
        Organization $organization,
        User $user,
        ?BillingPeriod $billingPeriod,
    ): QueryBuilder {
        $query = DB::table('clients')
            ->selectRaw('clients.id as row_key')
            ->selectRaw('clients.id as client_id')
            ->selectRaw('clients.region_id as region_id')
            ->selectRaw('clients.street_id as street_id')
            ->selectRaw('1 as new_clients_count');

        $this->applyClientVisibility($query, $user, $organization);

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->whereRaw('1 = 0');
        }

        $periodStartsAt = BillingPeriod::periodStart($billingPeriod->starts_on)->startOfDay();

        return $query->whereBetween('clients.created_at', [
            $periodStartsAt->toDateTimeString(),
            $periodStartsAt->endOfMonth()->endOfDay()->toDateTimeString(),
        ]);
    }

    private function paymentRows(
        Organization $organization,
        User $user,
        ?BillingPeriod $billingPeriod,
    ): QueryBuilder {
        $query = DB::table('payments')
            ->join('clients', 'clients.id', '=', 'payments.client_id')
            ->selectRaw('payments.id as row_key')
            ->selectRaw('clients.id as client_id')
            ->selectRaw('clients.region_id as region_id')
            ->selectRaw('clients.street_id as street_id')
            ->selectRaw('1 as payments_count')
            ->selectRaw('payments.amount as payment_amount')
            ->where('payments.organization_id', $organization->getKey());

        $this->applyClientVisibility($query, $user, $organization);

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('payments.billing_period_id', $billingPeriod->getKey());
    }

    private function unpaidReceiptRows(
        Organization $organization,
        User $user,
        ?BillingPeriod $billingPeriod,
    ): QueryBuilder {
        $query = DB::table('receipts')
            ->join('clients', 'clients.id', '=', 'receipts.client_id')
            ->selectRaw('receipts.id as row_key')
            ->selectRaw('clients.id as client_id')
            ->selectRaw('clients.region_id as region_id')
            ->selectRaw('clients.street_id as street_id')
            ->selectRaw('1 as unpaid_receipts_count')
            ->selectRaw('receipts.amount as receipt_amount')
            ->selectRaw('receipts.paid_amount as paid_amount')
            ->selectRaw('(receipts.amount - receipts.paid_amount) as unpaid_amount')
            ->where('receipts.organization_id', $organization->getKey())
            ->whereRaw('receipts.amount > receipts.paid_amount');

        $this->applyClientVisibility($query, $user, $organization);

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('receipts.billing_period_id', $billingPeriod->getKey());
    }

    private function meterInstallationReplacementRows(
        Organization $organization,
        User $user,
        ?BillingPeriod $billingPeriod,
    ): QueryBuilder {
        $query = DB::table('meters')
            ->join('clients', 'clients.id', '=', 'meters.client_id')
            ->selectRaw('meters.id as row_key')
            ->selectRaw('clients.id as client_id')
            ->selectRaw('clients.region_id as region_id')
            ->selectRaw('clients.street_id as street_id')
            ->where('meters.organization_id', $organization->getKey());

        $this->applyClientVisibility($query, $user, $organization);

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query
                ->selectRaw('0 as installed_meters_count')
                ->selectRaw('0 as removed_meters_count')
                ->whereRaw('1 = 0');
        }

        [$startsOn, $endsOn] = $this->billingPeriodDateRange($billingPeriod);

        return $query
            ->selectRaw('case when meters.installed_on between ? and ? then 1 else 0 end as installed_meters_count', [$startsOn, $endsOn])
            ->selectRaw('case when meters.removed_on between ? and ? then 1 else 0 end as removed_meters_count', [$startsOn, $endsOn])
            ->where(function (QueryBuilder $query) use ($startsOn, $endsOn): void {
                $query
                    ->whereBetween('meters.installed_on', [$startsOn, $endsOn])
                    ->orWhereBetween('meters.removed_on', [$startsOn, $endsOn]);
            });
    }

    private function debtRows(
        Organization $organization,
        User $user,
        ?BillingPeriod $billingPeriod,
    ): QueryBuilder {
        $query = DB::table('receipts')
            ->join('clients', 'clients.id', '=', 'receipts.client_id')
            ->selectRaw('receipts.id as row_key')
            ->selectRaw('clients.id as client_id')
            ->selectRaw('clients.region_id as region_id')
            ->selectRaw('clients.street_id as street_id')
            ->selectRaw('receipts.opening_balance as opening_balance')
            ->selectRaw('receipts.amount as accrual_amount')
            ->selectRaw('receipts.paid_amount as paid_amount')
            ->selectRaw('receipts.adjustment_amount as adjustment_amount')
            ->selectRaw('receipts.closing_balance as debt_amount')
            ->where('receipts.organization_id', $organization->getKey())
            ->where('receipts.closing_balance', '>', 0);

        $this->applyClientVisibility($query, $user, $organization);

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('receipts.billing_period_id', $billingPeriod->getKey());
    }

    private function consumptionRows(
        Organization $organization,
        User $user,
        ?BillingPeriod $billingPeriod,
    ): QueryBuilder {
        $query = DB::table('meter_readings')
            ->join('clients', 'clients.id', '=', 'meter_readings.client_id')
            ->selectRaw('meter_readings.id as row_key')
            ->selectRaw('clients.id as client_id')
            ->selectRaw('clients.region_id as region_id')
            ->selectRaw('clients.street_id as street_id')
            ->selectRaw('1 as readings_count')
            ->selectRaw('meter_readings.consumption as consumption')
            ->where('meter_readings.organization_id', $organization->getKey());

        $this->applyClientVisibility($query, $user, $organization);

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('meter_readings.billing_period_id', $billingPeriod->getKey());
    }

    private function applyClientVisibility(
        QueryBuilder $query,
        User $user,
        Organization $organization,
        string $clientAlias = 'clients',
    ): QueryBuilder {
        $query->where("{$clientAlias}.organization_id", $organization->getKey());

        if ($user->isOrganizationOperator($organization)) {
            return $query;
        }

        if (! $user->isOrganizationController($organization)) {
            return $query->whereRaw('1 = 0');
        }

        $regionIds = $user->assignedRegionIdsForOrganization($organization);
        $streetIds = $user->assignedStreetIdsForOrganization($organization);

        if ($regionIds === [] && $streetIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (QueryBuilder $query) use ($clientAlias, $regionIds, $streetIds): void {
            if ($regionIds !== []) {
                $query->whereIn("{$clientAlias}.region_id", $regionIds);
            }

            if ($streetIds !== []) {
                $method = $regionIds === [] ? 'whereIn' : 'orWhereIn';

                $query->{$method}("{$clientAlias}.street_id", $streetIds);
            }
        });
    }

    /**
     * @return list<array{key: string, label: string, type: string, aggregate?: string}>
     */
    private function metricDefinitions(string $reportSlug): array
    {
        return match ($reportSlug) {
            'meter-reading-sheet' => [
                ['key' => 'meters_count', 'label' => 'Счётчиков', 'type' => 'integer'],
            ],
            'missing-meter-readings' => [
                ['key' => 'missing_meters_count', 'label' => 'Не снято счётчиков', 'type' => 'integer'],
            ],
            'controller-meter-reading-progress' => [
                ['key' => 'total_meters', 'label' => 'Всего счётчиков', 'type' => 'integer'],
                ['key' => 'read_meters', 'label' => 'Снято', 'type' => 'integer'],
                ['key' => 'missing_meters', 'label' => 'Не снято', 'type' => 'integer', 'aggregate' => 'computed'],
                ['key' => 'completion_percent', 'label' => 'Процент снятия', 'type' => 'percent', 'aggregate' => 'computed'],
            ],
            'new-client-accounts' => [
                ['key' => 'new_clients_count', 'label' => 'Новых лицевых счетов', 'type' => 'integer'],
            ],
            'payments' => [
                ['key' => 'payments_count', 'label' => 'Оплат', 'type' => 'integer'],
                ['key' => 'payment_amount', 'label' => 'Сумма оплат', 'type' => 'money'],
            ],
            'unpaid-receipts' => [
                ['key' => 'unpaid_receipts_count', 'label' => 'Неоплаченных квитанций', 'type' => 'integer'],
                ['key' => 'receipt_amount', 'label' => 'Начислено', 'type' => 'money'],
                ['key' => 'paid_amount', 'label' => 'Оплачено', 'type' => 'money'],
                ['key' => 'unpaid_amount', 'label' => 'Не оплачено', 'type' => 'money'],
            ],
            'meter-installation-replacement' => [
                ['key' => 'installed_meters_count', 'label' => 'Установлено', 'type' => 'integer'],
                ['key' => 'removed_meters_count', 'label' => 'Снято / заменено', 'type' => 'integer'],
            ],
            'debts' => [
                ['key' => 'opening_balance', 'label' => 'Начальное сальдо', 'type' => 'money'],
                ['key' => 'accrual_amount', 'label' => 'Начислено', 'type' => 'money'],
                ['key' => 'paid_amount', 'label' => 'Оплачено', 'type' => 'money'],
                ['key' => 'adjustment_amount', 'label' => 'Корректировка', 'type' => 'money'],
                ['key' => 'debt_amount', 'label' => 'Долг', 'type' => 'money'],
            ],
            'consumption' => [
                ['key' => 'readings_count', 'label' => 'Показаний', 'type' => 'integer'],
                ['key' => 'consumption', 'label' => 'Потребление', 'type' => 'decimal'],
            ],
            default => throw new InvalidArgumentException("Unsupported summary report [{$reportSlug}]."),
        };
    }

    /**
     * @return list<array{key: string, label: string, type: string, aggregate?: string}>
     */
    private function sumMetricDefinitions(string $reportSlug): array
    {
        return array_values(array_filter(
            $this->metricDefinitions($reportSlug),
            fn (array $metric): bool => ($metric['aggregate'] ?? 'sum') === 'sum',
        ));
    }

    /**
     * @return array<string, float|int|string>
     */
    private function recordFromRow(string $reportSlug, ReportSummaryGroup $group, object $row): array
    {
        $record = [
            'group_label' => $this->groupLabel($group, $row),
            'clients_count' => (int) $row->clients_count,
            'records_count' => (int) $row->records_count,
        ];

        foreach ($this->metricDefinitions($reportSlug) as $metric) {
            $record[$metric['key']] = $this->metricValue($metric, $row, $record);
        }

        return $record;
    }

    /**
     * @param  array{key: string, label: string, type: string, aggregate?: string}  $metric
     * @param  array<string, float|int|string>  $record
     */
    private function metricValue(array $metric, object $row, array $record): float|int
    {
        if (($metric['aggregate'] ?? 'sum') === 'computed') {
            return $this->computedMetricValue($metric['key'], $record);
        }

        $value = (float) ($row->{$metric['key']} ?? 0);

        return $metric['type'] === 'integer' ? (int) round($value) : $value;
    }

    /**
     * @param  array<string, float|int|string>  $record
     */
    private function computedMetricValue(string $key, array $record): float|int
    {
        $totalMeters = (int) ($record['total_meters'] ?? 0);
        $readMeters = (int) ($record['read_meters'] ?? 0);

        return match ($key) {
            'missing_meters' => max(0, $totalMeters - $readMeters),
            'completion_percent' => $totalMeters === 0 ? 0.0 : round(($readMeters / $totalMeters) * 100, 2),
            default => 0,
        };
    }

    private function groupLabel(ReportSummaryGroup $group, object $row): string
    {
        return match ($group) {
            ReportSummaryGroup::Controller => (string) ($row->controller_name ?: 'Без имени'),
            ReportSummaryGroup::Region => (string) ($row->region_name ?: 'Без района'),
            ReportSummaryGroup::Street => $this->streetLabel($row),
        };
    }

    private function streetLabel(object $row): string
    {
        $streetName = (string) ($row->street_name ?: 'Без улицы');
        $regionName = $row->region_name;

        if ($regionName) {
            return $regionName.' / '.$streetName;
        }

        return $streetName;
    }

    private function recordKey(ReportSummaryGroup $group, object $row): string
    {
        return $group->value.':'.($row->group_id ?? 'none');
    }

    private function emptyStateHeading(string $reportSlug, ?BillingPeriod $billingPeriod): string
    {
        if ($this->requiresBillingPeriod($reportSlug) && ! $billingPeriod instanceof BillingPeriod) {
            return 'Расчётный месяц не открыт';
        }

        return 'Нет данных для сводки';
    }

    private function emptyStateDescription(string $reportSlug, ?BillingPeriod $billingPeriod): string
    {
        if ($this->requiresBillingPeriod($reportSlug) && ! $billingPeriod instanceof BillingPeriod) {
            return 'Откройте расчётный месяц, чтобы увидеть сводный отчёт.';
        }

        return 'По выбранной группировке нет строк отчёта.';
    }

    private function requiresBillingPeriod(string $reportSlug): bool
    {
        return $reportSlug !== 'meter-reading-sheet';
    }

    private function excelFileName(
        string $reportSlug,
        ReportSummaryGroup $group,
        Organization $organization,
        ?BillingPeriod $billingPeriod,
    ): string {
        return sprintf(
            'summary-%s-%s-%d-%s-%s.xlsx',
            $reportSlug,
            $group->value,
            $organization->getKey(),
            $this->excelPeriodCode($reportSlug, $billingPeriod),
            today()->format('Y-m-d'),
        );
    }

    private function excelPeriodCode(string $reportSlug, ?BillingPeriod $billingPeriod): string
    {
        if (! $this->requiresBillingPeriod($reportSlug)) {
            return 'all';
        }

        return $billingPeriod?->code ?? 'no-open-period';
    }

    private function excelOptions(string $reportSlug): Options
    {
        $options = new Options;
        $columnsCount = count($this->excelHeadings($reportSlug, ReportSummaryGroup::Controller));

        $options->setColumnWidth(32, 1);

        for ($column = 2; $column <= $columnsCount; $column++) {
            $options->setColumnWidth(18, $column);
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function excelHeadings(string $reportSlug, ReportSummaryGroup $group): array
    {
        return [
            $group->heading(),
            'Абонентов',
            'Строк',
            ...array_map(
                fn (array $metric): string => $metric['label'],
                $this->metricDefinitions($reportSlug),
            ),
        ];
    }

    /**
     * @return list<Cell>
     */
    private function excelCells(object $record, string $reportSlug): array
    {
        $cells = [
            new StringCell((string) $record->group_label, null),
            new NumericCell((int) $record->clients_count, null),
            new NumericCell((int) $record->records_count, null),
        ];

        foreach ($this->metricDefinitions($reportSlug) as $metric) {
            $cells[] = $this->metricExcelCell($record->{$metric['key']} ?? 0, $metric);
        }

        return $cells;
    }

    /**
     * @param  array{key: string, label: string, type: string, aggregate?: string}  $metric
     */
    private function metricExcelCell(mixed $value, array $metric): Cell
    {
        if ($metric['type'] === 'integer') {
            return new NumericCell((int) $value, null);
        }

        if ($metric['type'] === 'decimal') {
            return new NumericCell((float) $value, (new Style)->setFormat('0.0000'));
        }

        if ($metric['type'] === 'percent') {
            return new NumericCell((float) $value, (new Style)->setFormat('0.00'));
        }

        return new NumericCell((float) $value, null);
    }
}
