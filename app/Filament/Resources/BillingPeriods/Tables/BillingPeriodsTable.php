<?php

namespace App\Filament\Resources\BillingPeriods\Tables;

use App\BillingPeriodStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BillingPeriodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->orderByDesc('starts_on'))
            ->columns([
                TextColumn::make('label')
                    ->label('Месяц')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('starts_on', $direction)),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (BillingPeriodStatus $state): string => $state->color()),
                TextColumn::make('active_clients_count')
                    ->label('Активных')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_accruals_count')
                    ->label('Создано')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('skipped_accruals_count')
                    ->label('Пропущено')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('failed_clients_count')
                    ->label('Ошибок')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('closed_at')
                    ->label('Закрыт')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(BillingPeriodStatus::class),
            ]);
    }
}
