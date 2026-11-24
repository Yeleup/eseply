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
use Illuminate\Support\Facades\DB;

class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    /**
     * Filament would open its transaction before the validation, whose reads
     * take the snapshot of the transaction before the subscriber is locked;
     * MariaDB's snapshot isolation would then refuse the locking reads of the
     * receipt a concurrent Kaspi posting has just changed. The transaction is
     * opened in handleRecordCreation() instead, after the validation, and its
     * first statements are the locks.
     */
    protected ?bool $hasDatabaseTransactions = false;

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

        // Filament's own save, inside a transaction that starts with the locks
        // and is tried again if MariaDB still refuses a read as changed meanwhile.
        return DB::transaction(function () use ($organization, $data): Model {
            app(RecordManualPayment::class)->lockClientAndMonth($organization, (int) $data['client_id']);

            return parent::handleRecordCreation($data);
        }, attempts: 3);
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
