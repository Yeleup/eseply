<?php

namespace App\Filament\Resources\Clients\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'tariffCategory',
                'utilityService',
            ]))
            ->columns([
                TextColumn::make('account_number')
                    ->label('Лицевой счёт')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('ФИО / Наименование')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client_type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'individual' => 'Физическое лицо',
                        'legal' => 'Юридическое лицо',
                        default => $state,
                    }),
                TextColumn::make('utilityService.name')
                    ->label('Услуга')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Не выбрана')
                    ->toggleable(),
                TextColumn::make('tariffCategory.name')
                    ->label('Категория тарифа')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Не выбрана')
                    ->toggleable(),
                TextColumn::make('billing_type')
                    ->label('Тип начисления')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'meter' => 'По счётчику',
                        'normative' => 'По нормативу',
                        'fixed' => 'Фиксированная сумма',
                        default => $state,
                    }),
                TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('address')
                    ->label('Адрес')
                    ->searchable()
                    ->limit(40)
                    ->toggleable(),
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
                TextColumn::make('starting_balance')
                    ->label('Стартовое сальдо')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('client_type')
                    ->label('Тип клиента')
                    ->options([
                        'individual' => 'Физическое лицо',
                        'legal' => 'Юридическое лицо',
                    ]),
                SelectFilter::make('tariff_category_id')
                    ->label('Категория тарифа')
                    ->options(fn (): array => Filament::getTenant()
                        ?->tariffCategories()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all() ?? []),
                SelectFilter::make('billing_type')
                    ->label('Тип начисления')
                    ->options([
                        'meter' => 'По счётчику',
                        'normative' => 'По нормативу',
                        'fixed' => 'Фиксированная сумма',
                    ]),
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
