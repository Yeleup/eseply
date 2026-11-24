<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Kaspi payments still in processing are posted without waiting for Kaspi to
 * repeat the request, even when their queued job was lost.
 */
Schedule::command('kaspi:post-pending')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * The request log of Kaspi keeps 30 days. Old rows go in portions on a
 * schedule of their own, so a backlog never holds up the minute pickup.
 */
Schedule::command('kaspi:prune-request-log')
    ->hourly()
    ->withoutOverlapping();
