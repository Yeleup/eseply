<?php

namespace App\Filament\Resources\Meters\Tables;

use App\Filament\Resources\Meters\MeterResource;
use App\Models\Client;
use App\Models\Meter;
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

class MetersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $query->with([
                    'client',
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
                    ->options(function (): array {
                        $tenant = Filament::getTenant();
                        $user = auth()->user();

                        if (! $tenant instanceof Organization || ! $user instanceof User) {
                            return [];
                        }

                        return Client::query()
                            ->visibleToOrganizationMember($user, $tenant)
                            ->orderBy('account_number')
                            ->pluck('account_number', 'id')
                            ->all();
                    }),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'active' => 'Активный',
                        'removed' => 'В архиве',
                    ]),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Открыть')
                    ->url(fn (Meter $record): string => MeterResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (Meter $record): bool => MeterResource::canView($record) && ! MeterResource::canEdit($record)),
                Action::make('archive')
                    ->label('Отправить в архив')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Отправить счётчик в архив?')
                    ->modalDescription('Дата снятия будет проставлена сегодняшней датой.')
                    ->modalSubmitActionLabel('Отправить в архив')
                    ->visible(fn (Meter $record): bool => ! $record->isArchived() && MeterResource::canEdit($record))
                    ->action(function (Meter $record): void {
                        abort_unless(MeterResource::canEdit($record), 403);

                        $record->archive();
                    }),
                Action::make('restoreFromArchive')
                    ->label('Вывести из архива')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Вывести счётчик из архива?')
                    ->modalDescription('Дата снятия будет очищена, счётчик снова станет активным.')
                    ->modalSubmitActionLabel('Вывести из архива')
                    ->visible(fn (Meter $record): bool => $record->isArchived() && MeterResource::canEdit($record))
                    ->action(function (Meter $record): void {
                        abort_unless(MeterResource::canEdit($record), 403);

                        $record->restoreFromArchive();
                    }),
                EditAction::make()
                    ->visible(fn (Meter $record): bool => MeterResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => MeterResource::canDeleteAny()),
                ]),
            ]);
    }
}
