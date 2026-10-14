<?php

namespace App\Providers\Filament;

use App\Enums\ResumeLanguage;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Models\JobPosting;
use App\Services\ResumePdfService;
use App\Support\Staleness;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->brandName('Jobora')
            ->login()
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->emailChangeVerification()
            ->profile()
            ->strictAuthorization()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->navigationGroups([
                NavigationGroup::make()->label('Vagas'),
                NavigationGroup::make()->label('Perfil'),
                NavigationGroup::make()->label('Mercado'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            // Download do PDF da versão adaptada (F6) — rota GET dedicada:
            // respostas de arquivo não podem ser retornadas de ações Livewire
            // (o valor retornado é serializado no snapshot JSON e bytes
            // binários quebram o encode). Falhas de geração (ex.: credenciais
            // Cloudflare ausentes) viram notificação + redirect, não 500.
            ->authenticatedRoutes(function (): void {
                Route::get('/job-postings/{posting}/resume-pdf', function (JobPosting $posting) {
                    Gate::authorize('view', $posting);

                    $language = ResumeLanguage::tryFrom((string) request()->query('language', ResumeLanguage::PtBr->value))
                        ?? ResumeLanguage::PtBr;

                    $adaptation = $posting->resumeAdaptation($language)->firstOrFail();

                    if ($adaptation->is_ats_optimized) {
                        Gate::authorize('useAtsOptimization');

                        if (! request()->boolean('ats_consent')) {
                            Notification::make()
                                ->danger()
                                ->title('Confirme o download otimizado')
                                ->body('Marque o consentimento de risco antes de baixar o PDF otimizado.')
                                ->send();

                            return redirect()->to(JobPostingResource::getUrl('application', ['record' => $posting]));
                        }

                        if (
                            blank($adaptation->ats_prompt)
                            || Staleness::isAtsPromptStale($adaptation)
                            || Staleness::isAdaptationStale($adaptation)
                        ) {
                            Notification::make()
                                ->danger()
                                ->title('Otimização ATS desatualizada')
                                ->body('Atualize a otimização ATS antes de baixar o PDF otimizado.')
                                ->send();

                            return redirect()->to(JobPostingResource::getUrl('application', ['record' => $posting]));
                        }
                    }

                    try {
                        // toResponse gera o conteúdo (chamada real ao driver);
                        // precisa estar dentro do try para falhas virarem redirect.
                        return app(ResumePdfService::class)->download($adaptation)->toResponse(request());
                    } catch (\Throwable $e) {
                        Log::channel('ai')->error('PDF generation failed.', [
                            'job_posting_id' => $posting->id,
                            'error' => $e->getMessage(),
                        ]);

                        Notification::make()
                            ->danger()
                            ->title('Falha ao gerar o PDF')
                            ->body($e->getMessage())
                            ->send();

                        return redirect()->to(JobPostingResource::getUrl('application', ['record' => $posting]));
                    }
                })->name('resume-pdf.download');
            });
    }
}
