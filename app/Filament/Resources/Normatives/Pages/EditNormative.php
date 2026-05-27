<?php

namespace App\Filament\Resources\Normatives\Pages;

use App\Filament\Resources\Normatives\NormativeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNormative extends EditRecord
{
    protected static string $resource = NormativeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
