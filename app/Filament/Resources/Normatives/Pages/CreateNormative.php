<?php

namespace App\Filament\Resources\Normatives\Pages;

use App\Filament\Resources\Normatives\NormativeResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateNormative extends CreateRecord
{
    protected static string $resource = NormativeResource::class;

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
