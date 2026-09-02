<?php

namespace App\Console\Commands;

use App\Services\AiOperationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai-operations:expire')]
#[Description('Expira operações de IA que aguardaram um worker.')]
class ExpireAiOperations extends Command
{
    public function handle(AiOperationService $service): int
    {
        $expired = $service->expireWaiting();

        $this->info("{$expired} operação(ões) expirada(s).");

        return self::SUCCESS;
    }
}
