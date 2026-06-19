<?php

namespace App\Actions;

use App\BalanceAdjustmentType;
use App\ClientType;
use App\Models\Accrual;
use App\Models\BalanceAdjustment;
use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Payment;
use App\Models\Receipt;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class BuildClientCardViewData
{
    /**
     * @return array{
     *     client: Client,
     *     generatedAt: Carbon,
     *     clientDetails: list<array{label: string, value: string}>,
     *     addressDetails: list<array{label: string, value: string}>,
     *     billingDetails: list<array{label: string, value: string}>,
     *     meters: list<array{title: string, details: list<array{label: string, value: string}>}>,
     *     meterRows: list<array<string, string>>,
     *     meterReadingRows: list<array<string, string>>,
     *     paymentRows: list<array<string, string>>,
     *     balanceAdjustmentRows: list<array<string, string>>,
     *     accrualRows: list<array<string, string>>,
     *     receiptRows: list<array<string, string>>,
     *     payments: list<array{title: string, details: list<array{label: string, value: string}>}>
     * }
     */
    public function handle(Client $client): array
    {
        $this->loadClientData($client);

        $clientTypeValue = $client->client_type instanceof ClientType
            ? $client->client_type->value
            : $client->client_type;

        $utilityService = $client->utilityService ?? $client->organization?->utilityService;

        return [
            'client' => $client,
            'generatedAt' => now(),
            'clientDetails' => $this->details([
                'Организация' => $client->organization?->name,
                'Услуга организации' => $utilityService?->name,
                'Единица измерения' => $utilityService?->unit_of_measurement,
                'Лицевой счёт' => $client->account_number,
                'ФИО / Наименование' => $client->name,
                'ИИН' => $client->iin,
                'Тип клиента' => ClientType::labelFor($client->client_type) ?? $this->value($clientTypeValue),
                'Статус' => $this->statusLabel($client->status),
                'Телефон' => $client->phone,
                'Договор' => $client->contract,
                'Тех. условия' => $client->technical_conditions,
                'Примечание' => $client->note,
                'Создан' => $this->dateTime($client->created_at),
                'Обновлён' => $this->dateTime($client->updated_at),
            ]),
            'addressDetails' => $this->details([
                'Регион' => $client->region?->name,
                'Улица' => $client->street?->name,
                'Дом' => $client->house,
                'Квартира / помещение' => $client->apartment,
            ]),
            'billingDetails' => $this->details([
                'Тип начисления' => $this->billingTypeLabel($client->billing_type),
                'Количество проживающих' => $client->residents_count,
                'Фиксированная сумма' => $this->money($client->fixed_amount),
            ]),
            'meters' => $client->meters
                ->values()
                ->map(fn (Meter $meter, int $index): array => [
                    'title' => 'Счётчик #'.($index + 1),
                    'details' => $this->meterDetails($meter),
                ])
                ->all(),
            'meterRows' => $client->meters
                ->values()
                ->map(fn (Meter $meter): array => $this->meterRow($meter))
                ->all(),
            'meterReadingRows' => $client->meterReadings
                ->values()
                ->map(fn (MeterReading $meterReading): array => $this->meterReadingRow($meterReading))
                ->all(),
            'paymentRows' => $client->payments
                ->values()
                ->map(fn (Payment $payment): array => $this->paymentRow($payment))
                ->all(),
            'balanceAdjustmentRows' => $client->balanceAdjustments
                ->values()
                ->map(fn (BalanceAdjustment $balanceAdjustment): array => $this->balanceAdjustmentRow($balanceAdjustment))
                ->all(),
            'accrualRows' => $client->accruals
                ->values()
                ->map(fn (Accrual $accrual): array => $this->accrualRow($accrual))
                ->all(),
            'receiptRows' => $client->receipts
                ->values()
                ->map(fn (Receipt $receipt): array => $this->receiptRow($receipt))
                ->all(),
            'payments' => $client->payments
                ->values()
                ->map(fn (Payment $payment, int $index): array => [
                    'title' => 'Оплата #'.($index + 1),
                    'details' => $this->paymentDetails($payment),
                ])
                ->all(),
        ];
    }

    private function loadClientData(Client $client): void
    {
        $client->load([
            'organization.utilityService',
            'utilityService',
            'region',
            'street',
            'meters' => function (HasMany $query): void {
                $query
                    ->with('utilityService')
                    ->orderBy('number');
            },
            'meterReadings' => function (HasMany $query): void {
                $query
                    ->with([
                        'billingPeriod',
                        'meter',
                        'utilityService',
                    ])
                    ->orderByBillingPeriodDesc()
                    ->orderByDesc('read_at')
                    ->orderByDesc('id');
            },
            'payments' => function (HasMany $query): void {
                $query
                    ->with('billingPeriod')
                    ->orderByDesc('paid_at')
                    ->orderByDesc('id');
            },
            'balanceAdjustments' => function (HasMany $query): void {
                $query
                    ->with('billingPeriod')
                    ->orderByDesc('adjusted_at')
                    ->orderByDesc('id');
            },
            'accruals' => function (HasMany $query): void {
                $query
                    ->with('billingPeriod')
                    ->orderByBillingPeriodDesc()
                    ->latest('closed_at')
                    ->latest('id');
            },
            'receipts' => function (HasMany $query): void {
                $query
                    ->with('billingPeriod')
                    ->orderByBillingPeriodDesc()
                    ->latest('issued_at')
                    ->latest('id');
            },
        ]);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function meterDetails(Meter $meter): array
    {
        return $this->details([
            'Номер' => $meter->number,
            'Услуга' => $meter->utilityService?->name,
            'Статус' => $this->meterStatusLabel($meter->status),
            'Начальное показание' => $this->decimal($meter->initial_reading),
            'Дата установки' => $this->date($meter->installed_on),
            'Дата снятия' => $this->date($meter->removed_on),
            'Примечание' => $meter->note,
            'Создан' => $this->dateTime($meter->created_at),
            'Обновлён' => $this->dateTime($meter->updated_at),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function meterRow(Meter $meter): array
    {
        return [
            'number' => $this->value($meter->number),
            'utility_service' => $this->value($meter->utilityService?->name),
            'status' => $this->meterStatusLabel($meter->status),
            'initial_reading' => $this->decimal($meter->initial_reading),
            'installed_on' => $this->date($meter->installed_on),
            'removed_on' => $this->date($meter->removed_on),
            'note' => $this->value($meter->note),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function meterReadingRow(MeterReading $meterReading): array
    {
        return [
            'period' => $this->period($meterReading),
            'meter_number' => $this->value($meterReading->meter?->number),
            'previous_reading' => $this->decimal($meterReading->previous_reading),
            'current_reading' => $this->decimal($meterReading->current_reading),
            'consumption' => $this->decimal($meterReading->consumption),
            'read_at' => $this->date($meterReading->read_at),
            'note' => $this->value($meterReading->note),
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function paymentDetails(Payment $payment): array
    {
        return $this->details([
            'Период' => $payment->period,
            'Сумма' => $this->money($payment->amount),
            'Дата оплаты' => $this->date($payment->paid_at),
            'Примечание' => $payment->note,
            'Создана' => $this->dateTime($payment->created_at),
            'Обновлена' => $this->dateTime($payment->updated_at),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function paymentRow(Payment $payment): array
    {
        return [
            'period' => $this->period($payment),
            'amount' => $this->money($payment->amount),
            'paid_at' => $this->date($payment->paid_at),
            'note' => $this->value($payment->note),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function balanceAdjustmentRow(BalanceAdjustment $balanceAdjustment): array
    {
        return [
            'period' => $this->period($balanceAdjustment),
            'type' => $this->balanceAdjustmentTypeLabel($balanceAdjustment->type),
            'amount' => $this->money($balanceAdjustment->amount),
            'adjusted_at' => $this->date($balanceAdjustment->adjusted_at),
            'note' => $this->value($balanceAdjustment->note),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function accrualRow(Accrual $accrual): array
    {
        return [
            'period' => $this->period($accrual),
            'utility_service' => $this->value($accrual->utility_service_name),
            'billing_type' => $this->billingTypeLabel($accrual->billing_type),
            'volume' => $this->decimal($accrual->volume),
            'tariff_price' => $this->money($accrual->tariff_price),
            'amount' => $this->money($accrual->amount),
            'paid_amount' => $this->money($accrual->paid_amount),
            'adjustment_amount' => $this->money($accrual->adjustment_amount),
            'opening_balance' => $this->money($accrual->opening_balance),
            'closing_balance' => $this->money($accrual->closing_balance),
            'closed_at' => $this->dateTime($accrual->closed_at),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function receiptRow(Receipt $receipt): array
    {
        return [
            'receipt_number' => $this->value($receipt->receipt_number),
            'period' => $this->period($receipt),
            'utility_service' => $this->value($receipt->utility_service_name),
            'billing_type' => $this->billingTypeLabel($receipt->billing_type),
            'volume' => $this->decimal($receipt->volume),
            'tariff_price' => $this->money($receipt->tariff_price),
            'amount' => $this->money($receipt->amount),
            'paid_amount' => $this->money($receipt->paid_amount),
            'adjustment_amount' => $this->money($receipt->adjustment_amount),
            'opening_balance' => $this->money($receipt->opening_balance),
            'closing_balance' => $this->money($receipt->closing_balance),
            'issued_at' => $this->dateTime($receipt->issued_at),
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

    private function value(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if ($value instanceof ClientType) {
            return ClientType::labelFor($value) ?? $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $this->dateTime($value);
        }

        return (string) $value;
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

    private function date(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return $value instanceof DateTimeInterface
            ? $value->format('d.m.Y')
            : Carbon::parse($value)->format('d.m.Y');
    }

    private function dateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return $value instanceof DateTimeInterface
            ? $value->format('d.m.Y H:i')
            : Carbon::parse($value)->format('d.m.Y H:i');
    }

    private function billingTypeLabel(?string $billingType): string
    {
        return match ($billingType) {
            'meter' => 'По счётчику',
            'per_person' => 'На одного человека',
            'fixed' => 'Фиксированная сумма',
            default => $this->value($billingType),
        };
    }

    private function balanceAdjustmentTypeLabel(BalanceAdjustmentType|string|null $type): string
    {
        return BalanceAdjustmentType::labelFor($type) ?? $this->value($type);
    }

    private function period(Accrual|BalanceAdjustment|MeterReading|Payment|Receipt $record): string
    {
        return $this->value($record->period);
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'active' => 'Активный',
            'inactive' => 'Неактивный',
            default => $this->value($status),
        };
    }

    private function meterStatusLabel(?string $status): string
    {
        return match ($status) {
            'active' => 'Активный',
            'removed' => 'В архиве',
            default => $this->value($status),
        };
    }
}
