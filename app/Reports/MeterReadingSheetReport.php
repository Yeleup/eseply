<?php

namespace App\Reports;

use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Reports\Contracts\OrganizationReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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

    public function table(Table $table, Organization $organization): Table
    {
        return $table
            ->query($this->query($organization))
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

    private function query(Organization $organization): Builder
    {
        return Meter::query()
            ->select('meters.*')
            ->addSelect([
                'previous_reading_for_report' => MeterReading::query()
                    ->select('current_reading')
                    ->whereColumn('meter_readings.meter_id', 'meters.id')
                    ->orderByDesc('period')
                    ->orderByDesc('id')
                    ->limit(1),
            ])
            ->join('clients', 'clients.id', '=', 'meters.client_id')
            ->with([
                'client.region',
                'client.street',
            ])
            ->whereBelongsTo($organization)
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
}
