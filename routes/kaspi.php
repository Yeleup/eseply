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

// Only GET gets this far: RejectKaspiMethods answers every other method with
// 405 and `Cache-Control: no-store` before routing; EnsureKaspiRequest keeps
// the same answer in case the route is ever reached another way.
Route::any('/kaspi/{uid}', KaspiController::class)
    ->middleware(EnsureKaspiRequest::class)
    ->name('kaspi.endpoint');
