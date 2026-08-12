<?php

namespace App\Reports;

use App\BillingPeriodStatus;
use App\Filament\Support\ClientAddressFilter;
use App\Filament\Support\ControllerZoneFilter;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Reports\Concerns\FormatsReportValues;
use App\Reports\Contracts\FiltersExcelExport;
use App\Reports\Contracts\OrganizationReport;
use App\Reports\Contracts\SelectsBillingPeriod;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TurnoverBalanceSheetReport implements FiltersExcelExport, OrganizationReport, SelectsBillingPeriod
{
    use FormatsReportValues;

    /**
     * Column label of every reported value, in report order.
     *
     * @var array<string, string>
     */
    private const array METRIC_LABELS = [
        'opening_debit' => 'Сальдо нач. Дебет',
        'opening_credit' => 'Сальдо нач. Кредит',
        'turnover_debit' => 'Оборот Дебет',
        'turnover_credit' => 'Оборот Кредит',
        'closing_debit' => 'Сальдо кон. Дебет',
        'closing_credit' => 'Сальдо кон. Кредит',
        'accrued_amount' => 'Начислено',
        'adjustment_amount' => 'Корректировка',
        'paid_amount' => 'Оплачено',
    ];

    private ?BillingPeriod $billingPeriod = null;

    public function slug(): string
    {
        return 'turnover-balance-sheet';
    }

    public function title(): string
    {
        return 'Оборотно-сальдовая ведомость';
    }

    public function description(): ?string
    {
        return 'Сальдо на начало, обороты за период и сальдо на конец по лицевым счетам выбранного расчётного месяца. Дебет — долг абонента, кредит — переплата.';
    }

    public function defaultBillingPeriodFor(Organization $organization): ?BillingPeriod
    {
        return BillingPeriod::query()
            ->forOrganization($organization)
            ->where('status', BillingPeriodStatus::Closed->value)
            ->orderByDesc('starts_on')
            ->first()
            ?? BillingPeriod::currentEditableFor($organization)
            ?? BillingPeriod::query()
                ->forOrganization($organization)
                ->orderByDesc('starts_on')
                ->first();
    }

    public function forBillingPeriod(?BillingPeriod $billingPeriod): static
    {
        $report = clone $this;
        $report->billingPeriod = $billingPeriod;

        return $report;
    }

    public function table(Table $table, Organization $organization, User $user): Table
    {
        $billingPeriod = $this->billingPeriod;

        return $table
            ->query($this->query($organization, $user, $billingPeriod))
            ->columns([
                TextColumn::make('account_number')
                    ->label('Лицевой счёт')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('clients.account_number', 'like', "%{$search}%"))
                    ->sortable(['clients.account_number']),
                TextColumn::make('name')
                    ->label('Абонент')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('clients.name', 'like', "%{$search}%"))
                    ->sortable(['clients.name']),
                TextColumn::make('client_address')
                    ->label('Адрес')
                    ->state(fn (Client $record): string => $this->formatClientAddress($record))
                    ->wrap(),
                TextColumn::make('billing_period_label')
                    ->label('Период')
                    ->state(fn (): string => $billingPeriod?->label ?? '-'),
                ...$this->metricColumns(),
            ])
            ->filters($this->filters($organization))
            ->recordUrl(null)
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading($billingPeriod instanceof BillingPeriod
                ? 'Нет данных за выбранный расчётный месяц'
                : 'Расчётный месяц не найден')
            ->emptyStateDescription($billingPeriod instanceof BillingPeriod
                ? 'За выбранный расчётный месяц нет лицевых счетов с сальдо и оборотами.'
                : 'Откройте расчётный месяц, чтобы построить оборотно-сальдовую ведомость.')
            ->striped();
    }

    public function downloadExcel(Organization $organization, User $user): StreamedResponse
    {
        return $this->downloadFilteredExcel($organization, $user, []);
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    public function downloadFilteredExcel(Organization $organization, User $user, array $filters): StreamedResponse
    {
        $billingPeriod = $this->billingPeriod;
        $query = $this->filteredQuery($organization, $user, $filters);

        return response()->streamDownload(
            function () use ($query, $billingPeriod): void {
                $writer = new Writer($this->excelOptions());
                $writer->openToFile('php://output');

                foreach ($this->excelHeadingRows() as $headingRow) {
                    $writer->addRow($headingRow);
                }

                $totals = array_fill_keys(array_keys(self::METRIC_LABELS), 0.0);

                foreach ($query->lazy(500) as $client) {
                    $metrics = TurnoverBalanceValues::metricsOf($client);

                    foreach ($metrics as $key => $value) {
                        $totals[$key] += $value;
                    }

                    $writer->addRow(new Row($this->excelCells($client, $metrics, $billingPeriod)));
                }

                $writer->addRow(new Row($this->excelTotalCells($totals)));

                $writer->close();
            },
            $this->excelFileName($organization, $billingPeriod),
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        );
    }

    /**
     * @return list<TextColumn>
     */
    private function metricColumns(): array
    {
        $expressions = TurnoverBalanceValues::metricExpressions();

        return array_values(array_map(
            fn (string $key): TextColumn => TextColumn::make($key)
                ->label(self::METRIC_LABELS[$key])
                ->state(fn (Client $record): float => TurnoverBalanceValues::metricsOf($record)[$key])
                ->money('KZT')
                ->summarize($this->metricSummarizer($expressions[$key])),
            array_keys(self::METRIC_LABELS),
        ));
    }

    /**
     * Filament wraps the table query into a derived table before calling the summarizer,
     * so the aliases selected by the report query can be aggregated directly.
     */
    private function metricSummarizer(string $expression): Summarizer
    {
        return Summarizer::make('total')
            ->label('Итого')
            ->money('KZT')
            ->using(fn (QueryBuilder $query): float => (float) ($query
                ->selectRaw("coalesce(sum({$expression}), 0) as aggregate")
                ->value('aggregate') ?? 0));
    }

    /**
     * The screen table and the XLSX export share one filter definition, so both apply identical rules.
     *
     * @return list<BaseFilter>
     */
    private function filters(Organization $organization): array
    {
        return [
            ClientAddressFilter::make($organization, 'clients.region_id', 'clients.street_id'),
            ControllerZoneFilter::make($organization, null),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     * @return Builder<Client>
     */
    private function filteredQuery(Organization $organization, User $user, array $filters): Builder
    {
        $query = $this->query($organization, $user, $this->billingPeriod);
        $reportFilters = $this->filters($organization);

        foreach ($reportFilters as $filter) {
            $filter->applyToBaseQuery($query, $filters[$filter->getName()] ?? []);
        }

        return $query->where(function (Builder $query) use ($reportFilters, $filters): void {
            foreach ($reportFilters as $filter) {
                $filter->apply($query, $filters[$filter->getName()] ?? []);
            }
        });
    }

    /**
     * @return Builder<Client>
     */
    private function query(Organization $organization, User $user, ?BillingPeriod $billingPeriod): Builder
    {
        $query = Client::query()
            ->select('clients.*')
            ->with([
                'region',
                'street',
            ])
            ->visibleToOrganizationMember($user, $organization)
            ->orderBy('clients.account_number')
            ->orderBy('clients.id');

        if (! $billingPeriod instanceof BillingPeriod) {
            return $this->withoutValues($query);
        }

        if (TurnoverBalanceValues::isClosed($billingPeriod)) {
            return $query
                ->join('accruals', function (JoinClause $join) use ($billingPeriod): void {
                    TurnoverBalanceValues::joinAccrual($join, $billingPeriod);
                })
                ->addSelect(TurnoverBalanceValues::closedPeriodColumns());
        }

        return $query
            ->where('clients.status', 'active')
            ->addSelect(TurnoverBalanceValues::openPeriodSubQueries($organization, $billingPeriod));
    }

    /**
     * Keeps the alias list of the query stable when there is no billing period to report on,
     * so the summarizers still have the columns they aggregate.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    private function withoutValues(Builder $query): Builder
    {
        foreach ([
            TurnoverBalanceValues::OPENING_BALANCE,
            TurnoverBalanceValues::ACCRUED_AMOUNT,
            TurnoverBalanceValues::PAID_AMOUNT,
            TurnoverBalanceValues::ADJUSTMENT_AMOUNT,
        ] as $alias) {
            $query->selectRaw("0 as {$alias}");
        }

        return $query->whereRaw('1 = 0');
    }

    private function excelFileName(Organization $organization, ?BillingPeriod $billingPeriod): string
    {
        return sprintf(
            'turnover-balance-sheet-%d-%s-%s.xlsx',
            $organization->getKey(),
            $billingPeriod?->code ?? 'no-period',
            today()->format('Y-m-d'),
        );
    }

    private function excelOptions(): Options
    {
        $options = new Options;
        $options->setColumnWidth(16, 1);
        $options->setColumnWidth(28, 2);
        $options->setColumnWidth(36, 3);
        $options->setColumnWidth(12, 4);

        for ($column = 5; $column <= 13; $column++) {
            $options->setColumnWidth(18, $column);
        }

        $options->mergeCells(0, 1, 0, 2);
        $options->mergeCells(1, 1, 1, 2);
        $options->mergeCells(2, 1, 2, 2);
        $options->mergeCells(3, 1, 3, 2);
        $options->mergeCells(4, 1, 5, 1);
        $options->mergeCells(6, 1, 7, 1);
        $options->mergeCells(8, 1, 9, 1);
        $options->mergeCells(10, 1, 12, 1);

        return $options;
    }

    /**
     * @return list<Row>
     */
    private function excelHeadingRows(): array
    {
        return [
            new Row($this->excelHeadingCells([
                'Лицевой счёт',
                'Абонент',
                'Адрес',
                'Период',
                'Сальдо на начало периода',
                '',
                'Обороты за период',
                '',
                'Сальдо на конец периода',
                '',
                'Расшифровка оборотов',
                '',
                '',
            ])),
            new Row($this->excelHeadingCells([
                '',
                '',
                '',
                '',
                'Дебет',
                'Кредит',
                'Дебет',
                'Кредит',
                'Дебет',
                'Кредит',
                'Начислено',
                'Корректировка',
                'Оплачено',
            ])),
        ];
    }

    /**
     * @param  array<string, float>  $metrics
     * @return list<Cell>
     */
    private function excelCells(Client $client, array $metrics, ?BillingPeriod $billingPeriod): array
    {
        return [
            new StringCell((string) $client->account_number, null),
            new StringCell((string) $client->name, null),
            new StringCell($this->formatClientAddress($client), (new Style)->setShouldWrapText()),
            new StringCell($billingPeriod?->label ?? '', null),
            ...array_map(
                fn (string $key): NumericCell => new NumericCell($metrics[$key], null),
                array_keys(self::METRIC_LABELS),
            ),
        ];
    }

    /**
     * @param  array<string, float>  $totals
     * @return list<Cell>
     */
    private function excelTotalCells(array $totals): array
    {
        $style = (new Style)->setFontBold();

        return [
            new StringCell('Итого', $style),
            new StringCell('', $style),
            new StringCell('', $style),
            new StringCell('', $style),
            ...array_map(
                fn (string $key): NumericCell => new NumericCell($totals[$key], $style),
                array_keys(self::METRIC_LABELS),
            ),
        ];
    }
}
