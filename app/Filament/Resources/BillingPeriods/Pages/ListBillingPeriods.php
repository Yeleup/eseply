<?php

namespace App\Filament\Resources\BillingPeriods\Pages;

use App\Filament\Resources\BillingPeriods\BillingPeriodResource;
use App\Filament\Support\OrganizationMemberAccess;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBillingPeriods extends ListRecords
{
    protected static string $resource = BillingPeriodResource::class;

    public function mount(): void
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
