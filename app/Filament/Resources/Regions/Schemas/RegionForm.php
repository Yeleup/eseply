<?php

namespace App\Filament\Resources\Regions\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class RegionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: 'regions',
                        column: 'name',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule
                            ->where('organization_id', Filament::getTenant()?->getKey()),
                    ),
            ]);
    }
}
