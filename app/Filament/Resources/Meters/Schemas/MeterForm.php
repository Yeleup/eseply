<?php

namespace App\Filament\Resources\Meters\Schemas;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
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
                            ->options(function (): array {
                                $tenant = Filament::getTenant();
                                $user = auth()->user();

                                if (! $tenant instanceof Organization || ! $user instanceof User) {
                                    return [];
                                }

                                return Client::query()
                                    ->visibleToOrganizationMember($user, $tenant)
                                    ->orderBy('account_number')
                                    ->get()
                                    ->mapWithKeys(fn (Client $client): array => [
                                        $client->id => "{$client->account_number} - {$client->name}",
                                    ])
                                    ->all();
                            })
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
                        Textarea::make('note')
                            ->label('Примечание')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
