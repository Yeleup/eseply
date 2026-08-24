<?php

namespace App\Filament\Resources\BalanceAdjustments\Schemas;

use App\BalanceAdjustmentType;
use App\Filament\Support\ClientSelect;
use App\Models\BalanceAdjustment;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class BalanceAdjustmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Корректировка сальдо')
                    ->columns(2)
                    ->schema([
                        ClientSelect::make(),
                        Select::make('type')
                            ->label('Тип')
                            ->options(BalanceAdjustmentType::class)
                            ->default(BalanceAdjustmentType::ManualAdjustment->value)
                            ->required()
                            ->rules([
                                fn (Get $get, ?BalanceAdjustment $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                    if ($value !== BalanceAdjustmentType::OpeningBalance->value) {
                                        return;
                                    }

                                    $clientId = $get('client_id');

                                    if ($record?->type === BalanceAdjustmentType::OpeningBalance
                                        && (int) $clientId === (int) $record->client_id) {
                                        return;
                                    }

                                    if (BalanceAdjustment::openingBalanceLockedFor($clientId)) {
                                        $fail(BalanceAdjustment::OPENING_BALANCE_LOCKED_MESSAGE);
                                    }
                                },
                            ])
                            ->native(false),
                        TextInput::make('amount')
                            ->label('Сумма')
                            ->numeric()
                            ->step('0.01')
                            ->default(0)
                            ->required(),
                        DatePicker::make('adjusted_at')
                            ->label('Дата корректировки')
                            ->native(false),
                        Textarea::make('note')
                            ->label('Причина / примечание')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
