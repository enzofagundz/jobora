<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class CaptureSetupPage extends Page
{
    protected string $view = 'filament.pages.capture-setup';

    protected static ?string $slug = 'capture-setup';

    protected static ?string $navigationLabel = 'Capturar vagas';

    protected static ?string $title = 'Capturar vagas';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Vagas';

    protected static ?int $navigationSort = 2;

    public function tokenConfigured(): bool
    {
        return config('capture.api_token') !== '';
    }

    /** Token mascarado para exibição; nunca o valor integral por padrão. */
    public function maskedToken(): string
    {
        $token = (string) config('capture.api_token');

        if ($token === '') {
            return '';
        }

        return str_repeat('•', max(4, strlen($token) - 4)).substr($token, -4);
    }

    public function fullToken(): string
    {
        return (string) config('capture.api_token');
    }
}
