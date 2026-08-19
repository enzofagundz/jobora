<?php

namespace App\Jobs;

use App\Models\JobCapture;
use App\Services\JobTriageService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

class TriageJobCaptures implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * @param  array<int, int>  $captureIds
     */
    public function __construct(public array $captureIds) {}

    public function handle(JobTriageService $service): void
    {
        // O service trata falhas internamente: falha total/parcial marca as
        // capturas afetadas como failed (recuperáveis via re-envio) — sem retry aqui.
        $service->triageBatch(JobCapture::query()->whereIn('id', $this->captureIds)->get());
    }
}
