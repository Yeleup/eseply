<?php

namespace App\Reports;

use App\Filament\Support\ClientAddressFilter;
use App\Filament\Support\ControllerZoneFilter;
use App\Filament\Support\DateRangeFilter;
use App\Models\BillingPeriod;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use App\Reports\Contracts\FiltersExcelExport;
use App\Reports\Contracts\OrganizationReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeterReadingSheetReport implements FiltersExcelExport, OrganizationReport
{
    public function slug(): string
    {
        return 'meter-reading-sheet';
    }

    public function title(): string
    {
        return 'Ведомость снятия показаний';
    }

    public function description(): ?string
    {
        return 'Активные счётчики активных абонентов выбранной организации. Если у абонента несколько счётчиков, они выводятся соседними строками.';
    }

    public function table(Table $table, Organization $organization, User $user): Table
    {
        return $table
            ->query($this->query($organization, $user))
            ->columns([
                TextColumn::make('client.account_number')
                    ->label('Лицевой счёт')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('clients.account_number', 'like', "%{$search}%")),
                TextColumn::make('client.name')
                    ->label('ФИО')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('clients.name', 'like', "%{$search}%")),
                TextColumn::make('client_address')
                    ->label('Адрес')
                    ->state(fn (Meter $record): string => $this->formatAddress($record)),
                TextColumn::make('client.residents_count')
                    ->label('Кол. проживающих')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('number')
                    ->label('Счётчик')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('installed_on')
                    ->label('Дата установки')
                    ->date('d.m.Y')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('previous_reading_for_report')
                    ->label('Предыдущее показание')
                    ->state(fn (Meter $record): int => $this->previousReading($record))
                    ->numeric(0),
                TextColumn::make('current_reading_for_report')
                    ->label('Текущее показание')
                    ->state(fn (Meter $record): ?int => $this->currentReading($record))
                    ->numeric(0)
                    ->placeholder(''),
            ])
            ->filters($this->filters($organization))
            ->recordUrl(null)
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Нет активных счётчиков')
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
        $query = $this->filteredQuery($organization, $user, $filters);

        return response()->streamDownload(
            function () use ($query): void {
                $writer = new Writer($this->excelOptions());
                $writer->openToFile('php://output');

                $writer->addRow(new Row($this->excelHeadingCells()));

                foreach ($query->lazy(500) as $meter) {
                    $writer->addRow(new Row($this->excelCells($meter)));
                }

                $writer->close();
            },
            $this->excelFileName($organization),
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        );
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
            ControllerZoneFilter::make($organization),
            DateRangeFilter::make('installed_on', 'Дата установки', 'meters.installed_on'),
        ];
    }

    /**
     * Filters are applied exactly the way Filament applies them to the screen table:
     * base-query callbacks first, then every filter inside one nested group.
     *
     * @param  array<string, array<string, mixed>>  $filters
     */
    private function filteredQuery(Organization $organization, User $user, array $filters): Builder
    {
        $query = $this->query($organization, $user);
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

    private function query(Organization $organization, User $user): Builder
    {
        $billingPeriod = BillingPeriod::currentEditableFor($organization);

        return Meter::query()
            ->select('meters.*')
            ->addSelect([
                'previous_reading_for_report' => $this->previousReadingSubquery($billingPeriod),
                'current_reading_for_report' => $this->currentReadingSubquery($billingPeriod),
            ])
            ->join('clients', 'clients.id', '=', 'meters.client_id')
            ->with([
                'client.region',
                'client.street',
            ])
            ->visibleToOrganizationMember($user, $organization)
            ->where('clients.status', 'active')
            ->where('meters.status', 'active')
            ->orderBy('clients.account_number')
            ->orderBy('meters.number');
    }

    /**
     * The reading at the start of the current billing month: the latest taken reading
     * of a strictly earlier month, the same rule as the «Предыдущее» column of bulk
     * meter-reading entry. Without an editable month every taken reading counts.
     *
     * @return Builder<MeterReading>
     */
    private function previousReadingSubquery(?BillingPeriod $billingPeriod): Builder
    {
        return MeterReading::query()
            ->select('current_reading')
            ->whereColumn('meter_readings.meter_id', 'meters.id')
            ->taken()
            ->when(
                $billingPeriod instanceof BillingPeriod,
                fn (Builder $query): Builder => $query->whereHas(
                    'billingPeriod',
                    fn (Builder $periodQuery): Builder => $periodQuery->whereDate(
                        'starts_on',
                        '<',
                        $billingPeriod->starts_on->toDateString(),
                    ),
                ),
            )
            ->orderByDesc(
                BillingPeriod::query()
                    ->select('starts_on')
                    ->whereColumn('billing_periods.id', 'meter_readings.billing_period_id'),
            )
            ->orderByDesc('id')
            ->limit(1);
    }

    /**
     * The reading taken in the current billing month; null until it is taken,
     * and always null without an editable month.
     *
     * @return Builder<MeterReading>
     */
    private function currentReadingSubquery(?BillingPeriod $billingPeriod): Builder
    {
        return MeterReading::query()
            ->select('current_reading')
            ->whereColumn('meter_readings.meter_id', 'meters.id')
            ->when(
                $billingPeriod instanceof BillingPeriod,
                fn (Builder $query): Builder => $query->where('meter_readings.billing_period_id', $billingPeriod->getKey()),
                // `where(column, null)` would match readings without a month instead of none.
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            )
            ->taken()
            ->orderByDesc('id')
            ->limit(1);
    }

    private function formatAddress(Meter $meter): string
    {
        $client = $meter->client;

        if (! $client) {
            return '-';
        }

        /** @var Collection<int, string> $parts */
        $parts = collect([
            $client->region?->name,
            $client->street?->name,
            filled($client->house) ? 'д. '.$client->house : null,
            filled($client->apartment) ? 'кв. '.$client->apartment : null,
        ])->filter(fn (?string $part): bool => filled($part));

        return $parts->isEmpty() ? '-' : $parts->implode(', ');
    }

    private function excelFileName(Organization $organization): string
    {
        return sprintf(
            'meter-reading-sheet-%d-%s.xlsx',
            $organization->getKey(),
            today()->format('Y-m-d'),
        );
    }

    private function excelOptions(): Options
    {
        $options = new Options;
        $options->setColumnWidth(16, 1);
        $options->setColumnWidth(28, 2);
        $options->setColumnWidth(36, 3);
        $options->setColumnWidth(18, 4);
        $options->setColumnWidth(18, 5);
        $options->setColumnWidth(18, 6);
        $options->setColumnWidth(22, 7);
        $options->setColumnWidth(22, 8);

        return $options;
    }

    /**
     * @return list<Cell>
     */
    private function excelHeadingCells(): array
    {
        $style = (new Style)
            ->setFontBold()
            ->setBackgroundColor(Color::rgb(229, 231, 235));

        return array_map(
            fn (string $heading): StringCell => new StringCell($heading, $style),
            [
                'Лицевой счёт',
                'ФИО',
                'Адрес',
                'Кол. проживающих',
                'Счётчик',
                'Дата установки',
                'Предыдущее показание',
                'Текущее показание',
            ],
        );
    }

    /**
     * @return list<Cell>
     */
    private function excelCells(Meter $meter): array
    {
        $client = $meter->client;
        $currentReading = $this->currentReading($meter);

        return [
            new StringCell((string) ($client?->account_number ?? ''), null),
            new StringCell((string) ($client?->name ?? ''), null),
            new StringCell($this->formatAddress($meter), (new Style)->setShouldWrapText()),
            $client?->residents_count === null
                ? new EmptyCell(null, null)
                : new NumericCell($client->residents_count, null),
            new StringCell((string) $meter->number, null),
            new StringCell($meter->installed_on?->format('d.m.Y') ?? '', null),
            new NumericCell($this->previousReading($meter), (new Style)->setFormat('0')),
            $currentReading === null
                ? new EmptyCell(null, null)
                : new NumericCell($currentReading, (new Style)->setFormat('0')),
        ];
    }

    private function previousReading(Meter $meter): int
    {
        return MeterReading::wholeReading(
            $meter->getAttribute('previous_reading_for_report') ?? $meter->initial_reading,
        ) ?? 0;
    }

    private function currentReading(Meter $meter): ?int
    {
        return MeterReading::wholeReading($meter->getAttribute('current_reading_for_report'));
    }
}
