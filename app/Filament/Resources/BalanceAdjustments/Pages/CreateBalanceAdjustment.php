<?php

namespace App\Filament\Resources\BalanceAdjustments\Pages;

use App\Filament\Resources\BalanceAdjustments\BalanceAdjustmentResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateBalanceAdjustment extends CreateRecord
{
    protected static string $resource = BalanceAdjustmentResource::class;

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
