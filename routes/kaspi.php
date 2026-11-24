<?php

use App\Http\Controllers\KaspiController;
use App\Http\Middleware\EnsureKaspiRequest;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Kaspi online
|--------------------------------------------------------------------------
|
| The permanent address of an organization for Kaspi `check` and `pay`. It is
| loaded without the `web` middleware group (bootstrap/app.php).
|
*/

Route::get('/kaspi/{uid}', KaspiController::class)
    ->middleware(EnsureKaspiRequest::class)
    ->name('kaspi.endpoint');
