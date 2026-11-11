<?php

namespace App\Console\Commands;

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Jobs\ProcessAiOperation;
use App\Models\AiOperation;
use App\Models\PageCapture;
use App\Services\Discovery\PublicationExtractionService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PublicationExtractionsRecover extends Command
{
    protected $signature = 'publication-extractions:recover';

    protected $description = 'Recupera extrações de publicações pendentes, sem operação ou com tentativa travada';

    public function handle(PublicationExtractionService $extractions): int
    {
        $scheduled = 0;
        $now = now();
        $queuedThreshold = $now->copy()->subMinute();
        $runningThreshold = $now->copy()->subSeconds(130);
        $windowStart = $now->copy()->subHours(max(1, (int) config('capture.ingestion.publication_extraction_recovery_hours', 24)));
        $disk = Storage::disk((string) config('capture.ingestion.disk', 'local'));

        PageCapture::query()
            ->whereIn('surface', $extractions->supportedSurfaces())
            ->whereNull('deleted_at')
            ->whereNotNull('html_path')
            ->where('raw_expires_at', '>', $now)
            ->where('created_at', '>=', $windowStart)
            ->whereDoesntHave('extractionOperation')
            ->orderBy('id')
            ->chunkById(100, function ($captures) use ($extractions, $disk, &$scheduled): void {
                foreach ($captures as $capture) {
                    if (! $this->rawExists($disk, $capture)) {
                        continue;
                    }

                    if ($extractions->requestSafely($capture) !== null) {
                        $scheduled++;
                    }
                }
            });

        AiOperation::query()
            ->where('operation_type', AiOperationType::PublicationExtraction)
            ->where('target_type', 'page_capture')
            ->where('status', AiOperationStatus::Queued)
            ->where('updated_at', '>=', $windowStart)
            ->where(function ($query) use ($queuedThreshold): void {
                $query->whereNull('queued_at')->orWhere('queued_at', '<=', $queuedThreshold);
            })
            ->whereExists(fn ($query) => $this->eligibleCapture($query, $now))
            ->orderBy('id')
            ->chunkById(100, function ($operations) use (&$scheduled): void {
                foreach ($operations as $operation) {
                    if ($this->dispatch($operation, $operation->attempt_token)) {
                        $scheduled++;
                    }
                }
            });

        AiOperation::query()
            ->where('operation_type', AiOperationType::PublicationExtraction)
            ->where('target_type', 'page_capture')
            ->where('status', AiOperationStatus::Running)
            ->where('updated_at', '>=', $windowStart)
            ->where(function ($query) use ($runningThreshold): void {
                $query->where('last_attempt_started_at', '<=', $runningThreshold)
                    ->orWhere(function ($query) use ($runningThreshold): void {
                        $query->whereNull('last_attempt_started_at')
                            ->where('started_at', '<=', $runningThreshold);
                    });
            })
            ->whereExists(fn ($query) => $this->eligibleCapture($query, $now))
            ->orderBy('id')
            ->chunkById(100, function ($operations) use (&$scheduled): void {
                foreach ($operations as $operation) {
                    if ($this->dispatch($operation, null)) {
                        $scheduled++;
                    }
                }
            });

        $this->info("Extração de publicações: {$scheduled} operação(ões) recuperada(s).");

        return self::SUCCESS;
    }

    /**
     * Falha de storage não pode interromper a recuperação das operações travadas.
     */
    private function rawExists(Filesystem $disk, PageCapture $capture): bool
    {
        try {
            return $disk->exists((string) $capture->html_path);
        } catch (Throwable $exception) {
            Log::channel('ai')->warning('Publication extraction recovery storage check failed.', [
                'page_capture_id' => $capture->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function eligibleCapture(Builder $query, CarbonInterface $now): Builder
    {
        return $query->selectRaw('1')
            ->from('page_captures')
            ->whereColumn('page_captures.id', 'ai_operations.target_id')
            ->whereNull('page_captures.deleted_at')
            ->whereNotNull('page_captures.html_path')
            ->where('page_captures.raw_expires_at', '>', $now);
    }

    private function dispatch(AiOperation $operation, ?string $attemptToken): bool
    {
        try {
            ProcessAiOperation::dispatch($operation->id, $operation->run_token, $attemptToken);

            return true;
        } catch (Throwable $exception) {
            Log::channel('ai')->warning('Publication extraction recovery dispatch failed.', [
                'operation_id' => $operation->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
