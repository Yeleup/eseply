<?php

namespace App\Filament\Resources\UtilityServices\Pages;

use App\Filament\Resources\UtilityServices\UtilityServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUtilityServices extends ListRecords
{
    protected static string $resource = UtilityServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
