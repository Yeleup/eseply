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
    | seconds, in case a job was lost: `batch_size` operations at a time, at
    | most `pickup_per_organization` operations and `pickup_organization_seconds`
    | of one organization, at most `pickup_seconds` for the whole round.
    |
    */

    'posting' => [
        'backoff' => [10, 30, 60, 120, 300],
        'job_lock_wait_seconds' => (int) env('KASPI_POSTING_LOCK_WAIT_SECONDS', 30),
        'pickup_after' => 60,
        'batch_size' => 100,
        'pickup_per_organization' => 50,
        'pickup_organization_seconds' => (float) env('KASPI_PICKUP_ORGANIZATION_SECONDS', 10),
        'pickup_seconds' => (float) env('KASPI_PICKUP_SECONDS', 40),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request log
    |--------------------------------------------------------------------------
    |
    | Every request from Kaspi is kept `keep_days` days, the latest one of an
    | organization always. Older rows are deleted every hour in portions of
    | `prune_batch_size` rows, at most `prune_max_batches` portions a run; the
    | rest waits for the next run.
    |
    */

    'request_log' => [
        'keep_days' => 30,
        'prune_batch_size' => 1000,
        'prune_max_batches' => 200,
    ],

];
