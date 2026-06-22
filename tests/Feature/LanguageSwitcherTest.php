<?php

use App\Filament\Resources\Receipts\ReceiptResource;
use CraftForge\FilamentLanguageSwitcher\FilamentLanguageSwitcherPlugin;
use CraftForge\FilamentLanguageSwitcher\Http\Middleware\SetLocale;
use Filament\Facades\Filament;
use Illuminate\Session\Middleware\StartSession;

test('admin panel registers the language switcher plugin', function () {
    $plugin = Filament::getPanel('admin')->getPlugin('filament-language-switcher');
    $locales = (function (): array {
        return $this->getLocales();
    })->call($plugin);

    expect($plugin)->toBeInstanceOf(FilamentLanguageSwitcherPlugin::class)
        ->and(array_column($locales, 'code'))->toBe(['ru', 'kk']);
});

test('language switcher middleware runs after the session starts', function () {
    $middleware = Filament::getPanel('admin')->getMiddleware();

    expect(array_search(StartSession::class, $middleware, true))
        ->toBeLessThan(array_search(SetLocale::class, $middleware, true));
});

test('receipt resource labels are translated for supported locales', function (string $locale, string $label) {
    app()->setLocale($locale);

    expect(ReceiptResource::getNavigationLabel())->toBe($label);
})->with([
    'russian' => ['ru', 'Квитанции'],
    'kazakh' => ['kk', 'Түбіртектер'],
]);

test('receipt translations include kazakh billing types', function () {
    app()->setLocale('kk');

    expect(__('filament-receipts.billing_types.meter'))->toBe('Есептегіш бойынша')
        ->and(__('filament-receipts.actions.print_selected'))->toBe('Таңдалғандарды басып шығару');
});
