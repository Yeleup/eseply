<?php

test('the design preview page renders every documented surface', function () {
    $this->get(route('design-preview'))
        ->assertSuccessful()
        ->assertSee('Оборотно-сальдовая ведомость')
        ->assertSee('Объём, м3');
});
