<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Organization;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

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
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $organization = $this->tenant;

        if ($organization instanceof Organization) {
            $data['utility_service_name'] = $organization->utilityService?->name;
            $data['utility_service_unit_of_measurement'] = $organization->utilityService?->unit_of_measurement;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Organization, 404);

        $utilityServiceData = [
            'name' => $data['utility_service_name'],
            'unit_of_measurement' => $data['utility_service_unit_of_measurement'],
            'status' => 'active',
        ];

        unset($data['utility_service_name'], $data['utility_service_unit_of_measurement']);

        $record->update($data);

        $record->utilityService()->updateOrCreate(
            ['organization_id' => $record->id],
            $utilityServiceData,
        );

        return $record;
    }
}
