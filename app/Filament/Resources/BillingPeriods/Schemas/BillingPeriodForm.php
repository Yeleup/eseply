<?php

namespace App\Filament\Resources\BillingPeriods\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BillingPeriodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Расчётный месяц')
                    ->columns(2)
                    ->schema([
                        TextInput::make('period')
                            ->label('Месяц')
                            ->placeholder('202605')
                            ->helperText('Формат: ГГГГММ. Новый месяц можно открыть только после закрытия предыдущего.')
                            ->default(now()->format('Ym'))
                            ->required()
                            ->length(6)
                            ->regex('/^\d{6}$/')
                            ->rules(['date_format:Ym']),
                    ]),
            ]);
    }
}
