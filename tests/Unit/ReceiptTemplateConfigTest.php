<?php

use App\Support\ReceiptTemplateConfig;
use App\Support\ReceiptTemplateDefaults;

test('default config renders all blocks except footer note', function () {
    $config = ReceiptTemplateConfig::default();

    expect($config->enabledBlockTypes())->toBe([
        'header',
        'client_details',
        'organization_details',
        'meters_table',
        'totals',
    ])
        ->and($config->title())->toBe('Квитанция на оплату коммунальной услуги')
        ->and($config->footerNote())->toBe('')
        ->and($config->label('account_number'))->toBe('Лицевой счёт')
        ->and($config->copiesPerPage())->toBe(2)
        ->and($config->copyTitles())->toBe(['Для организации', 'Для абонента'])
        ->and($config->copyCssClasses())->toBe('')
        ->and($config->showLogo())->toBeTrue()
        ->and($config->showQr())->toBeFalse()
        ->and($config->logoUrl())->toBeNull()
        ->and($config->settings())->toBe(ReceiptTemplateDefaults::settings());
});

test('saved settings control block order and visibility', function () {
    $config = ReceiptTemplateConfig::fromSettings([
        'blocks' => [
            ['type' => 'header', 'enabled' => true],
            ['type' => 'organization_details', 'enabled' => true],
            ['type' => 'client_details', 'enabled' => true],
            ['type' => 'meters_table', 'enabled' => false],
            ['type' => 'totals', 'enabled' => true],
            ['type' => 'footer_note', 'enabled' => true],
        ],
        'texts' => [
            'title' => 'Счёт за воду',
            'footer_note' => 'Оплатите до 25 числа',
            'labels' => ['account_number' => 'Абонентский номер'],
        ],
        'appearance' => [
            'copies_per_page' => 1,
            'font_size' => 'large',
            'density' => 'compact',
            'borders' => false,
            'show_logo' => false,
            'show_qr' => true,
        ],
    ], logoUrl: '/storage/receipt-templates/1/logo.png', qrUrl: '/storage/receipt-templates/1/qr.png');

    expect($config->enabledBlockTypes())->toBe([
        'header',
        'organization_details',
        'client_details',
        'totals',
        'footer_note',
    ])
        ->and($config->title())->toBe('Счёт за воду')
        ->and($config->footerNote())->toBe('Оплатите до 25 числа')
        ->and($config->label('account_number'))->toBe('Абонентский номер')
        ->and($config->label('client_name'))->toBe('Абонент')
        ->and($config->copiesPerPage())->toBe(1)
        ->and($config->copyTitles())->toBe(['Для абонента'])
        ->and($config->copyCssClasses())->toBe('receipt-font-large receipt-density-compact receipt-no-borders')
        ->and($config->showLogo())->toBeFalse()
        ->and($config->showQr())->toBeTrue()
        ->and($config->qrUrl())->toBe('/storage/receipt-templates/1/qr.png');
});

test('settings merge drops unknown values and fills missing keys from defaults', function () {
    $config = ReceiptTemplateConfig::fromSettings([
        'blocks' => [
            ['type' => 'header', 'enabled' => false],
            ['type' => 'banner', 'enabled' => true],
            ['type' => 'meters_table', 'enabled' => true],
            ['type' => 'meters_table', 'enabled' => false],
        ],
        'texts' => [
            'title' => '',
            'labels' => ['account_number' => '', 'unknown_key' => 'X'],
        ],
        'appearance' => [
            'copies_per_page' => 5,
            'font_size' => 'huge',
        ],
        'unknown_section' => ['x' => 1],
    ]);

    $settings = $config->settings();

    // header принудительно включён, banner отброшен, дубль meters_table отброшен,
    // отсутствующие блоки добавлены в конец с дефолтным enabled
    expect(array_column($settings['blocks'], 'type'))->toBe([
        'header',
        'meters_table',
        'client_details',
        'organization_details',
        'totals',
        'footer_note',
    ])
        ->and($settings['blocks'][0]['enabled'])->toBeTrue()
        ->and($settings['blocks'][5]['enabled'])->toBeFalse()
        ->and($config->title())->toBe('Квитанция на оплату коммунальной услуги')
        ->and($config->label('account_number'))->toBe('Лицевой счёт')
        ->and($settings['texts']['labels'])->toBe([])
        ->and($config->copiesPerPage())->toBe(2)
        ->and($settings['appearance']['font_size'])->toBe('normal')
        ->and($settings)->not->toHaveKey('unknown_section');
});
