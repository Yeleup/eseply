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
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

class CreateMeterReading extends CreateRecord
{
    protected static string $resource = MeterReadingResource::class;

    /**
     * @var array{meter_id: int, billing_period_id: int, current_reading: int}|null
     */
    protected ?array $confirmedLargeConsumption = null;

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

        $this->confirmLargeConsumptionIfNeeded($data, $billingPeriod);

        return $data;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->largeConsumptionConfirmationAction()];
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function confirmLargeConsumptionIfNeeded(array $data, BillingPeriod $billingPeriod): void
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        if (! $tenant instanceof Organization || ! $user instanceof User || ! $user->isOrganizationController($tenant)) {
            return;
        }

        $confirmation = MeterReading::largeConsumptionConfirmationFor(
            meterId: $data['meter_id'] ?? null,
            billingPeriodId: $billingPeriod->getKey(),
            previousReading: MeterReading::wholeReading($data['previous_reading'] ?? null),
            currentReading: $data['current_reading'] ?? null,
        );

        if ($confirmation === null || $this->hasConfirmedLargeConsumption($data, $billingPeriod)) {
            return;
        }

        $this->mountAction('confirmLargeConsumption', [
            'meter_id' => (int) $data['meter_id'],
            'billing_period_id' => (int) $billingPeriod->getKey(),
            ...$confirmation,
        ]);

        throw new Halt;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasConfirmedLargeConsumption(array $data, BillingPeriod $billingPeriod): bool
    {
        return $this->confirmedLargeConsumption === [
            'meter_id' => (int) ($data['meter_id'] ?? 0),
            'billing_period_id' => (int) $billingPeriod->getKey(),
            'current_reading' => MeterReading::wholeReading($data['current_reading'] ?? null),
        ];
    }

    private function largeConsumptionConfirmationAction(): Action
    {
        return Action::make('confirmLargeConsumption')
            ->extraAttributes(['class' => 'hidden'])
            ->requiresConfirmation()
            ->modalHeading(__('meter-readings.confirmation.large_consumption.heading'))
            ->modalDescription(fn (array $arguments): string => MeterReading::largeConsumptionConfirmationDescription([
                'current_reading' => (int) ($arguments['current_reading'] ?? 0),
                'consumption' => (int) ($arguments['consumption'] ?? 0),
                'average_consumption' => (float) ($arguments['average_consumption'] ?? 0),
            ]))
            ->modalSubmitActionLabel(__('meter-readings.confirmation.large_consumption.submit'))
            ->modalCancelActionLabel(__('meter-readings.confirmation.large_consumption.cancel'))
            ->action(function (array $arguments): void {
                $this->confirmedLargeConsumption = [
                    'meter_id' => (int) ($arguments['meter_id'] ?? 0),
                    'billing_period_id' => (int) ($arguments['billing_period_id'] ?? 0),
                    'current_reading' => (int) ($arguments['current_reading'] ?? 0),
                ];

                $this->create();
            });
    }
}
