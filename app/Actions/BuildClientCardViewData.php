<?php

namespace App\Actions;

use App\ClientType;
use App\Models\Client;
use App\Models\Meter;
use App\Models\Payment;
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
            'payments' => function (HasMany $query): void {
                $query
                    ->orderByDesc('paid_at')
                    ->orderByDesc('id');
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
