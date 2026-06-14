<?php

namespace App\Filament\Resources\BalanceAdjustments\Pages;

use App\Filament\Resources\BalanceAdjustments\BalanceAdjustmentResource;
use App\Filament\Support\CurrentBillingPeriod;
use Filament\Actions\Action;
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

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->disabled(fn (): bool => CurrentBillingPeriod::missing())
            ->tooltip(fn (): ?string => CurrentBillingPeriod::missingTooltip());
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->disabled(fn (): bool => CurrentBillingPeriod::missing())
            ->tooltip(fn (): ?string => CurrentBillingPeriod::missingTooltip());
    }
}
