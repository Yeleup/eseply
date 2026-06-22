<?php

use App\Http\Controllers\XPaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/webhooks/xpayment', XPaymentWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhooks.xpayment');

if (app()->environment(['local', 'testing'])) {
    Route::view('/design-preview', 'design-preview')->name('design-preview');
}
