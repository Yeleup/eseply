<?php

namespace App\Reports;

use App\Filament\Support\ClientAddressFilter;
use App\Filament\Support\ControllerZoneFilter;
use App\Filament\Support\DateRangeFilter;
use App\Models\BillingPeriod;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use App\Reports\Concerns\AppliesReportFilters;
use App\Reports\Concerns\FormatsReportValues;
use App\Reports\Contracts\FiltersExcelExport;
use App\Reports\Contracts\OrganizationReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConsumptionReport implements FiltersExcelExport, OrganizationReport
{
    use AppliesReportFilters;
    use FormatsReportValues;

    public function slug(): string
    {
        return 'consumption';
    }

    public function title(): string
    {
        return 'Отчёт по потреблениям';
    }

    public function description(): ?string
    {
        return 'Потребление по показаниям счётчиков за текущий открытый или ошибочный расчётный месяц.';
    }

    public function table(Table $table, Organization $organization, User $user): Table
    {
        $billingPeriod = BillingPeriod::currentEditableFor($organization);

        return $table
            ->query($this->query($organization, $user, $billingPeriod))
            ->columns([
                TextColumn::make('client.account_number')
                    ->label('Лицевой счёт')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('clients.account_number', 'like', '%'.$search.'%')),
                TextColumn::make('client.name')
                    ->label('Абонент')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('clients.name', 'like', '%'.$search.'%')),
                TextColumn::make('client_address')
                    ->label('Адрес')
                    ->state(fn (MeterReading $record): string => $this->formatClientAddress($record->client)),
                TextColumn::make('meter.number')
                    ->label('Счётчик')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('meters.number', 'like', '%'.$search.'%')),
                TextColumn::make('consumption_period_for_report')
                    ->label('Период')
                    ->state(fn (): string => $billingPeriod?->label ?? '-'),
                TextColumn::make('previous_reading')
                    ->label('Предыдущее')
                    ->numeric(0)
                    ->sortable(),
                TextColumn::make('current_reading')
                    ->label('Текущее')
                    ->numeric(0)
                    ->sortable(),
                TextColumn::make('consumption')
                    ->label('Потребление')
                    ->numeric(0)
                    ->sortable(),
                TextColumn::make('read_at')
                    ->label('Дата снятия')
                    ->date('d.m.Y')
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters($this->filters($organization))
            ->recordUrl(null)
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading($billingPeriod instanceof BillingPeriod ? 'Потреблений нет' : 'Расчётный месяц не открыт')
            ->emptyStateDescription($billingPeriod instanceof BillingPeriod
                ? 'За текущий расчётный месяц показания счётчиков не найдены.'
                : 'Откройте расчётный месяц, чтобы увидеть потребления.')
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

        return $this->downloadXlsx(
            $this->excelFileName($organization, $billingPeriod),
            $this->excelOptions(),
            $this->excelHeadings(),
            fn (): iterable => $query->lazy(500),
            fn (object $record): array => $this->excelCells($record, $billingPeriod),
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
            DateRangeFilter::make('read_at', 'Дата снятия', 'meter_readings.read_at'),
        ];
    }

    /**
     * @return Builder<MeterReading>
     */
    private function query(Organization $organization, User $user, ?BillingPeriod $billingPeriod): Builder
    {
        $query = MeterReading::query()
            ->select('meter_readings.*')
            ->join('clients', 'clients.id', '=', 'meter_readings.client_id')
            ->join('meters', 'meters.id', '=', 'meter_readings.meter_id')
            ->with([
                'billingPeriod',
                'client.region',
                'client.street',
                'meter',
            ])
            ->visibleToOrganizationMember($user, $organization)
            ->orderBy('clients.account_number')
            ->orderBy('meters.number')
            ->orderBy('meter_readings.id');

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->where('meter_readings.id', 0);
        }

        // A visit that took no reading is not a consumption row: it has no
        // current value and its zero would dilute the report.
        return $query->whereBelongsTo($billingPeriod)->taken();
    }

    private function excelFileName(Organization $organization, ?BillingPeriod $billingPeriod): string
    {
        return sprintf(
            'consumption-%d-%s-%s.xlsx',
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
        $options->setColumnWidth(14, 5);
        $options->setColumnWidth(16, 6);
        $options->setColumnWidth(16, 7);
        $options->setColumnWidth(16, 8);
        $options->setColumnWidth(16, 9);

        return $options;
    }

    /**
     * @return list<string>
     */
    private function excelHeadings(): array
    {
        return [
            'Лицевой счёт',
            'Абонент',
            'Адрес',
            'Счётчик',
            'Период',
            'Предыдущее',
            'Текущее',
            'Потребление',
            'Дата снятия',
        ];
    }

    /**
     * @return list<Cell>
     */
    private function excelCells(object $record, ?BillingPeriod $billingPeriod): array
    {
        /** @var MeterReading $meterReading */
        $meterReading = $record;

        return [
            new StringCell((string) ($meterReading->client?->account_number ?? ''), null),
            new StringCell((string) ($meterReading->client?->name ?? ''), null),
            new StringCell($this->formatClientAddress($meterReading->client), (new Style)->setShouldWrapText()),
            new StringCell((string) ($meterReading->meter?->number ?? ''), null),
            new StringCell($billingPeriod?->label ?? '', null),
            new NumericCell((int) $meterReading->previous_reading, (new Style)->setFormat('0')),
            new NumericCell((int) $meterReading->current_reading, (new Style)->setFormat('0')),
            new NumericCell((int) $meterReading->consumption, (new Style)->setFormat('0')),
            new StringCell($meterReading->read_at?->format('d.m.Y') ?? '', null),
        ];
    }
}
