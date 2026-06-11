<?php

namespace App\Filament\Resources\BalanceAdjustments\Tables;

use App\BalanceAdjustmentType;
use App\Filament\Support\BillingPeriodOptions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BalanceAdjustmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'billingPeriod',
                    'client',
                ])
                ->orderByDesc('adjusted_at')
                ->orderByDesc('id'))
            ->columns([
                TextColumn::make('period')
                    ->label('Период')
                    ->placeholder('-'),
                TextColumn::make('client.account_number')
                    ->label('Лицевой счёт')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('Абонент')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (BalanceAdjustmentType|string $state): string => BalanceAdjustmentType::labelFor($state) ?? (string) $state),
                TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('adjusted_at')
                    ->label('Дата корректировки')
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('billing_period_id')
                    ->label('Период')
                    ->options(fn (): array => BillingPeriodOptions::all()),
                SelectFilter::make('client_id')
                    ->label('Абонент')
                    ->options(fn (): array => Filament::getTenant()
                        ?->clients()
                        ->orderBy('account_number')
                        ->pluck('account_number', 'id')
                        ->all() ?? []),
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options(BalanceAdjustmentType::class),
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
