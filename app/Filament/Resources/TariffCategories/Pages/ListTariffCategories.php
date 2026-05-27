<?php

namespace App\Filament\Resources\TariffCategories\Pages;

use App\Filament\Resources\TariffCategories\TariffCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTariffCategories extends ListRecords
{
    protected static string $resource = TariffCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
