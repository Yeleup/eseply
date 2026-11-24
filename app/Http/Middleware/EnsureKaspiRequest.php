<?php

namespace App\Http\Middleware;

use App\Kaspi\KaspiInput;
use App\Kaspi\KaspiResponse;
use App\Kaspi\KaspiSender;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The Kaspi address takes GET from Kaspi only (`KaspiSender`).
 *
 * The address is not secret, so this is its only protection. Every other
 * method — HEAD, OPTIONS, POST and the rest — gets 405; any other sender gets
 * result 5 before the organization is looked up.
 */
class EnsureKaspiRequest
{
    /**
     * @param  Closure(Request): SymfonyResponse  $next
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        // Other methods never get here: RejectKaspiMethods answers them before routing.
        if (! $request->isMethod('GET')) {
            return new Response('', 405, [
                'Allow' => 'GET',
                'Cache-Control' => 'no-store',
            ]);
        }

        $sender = KaspiSender::check($request);

        if ($sender !== 'accepted') {
            // The source address helps to set TRUSTED_PROXIES: a request sent
            // through the web server shows its address here.
            Log::info('Kaspi: запрос не принят', [
                'reason' => $sender,
                'remote_addr' => $request->server->get('REMOTE_ADDR'),
            ]);

            return KaspiResponse::http(KaspiResponse::error(KaspiInput::responseTxnIdOf($request)));
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
