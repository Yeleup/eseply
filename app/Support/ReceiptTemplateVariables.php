<?php

namespace App\Support;

use App\Models\Receipt;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Каталог переменных HTML-шаблона квитанции: единственный источник правды
 * о доступных плейсхолдерах, их русских названиях и значениях.
 */
final class ReceiptTemplateVariables
{
    /**
     * Ключ => русское название (для палитры редактора). Последние три —
     * составные фрагменты, остальные — скалярные значения.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'organization_name' => 'Название организации',
            'organization_address' => 'Адрес организации',
            'organization_bin' => 'БИН / ИИН',
            'organization_phone' => 'Телефон организации',
            'organization_bank' => 'Банк',
            'organization_iban' => 'IBAN',
            'account_number' => 'Лицевой счёт',
            'client_name' => 'Абонент',
            'client_address' => 'Адрес абонента',
            'period' => 'Период',
            'service_name' => 'Услуга',
            'billing_type' => 'Тип расчёта',
            'receipt_number' => 'Номер квитанции',
            'issued_date' => 'Дата квитанции',
            'generated_at' => 'Сформирована',
            'volume' => 'Объём',
            'unit' => 'Единица измерения',
            'tariff_price' => 'Тариф',
            'amount' => 'Сумма',
            'paid_amount' => 'Оплачено',
            'adjustment_amount' => 'Корректировка',
            'opening_balance' => 'Долг (начальное сальдо)',
            'closing_balance' => 'Конечное сальдо',
            'amount_due' => 'К оплате',
            'copy_title' => 'Название экземпляра',
            'meters_table' => 'Таблица счётчиков',
            'logo' => 'Логотип',
            'qr' => 'QR-код',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function values(Receipt $receipt, string $copyTitle, Carbon $generatedAt): array
    {
        $organization = $receipt->organization;

        return [
            'organization_name' => self::value($organization?->name),
            'organization_address' => self::value($organization?->address),
            'organization_bin' => self::value($organization?->bin_iin),
            'organization_phone' => self::value($organization?->phone),
            'organization_bank' => self::value($organization?->bank),
            'organization_iban' => self::value($organization?->iban),
            'account_number' => self::value($receipt->account_number),
            'client_name' => self::value($receipt->client_name),
            'client_address' => self::clientAddress($receipt),
            'period' => self::value($receipt->billingPeriod?->label ?? $receipt->period),
            'service_name' => self::value($receipt->utility_service_name),
            'billing_type' => self::billingTypeLabel($receipt->billing_type),
            'receipt_number' => self::value($receipt->receipt_number),
            'issued_date' => self::value($receipt->issued_at?->format('d.m.Y')),
            'generated_at' => $generatedAt->format('d.m.Y H:i'),
            'volume' => self::decimal($receipt->volume),
            'unit' => self::value($organization?->utilityService?->unit_of_measurement),
            'tariff_price' => self::money($receipt->tariff_price),
            'amount' => self::money($receipt->amount),
            'paid_amount' => self::money($receipt->paid_amount),
            'adjustment_amount' => self::money($receipt->adjustment_amount),
            'opening_balance' => self::money($receipt->opening_balance),
            'closing_balance' => self::money($receipt->closing_balance),
            'amount_due' => self::money(max(0, (float) $receipt->closing_balance)),
            'copy_title' => $copyTitle,
        ];
    }

    /**
     * @param  list<array{meter_number: string, previous_reading: string, current_reading: string, consumption: string, tariff_price: string, amount: string}>  $meterReadingLines
     * @return array<string, string>
     */
    public static function fragments(Receipt $receipt, array $meterReadingLines, Carbon $generatedAt): array
    {
        $template = $receipt->organization?->receiptTemplate;

        return [
            'meters_table' => view('receipts.fragments.meters-table', [
                'meterReadingLines' => $meterReadingLines,
                'volume' => self::decimal($receipt->volume),
                'amount' => self::money($receipt->amount),
                'generatedAt' => $generatedAt,
            ])->render(),
            'logo' => self::image(ReceiptTemplateImageStorage::url($template?->logo_path), 'Логотип организации', 'rt-logo'),
            'qr' => self::image(ReceiptTemplateImageStorage::url($template?->qr_path), 'QR-код для оплаты', 'rt-qr'),
        ];
    }

    private static function image(?string $url, string $alt, string $class): string
    {
        if (blank($url)) {
            return '';
        }

        return '<img src="'.e($url).'" alt="'.e($alt).'" class="'.$class.'">';
    }

    private static function clientAddress(Receipt $receipt): string
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

    private static function billingTypeLabel(?string $billingType): string
    {
        return match ($billingType) {
            'fixed' => 'Фиксированная сумма',
            'meter' => 'По счётчику',
            'per_person' => 'На одного человека',
            default => self::value($billingType),
        };
    }

    private static function money(mixed $value): string
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
    private static function decimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return rtrim(rtrim(number_format((float) $value, 4, '.', ' '), '0'), '.');
    }

    private static function value(mixed $value): string
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
