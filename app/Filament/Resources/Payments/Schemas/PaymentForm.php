<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Filament\Support\BillingPeriodOptions;
use App\Models\BillingPeriod;
use App\Models\Client;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Оплата')
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
                        Select::make('billing_period_id')
                            ->label('Расчётный месяц')
                            ->options(fn (): array => BillingPeriodOptions::editable())
                            ->helperText('Оплату можно внести только в открытый месяц.')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->scopedExists(BillingPeriod::class, 'id')
                            ->native(false),
                        TextInput::make('amount')
                            ->label('Сумма')
                            ->numeric()
                            ->step('0.01')
                            ->minValue(0)
                            ->required(),
                        DatePicker::make('paid_at')
                            ->label('Дата оплаты')
                            ->native(false),
                        Textarea::make('note')
                            ->label('Примечание')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
