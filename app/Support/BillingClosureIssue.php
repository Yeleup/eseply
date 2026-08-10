<?php

namespace App\Support;

final readonly class BillingClosureIssue
{
    /**
     * Stable error codes of the month closing with a short reason that does not
     * depend on a single subscriber, so the codes can be grouped in a report.
     *
     * @var array<string, string>
     */
    public const array CODE_LABELS = [
        'missing_organization_utility_service' => 'Не задана услуга организации.',
        'unsupported_billing_type' => 'Не выбран поддерживаемый тип начисления.',
        'missing_fixed_amount' => 'Не указана фиксированная сумма.',
        'missing_residents_count' => 'Не указано количество проживающих.',
        'missing_per_person_price' => 'Не указана цена тарифа на одного человека.',
        'missing_active_meters' => 'Не найдены активные счётчики по услуге организации.',
        'missing_meter_reading' => 'Нет показания счётчика за период.',
        'negative_meter_consumption' => 'Отрицательный расход по счётчику.',
        'missing_unit_price' => 'Не указана цена за единицу услуги.',
        'missing_tariff' => 'Не найден активный тариф на начало периода.',
    ];

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $context = [],
    ) {}

    public static function labelFor(?string $code): string
    {
        if ($code === null || $code === '') {
            return '-';
        }

        return self::CODE_LABELS[$code] ?? $code;
    }
}
