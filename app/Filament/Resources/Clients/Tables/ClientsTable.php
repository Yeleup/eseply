<?php

namespace App\Filament\Resources\Clients\Tables;

use App\ClientType;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Action;
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
            ->modifyQueryUsing(function (Builder $query): Builder {
                $query->with([
                    'region',
                    'street',
                    'utilityService',
                ]);

                $tenant = Filament::getTenant();
                $user = auth()->user();

                if ($tenant instanceof Organization && $user instanceof User) {
                    return $query->visibleToOrganizationMember($user, $tenant);
                }

                return $query->whereRaw('1 = 0');
            })
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
                    ->formatStateUsing(fn (ClientType|string $state): string => ClientType::labelFor($state) ?? (string) $state),
                TextColumn::make('utilityService.name')
                    ->label('Услуга')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Не выбрана')
                    ->toggleable(),
                TextColumn::make('billing_type')
                    ->label('Тип начисления')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'meter' => 'По счётчику',
                        'per_person' => 'На одного человека',
                        'fixed' => 'Фиксированная сумма',
                        default => $state,
                    }),
                TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('region.name')
                    ->label('Регион')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('street.name')
                    ->label('Улица')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('house')
                    ->label('Дом')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('apartment')
                    ->label('Квартира')
                    ->searchable()
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
                SelectFilter::make('billing_type')
                    ->label('Тип начисления')
                    ->options([
                        'meter' => 'По счётчику',
                        'per_person' => 'На одного человека',
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
                Action::make('open')
                    ->label('Открыть')
                    ->url(fn (Client $record): string => ClientResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (Client $record): bool => ClientResource::canView($record) && ! ClientResource::canEdit($record)),
                EditAction::make()
                    ->visible(fn (Client $record): bool => ClientResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => ClientResource::canDeleteAny()),
                ]),
            ]);
    }
}
