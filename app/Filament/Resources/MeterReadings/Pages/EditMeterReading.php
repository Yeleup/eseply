<?php

namespace App\Filament\Resources\MeterReadings\Pages;

use App\Filament\Resources\MeterReadings\MeterReadingResource;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

class EditMeterReading extends EditRecord
{
    protected static string $resource = MeterReadingResource::class;

    /**
     * @var array{meter_id: int, billing_period_id: int, current_reading: int}|null
     */
    protected ?array $confirmedLargeConsumption = null;

    protected function authorizeAccess(): void
    {
        $record = $this->getRecord();

        abort_unless(
            $record instanceof MeterReading
                && MeterReadingResource::canEdit($record),
            404,
        );
    }

    /**
     * @return array<DeleteAction|Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => $this->record instanceof MeterReading && MeterReadingResource::canDelete($this->record)),
            $this->largeConsumptionConfirmationAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->authorizeMeter($data['meter_id'] ?? null);

        $data['previous_reading'] = MeterReading::previousReadingForBillingPeriod(
            $data['meter_id'] ?? null,
            $this->record instanceof MeterReading ? $this->record->billing_period_id : null,
        ) ?? 0;

        $this->confirmLargeConsumptionIfNeeded($data);

        return $data;
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function confirmLargeConsumptionIfNeeded(array $data): void
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();
        $billingPeriodId = $this->record instanceof MeterReading ? $this->record->billing_period_id : null;

        if (! $tenant instanceof Organization || ! $user instanceof User || ! $user->isOrganizationController($tenant) || $billingPeriodId === null) {
            return;
        }

        $confirmation = MeterReading::largeConsumptionConfirmationFor(
            meterId: $data['meter_id'] ?? null,
            billingPeriodId: $billingPeriodId,
            previousReading: MeterReading::wholeReading($data['previous_reading'] ?? null),
            currentReading: $data['current_reading'] ?? null,
        );

        if ($confirmation === null || $this->hasConfirmedLargeConsumption($data, $billingPeriodId)) {
            return;
        }

        $this->mountAction('confirmLargeConsumption', [
            'meter_id' => (int) $data['meter_id'],
            'billing_period_id' => (int) $billingPeriodId,
            ...$confirmation,
        ]);

        throw new Halt;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasConfirmedLargeConsumption(array $data, int $billingPeriodId): bool
    {
        return $this->confirmedLargeConsumption === [
            'meter_id' => (int) ($data['meter_id'] ?? 0),
            'billing_period_id' => $billingPeriodId,
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

                $this->save();
            });
    }
}
