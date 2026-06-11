<?php

namespace App\Filament\Resources\MeterReadings\Pages;

use App\Filament\Resources\MeterReadings\MeterReadingResource;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditMeterReading extends EditRecord
{
    protected static string $resource = MeterReadingResource::class;

    protected function authorizeAccess(): void
    {
        $record = $this->getRecord();

        abort_unless(
            $record instanceof MeterReading
                && OrganizationMemberAccess::canUpdateMeterReading($record),
            404,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => $this->record instanceof MeterReading && MeterReadingResource::canDelete($this->record)),
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
            $data['billing_period_id'] ?? null,
        ) ?? 0;

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
}
