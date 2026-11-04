<?php

use App\Providers\AgentIntegrationServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    AgentIntegrationServiceProvider::class,
    AdminPanelProvider::class,
];
