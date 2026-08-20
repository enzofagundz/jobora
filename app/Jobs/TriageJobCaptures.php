<?php

namespace App\Jobs;

use App\Enums\JobCaptureTriageStatus;
use App\Models\JobCapture;
use App\Models\User;
use App\Services\JobTriageService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TriageJobCaptures implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public int $timeout = 130;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    /**
     * @param  array<int, int>  $captureIds
     */
    public function __construct(
        public array $captureIds,
        public int $userId,
    ) {}

    public function uniqueId(): string
    {
        $sorted = $this->captureIds;
        sort($sorted);

        return 'triage:'.$this->userId.':'.md5(implode(',', $sorted));
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(JobTriageService $service): void
    {
        // O service trata falhas internamente: falha total/parcial marca as
        // capturas afetadas como failed (recuperáveis via re-envio) — sem retry aqui
        // além do backoff definido no Job.
        // Os critérios de triagem vêm do usuário que iniciou o lote; se ele não
        // existir mais, a triagem segue com listas vazias (comportamento de
        // usuário sem critérios).
        $service->triageBatch(
            JobCapture::query()->whereIn('id', $this->captureIds)->get(),
            User::find($this->userId),
        );
    }

    /**
     * Ensure captures stuck in processing are marked failed if the job
     * itself fails (e.g., timeout or unhandled exception).
     */
    public function failed(?Throwable $exception): void
    {
        JobCapture::query()
            ->whereIn('id', $this->captureIds)
            ->where('triage_status', JobCaptureTriageStatus::Processing)
            ->update([
                'triage_status' => JobCaptureTriageStatus::Failed,
                'triage_error' => $exception?->getMessage() ?? 'Job falhou (timeout)',
            ]);
    }
}
