<?php

namespace App\Filament\Resources\Normatives\Pages;

use App\Filament\Resources\Normatives\NormativeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNormatives extends ListRecords
{
    protected static string $resource = NormativeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
