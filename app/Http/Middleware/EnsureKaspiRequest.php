<?php

namespace App\Http\Middleware;

use App\Kaspi\KaspiInput;
use App\Kaspi\KaspiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The Kaspi address takes GET from Kaspi's addresses only.
 *
 * The address is not secret, so the IP is its only protection. `request()->ip()`
 * honours X-Forwarded-For only from the trusted proxies of
 * config/trustedproxy.php, so the header cannot fake an address from outside.
 * Any other client gets result 5 before the organization is looked up.
 */
class EnsureKaspiRequest
{
    /**
     * @param  Closure(Request): SymfonyResponse  $next
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        // Route::get() also answers HEAD; Kaspi sends GET only.
        if (! $request->isMethod('GET')) {
            return new Response('', 405, [
                'Allow' => 'GET',
                'Cache-Control' => 'no-store',
            ]);
        }

        if (! in_array($request->ip(), (array) config('kaspi.allowed_ips'), true)) {
            return KaspiResponse::http(KaspiResponse::error(KaspiInput::responseTxnIdOf($request)));
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
