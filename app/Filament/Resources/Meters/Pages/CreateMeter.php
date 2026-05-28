<?php

namespace App\Filament\Resources\Meters\Pages;

use App\Filament\Resources\Meters\MeterResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateMeter extends CreateRecord
{
    protected static string $resource = MeterResource::class;

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
