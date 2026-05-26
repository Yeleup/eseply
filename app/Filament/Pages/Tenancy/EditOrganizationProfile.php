<?php

namespace App\Filament\Pages\Tenancy;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;

class EditOrganizationProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Профиль организации';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Название организации')
                    ->required()
                    ->maxLength(255),
                TextInput::make('bin_iin')
                    ->label('БИН / ИИН')
                    ->maxLength(12),
                TextInput::make('phone')
                    ->label('Телефон')
                    ->tel()
                    ->maxLength(255),
                TextInput::make('address')
                    ->label('Адрес')
                    ->maxLength(255),
                TextInput::make('bank')
                    ->label('Банк')
                    ->maxLength(255),
                TextInput::make('iban')
                    ->label('IBAN')
                    ->maxLength(34),
                Textarea::make('note')
                    ->label('Примечание')
                    ->columnSpanFull(),
            ]);
    }
}
