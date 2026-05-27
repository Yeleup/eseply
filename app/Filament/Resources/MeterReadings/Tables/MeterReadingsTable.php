<?php

namespace App\Filament\Resources\MeterReadings\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MeterReadingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'client',
                    'meter',
                    'utilityService',
                ])
                ->orderByDesc('period'))
            ->columns([
                TextColumn::make('period')
                    ->label('Период')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('meter.number')
                    ->label('Счётчик')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.account_number')
                    ->label('Лицевой счёт')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('Абонент')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('utilityService.name')
                    ->label('Услуга')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Не указана'),
                TextColumn::make('previous_reading')
                    ->label('Предыдущее')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('current_reading')
                    ->label('Текущее')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('consumption')
                    ->label('Расход')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('read_at')
                    ->label('Дата ввода')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('period')
                    ->label('Период')
                    ->options(fn (): array => Filament::getTenant()
                        ?->meterReadings()
                        ->orderByDesc('period')
                        ->pluck('period', 'period')
                        ->all() ?? []),
                SelectFilter::make('meter_id')
                    ->label('Счётчик')
                    ->options(fn (): array => Filament::getTenant()
                        ?->meters()
                        ->orderBy('number')
                        ->pluck('number', 'id')
                        ->all() ?? []),
                SelectFilter::make('utility_service_id')
                    ->label('Услуга')
                    ->options(fn (): array => Filament::getTenant()
                        ?->utilityServices()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all() ?? []),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
