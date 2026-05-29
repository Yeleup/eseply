<?php

namespace App\Filament\Resources\BalanceAdjustments\Pages;

use App\Filament\Resources\BalanceAdjustments\BalanceAdjustmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBalanceAdjustments extends ListRecords
{
    protected static string $resource = BalanceAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
