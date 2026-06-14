<?php

namespace App\Actions;

use App\Models\Receipt;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class BuildReceiptPrintViewData
{
    /**
     * @return array{
     *     receipt: Receipt,
     *     generatedAt: Carbon,
     *     organizationDetails: list<array{label: string, value: string}>,
     *     clientDetails: list<array{label: string, value: string}>,
     *     calculationDetails: list<array{label: string, value: string}>,
     *     balanceDetails: list<array{label: string, value: string}>,
     *     paymentDue: string,
     *     clientAddress: string
     * }
     */
    public function handle(Receipt $receipt): array
    {
        $receipt->load([
            'billingPeriod',
            'client.region',
            'client.street',
            'organization.utilityService',
        ]);

        return [
            'receipt' => $receipt,
            'generatedAt' => now(),
            'organizationDetails' => $this->details([
                'Организация' => $receipt->organization?->name,
                'БИН / ИИН' => $receipt->organization?->bin_iin,
                'Телефон' => $receipt->organization?->phone,
                'Адрес' => $receipt->organization?->address,
                'Банк' => $receipt->organization?->bank,
                'IBAN' => $receipt->organization?->iban,
            ]),
            'clientDetails' => $this->details([
                'Лицевой счёт' => $receipt->account_number,
                'Абонент' => $receipt->client_name,
                'Адрес' => $this->clientAddress($receipt),
                'Период' => $receipt->billingPeriod?->label ?? $receipt->period,
                'Услуга' => $receipt->utility_service_name,
                'Тип начисления' => $this->billingTypeLabel($receipt->billing_type),
            ]),
            'calculationDetails' => $this->details([
                'Объём' => $this->decimal($receipt->volume),
                'Единица измерения' => $receipt->organization?->utilityService?->unit_of_measurement,
                'Тариф' => $this->money($receipt->tariff_price),
                'Начислено' => $this->money($receipt->amount),
                'Оплачено' => $this->money($receipt->paid_amount),
                'Корректировка' => $this->money($receipt->adjustment_amount),
            ]),
            'balanceDetails' => $this->details([
                'Начальное сальдо' => $this->money($receipt->opening_balance),
                'Начислено' => $this->money($receipt->amount),
                'Оплачено' => $this->money($receipt->paid_amount),
                'Корректировка' => $this->money($receipt->adjustment_amount),
                'Конечное сальдо' => $this->money($receipt->closing_balance),
            ]),
            'paymentDue' => $this->money(max(0, (float) $receipt->closing_balance)),
            'clientAddress' => $this->clientAddress($receipt),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<array{label: string, value: string}>
     */
    private function details(array $values): array
    {
        $details = [];

        foreach ($values as $label => $value) {
            $details[] = [
                'label' => $label,
                'value' => $this->value($value),
            ];
        }

        return $details;
    }

    private function clientAddress(Receipt $receipt): string
    {
        $client = $receipt->client;

        if (! $client) {
            return '-';
        }

        $parts = array_filter([
            $client->region?->name,
            $client->street?->name,
            filled($client->house) ? 'д. '.$client->house : null,
            filled($client->apartment) ? 'кв. '.$client->apartment : null,
        ], fn (?string $part): bool => filled($part));

        return $parts === [] ? '-' : implode(', ', $parts);
    }

    private function billingTypeLabel(?string $billingType): string
    {
        return match ($billingType) {
            'fixed' => 'Фиксированная сумма',
            'meter' => 'По счётчику',
            'per_person' => 'На одного человека',
            default => $this->value($billingType),
        };
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 2, '.', ' ').' KZT';
    }

    private function decimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 4, '.', ' ');
    }

    private function value(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('d.m.Y H:i');
        }

        return (string) $value;
    }
}
