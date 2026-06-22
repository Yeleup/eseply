<?php

return [
    'model_label' => 'квитанция',
    'plural_model_label' => 'квитанции',
    'navigation_label' => 'Квитанции',
    'navigation_group' => 'Учёт',

    'sections' => [
        'receipt' => 'Квитанция',
        'meters' => 'Счётчики',
        'calculation' => 'Расчёт',
    ],

    'fields' => [
        'receipt_number' => 'Номер',
        'period' => 'Период',
        'issued_at' => 'Сформирована',
        'account_number' => 'Лицевой счёт',
        'client_name' => 'Абонент',
        'utility_service_name' => 'Услуга',
        'billing_type' => 'Тип расчёта',
        'volume' => 'Объём',
        'tariff_price' => 'Тариф',
        'opening_balance' => 'Начальное сальдо',
        'amount' => 'Сумма',
        'paid_amount' => 'Оплачено',
        'adjustment_amount' => 'Корректировка',
        'closing_balance' => 'Конечное сальдо',
    ],

    'billing_types' => [
        'fixed' => 'Фиксированная сумма',
        'meter' => 'По счётчику',
        'per_person' => 'На одного человека',
    ],

    'meter_columns' => [
        'meter_number' => '№ счётчика',
        'previous_reading' => 'Предыдущее',
        'current_reading' => 'Текущее',
        'consumption' => 'Расход',
        'tariff_price' => 'Тариф',
        'amount' => 'Сумма',
    ],

    'filters' => [
        'region' => 'Регион',
        'street' => 'Улица',
        'controller' => 'Контроллер',
    ],

    'actions' => [
        'print_filtered' => 'Печатать по фильтру',
        'print' => 'Печать',
        'print_selected' => 'Печатать выбранные',
        'open' => 'Открыть',
    ],
];
