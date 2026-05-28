<?php

namespace App\Filament\Resources\Tariffs\Pages;

use App\Filament\Resources\Tariffs\TariffResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateTariff extends CreateRecord
{
    protected static string $resource = TariffResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['utility_service_id'] = Filament::getTenant()?->utilityService?->getKey();

        return $data;
    }
}
