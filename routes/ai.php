<?php

use App\Http\Middleware\AuthenticateAgentIntegration;
use App\Http\Middleware\ProtectOAuthConsentFrames;
use App\Http\Middleware\ValidateDynamicClientRegistration;
use App\Mcp\Servers\JoboraServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

foreach (Route::getRoutes() as $route) {
    if ($route->uri() === 'oauth/register' && in_array('POST', $route->methods(), true)) {
        $route->middleware([ValidateDynamicClientRegistration::class, 'throttle:10,1']);
    }

}

app()->booted(function (): void {
    foreach (Route::getRoutes() as $route) {
        if (in_array($route->uri(), ['oauth/authorize', 'oauth/device/authorize'], true)) {
            $route->middleware(ProtectOAuthConsentFrames::class);
        }
    }
});

Mcp::local('jobora', JoboraServer::class);

Mcp::web('/mcp', JoboraServer::class)
    ->middleware(['auth:api', AuthenticateAgentIntegration::class]);
