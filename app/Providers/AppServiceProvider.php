<?php

namespace App\Providers;

use App\Kaspi\KaspiSessionLockWait;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(KaspiSessionLockWait::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A connection Laravel opens again after losing one keeps the zero lock
        // wait timeout of a Kaspi deadline (KaspiSessionLockWait).
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            app(KaspiSessionLockWait::class)->applyTo($event->connection);
        });
    }
}
