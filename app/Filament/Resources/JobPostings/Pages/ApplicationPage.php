<?php

namespace App\Filament\Resources\JobPostings\Pages;

use App\Enums\AiOperationType;
use App\Enums\ApplicationMethodType;
use App\Enums\ApplicationStatus;
use App\Enums\JobPostingStatus;
use App\Enums\ResumeLanguage;
use App\Filament\Pages\ResumePage;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Filament\Resources\JobPostings\Schemas\ApplicationMethodForm;
use App\Models\AgentIntegration;
use App\Models\AiOperation;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\ApplicationMethod;
use App\Models\DocumentArtifact;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Services\AiOperationService;
use App\Services\ApplicationApprovalService;
use App\Services\ApplicationPreparationService;
use App\Services\ApplicationTrackingService;
use App\Services\DocumentArtifactService;
use App\Services\JobReviewService;
use App\Services\ProfessionalProfileService;
use App\Services\ResumeService;
use App\Support\Staleness;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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

    /**
     * Idioma em foco na página. Não é persistido: a página sempre abre em
     * PT-BR e o seletor só troca a leitura desta sessão.
     */
    public string $resumeLanguage = ResumeLanguage::PtBr->value;

    public bool $atsConsent = false;

    public ?array $atsScoreResult = null;

    public ?string $atsConsentMarker = null;

    public ?string $destinationApplicationMethodId = null;

    public bool $preparationCoverLetterRequested = false;

    public bool $preparationTranslateMissing = false;

    public bool $preparationAtsConsent = false;

    public ?array $preparationResult = null;

    public array $preparationOperations = [];

    public ?array $preparationData = ['questions' => []];

    public array $preparationQuestionLabels = [];

    public ?array $approvalData = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        Gate::authorize('view', $this->record);

        $this->destinationApplicationMethodId = $this->applicationMethods()->first()?->id;

        $this->form->fill([
            'original_resume' => ResumeService::plainText(app(ResumeService::class)->content(auth()->user(), ResumeLanguage::PtBr)),
        ]);

        $this->fillAdaptedForm();
        $this->refreshAiOperation();
        $this->recoverCurrentApplicationPreparation();
    }

    /**
     * Troca o idioma em foco: recarrega currículo original, adaptado e o
     * estado do ATS pelo idioma escolhido. O seletor não persiste.
     */
    public function updatedResumeLanguage(): void
    {
        $this->atsConsent = false;
        $this->preparationAtsConsent = false;
        $this->fillOriginalForm();
        $this->refreshAiOperation();
    }

    public function language(): ResumeLanguage
    {
        return ResumeLanguage::from($this->resumeLanguage);
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

    public function preparationForm(Schema $schema): Schema
    {
        return $schema->components([
            Repeater::make('questions')->label('Perguntas da candidatura')->schema([
                TextInput::make('question')->label('Pergunta')->required()->maxLength(5000),
                Textarea::make('answer')->label('Resposta')->rows(3)->maxLength(10000),
                Repeater::make('support')->label('Trechos de apoio')->schema([
                    Select::make('source')->label('Origem')->options(['profile' => 'Perfil confirmado', 'resume' => 'Currículo'])->required(),
                    TextInput::make('path')->label('Campo de origem')->required()->maxLength(255)
                        ->helperText('Ex.: competencies.0 no perfil ou content no currículo.'),
                    Textarea::make('quote')->label('Trecho literal')->required()->maxLength(10000)->rows(2),
                ])->defaultItems(0)->maxItems(50)->columnSpanFull(),
            ])->defaultItems(0)->maxItems(100)->addActionLabel('Adicionar pergunta')->columnSpanFull()
                ->helperText('Use apenas informações do perfil confirmado ou currículo. Respostas sem trecho literal correspondente ficam pendentes.'),
        ])->statePath('preparationData');
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

    /**
     * Recarrega o currículo original do idioma em foco preservando o resto de
     * data (fill() substituiria o array data inteiro).
     */
    private function fillOriginalForm(): void
    {
        $this->form->fillPartially(
            [
                'original_resume' => ResumeService::plainText($this->originalResume()),
            ],
            ['original_resume'],
        );
    }

    public function originalResume(): ?string
    {
        return app(ResumeService::class)->content(auth()->user(), $this->language());
    }

    public function hasResume(ResumeLanguage $language): bool
    {
        return filled(app(ResumeService::class)->content(auth()->user(), $language));
    }

    public function adaptation(): ?ResumeAdaptation
    {
        return $this->adaptationFor($this->language());
    }

    public function adaptationFor(ResumeLanguage $language): ?ResumeAdaptation
    {
        return $this->record->resumeAdaptation($language)->first();
    }

    public function activeAiOperation(): ?AiOperation
    {
        return AiOperation::query()
            ->where('user_id', auth()->id())
            ->where('job_posting_id', $this->record->getKey())
            ->active()
            ->latest('queued_at')
            ->first();
    }

    public function hasActiveAiOperation(): bool
    {
        return $this->activeAiOperation() !== null;
    }

    public function aiOperationLabel(AiOperation $operation): string
    {
        return match ($operation->operation_type) {
            AiOperationType::ResumeTranslation => 'Tradução do currículo',
            AiOperationType::ResumeAdaptation => 'Adequação do currículo',
            AiOperationType::AtsOptimization => 'Otimização ATS',
            AiOperationType::AtsScore => 'Simulação ATS',
            AiOperationType::StrategicCompetencyClassification => 'Classificação estratégica',
        };
    }

    public function refreshAiOperation(): void
    {
        $this->record->refresh();
        $this->fillAdaptedForm();

        $adaptation = $this->adaptation();
        $currentConsentMarker = $this->atsConsentMarker($adaptation);

        if ($this->atsConsentMarker !== null && $this->atsConsentMarker !== $currentConsentMarker) {
            $this->atsConsent = false;
            $this->preparationAtsConsent = false;
        }

        $this->atsConsentMarker = $currentConsentMarker;
        $this->atsScoreResult = $adaptation?->ats_score === null
            ? null
            : [
                'score' => $adaptation->ats_score,
                'rationale' => $adaptation->ats_rationale ?? '',
            ];

        $this->refreshApplicationPreparation();
    }

    public function prepareApplicationMaterials(ApplicationPreparationService $preparations): void
    {
        $user = auth()->user();
        $posting = JobPosting::query()->where('user_id', $user->id)->findOrFail($this->record->getKey());
        Gate::forUser($user)->authorize('update', $posting);

        if (! $posting->isEligibleForApplicationDocuments()) {
            Notification::make()->danger()->title('Vaga indisponível para preparação')->body('A oportunidade precisa estar pronta para candidatura.')->send();

            return;
        }

        $language = ResumeLanguage::tryFrom($this->resumeLanguage);

        if ($language === null || blank($this->destinationApplicationMethodId)) {
            Notification::make()->warning()->title('Selecione idioma e destino')->body('Escolha uma forma de candidatura cadastrada nesta vaga.')->send();

            return;
        }

        $profile = app(ProfessionalProfileService::class)->get($user);
        $input = [
            'job_posting_id' => $posting->id,
            'expected_profile_confirmed_version' => $profile['confirmed_version'],
            'language' => $language->value,
            'destination' => ['application_method_id' => (int) $this->destinationApplicationMethodId],
            'cover_letter_requested' => $this->preparationCoverLetterRequested,
            'translate_missing' => $language === ResumeLanguage::En && $this->preparationTranslateMissing,
            'ats_consent' => $this->preparationAtsConsent,
            'questions' => $this->preparationQuestions(),
        ];

        if ($posting->isStructuredOpportunity()) {
            $input['expected_opportunity_version'] = $posting->opportunity_version;
        }

        try {
            $this->preparationResult = $preparations->prepare($user, $input, ['source' => 'filament']);
            $this->refreshApplicationPreparation($preparations);
            $notification = match ($this->preparationResult['state']) {
                'pending_information' => Notification::make()->warning()->title('Faltam informações'),
                'preparing' => Notification::make()->info()->title('Preparação em andamento'),
                default => Notification::make()->success()->title('Material preparado'),
            };
            $notification->send();
        } catch (AuthorizationException|LogicException|ModelNotFoundException) {
            Notification::make()->danger()->title('Não foi possível preparar o material')->body('Confira se perfil, vaga e destino ainda estão válidos.')->send();
        }
    }

    public function refreshApplicationPreparation(?ApplicationPreparationService $preparations = null): void
    {
        if ($this->preparationResult === null) {
            $this->approvalData = null;

            return;
        }

        $preparations ??= app(ApplicationPreparationService::class);
        $user = auth()->user();
        $preparationId = $this->preparationResult['preparation_id'] ?? null;

        if ($preparationId !== null) {
            try {
                $result = $preparations->get($user, (string) $preparationId);
                if ((int) ($result['snapshot']['opportunity']['id'] ?? 0) !== (int) $this->record->getKey()) {
                    $this->preparationResult = null;
                    $this->preparationOperations = [];
                    $this->approvalData = null;

                    return;
                }
                $this->preparationResult = $result;
            } catch (ModelNotFoundException) {
                $this->preparationResult = null;
                $this->approvalData = null;
            }
        }

        $operationIds = array_values(array_filter($this->preparationResult['operation_ids'] ?? [], 'is_int'));
        $this->preparationOperations = AiOperation::query()->where('user_id', $user->id)->whereKey($operationIds)->get()
            ->filter(fn (AiOperation $operation): bool => $operation->operation_type === AiOperationType::ResumeTranslation
                ? $operation->target_type === 'user' && (int) $operation->target_id === $user->id
                : $operation->job_posting_id === $this->record->getKey()
                    && in_array($operation->operation_type, [AiOperationType::ResumeAdaptation, AiOperationType::AtsOptimization, AiOperationType::AtsScore], true))
            ->map(fn (AiOperation $operation): array => [
                'type' => $this->aiOperationLabel($operation),
                'status' => $operation->status?->value,
                'active' => $operation->isActive(),
            ])->values()->all();

        $this->refreshApplicationApproval();
    }

    public function refreshApplicationApproval(?ApplicationApprovalService $approvals = null): void
    {
        $preparationId = $this->preparationResult['preparation_id'] ?? null;

        if ($preparationId === null) {
            $this->approvalData = null;

            return;
        }

        try {
            $this->approvalData = ($approvals ?? app(ApplicationApprovalService::class))
                ->forPreparation(auth()->user(), (string) $preparationId);
        } catch (ModelNotFoundException|AuthorizationException) {
            $this->approvalData = null;
        }
    }

    public function revokeApplicationApproval(string $approvalId): void
    {
        $user = auth()->user();
        $posting = JobPosting::query()->where('user_id', $user->id)->findOrFail($this->record->getKey());
        $preparationId = $this->preparationResult['preparation_id'] ?? null;
        $currentPreparationId = $posting->application()->value('current_preparation_id');

        if ($preparationId === null || (string) $preparationId !== (string) $currentPreparationId) {
            Notification::make()->warning()->title('Aprovação não pertence ao material atual')->send();

            return;
        }

        $preparation = app(ApplicationPreparationService::class)->get($user, (string) $preparationId);

        if (! ($preparation['current'] ?? false)
            || (int) ($preparation['snapshot']['opportunity']['id'] ?? 0) !== (int) $posting->getKey()) {
            Notification::make()->warning()->title('Material não está mais atual')->send();

            return;
        }

        $approvalService = app(ApplicationApprovalService::class);
        $approvalList = $approvalService->forPreparation($user, (string) $preparationId);
        $ownedApproval = collect($approvalList['approvals'] ?? [])->first(fn (array $approval): bool => (string) $approval['id'] === $approvalId);

        if ($ownedApproval === null) {
            Notification::make()->danger()->title('Aprovação não encontrada para este material')->send();

            return;
        }

        try {
            $approvalService->revoke($user, $approvalId, ['source' => 'filament']);
            Notification::make()->success()->title('Aprovação revogada')->body('A aprovação registrada não enviou candidatura.')->send();
            $this->refreshApplicationApproval($approvalService);
        } catch (AuthorizationException|ModelNotFoundException) {
            Notification::make()->danger()->title('Não foi possível revogar a aprovação')->send();
        }
    }

    public function approvalIntegrationName(int $integrationId): string
    {
        return AgentIntegration::query()->where('user_id', auth()->id())->find($integrationId)?->name ?? 'Integração indisponível';
    }

    public function approvalStatusLabel(array $approval): string
    {
        $canShowAsActive = ($approval['status'] ?? null) === 'active'
            && ($this->preparationResult['current'] ?? false)
            && ! ($this->preparationResult['stale'] ?? true)
            && ($this->preparationResult['state'] ?? null) === 'awaiting_approval'
            && ($this->preparationResult['pending'] ?? []) === [];

        if ($canShowAsActive) {
            return 'Aprovação registrada para este material';
        }

        return match ($approval['status'] ?? null) {
            'revoked' => 'Aprovação revogada',
            'expired' => 'Aprovação expirada',
            default => 'Aprovação inválida ou material alterado',
        };
    }

    public function hasActivePreparationOperation(): bool
    {
        return collect($this->preparationOperations)->contains(fn (array $operation): bool => $operation['active']);
    }

    public function canGenerateApplicationDocuments(): bool
    {
        return $this->record->fresh()->isEligibleForApplicationDocuments();
    }

    public function preparedArtifactUrl(string $artifactId): ?string
    {
        $preparations = app(ApplicationPreparationService::class);
        $documents = app(DocumentArtifactService::class);
        $this->refreshApplicationPreparation($preparations);
        $preparationId = $this->preparationResult['preparation_id'] ?? null;

        if ($preparationId === null) {
            return null;
        }

        try {
            $artifact = DocumentArtifact::query()->where('user_id', auth()->id())
                ->where('preparation_id', $preparationId)->findOrFail($artifactId);

            if ($artifact->revoked_at !== null || $artifact->expires_at->lte(now())
                || ! Storage::disk('local')->exists($artifact->path)
                || ($artifact->requires_ats_authorization && ! Gate::allows('useAtsOptimization'))) {
                return null;
            }

            return $documents->deliver(auth()->user(), $artifact, session: true)['download_url'];
        } catch (ModelNotFoundException) {
            return null;
        } catch (HttpExceptionInterface $exception) {
            if (in_array($exception->getStatusCode(), [403, 410], true)) {
                return null;
            }

            throw $exception;
        }
    }

    public function preparationPendingLabel(array $pending): string
    {
        return match ($pending['code'] ?? null) {
            'missing_resume' => 'Currículo no idioma escolhido ainda não está disponível. Cadastre essa versão ou, para EN, marque a opção de solicitar tradução.',
            'unsupported_answer' => 'Resposta sem trecho literal correspondente no perfil ou currículo. Revise resposta e trecho de apoio antes de preparar novamente.',
            'cover_letter_required' => 'Este destino exige carta de apresentação. Marque “Incluir carta de apresentação” e prepare novamente.',
            default => 'Revise as informações pendentes antes de usar este material.',
        };
    }

    private function preparationQuestions(): array
    {
        $questions = $this->preparationForm->getState()['questions'] ?? [];
        $this->preparationQuestionLabels = [];

        return collect(array_values($questions))
            ->filter(fn (array $question): bool => filled($question['question'] ?? null))
            ->values()
            ->map(function (array $question, int $index): array {
                $id = 'question-'.substr(hash('sha256', $index."\0".$question['question']), 0, 24);
                $this->preparationQuestionLabels[$id] = $question['question'];

                return [
                    'id' => $id,
                    'question' => $question['question'],
                    'answer' => $question['answer'] ?? null,
                    'support' => array_values(array_map(static fn (array $support): array => [
                        'source' => $support['source'], 'path' => $support['path'], 'quote' => $support['quote'],
                    ], $question['support'] ?? [])),
                ];
            })->all();
    }

    private function recoverCurrentApplicationPreparation(): void
    {
        $preparationId = $this->record->application()->value('current_preparation_id');

        if ($preparationId === null) {
            return;
        }

        $this->preparationResult = app(ApplicationPreparationService::class)->get(auth()->user(), (string) $preparationId);
        $this->refreshApplicationPreparation();
    }

    public function atsScoreIsStale(): bool
    {
        $adaptation = $this->adaptation();

        return $adaptation !== null && Staleness::isAtsScoreStale($adaptation);
    }

    public function atsPromptIsStale(): bool
    {
        $adaptation = $this->adaptation();

        return $adaptation !== null && Staleness::isAtsPromptStale($adaptation);
    }

    private function atsConsentMarker(?ResumeAdaptation $adaptation): string
    {
        if ($adaptation === null) {
            return '';
        }

        return implode('|', [
            $adaptation->source_fingerprint ?? '',
            $adaptation->ats_prompt_fingerprint ?? hash('sha256', (string) $adaptation->ats_prompt),
            $adaptation->is_ats_optimized ? '1' : '0',
        ]);
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

    public function languageName(ResumeLanguage $language): string
    {
        return match ($language) {
            ResumeLanguage::PtBr => 'Português (PT-BR)',
            ResumeLanguage::En => 'Inglês (EN)',
        };
    }

    public function adaptItemLabel(ResumeLanguage $language): string
    {
        $name = $this->languageName($language);

        return $this->adaptationFor($language) === null
            ? 'Adequar em '.$name
            : 'Adequar novamente em '.$name;
    }

    public function adapt(ResumeLanguage $language = ResumeLanguage::PtBr): void
    {
        Gate::authorize('update', $this->record);

        if (! $this->canGenerateApplicationDocuments()) {
            $this->notifyIneligibleGeneration();

            return;
        }

        $this->atsConsent = false;

        try {
            app(AiOperationService::class)->enqueueResumeAdaptation(auth()->user(), $this->record, $language);
            $this->resumeLanguage = $language->value;
            $this->fillOriginalForm();
            $this->refreshAiOperation();

            Notification::make()
                ->info()
                ->title('Adequação em andamento')
                ->body('A versão adaptada será atualizada quando o processamento terminar.')
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Falha na adequação')
                ->body($e->getMessage())
                ->send();
        }
    }

    public function canUseAtsOptimization(): bool
    {
        return Gate::allows('useAtsOptimization');
    }

    public function isAtsOptimized(): bool
    {
        return (bool) ($this->adaptation()?->is_ats_optimized ?? false);
    }

    public function atsPrompt(): ?string
    {
        return $this->adaptation()?->ats_prompt;
    }

    public function toggleAtsOptimization(): void
    {
        Gate::authorize('useAtsOptimization');
        Gate::authorize('update', $this->record);

        if (! $this->canGenerateApplicationDocuments()) {
            $this->notifyIneligibleGeneration();

            return;
        }

        $this->atsConsent = false;

        try {
            $newState = ! $this->isAtsOptimized();
            app(AiOperationService::class)->enqueueAtsOptimization(auth()->user(), $this->record, $newState, $this->language());
            $this->refreshAiOperation();

            Notification::make()
                ->info()
                ->title('Atualização da otimização ATS em andamento')
                ->body('A configuração será atualizada quando o processamento terminar.')
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Falha na otimização')
                ->body($e->getMessage())
                ->send();
        }
    }

    public function simulateAtsScore(): void
    {
        Gate::authorize('useAtsOptimization');
        Gate::authorize('view', $this->record);

        if (! $this->canGenerateApplicationDocuments()) {
            $this->notifyIneligibleGeneration();

            return;
        }

        if (! $this->adaptation()) {
            Notification::make()
                ->danger()
                ->title('Sem adaptação')
                ->body('Gere a adaptação antes de simular.')
                ->send();

            return;
        }

        try {
            app(AiOperationService::class)->enqueueAtsScore(auth()->user(), $this->record, $this->language());
            $this->refreshAiOperation();

            Notification::make()
                ->info()
                ->title('Simulação ATS em andamento')
                ->body('A nota será atualizada quando o processamento terminar.')
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Falha na simulação')
                ->body($e->getMessage())
                ->send();
        }
    }

    private function notifyIneligibleGeneration(): void
    {
        Notification::make()->warning()->title('Vaga indisponível para novas gerações')
            ->body('O histórico continua disponível, mas gere ou prepare material somente para uma vaga pronta.')->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('adaptPtBr')
                    ->label(fn (): string => $this->adaptItemLabel(ResumeLanguage::PtBr))
                    ->disabled(fn (): bool => $this->hasActiveAiOperation() || ! $this->canGenerateApplicationDocuments())
                    ->action(fn () => $this->adapt(ResumeLanguage::PtBr)),
                Action::make('adaptEn')
                    ->label(fn (): string => $this->adaptItemLabel(ResumeLanguage::En))
                    ->disabled(fn (): bool => $this->hasActiveAiOperation() || ! $this->hasResume(ResumeLanguage::En) || ! $this->canGenerateApplicationDocuments())
                    ->tooltip(fn (): ?string => $this->hasResume(ResumeLanguage::En)
                        ? null
                        : ResumeLanguage::En->missingResumeGuidance())
                    ->action(fn () => $this->adapt(ResumeLanguage::En)),
                Action::make('createEnglishResume')
                    ->label('Criar versão em inglês do currículo')
                    ->icon(Heroicon::OutlinedLanguage)
                    ->visible(fn (): bool => ! $this->hasResume(ResumeLanguage::En))
                    ->url(fn (): string => ResumePage::getUrl()),
            ])
                ->label(fn (): string => $this->adaptation() === null ? 'Adequar currículo' : 'Adequar novamente')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('primary')
                ->button(),
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

                    app(ApplicationTrackingService::class)->start(auth()->user(), $this->record);

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
                            auth()->user(),
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
                ->label(fn (): string => $this->isAtsOptimized() ? 'Baixar PDF otimizado' : 'Baixar PDF')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->visible(fn (): bool => $this->adaptation() !== null && (! $this->isAtsOptimized() || $this->atsConsent))
                ->url(fn (): string => $this->isAtsOptimized()
                    ? route('filament.admin.resume-pdf.download', [
                        'posting' => $this->record,
                        'language' => $this->resumeLanguage,
                        'ats_consent' => '1',
                    ])
                    : route('filament.admin.resume-pdf.download', [
                        'posting' => $this->record,
                        'language' => $this->resumeLanguage,
                    ]))
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
                            auth()->user(),
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
