<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Organization;
use App\Models\User;
use App\OrganizationMemberRole;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class RegisterOrganization extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Создать организацию';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Организация')
                    ->columns(2)
                    ->schema([
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
                    ]),
                Section::make('Услуга организации')
                    ->columns(2)
                    ->schema([
                        TextInput::make('utility_service_name')
                            ->label('Услуга')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('utility_service_unit_of_measurement')
                            ->label('Единица измерения')
                            ->required()
                            ->maxLength(255),
                    ]),
                Section::make('XPayment / Kaspi')
                    ->columns(1)
                    ->schema([
                        TextInput::make('xpayment_api_key')
                            ->label('API key устройства')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->helperText('Уникальный xdev_* ключ этой организации. Можно заполнить позже в профиле организации.')
                            ->dehydrated(fn (?string $state): bool => filled($state)),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Organization
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        $utilityServiceData = [
            'name' => $data['utility_service_name'],
            'unit_of_measurement' => $data['utility_service_unit_of_measurement'],
            'status' => 'active',
        ];

        unset($data['utility_service_name'], $data['utility_service_unit_of_measurement']);

        $organization = Organization::create($data);

        $organization->utilityService()->create($utilityServiceData);

        $organization->users()->attach($user, [
            'role' => OrganizationMemberRole::Operator->value,
        ]);

        return $organization;
    }
}
