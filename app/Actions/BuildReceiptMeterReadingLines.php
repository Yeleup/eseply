<?php

namespace App\Actions;

use App\Models\MeterReading;
use App\Models\Receipt;

class BuildReceiptMeterReadingLines
{
    /**
     * @return list<array{meter_number:string, previous_reading:string, current_reading:string, consumption:string, tariff_price:string, amount:string}>
     */
    public function handle(Receipt $receipt): array
    {
        if (! $receipt->client_id || ! $receipt->billing_period_id) {
            return [];
        }

        $tariffPrice = $receipt->tariff_price === null ? null : (float) $receipt->tariff_price;

        return MeterReading::query()
            ->with('meter')
            ->where('organization_id', $receipt->organization_id)
            ->where('client_id', $receipt->client_id)
            ->where('billing_period_id', $receipt->billing_period_id)
            ->orderBy('meter_id')
            ->orderBy('id')
            ->get()
            ->map(function (MeterReading $meterReading) use ($tariffPrice): array {
                $consumption = (float) $meterReading->consumption;

                return [
                    'meter_number' => $meterReading->meter?->number ?? '-',
                    'previous_reading' => $this->decimal($meterReading->previous_reading),
                    'current_reading' => $this->decimal($meterReading->current_reading),
                    'consumption' => $this->decimal($consumption),
                    'tariff_price' => $this->money($tariffPrice),
                    'amount' => $this->money($tariffPrice === null ? null : round($consumption * $tariffPrice, 2)),
                ];
            })
            ->values()
            ->all();
    }

    private function decimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 4, '.', ' ');
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 2, '.', ' ').' KZT';
    }
}
