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
use App\Reports\Concerns\AppliesReportFilters;
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

class MissingMeterReadingsReport implements FiltersExcelExport, OrganizationReport
{
    use AppliesReportFilters;

    public function slug(): string
    {
        return 'missing-meter-readings';
    }

    public function title(): string
    {
        return 'Список не снятых показаний';
    }

    public function description(): ?string
    {
        return 'Активные счётчики активных абонентов с типом начисления «по счётчику», по которым нет показания за текущий расчётный месяц.';
    }

    public function table(Table $table, Organization $organization, User $user): Table
    {
        $billingPeriod = BillingPeriod::currentEditableFor($organization);

        return $table
            ->query($this->query($organization, $user, $billingPeriod))
            ->columns([
                TextColumn::make('client.account_number')
                    ->label('Лицевой счёт')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('clients.account_number', 'like', '%'.$search.'%')),
                TextColumn::make('client.name')
                    ->label('ФИО')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('clients.name', 'like', '%'.$search.'%')),
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
                TextColumn::make('missing_period')
                    ->label('Период')
                    ->state(fn (): string => $billingPeriod?->label ?? '-'),
                TextColumn::make('previous_reading_for_report')
                    ->label('Предыдущее показание')
                    ->state(fn (Meter $record): int => $this->previousReading($record))
                    ->numeric(0),
            ])
            ->filters($this->filters($organization))
            ->recordUrl(null)
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading($billingPeriod instanceof BillingPeriod ? 'Все показания сняты' : 'Расчётный месяц не открыт')
            ->emptyStateDescription($billingPeriod instanceof BillingPeriod
                ? 'За текущий расчётный месяц нет активных счётчиков без показаний.'
                : 'Откройте расчётный месяц, чтобы увидеть список не снятых показаний.')
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
        $billingPeriod = BillingPeriod::currentEditableFor($organization);
        $query = $this->applyReportFilters(
            $this->query($organization, $user, $billingPeriod),
            $this->filters($organization),
            $filters,
        );

        return response()->streamDownload(
            function () use ($query, $billingPeriod): void {
                $writer = new Writer($this->excelOptions());
                $writer->openToFile('php://output');

                $writer->addRow(new Row($this->excelHeadingCells()));

                foreach ($query->lazy(500) as $meter) {
                    $writer->addRow(new Row($this->excelCells($meter, $billingPeriod)));
                }

                $writer->close();
            },
            $this->excelFileName($organization, $billingPeriod),
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

    private function query(Organization $organization, User $user, ?BillingPeriod $billingPeriod): Builder
    {
        $query = Meter::query()
            ->select('meters.*')
            ->join('clients', 'clients.id', '=', 'meters.client_id')
            ->with([
                'client.region',
                'client.street',
            ])
            ->visibleToOrganizationMember($user, $organization)
            ->where('clients.status', 'active')
            ->where('clients.billing_type', 'meter')
            ->where('meters.status', 'active')
            ->orderBy('clients.account_number')
            ->orderBy('meters.number');

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->where('meters.id', 0);
        }

        return $query
            ->addSelect([
                'previous_reading_for_report' => MeterReading::query()
                    ->select('current_reading')
                    ->whereColumn('meter_readings.meter_id', 'meters.id')
                    ->taken()
                    ->whereHas(
                        'billingPeriod',
                        fn (Builder $query): Builder => $query->whereDate(
                            'starts_on',
                            '<',
                            $billingPeriod->starts_on->toDateString(),
                        ),
                    )
                    ->orderByDesc(
                        BillingPeriod::query()
                            ->select('starts_on')
                            ->whereColumn('billing_periods.id', 'meter_readings.billing_period_id'),
                    )
                    ->orderByDesc('id')
                    ->limit(1),
            ])
            // A visit that took no reading leaves the meter unread, so it has
            // to stay on the list the operator chases.
            ->whereDoesntHave(
                'readings',
                fn (Builder $query): Builder => $query->whereBelongsTo($billingPeriod)->taken(),
            );
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

    private function excelFileName(Organization $organization, ?BillingPeriod $billingPeriod): string
    {
        return sprintf(
            'missing-meter-readings-%d-%s-%s.xlsx',
            $organization->getKey(),
            $billingPeriod?->code ?? 'no-open-period',
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
        $options->setColumnWidth(14, 7);
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
                'Период',
                'Предыдущее показание',
            ],
        );
    }

    /**
     * @return list<Cell>
     */
    private function excelCells(Meter $meter, ?BillingPeriod $billingPeriod): array
    {
        $client = $meter->client;

        return [
            new StringCell((string) ($client?->account_number ?? ''), null),
            new StringCell((string) ($client?->name ?? ''), null),
            new StringCell($this->formatAddress($meter), (new Style)->setShouldWrapText()),
            $client?->residents_count === null
                ? new EmptyCell(null, null)
                : new NumericCell($client->residents_count, null),
            new StringCell((string) $meter->number, null),
            new StringCell($meter->installed_on?->format('d.m.Y') ?? '', null),
            new StringCell($billingPeriod?->label ?? '', null),
            new NumericCell($this->previousReading($meter), (new Style)->setFormat('0')),
        ];
    }

    private function previousReading(Meter $meter): int
    {
        return MeterReading::wholeReading(
            $meter->getAttribute('previous_reading_for_report') ?? $meter->initial_reading,
        ) ?? 0;
    }
}
