<?php

namespace App\Jobs;

use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\JobAnalysisService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
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

    public function __construct(public JobPosting $posting, public int $userId) {}

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

    public function handle(JobAnalysisService $service): void
    {
        $service->analyze($this->posting);

        $fresh = $this->posting->fresh();

        if (! $fresh) {
            return;
        }

        // Pending after transient error: state not yet terminal, do not notify.
        if ($fresh->status === JobPostingStatus::Pending) {
            return;
        }

        $recipient = User::find($this->userId);

        if (! $recipient) {
            return;
        }

        if ($fresh->status === JobPostingStatus::NeedsReview) {
            Notification::make()
                ->success()
                ->title('Análise de vaga concluída')
                ->body('A vaga foi analisada e está aguardando revisão.')
                ->sendToDatabase($recipient, isEventDispatched: true);
        } elseif ($fresh->status === JobPostingStatus::Failed) {
            Notification::make()
                ->danger()
                ->title('Falha na análise da vaga')
                ->body('A análise não foi concluída. Abra a vaga para tentar novamente.')
                ->sendToDatabase($recipient, isEventDispatched: true);
        }
    }

    /**
     * If the job fails after all retries, ensure the posting is not left
     * in an ambiguous state. The service already marks failed on exceptions,
     * but a worker timeout (SIGALRM) bypasses that handling.
     */
    public function failed(?Throwable $exception): void
    {
        $fresh = $this->posting->fresh();

        if ($fresh && $fresh->status->value === 'pending') {
            $fresh->forceFill([
                'status' => JobPostingStatus::Failed,
                'error_message' => 'Falha na análise com IA: '.($exception?->getMessage() ?? 'timeout'),
            ])->save();

            $recipient = User::find($this->userId);

            if ($recipient) {
                Notification::make()
                    ->danger()
                    ->title('Falha na análise da vaga')
                    ->body('A análise não foi concluída. Abra a vaga para tentar novamente.')
                    ->sendToDatabase($recipient, isEventDispatched: true);
            }
        }
    }
}
