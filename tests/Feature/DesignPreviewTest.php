<?php

test('the design preview page renders every documented surface', function () {
    $this->get(route('design-preview'))
        ->assertSuccessful()
        ->assertSee('Оборотно-сальдовая ведомость')
        ->assertSee('Объём, м3');
});

test('the meter reading sheet preview shows the previous and current reading columns', function () {
    $this->get(route('design-preview'))
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Ведомость снятия показаний',
            'Предыдущее и текущее показание за расчётный месяц',
            'Предыдущее показание',
            'Текущее показание',
        ]);
});

test('the payment desk preview shows every required state', function () {
    $response = $this->get(route('design-preview'))->assertSuccessful();

    foreach ([
        'Приём оплат',
        'Поиск абонента',
        'Оплатить',
        'Введите минимум 2 символа',
        'Модалка приёма оплаты',
        'Карточка абонента',
        'Последняя оплата',
        'Корректировки',
        'Вся сумма',
        'Принять оплату',
        'Лента за сегодня',
        'Оплата провайдера, правка недоступна',
        'Сегодня оплат ещё не было',
        'Оплата не принята',
    ] as $marker) {
        $response->assertSee($marker);
    }
});

test('the payment desk preview shows the keyboard states', function () {
    $response = $this->get(route('design-preview'))->assertSuccessful();

    $response
        ->assertSeeInOrder([
            'Горячие клавиши',
            'В поиске',
            'следующий, предыдущий абонент',
            'В окне приёма',
            'пустая сумма — подставить весь долг, иначе принять оплату',
        ])
        ->assertSeeInOrder(['выбрать', 'оплатить', 'стереть выделенный счёт', 'очистить', 'все клавиши'])
        ->assertSeeInOrder(['100001 — Иванов Иван', 'Оплатить', 'Enter'])
        ->assertSee('Enter</kbd> не принимает оплату: расчётный месяц не открыт', false)
        ->assertSee('100042 — долга нет, приём оплаты не требуется.')
        ->assertSee('выделен целиком:')
        ->assertSee('стирает всё одним нажатием, или просто набирайте следующий счёт поверх.')
        ->assertSee('Вся сумма <kbd', false)
        ->assertSee('принять из примечания')
        ->assertSee('Принять оплату <kbd', false);
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

test('the client form preview shows the read only client controllers next to the address', function () {
    $this->get(route('design-preview'))
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Данные абонента',
            'Улица *',
            'Контроллеры',
            'Айгуль Сейтова, Ержан Касымов',
            'Дом',
        ]);
});
