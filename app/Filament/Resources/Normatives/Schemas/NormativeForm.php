<?php

namespace App\Filament\Resources\Normatives\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class NormativeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Норматив')
                    ->columns(2)
                    ->schema([
                        Select::make('utility_service_id')
                            ->label('Услуга')
                            ->options(fn (): array => Filament::getTenant()
                                ?->utilityServices()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all() ?? [])
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false),
                        Select::make('tariff_category_id')
                            ->label('Категория тарифа')
                            ->options(fn (): array => Filament::getTenant()
                                ?->tariffCategories()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all() ?? [])
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false),
                        TextInput::make('value')
                            ->label('Норма')
                            ->numeric()
                            ->step('0.0001')
                            ->minValue(0)
                            ->required(),
                        Select::make('calculation_type')
                            ->label('Тип расчёта')
                            ->options([
                                'per_person' => 'На человека',
                                'per_object' => 'На объект',
                                'per_area' => 'По площади',
                            ])
                            ->required()
                            ->native(false),
                        DatePicker::make('starts_on')
                            ->label('Дата начала')
                            ->required()
                            ->native(false),
                        Select::make('status')
                            ->label('Статус')
                            ->options([
                                'active' => 'Активный',
                                'inactive' => 'Неактивный',
                            ])
                            ->default('active')
                            ->required()
                            ->native(false),
                    ]),
            ]);
    }
}
