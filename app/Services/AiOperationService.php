<?php

namespace App\Services;

use App\Enums\AgentIntegrationScope;
use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Enums\ResumeLanguage;
use App\Filament\Pages\ProfessionalCompetenciesPage;
use App\Filament\Pages\ProfessionalProfilePage;
use App\Filament\Pages\ResumePage;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Jobs\ClassifyPublicationWithClef;
use App\Jobs\ProcessAiOperation;
use App\Mcp\Support\AgentIntegrationContext;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\PageCapture;
use App\Models\ProfessionalProfile;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\PublicationTriageResult;
use App\Models\Resume;
use App\Models\User;
use App\Services\Discovery\PublicationExtractionException;
use App\Services\Discovery\PublicationExtractionProcessor;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class AiOperationService
{
    private const RUNNING_STALE_AFTER_SECONDS = 130;

    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly AiInputSnapshotService $snapshots,
        private readonly ResumeService $resumeService,
        private readonly ResumeAdaptationService $resumeAdaptation,
        private readonly ResumeTranslationService $resumeTranslation,
        private readonly StrategicCompetencyService $strategicCompetency,
        private readonly AtsScorerService $atsScorer,
        private readonly PublicationExtractionProcessor $publicationExtraction,
        private readonly PublicationTriageProcessor $publicationTriage,
        private readonly ProfileImportProcessor $profileImport,
        private readonly MatchingProcessor $matching,
    ) {}

    /** @param array{source?: string, integration_id?: int} $actorMetadata */
    public function enqueueMatching(User $user, int $opportunityId, int $expectedOpportunityVersion, int $expectedProfileConfirmedVersion, string $criteriaVersion = 'matching-v1', array $actorMetadata = [], bool $retry = false): AiOperation
    {
        if (app()->bound(AgentIntegrationContext::class)) {
            $actorMetadata = ['source' => 'mcp', 'integration_id' => app(AgentIntegrationContext::class)->integration->id];
        }
        $matching = app(MatchingService::class);
        $matching->authorize($user, $actorMetadata);
        $payload = $matching->snapshot($user, $opportunityId, $criteriaVersion);
        $frozen = $payload['frozen'];
        if ($frozen['opportunity']['opportunity_version'] !== $expectedOpportunityVersion || $frozen['profile']['confirmed_version'] !== $expectedProfileConfirmedVersion) {
            throw new MatchingException('conflict');
        }
        $payload['requester_actor'] = $actorMetadata;
        $fingerprint = MatchingService::hash($frozen);
        $key = 'matching:'.$fingerprint;
        if ($retry) {
            $previous = AiOperation::query()->where('user_id', $user->id)->where('operation_key', $key)->first();
            if ($previous === null || ($previous->status === AiOperationStatus::Failed && $previous->attempts >= self::MAX_ATTEMPTS)) {
                throw new LogicException('Não há retry de matching disponível para esta entrada.');
            }
        }

        return $this->enqueue($user, AiOperationType::Matching, $key, 'opportunity', $opportunityId, null,
            ['payload' => $payload, 'fingerprint' => $fingerprint], failOnDispatchError: false, idempotent: true, retry: $retry);
    }

    /** @param array{source?: string, integration_id?: int} $actorMetadata */
    public function enqueueProfileImport(User $user, ResumeLanguage $language, int $expectedVersion, array $actorMetadata = [], bool $retry = false): AiOperation
    {
        Validator::validate(['expected_version' => $expectedVersion], ['expected_version' => ['integer', 'min:0', 'max:2147483644']]);
        app(ProfileImportService::class)->validateActor($user, $actorMetadata);
        $state = app(ProfessionalProfileService::class)->get($user);
        if ($expectedVersion < 0 || $state['version'] !== $expectedVersion) {
            throw new ProfessionalProfileConflictException;
        }
        if (! $retry) {
            $resume = Resume::query()->where('user_id', $user->id)->where('language', $language->value)->first();
            $content = $resume?->content;
            if (! is_string($content) || trim($content) === '' || mb_strlen($content) > (int) config('ai.profile_import.max_content_characters', 100000)) {
                throw new LogicException('Cadastre um currículo válido no idioma solicitado antes de importar.');
            }
            $sourceFingerprint = hash('sha256', $content);
            $content = ResumeService::plainText($content);
            if (trim($content) === '' || mb_strlen($content) > (int) config('ai.profile_import.max_content_characters', 100000)) {
                throw new LogicException('Cadastre um currículo válido no idioma solicitado antes de importar.');
            }
        } elseif (! ProfessionalProfile::query()->where('user_id', $user->id)->exists()) {
            throw new LogicException('Não há importação solicitada para retry.');
        }
        $draft = $state['draft'];
        unset($draft['preferences'], $draft['restrictions']);
        $profile = ProfessionalProfile::query()->firstOrCreate(['user_id' => $user->id], ['version' => 0, 'draft' => $draft, 'confirmed_version' => 0]);

        if ($retry) {
            $previous = AiOperation::query()->where('user_id', $user->id)->where('operation_type', AiOperationType::ProfileImport)
                ->where('target_id', $profile->id)->latest('id')->get()->first(fn (AiOperation $operation): bool => ($operation->input_snapshot['base_version'] ?? null) === $expectedVersion
                    && ($operation->input_snapshot['source']['language'] ?? null) === $language->value);
            if ($previous === null) {
                throw new LogicException('Não há importação solicitada para retry.');
            }
            if ($previous->isActive() || $previous->status === AiOperationStatus::Completed) {
                return $previous;
            }
            if ($previous->attempts >= self::MAX_ATTEMPTS) {
                throw new LogicException('Limite de tentativas de importação atingido.');
            }
            $snapshot = ['payload' => $previous->input_snapshot, 'fingerprint' => $previous->input_fingerprint];
            $key = $previous->operation_key;
        } else {
            $blocks = [];
            foreach (preg_split('/\R/u', $content) ?: [] as $line) {
                if (trim($line) !== '') {
                    $blocks['block-'.(count($blocks) + 1)] = $line;
                }
            }
            $payload = [
                'profile_id' => $profile->id, 'base_version' => $expectedVersion,
                'base_confirmed_version' => $state['confirmed_version'], 'base_document' => $state['draft'],
                'source' => ['language' => $language->value, 'fingerprint' => $sourceFingerprint, 'content' => $content],
                'blocks' => $blocks, 'requester_actor' => $actorMetadata,
                'provider' => (string) config('ai.profile_import.provider'), 'model' => (string) config('ai.profile_import.model'),
                'schema_version' => (string) config('ai.profile_import.schema_version'),
            ];
            $fingerprint = hash('sha256', json_encode([$user->id, $profile->id, $expectedVersion, $payload['source']['language'], $payload['source']['fingerprint'], $payload['schema_version'], $payload['provider'], $payload['model']], JSON_THROW_ON_ERROR));
            $snapshot = ['payload' => $payload, 'fingerprint' => $fingerprint];
            $key = 'profile_import:'.$fingerprint;
        }

        return $this->enqueue($user, AiOperationType::ProfileImport, $key, 'professional_profile', $profile->id, null, $snapshot,
            failOnDispatchError: false, idempotent: true, retry: $retry);
    }

    public function enqueueResumeAdaptation(User $user, JobPosting $posting, ResumeLanguage $language = ResumeLanguage::PtBr, array $actorMetadata = []): AiOperation
    {
        Gate::forUser($user)->authorize('update', $posting);

        if ($posting->user_id !== $user->id) {
            throw new LogicException('Vaga não pertence ao usuário.');
        }

        if (! $posting->fresh()->isEligibleForApplicationDocuments()) {
            throw new LogicException('A adequação exige uma vaga confirmada.');
        }

        if (blank($this->resumeService->content($user, $language))) {
            throw new LogicException($language->missingResumeGuidance());
        }

        $snapshot = $this->snapshots->resumeAdaptation($user, $posting->fresh()->load('items'), $language);
        $snapshot = $this->documentSnapshot($user, $posting, $snapshot, $actorMetadata, AgentIntegrationScope::AtsWrite);

        return $this->enqueue(
            $user,
            AiOperationType::ResumeAdaptation,
            AiOperationType::ResumeAdaptation->value.':job_posting:'.$posting->id.':'.$language->value,
            'job_posting',
            $posting->id,
            $posting->id,
            $snapshot,
        );
    }

    public function enqueueResumeTranslation(User $user, array $actorMetadata = []): AiOperation
    {
        if (blank($this->resumeService->content($user, ResumeLanguage::PtBr))) {
            throw new LogicException('Cadastre o currículo em português antes de gerar a versão em inglês.');
        }

        $snapshot = $this->snapshots->resumeTranslation($user);
        $snapshot = $this->documentSnapshot($user, null, $snapshot, $actorMetadata, AgentIntegrationScope::ResumeWrite);

        return $this->enqueue(
            $user,
            AiOperationType::ResumeTranslation,
            AiOperationType::ResumeTranslation->value.':user:'.$user->id,
            'user',
            $user->id,
            null,
            $snapshot,
        );
    }

    public function enqueueStrategicCompetencyClassification(User $user): AiOperation
    {
        if (blank($this->resumeService->content($user, ResumeLanguage::PtBr))) {
            throw new LogicException('Cadastre o currículo antes de classificar as competências.');
        }

        $snapshot = $this->snapshots->strategicCompetency($user);

        if ($snapshot['payload']['candidate_concepts'] === []) {
            throw new LogicException('Não há competências suficientes para classificar.');
        }

        return $this->enqueue(
            $user,
            AiOperationType::StrategicCompetencyClassification,
            AiOperationType::StrategicCompetencyClassification->value.':user:'.$user->id,
            'user',
            $user->id,
            null,
            $snapshot,
        );
    }

    public function enqueueAtsScore(User $user, JobPosting $posting, ResumeLanguage $language = ResumeLanguage::PtBr, array $actorMetadata = []): AiOperation
    {
        Gate::forUser($user)->authorize('useAtsOptimization');
        Gate::forUser($user)->authorize('view', $posting);

        if ($posting->user_id !== $user->id) {
            throw new LogicException('Vaga não pertence ao usuário.');
        }

        if (! $posting->fresh()->isEligibleForApplicationDocuments()) {
            throw new LogicException('A simulação ATS exige uma vaga confirmada.');
        }

        $snapshot = $this->snapshots->atsScore($posting->fresh()->load('items'), $language);
        $snapshot = $this->documentSnapshot($user, $posting, $snapshot, $actorMetadata, AgentIntegrationScope::AtsWrite);

        return $this->enqueue(
            $user,
            AiOperationType::AtsScore,
            AiOperationType::AtsScore->value.':job_posting:'.$posting->id.':'.$language->value,
            'job_posting',
            $posting->id,
            $posting->id,
            $snapshot,
        );
    }

    public function enqueueAtsOptimization(User $user, JobPosting $posting, bool $enabled, ResumeLanguage $language = ResumeLanguage::PtBr, array $actorMetadata = []): AiOperation
    {
        Gate::forUser($user)->authorize('useAtsOptimization');
        Gate::forUser($user)->authorize('update', $posting);

        if ($posting->user_id !== $user->id) {
            throw new LogicException('Vaga não pertence ao usuário.');
        }

        if (! $posting->fresh()->isEligibleForApplicationDocuments()) {
            throw new LogicException('A otimização exige uma vaga confirmada.');
        }

        $snapshot = $this->snapshots->atsOptimization(
            $user,
            $posting->fresh()->load('items'),
            $enabled,
            $language,
        );

        if ($enabled && blank($snapshot['payload']['ats_prompt'] ?? null)) {
            throw new LogicException('Não há palavras-chave do currículo para otimizar esta vaga.');
        }

        $snapshot = $this->documentSnapshot($user, $posting, $snapshot, $actorMetadata, AgentIntegrationScope::AtsWrite);

        return $this->enqueue(
            $user,
            AiOperationType::AtsOptimization,
            AiOperationType::AtsOptimization->value.':job_posting:'.$posting->id.':'.$language->value,
            'job_posting',
            $posting->id,
            $posting->id,
            $snapshot,
        );
    }

    /** @param array{payload: array, fingerprint: string} $snapshot @param array{source?: string, integration_id?: int} $actor */
    private function documentSnapshot(User $user, ?JobPosting $posting, array $snapshot, array $actor, AgentIntegrationScope $scope): array
    {
        if (app()->bound(AgentIntegrationContext::class)) {
            $actor = ['source' => 'mcp', 'integration_id' => app(AgentIntegrationContext::class)->integration->id];
        }
        $access = app(ApplicationDocumentAccess::class);
        $access->authorize($user, $actor, $scope);
        $snapshot['payload']['document_guard'] = [
            'actor' => $actor, 'scope' => $scope->value,
            'version_hash' => $access->versionHash($user, $posting?->fresh()),
            'translation_source_hash' => $posting === null ? $access->sourceHash($user) : null,
        ];

        return $snapshot;
    }

    private function authorizeDocumentOperation(AiOperation $operation): void
    {
        if (! in_array($operation->operation_type, [AiOperationType::ResumeAdaptation, AiOperationType::ResumeTranslation, AiOperationType::AtsOptimization, AiOperationType::AtsScore], true)) {
            return;
        }
        $access = app(ApplicationDocumentAccess::class);
        $guard = $operation->input_snapshot['document_guard'] ?? [];
        $scope = $operation->operation_type === AiOperationType::ResumeTranslation ? AgentIntegrationScope::ResumeWrite : AgentIntegrationScope::AtsWrite;
        $access->authorize($operation->user, $guard['actor'] ?? [], $scope);
        $posting = $operation->jobPosting?->fresh();
        if ($posting !== null) {
            $access->posting($operation->user, $posting);
        }
        if ((isset($guard['version_hash']) && ! hash_equals($guard['version_hash'], $access->versionHash($operation->user, $posting)))
            || (isset($guard['translation_source_hash']) && ! hash_equals($guard['translation_source_hash'], $access->sourceHash($operation->user)))) {
            throw new LogicException('As fontes da operação mudaram. Solicite novamente.');
        }
    }

    public function enqueuePublicationExtraction(PageCapture $capture): AiOperation
    {
        $snapshot = [
            'payload' => [
                'page_capture_id' => $capture->id,
                'html_path' => $capture->html_path,
                'surface' => $capture->surface,
                'format_version' => $capture->format_version,
                'payload_hash' => $capture->payload_hash,
            ],
            'fingerprint' => hash('sha256', implode('|', [
                $capture->id,
                (string) $capture->html_path,
                (string) $capture->surface,
                (string) $capture->format_version,
                (string) $capture->payload_hash,
            ])),
        ];

        return $this->enqueue(
            $capture->user,
            AiOperationType::PublicationExtraction,
            AiOperationType::PublicationExtraction->value.':page_capture:'.$capture->id,
            'page_capture',
            $capture->id,
            null,
            $snapshot,
            failOnDispatchError: false,
        );
    }

    public function enqueuePublicationTriage(
        User $user,
        int $publicationId,
        int $contentVersion,
        bool $retry = false,
        bool $bypassClef = false,
        ?string $reason = null,
    ): AiOperation {
        if ($retry) {
            if ($bypassClef || filled($reason)) {
                throw new LogicException('Retry reutiliza a decisão e o motivo do snapshot original.');
            }

            return $this->retryPublicationTriage($user, $publicationId, $contentVersion);
        }

        if ($bypassClef && blank($reason)) {
            throw new LogicException('Informe motivo para ignorar a decisão Clef.');
        }

        if (! $bypassClef && filled($reason)) {
            throw new LogicException('Motivo só pode ser informado junto com bypass_clef=true.');
        }

        if ($reason !== null && mb_strlen(trim($reason)) > 500) {
            throw new LogicException('Motivo do bypass deve ter até 500 caracteres.');
        }

        $publication = Publication::query()
            ->where('user_id', $user->id)
            ->whereKey($publicationId)
            ->first();

        if ($publication === null) {
            throw new LogicException('Publicação não encontrada para este usuário.');
        }

        if ($publication->content_version !== $contentVersion) {
            throw new LogicException('A versão informada não é a versão atual da publicação.');
        }

        $operationKey = "user:{$user->id}:publication_triage:publication:{$publication->id}:version:{$contentVersion}";
        $completedOperation = AiOperation::query()
            ->where('user_id', $user->id)
            ->where('operation_key', $operationKey)
            ->where('operation_type', AiOperationType::PublicationTriage)
            ->where('target_type', 'publication')
            ->where('target_id', $publication->id)
            ->where('status', AiOperationStatus::Completed)
            ->first();

        if ($completedOperation !== null) {
            return $completedOperation;
        }

        if (! is_string($publication->content) || trim($publication->content) === '') {
            throw new LogicException('A publicação não possui conteúdo persistido suficiente; nova visita necessária.');
        }

        if (! is_string($publication->content_hash)
            || ! hash_equals($publication->content_hash, hash('sha256', $publication->content))
            || mb_strlen($publication->content) > (int) config('ai.publication_triage.max_content_characters', 100000)) {
            throw new LogicException('O conteúdo persistido da publicação é inválido; nova visita necessária.');
        }

        $clefResult = PublicationClefResult::query()
            ->where('publication_id', $publication->id)
            ->where('content_version', $publication->content_version)
            ->orderByDesc('attempt')
            ->first();

        if ($clefResult?->status === 'barred' && ! $bypassClef) {
            throw new LogicException('A publicação não está elegível segundo a decisão Clef atual.');
        }

        if ($clefResult?->status !== 'barred'
            && $clefResult?->status !== 'eligible'
            && ! ($bypassClef && $clefResult?->status === 'inconclusive')) {
            throw new LogicException('A decisão Clef atual não permite solicitar triagem.');
        }

        if ($clefResult?->status === 'eligible' && $bypassClef) {
            throw new LogicException('Bypass só se aplica a uma publicação barrada pela decisão Clef atual.');
        }

        $observation = $publication->observations()
            ->where('content_version', $publication->content_version)
            ->latest('observed_at')
            ->first();
        $evidenceBlockIds = $observation?->evidence_block_ids ?? [];

        $provider = (string) config('ai.publication_triage.provider');
        $model = (string) config('ai.publication_triage.model');
        $schemaVersion = (string) config('ai.publication_triage.schema_version');
        $snapshot = [
            'payload' => [
                'publication_id' => $publication->id,
                'content_version' => $publication->content_version,
                'content_hash' => $publication->content_hash,
                'url' => $publication->url,
                'content' => $publication->content,
                'evidence_block_ids' => $evidenceBlockIds,
                'provider' => $provider,
                'model' => $model,
                'schema_version' => $schemaVersion,
                'clef_result_id' => $clefResult->id,
                'clef_status' => $clefResult->status,
                'clef_bypassed' => $bypassClef,
                'clef_bypass_reason' => $bypassClef ? trim((string) $reason) : null,
                'requested_by' => $user->id,
            ],
        ];
        $snapshot['fingerprint'] = hash('sha256', json_encode($snapshot['payload'], JSON_THROW_ON_ERROR));

        return $this->enqueue(
            $user,
            AiOperationType::PublicationTriage,
            $operationKey,
            'publication',
            $publication->id,
            null,
            $snapshot,
            failOnDispatchError: false,
            idempotent: true,
            retry: $retry,
            allowTerminalReplacement: $bypassClef,
        );
    }

    public function retryPublicationClef(User $user, int $publicationId, int $contentVersion): PublicationClefResult
    {
        $publication = Publication::query()
            ->where('user_id', $user->id)
            ->whereKey($publicationId)
            ->first();

        if ($publication === null) {
            throw new LogicException('Publicação não encontrada para este usuário.');
        }

        if ($publication->content_version !== $contentVersion) {
            throw new LogicException('A versão informada não é a versão atual da publicação.');
        }

        $result = DB::transaction(function () use ($publication, $contentVersion): PublicationClefResult {
            $latest = PublicationClefResult::query()
                ->where('publication_id', $publication->id)
                ->where('content_version', $contentVersion)
                ->orderByDesc('attempt')
                ->lockForUpdate()
                ->first();

            if ($latest === null) {
                throw new LogicException('Não há classificação Clef para retry nesta versão da publicação.');
            }

            if (in_array($latest->status, ['pending', 'running'], true)) {
                return $latest;
            }

            if ($latest->status !== 'inconclusive') {
                throw new LogicException('Somente classificação Clef inconclusiva aceita retry explícito.');
            }

            return PublicationClefResult::query()->create([
                'publication_id' => $publication->id,
                'content_version' => $contentVersion,
                'attempt' => $latest->attempt + 1,
                'status' => 'pending',
            ]);
        });

        if ($result->status === 'pending' && $result->wasRecentlyCreated) {
            try {
                ClassifyPublicationWithClef::dispatch($result->id);
            } catch (Throwable $exception) {
                Log::channel('ai')->warning('Publication Clef retry dispatch failed; recovery will retry.', [
                    'result_id' => $result->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $result;
    }

    private function retryPublicationTriage(User $user, int $publicationId, int $contentVersion): AiOperation
    {
        $operationKey = "user:{$user->id}:publication_triage:publication:{$publicationId}:version:{$contentVersion}";
        $operation = AiOperation::query()
            ->where('user_id', $user->id)
            ->where('operation_key', $operationKey)
            ->where('operation_type', AiOperationType::PublicationTriage)
            ->where('target_type', 'publication')
            ->where('target_id', $publicationId)
            ->first();

        if ($operation === null) {
            throw new LogicException('Não há operação solicitada para retry nesta versão da publicação.');
        }

        $snapshotPayload = $operation->input_snapshot ?? [];

        if (($snapshotPayload['content_version'] ?? null) !== $contentVersion) {
            throw new LogicException('A versão informada não corresponde ao snapshot da operação.');
        }

        if (! is_string($snapshotPayload['content'] ?? null)
            || trim($snapshotPayload['content']) === ''
            || ! is_string($snapshotPayload['content_hash'] ?? null)
            || ! hash_equals($snapshotPayload['content_hash'], hash('sha256', $snapshotPayload['content']))) {
            throw new LogicException('O snapshot não contém publicação suficiente; nova visita necessária.');
        }

        if ($operation->isActive() || $operation->status === AiOperationStatus::Completed) {
            return $operation;
        }

        if ($operation->status !== AiOperationStatus::Failed) {
            throw new LogicException('Somente operação com falha aceita retry explícito.');
        }

        return $this->enqueue(
            $user,
            AiOperationType::PublicationTriage,
            $operationKey,
            'publication',
            $publicationId,
            null,
            [
                'payload' => $snapshotPayload,
                'fingerprint' => $operation->input_fingerprint,
            ],
            failOnDispatchError: false,
            idempotent: true,
            retry: true,
        );
    }

    /**
     * @param  array{payload: array<string, mixed>, fingerprint: string}  $snapshot
     */
    private function enqueue(
        User $user,
        AiOperationType $operationType,
        string $operationKey,
        string $targetType,
        int $targetId,
        ?int $jobPostingId,
        array $snapshot,
        bool $failOnDispatchError = true,
        bool $idempotent = false,
        bool $retry = false,
        bool $allowTerminalReplacement = false,
    ): AiOperation {
        $now = now();

        $lock = $this->enqueueLock($user->id, $operationType, $jobPostingId);

        if (! $lock->get()) {
            throw new LogicException('Outra operação de IA já está sendo iniciada para este alvo.');
        }

        try {
            $shouldDispatch = true;
            $operation = DB::transaction(function () use ($user, $operationType, $operationKey, $targetType, $targetId, $jobPostingId, $snapshot, $now, $idempotent, $retry, $allowTerminalReplacement, &$shouldDispatch): AiOperation {
                $operation = AiOperation::query()
                    ->where('operation_key', $operationKey)
                    ->lockForUpdate()
                    ->first();

                if ($idempotent && $operation !== null) {
                    $fingerprintMatches = hash_equals($operation->input_fingerprint, $snapshot['fingerprint']);
                    if ($operation->status === AiOperationStatus::Completed
                        || ($operation->isActive() && $fingerprintMatches)) {
                        $shouldDispatch = false;

                        return $operation;
                    }

                    if (! $fingerprintMatches
                        && (! $allowTerminalReplacement || $operation->isActive())) {
                        throw new LogicException('A operação existente tem snapshot diferente para esta publicação e versão.');
                    }

                    if ($fingerprintMatches && $operation->status === AiOperationStatus::Failed && ! $retry) {
                        $shouldDispatch = false;

                        return $operation;
                    }
                }

                if ($operation?->isActive()) {
                    throw new LogicException($this->inProgressMessage($operationType));
                }

                if ($jobPostingId !== null && in_array($operationType, [
                    AiOperationType::ResumeAdaptation,
                    AiOperationType::AtsOptimization,
                    AiOperationType::AtsScore,
                ], true)) {
                    $postingOperation = AiOperation::query()
                        ->where('job_posting_id', $jobPostingId)
                        ->where('operation_key', '!=', $operationKey)
                        ->active()
                        ->lockForUpdate()
                        ->first();

                    if ($postingOperation !== null) {
                        throw new LogicException('Outra operação de IA já está em andamento para esta vaga.');
                    }
                }

                $attributes = [
                    'user_id' => $user->id,
                    'operation_key' => $operationKey,
                    'operation_type' => $operationType,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'job_posting_id' => $jobPostingId,
                    'run_token' => (string) Str::uuid(),
                    'attempt_token' => (string) Str::uuid(),
                    'status' => AiOperationStatus::Queued,
                    'attempts' => in_array($operationType, [AiOperationType::ProfileImport, AiOperationType::Matching], true) ? ($operation?->attempts ?? 0) : 0,
                    'queued_at' => $now,
                    'started_at' => null,
                    'last_attempt_started_at' => null,
                    'completed_at' => null,
                    'failed_at' => null,
                    'expires_at' => $now->copy()->addMinutes($this->queueTtlMinutes($operationType)),
                    'input_snapshot' => $snapshot['payload'],
                    'input_fingerprint' => $snapshot['fingerprint'],
                    'failure_code' => null,
                    'notified_at' => null,
                ];

                if ($operation === null) {
                    return AiOperation::create($attributes);
                }

                $operation->fill($attributes);
                $operation->save();

                return $operation;
            });
        } finally {
            $lock->release();
        }

        if (! $shouldDispatch) {
            return $operation;
        }

        try {
            $pendingDispatch = ProcessAiOperation::dispatch(
                $operation->id,
                $operation->run_token,
                $operation->attempt_token,
            );
            unset($pendingDispatch);
        } catch (Throwable $exception) {
            if (! $failOnDispatchError) {
                Log::channel('ai')->warning(match ($operationType) {
                    AiOperationType::ProfileImport => 'Profile import dispatch failed; recovery will retry.',
                    AiOperationType::Matching => 'Matching dispatch failed; recovery will retry.',
                    AiOperationType::PublicationTriage => 'Publication triage dispatch failed; recovery will retry.',
                    default => 'Publication extraction dispatch failed; recovery will retry.',
                }, [
                    'operation_id' => $operation->id,
                    'error' => $exception->getMessage(),
                ]);

                return $operation;
            }

            $this->fail(
                $operation->id,
                $operation->run_token,
                $exception,
                $operation->attempt_token,
            );

            throw $exception;
        }

        return $operation;
    }

    private function enqueueLock(int $userId, AiOperationType $operationType, ?int $jobPostingId): mixed
    {
        $store = config('cache.lock_store', config('cache.default', 'file'));
        $stores = config('cache.stores', []);

        if (! isset($stores[$store])) {
            $store = 'file';
        }

        $key = $jobPostingId === null
            ? "ai-operation:user:{$userId}:{$operationType->value}"
            : "ai-operation:posting:{$jobPostingId}";

        return Cache::store($store)->lock($key, 10);
    }

    private function queueTtlMinutes(AiOperationType $operationType): int
    {
        if ($operationType === AiOperationType::PublicationExtraction) {
            return max(1, (int) config('capture.ingestion.publication_extraction_ttl_minutes', 60));
        }

        if ($operationType === AiOperationType::PublicationTriage) {
            return 5;
        }

        return 5;
    }

    private function inProgressMessage(AiOperationType $operationType): string
    {
        return match ($operationType) {
            AiOperationType::ResumeAdaptation => 'Adequação já está em andamento para esta vaga.',
            AiOperationType::ResumeTranslation => 'Tradução já está em andamento para este usuário.',
            AiOperationType::StrategicCompetencyClassification => 'Classificação já está em andamento para este usuário.',
            AiOperationType::AtsOptimization => 'Atualização da otimização ATS já está em andamento para esta vaga.',
            AiOperationType::AtsScore => 'Simulação ATS já está em andamento para esta vaga.',
            AiOperationType::PublicationExtraction => 'A extração de publicações já está em andamento para esta captura.',
            AiOperationType::PublicationTriage => 'A triagem da publicação já está em andamento.',
            AiOperationType::ProfileImport => 'A importação do currículo já está em andamento.',
            AiOperationType::Matching => 'O matching já está em andamento.',
        };
    }

    public function process(int $operationId, string $runToken, ?string &$attemptToken = null): void
    {
        $operation = DB::transaction(function () use ($operationId, $runToken, &$attemptToken): ?AiOperation {
            $reclaimedRunningAttempt = false;
            $operation = AiOperation::query()
                ->whereKey($operationId)
                ->where('run_token', $runToken)
                ->lockForUpdate()
                ->first();

            if ($operation === null || ! $operation->status->isActive()) {
                return null;
            }

            if ($operation->expires_at->lte(now())
                && ($operation->status === AiOperationStatus::Queued || $this->isRunningAttemptStale($operation))) {
                $operation->forceFill([
                    'status' => AiOperationStatus::Failed,
                    'failed_at' => now(),
                    'failure_code' => 'worker_unavailable',
                ])->save();
                $this->recordPublicationTriageFailure($operation, 'worker_unavailable');

                return $operation->fresh(['user']);
            }

            if ($operation->status === AiOperationStatus::Queued
                && $attemptToken !== null
                && ($operation->attempt_token === null || ! hash_equals($operation->attempt_token, $attemptToken))) {
                return null;
            }

            if ($operation->status === AiOperationStatus::Running) {
                if (! $this->isRunningAttemptStale($operation)) {
                    return null;
                }

                if ($operation->attempts >= self::MAX_ATTEMPTS) {
                    $operation->forceFill([
                        'status' => AiOperationStatus::Failed,
                        'failed_at' => now(),
                        'failure_code' => 'processing_failed',
                    ])->save();
                    $this->recordPublicationTriageFailure($operation, 'processing_failed');

                    return $operation->fresh(['user']);
                }

                $reclaimedRunningAttempt = true;
                $operation->forceFill(['status' => AiOperationStatus::Queued])->save();
            }

            if ($operation->attempts >= self::MAX_ATTEMPTS) {
                $operation->forceFill([
                    'status' => AiOperationStatus::Failed,
                    'failed_at' => now(),
                    'failure_code' => 'processing_failed',
                ])->save();
                $this->recordPublicationTriageFailure($operation, 'processing_failed');

                return $operation->fresh(['user']);
            }

            $canReuseAttemptToken = $operation->status === AiOperationStatus::Queued
                && ! $reclaimedRunningAttempt
                && $attemptToken !== null
                && $operation->attempt_token !== null
                && hash_equals($operation->attempt_token, $attemptToken);
            $attemptToken = $canReuseAttemptToken
                ? $attemptToken
                : (string) Str::uuid();

            $operation->forceFill([
                'status' => AiOperationStatus::Running,
                'attempt_token' => $attemptToken,
                'attempts' => $operation->attempts + 1,
                'started_at' => $operation->started_at ?? now(),
                'last_attempt_started_at' => now(),
            ])->save();

            return $operation->fresh(['user', 'jobPosting']);
        });

        if ($operation === null) {
            return;
        }

        if (! $operation->status->isActive()) {
            if ($operation->status === AiOperationStatus::Completed) {
                $this->notifyCompleted($operation);
            } elseif ($operation->status === AiOperationStatus::Failed) {
                $this->notifyFailed($operation);
            }

            return;
        }

        if ($attemptToken === null) {
            throw new LogicException('A tentativa da operação não recebeu um token.');
        }

        $ownedAttemptToken = $attemptToken;

        try {
            $this->authorizeDocumentOperation($operation);
            match ($operation->operation_type) {
                AiOperationType::ResumeAdaptation => $this->processResumeAdaptation($operation, $runToken, $ownedAttemptToken),
                AiOperationType::ResumeTranslation => $this->processResumeTranslation($operation, $runToken, $ownedAttemptToken),
                AiOperationType::StrategicCompetencyClassification => $this->processStrategicCompetency($operation, $runToken, $ownedAttemptToken),
                AiOperationType::AtsOptimization => $this->processAtsOptimization($operation, $runToken, $ownedAttemptToken),
                AiOperationType::AtsScore => $this->processAtsScore($operation, $runToken, $ownedAttemptToken),
                AiOperationType::PublicationExtraction => $this->processPublicationExtraction($operation, $runToken, $ownedAttemptToken),
                AiOperationType::PublicationTriage => $this->processPublicationTriage($operation, $runToken, $ownedAttemptToken),
                AiOperationType::ProfileImport => $this->profileImport->process($operation, $runToken, $ownedAttemptToken,
                    fn (Closure $persist): mixed => $this->persistForAttempt($operation->id, $runToken, $ownedAttemptToken, $persist)),
                AiOperationType::Matching => $this->matching->process($operation, $runToken, $ownedAttemptToken,
                    fn (Closure $persist): mixed => $this->persistForAttempt($operation->id, $runToken, $ownedAttemptToken, $persist)),
            };
        } catch (MatchingException $exception) {
            AiOperation::query()->whereKey($operation->id)->where('run_token', $runToken)->where('attempt_token', $ownedAttemptToken)
                ->where('status', AiOperationStatus::Running)->update([
                    'status' => AiOperationStatus::Failed->value, 'failed_at' => now(), 'failure_code' => $exception->failureCode,
                ]);

            return;
        } catch (ProfileImportException $exception) {
            $updated = AiOperation::query()->whereKey($operation->id)->where('run_token', $runToken)->where('attempt_token', $ownedAttemptToken)
                ->where('status', AiOperationStatus::Running)->update([
                    'status' => AiOperationStatus::Failed->value, 'failed_at' => now(), 'failure_code' => $exception->failureCode,
                ]);

            if ($updated === 1) {
                $this->notifyFailed($operation->fresh(['user']));
            }

            return;
        } catch (PublicationTriageInvalidOutput $exception) {
            $this->persistForAttempt(
                $operation->id,
                $runToken,
                $ownedAttemptToken,
                function () use ($operation, $runToken, $ownedAttemptToken, $exception): void {
                    $snapshot = $operation->input_snapshot ?? [];
                    PublicationTriageResult::query()->create([
                        'ai_operation_id' => $operation->id,
                        'publication_id' => $snapshot['publication_id'],
                        'content_version' => $snapshot['content_version'],
                        'run_token' => $runToken,
                        'attempt_token' => $ownedAttemptToken,
                        'attempt' => $operation->attempts,
                        'status' => 'failed',
                        'provider' => $snapshot['provider'],
                        'model' => $snapshot['model'],
                        'schema_version' => $snapshot['schema_version'],
                        'input_fingerprint' => $operation->input_fingerprint,
                        'prompt_tokens' => $exception->promptTokens,
                        'completion_tokens' => $exception->completionTokens,
                        'error_code' => 'invalid_output',
                        ...$this->publicationTriageAuditFields($snapshot),
                    ]);

                    AiOperation::query()
                        ->whereKey($operation->id)
                        ->where('run_token', $runToken)
                        ->where('attempt_token', $ownedAttemptToken)
                        ->where('status', AiOperationStatus::Running->value)
                        ->update([
                            'status' => AiOperationStatus::Failed->value,
                            'failed_at' => now(),
                            'failure_code' => 'invalid_output',
                        ]);
                },
            );

            $failedOperation = AiOperation::query()
                ->whereKey($operation->id)
                ->where('run_token', $runToken)
                ->where('attempt_token', $ownedAttemptToken)
                ->where('status', AiOperationStatus::Failed->value)
                ->first();

            if ($failedOperation !== null) {
                $snapshot = $failedOperation->input_snapshot ?? [];
                Log::channel('ai')->warning('Publication triage output validation failed.', [
                    'operation_id' => $failedOperation->id,
                    'publication_id' => $snapshot['publication_id'],
                    'content_version' => $snapshot['content_version'],
                    'provider' => $snapshot['provider'],
                    'model' => $snapshot['model'],
                    ...$exception->diagnostic(),
                ]);
                $this->notifyFailed($failedOperation->fresh(['user']));
            }

            return;
        } catch (PublicationExtractionException $exception) {
            $failed = AiOperation::query()
                ->whereKey($operation->id)
                ->where('run_token', $runToken)
                ->where('attempt_token', $ownedAttemptToken)
                ->where('status', AiOperationStatus::Running)
                ->update([
                    'status' => AiOperationStatus::Failed->value,
                    'failed_at' => now(),
                    'failure_code' => $exception->failureCode,
                ]);

            if ($failed === 1) {
                $operation->forceFill([
                    'status' => AiOperationStatus::Failed,
                    'failed_at' => now(),
                    'failure_code' => $exception->failureCode,
                ]);
                $this->notifyFailed($operation);
            }

            return;
        } catch (Throwable $exception) {
            AiOperation::query()
                ->whereKey($operation->id)
                ->where('run_token', $runToken)
                ->where('attempt_token', $ownedAttemptToken)
                ->where('status', AiOperationStatus::Running)
                ->update([
                    'status' => AiOperationStatus::Queued->value,
                    'failure_code' => 'retrying',
                ]);

            throw $exception;
        }

        $completed = AiOperation::query()
            ->whereKey($operation->id)
            ->where('run_token', $runToken)
            ->where('attempt_token', $ownedAttemptToken)
            ->where('status', AiOperationStatus::Running)
            ->update([
                'status' => AiOperationStatus::Completed->value,
                'completed_at' => now(),
                'failure_code' => null,
            ]);

        if ($completed === 1) {
            $operation->forceFill([
                'status' => AiOperationStatus::Completed,
                'completed_at' => now(),
            ]);
            $this->notifyCompleted($operation);
        } else {
            $terminalOperation = AiOperation::query()
                ->whereKey($operation->id)
                ->where('run_token', $runToken)
                ->where('attempt_token', $ownedAttemptToken)
                ->first();

            if ($terminalOperation?->status === AiOperationStatus::Completed) {
                $this->notifyCompleted($terminalOperation->fresh(['user']));
            } elseif ($terminalOperation?->status === AiOperationStatus::Failed) {
                $this->notifyFailed($terminalOperation->fresh(['user']));
            }
        }
    }

    private function processResumeAdaptation(AiOperation $operation, string $runToken, string $attemptToken): void
    {
        if ($operation->user === null || $operation->jobPosting === null) {
            throw new LogicException('O alvo da adequação não está disponível.');
        }

        $this->resumeAdaptation->adaptFromSnapshot(
            $operation->user,
            $operation->jobPosting,
            $operation->input_snapshot ?? [],
            $operation->input_fingerprint,
            fn (Closure $persist): mixed => $this->persistForAttempt(
                $operation->id,
                $runToken,
                $attemptToken,
                $persist,
            ),
        );
    }

    private function processResumeTranslation(AiOperation $operation, string $runToken, string $attemptToken): void
    {
        if ($operation->user === null) {
            throw new LogicException('O usuário da tradução não está disponível.');
        }

        $this->resumeTranslation->translateFromSnapshot(
            $operation->user,
            $operation->input_snapshot ?? [],
            $operation->input_fingerprint,
            fn (Closure $persist): mixed => $this->persistForAttempt(
                $operation->id,
                $runToken,
                $attemptToken,
                $persist,
            ),
        );
    }

    private function processStrategicCompetency(AiOperation $operation, string $runToken, string $attemptToken): void
    {
        if ($operation->user === null) {
            throw new LogicException('O usuário da classificação não está disponível.');
        }

        $this->strategicCompetency->classifyFromSnapshot(
            $operation->user,
            $operation->input_snapshot ?? [],
            $operation->input_fingerprint,
            fn (Closure $persist): mixed => $this->persistForAttempt(
                $operation->id,
                $runToken,
                $attemptToken,
                $persist,
            ),
        );
    }

    private function processAtsScore(AiOperation $operation, string $runToken, string $attemptToken): void
    {
        if ($operation->user === null || $operation->jobPosting === null) {
            throw new LogicException('O alvo da simulação ATS não está disponível.');
        }

        $this->atsScorer->scoreFromSnapshot(
            $operation->user,
            $operation->jobPosting,
            $operation->input_snapshot ?? [],
            $operation->input_fingerprint,
            fn (Closure $persist): mixed => $this->persistForAttempt(
                $operation->id,
                $runToken,
                $attemptToken,
                $persist,
            ),
        );
    }

    private function processAtsOptimization(AiOperation $operation, string $runToken, string $attemptToken): void
    {
        if ($operation->user === null || $operation->jobPosting === null) {
            throw new LogicException('O alvo da otimização ATS não está disponível.');
        }

        $this->resumeAdaptation->setAtsOptimizationFromSnapshot(
            $operation->user,
            $operation->jobPosting,
            $operation->input_snapshot ?? [],
            $operation->input_fingerprint,
            fn (Closure $persist): mixed => $this->persistForAttempt(
                $operation->id,
                $runToken,
                $attemptToken,
                $persist,
            ),
        );
    }

    private function processPublicationExtraction(AiOperation $operation, string $runToken, string $attemptToken): void
    {
        if ($operation->user === null) {
            throw new LogicException('O usuário da extração de publicações não está disponível.');
        }

        $this->publicationExtraction->extractFromSnapshot(
            $operation->user,
            $operation->input_snapshot ?? [],
            fn (Closure $persist): mixed => $this->persistForAttempt(
                $operation->id,
                $runToken,
                $attemptToken,
                $persist,
            ),
        );
    }

    private function processPublicationTriage(AiOperation $operation, string $runToken, string $attemptToken): void
    {
        if ($operation->user === null) {
            throw new LogicException('O usuário da triagem não está disponível.');
        }

        $this->publicationTriage->process(
            $operation->user,
            $operation,
            $operation->input_snapshot ?? [],
            $runToken,
            $attemptToken,
            fn (Closure $persist): mixed => $this->persistForAttempt(
                $operation->id,
                $runToken,
                $attemptToken,
                $persist,
            ),
        );
    }

    /**
     * Keep the result write behind the attempt lease so a recovered worker
     * cannot be overwritten by the worker that lost that lease.
     *
     * @param  Closure(): mixed  $persist
     */
    private function persistForAttempt(int $operationId, string $runToken, string $attemptToken, Closure $persist): mixed
    {
        return DB::transaction(function () use ($operationId, $runToken, $attemptToken, $persist): mixed {
            $ownsAttempt = AiOperation::query()
                ->whereKey($operationId)
                ->where('run_token', $runToken)
                ->where('attempt_token', $attemptToken)
                ->where('status', AiOperationStatus::Running->value)
                ->lockForUpdate()
                ->exists();

            if (! $ownsAttempt) {
                throw new LogicException('A tentativa da operação não é mais válida.');
            }

            $this->authorizeDocumentOperation(AiOperation::query()->findOrFail($operationId));

            return $persist();
        });
    }

    public function fail(
        int $operationId,
        string $runToken,
        ?Throwable $exception = null,
        ?string $attemptToken = null,
        ?int $queueAttempt = null,
    ): void {
        $operation = DB::transaction(function () use ($operationId, $runToken, $attemptToken, $queueAttempt, $exception): ?AiOperation {
            $operation = AiOperation::query()
                ->whereKey($operationId)
                ->where('run_token', $runToken)
                ->lockForUpdate()
                ->first();

            if ($operation === null || ! $operation->status->isActive()) {
                return null;
            }

            $tokenMatches = $attemptToken !== null
                && $operation->attempt_token !== null
                && hash_equals($operation->attempt_token, $attemptToken);
            $legacyQueueAttemptMatches = $attemptToken === null
                && $queueAttempt !== null
                && (
                    ($operation->status === AiOperationStatus::Queued && $operation->attempts === 0)
                    || ($operation->attempt_token === null && $operation->attempts === $queueAttempt)
                );
            $queueExhaustionMatches = $attemptToken === null
                && $operation->attempt_token === null
                && $queueAttempt !== null
                && $queueAttempt >= self::MAX_ATTEMPTS
                && $operation->attempts >= self::MAX_ATTEMPTS
                && (
                    $operation->status === AiOperationStatus::Queued
                    || ($operation->status === AiOperationStatus::Running && $exception instanceof TimeoutExceededException)
                );

            if (! $tokenMatches && ! $legacyQueueAttemptMatches && ! $queueExhaustionMatches) {
                return null;
            }

            $operation->forceFill([
                'status' => AiOperationStatus::Failed,
                'failed_at' => now(),
                'failure_code' => 'processing_failed',
            ])->save();

            $this->recordPublicationTriageFailure($operation, 'processing_failed');

            return $operation->fresh(['user']);
        });

        if ($exception !== null) {
            Log::channel('ai')->error('Background AI operation failed.', [
                'operation_id' => $operationId,
                'error' => $exception->getMessage(),
            ]);
        }

        if ($operation !== null) {
            $this->notifyFailed($operation);
        }
    }

    private function isRunningAttemptStale(AiOperation $operation): bool
    {
        if ($operation->status !== AiOperationStatus::Running) {
            return false;
        }

        $lastAttemptStartedAt = $operation->last_attempt_started_at ?? $operation->started_at;

        return $lastAttemptStartedAt !== null
            && $lastAttemptStartedAt->lte(now()->subSeconds(self::RUNNING_STALE_AFTER_SECONDS));
    }

    private function recordPublicationTriageFailure(AiOperation $operation, string $errorCode): void
    {
        if ($operation->operation_type !== AiOperationType::PublicationTriage || $operation->attempt_token === null) {
            return;
        }

        $snapshot = $operation->input_snapshot ?? [];

        PublicationTriageResult::query()->firstOrCreate([
            'ai_operation_id' => $operation->id,
            'run_token' => $operation->run_token,
            'attempt_token' => $operation->attempt_token,
        ], [
            'publication_id' => $snapshot['publication_id'],
            'content_version' => $snapshot['content_version'],
            'attempt' => $operation->attempts,
            'status' => 'failed',
            'provider' => $snapshot['provider'],
            'model' => $snapshot['model'],
            'schema_version' => $snapshot['schema_version'],
            'input_fingerprint' => $operation->input_fingerprint,
            'error_code' => $errorCode,
            ...$this->publicationTriageAuditFields($snapshot),
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function publicationTriageAuditFields(array $snapshot): array
    {
        return [
            'clef_result_id' => $snapshot['clef_result_id'] ?? null,
            'clef_status' => $snapshot['clef_status'] ?? null,
            'clef_bypassed' => $snapshot['clef_bypassed'] ?? false,
            'clef_bypass_reason' => $snapshot['clef_bypass_reason'] ?? null,
            'requested_by' => $snapshot['requested_by'] ?? null,
        ];
    }

    public function expireWaiting(): int
    {
        $operations = AiOperation::query()
            ->whereIn('status', [AiOperationStatus::Queued, AiOperationStatus::Running])
            ->where('expires_at', '<=', now())
            ->get(['id', 'run_token']);
        $expired = 0;

        foreach ($operations as $operation) {
            if ($this->expire($operation->id, $operation->run_token)) {
                $expired++;
            }
        }

        $this->retryPendingTerminalNotifications();

        return $expired;
    }

    private function retryPendingTerminalNotifications(): void
    {
        $operations = AiOperation::query()
            ->whereIn('status', [
                AiOperationStatus::Completed->value,
                AiOperationStatus::Failed->value,
            ])
            ->whereNull('notified_at')
            ->orderBy('id')
            ->get();

        foreach ($operations as $operation) {
            if ($operation->status === AiOperationStatus::Completed) {
                $this->notifyCompleted($operation);
            } else {
                $this->notifyFailed($operation);
            }
        }
    }

    public function expire(int $operationId, string $runToken): bool
    {
        $operation = DB::transaction(function () use ($operationId, $runToken): ?AiOperation {
            $operation = AiOperation::query()
                ->whereKey($operationId)
                ->where('run_token', $runToken)
                ->whereIn('status', [AiOperationStatus::Queued, AiOperationStatus::Running])
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->first();

            if ($operation === null
                || ($operation->status === AiOperationStatus::Running && ! $this->isRunningAttemptStale($operation))) {
                return null;
            }

            $operation->forceFill([
                'status' => AiOperationStatus::Failed,
                'failed_at' => now(),
                'failure_code' => 'worker_unavailable',
            ])->save();
            $this->recordPublicationTriageFailure($operation, 'worker_unavailable');

            return $operation->fresh(['user']);
        });

        if ($operation === null) {
            return false;
        }

        $this->notifyFailed($operation);

        return true;
    }

    private function notifyCompleted(AiOperation $operation): void
    {
        [$title, $body] = match ($operation->operation_type) {
            AiOperationType::ResumeAdaptation => [
                'Adequação concluída',
                'A versão adaptada do currículo está disponível para esta vaga.',
            ],
            AiOperationType::ResumeTranslation => [
                'Tradução concluída',
                'A versão em inglês do seu currículo está disponível.',
            ],
            AiOperationType::StrategicCompetencyClassification => [
                'Classificação estratégica concluída',
                'As competências estratégicas do seu perfil foram atualizadas.',
            ],
            AiOperationType::AtsOptimization => [
                'Otimização ATS concluída',
                'A configuração de otimização da vaga foi atualizada.',
            ],
            AiOperationType::AtsScore => [
                'Simulação ATS concluída',
                'A nota da simulação ATS está disponível na candidatura.',
            ],
            AiOperationType::PublicationExtraction => [
                'Extração de publicações concluída',
                'As publicações da captura estão disponíveis para consulta.',
            ],
            AiOperationType::PublicationTriage => [
                'Triagem de publicação concluída',
                'O resultado estruturado está disponível para consulta.',
            ],
            AiOperationType::ProfileImport => ['Proposta de perfil disponível', 'Revise os fatos e as evidências antes de confirmar.'],
            AiOperationType::Matching => ['Matching disponível', 'Consulte critérios, evidências e incertezas.'],
        };

        $this->sendTerminalNotification($operation, true, $title, $body);
    }

    private function notifyFailed(AiOperation $operation): void
    {
        [$title, $body] = match ($operation->operation_type) {
            AiOperationType::ResumeAdaptation => [
                'Falha na adequação',
                'Não foi possível gerar a versão adaptada. Tente novamente.',
            ],
            AiOperationType::ResumeTranslation => [
                'Falha na tradução',
                'Não foi possível gerar a versão em inglês do currículo. Tente novamente.',
            ],
            AiOperationType::StrategicCompetencyClassification => [
                'Falha na classificação estratégica',
                'Não foi possível atualizar as competências estratégicas. Tente novamente.',
            ],
            AiOperationType::AtsOptimization => [
                'Falha na otimização ATS',
                'Não foi possível atualizar a otimização ATS. Tente novamente.',
            ],
            AiOperationType::AtsScore => [
                'Falha na simulação ATS',
                'Não foi possível simular a nota ATS. Tente novamente.',
            ],
            AiOperationType::PublicationExtraction => [
                'Falha na extração de publicações',
                'Não foi possível processar as publicações desta captura.',
            ],
            AiOperationType::PublicationTriage => [
                'Falha na triagem da publicação',
                'Não foi possível estruturar esta publicação. Tente novamente.',
            ],
            AiOperationType::ProfileImport => ['Falha na importação do currículo', 'Consulte a operação antes de tentar novamente.'],
            AiOperationType::Matching => ['Falha no matching', 'Consulte a operação antes de tentar novamente.'],
        };

        $this->sendTerminalNotification($operation, false, $title, $body);
    }

    private function sendTerminalNotification(
        AiOperation $operation,
        bool $successful,
        string $title,
        string $body,
    ): void {
        try {
            $user = DB::transaction(function () use ($operation, $successful, $title, $body): ?User {
                $current = AiOperation::query()
                    ->with('user')
                    ->whereKey($operation->id)
                    ->where('run_token', $operation->run_token)
                    ->where('status', $operation->status->value)
                    ->whereNull('notified_at')
                    ->lockForUpdate()
                    ->first();

                if ($current === null || $current->user === null) {
                    return null;
                }

                if ($current->user->notifications()->whereKey($operation->run_token)->exists()) {
                    $current->forceFill(['notified_at' => now()])->save();

                    return $current->user;
                }

                $notification = Notification::make($operation->run_token)
                    ->title($title)
                    ->body($body);

                if ($successful) {
                    $notification->success();
                } else {
                    $notification->danger();
                }

                [$linkUrl, $linkLabel] = $this->notificationLinkFor($current);
                if (filled($linkUrl) && filled($linkLabel)) {
                    $notification->actions([
                        Action::make('view')->label($linkLabel)->url($linkUrl)->markAsRead(),
                    ]);
                }

                // Filament queues its database notification; insert it directly so
                // the durable notification and terminal marker commit together.
                $databaseNotification = $notification->toDatabase();
                $current->user->notifications()->create([
                    'id' => $operation->run_token,
                    'type' => $databaseNotification::class,
                    'data' => $databaseNotification->toArray(),
                    'read_at' => null,
                ]);
                $current->forceFill(['notified_at' => now()])->save();

                return $current->user;
            });
        } catch (Throwable $exception) {
            Log::channel('ai')->warning('Terminal AI notification persistence failed.', [
                'operation_id' => $operation->id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if ($user === null) {
            return;
        }

        try {
            DatabaseNotificationsSent::dispatch($user);
        } catch (Throwable $exception) {
            Log::channel('ai')->warning('Terminal AI notification broadcast failed.', [
                'operation_id' => $operation->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function notificationLinkFor(AiOperation $operation): array
    {
        return match ($operation->operation_type) {
            AiOperationType::ResumeAdaptation,
            AiOperationType::AtsOptimization,
            AiOperationType::AtsScore => $operation->job_posting_id
                ? [JobPostingResource::getUrl('application', ['record' => $operation->job_posting_id]), 'Ver candidatura']
                : [null, null],
            AiOperationType::StrategicCompetencyClassification => [ProfessionalCompetenciesPage::getUrl(), 'Ver competências'],
            AiOperationType::ResumeTranslation => [ResumePage::getUrl(), 'Ver currículo'],
            AiOperationType::PublicationExtraction,
            AiOperationType::PublicationTriage => [route('filament.admin.resources.page-captures.index'), 'Ver capturas de página'],
            AiOperationType::ProfileImport => [ProfessionalProfilePage::getUrl(), 'Revisar perfil profissional'],
            AiOperationType::Matching => $operation->target_id
                ? [JobPostingResource::getUrl('matching', ['record' => $operation->target_id]), 'Ver matching']
                : [route('filament.admin.pages.ai-operations'), 'Ver operação'],
        };
    }
}
