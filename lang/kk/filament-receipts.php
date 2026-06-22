<?php

return [
    'model_label' => 'түбіртек',
    'plural_model_label' => 'түбіртектер',
    'navigation_label' => 'Түбіртектер',
    'navigation_group' => 'Есеп',

    'sections' => [
        'receipt' => 'Түбіртек',
        'meters' => 'Есептегіштер',
        'calculation' => 'Есептеу',
    ],

    'fields' => [
        'receipt_number' => 'Нөмір',
        'period' => 'Кезең',
        'issued_at' => 'Қалыптастырылды',
        'account_number' => 'Дербес шот',
        'client_name' => 'Абонент',
        'utility_service_name' => 'Қызмет',
        'billing_type' => 'Есептеу түрі',
        'volume' => 'Көлем',
        'tariff_price' => 'Тариф',
        'opening_balance' => 'Бастапқы сальдо',
        'amount' => 'Сома',
        'paid_amount' => 'Төленді',
        'adjustment_amount' => 'Түзету',
        'closing_balance' => 'Соңғы сальдо',
    ],

    'billing_types' => [
        'fixed' => 'Тұрақты сома',
        'meter' => 'Есептегіш бойынша',
        'per_person' => 'Бір адамға',
    ],

    'meter_columns' => [
        'meter_number' => 'Есептегіш №',
        'previous_reading' => 'Алдыңғы',
        'current_reading' => 'Ағымдағы',
        'consumption' => 'Шығын',
        'tariff_price' => 'Тариф',
        'amount' => 'Сома',
    ],

    'filters' => [
        'region' => 'Аймақ',
        'street' => 'Көше',
        'controller' => 'Контроллер',
    ],

    'actions' => [
        'print_filtered' => 'Фильтр бойынша басып шығару',
        'print' => 'Басып шығару',
        'print_selected' => 'Таңдалғандарды басып шығару',
        'open' => 'Ашу',
    ],
];
