<?php

use App\Support\ReceiptTemplateRenderer;

test('renderer substitutes scalars with escaping and fragments as html', function () {
    $html = '<p>{{client_name}} / {{ amount }}</p><div>{{meters_table}}</div><span>{{unknown_key}}</span>';

    $rendered = ReceiptTemplateRenderer::render(
        $html,
        ['client_name' => 'Иванов <b>Иван</b>', 'amount' => '1 800.00 KZT'],
        ['meters_table' => '<table><tr><td>MTR-1</td></tr></table>'],
    );

    expect($rendered)->toContain('Иванов &lt;b&gt;Иван&lt;/b&gt;')
        ->and($rendered)->not->toContain('<b>Иван</b>')
        ->and($rendered)->toContain('1 800.00 KZT')
        ->and($rendered)->toContain('<table><tr><td>MTR-1</td></tr></table>')
        ->and($rendered)->toContain('<span></span>');
});
