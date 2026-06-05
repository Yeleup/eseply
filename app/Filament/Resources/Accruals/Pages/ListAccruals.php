<?php

namespace App\Filament\Resources\Accruals\Pages;

use App\Filament\Resources\Accruals\AccrualResource;
use App\Filament\Support\OrganizationMemberAccess;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListAccruals extends ListRecords
{
    protected static string $resource = AccrualResource::class;

    public function mount(): void
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('closeBillingMonth')
                ->label('Закрыть месяц')
                ->icon(Heroicon::OutlinedCalculator)
                ->url(fn (): string => AccrualResource::getUrl('close')),
        ];
    }
}
