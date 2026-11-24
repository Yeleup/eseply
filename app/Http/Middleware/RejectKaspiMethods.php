<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Every method but GET on the Kaspi address gets 405 with
 * `Cache-Control: no-store`, before routing.
 *
 * The route answers only the methods the router knows (Router::$verbs); TRACE,
 * CONNECT or any other one would otherwise get the router's own 405 without
 * the header, so the check runs here, in front of the router.
 */
class RejectKaspiMethods
{
    /**
     * @param  Closure(Request): SymfonyResponse  $next
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if ($request->is('kaspi', 'kaspi/*') && ! $request->isMethod('GET')) {
            return new Response('', 405, [
                'Allow' => 'GET',
                'Cache-Control' => 'no-store',
            ]);
        }

        return $next($request);
    }
}
