<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\CurrentBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    public function mount(): void
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->disabled(fn (): bool => CurrentBillingPeriod::missing())
                ->tooltip(fn (): ?string => CurrentBillingPeriod::missingTooltip()),
        ];
    }
}
