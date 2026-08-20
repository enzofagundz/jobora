<?php

namespace App\Jobs;

use App\Models\JobCapture;
use App\Models\User;
use App\Services\JobTriageService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

class TriageJobCaptures implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * @param  array<int, int>  $captureIds
     */
    public function __construct(
        public array $captureIds,
        public int $userId,
    ) {}

    public function handle(JobTriageService $service): void
    {
        // O service trata falhas internamente: falha total/parcial marca as
        // capturas afetadas como failed (recuperáveis via re-envio) — sem retry aqui.
        // Os critérios de triagem vêm do usuário que iniciou o lote; se ele não
        // existir mais, a triagem segue com listas vazias (comportamento de
        // usuário sem critérios).
        $service->triageBatch(
            JobCapture::query()->whereIn('id', $this->captureIds)->get(),
            User::find($this->userId),
        );
    }
}
