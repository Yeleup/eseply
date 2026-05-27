<?php

namespace App\Filament\Resources\UtilityServices\Pages;

use App\Filament\Resources\UtilityServices\UtilityServiceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUtilityService extends EditRecord
{
    protected static string $resource = UtilityServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
