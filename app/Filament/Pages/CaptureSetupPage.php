<?php

namespace App\Filament\Pages;

use App\Services\CaptureTokenService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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

    public ?string $plainToken = null;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()?->disabled_at === null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateToken')
                ->label('Gerar credencial')
                ->icon('heroicon-o-key')
                ->color('primary')
                ->visible(fn (): bool => ! $this->tokenConfigured())
                ->action(function (): void {
                    $plain = app(CaptureTokenService::class)->issueToken(auth()->user());
                    $this->plainToken = $plain;

                    Notification::make()
                        ->success()
                        ->title('Credencial gerada')
                        ->body('Copie o token agora. Ele só será exibido integralmente uma vez.')
                        ->send();
                }),
            Action::make('regenerateToken')
                ->label('Regenerar credencial')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->tokenConfigured())
                ->requiresConfirmation()
                ->modalHeading('Regenerar credencial')
                ->modalDescription('O token anterior deixará de funcionar imediatamente. Todas as instalações da extensão precisarão ser atualizadas.')
                ->modalSubmitActionLabel('Regenerar')
                ->action(function (): void {
                    $plain = app(CaptureTokenService::class)->issueToken(auth()->user());
                    $this->plainToken = $plain;

                    Notification::make()
                        ->warning()
                        ->title('Credencial regenerada')
                        ->body('O token anterior foi invalidado. Atualize a extensão com o novo token.')
                        ->send();
                }),
            Action::make('revokeToken')
                ->label('Revogar credencial')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => $this->tokenConfigured())
                ->requiresConfirmation()
                ->modalHeading('Revogar credencial')
                ->modalDescription('A extensão deixará de funcionar até que uma nova credencial seja gerada.')
                ->modalSubmitActionLabel('Revogar')
                ->action(function (): void {
                    app(CaptureTokenService::class)->revokeToken(auth()->user());
                    $this->plainToken = null;

                    Notification::make()
                        ->success()
                        ->title('Credencial revogada')
                        ->body('A credencial foi removida. Gere uma nova para voltar a usar a extensão.')
                        ->send();
                }),
        ];
    }

    public function tokenConfigured(): bool
    {
        $user = auth()->user();

        return $user !== null && app(CaptureTokenService::class)->tokenConfigured($user);
    }

    public function maskedToken(): string
    {
        $user = auth()->user();

        return $user ? app(CaptureTokenService::class)->maskedToken($user) : '';
    }

    public function fullToken(): string
    {
        return (string) ($this->plainToken ?? '');
    }

    public function hasPlainToken(): bool
    {
        return $this->plainToken !== null && $this->plainToken !== '';
    }
}
