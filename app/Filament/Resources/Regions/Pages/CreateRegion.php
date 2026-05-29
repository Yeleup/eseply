<?php

namespace App\Filament\Resources\Regions\Pages;

use App\Filament\Resources\Regions\RegionResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateRegion extends CreateRecord
{
    protected static string $resource = RegionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organization_id'] = Filament::getTenant()?->getKey();

        return $data;
    }
}
