<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Only a request that reaches the application from one of these addresses
    | may set X-Forwarded-For, -Proto, -Host and -Port. Trusting every address
    | ('*') would let anyone set their IP with X-Forwarded-For, and the IP is
    | the only protection of the Kaspi address (docs/modules/kaspi-online.md).
    |
    | The default covers loopback and the private networks: the web server in
    | front of the application runs on the same server and reaches the
    | container through Docker's bridge network, so its address is private,
    | while clients on the internet never have a private address. If the
    | front proxy connects from a public address, set TRUSTED_PROXIES to that
    | address (comma separated). Never set '*'.
    |
    */

    'proxies' => env(
        'TRUSTED_PROXIES',
        '127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1/128,fc00::/7',
    ),

];
