<?php

namespace App\Filament\Resources\MeterReadings\Schemas;

use App\Models\Meter;
use App\Models\MeterReading;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                $set('previous_reading', MeterReading::previousReadingFor($state, $get('period')) ?? 0);
                            })
                            ->native(false),
                        TextInput::make('period')
                            ->label('Период')
                            ->placeholder('202605')
                            ->helperText('Формат: ГГГГММ')
                            ->required()
                            ->length(6)
                            ->regex('/^\d{6}$/')
                            ->rules(['date_format:Ym'])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                $set('previous_reading', MeterReading::previousReadingFor($get('meter_id'), $state) ?? 0);
                            }),
                        TextInput::make('previous_reading')
                            ->label('Предыдущее показание')
                            ->numeric()
                            ->step('0.0001')
                            ->minValue(0)
                            ->default(fn (Get $get): float => MeterReading::previousReadingFor($get('meter_id'), $get('period')) ?? 0)
                            ->readOnly()
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
