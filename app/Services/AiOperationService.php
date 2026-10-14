<?php

namespace App\Services;

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Enums\JobPostingStatus;
use App\Enums\ResumeLanguage;
use App\Filament\Pages\ProfessionalCompetenciesPage;
use App\Filament\Pages\ResumePage;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Jobs\ProcessAiOperation;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
    ) {}

    public function enqueueResumeAdaptation(User $user, JobPosting $posting, ResumeLanguage $language = ResumeLanguage::PtBr): AiOperation
    {
        Gate::forUser($user)->authorize('update', $posting);

        if ($posting->user_id !== $user->id) {
            throw new LogicException('Vaga não pertence ao usuário.');
        }

        if ($posting->status !== JobPostingStatus::Confirmed) {
            throw new LogicException('A adequação exige uma vaga confirmada.');
        }

        if (blank($this->resumeService->content($user, $language))) {
            throw new LogicException($language->missingResumeGuidance());
        }

        $snapshot = $this->snapshots->resumeAdaptation($user, $posting->fresh()->load('items'), $language);

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

    public function enqueueResumeTranslation(User $user): AiOperation
    {
        if (blank($this->resumeService->content($user, ResumeLanguage::PtBr))) {
            throw new LogicException('Cadastre o currículo em português antes de gerar a versão em inglês.');
        }

        $snapshot = $this->snapshots->resumeTranslation($user);

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

    public function enqueueAtsScore(User $user, JobPosting $posting, ResumeLanguage $language = ResumeLanguage::PtBr): AiOperation
    {
        Gate::forUser($user)->authorize('useAtsOptimization');
        Gate::forUser($user)->authorize('view', $posting);

        if ($posting->user_id !== $user->id) {
            throw new LogicException('Vaga não pertence ao usuário.');
        }

        if ($posting->status !== JobPostingStatus::Confirmed) {
            throw new LogicException('A simulação ATS exige uma vaga confirmada.');
        }

        $snapshot = $this->snapshots->atsScore($posting->fresh()->load('items'), $language);

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

    public function enqueueAtsOptimization(User $user, JobPosting $posting, bool $enabled, ResumeLanguage $language = ResumeLanguage::PtBr): AiOperation
    {
        Gate::forUser($user)->authorize('useAtsOptimization');
        Gate::forUser($user)->authorize('update', $posting);

        if ($posting->user_id !== $user->id) {
            throw new LogicException('Vaga não pertence ao usuário.');
        }

        if ($posting->status !== JobPostingStatus::Confirmed) {
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
    ): AiOperation {
        $now = now();

        $lock = $this->enqueueLock($user->id, $operationType, $jobPostingId);

        if (! $lock->get()) {
            throw new LogicException('Outra operação de IA já está sendo iniciada para este alvo.');
        }

        try {
            $operation = DB::transaction(function () use ($user, $operationType, $operationKey, $targetType, $targetId, $jobPostingId, $snapshot, $now): AiOperation {
                $operation = AiOperation::query()
                    ->where('operation_key', $operationKey)
                    ->lockForUpdate()
                    ->first();

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
                    'attempts' => 0,
                    'queued_at' => $now,
                    'started_at' => null,
                    'last_attempt_started_at' => null,
                    'completed_at' => null,
                    'failed_at' => null,
                    'expires_at' => $now->copy()->addMinutes(5),
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

        try {
            $pendingDispatch = ProcessAiOperation::dispatch(
                $operation->id,
                $operation->run_token,
                $operation->attempt_token,
            );
            unset($pendingDispatch);
        } catch (Throwable $exception) {
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

    private function inProgressMessage(AiOperationType $operationType): string
    {
        return match ($operationType) {
            AiOperationType::ResumeAdaptation => 'Adequação já está em andamento para esta vaga.',
            AiOperationType::ResumeTranslation => 'Tradução já está em andamento para este usuário.',
            AiOperationType::StrategicCompetencyClassification => 'Classificação já está em andamento para este usuário.',
            AiOperationType::AtsOptimization => 'Atualização da otimização ATS já está em andamento para esta vaga.',
            AiOperationType::AtsScore => 'Simulação ATS já está em andamento para esta vaga.',
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

            if ($operation->status === AiOperationStatus::Queued && $operation->expires_at->lte(now())) {
                $operation->forceFill([
                    'status' => AiOperationStatus::Failed,
                    'failed_at' => now(),
                    'failure_code' => 'worker_unavailable',
                ])->save();

                return $operation->fresh(['user']);
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
            match ($operation->operation_type) {
                AiOperationType::ResumeAdaptation => $this->processResumeAdaptation($operation, $runToken, $ownedAttemptToken),
                AiOperationType::ResumeTranslation => $this->processResumeTranslation($operation, $runToken, $ownedAttemptToken),
                AiOperationType::StrategicCompetencyClassification => $this->processStrategicCompetency($operation, $runToken, $ownedAttemptToken),
                AiOperationType::AtsOptimization => $this->processAtsOptimization($operation, $runToken, $ownedAttemptToken),
                AiOperationType::AtsScore => $this->processAtsScore($operation, $runToken, $ownedAttemptToken),
            };
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
                    $operation->attempts === $queueAttempt
                    || ($operation->status === AiOperationStatus::Queued && $operation->attempts === 0)
                );
            $queueExhaustionMatches = $queueAttempt !== null
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

    public function expireWaiting(): int
    {
        $operations = AiOperation::query()
            ->where('status', AiOperationStatus::Queued)
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
                ->where('status', AiOperationStatus::Queued)
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->first();

            if ($operation === null) {
                return null;
            }

            $operation->forceFill([
                'status' => AiOperationStatus::Failed,
                'failed_at' => now(),
                'failure_code' => 'worker_unavailable',
            ])->save();

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
        };
    }
}
