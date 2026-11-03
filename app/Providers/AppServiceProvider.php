<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Triage\ClefJobPrefilter;
use App\Services\Triage\JobPrefilter;
use App\Services\Triage\PrefilterPolicy;
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
        $this->app->bind(JobPrefilter::class, function (): ClefJobPrefilter {
            $config = (array) config('triage.prefilter', []);

            return new ClefJobPrefilter(
                accountId: (string) ($config['account_id'] ?? ''),
                apiToken: (string) ($config['api_token'] ?? ''),
                model: (string) ($config['model'] ?? '@cf/cloudflare/clef-flash'),
                baseUrl: (string) ($config['base_url'] ?? 'https://api.cloudflare.com/client/v4'),
                timeout: (int) ($config['timeout'] ?? 15),
                concurrency: (int) ($config['concurrency'] ?? 5),
            );
        });

        $this->app->bind(PrefilterPolicy::class, fn (): PrefilterPolicy => new PrefilterPolicy(
            minJobProbability: (float) config('triage.prefilter.min_job_probability', 0.50),
            nonJobKindLabels: (array) config('triage.non_job_kinds', []),
        ));
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
