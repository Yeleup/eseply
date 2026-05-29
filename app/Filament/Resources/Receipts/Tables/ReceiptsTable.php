<?php

namespace App\Filament\Resources\Receipts\Tables;

use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReceiptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'accrual',
                    'client',
                ])
                ->latest('issued_at'))
            ->columns([
                TextColumn::make('receipt_number')
                    ->label('Номер')
                    ->searchable()
                    ->sortable(),
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
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->label('Начислено')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Оплачено')
                    ->money('KZT')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('adjustment_amount')
                    ->label('Корректировка')
                    ->money('KZT')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('closing_balance')
                    ->label('Конечное сальдо')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('issued_at')
                    ->label('Сформирована')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('period')
                    ->label('Период')
                    ->options(fn (): array => Filament::getTenant()
                        ?->receipts()
                        ->orderByDesc('period')
                        ->pluck('period', 'period')
                        ->all() ?? []),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
