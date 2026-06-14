<?php

namespace App\Filament\Resources\MeterReadings\Pages;

use App\Filament\Resources\MeterReadings\MeterReadingResource;
use App\Filament\Support\CurrentBillingPeriod;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMeterReadings extends ListRecords
{
    protected static string $resource = MeterReadingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => MeterReadingResource::canCreate())
                ->disabled(fn (): bool => CurrentBillingPeriod::missing())
                ->tooltip(fn (): ?string => CurrentBillingPeriod::missingTooltip()),
        ];
    }
}
