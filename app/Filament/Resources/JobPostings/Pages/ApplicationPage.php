<?php

namespace App\Filament\Resources\JobPostings\Pages;

use App\Enums\ApplicationMethodType;
use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Filament\Resources\JobPostings\Schemas\ApplicationMethodForm;
use App\Models\ApplicationMethod;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Services\JobReviewService;
use App\Services\ResumeAdaptationService;
use App\Services\ResumeService;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

/**
 * Página de Candidatura do JobPosting — substitui a antiga
 * ResumeAdaptationPage. Concentra currículo adaptado e formas de
 * candidatura, sem criar entidade `Application` própria. Não vira item
 * de navegação global: é acessada pela ação `application` da tabela.
 */
class ApplicationPage extends Page
{
    use InteractsWithRecord;

    protected static string $resource = JobPostingResource::class;

    protected static ?string $title = 'Candidatura';

    protected string $view = 'filament.resources.job-postings.pages.application';

    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        Gate::authorize('view', $this->record);

        if ($this->record->status !== JobPostingStatus::Confirmed) {
            $this->redirect(ListJobPostings::getUrl());
        }

        $this->form->fill([
            'original_resume' => ResumeService::plainText(app(ResumeService::class)->content()),
        ]);

        $this->fillAdaptedForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('original_resume')
                    ->label('Currículo original')
                    ->hiddenLabel()
                    ->disabled()
                    ->rows(24)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function adaptedForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('adapted_resume')
                    ->label('Currículo adaptado')
                    ->hiddenLabel()
                    ->disabled()
                    ->rows(24)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function getSubheading(): ?string
    {
        return 'Compare o currículo adaptado e gerencie as formas de candidatura desta vaga.';
    }

    private function fillAdaptedForm(): void
    {
        // fillPartially preserva data.original_resume (fill() substituiria o array data inteiro).
        $this->adaptedForm->fillPartially(
            [
                'adapted_resume' => $this->adaptation()?->adapted_content ?? '',
            ],
            ['adapted_resume'],
        );
    }

    public function originalResume(): ?string
    {
        return app(ResumeService::class)->content();
    }

    public function adaptation(): ?ResumeAdaptation
    {
        return $this->record->resumeAdaptation;
    }

    public function applicationMethods(): Collection
    {
        return $this->record->applicationMethods()->latest()->get();
    }

    /**
     * Valida e devolve uma URL absoluta http(s); caso contrário null.
     * Nunca use valores crus como `href`.
     */
    public static function safeHttpUrl(?string $url): ?string
    {
        if ($url === null || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)
            ? $url
            : null;
    }

    public static function methodTypeLabel(string $typeValue): string
    {
        $type = ApplicationMethodType::tryFrom($typeValue);

        return $type?->getLabel() ?? $typeValue;
    }

    public static function methodActionLabel(string $typeValue): string
    {
        return match ($typeValue) {
            'linkedin_apply' => 'Candidatar-se pelo LinkedIn',
            'indeed_apply' => 'Candidatar-se pelo Indeed',
            'external_url' => 'Abrir candidatura',
            'email' => 'Enviar e-mail',
            'whatsapp' => 'Abrir conversa no WhatsApp',
            'phone' => 'Ligar',
            'form' => 'Preencher formulário',
            default => 'Candidatar-se',
        };
    }

    public static function shouldShowInstructions(ApplicationMethod $method): bool
    {
        $hasUrl = filled($method->url) || filled($method->email) || filled($method->phone);

        if ($hasUrl) {
            return false;
        }

        return ! in_array($method->type?->value, ['email', 'external_url'], true);
    }

    public function adapt(): void
    {
        Gate::authorize('update', $this->record);

        try {
            app(ResumeAdaptationService::class)->adapt($this->record);

            $this->fillAdaptedForm();

            Notification::make()
                ->success()
                ->title('Currículo adequado')
                ->body('A versão adaptada para esta vaga foi gerada.')
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Falha na adequação')
                ->body($e->getMessage())
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adapt')
                ->label(fn (): string => $this->adaptation() === null ? 'Adequar currículo' : 'Adequar novamente')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('primary')
                ->action(fn () => $this->adapt()),
            Action::make('downloadPdf')
                ->label('Baixar PDF')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->visible(fn (): bool => $this->adaptation() !== null)
                ->url(fn (): string => route('filament.admin.resume-pdf.download', $this->record))
                ->openUrlInNewTab(),
            Action::make('manageApplicationMethods')
                ->label('Gerenciar métodos')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === JobPostingStatus::Confirmed)
                ->authorize('update', $this->record)
                ->modalHeading('Como se candidatar')
                ->modalDescription('Corrija ou adicione formas de candidatura. As alterações substituem o conjunto atual desta vaga.')
                ->modalWidth(Width::SevenExtraLarge)
                ->modalSubmitActionLabel('Salvar métodos')
                ->schema([
                    Repeater::make('application_methods')
                        ->label('Formas de candidatura')
                        ->schema(ApplicationMethodForm::schema())
                        ->columns(3)
                        ->columnSpanFull()
                        ->addActionLabel('Adicionar método')
                        ->defaultItems(0),
                ])
                ->fillForm(fn (): array => [
                    'application_methods' => $this->applicationMethods()
                        ->map(fn (ApplicationMethod $method): array => [
                            'type' => $method->type?->value,
                            'platform' => $method->platform,
                            'url' => $method->url,
                            'email' => $method->email,
                            'phone' => $method->phone,
                            'instructions' => $method->instructions,
                        ])
                        ->all(),
                ])
                ->action(function (array $data, JobPosting $record): void {
                    try {
                        app(JobReviewService::class)->replaceApplicationMethods(
                            $record,
                            $data['application_methods'] ?? [],
                        );

                        Notification::make()
                            ->success()
                            ->title('Métodos atualizados')
                            ->body('As formas de candidatura desta vaga foram salvas.')
                            ->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()
                            ->danger()
                            ->title('Não foi possível salvar')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
        ];
    }
}
