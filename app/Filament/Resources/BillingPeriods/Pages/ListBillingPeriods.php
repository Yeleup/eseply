<?php

namespace App\Filament\Resources\BillingPeriods\Pages;

use App\Filament\Resources\BillingPeriods\BillingPeriodResource;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Organization;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

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
            Action::make('openNextBillingPeriod')
                ->label('Новый расчётный месяц')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->action(fn (): null => $this->openNextBillingPeriod()),
        ];
    }

    private function openNextBillingPeriod(): null
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization) {
            return null;
        }

        try {
            $billingPeriod = BillingPeriod::openNextFor($tenant, auth()->id());
        } catch (ValidationException $exception) {
            Notification::make()
                ->title($exception->validator->errors()->first() ?: 'Не удалось открыть расчётный месяц')
                ->danger()
                ->send();

            return null;
        }

        Notification::make()
            ->title("Открыт расчётный месяц {$billingPeriod->label}")
            ->success()
            ->send();

        return null;
    }
}
