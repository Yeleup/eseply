<?php

namespace App\Filament\Resources\MeterReadings\Pages;

use App\Filament\Resources\MeterReadings\MeterReadingResource;
use App\Models\MeterReading;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMeterReading extends EditRecord
{
    protected static string $resource = MeterReadingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['previous_reading'] = MeterReading::previousReadingFor(
            $data['meter_id'] ?? null,
            $data['period'] ?? null,
        ) ?? 0;

        return $data;
    }
}
