<?php

use App\Http\Middleware\RejectKaspiMethods;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            /**
             * The Kaspi address lives outside the `web` group: no session,
             * cookies, CSRF or rate limiting (docs/modules/kaspi-online.md).
             */
            Route::group([], __DIR__.'/../routes/kaspi.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * Trusted proxies come from config/trustedproxy.php (TRUSTED_PROXIES):
         * trusting every address would let anyone fake their IP through
         * X-Forwarded-For, and the IP is what the Kaspi address relies on.
         */
        $isKaspiRequest = fn (Request $request): bool => $request->is('kaspi/*');

        // Before routing: methods the router does not know never reach the route.
        $middleware->prepend(RejectKaspiMethods::class);

        // Kaspi parameters are checked exactly as they arrive.
        $middleware->trimStrings(except: [$isKaspiRequest]);
        $middleware->convertEmptyStringsToNull(except: [$isKaspiRequest]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
