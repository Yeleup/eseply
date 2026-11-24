<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Actions\RecordManualPayment;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\CurrentBillingPeriod;
use App\Models\Organization;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    /**
     * Always cash, with the subscriber row locked first (RecordManualPayment);
     * the subscriber is looked up inside the current organization only.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $organization = Filament::getTenant();

        abort_unless($organization instanceof Organization, 404);

        return app(RecordManualPayment::class)->handle(
            $organization,
            (int) $data['client_id'],
            $data,
            auth()->id(),
        );
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
