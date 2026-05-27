<?php

namespace App\Filament\Resources\MeterReadings\Schemas;

use App\Models\Meter;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MeterReadingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Показание')
                    ->columns(2)
                    ->schema([
                        Select::make('meter_id')
                            ->label('Счётчик')
                            ->options(fn (): array => Filament::getTenant()
                                ?->meters()
                                ->with('client')
                                ->orderBy('number')
                                ->get()
                                ->mapWithKeys(fn (Meter $meter): array => [
                                    $meter->id => "{$meter->number} - {$meter->client?->account_number}",
                                ])
                                ->all() ?? [])
                            ->searchable()
                            ->preload()
                            ->required()
                            ->scopedExists(Meter::class, 'id')
                            ->native(false),
                        TextInput::make('period')
                            ->label('Период')
                            ->placeholder('202605')
                            ->helperText('Формат: ГГГГММ')
                            ->required()
                            ->length(6)
                            ->regex('/^\d{6}$/')
                            ->rules(['date_format:Ym']),
                        TextInput::make('previous_reading')
                            ->label('Предыдущее показание')
                            ->numeric()
                            ->step('0.0001')
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        TextInput::make('current_reading')
                            ->label('Текущее показание')
                            ->numeric()
                            ->step('0.0001')
                            ->minValue(0)
                            ->required(),
                        DatePicker::make('read_at')
                            ->label('Дата ввода')
                            ->native(false),
                        Textarea::make('note')
                            ->label('Примечание')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
