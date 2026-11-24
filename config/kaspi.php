<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kaspi addresses
    |--------------------------------------------------------------------------
    |
    | Kaspi sends `check` and `pay` only from these addresses (page 1 of the
    | protocol). The address is not secret, so this check is the only
    | protection of /kaspi/{uid}: any other client gets result 5 before the
    | organization is even looked up. Comma separated.
    |
    */

    'allowed_ips' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('KASPI_ALLOWED_IPS', '194.187.247.152')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Account format
    |--------------------------------------------------------------------------
    |
    | The regular expression the organization gives Kaspi; Kaspi checks the
    | account against it before calling us, and we check it again.
    |
    */

    'account_pattern' => '^[0-9]{6,10}$',

    /*
    |--------------------------------------------------------------------------
    | Lock wait deadline
    |--------------------------------------------------------------------------
    |
    | Kaspi drops the connection after 15 seconds. All waits for locks in one
    | Kaspi request — database rows and the cache lock of the billing month —
    | share this deadline in seconds; past it `pay` answers 4 for a saved
    | operation and 5 otherwise.
    |
    */

    'lock_wait_seconds' => (int) env('KASPI_LOCK_WAIT_SECONDS', 5),

    /*
    |--------------------------------------------------------------------------
    | Posting of operations in processing
    |--------------------------------------------------------------------------
    |
    | A queued posting job retries with these pauses (seconds); the scheduler
    | picks up every operation still in processing after `pickup_after`
    | seconds, in case a job was lost.
    |
    */

    'posting' => [
        'backoff' => [10, 30, 60, 120, 300],
        'job_lock_wait_seconds' => 30,
        'pickup_after' => 60,
    ],

];
