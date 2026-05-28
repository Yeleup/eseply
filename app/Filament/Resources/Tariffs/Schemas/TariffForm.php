<?php

namespace App\Filament\Resources\Tariffs\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TariffForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Тариф')
                    ->columns(2)
                    ->schema([
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
                        TextInput::make('price')
                            ->label('Цена')
                            ->numeric()
                            ->step('0.01')
                            ->minValue(0)
                            ->required(),
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
