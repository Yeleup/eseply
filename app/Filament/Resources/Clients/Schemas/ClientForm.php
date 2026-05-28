<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\ClientType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Данные абонента')
                    ->columns(2)
                    ->schema([
                        TextInput::make('account_number')
                            ->label('Лицевой счёт')
                            ->required()
                            ->maxLength(255)
                            ->scopedUnique(),
                        TextInput::make('name')
                            ->label('ФИО / Наименование')
                            ->required()
                            ->maxLength(255),
                        Select::make('client_type')
                            ->label('Тип клиента')
                            ->options(ClientType::class)
                            ->default(ClientType::Individual->value)
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
                        TextInput::make('phone')
                            ->label('Телефон')
                            ->tel()
                            ->maxLength(255),
                        TextInput::make('address')
                            ->label('Адрес')
                            ->maxLength(255),
                        TextInput::make('starting_balance')
                            ->label('Стартовое сальдо')
                            ->numeric()
                            ->step('0.01')
                            ->default(0)
                            ->required(),
                        Textarea::make('note')
                            ->label('Примечание')
                            ->columnSpanFull(),
                    ]),
                Section::make('Настройки начисления')
                    ->columns(2)
                    ->schema([
                        Select::make('billing_type')
                            ->label('Тип начисления')
                            ->options([
                                'meter' => 'По счётчику',
                                'per_person' => 'На одного человека',
                                'fixed' => 'Фиксированная сумма',
                            ])
                            ->default('per_person')
                            ->required()
                            ->native(false),
                        TextInput::make('residents_count')
                            ->label('Количество проживающих')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        TextInput::make('area')
                            ->label('Площадь')
                            ->numeric()
                            ->step('0.01')
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        TextInput::make('fixed_amount')
                            ->label('Фиксированная сумма')
                            ->numeric()
                            ->step('0.01')
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                    ]),
            ]);
    }
}
