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

// Every method reaches the route, so the middleware answers each of them
// itself: GET is served, the rest get 405 with `Cache-Control: no-store`.
Route::any('/kaspi/{uid}', KaspiController::class)
    ->middleware(EnsureKaspiRequest::class)
    ->name('kaspi.endpoint');
