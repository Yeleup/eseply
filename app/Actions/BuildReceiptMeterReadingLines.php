<?php

namespace App\Actions;

use App\Models\MeterReading;
use App\Models\Receipt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BuildReceiptMeterReadingLines
{
    /**
     * @return list<array{meter_number:string, previous_reading:string, current_reading:string, consumption:string, tariff_price:string, amount:string}>
     */
    public function handle(Receipt $receipt): array
    {
        return $this->handleMany(collect([$receipt]))[$receipt->getKey()] ?? [];
    }

    /**
     * Строки счётчиков для порции квитанций: показания и счётчики всех
     * квитанций загружаются двумя запросами, а не парой запросов на каждую
     * квитанцию, поэтому массовая печать не делает N+1. Показания отбираются
     * по точным парам «период — абоненты периода» внутри организации, а не
     * по независимым спискам абонентов и периодов: иначе порция с разными
     * периодами загрузила бы показания всех абонентов за все эти периоды.
     *
     * @param  Collection<int, Receipt>  $receipts
     * @return array<int|string, list<array{meter_number:string, previous_reading:string, current_reading:string, consumption:string, tariff_price:string, amount:string}>>
     */
    public function handleMany(Collection $receipts): array
    {
        $receiptsWithReadings = $receipts->filter(
            fn (Receipt $receipt): bool => $receipt->client_id && $receipt->billing_period_id,
        );

        $meterReadingsByClientPeriod = $receiptsWithReadings->isEmpty()
            ? collect()
            : MeterReading::query()
                ->with('meter')
                ->where(function (Builder $query) use ($receiptsWithReadings): void {
                    $receiptsWithReadings
                        ->groupBy(fn (Receipt $receipt): string => (int) $receipt->organization_id.':'.(int) $receipt->billing_period_id)
                        ->each(fn (Collection $periodReceipts): Builder => $query->orWhere(
                            fn (Builder $query): Builder => $query
                                ->where('organization_id', $periodReceipts->first()->organization_id)
                                ->where('billing_period_id', $periodReceipts->first()->billing_period_id)
                                ->whereIn('client_id', $periodReceipts->pluck('client_id')->unique()->values()),
                        ));
                })
                ->orderBy('meter_id')
                ->orderBy('id')
                ->get()
                ->groupBy(fn (MeterReading $meterReading): string => $this->clientPeriodKey(
                    $meterReading->organization_id,
                    $meterReading->client_id,
                    $meterReading->billing_period_id,
                ));

        $lines = [];

        foreach ($receipts as $receipt) {
            if (! $receipt->client_id || ! $receipt->billing_period_id) {
                $lines[$receipt->getKey()] = [];

                continue;
            }

            $lines[$receipt->getKey()] = $this->lines(
                $meterReadingsByClientPeriod->get($this->clientPeriodKey(
                    $receipt->organization_id,
                    $receipt->client_id,
                    $receipt->billing_period_id,
                ), collect()),
                $receipt->tariff_price === null ? null : (float) $receipt->tariff_price,
            );
        }

        return $lines;
    }

    /**
     * @param  Collection<int, MeterReading>  $meterReadings
     * @return list<array{meter_number:string, previous_reading:string, current_reading:string, consumption:string, tariff_price:string, amount:string}>
     */
    private function lines(Collection $meterReadings, ?float $tariffPrice): array
    {
        return $meterReadings
            ->map(function (MeterReading $meterReading) use ($tariffPrice): array {
                $consumption = (int) $meterReading->consumption;

                return [
                    'meter_number' => $meterReading->meter?->number ?? '-',
                    'previous_reading' => $this->reading($meterReading->previous_reading),
                    'current_reading' => $this->reading($meterReading->current_reading),
                    'consumption' => $this->reading($consumption),
                    'tariff_price' => $this->money($tariffPrice),
                    'amount' => $this->money($tariffPrice === null ? null : round($consumption * $tariffPrice, 2)),
                ];
            })
            ->values()
            ->all();
    }

    private function clientPeriodKey(mixed $organizationId, mixed $clientId, mixed $billingPeriodId): string
    {
        return (int) $organizationId.':'.(int) $clientId.':'.(int) $billingPeriodId;
    }

    private function reading(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 0, '.', ' ');
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 2, '.', ' ').' KZT';
    }
}
