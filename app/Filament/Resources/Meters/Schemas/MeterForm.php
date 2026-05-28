<?php

namespace App\Filament\Resources\Meters\Schemas;

use App\Models\Client;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MeterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Счётчик')
                    ->columns(2)
                    ->schema([
                        Select::make('client_id')
                            ->label('Абонент')
                            ->options(fn (): array => Filament::getTenant()
                                ?->clients()
                                ->orderBy('account_number')
                                ->get()
                                ->mapWithKeys(fn (Client $client): array => [
                                    $client->id => "{$client->account_number} - {$client->name}",
                                ])
                                ->all() ?? [])
                            ->searchable()
                            ->preload()
                            ->required()
                            ->scopedExists(Client::class, 'id')
                            ->native(false),
                        TextInput::make('number')
                            ->label('Номер счётчика')
                            ->required()
                            ->maxLength(255)
                            ->scopedUnique(),
                        TextInput::make('initial_reading')
                            ->label('Начальное показание')
                            ->numeric()
                            ->step('0.0001')
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        DatePicker::make('installed_on')
                            ->label('Дата установки')
                            ->native(false),
                        DatePicker::make('removed_on')
                            ->label('Дата снятия')
                            ->native(false),
                        Select::make('status')
                            ->label('Статус')
                            ->options([
                                'active' => 'Активный',
                                'removed' => 'Снят',
                            ])
                            ->default('active')
                            ->required()
                            ->native(false),
                        Textarea::make('note')
                            ->label('Примечание')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
