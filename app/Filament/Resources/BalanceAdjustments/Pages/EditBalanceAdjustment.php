<?php

namespace App\Filament\Resources\BalanceAdjustments\Pages;

use App\Filament\Resources\BalanceAdjustments\BalanceAdjustmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBalanceAdjustment extends EditRecord
{
    protected static string $resource = BalanceAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
