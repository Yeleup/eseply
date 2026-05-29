<?php

namespace App\Filament\Resources\Meters\Tables;

use App\Models\Meter;
use Filament\Actions\Action;
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
                        'removed' => 'В архиве',
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
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'active' => 'Активный',
                        'removed' => 'В архиве',
                    ]),
            ])
            ->recordActions([
                Action::make('archive')
                    ->label('Отправить в архив')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Отправить счётчик в архив?')
                    ->modalDescription('Дата снятия будет проставлена сегодняшней датой.')
                    ->modalSubmitActionLabel('Отправить в архив')
                    ->visible(fn (Meter $record): bool => ! $record->isArchived())
                    ->action(function (Meter $record): void {
                        $record->archive();
                    }),
                Action::make('restoreFromArchive')
                    ->label('Вывести из архива')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Вывести счётчик из архива?')
                    ->modalDescription('Дата снятия будет очищена, счётчик снова станет активным.')
                    ->modalSubmitActionLabel('Вывести из архива')
                    ->visible(fn (Meter $record): bool => $record->isArchived())
                    ->action(function (Meter $record): void {
                        $record->restoreFromArchive();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
