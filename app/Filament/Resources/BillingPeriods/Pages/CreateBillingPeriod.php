<?php

namespace App\Filament\Resources\BillingPeriods\Pages;

use App\BillingPeriodStatus;
use App\Filament\Resources\BillingPeriods\BillingPeriodResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateBillingPeriod extends CreateRecord
{
    protected static string $resource = BillingPeriodResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organization_id'] = Filament::getTenant()?->getKey();
        $data['status'] = BillingPeriodStatus::Open;
        $data['opened_at'] = now();
        $data['opened_by_user_id'] = auth()->id();

        return $data;
    }
}
