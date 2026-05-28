<?php

namespace App\Filament\Resources\Tariffs\Schemas;

use App\ClientType;
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
                        Select::make('client_type')
                            ->label('Тип клиента')
                            ->options(ClientType::class)
                            ->default(ClientType::Individual->value)
                            ->required()
                            ->native(false),
                        TextInput::make('unit_price')
                            ->label('Цена за единицу')
                            ->numeric()
                            ->step('0.01')
                            ->minValue(0),
                        TextInput::make('per_person_price')
                            ->label('Цена на одного человека')
                            ->numeric()
                            ->step('0.01')
                            ->minValue(0),
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
