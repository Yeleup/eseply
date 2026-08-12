<?php

use App\Support\ReceiptTemplateHtmlSanitizer;

test('sanitizer keeps allowed markup and placeholders', function () {
    $html = '<div class="a" style="color:red"><h2>Счёт</h2><p>Абонент: {{client_name}}</p>'
        .'<table><tbody><tr><td colspan="2">{{amount}}</td></tr></tbody></table>'
        .'<img src="/storage/receipt-templates/1/logo.png" alt="Логотип" width="40" height="40"><hr></div>';

    $clean = ReceiptTemplateHtmlSanitizer::sanitizeHtml($html);

    expect($clean)->toContain('{{client_name}}')
        ->and($clean)->toContain('{{amount}}')
        ->and($clean)->toContain('class="a"')
        ->and($clean)->toContain('style="color:red"')
        ->and($clean)->toContain('colspan="2"')
        ->and($clean)->toContain('/storage/receipt-templates/1/logo.png')
        ->and($clean)->toContain('<hr');
});

test('sanitizer strips scripts event handlers and dangerous tags', function () {
    $html = '<p onclick="alert(1)">x</p><script>alert(1)</script>'
        .'<iframe src="https://evil.example"></iframe><form><input></form>'
        .'<a href="javascript:alert(1)">link</a><object data="x"></object>';

    $clean = ReceiptTemplateHtmlSanitizer::sanitizeHtml($html);

    expect($clean)->not->toContain('script')
        ->and($clean)->not->toContain('onclick')
        ->and($clean)->not->toContain('iframe')
        ->and($clean)->not->toContain('javascript:')
        ->and($clean)->not->toContain('<form')
        ->and($clean)->not->toContain('<input')
        ->and($clean)->not->toContain('<object')
        ->and($clean)->toContain('x');
});

test('sanitizer removes external and data image sources but keeps storage paths', function () {
    $html = '<img src="https://evil.example/a.png"><img src="data:image/png;base64,AAAA">'
        .'<img src="/storage/receipt-templates/5/qr.png"><img src="/etc/passwd">';

    $clean = ReceiptTemplateHtmlSanitizer::sanitizeHtml($html);

    expect($clean)->not->toContain('evil.example')
        ->and($clean)->not->toContain('data:image')
        ->and($clean)->not->toContain('/etc/passwd')
        ->and($clean)->toContain('/storage/receipt-templates/5/qr.png');
});

test('sanitizer does not truncate large templates under the limit', function () {
    $row = '<tr><td>строка</td><td>{{amount}}</td></tr>';
    $html = '<table><tbody>'.str_repeat($row, 700).'</tbody></table>';

    expect(strlen($html))->toBeGreaterThan(20000);

    $clean = ReceiptTemplateHtmlSanitizer::sanitizeHtml($html);

    expect(substr_count($clean, '{{amount}}'))->toBe(700);
});

test('css filter strips imports urls and expressions but keeps rules', function () {
    $css = "@import url('https://evil.example/x.css');\n"
        .".rt-header { color: #111; border-bottom: 1px solid #000; }\n"
        .".bad { background: url(https://evil.example/b.png); behavior: url(x.htc); }\n"
        .".worse { width: expression(alert(1)); content: 'javascript:alert(1)'; }";

    $clean = ReceiptTemplateHtmlSanitizer::sanitizeCss($css);

    expect($clean)->toContain('.rt-header')
        ->and($clean)->toContain('border-bottom: 1px solid #000')
        ->and($clean)->not->toContain('@import')
        ->and($clean)->not->toContain('url(')
        ->and($clean)->not->toContain('expression(')
        ->and($clean)->not->toContain('behavior:')
        ->and($clean)->not->toContain('javascript:');
});

test('sanitizer filters dangerous css inside style attributes', function () {
    $html = '<div style="color:red;background:url(https://evil.example/x.png)">a</div>'
        .'<p style="width:expression(alert(1));behavior:url(x.htc)">b</p>';

    $clean = ReceiptTemplateHtmlSanitizer::sanitizeHtml($html);

    expect($clean)->toContain('color:red')
        ->and($clean)->not->toContain('evil.example')
        ->and($clean)->not->toContain('expression(')
        ->and($clean)->not->toContain('behavior:');
});

test('sanitizer blocks url encoded traversal in image sources', function () {
    $clean = ReceiptTemplateHtmlSanitizer::sanitizeHtml('<img src="/storage/%2e%2e/%2e%2e/etc/passwd">');

    expect($clean)->not->toContain('%2e')
        ->and($clean)->not->toContain('/etc/passwd');
});

test('sanitizer blocks double encoded traversal in image sources', function () {
    $clean = ReceiptTemplateHtmlSanitizer::sanitizeHtml('<img src="/storage/%252e%252e/%252e%252e/etc/passwd">');
    expect($clean)->not->toContain('%252e')
        ->and($clean)->not->toContain('/etc/passwd');
});

test('css filter neutralizes hex escape obfuscation', function () {
    $css = '.a { background: \75rl(https://evil.example/x.png); }';
    $clean = ReceiptTemplateHtmlSanitizer::sanitizeCss($css);
    expect($clean)->not->toContain('evil.example');
});

test('css filter keeps legitimate rules intact', function () {
    $css = ".rt-header { color: #111; border-bottom: 1px solid #000; } .x::before { content: 'Итого'; }";
    $clean = ReceiptTemplateHtmlSanitizer::sanitizeCss($css);
    expect($clean)->toContain('#111')
        ->and($clean)->toContain('1px solid #000')
        ->and($clean)->toContain('Итого');
});

test('css filter neutralizes style tag breakout', function () {
    $css = 'body { color: red; } </style><script>alert(document.cookie)</script>';
    $clean = ReceiptTemplateHtmlSanitizer::sanitizeCss($css);

    expect($clean)->not->toContain('</style>')
        ->and($clean)->not->toContain('<script>')
        ->and($clean)->not->toContain('<')
        ->and($clean)->not->toContain('>')
        ->and($clean)->toContain('color: red');
});
