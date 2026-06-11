<?php

namespace App\Reports;

use App\Models\BillingPeriod;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use App\Reports\Contracts\OrganizationReport;
use Filament\Tables\Columns\TextColumn;
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

class MeterReadingSheetReport implements OrganizationReport
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
                    ->state(fn (Meter $record): mixed => $record->getAttribute('previous_reading_for_report') ?? $record->initial_reading)
                    ->numeric(4),
                TextColumn::make('reading_entry')
                    ->label('Показание')
                    ->state(fn (): string => '')
                    ->placeholder(''),
            ])
            ->recordUrl(null)
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Нет активных счётчиков')
            ->striped();
    }

    public function downloadExcel(Organization $organization, User $user): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($organization, $user): void {
                $writer = new Writer($this->excelOptions());
                $writer->openToFile('php://output');

                $writer->addRow(new Row($this->excelHeadingCells()));

                foreach ($this->query($organization, $user)->lazy(500) as $meter) {
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

    private function query(Organization $organization, User $user): Builder
    {
        return Meter::query()
            ->select('meters.*')
            ->addSelect([
                'previous_reading_for_report' => MeterReading::query()
                    ->select('current_reading')
                    ->whereColumn('meter_readings.meter_id', 'meters.id')
                    ->orderByDesc(
                        BillingPeriod::query()
                            ->select('starts_on')
                            ->whereColumn('billing_periods.id', 'meter_readings.billing_period_id'),
                    )
                    ->orderByDesc('id')
                    ->limit(1),
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
        $options->setColumnWidth(18, 8);

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
                'Показание',
            ],
        );
    }

    /**
     * @return list<Cell>
     */
    private function excelCells(Meter $meter): array
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
            new NumericCell($this->previousReading($meter), (new Style)->setFormat('0.0000')),
            new StringCell('', null),
        ];
    }

    private function previousReading(Meter $meter): float
    {
        return (float) ($meter->getAttribute('previous_reading_for_report') ?? $meter->initial_reading);
    }
}
