<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\ClientType;
use App\Models\Region;
use App\Models\Street;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

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
                        Select::make('region_id')
                            ->label('Регион')
                            ->options(fn (): array => Filament::getTenant()
                                ?->regions()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all() ?? [])
                            ->searchable()
                            ->preload()
                            ->required()
                            ->scopedExists(Region::class, 'id')
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('street_id', null))
                            ->native(false),
                        Select::make('street_id')
                            ->label('Улица')
                            ->options(fn (Get $get): array => Street::query()
                                ->where('organization_id', Filament::getTenant()?->getKey())
                                ->where('region_id', $get('region_id'))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabled(fn (Get $get): bool => blank($get('region_id')))
                            ->rules(fn (Get $get): array => [
                                Rule::exists('streets', 'id')
                                    ->where('organization_id', Filament::getTenant()?->getKey())
                                    ->where('region_id', $get('region_id')),
                            ])
                            ->native(false),
                        TextInput::make('house')
                            ->label('Дом')
                            ->maxLength(255),
                        TextInput::make('apartment')
                            ->label('Квартира / помещение')
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
                            ->live()
                            ->native(false),
                        TextInput::make('residents_count')
                            ->label('Количество проживающих')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(fn (Get $get): bool => $get('billing_type') === 'per_person')
                            ->visible(fn (Get $get): bool => $get('billing_type') === 'per_person'),
                        TextInput::make('fixed_amount')
                            ->label('Фиксированная сумма')
                            ->numeric()
                            ->step('0.01')
                            ->minValue(0)
                            ->default(0)
                            ->required(fn (Get $get): bool => $get('billing_type') === 'fixed')
                            ->visible(fn (Get $get): bool => $get('billing_type') === 'fixed'),
                    ]),
            ]);
    }
}
