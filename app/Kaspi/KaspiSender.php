<?php

namespace App\Kaspi;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Whether a request really comes from Kaspi.
 *
 * Kaspi calls the HTTPS address, so its request always reaches the
 * application through the web server in front of it. It is accepted only
 * when the connection comes from one of the exact web server addresses of
 * TRUSTED_PROXIES and the client address the web server passed on is
 * Kaspi's. A direct connection to the application, or one from any other
 * address — a private one included — is never believed, whatever headers it
 * sends. Until TRUSTED_PROXIES holds exact addresses, nothing is accepted.
 */
final class KaspiSender
{
    /**
     * The exact web server addresses, or an empty list when they are not set
     * or are not single addresses (a range, '*').
     *
     * @return list<string>
     */
    public static function proxies(): array
    {
        $proxies = (array) config('trustedproxy.configured', []);

        foreach ($proxies as $proxy) {
            if (! is_string($proxy) || filter_var($proxy, FILTER_VALIDATE_IP) === false) {
                return [];
            }
        }

        return array_values($proxies);
    }

    public static function isConfigured(): bool
    {
        return self::proxies() !== [];
    }

    /**
     * @return 'accepted'|'not_configured'|'not_through_proxy'|'not_kaspi'
     */
    public static function check(Request $request): string
    {
        $proxies = self::proxies();

        if ($proxies === []) {
            return 'not_configured';
        }

        if (! IpUtils::checkIp((string) $request->server->get('REMOTE_ADDR'), $proxies)) {
            return 'not_through_proxy';
        }

        // Trusted proxies are exactly these addresses, so request()->ip() is
        // the address the web server passed on, not one the client wrote.
        if (! IpUtils::checkIp((string) $request->ip(), (array) config('kaspi.allowed_ips'))) {
            return 'not_kaspi';
        }

        return 'accepted';
    }
}
