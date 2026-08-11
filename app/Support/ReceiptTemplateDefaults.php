<?php

namespace App\Support;

final class ReceiptTemplateDefaults
{
    public const BLOCK_TYPES = [
        'header',
        'client_details',
        'organization_details',
        'meters_table',
        'totals',
        'footer_note',
    ];

    public const FONT_SIZES = ['compact', 'normal', 'large'];

    public const DENSITIES = ['compact', 'normal', 'large'];

    public const COPIES_PER_PAGE = [1, 2];

    /**
     * @return array{
     *     blocks: list<array{type: string, enabled: bool}>,
     *     texts: array{title: string, footer_note: string, labels: array<string, string>},
     *     appearance: array{copies_per_page: int, font_size: string, density: string, borders: bool, show_logo: bool, show_qr: bool}
     * }
     */
    public static function settings(): array
    {
        return [
            'blocks' => [
                ['type' => 'header', 'enabled' => true],
                ['type' => 'client_details', 'enabled' => true],
                ['type' => 'organization_details', 'enabled' => true],
                ['type' => 'meters_table', 'enabled' => true],
                ['type' => 'totals', 'enabled' => true],
                ['type' => 'footer_note', 'enabled' => false],
            ],
            'texts' => [
                'title' => 'Квитанция на оплату коммунальной услуги',
                'footer_note' => '',
                'labels' => [],
            ],
            'appearance' => [
                'copies_per_page' => 2,
                'font_size' => 'normal',
                'density' => 'normal',
                'borders' => true,
                'show_logo' => true,
                'show_qr' => false,
            ],
        ];
    }

    public static function blockLabel(string $type): string
    {
        return match ($type) {
            'header' => 'Шапка',
            'client_details' => 'Абонент',
            'organization_details' => 'Реквизиты',
            'meters_table' => 'Счётчики',
            'totals' => 'Итоги',
            'footer_note' => 'Примечание',
            default => $type,
        };
    }

    /**
     * Дефолтные подписи полей, которые организация может переопределить.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'account_number' => 'Лицевой счёт',
            'client_name' => 'Абонент',
            'client_address' => 'Адрес',
            'period' => 'Период',
            'service' => 'Услуга',
            'billing_type' => 'Тип расчёта',
            'organization' => 'Организация',
            'bin_iin' => 'БИН / ИИН',
            'phone' => 'Телефон',
            'organization_address' => 'Адрес',
            'bank' => 'Банк',
            'iban' => 'IBAN',
        ];
    }
}
