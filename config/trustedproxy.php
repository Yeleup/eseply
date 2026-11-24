<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| X-Forwarded-For, -Proto, -Host and -Port are believed only from a request
| that reaches the application from a trusted proxy. Trusting every address
| ('*') would let anyone set their IP with X-Forwarded-For.
|
| TRUSTED_PROXIES — the exact addresses of the web server in front of the
| application, as the application sees them (comma separated). Until it is
| set, the site keeps working behind a web server on the same server through
| the loopback and private networks, but Kaspi online stays closed: the
| Kaspi address believes X-Forwarded-For only from these exact addresses
| (docs/modules/kaspi-online.md, «Доверенные прокси»).
|
*/

$configured = array_values(array_filter(array_map(
    trim(...),
    explode(',', (string) env('TRUSTED_PROXIES', '')),
), fn (string $proxy): bool => $proxy !== ''));

return [

    'proxies' => $configured !== []
        ? $configured
        : ['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7'],

    /*
     * The proxies set explicitly, the only ones the Kaspi address relies on.
     */
    'configured' => $configured,

];
