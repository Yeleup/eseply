<?php

namespace App\Filament\Resources\Tariffs\Tables;

use App\ClientType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
                'utilityService',
            ]))
            ->columns([
                TextColumn::make('utilityService.name')
                    ->label('Услуга')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client_type')
                    ->label('Тип клиента')
                    ->badge()
                    ->formatStateUsing(fn (ClientType|string $state): string => ClientType::labelFor($state) ?? (string) $state)
                    ->sortable(),
                TextColumn::make('unit_price')
                    ->label('Цена за единицу')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('per_person_price')
                    ->label('Цена на человека')
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
                SelectFilter::make('client_type')
                    ->label('Тип клиента')
                    ->options(ClientType::class),
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
