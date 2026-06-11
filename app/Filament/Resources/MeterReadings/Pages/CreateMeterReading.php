<?php

namespace App\Filament\Resources\MeterReadings\Pages;

use App\Filament\Resources\MeterReadings\MeterReadingResource;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

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
