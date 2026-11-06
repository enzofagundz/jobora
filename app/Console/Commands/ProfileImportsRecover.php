<?php

namespace App\Console\Commands;

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Jobs\ProcessAiOperation;
use App\Models\AiOperation;
use App\Services\AiOperationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProfileImportsRecover extends Command
{
    protected $signature = 'profile-imports:recover';

    protected $description = 'Recupera somente importações de perfil já solicitadas';

    public function handle(AiOperationService $operations): int
    {
        $scheduled = 0;
        AiOperation::query()->where('operation_type', AiOperationType::ProfileImport)
            ->whereIn('status', [AiOperationStatus::Queued, AiOperationStatus::Running])
            ->orderBy('id')->chunkById(100, function ($batch) use ($operations, &$scheduled): void {
                foreach ($batch as $operation) {
                    if ($operation->expires_at->lte(now())) {
                        $operations->expire($operation->id, $operation->run_token);

                        continue;
                    }
                    $recovered = DB::transaction(function () use ($operation): ?AiOperation {
                        $current = AiOperation::query()->whereKey($operation->id)->where('run_token', $operation->run_token)->lockForUpdate()->first();
                        if ($current === null || $current->expires_at->lte(now())) {
                            return null;
                        }
                        $stale = ($current->status === AiOperationStatus::Queued && ($current->queued_at === null || $current->queued_at->lte(now()->subMinute())))
                            || ($current->status === AiOperationStatus::Running && ($current->last_attempt_started_at ?? $current->started_at)?->lte(now()->subSeconds(130)));
                        if (! $stale) {
                            return null;
                        }
                        $updated = AiOperation::query()->whereKey($current->id)->where('run_token', $current->run_token)
                            ->where('attempt_token', $current->attempt_token)->where('status', $current->status)->update([
                                'status' => 'queued', 'attempt_token' => (string) Str::uuid(), 'queued_at' => now(), 'failure_code' => null,
                            ]);

                        return $updated === 1 ? $current->fresh() : null;
                    });
                    if ($recovered === null) {
                        continue;
                    }
                    try {
                        ProcessAiOperation::dispatch($recovered->id, $recovered->run_token, $recovered->attempt_token, (string) Str::uuid());
                        $scheduled++;
                    } catch (Throwable) {
                        Log::channel('ai')->warning('Profile import recovery dispatch failed.', ['operation_id' => $recovered->id]);
                    }
                }
            });
        $this->info("Importações de perfil: {$scheduled} operação(ões) recuperada(s).");

        return self::SUCCESS;
    }
}
