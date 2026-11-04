<?php

namespace App\Providers;

use App\Enums\AgentIntegrationScope;
use App\Services\AgentIntegrationService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;

class AgentIntegrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Passport::tokensCan([
            'mcp:use' => 'Usar o servidor MCP',
        ]);

        Passport::authorizationView('oauth.authorize');

        View::composer('oauth.authorize', function ($view): void {
            $view->with('integrationCapabilities', AgentIntegrationScope::labels());
        });

        Event::listen(
            AccessTokenCreated::class,
            [AgentIntegrationService::class, 'handleAccessTokenCreated'],
        );
    }
}
