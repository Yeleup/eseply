<?php

namespace App\Filament\Resources\MeterReadings\Pages;

use App\Filament\Resources\MeterReadings\MeterReadingResource;
use App\Filament\Support\CurrentBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateMeterReading extends CreateRecord
{
    protected static string $resource = MeterReadingResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->authorizeMeter($data['meter_id'] ?? null);

        $billingPeriod = $this->currentBillingPeriod();
        $this->ensureReadingDoesNotAlreadyExist($data['meter_id'] ?? null, $billingPeriod->getKey());

        $data['billing_period_id'] = $billingPeriod->getKey();
        $data['previous_reading'] = MeterReading::previousReadingForBillingPeriod(
            $data['meter_id'] ?? null,
            $billingPeriod->getKey(),
        ) ?? 0;

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

    private function ensureReadingDoesNotAlreadyExist(mixed $meterId, int|string $billingPeriodId): void
    {
        if (! MeterReading::existsForMeterBillingPeriod($meterId, $billingPeriodId)) {
            return;
        }

        throw ValidationException::withMessages([
            'data.current_reading' => MeterReading::DUPLICATE_BILLING_PERIOD_MESSAGE,
        ]);
    }

    private function authorizeMeter(mixed $meterId): void
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();
        $meter = Meter::query()->find($meterId);

        abort_unless(
            $tenant instanceof Organization
                && $user instanceof User
                && $meter instanceof Meter
                && OrganizationMemberAccess::canCreateMeterReadingForMeter($meter),
            403,
        );
    }

    private function currentBillingPeriod(): BillingPeriod
    {
        $tenant = Filament::getTenant();

        abort_unless($tenant instanceof Organization, 403);

        return BillingPeriod::requireCurrentEditableFor($tenant);
    }
}
