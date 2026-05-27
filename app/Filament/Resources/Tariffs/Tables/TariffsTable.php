<?php

namespace App\Filament\Resources\Tariffs\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TariffsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'tariffCategory',
                'utilityService',
            ]))
            ->columns([
                TextColumn::make('utilityService.name')
                    ->label('Услуга')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tariffCategory.name')
                    ->label('Категория')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('price')
                    ->label('Цена')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('starts_on')
                    ->label('Дата начала')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Активный',
                        'inactive' => 'Неактивный',
                        default => $state,
                    })
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('utility_service_id')
                    ->label('Услуга')
                    ->options(fn (): array => Filament::getTenant()
                        ?->utilityServices()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all() ?? []),
                SelectFilter::make('tariff_category_id')
                    ->label('Категория тарифа')
                    ->options(fn (): array => Filament::getTenant()
                        ?->tariffCategories()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all() ?? []),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'active' => 'Активный',
                        'inactive' => 'Неактивный',
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
