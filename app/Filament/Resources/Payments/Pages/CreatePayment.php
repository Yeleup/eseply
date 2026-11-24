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
     * The payment is saved by Filament itself, in one transaction with the
     * locks of RecordManualPayment.
     */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return RecordManualPayment::manualData($data, (int) Filament::getTenant()?->getKey(), auth()->id());
    }

    /**
     * The subscriber is looked up inside the current organization only; its
     * row and the month are locked before Filament's own save.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $organization = Filament::getTenant();

        abort_unless($organization instanceof Organization, 404);

        app(RecordManualPayment::class)->lockClientAndMonth($organization, (int) $data['client_id']);

        return parent::handleRecordCreation($data);
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
