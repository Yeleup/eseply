<?php

namespace App\Filament\Resources\Meters\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MetersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'client',
                'utilityService',
            ]))
            ->columns([
                TextColumn::make('number')
                    ->label('Номер')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.account_number')
                    ->label('Лицевой счёт')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('Абонент')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('utilityService.name')
                    ->label('Услуга')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Не указана'),
                TextColumn::make('initial_reading')
                    ->label('Начальное показание')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'removed' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Активный',
                        'removed' => 'Снят',
                        default => $state,
                    })
                    ->sortable(),
                TextColumn::make('installed_on')
                    ->label('Установлен')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('removed_on')
                    ->label('Снят')
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('client_id')
                    ->label('Абонент')
                    ->options(fn (): array => Filament::getTenant()
                        ?->clients()
                        ->orderBy('account_number')
                        ->pluck('account_number', 'id')
                        ->all() ?? []),
                SelectFilter::make('utility_service_id')
                    ->label('Услуга')
                    ->options(fn (): array => Filament::getTenant()
                        ?->utilityServices()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all() ?? []),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'active' => 'Активный',
                        'removed' => 'Снят',
                    ]),
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
