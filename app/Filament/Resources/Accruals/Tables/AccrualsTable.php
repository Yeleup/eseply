<?php

namespace App\Filament\Resources\Accruals\Tables;

use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccrualsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'client',
                    'utilityService',
                ])
                ->latest('closed_at'))
            ->columns([
                TextColumn::make('period')
                    ->label('Период')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('account_number')
                    ->label('Лицевой счёт')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client_name')
                    ->label('Абонент')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('utility_service_name')
                    ->label('Услуга')
                    ->searchable()
                    ->placeholder('Не указана'),
                TextColumn::make('billing_type')
                    ->label('Тип начисления')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'fixed' => 'Фиксированная сумма',
                        'meter' => 'По счётчику',
                        'normative' => 'По нормативу',
                        default => $state,
                    }),
                TextColumn::make('volume')
                    ->label('Объём')
                    ->numeric(4)
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('tariff_price')
                    ->label('Тариф')
                    ->money('KZT')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label('Начислено')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Оплачено')
                    ->money('KZT')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('opening_balance')
                    ->label('Начальное сальдо')
                    ->money('KZT')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('closing_balance')
                    ->label('Конечное сальдо')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('closed_at')
                    ->label('Закрыт')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('period')
                    ->label('Период')
                    ->options(fn (): array => Filament::getTenant()
                        ?->accruals()
                        ->orderByDesc('period')
                        ->pluck('period', 'period')
                        ->all() ?? []),
                SelectFilter::make('billing_type')
                    ->label('Тип начисления')
                    ->options([
                        'fixed' => 'Фиксированная сумма',
                        'normative' => 'По нормативу',
                    ]),
            ]);
    }
}
