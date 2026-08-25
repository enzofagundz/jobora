<?php

namespace App\Filament\Resources\JobPostings\Pages;

use App\Enums\ApplicationMethodType;
use App\Enums\ApplicationStatus;
use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Filament\Resources\JobPostings\Schemas\ApplicationMethodForm;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\ApplicationMethod;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Services\ApplicationTrackingService;
use App\Services\JobReviewService;
use App\Services\ResumeAdaptationService;
use App\Services\ResumeService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

/**
 * Página de Candidatura do JobPosting — substitui a antiga
 * ResumeAdaptationPage. Concentra currículo adaptado, formas de candidatura e o
 * acompanhamento da candidatura (entidade de domínio `Application` + histórico
 * append-only em `ApplicationEvent`). Não vira item de navegação global: é
 * acessada pela ação `application` da tabela; o acompanhamento é iniciado sob
 * demanda pelo usuário, nunca ao abrir a página.
 */
class ApplicationPage extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

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

    public function application(): ?Application
    {
        return $this->record->application;
    }

    /**
     * @return Collection<int, ApplicationEvent>
     */
    public function applicationEvents(): Collection
    {
        $application = $this->application();

        if ($application === null) {
            return Collection::make();
        }

        return $application->events()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<int, array{id: int, occurred_at: CarbonInterface, title: string, status: ApplicationStatus, notes: ?string, contact: ?string}>
     */
    public function eventRows(): array
    {
        return $this->applicationEvents()
            ->map(fn (ApplicationEvent $event): array => [
                'id' => $event->id,
                'occurred_at' => $event->occurred_at,
                'title' => $event->title,
                'status' => $event->status,
                'notes' => $event->notes,
                'contact' => $event->contact,
            ])
            ->all();
    }

    /**
     * @return array<int, ApplicationStatus>
     */
    public function reachedStages(): array
    {
        return $this->applicationEvents()
            ->map(fn (ApplicationEvent $event): ApplicationStatus => $event->status)
            ->unique()
            ->values()
            ->all();
    }

    public function suggestedNextStage(): ?ApplicationStatus
    {
        $status = $this->application()?->status;

        if ($status === null || $status->isTerminal() || $status === ApplicationStatus::Waiting) {
            return null;
        }

        return $status->nextPipelineStage();
    }

    public function statusColor(ApplicationStatus $status): string
    {
        return match ($status) {
            ApplicationStatus::Planned => 'gray',
            ApplicationStatus::Preparing => 'info',
            ApplicationStatus::Submitted => 'primary',
            ApplicationStatus::Waiting => 'warning',
            ApplicationStatus::Assessment => 'warning',
            ApplicationStatus::Interview => 'info',
            ApplicationStatus::Offer => 'success',
            ApplicationStatus::Completed => 'success',
            ApplicationStatus::Rejected => 'danger',
            ApplicationStatus::Withdrawn => 'gray',
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->eventRows())
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Aconteceu em')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('title')
                    ->label('Movimentação')
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Etapa')
                    ->badge()
                    ->color(fn (ApplicationStatus $state): string => $this->statusColor($state)),
                TextColumn::make('notes')
                    ->label('Observação')
                    ->wrap()
                    ->limit(200),
                TextColumn::make('contact')
                    ->label('Contato')
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->emptyStateHeading('Nenhuma movimentação registrada')
            ->emptyStateDescription('Registre a primeira movimentação para iniciar o histórico desta candidatura.');
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
            Action::make('startTracking')
                ->label('Iniciar acompanhamento')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('info')
                ->visible(fn (): bool => $this->application() === null)
                ->authorize('update', $this->record)
                ->requiresConfirmation()
                ->modalHeading('Iniciar acompanhamento')
                ->modalDescription('Será criada a candidatura com status "Planejada" para esta vaga.')
                ->action(function (): void {
                    Gate::authorize('update', $this->record);

                    app(ApplicationTrackingService::class)->start($this->record);

                    $this->record->refresh();

                    Notification::make()
                        ->success()
                        ->title('Acompanhamento iniciado')
                        ->body('A candidatura foi criada com status "Planejada".')
                        ->send();
                }),
            Action::make('recordUpdate')
                ->label('Registrar atualização')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('primary')
                ->visible(fn (): bool => $this->application() !== null)
                ->authorize('update', $this->record)
                ->modalHeading('Registrar movimentação')
                ->modalDescription('Repetir o status atual registra uma nova observação sem apagar o histórico anterior.')
                ->schema([
                    Select::make('status')
                        ->label('Status')
                        ->options(ApplicationStatus::class)
                        ->required()
                        ->default(fn (): ?string => $this->application()?->status?->value),
                    TextInput::make('title')
                        ->label('Movimentação')
                        ->nullable()
                        ->maxLength(255)
                        ->placeholder('Ex.: Candidatura enviada por e-mail'),
                    Textarea::make('notes')
                        ->label('Observação')
                        ->nullable()
                        ->maxLength(2000)
                        ->rows(5)
                        ->columnSpanFull(),
                    TextInput::make('contact')
                        ->label('Contato')
                        ->nullable()
                        ->maxLength(255)
                        ->helperText('Referência livre (nome, canal ou e-mail). Não é um cadastro de CRM.'),
                    DateTimePicker::make('occurred_at')
                        ->label('Aconteceu em')
                        ->required()
                        ->default(now()),
                ])
                ->action(function (array $data): void {
                    Gate::authorize('update', $this->record);

                    $application = $this->application();

                    if ($application === null) {
                        return;
                    }

                    $status = $data['status'] instanceof ApplicationStatus
                        ? $data['status']
                        : ApplicationStatus::from($data['status']);

                    try {
                        app(ApplicationTrackingService::class)->recordUpdate(
                            $application,
                            $status,
                            $data['title'] ?? null,
                            $data['notes'] ?? null,
                            $data['contact'] ?? null,
                            Carbon::parse($data['occurred_at']),
                        );

                        $this->record->refresh();

                        Notification::make()
                            ->success()
                            ->title('Acompanhamento atualizado')
                            ->body('A movimentação foi registrada no histórico.')
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Falha ao registrar')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
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
