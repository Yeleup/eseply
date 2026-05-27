<?php

namespace App\Filament\Resources\UtilityServices\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UtilityServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Услуга')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('unit_of_measurement')
                            ->label('Единица измерения')
                            ->required()
                            ->maxLength(255),
                        Select::make('status')
                            ->label('Статус')
                            ->options([
                                'active' => 'Активная',
                                'inactive' => 'Неактивная',
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
