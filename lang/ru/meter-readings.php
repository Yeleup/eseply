<?php

return [
    'validation' => [
        'current_reading' => [
            'max' => 'Показание не может быть больше :max.',
        ],
    ],
    'confirmation' => [
        'large_consumption' => [
            'heading' => 'Подтвердите большой расход',
            'description' => 'Введённое показание: :current. Расход: :consumption. Средний расход за последние 3 расчётных месяца: :average. Сохранить показание?',
            'submit' => 'Подтвердить и сохранить',
            'cancel' => 'Вернуться к вводу',
        ],
    ],
];
