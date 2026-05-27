<?php

namespace App\Filament\Resources\TariffCategories\Pages;

use App\Filament\Resources\TariffCategories\TariffCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTariffCategory extends EditRecord
{
    protected static string $resource = TariffCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
