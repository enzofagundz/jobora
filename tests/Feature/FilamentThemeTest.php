<?php

use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Filament\Panel;

it('registers the admin custom theme', function (): void {
    $panel = Filament::getPanel('admin');

    if (! $panel) {
        $panel = app(AdminPanelProvider::class)->panel(Panel::make());
    }

    // Fallback for providers that require explicit app instance
    if (! $panel->getViteTheme()) {
        try {
            $fallback = (new AdminPanelProvider(app()))->panel(Panel::make());
            if ($fallback->getViteTheme()) {
                $panel = $fallback;
            }
        } catch (Throwable) {
            // keep original panel
        }
    }

    expect($panel->getViteTheme())
        ->toBe('resources/css/filament/admin/theme.css');
});
