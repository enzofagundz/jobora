<?php

namespace App\Jobs;

use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use App\Services\JobAnalysisService;
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

    public function __construct(public JobPosting $posting) {}

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
        // O service trata falhas internamente: erro transitório do provedor
        // mantém a vaga pendente; falha real marca failed — sem retry aqui
        // além do backoff definido no Job.
        $service->analyze($this->posting);
    }

    /**
     * If the job fails after all retries, ensure the posting is not left
     * in an ambiguous state. The service already marks failed on exceptions,
     * but a worker timeout (SIGALRM) bypasses that handling.
     */
    public function failed(?Throwable $exception): void
    {
        // Only act if the posting is still pending (e.g., worker killed mid-analysis).
        // If the service already transitioned it to needs_review/failed, keep it.
        $fresh = $this->posting->fresh();

        if ($fresh && $fresh->status->value === 'pending') {
            $fresh->forceFill([
                'status' => JobPostingStatus::Failed,
                'error_message' => 'Falha na análise com IA: '.($exception?->getMessage() ?? 'timeout'),
            ])->save();
        }
    }
}
