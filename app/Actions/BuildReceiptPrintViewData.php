<?php

namespace App\Actions;

use App\Models\Receipt;
use App\Support\ReceiptTemplateConfig;
use App\Support\ReceiptTemplateImageStorage;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class BuildReceiptPrintViewData
{
    public function __construct(
        private readonly BuildReceiptMeterReadingLines $buildReceiptMeterReadingLines,
    ) {}

    /**
     * @return array{
     *     receipt: Receipt,
     *     template: ReceiptTemplateConfig,
     *     generatedAt: Carbon,
     *     organizationDetails: list<array{label: string, value: string}>,
     *     clientDetails: list<array{label: string, value: string}>,
     *     calculationDetails: list<array{label: string, value: string}>,
     *     meterReadingLines: list<array{meter_number:string, previous_reading:string, current_reading:string, consumption:string, tariff_price:string, amount:string}>,
     *     balanceDetails: list<array{label: string, value: string}>,
     *     paymentDue: string,
     *     clientAddress: string
     * }
     */
    public function handle(Receipt $receipt, ?ReceiptTemplateConfig $template = null): array
    {
        $receipt->loadMissing([
            'billingPeriod',
            'client.region',
            'client.street',
            'organization.utilityService',
            'organization.receiptTemplate',
        ]);

        $template ??= $this->templateFor($receipt);

        return [
            'receipt' => $receipt,
            'template' => $template,
            'generatedAt' => now(),
            'organizationDetails' => $this->details([
                $template->label('organization') => $receipt->organization?->name,
                $template->label('bin_iin') => $receipt->organization?->bin_iin,
                $template->label('phone') => $receipt->organization?->phone,
                $template->label('organization_address') => $receipt->organization?->address,
                $template->label('bank') => $receipt->organization?->bank,
                $template->label('iban') => $receipt->organization?->iban,
            ]),
            'clientDetails' => $this->details([
                $template->label('account_number') => $receipt->account_number,
                $template->label('client_name') => $receipt->client_name,
                $template->label('client_address') => $this->clientAddress($receipt),
                $template->label('period') => $receipt->billingPeriod?->label ?? $receipt->period,
                $template->label('service') => $receipt->utility_service_name,
                $template->label('billing_type') => $this->billingTypeLabel($receipt->billing_type),
            ]),
            'calculationDetails' => $this->details([
                'Объём' => $this->decimal($receipt->volume),
                'Единица измерения' => $receipt->organization?->utilityService?->unit_of_measurement,
                'Тариф' => $this->money($receipt->tariff_price),
                'Сумма' => $this->money($receipt->amount),
                'Оплачено' => $this->money($receipt->paid_amount),
                'Корректировка' => $this->money($receipt->adjustment_amount),
            ]),
            'meterReadingLines' => $this->buildReceiptMeterReadingLines->handle($receipt),
            'balanceDetails' => $this->details([
                'Начальное сальдо' => $this->money($receipt->opening_balance),
                'Сумма' => $this->money($receipt->amount),
                'Оплачено' => $this->money($receipt->paid_amount),
                'Корректировка' => $this->money($receipt->adjustment_amount),
                'Конечное сальдо' => $this->money($receipt->closing_balance),
            ]),
            'paymentDue' => $this->money(max(0, (float) $receipt->closing_balance)),
            'clientAddress' => $this->clientAddress($receipt),
        ];
    }

    private function templateFor(Receipt $receipt): ReceiptTemplateConfig
    {
        $model = $receipt->organization?->receiptTemplate;

        if (! $model) {
            return ReceiptTemplateConfig::default();
        }

        return ReceiptTemplateConfig::fromSettings(
            is_array($model->settings) ? $model->settings : [],
            ReceiptTemplateImageStorage::url($model->logo_path),
            ReceiptTemplateImageStorage::url($model->qr_path),
        );
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

    /**
     * Объём печатается без незначащих нулей: по счётчику он равен сумме целых
     * расходов, поэтому дробная часть выводится, только если она есть.
     */
    private function decimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return rtrim(rtrim(number_format((float) $value, 4, '.', ' '), '0'), '.');
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
