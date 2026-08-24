<?php

test('the design preview page renders every documented surface', function () {
    $this->get(route('design-preview'))
        ->assertSuccessful()
        ->assertSee('Оборотно-сальдовая ведомость')
        ->assertSee('Объём, м3');
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
        'Нет счётчиков по выбранному адресу',
        'Загрузка списка счётчиков',
        'Не удалось сохранить показание',
        'Расчётный месяц не открыт',
    ] as $marker) {
        $response->assertSee($marker);
    }
});
