<?php

namespace App\Filament\Resources\Regions\Schemas;

use App\Models\City;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class RegionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                    ->native(false),
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: 'regions',
                        column: 'name',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                            ->where('city_id', $get('city_id')),
                    ),
            ]);
    }
}
