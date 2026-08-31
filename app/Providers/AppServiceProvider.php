<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Gate;
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
        Gate::define('admin', fn (User $user): bool => (bool) $user->is_admin);
        Gate::define('useAtsOptimization', fn (User $user): bool => (bool) ($user->is_admin || $user->can_use_ats_optimization));

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
