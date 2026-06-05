<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Support\OrganizationMemberAccess;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AccrualsRelationManager extends RelationManager
{
    protected static string $relationship = 'accruals';

    protected static ?string $title = 'Начисления';

    protected static ?string $modelLabel = 'начисление';

    protected static ?string $pluralModelLabel = 'начисления';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return OrganizationMemberAccess::canManageTenant()
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->ownerRecord, $this->pageClass ?? static::class), 403);

        parent::mount();
    }

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('period')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->latest('closed_at'))
            ->columns([
                TextColumn::make('period')
                    ->label('Период')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('utility_service_name')
                    ->label('Услуга')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('billing_type')
                    ->label('Тип начисления')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'fixed' => 'Фиксированная сумма',
                        'meter' => 'По счётчику',
                        'per_person' => 'На одного человека',
                        default => $state,
                    }),
                TextColumn::make('volume')
                    ->label('Объём')
                    ->numeric(4)
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('tariff_price')
                    ->label('Тариф')
                    ->money('KZT')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label('Начислено')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Оплачено')
                    ->money('KZT')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('adjustment_amount')
                    ->label('Корректировка')
                    ->money('KZT')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('opening_balance')
                    ->label('Начальное сальдо')
                    ->money('KZT')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('closing_balance')
                    ->label('Конечное сальдо')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('closed_at')
                    ->label('Закрыт')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('period')
                    ->label('Период')
                    ->options(fn (): array => $this->ownerRecord
                        ->accruals()
                        ->orderByDesc('period')
                        ->pluck('period', 'period')
                        ->all()),
            ]);
    }
}
