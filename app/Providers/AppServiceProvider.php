<?php

namespace App\Providers;

use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Override the framework's default queue:listen (tries=1, timeout=0)
            // with a queue:work that respects job tries/timeout/backoff and
            // avoids indefinite SQLite write contention.
            DevCommands::artisan(
                'queue:work --queue=default --tries=3 --timeout=130 --backoff=10,30,60 --max-jobs=1000 --memory=512 --sleep=3',
                'queue'
            );
        }
    }
}
