<?php

namespace App\Jobs;

use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\JobAnalysisService;
use App\Support\Ai\FriendlyAiErrorMapper;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeJobPosting implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * Seconds the job may run before timing out.
     * Must exceed agent timeout (120s) but stay below queue retry_after (180s).
     */
    public int $timeout = 130;

    /**
     * Number of attempts before failing.
     */
    public int $tries = 3;

    /**
     * Backoff in seconds between retries.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60];

    public function __construct(public JobPosting $posting, public int $userId, public string $trigger = 'auto_dispatch') {}

    /**
     * Unique lock key: one concurrent analysis per posting.
     */
    public function uniqueId(): string
    {
        return 'analyze-job-posting:'.$this->posting->getKey();
    }

    /**
     * How long the unique lock should be held (seconds).
     */
    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): CacheRepository
    {
        $store = config('cache.lock_store', config('cache.default', 'file'));
        $stores = config('cache.stores', []);

        if (! isset($stores[$store])) {
            $store = 'file';
        }

        try {
            return Cache::store($store);
        } catch (Throwable) {
            return Cache::store('file');
        }
    }

    private function provider(): string
    {
        return (string) config('ai.default');
    }

    private function model(): string
    {
        return (string) config('ai.providers.'.config('ai.default').'.models.text.default');
    }

    public function handle(JobAnalysisService $service): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $posting = $user->jobPostings()->whereKey($this->posting->getKey())->first();

        if (! $posting) {
            return;
        }

        $service->analyze($user, $posting, $this->trigger);

        $fresh = $posting->fresh();

        if (! $fresh) {
            return;
        }

        // Pending after transient error: state not yet terminal, do not notify.
        if ($fresh->status === JobPostingStatus::Pending) {
            return;
        }

        if ($fresh->status === JobPostingStatus::NeedsReview) {
            Notification::make()
                ->success()
                ->title('Análise de vaga concluída')
                ->body('A vaga foi analisada e está aguardando revisão.')
                ->actions([
                    Action::make('view')->label('Ver vaga')->url(JobPostingResource::getUrl('view', ['record' => $fresh->getKey()]))->markAsRead(),
                ])
                ->sendToDatabase($user, isEventDispatched: true);
        } elseif ($fresh->status === JobPostingStatus::Failed) {
            $this->notifyFailure($user, $fresh);
        }
    }

    private function notifyFailure(User $user, JobPosting $posting): void
    {
        $attempt = $posting->latestAttempt();
        $attemptNo = $attempt?->attempt_no ?? $posting->latestAttemptNo();
        $friendly = (string) ($posting->error_message ?: 'Não foi possível concluir a análise com IA. Tente novamente.');
        $technical = $attempt ? " {$attempt->operation} {$attempt->provider}/{$attempt->model} ({$attempt->error_class})" : '';

        Notification::make()
            ->danger()
            ->title('Falha na análise da vaga')
            ->body("{$friendly} Tentativa {$attemptNo}.{$technical}")
            ->actions([
                Action::make('view')->label('Ver detalhes')->url(JobPostingResource::getUrl('view', ['record' => $posting->getKey()]))->markAsRead(),
                Action::make('retry')->label('Tentar novamente')->action(fn (): mixed => self::dispatch($posting, $user->id, 'manual_retry'))->markAsRead(),
            ])
            ->sendToDatabase($user, isEventDispatched: true);
    }

    /**
     * If the job fails after all retries, ensure the posting is not left
     * in an ambiguous state. The service already marks failed on exceptions,
     * but a worker timeout (SIGALRM) bypasses that handling.
     */
    public function failed(?Throwable $exception): void
    {
        $user = User::find($this->userId);

        $fresh = null;

        if ($user) {
            $fresh = $user->jobPostings()->whereKey($this->posting->getKey())->first();
        } else {
            $fresh = $this->posting->fresh();
            if ($fresh && $fresh->user_id !== $this->userId) {
                $fresh = null;
            }
        }

        if ($fresh && $fresh->status->value === 'pending') {
            $friendly = FriendlyAiErrorMapper::for($exception ?? new \RuntimeException('timeout'));
            $attemptNo = $fresh->nextAttemptNo();

            $fresh->forceFill([
                'status' => JobPostingStatus::Failed,
                'suggested_data' => null,
                'error_message' => $friendly,
            ])->save();

            $attempt = $fresh->attempts()->create([
                'user_id' => $fresh->user_id,
                'operation' => 'job_extraction',
                'provider' => $this->provider(),
                'model' => $this->model(),
                'status' => 'permanent_error',
                'error_class' => $exception ? $exception::class : 'ExhaustedRetries',
                'error_message' => $exception?->getMessage() ?? 'timeout',
                'transient' => false,
                'attempt_no' => $attemptNo,
                'trigger' => 'auto_retry',
            ]);

            Log::channel('ai')->error('Job posting analysis failed.', [
                'job_posting_id' => $fresh->id,
                'error' => $attempt->error_message,
                'error_class' => $attempt->error_class,
                'operation' => 'job_extraction',
                'provider' => $attempt->provider,
                'model' => $attempt->model,
                'attempt_id' => $attempt->id,
                'attempt_no' => $attempt->attempt_no,
                'trigger' => 'auto_retry',
            ]);

            if ($user) {
                $this->notifyFailure($user, $fresh->fresh() ?? $fresh);
            }
        }
    }
}
