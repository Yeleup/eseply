<?php

namespace App\Filament\Resources\MeterReadings\Pages;

use App\Filament\Resources\MeterReadings\MeterReadingResource;
use App\Models\MeterReading;
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
        $data['previous_reading'] = MeterReading::previousReadingFor(
            $data['meter_id'] ?? null,
            $data['period'] ?? null,
        ) ?? 0;

        return $data;
    }
}
