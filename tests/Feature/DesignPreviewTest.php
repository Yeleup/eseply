<?php

test('the design preview page renders every documented surface', function () {
    $this->get(route('design-preview'))
        ->assertSuccessful()
        ->assertSee('Оборотно-сальдовая ведомость')
        ->assertSee('Объём, м3');
});

test('the payment desk preview shows every required state', function () {
    $response = $this->get(route('design-preview'))->assertSuccessful();

    foreach ([
        'Приём оплат',
        'Поиск абонента',
        'Долг и приём оплаты',
        'Вся сумма',
        'Лента за сегодня',
        'Оплата провайдера, правка недоступна',
        'Сегодня оплат ещё не было',
        'Оплата не принята',
    ] as $marker) {
        $response->assertSee($marker);
    }
});

test('the readings entry preview shows every required state', function () {
    $response = $this->get(route('design-preview'))->assertSuccessful();

    foreach ([
        'Ввод показаний',
        'Плотная таблица',
        'Карточка на телефоне',
        'Ким Ольга — второй счётчик',
        'Только не снятые',
        'Только проблемные',
        'Расход отрицательный',
        'Фото и примечание',
        'Сделать фото',
        'Из галереи',
        'Визит без показания',
        'Не снято',
        'Нет счётчиков по выбранному адресу',
        'Загрузка списка счётчиков',
        'Не удалось сохранить показание',
        'Расчётный месяц не открыт',
        'Показание не может быть больше 99999.',
        'Подтверждение большого расхода',
        'Подтвердите большой расход',
        'Вернуться к вводу',
        'Не сохранено:',
    ] as $marker) {
        $response->assertSee($marker);
    }
});
