<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\ClientType;
use App\Models\City;
use App\Models\Client;
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
use Illuminate\Validation\Rules\Unique;

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
                            ->helperText('Присваивается автоматически при создании и не изменяется.')
                            ->maxLength(255)
                            ->readOnly()
                            ->saved(false)
                            ->validatedWhenNotDehydrated(false),
                        TextInput::make('name')
                            ->label('ФИО / Наименование')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('iin')
                            ->label('ИИН')
                            ->required()
                            ->maxLength(12)
                            ->unique(
                                table: 'clients',
                                column: 'iin',
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule
                                    ->where('organization_id', Filament::getTenant()?->getKey()),
                            ),
                        Select::make('client_type')
                            ->label('Тип клиента')
                            ->options(ClientType::class)
                            ->default(ClientType::Individual->value)
                            ->required()
                            ->native(false),
                        TextInput::make('residents_count')
                            ->label('Количество проживающих')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
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
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                table: 'clients',
                                column: 'phone',
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule
                                    ->where('organization_id', Filament::getTenant()?->getKey()),
                            ),
                        TextInput::make('contract')
                            ->label('Договор')
                            ->required(),
                        TextInput::make('technical_conditions')
                            ->label('Тех. условия'),
                        Select::make('city_id')
                            ->label('Город')
                            ->options(fn (): array => Filament::getTenant()
                                ?->cities()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all() ?? [])
                            ->searchable()
                            ->preload()
                            ->required()
                            ->scopedExists(City::class, 'id')
                            ->live()
                            ->saved(false)
                            ->afterStateHydrated(function (Set $set, ?Client $record): void {
                                if ($record?->region?->city_id !== null) {
                                    $set('city_id', $record->region->city_id);
                                }
                            })
                            ->afterStateUpdated(function (Set $set): void {
                                $set('region_id', null);
                                $set('street_id', null);
                            })
                            ->native(false),
                        Select::make('region_id')
                            ->label('Регион')
                            ->options(fn (Get $get): array => Filament::getTenant()
                                ?->regions()
                                ->where('city_id', $get('city_id'))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all() ?? [])
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabled(fn (Get $get): bool => blank($get('city_id')))
                            ->rules(fn (Get $get): array => [
                                Rule::exists('regions', 'id')
                                    ->where('organization_id', Filament::getTenant()?->getKey())
                                    ->where('city_id', $get('city_id')),
                            ])
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
