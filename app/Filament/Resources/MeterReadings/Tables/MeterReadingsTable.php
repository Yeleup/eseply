<?php

namespace App\Filament\Resources\MeterReadings\Tables;

use App\Filament\Resources\MeterReadings\MeterReadingResource;
use App\Filament\Support\BillingPeriodOptions;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MeterReadingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $query
                    ->with([
                        'billingPeriod',
                        'client',
                        'meter',
                        'utilityService',
                    ])
                    ->orderByBillingPeriodDesc();

                $tenant = Filament::getTenant();
                $user = auth()->user();

                if ($tenant instanceof Organization && $user instanceof User) {
                    return $query->visibleToOrganizationMember($user, $tenant);
                }

                return $query->whereRaw('1 = 0');
            })
            ->columns([
                TextColumn::make('period')
                    ->label('Период')
                    ->placeholder('-'),
                TextColumn::make('meter.number')
                    ->label('Счётчик')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.account_number')
                    ->label('Лицевой счёт')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('Абонент')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('utilityService.name')
                    ->label('Услуга')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Не указана'),
                TextColumn::make('previous_reading')
                    ->label('Предыдущее')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('current_reading')
                    ->label('Текущее')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('consumption')
                    ->label('Расход')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('read_at')
                    ->label('Дата ввода')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('billing_period_id')
                    ->label('Период')
                    ->options(function (): array {
                        $tenant = Filament::getTenant();

                        if (! $tenant instanceof Organization) {
                            return [];
                        }

                        return BillingPeriodOptions::all($tenant);
                    }),
                SelectFilter::make('meter_id')
                    ->label('Счётчик')
                    ->options(function (): array {
                        $tenant = Filament::getTenant();
                        $user = auth()->user();

                        if (! $tenant instanceof Organization || ! $user instanceof User) {
                            return [];
                        }

                        return Meter::query()
                            ->visibleToOrganizationMember($user, $tenant)
                            ->orderBy('number')
                            ->pluck('number', 'id')
                            ->all();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (MeterReading $record): bool => MeterReadingResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => MeterReadingResource::canDeleteAny()),
                ]),
            ]);
    }
}
