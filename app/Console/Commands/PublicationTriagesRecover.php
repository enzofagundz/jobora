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

class PublicationTriagesRecover extends Command
{
    protected $signature = 'publication-triages:recover';

    protected $description = 'Recupera operações de triagem de publicação já solicitadas';

    public function handle(AiOperationService $operations): int
    {
        $now = now();
        $queuedThreshold = $now->copy()->subMinute();
        $runningThreshold = $now->copy()->subSeconds(130);
        $scheduled = 0;

        AiOperation::query()
            ->where('operation_type', AiOperationType::PublicationTriage)
            ->where('target_type', 'publication')
            ->whereIn('status', [AiOperationStatus::Queued, AiOperationStatus::Running])
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($operationsToExpire) use ($operations): void {
                foreach ($operationsToExpire as $operation) {
                    $operations->expire($operation->id, $operation->run_token);
                }
            });

        AiOperation::query()
            ->where('operation_type', AiOperationType::PublicationTriage)
            ->where('target_type', 'publication')
            ->where('status', AiOperationStatus::Queued)
            ->where('expires_at', '>', $now)
            ->where(fn ($query) => $query->whereNull('queued_at')->orWhere('queued_at', '<=', $queuedThreshold))
            ->orderBy('id')
            ->chunkById(100, function ($operations) use (&$scheduled): void {
                foreach ($operations as $operation) {
                    $recovered = $this->reclaim($operation);

                    if ($recovered !== null && $this->dispatch(
                        $recovered['operation'],
                        $recovered['attempt_token'],
                        $recovered['recovery_token'],
                    )) {
                        $scheduled++;
                    }
                }
            });

        AiOperation::query()
            ->where('operation_type', AiOperationType::PublicationTriage)
            ->where('target_type', 'publication')
            ->where('status', AiOperationStatus::Running)
            ->where('expires_at', '>', $now)
            ->where(fn ($query) => $query->where('last_attempt_started_at', '<=', $runningThreshold)
                ->orWhere(fn ($query) => $query->whereNull('last_attempt_started_at')->where('started_at', '<=', $runningThreshold)))
            ->orderBy('id')
            ->chunkById(100, function ($operations) use (&$scheduled): void {
                foreach ($operations as $operation) {
                    $recovered = $this->reclaim($operation);

                    if ($recovered !== null && $this->dispatch(
                        $recovered['operation'],
                        $recovered['attempt_token'],
                        $recovered['recovery_token'],
                    )) {
                        $scheduled++;
                    }
                }
            });

        $this->info("Triagem de publicações: {$scheduled} operação(ões) recuperada(s).");

        return self::SUCCESS;
    }

    /**
     * @return array{operation: AiOperation, attempt_token: ?string, recovery_token: string}|null
     */
    private function reclaim(AiOperation $operation): ?array
    {
        return DB::transaction(function () use ($operation): ?array {
            $current = AiOperation::query()
                ->whereKey($operation->id)
                ->where('run_token', $operation->run_token)
                ->lockForUpdate()
                ->first();

            if ($current === null || $current->expires_at->lte(now())) {
                return null;
            }

            $queuedIsStale = $current->status === AiOperationStatus::Queued
                && ($current->queued_at === null || $current->queued_at->lte(now()->subMinute()));
            $runningIsStale = $current->status === AiOperationStatus::Running
                && ($current->last_attempt_started_at ?? $current->started_at)?->lte(now()->subSeconds(130));

            if (! $queuedIsStale && ! $runningIsStale) {
                return null;
            }

            $current->forceFill([
                'status' => AiOperationStatus::Queued,
                'attempt_token' => $runningIsStale ? (string) Str::uuid() : $current->attempt_token,
                'queued_at' => now(),
                'failure_code' => null,
            ])->save();

            return [
                'operation' => $current->fresh(),
                'attempt_token' => $runningIsStale ? null : $current->attempt_token,
                'recovery_token' => (string) Str::uuid(),
            ];
        });
    }

    private function dispatch(AiOperation $operation, ?string $attemptToken, string $recoveryToken): bool
    {
        try {
            ProcessAiOperation::dispatch($operation->id, $operation->run_token, $attemptToken, $recoveryToken);

            return true;
        } catch (Throwable $exception) {
            Log::channel('ai')->warning('Publication triage recovery dispatch failed.', [
                'operation_id' => $operation->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
